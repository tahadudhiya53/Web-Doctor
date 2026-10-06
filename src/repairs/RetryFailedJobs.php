<?php

namespace Tahadudhiya\WebDoctor\repairs;

use Craft;
use craft\queue\Queue;
use craft\queue\QueueInterface;
use craft\utilities\QueueManager;
use RuntimeException;
use Tahadudhiya\WebDoctor\base\RepairAction;
use Tahadudhiya\WebDoctor\diagnostics\queue\FailedJobsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueBacklogDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueDiagnostic;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Prerequisite;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RepairContext;
use Tahadudhiya\WebDoctor\models\RepairReport;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\rules\RootCauseRules;
use Tahadudhiya\WebDoctor\verifications\RetriedJobsSettled;
use Throwable;

/**
 * Puts failed queue jobs back in the queue, with Craft's own `Queue::retry()` — the call Utilities →
 * Queue Manager makes — one job at a time, for exactly the jobs the preview named.
 *
 * Bounded to the oldest {@see self::MAX_JOBS} failed jobs of this queue, so one confirmation is never
 * a mass change. The jobs are this queue's own, read through the same scoping the queue checks use,
 * so it cannot retry another queue's jobs. Nothing runs them here: whatever runs this installation's
 * queue picks them up.
 *
 * A job that ran out of memory is refused rather than retried, because retrying it before its limit
 * is raised only repeats the failure. The error Craft recorded is read to decide that and is never
 * shown: who may see a job's error is Craft's rule, and the preview names each job by its
 * description.
 */
class RetryFailedJobs extends RepairAction
{
    public const ID = 'queue.retryFailedJobs';

    /** @var int The most failed jobs one repair retries. */
    public const MAX_JOBS = 50;

    /**
     * @var QueueInterface|null The queue to retry jobs in. Craft's own unless a caller supplies
     * another, which is how a test retries jobs in a queue table of its own.
     */
    public ?QueueInterface $queue = null;

    public function name(): string
    {
        return Craft::t('web-doctor', 'Retry the failed queue jobs');
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Puts up to {max} of this queue’s failed jobs back in the queue with Craft’s own retry, the one Utilities → Queue Manager uses. Whatever runs the queue here then runs them again.', ['max' => self::MAX_JOBS]);
    }

    public function diagnosticId(): string
    {
        return FailedJobsDiagnostic::ID;
    }

    public function recommendation(): ?string
    {
        return 'queue.inspectThenRetry';
    }

    public function risk(): RepairRisk
    {
        return RepairRisk::MEDIUM;
    }

    public function riskReason(): string
    {
        return Craft::t('web-doctor', 'Each job runs again, so anything it did before it failed — an email sent, a payment taken, a record written — may happen twice.');
    }

    public function isApplicable(RecommendationCase $finding): bool
    {
        return $finding->diagnosticId === FailedJobsDiagnostic::ID
            && $finding->where(EvidenceType::QUEUE, static fn(Evidence $e): bool => (int)$e->get('failed', 0) > 0) !== [];
    }

    public function isAuthorized(): bool
    {
        try {
            return Craft::$app->getUtilities()->checkAuthorization(QueueManager::class);
        } catch (Throwable $e) {
            // Refused, as the safe answer — but a lookup that broke is not somebody lacking access.
            SafeException::log('Whether Craft allows retrying queue jobs could not be established', $e);

            return false;
        }
    }

    public function authorization(): string
    {
        return Craft::t('web-doctor', 'Craft lets somebody retry queue jobs only with access to the Queue Manager utility.');
    }

