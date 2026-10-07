<?php

namespace Tahadudhiya\WebDoctor\services;

use DateTimeImmutable;
use RuntimeException;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\helpers\Actor;
use Tahadudhiya\WebDoctor\helpers\QueryParams;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Retention;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\models\Dashboard;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\HealthSummary;
use Tahadudhiya\WebDoctor\models\HistoricalRun;
use Tahadudhiya\WebDoctor\models\ListPage;
use Tahadudhiya\WebDoctor\models\RunFilter;
use Tahadudhiya\WebDoctor\records\DiagnosticRunRecord;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Diagnostic history: every run somebody set going from the dashboard, kept as it happened, with a
 * health snapshot for each run that covered every check registered then.
 *
 * Written once, when the run finishes, from the run itself and the checks registered at that moment
 * — never worked out again later from the checks as they are now, which would describe a different
 * installation. The cached latest run is still what the dashboard shows; this is what it showed.
 *
 * The issue history is the Issue Center's own: every issue's events, kept since it was first found.
 */
class History extends Component
{
    /** @var int The most check results one run keeps; past it they are counted. */
    public const MAX_RESULTS = 500;

    /** @var int How many days a run is kept. Refused below one. */
    public int $retainDays = 365;

    /** @var int The most runs one round of retention removes. */
    public int $pruneBatch = 500;

    /** @var bool Whether this instance has pruned already: once per request is enough. */
    private bool $pruned = false;

    /**
     * Keeps a finished run: what each check answered, who ran it, where, and — when it covered every
     * check registered now, which is when the dashboard shows a score — the score, the weights it
     * was worked out with and the penalty each result cost.
     *
     * @param iterable<DiagnosticInterface> $registered The checks registered when the run finished.
     * @throws InvalidConfigException if retention cannot be one, before anything is written.
     * @throws RuntimeException if who ran it, the site or a moment cannot be established, or the row
     * cannot be saved — nothing is written with a guess in place of any of them.
     */
    public function record(DiagnosticRun $run, iterable $registered): HistoricalRun
    {
        Retention::validate($this->retainDays, $this->pruneBatch);
        [$userId, $userName] = Actor::current();

        $dashboard = Dashboard::build($registered, $run);
        $health = $dashboard->describesHealth() ? $dashboard->health() : null;
        $counts = HealthSummary::fromResults($run->results())->counts;
        $results = array_map(static fn(DiagnosticResult $r): array => [
            'diagnosticId' => $r->diagnosticId,
            'name' => mb_substr($r->name, 0, 255),
            'category' => $r->category->value,
            'status' => $r->status->value,
            'severity' => $r->severity()->value,
            'summary' => mb_substr(mb_scrub($r->summary, 'UTF-8'), 0, 500),
        ], $run->results());

        $record = new DiagnosticRunRecord();
        $record->runId = $run->id();
        $record->environment = mb_substr($run->context->environment, 0, 255);
        $record->siteId = $run->context->siteId;
        $record->siteName = SiteName::of($run->context->siteId);
        $record->mode = $run->context->mode->value;
        $record->depth = $run->context->depth->value;
        $record->userId = $userId;
        $record->userName = $userName === null ? null : mb_substr(Redaction::redactString($userName), 0, 255);
        $record->checksRegistered = $dashboard->registeredCount();
        $record->checksRun = $run->count();
        $record->complete = $dashboard->isComplete();
        $record->statusCounts = $this->encode($counts);
        $record->score = $health?->score;
        $record->maxScore = $health === null ? null : HealthSummary::MAX_SCORE;
        $record->weights = $health === null ? null : $this->encode(HealthSummary::weights());
        $record->severityCounts = $health === null ? null : $this->encode($health->severityCounts);
        $record->contributions = $health === null ? null : $this->encode($health->contributions);
        $record->results = $this->encode(Redaction::redact(array_slice($results, 0, self::MAX_RESULTS)));
        $record->resultsOmitted = max(0, count($results) - self::MAX_RESULTS);
        $record->startedAt = StoredTime::forDb($run->startedAt);
        $record->finishedAt = StoredTime::forDb($run->finishedAt);
        $record->durationMs = round($run->durationMs, 3);

        $this->save($record);
        $this->pruneWhenFree();

        return HistoricalRun::fromRecord($record);
    }

    /**
     * One page of the history, newest first unless the oldest are asked for, the ID breaking ties
     * between runs started in the same second. A page past the end shows the last.
     *
     * @return ListPage<HistoricalRun>
     */
    public function find(RunFilter $filter): ListPage
    {
        $query = DiagnosticRunRecord::find();

        if ($filter->environment !== null) {
            $query->andWhere(['environment' => $filter->environment]);
        }

        if ($filter->withoutSite) {
            $query->andWhere(SiteName::place(null));
        } elseif ($filter->siteId !== null) {
            $query->andWhere(SiteName::place($filter->siteId));
        }

        if ($filter->snapshotsOnly) {
            $query->andWhere(['not', ['score' => null]]);
        }

        if ($filter->from !== null) {
            $query->andWhere(['>=', 'startedAt', QueryParams::localDayStart($filter->from)]);
        }

        if ($filter->to !== null) {
            $query->andWhere(['<=', 'startedAt', QueryParams::localDayEnd($filter->to)]);
        }

        $total = (int)$query->count();
        $pages = max(1, (int)ceil($total / $filter->perPage));

        if ($filter->page > $pages) {
            $filter = $filter->onPage($pages);
        }

        $runs = [];

        foreach ($query->orderBy(self::order($filter->oldestFirst))->offset($filter->offset())->limit($filter->perPage)->all() as $record) {
            if ($record instanceof DiagnosticRunRecord) {
                $runs[] = HistoricalRun::fromRecord($record);
            }
        }

        return new ListPage($runs, $total, $filter);
    }

    /**
     * The order a page is read in: the moment, then the ID, both one way round. The ID is what keeps
     * two runs in the same second from swapping places between pages, whatever index the
     * database chooses.
     *
     * @return array<string, int>
     */
    public static function order(bool $oldestFirst): array
    {
        $direction = $oldestFirst ? SORT_ASC : SORT_DESC;

        return ['startedAt' => $direction, 'id' => $direction];
    }

    public function get(int $id): ?HistoricalRun
    {
        $record = DiagnosticRunRecord::findOne(['id' => $id]);

        return $record === null ? null : HistoricalRun::fromRecord($record);
    }

    /**
     * @return list<string>
     */
    public function knownEnvironments(): array
    {
        return QueryParams::choicesIn(DiagnosticRunRecord::tableName(), 'environment');
    }

    /**
     * Removes runs older than the retention period — at most {@see self::$pruneBatch}, oldest first —
     * and only from the history's own table. A run started exactly the period ago is kept.
     *
     * @throws InvalidConfigException if the period or the batch is not one, before anything is removed.
     */
    public function prune(?DateTimeImmutable $now = null): int
    {
        return Retention::prune(DiagnosticRunRecord::TABLE, 'startedAt', $this->retainDays, $this->pruneBatch, $now);
    }

    private function pruneWhenFree(): void
    {
        Retention::whenFree($this->pruned, fn() => $this->prune(), 'Old diagnostic runs could not be removed');
    }

    private function encode(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * A seam, so a test can make the row fail to save and prove nothing of it is kept.
     *
     * @throws RuntimeException
     */
    protected function save(DiagnosticRunRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor diagnostic run could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }
}
