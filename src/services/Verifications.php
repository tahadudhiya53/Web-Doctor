<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\base\VerificationActionInterface;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\ExecutionMode;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\DiagnosticMeta;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Savepoint;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\InvestigationPlan;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\models\StoredEvidence;
use Tahadudhiya\WebDoctor\models\Verification;
use Tahadudhiya\WebDoctor\models\VerificationCondition;
use Tahadudhiya\WebDoctor\records\VerificationRecord;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

/**
 * Establishes whether a repair worked, and keeps the record of each attempt.
 *
 * A repair that ran is not a problem that went away. Verifying one runs, through the engine, the
 * check that found the problem, the checks the repair names, and the rest of the problem's area;
 * reads what that particular repair should have left true ({@see VerificationActions}); compares the
 * evidence the issue last recorded with what the check records now; and asks whether any problem
 * or error has appeared since the repair started. The checks' findings go through the Issue
 * Center's own reconciliation, exactly as any run's do, so the issue and the verification cannot
 * give two answers to one question.
 *
 * The answer is one of three, and the rule is deliberately lopsided:
 *
 * - **failed** only when the check that found the problem reached a conclusion and still reports
 *   it. That run has put the issue back to open, so a failed verification never sits beside a
 *   resolved issue.
 * - **verified** only when that check reached a conclusion and no longer reports it, every other
 *   check answered, everything the repair should have left true holds, nothing new appeared since
 *   the repair, and the Issue Center and the error groups took the run. The issue's resolution then
 *   says it was verified rather than merely observed clear.
 * - **inconclusive** for everything else, each reason listed. A check that could not answer, a
 *   retried job that has not run yet, a new error — none of those is evidence the repair failed, and
 *   none is evidence it worked.
 *
 * It changes nothing in the installation: checks and verification actions only read.
 */
class Verifications extends Component
{
    /** @var int The most verifications a repair's page lists. */
    public const HISTORY_LIMIT = 10;

    /** @var int The most pieces of evidence kept for each side of the comparison; the rest are counted. */
    public const MAX_STATE = 10;

    /** @var int The most verifications one repair keeps; the oldest go first. */
    public int $maxPerRepair = 20;

    /** @var int The most checks one verification runs. */
    public int $maxChecks = InvestigationPlan::MAX_CHECKS;

    /** @var Repairs|null Where repairs are read and authorised. The plugin's own unless injected. */
    public ?Repairs $repairs = null;

    /** @var VerificationActions|null What each repair should have left true. The plugin's own unless injected. */
    public ?VerificationActions $actions = null;

    /** @var Diagnostics|null Where checks are looked up. The plugin's own unless injected. */
    public ?Diagnostics $registry = null;

    /** @var DiagnosticEngine|null What runs them. The plugin's own unless injected. */
    public ?DiagnosticEngine $engine = null;

    /** @var Issues|null The Issue Center. The plugin's own unless injected. */
    public ?Issues $issues = null;

    /** @var Errors|null Where the errors the checks meet are grouped. The plugin's own unless injected. */
    public ?Errors $errors = null;

    /** @var EvidenceStore|null Where the issue's recorded finding is read. The plugin's own unless injected. */
    public ?EvidenceStore $evidence = null;

    /**
     * @var string|null The environment this installation is running as. Craft's answer unless set,
     * which a test does so that a repair made under its own environment can be verified.
     */
    public ?string $environment = null;

