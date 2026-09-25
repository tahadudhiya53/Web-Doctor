<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use craft\db\ActiveQuery;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Tahadudhiya\WebDoctor\enums\IssueEventType;
use Tahadudhiya\WebDoctor\enums\IssueResolution;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\IssueEvent;
use Tahadudhiya\WebDoctor\models\IssueFilter;
use Tahadudhiya\WebDoctor\models\IssueList;
use Tahadudhiya\WebDoctor\models\IssueReconciliation;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\db\Expression;
use yii\db\IntegrityException;

/**
 * The problems Web Doctor has found, as they stand across runs. Three rules do the work.
 *
 * Only a warning or a failure becomes an issue. An error means the check broke and an unknown
 * means it could not tell; both count against the health score, but neither asserts a problem
 * exists, and a list of problems that may not be there is one nobody can act on.
 *
 * The same problem lands on the same issue, which is the fingerprint's answer.
 *
 * Resolution is established, never asserted: nothing here lets a person set an issue resolved.
 * That comes from a later run of the same check reaching a conclusion that is not the problem.
 * Somebody who has decided an issue needs no action says so with IGNORED or WONT_FIX, and has to
 * give a reason, because those are judgements rather than outcomes.
 */
class Issues extends Component
{
    /** @var int The most history the detail page asks for at once. */
    public const EVENT_LIMIT = 100;

    /**
     * @var EvidenceStore|null Where the evidence behind a finding is kept. Settable so a caller —
     * a test — can supply its own rather than the plugin's.
     */
    public ?EvidenceStore $evidence = null;

    /**
     * Brings the Issue Center up to date with what a run found.
     *
     * Done as one transaction. A run that is half-reconciled — new issues raised but nothing
     * resolved — would show a reader problems that the same run had already established were
     * gone.
     */
    public function reconcile(DiagnosticRun $run): IssueReconciliation
    {
        $environment = $run->context->environment;
        $siteId = $run->context->siteId;

        $findings = [];
        $conclusive = [];

        foreach ($run->results() as $result) {
            // A check that errored, was skipped or could not tell establishes nothing — neither
            // that a problem exists nor that one has gone. It is passed over in both directions.
            if (!$result->status->isConclusive()) {
                continue;
            }

            $conclusive[$result->diagnosticId] = true;

            if ($result->status->isProblem()) {
                $findings[Fingerprint::forResult($result, $environment, $siteId)] = $result;
            }
        }

        $opened = $updated = $recurred = $resolved = 0;
        $detectedAt = $this->forDb($run->finishedAt);
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($findings as $fingerprint => $result) {
                [$outcome, $record] = $this->record($fingerprint, $result, $run, $detectedAt);

                // In the same transaction as the issue, so an issue is never left pointing at the
                // evidence of a reconciliation that did not happen.
                $this->evidenceStore()->record((int)$record->id, $result, $run);

                match ($outcome) {
                    IssueEventType::DETECTED => $opened++,
                    IssueEventType::RECURRED => $recurred++,
                    default => $updated++,
                };
            }

            $resolved = $this->resolveCleared($run, array_keys($findings), array_keys($conclusive));

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return new IssueReconciliation(opened: $opened, updated: $updated, recurred: $recurred, resolved: $resolved);
    }

