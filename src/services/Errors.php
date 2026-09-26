<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Tahadudhiya\WebDoctor\helpers\ErrorNormalizer;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Savepoint;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\ErrorGroup;
use Tahadudhiya\WebDoctor\models\ErrorGroupPage;
use Tahadudhiya\WebDoctor\models\ErrorRecording;
use Tahadudhiya\WebDoctor\models\ErrorSignature;
use Tahadudhiya\WebDoctor\models\ErrorSource;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\Expression;

/**
 * The errors Web Doctor has run into, grouped so that the same error happening again is counted
 * against the group that already describes it rather than listed again.
 *
 * An error here is an exception a diagnostic run recorded: a check that broke, or a check that
 * caught the exception that stopped it answering. Each result that recorded one is one
 * occurrence, so a database refusing connections counts once for every check that ran into it,
 * every time it did. What makes two occurrences the same error is {@see ErrorSignature}'s answer.
 *
 * Unlike an issue, an error is recorded whatever the result's status. A check that broke raises
 * no issue, because it established nothing about the site — but it did happen, and a check that
 * breaks the same way on every run is exactly what grouping exists to show.
 */
class Errors extends Component
{
    /** @var int How many groups one page of the list shows. */
    public const PAGE_SIZE = 25;

    /**
     * @var int The most groups kept for one environment and site. Past it, the least recently
     * seen there go first; bounded per place so that one environment's errors can never push out
     * another's. Settable, so an installation can keep more or fewer and a test can reach the
     * bound without five hundred errors. Anything below one is a configuration error, refused
     * when a run is recorded rather than quietly read as something else.
     */
    public int $maxGroups = 500;

    /** @var Issues|null The Issue Center, for which issue a check's findings are recorded on. */
    public ?Issues $issues = null;

    /**
     * @var string|null The installation root paths are made relative to. Craft's answer unless
     * set, which a test does so that normalisation is deterministic.
     */
    public ?string $root = null;

    /**
     * Records the errors a run's results carry against their groups.
     *
     * A run with none costs nothing: no query is made unless there is something to record. When
     * there is, it is recorded in one transaction — groups, sources and pruning together — so a
     * run is never half-counted and a group never exists without the source that explains it.
     *
     * A place never holds more than {@see $maxGroups}, including when one run brings more distinct
     * errors than that. The run then records the errors already kept here first, since their
     * counts are the ones a reader is already following, then new ones in the order the run's
     * results met them, and reports how many it left out rather than dropping them unmentioned.
     */
    public function record(DiagnosticRun $run): ErrorRecording
    {
        $environment = $run->context->environment;
        $siteId = $run->context->siteId;

        /** @var array<string, array{signature: ErrorSignature, results: list<DiagnosticResult>}> $seen */
        $seen = [];
        $issueFingerprints = [];

        foreach ($run->results() as $result) {
            foreach ($this->signatures($result->evidence()) as $signature) {
                $fingerprint = $signature->fingerprint($environment, $siteId);
                $seen[$fingerprint] ??= ['signature' => $signature, 'results' => []];
                $seen[$fingerprint]['results'][] = $result;
                $issueFingerprints[$result->diagnosticId] = Fingerprint::forResult($result, $environment, $siteId);
            }
        }

        if ($seen === []) {
            return new ErrorRecording();
        }

        $seenAt = $this->forDb($run->finishedAt);
        $siteName = $this->siteName($siteId);
        $occurrences = $created = 0;

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $existing = $this->groupsByFingerprint(array_keys($seen));
            $kept = $this->withinLimit($seen, $existing);
            $issueIds = $this->issues()->idsByFingerprint(array_values(array_unique($issueFingerprints)));
            $records = [];

            foreach ($kept as $fingerprint => ['signature' => $signature, 'results' => $results]) {
                $record = $existing[$fingerprint] ?? null;

                if ($record === null) {
                    $record = $this->create($fingerprint, $signature, $run, $seenAt, $siteName, count($results));

                    if ($record !== null) {
                        $created++;
                    } else {
                        // Another request created this group between the lookup and the insert.
                        // Its row is the group now, so these occurrences are counted against it.
                        $record = Savepoint::committed(ErrorGroupRecord::find()->where(['fingerprint' => $fingerprint]))[0]
                            ?? throw new RuntimeException(sprintf('The error group for fingerprint %s could not be created or found.', $fingerprint));

                        $this->sighted($record, $signature, $run, $seenAt, count($results));
                    }
                } else {
                    $this->sighted($record, $signature, $run, $seenAt, count($results));
                }

                $records[$fingerprint] = $record;
                $occurrences += count($results);
            }

            // Every source of every kept group read in one query, rather than one per result.
            $sources = $this->sourcesOf(array_map(static fn(ErrorGroupRecord $r): int => (int)$r->id, array_values($records)));
            $sighted = [];

            foreach ($kept as $fingerprint => ['results' => $results]) {
                $groupId = (int)$records[$fingerprint]->id;

                foreach ($results as $result) {
                    $issueId = $issueIds[$issueFingerprints[$result->diagnosticId]] ?? null;
                    $source = $sources[$groupId . "\x1f" . $result->diagnosticId] ?? null;

                    if ($source === null) {
                        $this->recordSource($groupId, $result, $run, $seenAt, $issueId);

                        continue;
                    }

                    // What describes the check is written only when it changed; the count and the
                    // moment are written for every sighted source at once, below.
                    $this->describeSource($source, $result, $issueId);
                    $sighted[(int)$source->id] = ($sighted[(int)$source->id] ?? 0) + 1;
                }
            }

            $this->countSightings($sighted, $run, $seenAt);

            $this->prune($run);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return new ErrorRecording(occurrences: $occurrences, groups: count($kept), created: $created, omitted: count($seen) - count($kept));
    }