    public function prerequisites(RepairContext $context): array
    {
        $queue = $this->databaseQueue();

        if ($queue === null) {
            return [
                Prerequisite::checked('databaseQueue', Craft::t('web-doctor', 'Craft’s queue is its own database-backed queue, which this retries jobs in.'), false),
            ];
        }

        // With no channel to scope by, the queue checks read the whole table; retrying from it could
        // retry another queue's jobs, so this one is not carried out.
        if (QueueDiagnostic::channelOf($queue) === null) {
            return [
                Prerequisite::checked('databaseQueue', Craft::t('web-doctor', 'Craft’s queue is its own database-backed queue, which this retries jobs in.'), true),
                Prerequisite::checked('ownChannel', Craft::t('web-doctor', 'Which of the queue table’s jobs belong to this queue can be told, so no other queue’s job is retried.'), false),
            ];
        }

        $jobs = $this->failedJobs($queue);
        $outOfMemory = array_values(array_filter($jobs, static fn(array $job): bool => preg_match(RootCauseRules::OUT_OF_MEMORY, (string)$job['error']) === 1));

        return [
            Prerequisite::checked('databaseQueue', Craft::t('web-doctor', 'Craft’s queue is its own database-backed queue, which this retries jobs in.'), true),
            Prerequisite::checked(
                'jobsFailed',
                Craft::t('web-doctor', 'This queue has failed jobs to retry.'),
                $jobs !== [],
                $jobs === [] ? Craft::t('web-doctor', 'None has failed.') : null,
            ),
            Prerequisite::checked(
                'enoughMemory',
                Craft::t('web-doctor', 'None of the jobs to be retried ran out of memory. Retrying one before its memory limit is raised only repeats the failure.'),
                $outOfMemory === [],
                $outOfMemory === [] ? null : Craft::t('web-doctor', '{count, plural, =1{One job} other{# jobs}} ran out of memory: {ids}', [
                    'count' => count($outOfMemory),
                    'ids' => implode(', ', array_map(static fn(array $job): string => '#' . $job['id'], $outOfMemory)),
                ]),
            ),
            Prerequisite::acknowledged('safeToRepeat', Craft::t('web-doctor', 'Each job is safe to run again: a job that sends email, calls another service or charges a payment may have done part of its work before it failed.')),
            Prerequisite::acknowledged('causeDealtWith', Craft::t('web-doctor', 'What each job’s recorded error names has been dealt with. A job retried before then fails the same way.')),
        ];
    }

    public function preview(RepairContext $context): RepairReport
    {
        $queue = $this->databaseQueue();
        $jobs = $queue === null ? [] : $this->failedJobs($queue);
        $total = $queue === null ? 0 : $this->totalFailed($queue);

        return new RepairReport(
            summary: Craft::t('web-doctor', 'Retries {count, plural, =1{one failed job} other{# failed jobs}} of the {total} this queue holds. Nothing runs them here; whatever runs the queue picks them up.', [
                'count' => count($jobs),
                'total' => $total,
            ]),
            items: array_map(static fn(array $job): string => Craft::t('web-doctor', 'Job #{id}: {description} — attempt {attempt}, failed {failed}', [
                'id' => $job['id'],
                'description' => $job['description'] !== '' ? $job['description'] : Craft::t('web-doctor', 'No description'),
                'attempt' => $job['attempt'],
                'failed' => $job['dateFailed'] ?? Craft::t('web-doctor', 'at an unrecorded time'),
            ]), $jobs),
            state: [new Evidence(EvidenceType::QUEUE, Craft::t('web-doctor', 'Failed jobs before the repair'), self::ID, [
                'failed' => $total,
                'toRetry' => array_map(static fn(array $job): array => array_diff_key($job, ['error' => true]), $jobs),
            ])],
            // A job that failed again since the preview — or one retried by somebody else — changes
            // its attempt or its failure time, and so changes what would be retried.
            fingerprint: $this->fingerprint($queue, $jobs),
        );
    }