    /**
     * Records one finding against the issue it belongs to, creating that issue if it is new.
     *
     * @return array{0: IssueEventType, 1: IssueRecord} What this amounted to — a new issue, one
     * coming back, or one already known being seen again — and the issue it amounted to it on.
     */
    private function record(string $fingerprint, DiagnosticResult $result, DiagnosticRun $run, string $detectedAt): array
    {
        $severity = $result->severity();
        $title = $this->title($result);
        $record = $this->findByFingerprint($fingerprint);

        if ($record === null) {
            $record = $this->create($fingerprint, $result, $run, $detectedAt);

            if ($record !== null) {
                $this->logEvent($record, IssueEventType::DETECTED, severity: $severity, runId: $run->id());

                return [IssueEventType::DETECTED, $record];
            }

            // Another request inserted this fingerprint between the lookup and the insert. Its
            // row is the issue now, so this detection is recorded against that one.
            $record = $this->findByFingerprint($fingerprint);

            if ($record === null) {
                throw new RuntimeException(sprintf('The issue for fingerprint %s could not be created or found.', $fingerprint));
            }
        }

        $previousStatus = IssueStatus::tryFrom((string)$record->status) ?? IssueStatus::NEW;
        $previousSeverity = Severity::tryFrom((string)$record->severity);
        $previousTitle = (string)$record->title;

        $record->occurrences = (int)$record->occurrences + 1;
        $this->applyFinding($record, $result, $run, $detectedAt);

        // A resolved issue found again was not resolved. It returns as something nobody has
        // looked at, keeping its count and history: one that has come back four times is a
        // different story from one raised for the first time. The note explaining an earlier
        // decision goes, since it no longer describes where the issue stands.
        if ($previousStatus === IssueStatus::RESOLVED) {
            $record->status = IssueStatus::NEW->value;
            $record->resolution = IssueResolution::NONE->value;
            $record->resolvedAt = null;
            $record->resolvedByRunId = null;
            $record->statusNote = null;
            $record->statusChangedAt = null;
            $record->statusChangedBy = null;

            $this->save($record);
            $this->logEvent($record, IssueEventType::RECURRED, from: $previousStatus, to: IssueStatus::NEW, severity: $severity, runId: $run->id());

            return [IssueEventType::RECURRED, $record];
        }

        // An issue somebody ignored or ruled out stays that way; only the count and the last-seen
        // date move. A standing decision is not a later run's to overturn.
        $this->save($record);

        if ($previousSeverity !== $severity || $previousTitle !== $title) {
            $this->logEvent($record, IssueEventType::CHANGED, severity: $severity, runId: $run->id(), note: $this->changeNote($previousSeverity, $severity, $previousTitle, $title));
        }

        return [IssueEventType::CHANGED, $record];
    }

    /**
     * Inserts a new issue, or reports that somebody else already has.
     *
     * The insert runs in a nested transaction so a unique-key collision rolls back only itself.
     * PostgreSQL abandons an entire transaction after a failed statement, so without the
     * savepoint a lost race would take the whole reconciliation down with it.
     *
     * @return IssueRecord|null Null when the fingerprint was taken while this was being built.
     */
    private function create(string $fingerprint, DiagnosticResult $result, DiagnosticRun $run, string $detectedAt): ?IssueRecord
    {
        $savepoint = Craft::$app->getDb()->beginTransaction();

        try {
            $record = new IssueRecord();
            $record->fingerprint = $fingerprint;
            $record->status = IssueStatus::NEW->value;
            $record->resolution = IssueResolution::NONE->value;
            $record->occurrences = 1;
            $record->firstDetected = $detectedAt;
            $record->firstRunId = $run->id();

            $this->applyFinding($record, $result, $run, $detectedAt);
            $this->save($record);

            $savepoint->commit();

            return $record;
        } catch (IntegrityException) {
            $savepoint->rollBack();

            return null;
        } catch (Throwable $e) {
            $savepoint->rollBack();

            throw $e;
        }
    }

    /**
     * The issue a fingerprint belongs to. A seam rather than a direct call, so the lost-race path
     * can be exercised: nothing else can make two requests collide inside one test process.
     */
    protected function findByFingerprint(string $fingerprint): ?IssueRecord
    {
        return IssueRecord::findOne(['fingerprint' => $fingerprint]);
    }

