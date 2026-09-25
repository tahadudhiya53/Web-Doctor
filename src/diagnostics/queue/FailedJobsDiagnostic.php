<?php

namespace Tahadudhiya\WebDoctor\diagnostics\queue;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\queue\Queue;
use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Jobs that ran and failed.
 *
 * A failed job is work the site was asked to do and did not do: an email not sent, a search
 * index not updated, an image not generated. Craft keeps the failure and its error, and nothing
 * ever brings it to anyone's attention — so a site can be quietly not doing things for months.
 *
 * Failures are grouped by what the job was, because twenty failures of one job and twenty
 * different failures are entirely different situations and the count alone cannot tell them
 * apart. Grouping is on the job description exactly as Craft recorded it, never on a loosened
 * form of it: merging two unrelated failures would produce a wrong story, and leaving them
 * apart only produces a longer one.
 *
 * Error text is shown on the same terms Craft itself shows it — in development mode, or to an
 * administrator. Web Doctor does not become the way around that, and it redacts what it does
 * show regardless: being allowed to see an error is not the same as being shown a credential
 * that happened to be inside one.
 */
class FailedJobsDiagnostic extends QueueDiagnostic
{
    public const ID = 'queue.failedJobs';

    /** @var array<string, int> How many failures are examined individually, by depth. */
    private const SAMPLE_SIZE = [
        DiagnosticDepth::SHALLOW->value => 0,
        DiagnosticDepth::NORMAL->value => 10,
        DiagnosticDepth::DEEP->value => 50,
    ];

    public function name(): string
    {
        return Craft::t('web-doctor', 'Failed queue jobs');
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Reports jobs that ran and failed, grouped by what the job was, with the error Craft recorded for each.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $queue = $this->databaseQueue();
            $total = $this->totalFailed($queue);
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The queue could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        if ($total === 0) {
            return $this->pass(
                Craft::t('web-doctor', 'No queue jobs have failed.'),
                [$this->evidence(EvidenceType::QUEUE, Craft::t('web-doctor', 'Failed jobs'), ['failed' => 0])],
            );
        }

        $sampleSize = self::SAMPLE_SIZE[$context->depth->value];
        $groups = $sampleSize > 0 ? $this->groupFailures($queue, $sampleSize) : [];
        $examined = array_sum(array_column($groups, 'occurrences'));
        $complete = $examined === $total;

        $evidence = [
            $this->evidence(EvidenceType::QUEUE, Craft::t('web-doctor', 'Failed jobs'), [
                // `failed` is every failure there is; the rest describe the sample that was
                // looked at. A queue with thousands of failures is exactly the one where
                // reading them all would be worst, so the difference is stated rather than
                // glossed over.
                'failed' => $total,
                'examined' => $examined,
                'distinctJobsInSample' => count($groups),
                'sampleLimit' => $sampleSize,
                'sampleIsComplete' => $examined === $total,
            ]),
        ];

        foreach ($groups as $group) {
            $evidence[] = new Evidence(
                type: EvidenceType::QUEUE_JOB,
                label: $group['description'],
                source: $this->id(),
                data: [
                    'description' => $group['description'],
                    'occurrences' => $group['occurrences'],
                    'firstFailed' => $group['firstFailed'],
                    'lastFailed' => $group['lastFailed'],
                    'error' => $group['error'],
                ],
                observedAt: $group['lastFailed'] === null ? null : new DateTimeImmutable('@' . $group['lastFailed']),
            );
        }

        $repeated = array_values(array_filter($groups, static fn(array $g): bool => $g['occurrences'] > 1));

        // What is said about *why* they failed can only be said about the ones that were read,
        // so the wording changes when the sample is not the whole set.
        if ($repeated !== []) {
            $description = $complete
                ? Craft::t('web-doctor', 'Some of these are the same job failing repeatedly, which points at a condition that has not gone away rather than at a one-off.')
                : Craft::t('web-doctor', 'Of the {examined} most recent failures examined, some are the same job failing repeatedly, which points at a condition that has not gone away. The rest were not read.', ['examined' => $examined]);
        } else {
            $description = $complete
                ? Craft::t('web-doctor', 'Work the site was asked to do has not been done, and it will not be retried on its own.')
                : Craft::t('web-doctor', 'Work the site was asked to do has not been done, and it will not be retried on its own. The {examined} most recent failures were examined; the rest were not read.', ['examined' => $examined]);
        }

        return $this->fail(
            $complete
                ? Craft::t('web-doctor', 'Queue jobs have failed: {count}.', ['count' => $total])
                : Craft::t('web-doctor', 'Queue jobs have failed: {count}, of which the {examined} most recent were examined.', ['count' => $total, 'examined' => $examined]),
            $evidence,
            recommendation: Craft::t('web-doctor', 'Look at the recorded error for each job, fix what it names, then retry the jobs from Utilities → Queue Manager.'),
            severity: $repeated !== [] || $total > 5 ? Severity::HIGH : Severity::MEDIUM,
            confidence: Confidence::CONFIRMED,
            description: $description,
        );
    }

