<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\ExecutionMode;
use Tahadudhiya\WebDoctor\enums\InvestigationStatus;
use Tahadudhiya\WebDoctor\enums\InvestigationStepType;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\ErrorRecording;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Investigation;
use Tahadudhiya\WebDoctor\models\InvestigationPlan;
use Tahadudhiya\WebDoctor\models\InvestigationStep;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\IssueReconciliation;
use Tahadudhiya\WebDoctor\models\IssueSnapshot;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\records\InvestigationRecord;
use Tahadudhiya\WebDoctor\records\InvestigationStepRecord;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Investigates an issue: runs the checks worth running alongside it, keeps what they found, and
 * says what else is open nearby.
 *
 * Which checks is the plan's answer ({@see InvestigationPlan}), and it is decided before anything
 * runs and recorded with its reasons. The checks run through the engine like any other run, so a
 * check that breaks is contained exactly as it is on the dashboard, and their findings go through
 * the Issue Center's own reconciliation — an investigation that re-runs the check behind an issue
 * and sees it pass has observed the issue clear, and saying so anywhere but the issue would leave
 * two answers to one question.
 *
 * It records what was observed, in order, and what could not be — and then weighs that against the
 * known causes ({@see RootCauses}), keeping each candidate with the evidence for and against it.
 * Weighing is the last thing it does, so a cause can only ever rest on what was actually recorded.
 */
class Investigations extends Component
{
    /** @var int How many of an issue's investigations its page lists. */
    public const HISTORY_LIMIT = 10;

    /**
     * @var int The most checks one investigation runs. Settable, so an installation can reach
     * further or less far and a test can reach the bound without registering twenty-five checks.
     */
    public int $maxChecks = InvestigationPlan::MAX_CHECKS;

    /**
     * @var int The most pieces of evidence one investigation keeps. Each is already bounded by the
     * evidence model, so this bounds the whole: past it, a check's evidence is counted, not kept.
     */
    public int $maxEvidence = 100;

    /** @var int The most investigations one issue keeps; the oldest go first. */
    public int $maxPerIssue = 20;

    /** @var int The most open issues nearby that one investigation records. */
    public int $relatedLimit = 20;

    /** @var Diagnostics|null Where checks are looked up; the plugin's unless set. */
    public ?Diagnostics $registry = null;

    /** @var DiagnosticEngine|null What runs them; the plugin's unless set. */
    public ?DiagnosticEngine $engine = null;

    /** @var Issues|null The Issue Center; the plugin's unless set. */
    public ?Issues $issues = null;

    /** @var Errors|null Where the errors the checks run into are grouped; the plugin's unless set. */
    public ?Errors $errors = null;

    /** @var RootCauses|null What weighs the findings against the known causes; the plugin's unless set. */
    public ?RootCauses $rootCauses = null;

    /**
     * @var string|null The environment this installation is running as. Craft's answer unless set,
     * which a test does so that an issue recorded under its own environment can be investigated.
     */
    public ?string $environment = null;

    /**
     * What investigating an issue would run, and why, without running any of it.
     */
    public function plan(Issue $issue, DiagnosticDepth $depth = DiagnosticDepth::NORMAL): InvestigationPlan
    {
        return InvestigationPlan::build(
            $issue->diagnosticId,
            $issue->category,
            $issue->affectedPlugin,
            $this->registry()->all(),
            $depth,
            $this->maxChecks,
        );
    }

    /**
     * Why this issue cannot be investigated from here, or null when it can.
     *
     * Checks run here describe this environment and the site in view. An issue found somewhere
     * else would be investigated with facts about a different place, which is the mixing of
     * evidence Web Doctor exists not to do.
     */
    public function refusal(Issue $issue): ?string
    {
        $here = $this->environment();

        if ($issue->environment !== $here) {
            return Craft::t('web-doctor', 'This issue was found in the “{found}” environment and this installation is running as “{here}”. Checks run here would describe a different environment, so it has to be investigated where it was found.', [
                'found' => $issue->environment,
                'here' => $here,
            ]);
        }

        // Craft soft-deletes sites, so the foreign key that nulls the reference rarely fires: a
        // site in the trash still has its ID on the issue, and has to be asked about.
        if (($issue->siteId === null && $issue->siteName !== null) || ($issue->siteId !== null && !$this->siteExists($issue->siteId))) {
            return Craft::t('web-doctor', 'The site this issue was found on, “{site}”, has been deleted, so there is nothing left to investigate it against.', [
                'site' => $issue->siteName ?? '#' . $issue->siteId,
            ]);
        }

        return null;
    }

