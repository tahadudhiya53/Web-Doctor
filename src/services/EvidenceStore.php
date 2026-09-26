<?php

namespace Tahadudhiya\WebDoctor\services;

use craft\db\ActiveQuery;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Savepoint;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\EvidencePage;
use Tahadudhiya\WebDoctor\models\StoredEvidence;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use yii\base\Component;
use yii\db\Expression;

/**
 * The evidence Web Doctor keeps, and the rules about what is worth keeping.
 *
 * Only evidence behind an issue is stored. A passing check's evidence describes nothing anybody
 * has to act on, and a check that errored or could not tell raises no issue to attach it to;
 * both stay in the latest run, which is where the answer to "what did the last run see" lives.
 *
 * What is stored is already redacted and bounded — the evidence model does both as it is built —
 * and it is stored once per distinct fact rather than once per run. An issue detected by every
 * scheduled run for a year holds the facts that changed over that year, not thousands of copies
 * of the one that did not. And an issue holds at most {@see $maxPerIssue} facts: the ones seen
 * most recently are kept, so the evidence behind the latest finding is never what goes.
 */
class EvidenceStore extends Component
{
    /** @var int How much earlier evidence one page of the detail page shows. */
    public const PAGE_SIZE = 20;

    /**
     * @var int The most facts one issue may hold. Settable, so an installation can keep more or
     * fewer and a test can reach the bound without recording a hundred of anything.
     */
    public int $maxPerIssue = 100;

    /**
     * Keeps the evidence behind one finding against the issue it raised.
     *
     * Runs inside the caller's transaction, so a reconciliation that fails keeps none of it.
     *
     * @return int How many facts were new to this issue.
     */
    public function record(int $issueId, DiagnosticResult $result, DiagnosticRun $run): int
    {
        $limit = max(1, $this->maxPerIssue);
        $incoming = [];

        foreach ($result->evidence() as $evidence) {
            // Each fact once: a check that reported the same thing twice in one result has still
            // only shown it once.
            if (count($incoming) < $limit) {
                $incoming[$evidence->digest()] ??= $evidence;
            }
        }

        if ($incoming === []) {
            return 0;
        }

        $seenAt = $this->forDb($run->finishedAt);
        $existing = $this->existing($issueId, array_keys($incoming));
        $new = 0;

        foreach ($incoming as $digest => $evidence) {
            if (isset($existing[$digest])) {
                continue;
            }

            $id = $this->create($issueId, $digest, $evidence, $result, $run, $seenAt);

            if ($id !== null) {
                $new++;

                continue;
            }

            // Another request stored this fact between the lookup and the insert. Its row is the
            // fact now, so this sighting is counted against it.
            $winner = Savepoint::committed(EvidenceRecord::find()->where(['issueId' => $issueId, 'digest' => $digest]))[0]
                ?? throw new RuntimeException(sprintf('Evidence %s for issue %d could not be stored or found.', $digest, $issueId));
            $existing[$digest] = (int)$winner->id;
        }

        if ($existing !== []) {
            EvidenceRecord::updateAll([
                'occurrences' => new Expression('[[occurrences]] + 1'),
                'lastSeen' => $seenAt,
                'lastRunId' => $run->id(),
                'dateUpdated' => $this->forDb(new DateTimeImmutable()),
            ], ['id' => array_values($existing)]);

            // When the fact was last true is not part of what makes it the same fact, so a
            // sighting carrying a newer moment moves it forward rather than being lost.
            foreach ($existing as $digest => $id) {
                $observedAt = $incoming[$digest]->observedAt ?? null;

                if ($observedAt !== null) {
                    EvidenceRecord::updateAll(['observedAt' => $this->forDb($observedAt)], ['id' => $id]);
                }
            }
        }

        $this->prune($issueId, $limit, $run->id(), count($incoming));

        return $new;
    }