    /**
     * Closes the issues this run showed are no longer being reported.
     *
     * Only issues raised by a check that reached a conclusion in this run, and only issues that
     * are still open. A check that errored proves nothing, and a decision somebody made about an
     * issue is not a passing check's to reverse.
     *
     * @param list<string> $foundFingerprints What this run did report.
     * @param list<string> $conclusiveDiagnostics Which checks reached a conclusion.
     */
    private function resolveCleared(DiagnosticRun $run, array $foundFingerprints, array $conclusiveDiagnostics): int
    {
        if ($conclusiveDiagnostics === []) {
            return 0;
        }

        $query = IssueRecord::find()
            ->where([
                'diagnosticId' => $conclusiveDiagnostics,
                'environment' => $run->context->environment,
                'siteId' => $run->context->siteId,
                'status' => array_map(static fn(IssueStatus $s): string => $s->value, IssueStatus::open()),
            ]);

        if ($foundFingerprints !== []) {
            $query->andWhere(['not in', 'fingerprint', $foundFingerprints]);
        }

        $resolvedAt = $this->forDb($run->finishedAt);
        $count = 0;

        foreach ($query->all() as $record) {
            if (!$record instanceof IssueRecord) {
                continue;
            }

            $from = IssueStatus::tryFrom((string)$record->status) ?? IssueStatus::NEW;

            $record->status = IssueStatus::RESOLVED->value;
            $record->resolution = IssueResolution::OBSERVED_CLEAR->value;
            $record->resolvedAt = $resolvedAt;
            $record->resolvedByRunId = $run->id();

            $this->save($record);
            $this->logEvent($record, IssueEventType::RESOLVED, from: $from, to: IssueStatus::RESOLVED, runId: $run->id());

            $count++;
        }

        return $count;
    }

    /**
     * Moves an issue through its lifecycle on somebody's say-so.
     *
     * Refuses the two statuses that are not a person's to set: resolution is what a later run
     * establishes, and repair state belongs to whatever performs repairs.
     *
     * @param int|null $userId Who asked. Recorded against the change, never used to authorise it
     * — authorisation is the controller's, and doing it twice invites the two to disagree.
     * @throws InvalidArgumentException if the status may not be set by hand, if a reason is
     * required and missing, or if the issue does not exist.
     */
    public function transition(int $issueId, IssueStatus $to, ?string $note = null, ?int $userId = null): Issue
    {
        if (!$to->isSettableByHand()) {
            throw new InvalidArgumentException(sprintf(
                'An issue cannot be moved to "%s" by hand. That state is established by what Web Doctor observes, not by a request.',
                $to->value,
            ));
        }

        $note = $note === null ? null : trim($note);

        if ($to->requiresReason() && ($note === null || $note === '')) {
            throw new InvalidArgumentException(sprintf(
                'Setting an issue to "%s" is a decision rather than an outcome, so it needs a reason.',
                $to->value,
            ));
        }

        $record = IssueRecord::findOne(['id' => $issueId]);

        if ($record === null) {
            throw new InvalidArgumentException(sprintf('No issue exists with the ID %d.', $issueId));
        }

        $from = IssueStatus::tryFrom((string)$record->status) ?? IssueStatus::NEW;

        if ($from === $to) {
            return Issue::fromRecord($record);
        }

        // The change and the record of it are one act. An issue showing a status with no history
        // behind it would be a decision nobody can account for, so either both land or neither
        // does.
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record->status = $to->value;
            // Moving back out of resolved withdraws the claim, so the grounds for it go too.
            $record->resolution = IssueResolution::NONE->value;
            $record->resolvedAt = null;
            $record->resolvedByRunId = null;
            // Redacted as the history entry beside it is: a reason is typed by a person, and a
            // person pasting a failing connection string into it is not a hypothetical.
            $record->statusNote = $note === null || $note === '' ? null : Redaction::redactString($note);
            $record->statusChangedAt = $this->forDb(new DateTimeImmutable());
            $record->statusChangedBy = $userId;

            $this->save($record);
            $this->logEvent($record, IssueEventType::STATUS_CHANGED, from: $from, to: $to, note: $note, userId: $userId);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return Issue::fromRecord($record);
    }

    /**
     * The issues matching a filter, one page of them.
     */
    public function find(IssueFilter $filter): IssueList
    {
        $query = $this->filtered($filter);
        $total = (int)$query->count();

        $issues = [];

        foreach ($query->orderBy($this->order($filter))->offset($filter->offset())->limit($filter->perPage)->all() as $record) {
            if ($record instanceof IssueRecord) {
                $issues[] = Issue::fromRecord($record);
            }
        }

        return new IssueList(issues: $issues, total: $total, filter: $filter);
    }