    /**
     * Which of a run's errors fit within the limit: those already kept here first, then new ones
     * in the order the run met them. Both orders are the run's own, so the same run always
     * keeps the same errors.
     *
     * @template T
     * @param array<string, T> $seen
     * @param array<string, ErrorGroupRecord> $existing
     * @return array<string, T>
     */
    private function withinLimit(array $seen, array $existing): array
    {
        $known = array_intersect_key($seen, $existing);
        $new = array_diff_key($seen, $existing);

        return array_slice($known + $new, 0, $this->limit(), true);
    }

    /**
     * The errors some evidence records, as Web Doctor identifies them here. The same answer
     * {@see record()} works from, so a page reading an investigation's stored evidence finds the
     * groups its occurrences were counted against.
     *
     * @param list<Evidence> $evidence
     * @return list<ErrorSignature>
     */
    public function signatures(array $evidence): array
    {
        return ErrorSignature::allIn($evidence, $this->root());
    }

    /**
     * One page of groups, most recently seen first, each with the checks that ran into it.
     */
    public function find(int $page = 1, ?string $environment = null): ErrorGroupPage
    {
        $query = ErrorGroupRecord::find();

        if ($environment !== null && $environment !== '') {
            $query->andWhere(['environment' => $environment]);
        }

        $total = (int)(clone $query)->count();
        $perPage = self::PAGE_SIZE;
        $page = min(max(1, $page), max(1, (int)ceil($total / $perPage)));

        $records = $query
            ->orderBy(['lastSeen' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return new ErrorGroupPage(items: $this->withSources($records), total: $total, page: $page, perPage: $perPage);
    }

    public function get(int $id): ?ErrorGroup
    {
        $record = ErrorGroupRecord::findOne(['id' => $id]);

        return $record === null ? null : $this->withSources([$record])[0] ?? null;
    }

    /**
     * The errors run into by checks whose findings are recorded on this issue, most recently seen
     * first.
     *
     * @return list<ErrorGroup>
     * @throws \InvalidArgumentException for a limit below one.
     */
    public function forIssue(int $issueId, int $limit = 20): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('A limit of at least 1 is needed; %d was given.', $limit));
        }

