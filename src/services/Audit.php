<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use DateTimeImmutable;
use RuntimeException;
use Tahadudhiya\WebDoctor\enums\AuditAction;
use Tahadudhiya\WebDoctor\enums\AuditObjectType;
use Tahadudhiya\WebDoctor\enums\AuditResult;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\helpers\Actor;
use Tahadudhiya\WebDoctor\helpers\QueryParams;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Retention;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\models\AuditEntry;
use Tahadudhiya\WebDoctor\models\AuditFilter;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\ErrorRecording;
use Tahadudhiya\WebDoctor\models\Investigation;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\IssueReconciliation;
use Tahadudhiya\WebDoctor\models\ListPage;
use Tahadudhiya\WebDoctor\models\RecommendationSet;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\records\AuditRecord;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * The audit trail: what was done with Web Doctor, by whom, to what, where, when and how it ended.
 *
 * Who did it is Craft's answer, read here from the signed-in user, never something a caller passes
 * in — an entry naming somebody else would be worse than none. Nobody signed in (the console, the
 * queue) is recorded as that.
 *
 * Two kinds of act are recorded, written differently on purpose:
 *
 * - A change Web Doctor makes on somebody's behalf — an issue moved or resolved, a repair previewed
 *   or carried out, a verification applied — is recorded with {@see self::record()} inside the same
 *   transaction as the change. The act and its entry stand or fall together, so there is never a
 *   change with no entry, nor an entry for a change that did not happen.
 * - That work started or finished — a diagnostic run, an investigation, the recommendations a run
 *   produced — is recorded with {@see self::tryRecord()} on its own. The work is the record of
 *   itself; a trail that cannot be written is logged rather than allowed to stop it.
 *
 * Nothing here updates or deletes an entry, except retention: entries older than
 * {@see self::$retainDays} days are removed, so the table does not grow without end.
 */
class Audit extends Component
{
    /**
     * @var int How many days an entry is kept. Settable through the component's config; refused
     * below one, rather than read as some other number.
     */
    public int $retainDays = 365;

    /** @var int The most entries one round of retention removes, so it never becomes a long delete. */
    public int $pruneBatch = 500;

    /** @var bool Whether this instance has pruned already: once per request is enough. */
    private bool $pruned = false;