    public function get(int $id): ?Issue
    {
        $record = IssueRecord::findOne(['id' => $id]);

        return $record === null ? null : Issue::fromRecord($record);
    }

    public function getByFingerprint(string $fingerprint): ?Issue
    {
        $record = IssueRecord::findOne(['fingerprint' => $fingerprint]);

        return $record === null ? null : Issue::fromRecord($record);
    }

    /**
     * An issue's history, most recent first.
     *
     * @return list<IssueEvent>
     */
    public function events(int $issueId, int $limit = self::EVENT_LIMIT): array
    {
        $records = IssueEventRecord::find()
            ->where(['issueId' => $issueId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(max(1, $limit))
            ->all();

        $events = [];

        foreach ($records as $record) {
            if ($record instanceof IssueEventRecord) {
                $events[] = IssueEvent::fromRecord($record);
            }
        }

        return $events;
    }

    /**
     * How many issues sit in each status, without loading them to count.
     *
     * Counted under everything the filter asks for except the status itself, which is what the
     * numbers sit beside: a count of every status in the database, shown against a list narrowed
     * to one environment, would disagree with the list under it.
     *
     * @return array<string, int>
     */
    public function countsByStatus(?IssueFilter $filter = null): array
    {
        $counts = array_fill_keys(IssueStatus::values(), 0);
        $query = $filter === null ? IssueRecord::find() : $this->filtered($filter, withStatus: false);

        $rows = $query
            ->select(['status', 'total' => 'COUNT(*)'])
            ->groupBy(['status'])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * The checks that have actually raised an issue, so the filter offers the ones worth
     * choosing rather than every check that exists.
     *
     * @return array<string, string> Diagnostic ID to the name it last reported under.
     */
    public function knownDiagnostics(): array
    {
        $rows = IssueRecord::find()
            ->select(['diagnosticId', 'diagnosticName'])
            ->distinct()
            ->orderBy(['diagnosticId' => SORT_ASC])
            ->asArray()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $id = (string)($row['diagnosticId'] ?? '');

            if ($id !== '') {
                $out[$id] = (string)($row['diagnosticName'] ?? '') ?: $id;
            }
        }

        return $out;
    }

    /**
     * The environments issues have been recorded in.
     *
     * @return list<string>
     */
    public function knownEnvironments(): array
    {
        $rows = IssueRecord::find()
            ->select(['environment'])
            ->distinct()
            ->orderBy(['environment' => SORT_ASC])
            ->column();

        return array_values(array_filter(array_map('strval', $rows), static fn(string $e): bool => $e !== ''));
    }

    /**
     * Applies a filter to a query. Every value arrives already checked against something that
     * knows the answers, so nothing here has to guess what it was handed.
     */
    private function filtered(IssueFilter $filter, bool $withStatus = true): ActiveQuery
    {
        $query = IssueRecord::find();

        if ($withStatus && $filter->statuses !== []) {
            $query->andWhere(['status' => array_map(static fn(IssueStatus $s): string => $s->value, $filter->statuses)]);
        }

        if ($filter->severities !== []) {
            $query->andWhere(['severity' => array_map(static fn(Severity $s): string => $s->value, $filter->severities)]);
        }

        if ($filter->diagnosticId !== null) {
            $query->andWhere(['diagnosticId' => $filter->diagnosticId]);
        }

        // "No particular site" means never associated with one. A deleted site's issues also
        // have a null siteId, so the retained siteName is what tells the two apart.
        if ($filter->withoutSite) {
            $query->andWhere(['siteId' => null, 'siteName' => null]);
        } elseif ($filter->siteId !== null) {
            $query->andWhere(['siteId' => $filter->siteId]);
        }

        if ($filter->environment !== null) {
            $query->andWhere(['environment' => $filter->environment]);
        }

        // A date in the filter is a calendar date where the reader is; the column is UTC. The
        // two are converted rather than compared as strings, or "today" would mean a window
        // offset by the server's distance from the reader for half of every day.
        if ($filter->detectedFrom !== null) {
            $query->andWhere(['>=', 'lastDetected', $this->localDayBoundary($filter->detectedFrom, '00:00:00')]);
        }

        if ($filter->detectedTo !== null) {
            $query->andWhere(['<=', 'lastDetected', $this->localDayBoundary($filter->detectedTo, '23:59:59')]);
        }

        return $query;
    }

    /**
     * How the list is ordered.
     *
     * Severity and status are ordered by what they mean rather than how they are spelled: a list
     * putting `critical` above `high` because C precedes H would be right by accident. The ID is
     * always the last term, so two issues that tie never swap places between pages.
     *
     * @return array<string, int>|array<int, mixed>
     */
    private function order(IssueFilter $filter): array
    {
        $direction = $filter->ascending ? SORT_ASC : SORT_DESC;
        $column = IssueFilter::SORTABLE[$filter->sort] ?? 'lastDetected';

        $primary = match ($column) {
            'severity' => $this->rankOrder('severity', Severity::cases(), static fn(Severity $s): int => $s->rank(), $filter->ascending),
            'status' => $this->rankOrder('status', IssueStatus::cases(), static fn(IssueStatus $s): int => $s->position(), $filter->ascending),
            default => new Expression(sprintf(
                '%s %s',
                Craft::$app->getDb()->quoteColumnName($column),
                $filter->ascending ? 'ASC' : 'DESC',
            )),
        };

        return [$primary, new Expression(Craft::$app->getDb()->quoteColumnName('id') . ($direction === SORT_ASC ? ' ASC' : ' DESC'))];
    }

    /**
     * An ordering that follows an enum's own ranking rather than its spelling. Nothing a request
     * sent reaches this SQL, but it is quoted regardless: an expression that is safe only while
     * nobody changes what feeds it is not safe.
     *
     * @param list<\BackedEnum> $cases
     * @param callable(mixed): int $rank
     */
    private function rankOrder(string $column, array $cases, callable $rank, bool $ascending): Expression
    {
        $db = Craft::$app->getDb();
        $whens = '';

        foreach ($cases as $case) {
            $whens .= sprintf(' WHEN %s THEN %d', $db->quoteValue((string)$case->value), $rank($case));
        }

        return new Expression(sprintf('CASE %s%s ELSE -1 END %s', $db->quoteColumnName($column), $whens, $ascending ? 'ASC' : 'DESC'));
    }

    /**
     * Writes what a finding currently says onto the issue. Everything here is the latest
     * reading; nothing that identifies the issue is touched.
     */
    private function applyFinding(IssueRecord $record, DiagnosticResult $result, DiagnosticRun $run, string $detectedAt): void
    {
        $record->diagnosticId = $result->diagnosticId;
        $record->diagnosticName = $this->fit($result->name !== '' ? $result->name : $result->diagnosticId, 255);
        $record->category = $result->category->value;
        $record->title = $this->title($result);
        $record->description = $result->description !== '' ? $result->description : null;
        $record->recommendation = $result->recommendation;
        $record->severity = $result->severity()->value;
        $record->resultStatus = $result->status->value;
        $record->environment = $this->fit($run->context->environment, 255);
        $record->siteId = $run->context->siteId;
        $record->siteName = $this->siteName($run->context->siteId);
        $record->affectedComponent = $this->fit($result->affectedComponent, 255);
        $record->affectedPlugin = $this->fit($result->affectedPlugin, 255);
        $record->latestResult = $this->snapshot($result);
        $record->latestRunId = $run->id();
        $record->lastDetected = $detectedAt;
    }

    /**
     * The site's name now, kept against the issue so that deleting the site — which nulls the
     * reference — leaves a finding that still says which site it was about.
     */
    private function siteName(?int $siteId): ?string
    {
        if ($siteId === null) {
            return null;
        }

        try {
            return $this->fit(Craft::$app->getSites()->getSiteById($siteId)?->getName(), 255);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the issue is called: the one line the check wrote for a person, or its own name where
     * it wrote none.
     */
    private function title(DiagnosticResult $result): string
    {
        $title = trim($result->summary);

        if ($title === '') {
            $title = $result->name !== '' ? $result->name : $result->diagnosticId;
        }

        return $this->fit($title, 255) ?? $result->diagnosticId;
    }

    /**
     * The latest finding, reduced to what the Issue Center displays.
     *
     * Evidence is not part of it. It is kept by {@see EvidenceStore}, once per distinct fact, so
     * repeating it here would be a second copy with rules of its own about what it may hold.
     *
     * Redacted on the way in. This is a serialization boundary, and every one of those redacts: a
     * boundary that trusts the last one is a boundary that stops working the moment something
     * arrives by a route nobody expected.
     */
    private function snapshot(DiagnosticResult $result): string
    {
        return Evidence::encode(Redaction::redact([
            'status' => $result->status->value,
            'severity' => $result->severity()->value,
            'summary' => $result->summary,
            'description' => $result->description,
            'recommendation' => $result->recommendation,
            'confidence' => $result->confidence->value,
            'affectedComponent' => $result->affectedComponent,
            'affectedPlugin' => $result->affectedPlugin,
            'runId' => $result->runId,
            'finishedAt' => $result->finishedAt?->format(DATE_ATOM),
            'durationMs' => $result->durationMs,
        ]));
    }

    /**
     * Says what changed about a finding, so the history is readable without comparing two rows.
     */
    private function changeNote(?Severity $from, Severity $to, string $fromTitle, string $toTitle): ?string
    {
        if ($from !== null && $from !== $to) {
            return Craft::t('web-doctor', 'Severity changed from {from} to {to}.', [
                'from' => $from->label(),
                'to' => $to->label(),
            ]);
        }

        return $fromTitle !== $toTitle ? $toTitle : null;
    }

    /**
     * Where evidence is kept. Resolved on first use, so an Issues built before the plugin finished
     * booting still ends up with the plugin's store rather than one of its own.
     */
    private function evidenceStore(): EvidenceStore
    {
        return $this->evidence ??= WebDoctor::getInstance()?->getEvidence() ?? new EvidenceStore();
    }

    /**
     * Appends to an issue's history. A seam rather than a private call, so a test can make the
     * event fail and prove the status change goes back with it.
     */
    protected function logEvent(
        IssueRecord $record,
        IssueEventType $type,
        ?IssueStatus $from = null,
        ?IssueStatus $to = null,
        ?Severity $severity = null,
        ?string $note = null,
        ?string $runId = null,
        ?int $userId = null,
    ): void {
        $event = new IssueEventRecord();
        $event->issueId = (int)$record->id;
        $event->type = $type->value;
        $event->fromStatus = $from?->value;
        $event->toStatus = $to?->value;
        $event->severity = $severity?->value;
        $event->note = $note === null || $note === '' ? null : Redaction::redactString($note);
        $event->runId = $runId;
        $event->userId = $userId;

        $this->save($event);
    }

    /**
     * @throws \RuntimeException if the row will not save, because a caller that thinks it stored
     * an issue and did not is worse than one that knows it failed.
     */
    private function save(IssueRecord|IssueEventRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor issue row could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }

    /**
     * A moment as the database stores it: UTC, in Craft's own format. Formatting the object as
     * it stands would write the server's local time into a column everything else reads as UTC.
     */
    private function forDb(DateTimeInterface $when): string
    {
        return Db::prepareDateForDb($when) ?? Db::prepareDateForDb(new DateTimeImmutable()) ?? gmdate('Y-m-d H:i:s');
    }

    /**
     * The start or end of a calendar day where the reader is, as a UTC timestamp.
     */
    private function localDayBoundary(string $date, string $time): string
    {
        try {
            $moment = new DateTimeImmutable("$date $time", new DateTimeZone(Craft::$app->getTimeZone()));
        } catch (\Throwable) {
            $moment = new DateTimeImmutable("$date $time", new DateTimeZone('UTC'));
        }

        return $this->forDb($moment);
    }

    /**
     * Cuts a value to what its column holds. A title longer than the column is a truncated title,
     * not a failed run.
     */
    private function fit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