    /**
     * The most recent failures, grouped by what the job was.
     *
     * Bounded by the sample size rather than by the number of failures: a queue with thousands
     * of failed jobs is exactly the queue where reading them all would be worst.
     *
     * Protected rather than private so a test can state the failures instead of a site having
     * to be put into the state that produces them.
     *
     * @return list<array{description: string, occurrences: int, firstFailed: int|null, lastFailed: int|null, error: string}>
     */
    protected function groupFailures(Queue $queue, int $limit): array
    {
        $rows = $this->failedJobs($queue)
            ->select(['description', 'error', 'dateFailed'])
            // Most recent first, and `id` breaks the tie so two rows failing in the same second
            // never come back in a different order on a later run.
            ->orderBy(['dateFailed' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $showErrors = $this->mayShowErrors();
        $groups = [];

        foreach ($rows as $row) {
            $description = (string)($row['description'] ?? '') ?: Craft::t('web-doctor', 'Untitled job');
            $failedAt = $row['dateFailed'] !== null ? DateTimeHelper::toDateTime($row['dateFailed']) : null;
            $timestamp = $failedAt === false || $failedAt === null ? null : $failedAt->getTimestamp();

            if (!isset($groups[$description])) {
                $groups[$description] = [
                    'description' => $description,
                    'occurrences' => 0,
                    'firstFailed' => $timestamp,
                    'lastFailed' => $timestamp,
                    'error' => $showErrors
                        ? Redaction::redactString((string)($row['error'] ?? ''))
                        : Redaction::presence($row['error'] ?? null),
                ];
            }

            $groups[$description]['occurrences']++;

            if ($timestamp !== null) {
                $groups[$description]['firstFailed'] = min($groups[$description]['firstFailed'] ?? $timestamp, $timestamp);
                $groups[$description]['lastFailed'] = max($groups[$description]['lastFailed'] ?? $timestamp, $timestamp);
            }
        }

        // Grouping is on a hash map, so the order out is insertion order — which is the query's
        // order and therefore stable. Sorted anyway, most frequent first, so the thing worth
        // looking at comes first and the order never depends on how PHP happens to iterate.
        $groups = array_values($groups);

        usort($groups, static fn(array $a, array $b): int => [$b['occurrences'], $a['description']] <=> [$a['occurrences'], $b['description']]);

        return $groups;
    }

    /**
     * How many jobs have failed. Protected so a test can state a queue's failures rather than
     * having to produce them.
     */
    protected function totalFailed(Queue $queue): int
    {
        return (int)$this->failedJobs($queue)->count('*');
    }

    /**
     * A query over this queue's failed jobs, scoped the way Craft scopes them in
     * `Queue::_createFailedJobQuery()`.
     */
    private function failedJobs(Queue $queue): Query
    {
        return $this->jobs($queue)->andWhere(['fail' => true]);
    }

    /**
     * Whether the error a job recorded may be shown.
     *
     * The same terms Craft applies in its own queue listing. Web Doctor reads the queue table
     * directly — Craft's listing puts failures last, so a bounded read of it returns none — and
     * reading it directly must not become a way around the rule that comes with it.
     */
    private function mayShowErrors(): bool
    {
        if (App::devMode()) {
            return true;
        }

        try {
            return Craft::$app->getUser()->getIsAdmin();
        } catch (Throwable) {
            return false;
        }
    }
}