    /**
     * Investigates an issue and returns what the investigation found.
     *
     * An investigation that breaks part-way is kept as a failed one saying why, rather than
     * thrown away; a record of an attempt is worth more to the next person than no record.
     *
     * @param int|null $userId Who asked. Recorded, never used to authorise — that is the controller's.
     * @throws InvalidArgumentException if there is no such issue, or it cannot be investigated here.
     */
    public function investigate(int $issueId, DiagnosticDepth $depth = DiagnosticDepth::NORMAL, ?int $userId = null): Investigation
    {
        $issue = $this->issues()->get($issueId);

        if ($issue === null) {
            throw new InvalidArgumentException(Craft::t('web-doctor', 'No issue exists with the ID {id}.', ['id' => $issueId]));
        }

        $refusal = $this->refusal($issue);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $plan = $this->plan($issue, $depth);

        // Built outright rather than through DiagnosticContext::current(), which fills an absent
        // site with the current one: an issue about no particular site would then be investigated
        // as a finding about one, and its findings would land on a different issue.
        $context = new DiagnosticContext(
            siteId: $issue->siteId,
            environment: $issue->environment,
            mode: Craft::$app instanceof ConsoleApplication ? ExecutionMode::CONSOLE : ExecutionMode::MANUAL,
            depth: $depth,
        );

        $record = $this->begin($issue, $plan, $context, $userId);
        $position = 0;

        // Everything after the record exists is inside this, its first two steps included: an
        // attempt that has been written down must end as something other than "running".
        try {
            $this->step($record, $position, InvestigationStepType::STARTED, $context->startedAt, [
                'diagnosticId' => $issue->diagnosticId,
                'diagnosticName' => $issue->diagnosticName,
                'summary' => $issue->title,
                'relatedIssueId' => $issue->id,
            ]);

            $this->step($record, $position, InvestigationStepType::PLANNED, new DateTimeImmutable(), [
                'summary' => $plan->ruleLabel,
                'note' => Craft::t('web-doctor', '{checks, plural, =0{No checks} =1{1 check} other{# checks}} chosen. {uncovered, plural, =0{Every related area has a check here.} =1{1 related area has no check here.} other{# related areas have no check here.}}', [
                    'checks' => count($plan->checks),
                    'uncovered' => count($plan->uncovered),
                ]),
            ]);

            $run = $this->engine()->runMany($this->diagnosticsFor($plan), $context);
            $reconciliation = $this->reconcile($run);
            $errors = $this->recordErrors($run);

            $this->finish($record, $position, $issue, $plan, $run, $reconciliation, $errors);
        } catch (Throwable $e) {
            $this->fail($record, $position, $context, $e);
        }

        $this->prune($issue->id);

        return Investigation::fromRecord($record);
    }

    public function get(int $id): ?Investigation
    {
        $record = InvestigationRecord::findOne(['id' => $id]);

        return $record === null ? null : Investigation::fromRecord($record);
    }