    /**
     * The evidence behind an issue's latest finding: what the check reported the last time it
     * reported the problem, in the order it was first recorded.
     *
     * @return list<StoredEvidence>
     */
    public function latest(int $issueId, ?string $latestRunId): array
    {
        if ($latestRunId === null) {
            return [];
        }

        return $this->read(
            EvidenceRecord::find()
                ->where(['issueId' => $issueId, 'lastRunId' => $latestRunId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(max(1, $this->maxPerIssue)),
        );
    }

    /**
     * Everything else an issue has been backed by, most recently seen first, one page at a time.
     */
    public function earlier(int $issueId, ?string $latestRunId, int $page = 1): EvidencePage
    {
        $query = EvidenceRecord::find()->where(['issueId' => $issueId]);

        if ($latestRunId !== null) {
            $query->andWhere(['or', ['lastRunId' => null], ['not', ['lastRunId' => $latestRunId]]]);
        }

        $total = (int)(clone $query)->count();
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        $items = $this->read(
            $query
                ->orderBy(['lastSeen' => SORT_DESC, 'id' => SORT_DESC])
                ->offset(($page - 1) * self::PAGE_SIZE)
                ->limit(self::PAGE_SIZE),
        );

        return new EvidencePage(items: $items, total: $total, page: $page, perPage: self::PAGE_SIZE);
    }

    /**
     * Which of these facts an issue already holds. A seam rather than a direct query, so the
     * lost-race path can be exercised: nothing else can make two requests collide in one process.
     *
     * @param list<string> $digests
     * @return array<string, int> Digest to row ID.
     */
    protected function existing(int $issueId, array $digests): array
    {
        $rows = EvidenceRecord::find()
            ->select(['id', 'digest'])
            ->where(['issueId' => $issueId, 'digest' => $digests])
            ->asArray()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(string)$row['digest']] = (int)$row['id'];
        }

        return $out;
    }

    /**
     * Inserts a new fact, or reports that somebody else already has.
     *
     * @return int|null The new row's ID, or null when the fact was stored while this was being built.
     */
    private function create(int $issueId, string $digest, Evidence $evidence, DiagnosticResult $result, DiagnosticRun $run, string $seenAt): ?int
    {
        $record = new EvidenceRecord();
        $record->issueId = $issueId;
        $record->digest = $digest;
        $record->diagnosticId = $result->diagnosticId;
        $record->type = $evidence->type->value;
        $record->label = $evidence->label;
        $record->source = $evidence->source;
        $record->reference = $evidence->reference;
        // Redacted as it goes in, although the evidence redacted itself when it was built.
        // This is a serialization boundary, and every one of those redacts.
        $record->data = $evidence->data === [] ? null : Evidence::encode(Redaction::redact($evidence->data));
        $record->metadata = $evidence->metadata === [] ? null : Evidence::encode(Redaction::redact($evidence->metadata));
        $record->confidence = $evidence->confidence?->value;
        $record->truncated = $evidence->truncated;
        // Attributed to the run being reconciled — the same context the issue's fingerprint was
        // built from — and never to whatever the evidence says about itself. The engine
        // stamps those to match, but a run assembled any other way must not be able to file
        // evidence under a site or environment its issue does not belong to.
        $record->environment = $run->context->environment;
        $record->siteId = $run->context->siteId;
        $record->firstRunId = $run->id();
        $record->lastRunId = $run->id();
        $record->occurrences = 1;
        $record->observedAt = $evidence->observedAt === null ? null : $this->forDb($evidence->observedAt);
        $record->firstSeen = $seenAt;
        $record->lastSeen = $seenAt;

        $inserted = Savepoint::insert(static function() use ($record): void {
            if (!$record->save()) {
                throw new RuntimeException(sprintf(
                    'Web Doctor evidence could not be saved: %s',
                    Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
                ));
            }
        });

        return $inserted ? (int)$record->id : null;
    }

    /**
     * Drops whatever an issue holds beyond its limit, least recently seen first.
     *
     * What this run just saw is never a candidate. Ordering by `lastSeen` alone would nearly
     * guarantee that, but the column holds whole seconds, and two runs in the same second tie —
     * at which point the tiebreak could remove a fact the current run had just seen again.
     *
     * @param int $current How many facts the current run recorded against the issue.
     */
    private function prune(int $issueId, int $limit, string $runId, int $current): void
    {
        $stale = EvidenceRecord::find()
            ->select(['id'])
            ->where(['issueId' => $issueId])
            ->andWhere(['or', ['lastRunId' => null], ['not', ['lastRunId' => $runId]]])
            ->orderBy(['lastSeen' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(max(0, $limit - $current))
            ->column();

        if ($stale !== []) {
            EvidenceRecord::deleteAll(['id' => $stale]);
        }
    }

    /**
     * @return list<StoredEvidence>
     */
    private function read(ActiveQuery $query): array
    {
        $out = [];

        foreach ($query->all() as $record) {
            if ($record instanceof EvidenceRecord) {
                $out[] = StoredEvidence::fromRecord($record);
            }
        }

        return $out;
    }

    /**
     * A moment as the database stores it: UTC, in Craft's own format.
     */
    private function forDb(DateTimeInterface $when): string
    {
        return Db::prepareDateForDb($when) ?? gmdate('Y-m-d H:i:s');
    }
}