    /**
     * Records an act, and throws if it cannot — so a caller writing it inside the transaction of
     * the change it records has the change undone with it.
     *
     * @param array<string, mixed> $details Named values only: see {@see AuditEntry}.
     * @param string|null $siteName The site's name where the caller kept one — a deleted site's, which
     * Craft can no longer give. Read from Craft otherwise.
     * @throws InvalidConfigException if retention is configured as something that cannot be one.
     * @throws RuntimeException if who is acting, the site or the moment cannot be established, or
     * the entry cannot be saved — an entry is never written with a guess in place of any of them.
     */
    public function record(
        AuditAction $action,
        AuditResult $result,
        string $summary,
        AuditObjectType $objectType,
        ?string $objectId,
        ?string $objectLabel = null,
        ?int $issueId = null,
        ?string $environment = null,
        ?int $siteId = null,
        array $details = [],
        ?string $siteName = null,
    ): AuditEntry {
        Retention::validate($this->retainDays, $this->pruneBatch);
        [$userId, $userName] = Actor::current();

        $entry = new AuditEntry(
            id: null,
            action: $action,
            result: $result,
            summary: $summary,
            objectType: $objectType,
            objectId: $objectId,
            objectLabel: $objectLabel,
            issueId: $issueId,
            userId: $userId,
            userName: $userName,
            environment: $environment ?? DiagnosticContext::currentEnvironment(),
            siteId: $siteId,
            siteName: $siteName ?? SiteName::of($siteId),
            details: $details,
            occurredAt: new DateTimeImmutable(),
        );

        $record = new AuditRecord();
        $record->action = $entry->action->value;
        $record->result = $entry->result->value;
        $record->summary = $entry->summary;
        $record->objectType = $entry->objectType->value;
        $record->objectId = $entry->objectId === null ? null : mb_substr($entry->objectId, 0, 36);
        $record->objectLabel = $entry->objectLabel;
        $record->issueId = $entry->issueId;
        $record->userId = $entry->userId;
        $record->userName = $entry->userName;
        $record->environment = mb_substr($entry->environment, 0, 255);
        $record->siteId = $entry->siteId;
        $record->siteName = $entry->siteName;
        $record->details = $entry->storedDetails() === [] ? null : (string)json_encode(
            $entry->storedDetails(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
        $record->occurredAt = $this->moment($entry->occurredAt ?? throw new RuntimeException('An audit entry has no moment.'));

        $this->save($record);
        $this->pruneWhenFree();

        return AuditEntry::fromRecord($record);
    }

    /**
     * Records that work started or finished, without letting the trail stop the work: a failure is
     * logged, and null returned.
     *
     * @param array<string, mixed> $details
     */
    public function tryRecord(
        AuditAction $action,
        AuditResult $result,
        string $summary,
        AuditObjectType $objectType,
        ?string $objectId,
        ?string $objectLabel = null,
        ?int $issueId = null,
        ?string $environment = null,
        ?int $siteId = null,
        array $details = [],
        ?string $siteName = null,
    ): ?AuditEntry {
        try {
            return $this->record($action, $result, $summary, $objectType, $objectId, $objectLabel, $issueId, $environment, $siteId, $details, $siteName);
        } catch (Throwable $e) {
            SafeException::log(sprintf('The audit trail could not record "%s"', $action->value), $e);

            return null;
        }
    }

    /**
     * That somebody set a diagnostic run going: every check, or the ones they chose.
     *
     * @param list<string> $selected Empty for every check.
     */
    public function runStarted(DiagnosticContext $context, int $checks, array $selected): void
    {
        $this->tryRecord(
            AuditAction::DIAGNOSTICS_STARTED,
            AuditResult::NONE,
            $selected === []
                ? Craft::t('web-doctor', 'Started every check ({count}) at {depth} depth.', ['count' => $checks, 'depth' => $context->depth->value])
                : Craft::t('web-doctor', 'Started {count, plural, =1{1 selected check} other{# selected checks}} at {depth} depth.', ['count' => count($selected), 'depth' => $context->depth->value]),
            AuditObjectType::RUN,
            $context->runId,
            environment: $context->environment,
            siteId: $context->siteId,
            details: ['depth' => $context->depth->value, 'checks' => $selected === [] ? $checks : count($selected), 'selected' => $selected],
        );
    }

    /**
     * How a diagnostic run ended: what its checks reported, and whether its findings were kept.
     * A run whose findings could not all be kept is partial; one that could not run at all failed.
     */
    public function runCompleted(DiagnosticContext $context, ?DiagnosticRun $run, ?IssueReconciliation $issues, ?ErrorRecording $errors, bool $kept, ?int $historyId = null): void
    {
        if ($run === null) {
            $this->tryRecord(
                AuditAction::DIAGNOSTICS_COMPLETED,
                AuditResult::FAILED,
                Craft::t('web-doctor', 'The checks could not be run.'),
                AuditObjectType::RUN,
                $context->runId,
                environment: $context->environment,
                siteId: $context->siteId,
            );

            return;
        }

        $statuses = [];

        foreach (DiagnosticStatus::cases() as $status) {
            $statuses[$status->value] = 0;
        }

        foreach ($run->results() as $result) {
            $statuses[$result->status->value]++;
        }

        $this->tryRecord(
            AuditAction::DIAGNOSTICS_COMPLETED,
            $kept ? AuditResult::SUCCEEDED : AuditResult::PARTIAL,
            $kept
                ? Craft::t('web-doctor', '{count, plural, =1{1 check ran} other{# checks ran}}: {problems, plural, =0{no problems reported} =1{1 problem reported} other{# problems reported}}.', ['count' => $run->count(), 'problems' => $statuses['warning'] + $statuses['fail']])
                : Craft::t('web-doctor', '{count, plural, =1{1 check ran} other{# checks ran}}, but not everything it found could be kept.', ['count' => $run->count()]),
            AuditObjectType::RUN,
            $run->id(),
            environment: $run->context->environment,
            siteId: $run->context->siteId,
            details: array_filter($statuses) + array_filter([
                'issuesOpened' => $issues?->opened,
                'issuesRecurred' => $issues?->recurred,
                'issuesResolved' => $issues?->resolved,
                'errors' => $errors?->groups,
            ], static fn(?int $n): bool => $n !== null && $n > 0) + array_filter(['historyId' => $historyId]) + ['durationMs' => round($run->durationMs, 1)],
        );
    }

    /**
     * Which recommendations a run or an investigation produced for its findings: the rule each
     * finding was given, never the advice's text, which the finding's page shows as it stands.
     * Nothing is recorded where nothing was recommended.
     *
     * @param array<string, RecommendationSet> $sets By the check whose finding each answers.
     * @param array<string, mixed> $details Anything else the object needs to be found again by.
     */
    public function recommendationsGenerated(AuditObjectType $objectType, string $objectId, ?string $objectLabel, ?int $issueId, string $environment, ?int $siteId, array $sets, array $details = []): void
    {
        $given = [];
        $broken = [];

        ksort($sets);

        foreach ($sets as $diagnosticId => $set) {
            foreach ($set->recommendations as $recommendation) {
                $given[] = $diagnosticId . ': ' . $recommendation->ruleId;
            }

            foreach ($set->failed as $ruleId) {
                $broken[] = $diagnosticId . ': ' . $ruleId;
            }
        }

        if ($given === [] && $broken === []) {
            return;
        }

        $this->tryRecord(
            AuditAction::RECOMMENDATIONS_GENERATED,
            $broken === [] ? AuditResult::SUCCEEDED : AuditResult::PARTIAL,
            $broken === []
                ? Craft::t('web-doctor', '{count, plural, =1{1 recommendation} other{# recommendations}} given for the findings.', ['count' => count($given)])
                : Craft::t('web-doctor', '{count, plural, =1{1 recommendation} other{# recommendations}} given for the findings; {broken, plural, =1{1 rule} other{# rules}} could not be applied.', ['count' => count($given), 'broken' => count($broken)]),
            $objectType,
            $objectId,
            $objectLabel,
            $issueId,
            $environment,
            $siteId,
            $details + ['count' => count($given), 'recommendations' => $given, 'rulesThatBroke' => $broken],
        );
    }

    /**
     * Which recommendations an investigation's findings were given, as its page shows them, read
     * from what it kept. Choosing them again for the record never costs the investigation anything:
     * a failure is logged.
     *
     * @param Issue|null $issue The issue investigated; null for a recipe's investigation.
     */
    public function investigationRecommendations(Investigation $investigation, ?Issue $issue, Recommendations $recommendations): void
    {
        try {
            $this->recommendationsGenerated(
                AuditObjectType::INVESTIGATION,
                (string)$investigation->id,
                $issue !== null ? $issue->title : $investigation->plan->ruleLabel,
                $issue?->id,
                $investigation->environment,
                $investigation->siteId,
                $recommendations->forStoredInvestigation($investigation, $issue),
                array_filter(['recipeId' => $investigation->recipeId]),
            );
        } catch (Throwable $e) {
            SafeException::log('The recommendations an investigation produced could not be recorded', $e);
        }
    }

    /**
     * One page of the trail, as a filter asks for it: newest first unless the oldest are asked for,
     * the ID breaking ties between acts in the same second. A page past the end shows the last.
     *
     * @return ListPage<AuditEntry>
     */
    public function find(AuditFilter $filter): ListPage
    {
        $query = AuditRecord::find();

        if ($filter->actions !== []) {
            $query->andWhere(['action' => array_map(static fn(AuditAction $a): string => $a->value, $filter->actions)]);
        }

        if ($filter->results !== []) {
            $query->andWhere(['result' => array_map(static fn(AuditResult $r): string => $r->value, $filter->results)]);
        }

        if ($filter->withoutUser) {
            $query->andWhere(['userId' => null, 'userName' => null]);
        } elseif ($filter->userId !== null) {
            $query->andWhere(['userId' => $filter->userId]);
        }

        if ($filter->issueId !== null) {
            $query->andWhere(['issueId' => $filter->issueId]);
        }

        if ($filter->environment !== null) {
            $query->andWhere(['environment' => $filter->environment]);
        }

        // No particular site is no site ID and no kept name: a deleted site's entries are not that.
        if ($filter->withoutSite) {
            $query->andWhere(SiteName::place(null));
        } elseif ($filter->siteId !== null) {
            $query->andWhere(SiteName::place($filter->siteId));
        }

        if ($filter->from !== null) {
            $query->andWhere(['>=', 'occurredAt', QueryParams::localDayStart($filter->from)]);
        }

        if ($filter->to !== null) {
            $query->andWhere(['<=', 'occurredAt', QueryParams::localDayEnd($filter->to)]);
        }

        $total = (int)$query->count();
        $pages = max(1, (int)ceil($total / $filter->perPage));

        if ($filter->page > $pages) {
            $filter = $filter->onPage($pages);
        }

        $entries = [];

        foreach ($query->orderBy(self::order($filter->oldestFirst))->offset($filter->offset())->limit($filter->perPage)->all() as $record) {
            $entry = $record instanceof AuditRecord ? AuditEntry::fromRecord($record) : null;

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new ListPage($entries, $total, $filter);
    }

    /**
     * The order a page is read in: the moment, then the ID, both one way round. The ID is what keeps
     * two entries in the same second from swapping places between pages, whatever index the
     * database chooses.
     *
     * @return array<string, int>
     */
    public static function order(bool $oldestFirst): array
    {
        $direction = $oldestFirst ? SORT_ASC : SORT_DESC;

        return ['occurredAt' => $direction, 'id' => $direction];
    }

    /**
     * The people the trail names who can still be filtered by, by ID, each under a name an entry
     * recorded. Somebody whose account was deleted has no ID left to filter by. One row per distinct
     * pair is read, never the trail itself.
     *
     * @return array<int, string>
     */
    public function knownUsers(): array
    {
        return Actor::choicesIn(AuditRecord::tableName(), [['userId', 'userName']]);
    }

    /**
     * @return list<string>
     */
    public function knownEnvironments(): array
    {
        return QueryParams::choicesIn(AuditRecord::tableName(), 'environment');
    }

    /**
     * Removes entries older than the retention period — at most {@see self::$pruneBatch}, oldest
     * first — and only from the audit table. An entry recorded exactly the period ago is kept.
     *
     * @return int How many were removed.
     * @throws InvalidConfigException if the period or the batch is not one, before anything is removed.
     */
    public function prune(?DateTimeImmutable $now = null): int
    {
        return Retention::prune(AuditRecord::TABLE, 'occurredAt', $this->retainDays, $this->pruneBatch, $now);
    }

    /**
     * Retention after an entry is written: at most once an instance, and never inside somebody
     * else's transaction, where a failed delete would cost them their change. Its failure costs the
     * entry nothing and is logged.
     */
    private function pruneWhenFree(): void
    {
        Retention::whenFree($this->pruned, fn() => $this->prune(), 'Old audit entries could not be removed');
    }

    /**
     * The moment an entry is stored under. A seam, so a test can make it fail to be prepared and
     * prove the entry — and the change it records — is not written with another moment instead.
     *
     * @throws RuntimeException
     */
    protected function moment(DateTimeImmutable $when): string
    {
        return StoredTime::forDb($when);
    }

    /**
     * A seam rather than a private call, so a test can make an entry fail to save and prove the
     * change it records goes back with it.
     *
     * @throws RuntimeException
     */
    protected function save(AuditRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor audit entry could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }
}