    public function execute(RepairContext $context, RepairReport $preview): RepairReport
    {
        $queue = $this->databaseQueue() ?? throw new RuntimeException('Craft’s queue is not one this repair can retry jobs in.');
        $jobs = $this->failedJobs($queue);

        // Read once more at the moment of acting, and held to the preview: `retry()` resets a row by
        // its ID whatever state it is in, so a job picked up by a worker since would be reset mid-run.
        if (!hash_equals((string)$preview->fingerprint, $this->fingerprint($queue, $jobs))) {
            throw new Refusal(Craft::t('web-doctor', 'The failed jobs changed just before they were retried, so none was. Preview the repair again.'));
        }

        $ids = array_map(static fn(array $job): int => $job['id'], $jobs);

        $retriedIds = [];

        foreach ($ids as $id) {
            // Asked once more for each job, at the moment it is retried: `retry()` resets a row by its
            // ID whatever state it is in, so a job somebody else put back — which a worker may already
            // be running — must not be reset under it.
            $stillFailedNow = (bool)QueueDiagnostic::onQueueDb($queue, static fn($db) => QueueDiagnostic::jobs($queue)->andWhere(['id' => $id, 'fail' => true])->exists($db));

            if ($stillFailedNow) {
                // Noted first, so a worker that picks the job up at once is still seen to run it.
                RetriedJobsSettled::watch($queue, $id);
                $queue->retry((string)$id);
                $retriedIds[] = $id;
            }
        }

        $stillFailed = $ids === [] ? [] : array_map('intval', QueueDiagnostic::onQueueDb(
            $queue,
            static fn($db) => QueueDiagnostic::jobs($queue)->select(['id'])->andWhere(['id' => $ids, 'fail' => true])->column($db),
        ));
        $retried = array_values(array_diff($retriedIds, $stillFailed));

        return new RepairReport(
            summary: Craft::t('web-doctor', '{count, plural, =1{One job is} other{# jobs are}} back in the queue.', ['count' => count($retried)]),
            items: array_map(static fn(int $id): string => Craft::t('web-doctor', 'Job #{id}: back in the queue', ['id' => $id]), $retried),
            state: [new Evidence(EvidenceType::QUEUE, Craft::t('web-doctor', 'Failed jobs after the repair'), self::ID, [
                'retried' => $retried,
                'stillFailed' => $stillFailed,
                'failed' => $this->totalFailed($queue),
            ])],
        );
    }

    public function verifyWith(): array
    {
        return [FailedJobsDiagnostic::ID, QueueBacklogDiagnostic::ID];
    }

    public function verification(): string
    {
        return Craft::t('web-doctor', 'Once whatever runs the queue has run them, the failed-jobs check finds none of these jobs failed again, and the backlog check finds them no longer waiting.');
    }

    /**
     * The oldest failed jobs of this queue, up to the bound, with what identifies each attempt.
     *
     * @return list<array{id: int, description: string, attempt: int, dateFailed: string|null, error: string}>
     */
    private function failedJobs(Queue $queue): array
    {
        $rows = QueueDiagnostic::onQueueDb($queue, static fn($db) => QueueDiagnostic::jobs($queue)
            ->select(['id', 'description', 'attempt', 'dateFailed', 'error'])
            ->andWhere(['fail' => true])
            ->orderBy(['id' => SORT_ASC])
            ->limit(self::MAX_JOBS)
            ->all($db));

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'description' => (string)($row['description'] ?? ''),
            'attempt' => (int)($row['attempt'] ?? 0),
            'dateFailed' => is_string($row['dateFailed'] ?? null) ? $row['dateFailed'] : null,
            'error' => (string)($row['error'] ?? ''),
        ], $rows);
    }

    /**
     * What identifies the jobs that would be retried. A job that failed again since the preview — or
     * one retried by somebody else — changes its attempt or its failure time, and so this.
     *
     * Which queue it is — its table, its channel and the database it lives in — is part of it too, so
     * a preview made of one queue can never be carried out on another.
     *
     * @param list<array{id: int, description: string, attempt: int, dateFailed: string|null, error: string}> $jobs
     */
    private function fingerprint(?Queue $queue, array $jobs): string
    {
        return RepairReport::fingerprintOf([
            'queue' => $queue === null ? null : [$queue->tableName, QueueDiagnostic::channelOf($queue), $queue->db->dsn],
            'jobs' => array_map(static fn(array $job): array => [$job['id'], $job['attempt'], $job['dateFailed']], $jobs),
        ]);
    }

    private function totalFailed(Queue $queue): int
    {
        return (int)QueueDiagnostic::onQueueDb($queue, static fn($db) => QueueDiagnostic::jobs($queue)->andWhere(['fail' => true])->count('*', $db));
    }

    /**
     * Craft's queue, where it is the database-backed one this retries jobs in. Another driver — or
     * one Craft cannot build — is null, and the prerequisite that needs it is not met.
     */
    private function databaseQueue(): ?Queue
    {
        try {
            $queue = $this->queue ?? Craft::$app->getQueue();
        } catch (Throwable $e) {
            SafeException::log('Craft’s queue could not be built to retry its jobs', $e);

            return null;
        }

        return $queue instanceof Queue ? $queue : null;
    }
}