    /**
     * Why a repair cannot be verified here now, or null where it can. Reads only; writes nothing.
     */
    public function refusal(Repair $repair, Issue $issue): ?string
    {
        if ($repair->issueId !== $issue->id) {
            return Craft::t('web-doctor', 'No such repair of this issue.');
        }

        if ($repair->status !== RepairStatus::SUCCEEDED) {
            return $repair->status === RepairStatus::FAILED
                ? Craft::t('web-doctor', 'This repair did not finish cleanly, so there is no result of it to verify. Run the checks listed, or investigate the issue, to see where things stand.')
                : Craft::t('web-doctor', 'This repair has not been carried out, so there is nothing to verify yet.');
        }

        if (!$repair->isIntact()) {
            return Craft::t('web-doctor', 'This repair’s record cannot be read in full, so what it should have left true is not known and it cannot be verified.');
        }

        if ($this->repairs()->latestCarriedOut($issue->id)?->id !== $repair->id) {
            return Craft::t('web-doctor', 'A later repair of this issue has been carried out since this one, and has changed things since. Verify that one instead.');
        }

        // The repair was made for the issue's site; one filed under another site describes a
        // different place, whatever issue it points at.
        if ($repair->siteId !== $issue->siteId || $repair->diagnosticId !== $issue->diagnosticId) {
            return Craft::t('web-doctor', 'No such repair of this issue.');
        }

        if ($issue->status === IssueStatus::REPAIRING) {
            return Craft::t('web-doctor', 'This issue is being repaired now. Verify once that repair has finished.');
        }

        $here = $this->environment();

        if ($repair->environment !== $here || $issue->environment !== $here) {
            return Craft::t('web-doctor', 'This repair was carried out in the “{found}” environment and this installation is running as “{here}”. Checks run here would describe a different environment, so it has to be verified where it was carried out.', [
                'found' => $repair->environment,
                'here' => $here,
            ]);
        }

        // Craft soft-deletes sites, so a site in the trash still has its ID on the issue.
        if (($issue->siteId === null && $issue->siteName !== null) || ($issue->siteId !== null && !$this->siteExists($issue->siteId))) {
            return Craft::t('web-doctor', 'The site this repair was carried out on, “{site}”, has been deleted, so there is nothing left to verify it against.', [
                'site' => $issue->siteName ?? '#' . $issue->siteId,
            ]);
        }

        return null;
    }

    /**
     * Which checks verifying a repair runs, in the order they run, and why each. The check that
     * found the problem first, then the others the repair names, then the rest of the problem's area
     * as a shallow investigation would reach it — each once, for its closest reason. A check that is
     * not registered here is listed, and cannot run.
     *
     * @return list<array{diagnosticId: string, name: string, role: string, reason: string, diagnostic: DiagnosticInterface|null}>
     */
    public function plan(Repair $repair, Issue $issue): array
    {
        $this->bounds();

        $planned = [];
        $add = function(string $id, string $role, string $reason) use (&$planned): void {
            if (isset($planned[$id]) || count($planned) >= $this->maxChecks) {
                return;
            }

            $diagnostic = $this->registry()->get($id);
            $planned[$id] = [
                'diagnosticId' => $id,
                'name' => $diagnostic === null ? $id : DiagnosticMeta::name($diagnostic, $id),
                'role' => $role,
                'reason' => $reason,
                'diagnostic' => $diagnostic,
            ];
        };

        $add($repair->diagnosticId, Verification::ROLE_ORIGINAL, Craft::t('web-doctor', 'The check that found the problem. Its answer decides whether the problem is gone.'));

        foreach (array_slice($repair->verifyWith, 1) as $id) {
            $add($id, Verification::ROLE_NAMED, Craft::t('web-doctor', 'Named by the repair as showing whether it worked.'));
        }

        $area = InvestigationPlan::build($issue->diagnosticId, $issue->category, null, $this->registry()->all(), DiagnosticDepth::SHALLOW, $this->maxChecks);

        foreach ($area->diagnosticIds() as $id) {
            $add($id, Verification::ROLE_AREA, Craft::t('web-doctor', 'In the same area as the problem, {category}: a repair there can affect it.', [
                'category' => $issue->category->label(),
            ]));
        }

        return array_values($planned);
    }