        $groupIds = ErrorSourceRecord::find()
            ->select(['errorGroupId'])
            ->distinct()
            ->where(['issueId' => $issueId])
            ->column();

        if ($groupIds === []) {
            return [];
        }

        return $this->withSources(
            ErrorGroupRecord::find()
                ->where(['id' => $groupIds])
                ->orderBy(['lastSeen' => SORT_DESC, 'id' => SORT_DESC])
                ->limit($limit)
                ->all(),
        );
    }

    /**
     * The groups these fingerprints belong to, keyed by fingerprint. A fingerprint with no group
     * — never recorded, or pruned since — is left out.
     *
     * @param list<string> $fingerprints
     * @return array<string, ErrorGroup>
     */
    public function byFingerprints(array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $out = [];

        foreach ($this->withSources(ErrorGroupRecord::find()->where(['fingerprint' => $fingerprints])->all()) as $group) {
            $out[$group->fingerprint] = $group;
        }

        return $out;
    }

    /**
     * The environments errors have been recorded in.
     *
     * @return list<string>
     */
    public function knownEnvironments(): array
    {
        $rows = ErrorGroupRecord::find()
            ->select(['environment'])
            ->distinct()
            ->orderBy(['environment' => SORT_ASC])
            ->column();

        return array_values(array_filter(array_map('strval', $rows), static fn(string $e): bool => $e !== ''));
    }

    /**
     * The groups these fingerprints already have. A seam rather than a direct query, so the
     * lost-race path can be exercised: nothing else can make two requests collide in one process.
     *
     * @param list<string> $fingerprints
     * @return array<string, ErrorGroupRecord>
     */
    protected function groupsByFingerprint(array $fingerprints): array
    {
        $out = [];

        foreach (ErrorGroupRecord::find()->where(['fingerprint' => $fingerprints])->all() as $record) {
            if ($record instanceof ErrorGroupRecord) {
                $out[(string)$record->fingerprint] = $record;
            }
        }

        return $out;
    }

    /**
     * Inserts a new group, or reports that somebody else already has.
     */
    private function create(string $fingerprint, ErrorSignature $signature, DiagnosticRun $run, string $seenAt, ?string $siteName, int $occurrences): ?ErrorGroupRecord
    {
        $record = new ErrorGroupRecord();
        $record->fingerprint = $fingerprint;
        $record->exceptionClass = (string)$this->fit($signature->class, 255);
        $record->normalizedMessage = Redaction::redactString($signature->message);
        $record->origin = (string)$this->fit(Redaction::redactString($signature->origin), 500);
        $record->previous = $signature->previous === [] ? null : Evidence::encode(Redaction::redact($signature->previous));
        $record->stackFingerprint = $signature->stackFingerprint;
        $record->environment = (string)$this->fit($run->context->environment, 255);
        $record->siteId = $run->context->siteId;
        $record->siteName = $siteName;
        $record->occurrences = $occurrences;
        $record->firstSeen = $seenAt;
        $record->firstRunId = $run->id();
        $this->applyLatest($record, $signature, $run, $seenAt);

        return Savepoint::insert(fn() => $this->save($record)) ? $record : null;
    }

    /**
     * Counts occurrences against a group that already exists.
     */
    private function sighted(ErrorGroupRecord $record, ErrorSignature $signature, DiagnosticRun $run, string $seenAt, int $occurrences): void
    {
        $record->occurrences = (int)$record->occurrences + $occurrences;
        $this->applyLatest($record, $signature, $run, $seenAt);

        $this->save($record);
    }

    /**
     * Writes what the latest occurrence said. Nothing that identifies the group is touched; the
     * wording and the trace are the latest reading of it.
     *
     * Redacted as it goes in, although the evidence it came from redacted itself: this is a
     * serialization boundary, and every one of those redacts.
     */
    private function applyLatest(ErrorGroupRecord $record, ErrorSignature $signature, DiagnosticRun $run, string $seenAt): void
    {
        $record->message = $signature->sample === '' ? null : Redaction::redactString($signature->sample);

        // A trace is recorded only when the engine caught the exception, so an occurrence without
        // one leaves the last one that was recorded in place rather than erasing it.
        if ($signature->frames !== []) {
            $record->frames = Evidence::encode(array_map(Redaction::redactString(...), $signature->frames));
        }

        $record->lastSeen = $seenAt;
        $record->lastRunId = $run->id();
    }

    /**
     * Records that a check ran into an error it had not been recorded against before.
     *
     * A seam rather than a private call, so a test can make it fail and prove the group it
     * belongs to goes back with it: a group counted without the source that explains the count
     * would be a number nobody can account for.
     */
    protected function recordSource(int $groupId, DiagnosticResult $result, DiagnosticRun $run, string $seenAt, ?int $issueId): void
    {
        $record = new ErrorSourceRecord();
        $record->errorGroupId = $groupId;
        $record->diagnosticId = (string)$this->fit($result->diagnosticId, 100);
        $record->occurrences = 1;
        $record->firstSeen = $seenAt;
        $record->lastSeen = $seenAt;
        $record->lastRunId = $run->id();
        $this->describeSource($record, $result, $issueId, save: false);

        if (Savepoint::insert(fn() => $this->save($record))) {
            return;
        }

        // Another request recorded this check against this error in the meantime. Its row is the
        // source now, so this sighting is counted against it.
        $record = Savepoint::committed(ErrorSourceRecord::find()->where(['errorGroupId' => $groupId, 'diagnosticId' => $record->diagnosticId]))[0]
            ?? throw new RuntimeException(sprintf('The source %s of error group %d could not be created or found.', $result->diagnosticId, $groupId));

        $this->describeSource($record, $result, $issueId);
        $this->countSightings([(int)$record->id => 1], $run, $seenAt);
    }

    /**
     * Brings what a source says about its check up to date: the name it reported, the status of
     * the result that ran into the error, and the issue its findings are recorded on — found by
     * the fingerprint the Issue Center itself uses, so the two cannot disagree. A check that
     * broke still has one when an earlier run of it raised an issue. Written only when something
     * changed, which for a check seen again unchanged is never.
     */
    private function describeSource(ErrorSourceRecord $record, DiagnosticResult $result, ?int $issueId, bool $save = true): void
    {
        $record->diagnosticName = (string)$this->fit(Redaction::redactString($result->name !== '' ? $result->name : $result->diagnosticId), 255);
        $record->issueId = $issueId;
        $record->lastStatus = $result->status->value;

        if ($save && $record->getDirtyAttributes(['diagnosticName', 'issueId', 'lastStatus']) !== []) {
            $this->save($record);
        }
    }

    /**
     * Counts sightings against sources that already exist: one statement per distinct count,
     * which for any ordinary run — each check seen once — is one statement in all.
     *
     * @param array<int, int> $sighted Source ID to how many times this run saw it.
     */
    private function countSightings(array $sighted, DiagnosticRun $run, string $seenAt): void
    {
        $byCount = [];

        foreach ($sighted as $id => $count) {
            $byCount[$count][] = $id;
        }

        foreach ($byCount as $count => $ids) {
            ErrorSourceRecord::updateAll([
                'occurrences' => new Expression('[[occurrences]] + ' . (int)$count),
                'lastSeen' => $seenAt,
                'lastRunId' => $run->id(),
                'dateUpdated' => $this->forDb(new DateTimeImmutable()),
            ], ['id' => $ids]);
        }
    }

    /**
     * The sources these groups already have, keyed by group and diagnostic.
     *
     * @param list<int> $groupIds
     * @return array<string, ErrorSourceRecord>
     */
    private function sourcesOf(array $groupIds): array
    {
        $out = [];

        foreach (ErrorSourceRecord::find()->where(['errorGroupId' => $groupIds])->all() as $record) {
            if ($record instanceof ErrorSourceRecord) {
                $out[$record->errorGroupId . "\x1f" . $record->diagnosticId] = $record;
            }
        }

        return $out;
    }

    /**
     * Drops the run's environment and site's groups beyond the limit, least recently seen first.
     * What this run just saw is never a candidate, for the reason {@see EvidenceStore} gives:
     * `lastSeen` holds whole seconds, and a tie must not remove what the current run recorded.
     * Sources go with their groups.
     */
    private function prune(DiagnosticRun $run): void
    {
        $limit = $this->limit();
        $runId = $run->id();
        // A run about no particular site shares its place with nothing else. A deleted site's
        // groups also have no site ID, but they keep its name, and they are not the
        // installation's to be pushed out by.
        $place = $run->context->siteId === null
            ? ['environment' => $run->context->environment, 'siteId' => null, 'siteName' => null]
            : ['environment' => $run->context->environment, 'siteId' => $run->context->siteId];
        $current = (int)ErrorGroupRecord::find()->where($place + ['lastRunId' => $runId])->count();

        $stale = ErrorGroupRecord::find()
            ->select(['id'])
            ->where($place)
            ->andWhere(['or', ['lastRunId' => null], ['not', ['lastRunId' => $runId]]])
            ->orderBy(['lastSeen' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(max(0, $limit - $current))
            ->column();

        if ($stale !== []) {
            ErrorGroupRecord::deleteAll(['id' => $stale]);
        }
    }

    /**
     * Groups with their sources, the sources read in one query for all of them.
     *
     * @param array<array-key, mixed> $records
     * @return list<ErrorGroup>
     */
    private function withSources(array $records): array
    {
        $records = array_values(array_filter($records, static fn(mixed $r): bool => $r instanceof ErrorGroupRecord));

        if ($records === []) {
            return [];
        }

        $sources = [];

        $rows = ErrorSourceRecord::find()
            ->where(['errorGroupId' => array_map(static fn(ErrorGroupRecord $r): int => (int)$r->id, $records)])
            ->orderBy(['lastSeen' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        foreach ($rows as $row) {
            if ($row instanceof ErrorSourceRecord) {
                $sources[(int)$row->errorGroupId][] = ErrorSource::fromRecord($row);
            }
        }

        return array_map(
            static fn(ErrorGroupRecord $r): ErrorGroup => ErrorGroup::fromRecord($r, $sources[(int)$r->id] ?? []),
            $records,
        );
    }

    /**
     * The site's name now, kept for the reason {@see Issues} keeps it.
     */
    private function siteName(?int $siteId): ?string
    {
        if ($siteId === null) {
            return null;
        }

        try {
            return $this->fit(Craft::$app->getSites()->getSiteById($siteId, true)?->getName(), 255);
        } catch (Throwable $e) {
            SafeException::log('A site\'s name could not be read for an error group', $e);

            return null;
        }
    }

    /**
     * The configured bound, refused when it cannot be one. A limit of zero or less would either
     * record nothing or be read as some other number, and neither is what anybody configured.
     *
     * @throws InvalidConfigException
     */
    private function limit(): int
    {
        if ($this->maxGroups < 1) {
            throw new InvalidConfigException(sprintf('Web Doctor\'s errors component needs a maxGroups of at least 1; %d was configured.', $this->maxGroups));
        }

        return $this->maxGroups;
    }

    /**
     * The installation root paths are made relative to, so something else recognising errors —
     * root-cause analysis — recognises them exactly as they were grouped.
     */
    public function root(): ?string
    {
        return $this->root ?? ErrorNormalizer::root();
    }

    private function issues(): Issues
    {
        return $this->issues ??= WebDoctor::getInstance()?->getIssues() ?? new Issues();
    }

    /**
     * @throws RuntimeException if the row will not save.
     */
    private function save(ErrorGroupRecord|ErrorSourceRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor error row could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }

    private function forDb(DateTimeInterface $when): string
    {
        return Db::prepareDateForDb($when) ?? Db::prepareDateForDb(new DateTimeImmutable()) ?? gmdate('Y-m-d H:i:s');
    }

    private function fit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