    /**
     * An issue's investigations, newest first.
     *
     * @return list<Investigation>
     */
    public function forIssue(int $issueId, int $limit = self::HISTORY_LIMIT): array
    {
        $records = InvestigationRecord::find()
            ->where(['issueId' => $issueId])
            ->orderBy(['startedAt' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(max(1, $limit))
            ->all();

        $out = [];

        foreach ($records as $record) {
            if ($record instanceof InvestigationRecord) {
                $out[] = Investigation::fromRecord($record);
            }
        }

        return $out;
    }

    /**
     * An investigation's timeline, in the order it happened. Bounded by construction: one step per
     * planned check, one per related issue, and a handful of its own.
     *
     * @return list<InvestigationStep>
     */
    public function steps(int $investigationId): array
    {
        $records = InvestigationStepRecord::find()
            ->where(['investigationId' => $investigationId])
            ->orderBy(['position' => SORT_ASC])
            ->all();

        $out = [];

        foreach ($records as $record) {
            if ($record instanceof InvestigationStepRecord) {
                $out[] = InvestigationStep::fromRecord($record);
            }
        }

        return $out;
    }

    /**
     * Writes the investigation down before anything runs, so an attempt that never returns — a
     * fatal error, a killed request — still leaves a record that it was made.
     */
    private function begin(Issue $issue, InvestigationPlan $plan, DiagnosticContext $context, ?int $userId): InvestigationRecord
    {
        $record = new InvestigationRecord();
        $record->issueId = $issue->id;
        $record->runId = $context->runId;
        $record->status = InvestigationStatus::RUNNING->value;
        $record->depth = $plan->depth->value;
        $record->ruleId = $plan->ruleId;
        // Redacted as it goes in, like everything else Web Doctor writes down: the plan quotes
        // names and IDs that other plugins supplied.
        $record->plan = Evidence::encode(Redaction::redact($plan->jsonSerialize()));
        $record->environment = $context->environment;
        $record->siteId = $context->siteId;
        $record->startedBy = $userId;
        $record->checksPlanned = count($plan->checks);
        $record->startedAt = $this->forDb($context->startedAt);

        $this->save($record);

        return $record;
    }

    /**
     * Records what the run found, what that did to the Issue Center and what else is open
     * nearby, and closes the investigation — its counts and its last step in one act, so an
     * investigation never reads as finished without saying how.
     */
    private function finish(
        InvestigationRecord $record,
        int &$position,
        Issue $issue,
        InvestigationPlan $plan,
        DiagnosticRun $run,
        IssueReconciliation|string $reconciliation,
        ErrorRecording|string $errors,
    ): void {
        $budget = max(0, $this->maxEvidence);
        $raised = $this->issuesRaisedBy($run);

        foreach ($run->results() as $result) {
            $evidence = array_slice($result->evidence(), 0, $budget);
            $raisedOn = $raised[$result->diagnosticId] ?? null;

            $this->step($record, $position, InvestigationStepType::CHECKED, $result->finishedAt ?? new DateTimeImmutable(), [
                'diagnosticId' => $result->diagnosticId,
                'diagnosticName' => $result->name !== '' ? $result->name : $result->diagnosticId,
                'status' => $result->status->value,
                'severity' => $result->severity()->value,
                'summary' => $result->summary,
                'note' => $plan->reasonFor($result->diagnosticId),
                'relatedIssueId' => $raisedOn,
                'evidence' => $evidence,
                'evidenceCount' => count($result->evidence()),
                'durationMs' => $result->durationMs,
            ]);

            // Counted only once the step is written, so an investigation that stops part-way
            // reports exactly what it has a record of.
            $budget -= count($evidence);
            $this->count($record, $result);
            $record->evidenceCount += count($evidence);

            if ($result->diagnosticId === $issue->diagnosticId) {
                $record->originStatus = $result->status->value;
            }
        }

        $issuesNote = is_string($reconciliation)
            ? $reconciliation
            : trim(Craft::t('web-doctor', '{opened, plural, =0{No new issues.} =1{1 new issue.} other{# new issues.}} {resolved, plural, =0{None resolved.} =1{1 resolved.} other{# resolved.}}', [
                'opened' => $reconciliation->opened,
                'resolved' => $reconciliation->resolved,
            ]) . ($reconciliation->recurred > 0 ? ' ' . Craft::t('web-doctor', '{count, plural, =1{1 resolved issue came back.} other{# resolved issues came back.}}', ['count' => $reconciliation->recurred]) : ''));

        $errorsNote = match (true) {
            is_string($errors) => $errors,
            $errors->occurrences === 0 => null,
            default => Craft::t('web-doctor', '{count, plural, =1{1 error was recorded} other{# errors were recorded}}, {created, plural, =0{each one already seen here} =1{1 of them new} other{# of them new}}.', [
                'count' => $errors->groups,
                'created' => $errors->created,
            ]),
        };

        if ($errors instanceof ErrorRecording && $errors->omitted > 0) {
            $errorsNote = trim(($errorsNote ?? '') . ' ' . Craft::t('web-doctor', '{omitted, plural, =1{1 more distinct error was not recorded} other{# more distinct errors were not recorded}}, because as many are kept as the limit allows.', [
                'omitted' => $errors->omitted,
            ]));
        }

        $this->step($record, $position, InvestigationStepType::RECONCILED, $run->finishedAt, [
            'note' => $errorsNote === null ? $issuesNote : $issuesNote . ' ' . $errorsNote,
        ]);

        $categories = $plan->categories();

        if (!in_array($issue->category, $categories, true)) {
            $categories[] = $issue->category;
        }

        // Read after reconciling, so what is open reflects what the checks just established, and
        // without the issues this run touched, which are already in the timeline as findings.
        $now = new DateTimeImmutable();
        $nearbyIssues = $this->issues()->related($issue, $categories, $run->id(), $this->relatedLimit);

        foreach ($nearbyIssues as $nearby) {
            $this->step($record, $position, InvestigationStepType::RELATED_ISSUE, $now, [
                'diagnosticId' => $nearby->diagnosticId,
                'diagnosticName' => $nearby->diagnosticName,
                'status' => $nearby->resultStatus->value,
                'severity' => $nearby->severity->value,
                'summary' => $nearby->title,
                'note' => $this->relatedBecause($issue, $nearby),
                'relatedIssueId' => $nearby->id,
            ]);

            $record->relatedIssues++;
        }

        $weighed = $this->weigh($issue, $run, $raised, $nearbyIssues);

        $status = $record->checksIncomplete > 0 || !$plan->includesOrigin() ? InvestigationStatus::PARTIAL : InvestigationStatus::COMPLETED;
        $finishedAt = new DateTimeImmutable();
        $start = $position;
        $transaction = Craft::$app->getDb()->beginTransaction();

        // The causes, the step that reports them, the final state and the ending are one act: a
        // finish that fails must not leave conclusions standing behind an investigation that says
        // it stopped. The causes' own transaction is a savepoint inside this one, so nothing is
        // committed until this commits.
        try {
            $this->rootCauses()->record((int)$record->id, $weighed['causes']);
            $this->step($record, $position, InvestigationStepType::DIAGNOSED, $finishedAt, [
                'summary' => $weighed['summary'],
                'note' => $weighed['note'],
            ]);

            $record->status = $status->value;
            $record->finishedAt = $this->forDb($finishedAt);
            $record->durationMs = $this->elapsed($run->context, $finishedAt);

            $this->save($record);
            $this->step($record, $position, InvestigationStepType::FINISHED, $finishedAt, [
                'status' => $record->originStatus,
                'note' => $status->label(),
            ]);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            // The steps written inside it went with it, so the timeline continues from where it
            // stood rather than leaving a gap where they were.
            $position = $start;

            throw $e;
        }
    }

    /**
     * Weighs everything the investigation found against the known causes, in memory: nothing is
     * written here, so what it concludes is kept only if the investigation's finish is.
     *
     * The case is the investigation's own: the results as the checks returned them, with all their
     * evidence rather than the share of it the timeline keeps, the errors they ran into, the issues
     * their findings landed on and the ones open nearby. Weighing that can fail without costing the
     * investigation what it found, so a failure is said in the timeline rather than thrown.
     *
     * @param array<string, int> $raised The issue each check's findings landed on, by check.
     * @param list<Issue> $nearby The issues open nearby, as they stood.
     * @return array{causes: list<RootCause>, summary: string|null, note: string}
     */
    private function weigh(Issue $issue, DiagnosticRun $run, array $raised, array $nearby): array
    {
        $summary = null;
        $causes = [];

        try {
            $known = $this->issues()->getMany(array_values(array_diff(array_unique(array_values($raised)), [$issue->id])));
            $case = new CorrelationCase(
                issue: IssueSnapshot::fromIssue($issue, $run->id()),
                results: $run->results(),
                issues: array_map(static fn(Issue $i): IssueSnapshot => IssueSnapshot::fromIssue($i, $run->id()), [...array_values($known), ...$nearby]),
                issueIds: $raised,
                environment: $run->context->environment,
                siteId: $run->context->siteId,
                root: $this->errors()->root(),
            );

            $analysis = $this->rootCauses()->analyse($case);
            $causes = $analysis->causes;

            $summary = $analysis->leading()?->title;
            $note = Craft::t('web-doctor', '{count, plural, =0{None of the {weighed} known causes fits what was found.} =1{1 of the {weighed} known causes fits what was found.} other{# of the {weighed} known causes fit what was found.}}', [
                'count' => count($analysis->causes),
                'weighed' => $analysis->weighed,
            ]);

            if ($analysis->failed !== []) {
                $note .= ' ' . Craft::t('web-doctor', '{count, plural, =1{1 known cause could not be weighed} other{# known causes could not be weighed}}; the details are in Craft’s logs.', [
                    'count' => count($analysis->failed),
                ]);
            }
        } catch (Throwable $e) {
            SafeException::log('What an investigation found could not be weighed against the known causes', $e);
            $note = Craft::t('web-doctor', 'What the checks found could not be weighed against the known causes. The details are in Craft’s logs.');
        }

        return ['causes' => $causes, 'summary' => $summary, 'note' => $note];
    }

    /**
     * Brings the Issue Center up to date with what the checks found.
     *
     * A failure here is reported in the timeline rather than failing the investigation: what the
     * checks found is still what they found, and the reader is owed it.
     *
     * @return IssueReconciliation|string The outcome, or what to tell the reader instead.
     */
    private function reconcile(DiagnosticRun $run): IssueReconciliation|string
    {
        try {
            return $this->issues()->reconcile($run);
        } catch (Throwable $e) {
            SafeException::log('The issue list could not be updated after an investigation', $e);

            return Craft::t('web-doctor', 'The checks ran, but the issue list could not be updated. The details are in Craft’s logs.');
        }
    }

    /**
     * Counts the errors the checks ran into against their groups. A failure here is reported in
     * the timeline, for the reason a failed reconciliation is.
     *
     * @return ErrorRecording|string The outcome, or what to tell the reader instead.
     */
    private function recordErrors(DiagnosticRun $run): ErrorRecording|string
    {
        try {
            return $this->errors()->record($run);
        } catch (Throwable $e) {
            SafeException::log('The errors an investigation ran into could not be recorded', $e);

            return Craft::t('web-doctor', 'The errors the checks ran into could not be recorded. The details are in Craft’s logs.');
        }
    }

    /**
     * Keeps the attempt, saying it stopped. Partly completed when at least one check's result was
     * recorded before it stopped — that record is still true and still worth reading — and failed
     * when nothing was.
     *
     * The reason kept names the kind of exception and nothing more. Its message is redacted of
     * credentials in the log, but a database exception quotes its SQL, and an investigation page
     * is read by people who may not see the internals. The detail is in Craft's logs.
     */
    private function fail(InvestigationRecord $record, int &$position, DiagnosticContext $context, Throwable $exception): void
    {
        SafeException::log('An investigation could not be completed', $exception);

        $now = new DateTimeImmutable();
        $failure = Craft::t('web-doctor', 'The investigation stopped because of an unexpected {type}. The details are in Craft’s logs.', [
            'type' => $this->kindOf($exception),
        ]);

        $record->status = ((int)$record->checksRun > 0 ? InvestigationStatus::PARTIAL : InvestigationStatus::FAILED)->value;
        $record->failure = $failure;
        $record->finishedAt = $this->forDb($now);
        $record->durationMs = $this->elapsed($context, $now);

        // A save inside a transaction that was then rolled back left what it wrote marked as
        // written. None of it was, so everything is written again: an attribute set to the value
        // that save gave it — the same status, a finish in the same second — would otherwise be
        // skipped as unchanged and leave the row saying it is still running.
        foreach (array_keys($record->getAttributes(null, ['id'])) as $attribute) {
            $record->markAttributeDirty($attribute);
        }

        try {
            $this->save($record);
            $this->step($record, $position, InvestigationStepType::FAILED, $now, ['note' => $failure]);
        } catch (Throwable $e) {
            // The database that would hold the record is plausibly what failed. The log has it.
            SafeException::log('A failed investigation could not be recorded', $e);
        }
    }

    /**
     * The issues this run's findings landed on, by the check that found each, in one query. Found
     * by the fingerprint the Issue Center itself uses, so the two cannot disagree about which issue
     * a result belongs to.
     *
     * @return array<string, int> Diagnostic ID to issue ID.
     */
    private function issuesRaisedBy(DiagnosticRun $run): array
    {
        $fingerprints = [];

        foreach ($run->results() as $result) {
            if ($result->status->isProblem()) {
                $fingerprints[$result->diagnosticId] = Fingerprint::forResult($result, $run->context->environment, $run->context->siteId);
            }
        }

        try {
            $ids = $this->issues()->idsByFingerprint(array_values($fingerprints));
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($fingerprints as $diagnosticId => $fingerprint) {
            if (isset($ids[$fingerprint])) {
                $out[$diagnosticId] = $ids[$fingerprint];
            }
        }

        return $out;
    }

    /**
     * Adds one recorded result to the investigation's counts.
     */
    private function count(InvestigationRecord $record, DiagnosticResult $result): void
    {
        $record->checksRun = (int)$record->checksRun + 1;

        match (true) {
            $result->status->isProblem() => $record->checksWithProblems = (int)$record->checksWithProblems + 1,
            $result->status === DiagnosticStatus::SKIPPED => $record->checksSkipped = (int)$record->checksSkipped + 1,
            !$result->status->isConclusive() => $record->checksIncomplete = (int)$record->checksIncomplete + 1,
            default => null,
        };
    }

    /**
     * Why an open issue counted as nearby, and where it stood then — kept on the step, because the
     * issue will move on and the investigation has to go on saying what it saw.
     */
    private function relatedBecause(Issue $issue, Issue $nearby): string
    {
        if ($issue->affectedPlugin !== null && $issue->affectedPlugin !== '' && $nearby->affectedPlugin === $issue->affectedPlugin) {
            return Craft::t('web-doctor', '{status} at the time. Related because it names the plugin “{plugin}” too.', [
                'status' => $nearby->status->label(),
                'plugin' => $nearby->affectedPlugin,
            ]);
        }

        return Craft::t('web-doctor', '{status} at the time. Related because it is in {category}, an area this investigation looked at.', [
            'status' => $nearby->status->label(),
            'category' => $nearby->category->label(),
        ]);
    }

    /**
     * An exception's short class name. An anonymous class's name carries the path of the file it
     * was declared in, so it is named by what it extends instead.
     */
    private function kindOf(Throwable $exception): string
    {
        $class = $exception::class;

        if (str_contains($class, '@anonymous')) {
            $class = get_parent_class($exception) ?: 'exception';
        }

        return substr($class, (int)strrpos('\\' . $class, '\\'));
    }

    private function elapsed(DiagnosticContext $context, DateTimeInterface $until): float
    {
        return max(0.0, ((float)$until->format('U.u') - (float)$context->startedAt->format('U.u')) * 1000);
    }

    /**
     * Whether a site is still there — enabled or not, but not in the trash.
     */
    private function siteExists(int $siteId): bool
    {
        try {
            return Craft::$app->getSites()->getSiteById($siteId, true) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The planned checks, as the registry holds them. An ID the registry no longer knows is passed
     * on as it is, and the engine records it as a check that could not be run.
     *
     * @return list<DiagnosticInterface|string>
     */
    private function diagnosticsFor(InvestigationPlan $plan): array
    {
        return array_map(
            fn(string $id): DiagnosticInterface|string => $this->registry()->get($id) ?? $id,
            $plan->diagnosticIds(),
        );
    }

    /**
     * Drops an issue's investigations beyond its limit, oldest first. Their steps go with them.
     */
    private function prune(int $issueId): void
    {
        try {
            $stale = InvestigationRecord::find()
                ->select(['id'])
                ->where(['issueId' => $issueId])
                ->orderBy(['startedAt' => SORT_DESC, 'id' => SORT_DESC])
                ->offset(max(1, $this->maxPerIssue))
                ->column();

            if ($stale !== []) {
                InvestigationRecord::deleteAll(['id' => $stale]);
            }
        } catch (Throwable $e) {
            // Keeping one investigation too many is not worth losing the one just made.
            SafeException::log('Old investigations could not be removed', $e);
        }
    }

    /**
     * Appends to an investigation's timeline. A seam rather than a private call, so a test can
     * make a step fail to save and prove what is kept when one does.
     *
     * Everything written is redacted on the way in: the
     * summaries and names come from checks, including other plugins', and the evidence has been
     * redacted once already — but this is a serialization boundary, and every one of those redacts.
     *
     * @param array{diagnosticId?: string|null, diagnosticName?: string|null, status?: string|null, severity?: string|null, summary?: string|null, note?: string|null, relatedIssueId?: int|null, evidence?: list<Evidence>, evidenceCount?: int, durationMs?: float|null} $fields
     */
    protected function step(InvestigationRecord $investigation, int &$position, InvestigationStepType $type, DateTimeInterface $at, array $fields = []): void
    {
        $evidence = $fields['evidence'] ?? [];
        $text = static fn(?string $value): ?string => $value === null || $value === '' ? null : Redaction::redactString($value);

        $step = new InvestigationStepRecord();
        $step->investigationId = (int)$investigation->id;
        $step->position = $position;
        $step->type = $type->value;
        $step->diagnosticId = $this->fit($text($fields['diagnosticId'] ?? null), 100);
        $step->diagnosticName = $this->fit($text($fields['diagnosticName'] ?? null), 255);
        $step->status = $fields['status'] ?? null;
        $step->severity = $fields['severity'] ?? null;
        $step->summary = $text($fields['summary'] ?? null);
        $step->note = $text($fields['note'] ?? null);
        $step->relatedIssueId = $fields['relatedIssueId'] ?? null;
        $step->evidence = $evidence === []
            ? null
            : Evidence::encode(Redaction::redact(array_map(static fn(Evidence $e): array => $e->jsonSerialize(), $evidence)));
        $step->evidenceCount = $fields['evidenceCount'] ?? count($evidence);
        $step->evidenceTruncated = count($evidence) < $step->evidenceCount;
        $step->durationMs = $fields['durationMs'] ?? null;
        $step->occurredAt = $this->forDb($at);

        $this->save($step);

        // Only once it is written, so a step that could not be saved leaves no gap behind it.
        $position++;
    }

    /**
     * @throws RuntimeException if the row will not save, because an investigation that thinks it
     * recorded something and did not is worse than one that knows it failed.
     */
    private function save(InvestigationRecord|InvestigationStepRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor investigation row could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }

    private function environment(): string
    {
        return $this->environment ?? DiagnosticContext::currentEnvironment();
    }

    private function registry(): Diagnostics
    {
        return $this->registry ??= WebDoctor::getInstance()?->getDiagnostics() ?? new Diagnostics();
    }

    private function engine(): DiagnosticEngine
    {
        return $this->engine ??= WebDoctor::getInstance()?->getDiagnosticEngine() ?? new DiagnosticEngine(['registry' => $this->registry()]);
    }

    private function issues(): Issues
    {
        return $this->issues ??= WebDoctor::getInstance()?->getIssues() ?? new Issues();
    }

    private function errors(): Errors
    {
        return $this->errors ??= WebDoctor::getInstance()?->getErrors() ?? new Errors(['issues' => $this->issues()]);
    }

    private function rootCauses(): RootCauses
    {
        return $this->rootCauses ??= WebDoctor::getInstance()?->getRootCauses() ?? new RootCauses();
    }

    /**
     * A moment as the database stores it: UTC, in Craft's own format.
     */
    private function forDb(DateTimeInterface $when): string
    {
        return Db::prepareDateForDb($when) ?? gmdate('Y-m-d H:i:s');
    }

    private function fit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