    /**
     * Verifies a carried-out repair, records what was found, and returns it.
     *
     * @throws Refusal if the person may not, or the repair cannot be verified here now.
     */
    public function verify(int $repairId, int $issueId): Verification
    {
        $this->bounds();

        // Who is asking is Craft's answer, established here as it is for carrying a repair out.
        $userId = $this->repairs()->authorize();

        $repair = $this->repairs()->get($repairId);
        $issue = $this->issues()->get($issueId);

        if ($repair === null || $issue === null || $repair->issueId !== $issueId) {
            throw new Refusal(Craft::t('web-doctor', 'No such repair of this issue.'));
        }

        // One verification of an issue at a time, so a slower one can never finish after, and
        // overwrite, one that was started later. Craft's own mutex, held across processes.
        $mutex = Craft::$app->getMutex();
        $lock = 'web-doctor:verify:' . $issueId;

        if (!$mutex->acquire($lock)) {
            throw new Refusal(Craft::t('web-doctor', 'This issue is being verified now. Wait for that to finish, then look at its answer.'));
        }

        try {
            return $this->conduct($repair, $issue, $userId);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Everything after the lock: the refusals, read again now that nothing else is verifying this
     * issue, then the run, the answer and the record.
     *
     * @throws Refusal
     */
    private function conduct(Repair $repair, Issue $issue, int $userId): Verification
    {
        // Read again under the lock: what was read before it may have changed while it was awaited.
        $repair = $this->repairs()->get($repair->id) ?? throw new Refusal(Craft::t('web-doctor', 'No such repair of this issue.'));
        $issue = $this->issues()->get($issue->id) ?? throw new Refusal(Craft::t('web-doctor', 'No such repair of this issue.'));
        $refusal = $this->refusal($repair, $issue);

        if ($refusal !== null) {
            throw new Refusal($refusal);
        }

        // What stands when the checks begin, to be compared under lock when the answer is applied.
        $knownVerification = $this->latestVerificationId($repair->id, false);
        $since = $repair->startedAt ?? $repair->previewedAt;
        $context = new DiagnosticContext(
            siteId: $issue->siteId,
            environment: $issue->environment,
            mode: Craft::$app instanceof ConsoleApplication ? ExecutionMode::CONSOLE : ExecutionMode::MANUAL,
            depth: DiagnosticDepth::NORMAL,
        );
        $plan = $this->plan($repair, $issue);
        $failures = [];

        // The finding as the issue last recorded it, read before anything runs and changes it.
        $before = $this->before($issue, $failures);

        $run = $this->engine()->runMany(
            array_values(array_filter(array_column($plan, 'diagnostic'))),
            $context,
        );
        $conditions = $this->conditions($repair);

        $reconciled = $this->reconcile($run, $issue, $failures);
        $this->recordErrors($run, $failures);

        $checks = $this->checks($plan, $run, $issue, $since, $reconciled);
        $errors = $this->errorsMet($run, $since);
        $original = $run->resultFor($repair->diagnosticId);
        $after = $original?->evidence() ?? [];
        $result = $this->judge($checks, $conditions, $errors, $failures, $before === null ? null : count($before), count($after));
        $before ??= [];

        $record = new VerificationRecord();
        $record->repairId = $repair->id;
        $record->issueId = $issue->id;
        $record->diagnosticId = $repair->diagnosticId;
        $record->action = $repair->action;
        $record->environment = $context->environment;
        $record->siteId = $issue->siteId;
        $record->runId = $run->id();
        $record->checks = Evidence::encode(array_map(static fn(array $c): array => array_diff_key($c, ['diagnostic' => true]), $checks));
        $record->conditions = Evidence::encode($conditions);
        $record->originalState = Evidence::encode($this->bounded($before));
        $record->currentState = Evidence::encode($this->bounded($after));
        $record->comparison = Evidence::encode($this->compare($before, $after));
        $record->errors = Evidence::encode($errors);
        $record->verifiedBy = $userId;
        $record->startedAt = $this->forDb($context->startedAt);

        $note = fn(VerificationStatus $stands): string => match ($stands) {
            VerificationStatus::VERIFIED => Craft::t('web-doctor', '“{name}” was verified.', ['name' => $repair->actionName]),
            VerificationStatus::FAILED => Craft::t('web-doctor', '“{name}” was completed, but verification failed.', ['name' => $repair->actionName]),
            default => Craft::t('web-doctor', 'Verifying “{name}” was inconclusive: {reason}', [
                'name' => $repair->actionName,
                'reason' => $failures[0] ?? Craft::t('web-doctor', 'The issue or its repairs changed while this was being verified, so its answer could not be applied: verify again.'),
            ]),
        };

        // The record, the issue and the repair's answer are one act: a repair saying it was verified
        // with no verification behind it would be a claim nobody can check. What stands is decided
        // inside it, by locking reads of the repair, its verifications and the issue as they are
        // now — not as they were when the checks began, which may have gone.
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $superseded = $this->latestVerificationId($repair->id, true) !== $knownVerification;
            $latest = $this->repairs()->latestCarriedOut($issue->id, true);

            if ($superseded) {
                // Another verification of this repair was recorded while this one ran. Its answer is
                // the newer one and stands; this one is kept as what it saw, and changes nothing.
                $stands = VerificationStatus::INCONCLUSIVE;
                $failures[] = Craft::t('web-doctor', 'Another verification of this repair was recorded while this one ran. Its answer stands; this one changes nothing.');
            } else {
                $stands = $latest?->id === $repair->id
                    ? $this->issues()->recordVerification($issue->id, $result, $issue->latestRunId, $issue->status, $note, $run->id(), $userId)
                    : VerificationStatus::INCONCLUSIVE;

                if ($stands !== $result) {
                    $failures[] = Craft::t('web-doctor', 'The issue or its repairs changed while this was being verified, so its answer could not be applied: verify again.');
                }
            }

            $record->result = $stands->value;
            $record->failures = Evidence::encode($failures);
            $record->finishedAt = $this->forDb(new DateTimeImmutable());
            $record->durationMs = round(max(0.0, (microtime(true) - (float)$context->startedAt->format('U.u')) * 1000), 3);

            $this->save($record);

            if (!$superseded) {
                $this->repairs()->recordVerification($repair->id, $stands);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->prune($repair->id);

        Craft::info(Redaction::redactString(sprintf(
            'Repair %d (%s) of issue %d verified in the "%s" environment by user %d: %s.',
            $repair->id,
            $repair->action,
            $issue->id,
            $context->environment,
            $userId,
            $stands->value,
        )), WebDoctor::LOG_CATEGORY);

        return Verification::fromRecord($record);
    }

    /**
     * The newest verification recorded for a repair, or 0 where there is none; with a lock, the
     * newest committed now, held until the transaction reading it ends.
     */
    private function latestVerificationId(int $repairId, bool $locking): int
    {
        $query = VerificationRecord::find()->select(['id'])->where(['repairId' => $repairId])->orderBy(['id' => SORT_DESC])->limit(1);
        $record = $locking ? (Savepoint::committed($query)[0] ?? null) : $query->one();

        return $record instanceof VerificationRecord ? (int)$record->id : 0;
    }

    /**
     * The issue's last recorded finding, or null where it cannot be read — which is not an empty
     * finding: there is then nothing to compare with, and that is said.
     *
     * @param list<string> $failures
     * @return list<Evidence>|null
     */
    private function before(Issue $issue, array &$failures): ?array
    {
        try {
            return array_map(
                static fn(StoredEvidence $stored): Evidence => $stored->evidence,
                $this->evidenceStore()->latest($issue->id, $issue->latestRunId),
            );
        } catch (Throwable $e) {
            SafeException::log('The evidence behind an issue could not be read to verify its repair', $e);
            $failures[] = Craft::t('web-doctor', 'The evidence the issue last recorded could not be read, so there is nothing to compare what the check found now with. The details are in Craft’s logs.');

            return null;
        }
    }

    public function get(int $id): ?Verification
    {
        $record = VerificationRecord::findOne(['id' => $id]);

        return $record === null ? null : Verification::fromRecord($record);
    }

    /**
     * A repair's verifications, newest first.
     *
     * @return list<Verification>
     * @throws InvalidArgumentException for a limit below one.
     */
    public function forRepair(int $repairId, int $limit = self::HISTORY_LIMIT): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('A limit of at least 1 is needed; %d was given.', $limit));
        }

        $out = [];

        foreach (VerificationRecord::find()->where(['repairId' => $repairId])->orderBy(['startedAt' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all() as $record) {
            if ($record instanceof VerificationRecord) {
                $out[] = Verification::fromRecord($record);
            }
        }

        return $out;
    }

    public function environment(): string
    {
        return $this->environment ?? DiagnosticContext::currentEnvironment();
    }

    /**
     * The answer, from everything looked at. Failed only on the one ground that also keeps the
     * issue open; everything short of a clean answer on every count is inconclusive, and each
     * reason is added to `$failures` in the order a reader should meet them.
     *
     * @param list<array{diagnosticId: string, name: string, role: string, reason: string, status: \Tahadudhiya\WebDoctor\enums\DiagnosticStatus|null, severity: \Tahadudhiya\WebDoctor\enums\Severity|null, summary: string|null, issueId: int|null, persists: bool, appeared: bool|null}> $checks
     * @param list<VerificationCondition> $conditions
     * @param list<array{fingerprint: string, groupId: int|null, exceptionClass: string, diagnosticIds: list<string>, new: bool|null}> $errors
     * @param list<string> $failures What has already gone wrong recording the run; added to.
     * @param int|null $beforeCount How much evidence the issue last recorded; null where it could not be read.
     * @param int $afterCount How much evidence the check that found the problem recorded now.
     */
    private function judge(array $checks, array $conditions, array $errors, array &$failures, ?int $beforeCount, int $afterCount): VerificationStatus
    {
        $reasons = [];
        $failed = false;

        foreach ($checks as $check) {
            $status = $check['status'];

            if ($check['role'] === Verification::ROLE_ORIGINAL) {
                if ($status === null) {
                    $reasons[] = Craft::t('web-doctor', 'The check that found the problem, {id}, is not registered here, so it could not run again.', ['id' => $check['diagnosticId']]);
                } elseif (!$status->isConclusive()) {
                    $reasons[] = Craft::t('web-doctor', 'The check that found the problem could not answer ({status}): {summary}', ['status' => $status->label(), 'summary' => (string)$check['summary']]);
                } elseif ($check['persists']) {
                    $failed = true;
                    $reasons[] = Craft::t('web-doctor', 'The check that found the problem still reports it: {summary}', ['summary' => (string)$check['summary']]);
                } elseif ($status->isProblem()) {
                    // Not the problem being verified, but not a clean answer either: the check that
                    // decides whether the repair worked has to have nothing left to report.
                    $reasons[] = Craft::t('web-doctor', 'The check that found the problem still reports a problem, though not the one being verified: {summary}', ['summary' => (string)$check['summary']]);
                } elseif ($afterCount === 0) {
                    $reasons[] = Craft::t('web-doctor', 'The check that found the problem recorded no evidence for its answer, so there is nothing to show the problem has gone.');
                }

                continue;
            }

            if ($status === null) {
                $reasons[] = Craft::t('web-doctor', '{name} is not registered here, so it could not run.', ['name' => $check['name']]);
            } elseif (!$status->isConclusive()) {
                $reasons[] = Craft::t('web-doctor', '{name} could not answer ({status}).', ['name' => $check['name'], 'status' => $status->label()]);
            } elseif ($status->isProblem() && $check['appeared'] !== false) {
                $reasons[] = $check['appeared'] === true
                    ? Craft::t('web-doctor', '{name} reports a problem that appeared since the repair: {summary}', ['name' => $check['name'], 'summary' => (string)$check['summary']])
                    : Craft::t('web-doctor', '{name} reports a problem, and whether it appeared since the repair could not be told: {summary}', ['name' => $check['name'], 'summary' => (string)$check['summary']]);
            }
        }

        if ($beforeCount === 0) {
            $reasons[] = Craft::t('web-doctor', 'The issue’s last recorded finding holds no evidence, so there is nothing to compare what the check found now with.');
        }

        foreach ($conditions as $condition) {
            $detail = $condition->detail !== null ? ' (' . $condition->detail . ')' : '';

            // Conclusively not true is a failure of the repair, whatever the checks say.
            if ($condition->state === VerificationCondition::NOT_HELD) {
                $failed = true;
            }

            $reasons[] = match ($condition->state) {
                VerificationCondition::NOT_HELD => Craft::t('web-doctor', 'What the repair should have left true does not hold: {condition}', ['condition' => $condition->description . $detail]),
                VerificationCondition::HELD => null,
                default => Craft::t('web-doctor', 'Whether this holds cannot be told yet: {condition}', ['condition' => $condition->description . $detail]),
            };
        }

        $new = count(array_filter($errors, static fn(array $e): bool => $e['new'] === true));
        $untold = count(array_filter($errors, static fn(array $e): bool => $e['new'] === null));

        if ($new > 0) {
            $reasons[] = Craft::t('web-doctor', 'The checks ran into {count, plural, =1{an error} other{# errors}} first seen since the repair.', ['count' => $new]);
        }

        // An error Web Doctor could not match to a group — the recording failed, or the bound left it
        // out — may be new. Where the recording failed, that is already said.
        if ($untold > 0) {
            $reasons[] = Craft::t('web-doctor', 'Whether {count, plural, =1{an error} other{# errors}} the checks ran into appeared since the repair could not be told.', ['count' => $untold]);
        }

        $failures = array_values(array_filter([...$reasons, ...$failures], static fn(?string $r): bool => $r !== null && $r !== ''));

        return match (true) {
            $failed => VerificationStatus::FAILED,
            $failures === [] => VerificationStatus::VERIFIED,
            default => VerificationStatus::INCONCLUSIVE,
        };
    }

    /**
     * Each planned check with what it answered: whether it found the problem being verified again,
     * and of any other problem whether it appeared since the repair started.
     *
     * @param list<array{diagnosticId: string, name: string, role: string, reason: string, diagnostic: DiagnosticInterface|null}> $plan
     * @return list<array{diagnosticId: string, name: string, role: string, reason: string, status: \Tahadudhiya\WebDoctor\enums\DiagnosticStatus|null, severity: \Tahadudhiya\WebDoctor\enums\Severity|null, summary: string|null, issueId: int|null, persists: bool, appeared: bool|null}>
     */
    private function checks(array $plan, DiagnosticRun $run, Issue $issue, DateTimeInterface $since, bool $reconciled): array
    {
        $environment = $run->context->environment;
        $siteId = $run->context->siteId;
        $fingerprints = [];

        foreach ($run->results() as $result) {
            if ($result->status->isProblem()) {
                $fingerprints[$result->diagnosticId] = Fingerprint::forResult($result, $environment, $siteId);
            }
        }

        $issueIds = [];
        $appeared = null;

        try {
            $issueIds = $this->issues()->idsByFingerprint(array_values($fingerprints));
            // Only after the Issue Center took the run can "appeared since" be read from it.
            $appeared = $reconciled ? $this->issues()->appearedSince(array_values(array_diff($issueIds, [$issue->id])), $since) : null;
        } catch (Throwable $e) {
            SafeException::log('The issues a verification\'s findings landed on could not be read', $e);
        }

        $out = [];

        foreach ($plan as $entry) {
            $result = $entry['diagnostic'] === null ? null : $run->resultFor($entry['diagnosticId']);
            $fingerprint = $fingerprints[$entry['diagnosticId']] ?? null;
            $landedOn = $fingerprint === null ? null : ($issueIds[$fingerprint] ?? null);
            $persists = $fingerprint !== null && $fingerprint === $issue->fingerprint;

            $out[] = [
                ...$entry,
                'status' => $result?->status,
                'severity' => $result?->severity(),
                'summary' => $result === null
                    ? Craft::t('web-doctor', 'Not registered here, so it did not run.')
                    : $result->summary,
                'issueId' => $landedOn,
                'persists' => $persists,
                // Said only of a problem other than the one being verified; unknown where the
                // Issue Center could not say.
                'appeared' => $fingerprint === null || $persists || $landedOn === null || $appeared === null
                    ? null
                    : in_array($landedOn, $appeared, true),
            ];
        }

        return $out;
    }

    /**
     * The errors the checks ran into, each with its group and whether it was first seen since the
     * repair started — read after the run was recorded, so a group the run itself created is new.
     *
     * @return list<array{fingerprint: string, groupId: int|null, exceptionClass: string, diagnosticIds: list<string>, new: bool|null}>
     */
    private function errorsMet(DiagnosticRun $run, DateTimeInterface $since): array
    {
        $met = [];

        foreach ($run->results() as $result) {
            try {
                $signatures = $this->errors()->signatures($result->evidence());
            } catch (Throwable $e) {
                // An error that cannot be identified cannot be told to be old, so it is not one.
                SafeException::log('The errors a check ran into could not be identified to verify a repair', $e);
                $met['unreadable:' . $result->diagnosticId] = ['fingerprint' => 'unreadable:' . $result->diagnosticId, 'groupId' => null, 'exceptionClass' => '', 'diagnosticIds' => [$result->diagnosticId], 'new' => null];

                continue;
            }

            foreach ($signatures as $signature) {
                $fingerprint = $signature->fingerprint($run->context->environment, $run->context->siteId);
                $met[$fingerprint] ??= ['fingerprint' => $fingerprint, 'groupId' => null, 'exceptionClass' => $signature->shortClass(), 'diagnosticIds' => [], 'new' => null];
                $met[$fingerprint]['diagnosticIds'][] = $result->diagnosticId;
            }
        }

        if ($met === []) {
            return [];
        }

        try {
            $groups = $this->errors()->byFingerprints(array_keys($met));
        } catch (Throwable $e) {
            SafeException::log('The errors a verification ran into could not be read back', $e);
            $groups = [];
        }

        foreach ($met as $fingerprint => $error) {
            $group = $groups[$fingerprint] ?? null;

            if ($group !== null) {
                $met[$fingerprint]['groupId'] = $group->id;
                $met[$fingerprint]['new'] = $group->firstSeen->getTimestamp() >= $since->getTimestamp();
            }

            $met[$fingerprint]['diagnosticIds'] = array_values(array_unique($met[$fingerprint]['diagnosticIds']));
        }

        ksort($met, SORT_STRING);

        return array_values($met);
    }

    /**
     * What the repair should have left true, from every verification action written for its kind.
     * One that throws is contained: its condition reads as undetermined, never as held.
     *
     * @return list<VerificationCondition>
     */
    private function conditions(Repair $repair): array
    {
        try {
            $actions = $this->actions()->forRepairAction($repair->action);
        } catch (Throwable $e) {
            SafeException::log('The verification actions could not be read', $e);
            $actions = [];
        }

        // What a repair should have left true is part of whether it worked. Without an action to
        // check it — none registered, or one that failed to register — that part is unknown.
        if ($actions === []) {
            Craft::warning(sprintf('No verification action is registered for the repair action %s, so its repairs cannot be verified.', $repair->action), WebDoctor::LOG_CATEGORY);

            return [VerificationCondition::undetermined('verifiable', Craft::t('web-doctor', 'What this kind of repair should have left true is checked.'), Craft::t('web-doctor', 'No verification action is registered for “{action}”, so it cannot be checked.', ['action' => $repair->action]))];
        }

        $out = [];

        foreach ($actions as $action) {
            try {
                $read = $this->valid($action->conditions($repair));
            } catch (Throwable $e) {
                SafeException::log(sprintf('The verification action %s could not read the installation', $this->idOf($action)), $e);
                $read = null;
            }

            // A list that is empty, or holds anything but conditions, established nothing.
            if ($read === null || $read === []) {
                $out[] = VerificationCondition::undetermined('unreadable', Craft::t('web-doctor', 'What “{name}” checks could not be read. The details are in Craft’s logs.', ['name' => $this->nameOf($action)]));

                continue;
            }

            array_push($out, ...$read);
        }

        return $out;
    }

    /**
     * What an action returned, as conditions, or null where it returned anything else. Taken as
     * mixed because the action may be another plugin's.
     *
     * @return list<VerificationCondition>|null
     */
    private function valid(mixed $returned): ?array
    {
        if (!is_array($returned) || !array_is_list($returned)) {
            return null;
        }

        foreach ($returned as $condition) {
            if (!$condition instanceof VerificationCondition) {
                return null;
            }
        }

        return $returned;
    }

    /**
     * Brings the Issue Center up to date with what the checks found. A failure is a reason the
     * verification cannot be clean — the issue would then say something other than what was found —
     * rather than a reason to lose what the checks found.
     *
     * @param list<string> $failures
     */
    private function reconcile(DiagnosticRun $run, Issue $issue, array &$failures): bool
    {
        try {
            // The verified issue is left for the verification to settle: only a verified repair may
            // resolve it, so the check going quiet is not by itself allowed to.
            $this->issues()->reconcile($run, [$issue->fingerprint]);

            return true;
        } catch (Throwable $e) {
            SafeException::log('The issue list could not be updated after a verification', $e);
            $failures[] = Craft::t('web-doctor', 'The issue list could not be updated with what the checks found, so the issue does not yet say what they found. The details are in Craft’s logs.');

            return false;
        }
    }

    /**
     * @param list<string> $failures
     */
    private function recordErrors(DiagnosticRun $run, array &$failures): bool
    {
        try {
            $this->errors()->record($run);

            return true;
        } catch (Throwable $e) {
            SafeException::log('The errors a verification ran into could not be recorded', $e);
            $failures[] = Craft::t('web-doctor', 'The errors the checks ran into could not be recorded, so whether any is new could not be told. The details are in Craft’s logs.');

            return false;
        }
    }

    /**
     * Which facts are the same before and after, which have gone, and which are new — by what the
     * fact is, never by when it was seen.
     *
     * @param list<Evidence> $before
     * @param list<Evidence> $after
     * @return array{persisting: list<array{type: string, label: string}>, gone: list<array{type: string, label: string}>, appeared: list<array{type: string, label: string}>}
     */
    private function compare(array $before, array $after): array
    {
        $index = static function(array $evidence): array {
            $out = [];

            foreach ($evidence as $e) {
                $out[$e->digest()] = ['type' => $e->type->value, 'label' => $e->label];
            }

            ksort($out, SORT_STRING);

            return $out;
        };

        $was = $index($before);
        $is = $index($after);

        return [
            'persisting' => array_values(array_intersect_key($is, $was)),
            'gone' => array_values(array_diff_key($was, $is)),
            'appeared' => array_values(array_diff_key($is, $was)),
        ];
    }

    /**
     * @param list<Evidence> $evidence
     * @return array{evidence: list<array<string, mixed>>, count: int}
     */
    private function bounded(array $evidence): array
    {
        return [
            'evidence' => array_map(static fn(Evidence $e): array => $e->jsonSerialize(), array_slice($evidence, 0, self::MAX_STATE)),
            'count' => count($evidence),
        ];
    }

    /**
     * Drops a repair's verifications beyond the limit, oldest first.
     */
    private function prune(int $repairId): void
    {
        try {
            $stale = VerificationRecord::find()
                ->select(['id'])
                ->where(['repairId' => $repairId])
                ->orderBy(['startedAt' => SORT_DESC, 'id' => SORT_DESC])
                ->offset($this->maxPerRepair)
                ->column();

            if ($stale !== []) {
                VerificationRecord::deleteAll(['id' => $stale]);
            }
        } catch (Throwable $e) {
            SafeException::log('Old verifications could not be removed', $e);
        }
    }

    /**
     * @throws InvalidConfigException
     */
    private function bounds(): void
    {
        foreach (['maxPerRepair', 'maxChecks'] as $property) {
            if ($this->$property < 1) {
                throw new InvalidConfigException(sprintf('Web Doctor\'s verifications component needs a %s of at least 1; %d was configured.', $property, $this->$property));
            }
        }
    }

    private function idOf(VerificationActionInterface $action): string
    {
        try {
            return $action->id();
        } catch (Throwable) {
            return $action::class;
        }
    }

    private function nameOf(VerificationActionInterface $action): string
    {
        try {
            return Redaction::redactString($action->name());
        } catch (Throwable) {
            return $action::class;
        }
    }

    private function siteExists(int $siteId): bool
    {
        try {
            return Craft::$app->getSites()->getSiteById($siteId, true) !== null;
        } catch (Throwable $e) {
            SafeException::log('Whether a site still exists could not be established', $e);

            return false;
        }
    }

    /**
     * A seam rather than a private call, so a test can make a verification's row fail to save and
     * prove nothing of it stands.
     *
     * @throws RuntimeException if the row will not save.
     */
    protected function save(VerificationRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor verification row could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }

    private function forDb(DateTimeInterface $when): string
    {
        return Db::prepareDateForDb($when) ?? gmdate('Y-m-d H:i:s');
    }

    /**
     * The plugin's own dependencies, or the ones injected — never a second, unconfigured copy, for
     * the reason {@see Repairs} gives.
     *
     * @throws InvalidConfigException
     */
    private function repairs(): Repairs
    {
        return $this->repairs ??= WebDoctor::getInstance()?->getRepairs() ?? throw new InvalidConfigException('Verifications needs the repairs service, and Web Doctor is not installed to provide it.');
    }

    private function actions(): VerificationActions
    {
        return $this->actions ??= WebDoctor::getInstance()?->getVerificationActions() ?? throw new InvalidConfigException('Verifications needs the verification actions registry, and Web Doctor is not installed to provide it.');
    }

    private function registry(): Diagnostics
    {
        return $this->registry ??= WebDoctor::getInstance()?->getDiagnostics() ?? throw new InvalidConfigException('Verifications needs the diagnostics registry, and Web Doctor is not installed to provide it.');
    }

    private function engine(): DiagnosticEngine
    {
        return $this->engine ??= WebDoctor::getInstance()?->getDiagnosticEngine() ?? throw new InvalidConfigException('Verifications needs the diagnostic engine, and Web Doctor is not installed to provide it.');
    }

    private function issues(): Issues
    {
        return $this->issues ??= WebDoctor::getInstance()?->getIssues() ?? throw new InvalidConfigException('Verifications needs the Issue Center, and Web Doctor is not installed to provide it.');
    }

    private function errors(): Errors
    {
        return $this->errors ??= WebDoctor::getInstance()?->getErrors() ?? throw new InvalidConfigException('Verifications needs the error groups, and Web Doctor is not installed to provide them.');
    }

    private function evidenceStore(): EvidenceStore
    {
        return $this->evidence ??= WebDoctor::getInstance()?->getEvidence() ?? throw new InvalidConfigException('Verifications needs the evidence store, and Web Doctor is not installed to provide it.');
    }
}
