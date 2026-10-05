<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\helpers\Db;
use craft\web\Controller;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\controllers\AuditController;
use Tahadudhiya\WebDoctor\controllers\HistoryController;
use Tahadudhiya\WebDoctor\controllers\RepairsController;
use Tahadudhiya\WebDoctor\enums\AuditAction;
use Tahadudhiya\WebDoctor\enums\AuditObjectType;
use Tahadudhiya\WebDoctor\enums\AuditResult;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\InvestigationStatus;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\QueryParams;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\AuditEntry;
use Tahadudhiya\WebDoctor\models\AuditFilter;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\HistoricalRun;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\RecommendationSet;
use Tahadudhiya\WebDoctor\models\RepairFilter;
use Tahadudhiya\WebDoctor\models\RunFilter;
use Tahadudhiya\WebDoctor\records\AuditRecord;
use Tahadudhiya\WebDoctor\records\DiagnosticRunRecord;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\records\RepairRecord;
use Tahadudhiya\WebDoctor\services\Audit;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\Errors;
use Tahadudhiya\WebDoctor\services\EvidenceStore;
use Tahadudhiya\WebDoctor\services\History;
use Tahadudhiya\WebDoctor\services\Investigations;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\Recommendations;
use Tahadudhiya\WebDoctor\services\RootCauses;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\Tests\_support\WebDoctorTables;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * The audit trail and the repair history, end to end: what each act records and who it names, that
 * a change and its entry stand or fall together, that a trail which cannot be written never stops
 * work that is its own record, redaction at the database and on the page, the log's order and every
 * filter, who may read either, retention, and what the pages render.
 *
 * What the repair, verification, investigation and dashboard services record as they act is
 * asserted where each one's harness already lives — RepairTest, VerificationTest, InvestigationTest
 * and HealthDashboardTest — so this file holds the trail itself.
 *
 * Every test runs under an environment name of its own and removes only that environment's rows.
 */
class AuditTest extends TestCase
{
    private const ACCESS_CP = 'accessCp';

    private string $environment;
    private Audit $audit;
    private Issues $issues;
    private WebDoctor $plugin;
    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Craft::$app->getDb()->tableExists(AuditRecord::TABLE)) {
            self::fail(sprintf(
                'The table %s does not exist. Reinstall Web Doctor in this project first: `php craft plugin/uninstall web-doctor && php craft plugin/install web-doctor`.',
                AuditRecord::TABLE,
            ));
        }

        $this->environment = 'tests-' . bin2hex(random_bytes(5));
        $this->audit = new Audit();
        $this->issues = new Issues(['evidence' => new EvidenceStore(), 'audit' => $this->audit]);

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
        $this->plugin->set('audit', $this->audit);
        $this->plugin->set('issues', $this->issues);

        $this->signIn(admin: true);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        AuditRecord::deleteAll(['like', 'environment', 'tests-%', false]);
        DiagnosticRunRecord::deleteAll(['like', 'environment', 'tests-%', false]);
        RepairRecord::deleteAll(['like', 'environment', 'tests-%', false]);
        IssueRecord::deleteAll(['like', 'environment', 'tests-%', false]);

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }

        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }

        parent::tearDown();
    }

    // What is recorded -------------------------------------------------------------

    public function testAStatusChangeIsRecordedWithWhoDidItToWhatWhereAndWhy(): void
    {
        $issue = $this->raise();

        $this->issues->transition($issue->id, IssueStatus::IGNORED, 'Known and accepted.', 1);

        $entries = $this->entries();
        self::assertCount(1, $entries);
        $entry = $entries[0];

        self::assertSame(AuditAction::ISSUE_STATUS_CHANGED, $entry->action);
        self::assertSame(AuditResult::NONE, $entry->result);
        self::assertSame(AuditObjectType::ISSUE, $entry->objectType);
        self::assertSame((string)$issue->id, $entry->objectId);
        self::assertSame($issue->id, $entry->issueId);
        self::assertSame($issue->title, $entry->objectLabel);
        // Craft's signed-in user, never the ID the caller passed.
        self::assertSame(1, $entry->userId);
        self::assertSame(TestUser::USERNAME, $entry->userName);
        self::assertSame($this->environment, $entry->environment);
        self::assertSame(['from' => 'new', 'to' => 'ignored', 'reason' => 'Known and accepted.', 'diagnosticId' => 'tests.audit'], $entry->details);
        self::assertSame('web-doctor/issues/' . $issue->id, $entry->cpPath());
    }

    public function testAnIssueAChecksRunResolvesIsRecordedAndNobodySignedInIsRecordedAsNobody(): void
    {
        $issue = $this->raise();
        Craft::$app->getUser()->setIdentity(null);

        $this->runCheck(DiagnosticStatus::PASS);

        self::assertSame(IssueStatus::RESOLVED, $this->issues->get($issue->id)?->status);

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::ISSUE_RESOLVED, $entries[0]->action);
        self::assertSame(AuditResult::SUCCEEDED, $entries[0]->result);
        self::assertSame(\Tahadudhiya\WebDoctor\enums\IssueResolution::OBSERVED_CLEAR->value, $entries[0]->details['resolution'] ?? null);
        self::assertNull($entries[0]->userId);
        self::assertNull($entries[0]->userName);
        self::assertSame(Craft::t('web-doctor', 'Nobody signed in (console or queue)'), $entries[0]->userLabel());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function changesThatMustNotStandWithoutTheirEntry(): array
    {
        return [
            'a status change' => ['transition'],
            'a resolution' => ['resolve'],
        ];
    }

    /**
     * A change Web Doctor makes and the record that somebody made it are one act: an entry that cannot
     * be written takes the change with it, so there is never a change the trail does not account for.
     */
    #[DataProvider('changesThatMustNotStandWithoutTheirEntry')]
    public function testAChangeWhoseEntryCannotBeWrittenDoesNotHappen(string $change): void
    {
        $issue = $this->raise();
        $events = (int)IssueEventRecord::find()->where(['issueId' => $issue->id])->count();
        $this->issues->audit = $this->failingAudit();

        try {
            if ($change === 'transition') {
                $this->issues->transition($issue->id, IssueStatus::CONFIRMED, null, 1);
            } else {
                $this->runCheck(DiagnosticStatus::PASS);
            }

            self::fail('The change went through without its entry.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('audit entry could not be saved', $e->getMessage());
        }

        $after = $this->issues->get($issue->id);
        self::assertSame(IssueStatus::NEW, $after?->status);
        self::assertSame($events, (int)IssueEventRecord::find()->where(['issueId' => $issue->id])->count());
        self::assertSame([], $this->entries());
    }

    public function testARefusedChangeRecordsNothing(): void
    {
        $issue = $this->raise();
        $before = WebDoctorTables::snapshot();

        try {
            $this->issues->transition($issue->id, IssueStatus::RESOLVED, null, 1);
            self::fail('An issue was resolved by hand.');
        } catch (Refusal) {
        }

        self::assertSame($before, WebDoctorTables::snapshot());
    }

    public function testAnInvestigationIsRecordedStartingAndEndingAndATrailThatCannotBeWrittenDoesNotStopIt(): void
    {
        $issue = $this->raise();
        $investigations = $this->investigations($this->audit);

        $investigation = $investigations->investigate($issue->id);

        $entries = $this->entries(new AuditFilter(oldestFirst: true, environment: $this->environment));
        self::assertSame(
            [AuditAction::INVESTIGATION_STARTED, AuditAction::INVESTIGATION_COMPLETED],
            array_map(static fn(AuditEntry $e): AuditAction => $e->action, array_values(array_filter($entries, static fn(AuditEntry $e): bool => $e->objectType === AuditObjectType::INVESTIGATION))),
        );

        $ended = array_values(array_filter($entries, static fn(AuditEntry $e): bool => $e->action === AuditAction::INVESTIGATION_COMPLETED))[0];
        self::assertSame((string)$investigation->id, $ended->objectId);
        self::assertSame($issue->id, $ended->issueId);
        self::assertSame(AuditResult::SUCCEEDED, $ended->result);
        self::assertSame(1, $ended->details['checksRun'] ?? null);
        self::assertSame(sprintf('web-doctor/issues/%d/investigations/%d', $issue->id, $investigation->id), $ended->cpPath());

        // The investigation is the record of itself: a trail that cannot be written is logged and the
        // investigation goes on to its end.
        AuditRecord::deleteAll(['environment' => $this->environment]);
        $again = $this->investigations($this->failingAudit())->investigate($issue->id);

        self::assertSame('completed', $again->status->value);
        self::assertSame([], $this->entries());
    }

    /**
     * An investigation's ending is recorded as it ended, and one whose status cannot be read is not
     * recorded as having failed, or anything else it may not have done.
     */
    public function testAnInvestigationsEndingIsRecordedAsItEndedAndAnUnreadableOneAsInconclusive(): void
    {
        self::assertSame(AuditResult::SUCCEEDED, Investigations::endingResult(InvestigationStatus::COMPLETED));
        self::assertSame(AuditResult::PARTIAL, Investigations::endingResult(InvestigationStatus::PARTIAL));
        self::assertSame(AuditResult::FAILED, Investigations::endingResult(InvestigationStatus::FAILED));
        self::assertSame(AuditResult::FAILED, Investigations::endingResult(InvestigationStatus::RUNNING));
        self::assertSame(AuditResult::INCONCLUSIVE, Investigations::endingResult(null));
    }

    public function testTheRecommendationsAFindingWasGivenAreRecordedByRuleAndNothingIsRecordedWhereNoneWas(): void
    {
        $context = new DiagnosticContext(environment: $this->environment);
        $now = new DateTimeImmutable();
        $failedJobs = (new DiagnosticResult(
            diagnosticId: 'queue.failedJobs',
            name: 'Failed jobs',
            category: DiagnosticCategory::QUEUE,
            status: DiagnosticStatus::FAIL,
            summary: '1 job has failed.',
            evidence: [
                new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'queue.failedJobs', ['failed' => 1, 'examined' => 1, 'distinctJobsInSample' => 1, 'sampleLimit' => 10, 'sampleIsComplete' => true]),
                new Evidence(EvidenceType::QUEUE_JOB, 'Updating search indexes', 'queue.failedJobs', ['description' => 'Updating search indexes', 'occurrences' => 1, 'firstFailed' => 1700000000, 'lastFailed' => 1700000600, 'error' => 'Index not found']),
            ],
        ))->withExecution($context, $now, $now, 1.0);
        $passing = (new TestDiagnostic(['diagnosticId' => 'tests.audit']))->run($context)->withExecution($context, $now, $now, 1.0);
        $run = new DiagnosticRun(context: $context, results: [$passing, $failedJobs], startedAt: $now, finishedAt: $now, durationMs: 1.0);

        $sets = (new Recommendations())->forRun($run);

        // Only findings are advised on, by the check that reported each.
        self::assertSame(['queue.failedJobs'], array_keys($sets));

        $this->audit->recommendationsGenerated(AuditObjectType::RUN, $run->id(), null, null, $this->environment, null, $sets);
        $this->audit->recommendationsGenerated(AuditObjectType::RUN, $run->id(), null, null, $this->environment, null, ['tests.audit' => new RecommendationSet()]);
        $this->audit->recommendationsGenerated(AuditObjectType::RUN, $run->id(), null, null, $this->environment, null, ['tests.broken' => new RecommendationSet(failed: ['tests.rule'])]);

        $entries = $this->entries(new AuditFilter(oldestFirst: true, environment: $this->environment));
        self::assertCount(2, $entries, 'A finding nothing was recommended for recorded an entry.');

        self::assertSame(AuditAction::RECOMMENDATIONS_GENERATED, $entries[0]->action);
        self::assertSame(AuditResult::SUCCEEDED, $entries[0]->result);
        self::assertSame(['queue.failedJobs: queue.inspectThenRetry'], $entries[0]->details['recommendations'] ?? null);
        self::assertSame($run->id(), $entries[0]->objectId);

        // A rule that broke is said, as the page says it.
        self::assertSame(AuditResult::PARTIAL, $entries[1]->result);
        self::assertSame(['tests.broken: tests.rule'], $entries[1]->details['rulesThatBroke'] ?? null);
    }

    // Redaction ---------------------------------------------------------------------

    public function testNoCredentialReachesTheTrailByAnyRouteAndNoPayloadCanBeCarried(): void
    {
        $secret = 'hunter2-' . bin2hex(random_bytes(4));
        $issue = $this->raise(title: "Login refused: password=$secret");

        // A person's reason, a summary, a label, a named credential, a quoted one, a list, and a
        // payload — a structure — which an entry has nowhere to keep.
        $this->issues->transition($issue->id, IssueStatus::CONFIRMED, "Pasted from the log: password=$secret", 1);
        $this->audit->record(
            AuditAction::DIAGNOSTICS_COMPLETED,
            AuditResult::SUCCEEDED,
            "SMTP refused: password=$secret",
            AuditObjectType::RUN,
            'run-1',
            "token=$secret",
            environment: $this->environment,
            details: [
                'password' => $secret,
                'message' => "SMTP refused: password=$secret",
                'list' => ["api_key=$secret", 3, true],
                'payload' => ['evidence' => ['dsn' => "mysql://root:$secret@db"]],
                'not a name' => 'dropped',
            ],
        );

        $rows = (new \craft\db\Query())->from(AuditRecord::TABLE)->where(['environment' => $this->environment])->all();
        self::assertCount(2, $rows);
        self::assertStringNotContainsString($secret, (string)json_encode($rows));

        $entry = $this->entries(new AuditFilter(actions: [AuditAction::DIAGNOSTICS_COMPLETED], environment: $this->environment))[0];
        self::assertArrayNotHasKey('payload', $entry->details);
        self::assertArrayNotHasKey('not a name', $entry->details);
        self::assertSame(Redaction::REDACTED, $entry->details['password']);
        self::assertTrue($entry->truncated, 'Leaving something out was not said.');

        $html = $this->renderLog(['environment' => $this->environment]);
        self::assertStringNotContainsString($secret, $html);
        self::assertStringNotContainsString(Redaction::REDACTED, $html, 'A withheld value was printed as the raw marker.');
    }

    // Reading ---------------------------------------------------------------------------

    public function testTheLogIsChronologicalNewestOrOldestFirstWithActsInTheSameSecondKeptInOrder(): void
    {
        $ids = [];

        foreach (['-3 hours', '-1 hour', '-1 hour', 'now'] as $i => $when) {
            $ids[] = $this->entry(AuditAction::ISSUE_STATUS_CHANGED, AuditResult::NONE, "Entry $i", at: new DateTimeImmutable($when));
        }

        $newest = array_map(static fn(AuditEntry $e): ?int => $e->id, $this->entries());
        $oldest = array_map(static fn(AuditEntry $e): ?int => $e->id, $this->entries(new AuditFilter(environment: $this->environment, oldestFirst: true)));

        self::assertSame([$ids[3], $ids[2], $ids[1], $ids[0]], $newest);
        self::assertSame($ids, $oldest);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<int>}>
     */
    public static function filters(): array
    {
        // Indexes into the entries the test records, oldest first: see testEachFilterShowsExactlyWhatItAsksFor.
        return [
            'one action' => [['auditAction' => ['repair.executed']], [1, 2]],
            'two actions' => [['auditAction' => ['repair.executed', 'issue.resolved']], [1, 2, 3]],
            'a result' => [['result' => ['failed']], [2]],
            'a user' => [['user' => '1'], [0, 1, 2]],
            // Nobody signed in, as against somebody whose account has since been deleted.
            'nobody signed in' => [['user' => 'none'], [4, 3]],
            'an issue' => [['issue' => '{issue}'], [0, 3]],
            'from a day' => [['from' => '{today}'], [0, 1, 2, 3, 5]],
            'to a day' => [['to' => '{yesterday}'], [4]],
            'a day exactly' => [['from' => '{yesterday}', 'to' => '{yesterday}'], [4]],
            'together' => [['auditAction' => ['repair.executed'], 'result' => ['succeeded'], 'user' => '1'], [1]],
            'nothing matching' => [['auditAction' => ['verification.executed']], []],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param list<int> $expected
     */
    #[DataProvider('filters')]
    public function testEachFilterShowsExactlyWhatItAsksFor(array $params, array $expected): void
    {
        $issue = $this->raise();
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $today = new DateTimeImmutable('today 12:00', $tz);
        $yesterday = $today->modify('-1 day');

        $recorded = [
            $this->entry(AuditAction::ISSUE_STATUS_CHANGED, AuditResult::NONE, 'Moved', issueId: $issue->id, at: $today),
            $this->entry(AuditAction::REPAIR_EXECUTED, AuditResult::SUCCEEDED, 'Carried out', at: $today),
            $this->entry(AuditAction::REPAIR_EXECUTED, AuditResult::FAILED, 'Failed', at: $today),
            $this->entry(AuditAction::ISSUE_RESOLVED, AuditResult::SUCCEEDED, 'Resolved', issueId: $issue->id, signedIn: false, at: $today),
            $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Started', signedIn: false, at: $yesterday),
            $this->entry(AuditAction::DIAGNOSTICS_COMPLETED, AuditResult::SUCCEEDED, 'Completed', at: $today),
        ];

        // The last was done by somebody whose account has since been deleted: the link is gone, the
        // name stays.
        AuditRecord::updateAll(['userId' => null], ['id' => $recorded[5]]);

        $params = array_map(static fn(mixed $v): mixed => is_string($v) ? strtr($v, [
            '{issue}' => (string)$issue->id,
            '{today}' => $today->format('Y-m-d'),
            '{yesterday}' => $yesterday->format('Y-m-d'),
        ]) : $v, $params);

        $found = $this->audit->find(AuditFilter::fromParams($params + ['environment' => $this->environment, 'order' => 'oldest']));

        self::assertSame(
            array_map(static fn(int $i): int => $recorded[$i], $expected),
            array_map(static fn(AuditEntry $e): ?int => $e->id, $found->items),
        );
        self::assertSame(count($expected), $found->total);
    }

    public function testAPagePastTheEndShowsTheLastRatherThanNothing(): void
    {
        foreach (range(1, 5) as $i) {
            $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, "Entry $i");
        }

        $page = $this->audit->find(new AuditFilter(environment: $this->environment, page: 99, perPage: 2));

        self::assertSame(3, $page->currentPage());
        self::assertCount(1, $page->items);
        self::assertSame(5, $page->firstPosition());
        self::assertFalse($page->hasNextPage());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedFilters(): array
    {
        return [
            'an action that is not one' => [['auditAction' => 'repair.deleted']],
            'a result that is not one' => [['result' => ['done']]],
            'a user that is not an ID' => [['user' => '1abc']],
            'a user of zero' => [['user' => '0']],
            'an issue that is not an ID' => [['issue' => "12\n"]],
            'a date that is not one' => [['from' => '2026-13-01']],
            'a range that ends before it starts' => [['from' => '2026-05-02', 'to' => '2026-05-01']],
            'an order that is not one' => [['order' => 'sideways']],
            'a page that is not a whole number' => [['page' => '1.5']],
            'more per page than may be asked for' => [['perPage' => '500']],
            'an environment that is a list' => [['environment' => ['dev']]],
            'an environment of only spaces, which would widen to any' => [['environment' => '   ']],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('malformedFilters')]
    public function testAFilterThatIsNotOneIsRefusedAndNothingIsRead(array $params): void
    {
        $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Started');
        $before = WebDoctorTables::snapshot();
        $request = $this->request('GET');
        $request->setQueryParams($params);

        try {
            $this->auditController()->runAction('index');
            self::fail('A malformed filter was read as some other filter.');
        } catch (BadRequestHttpException) {
        }

        self::assertSame($before, WebDoctorTables::snapshot());
    }

    // Who may read it -------------------------------------------------------------------

    public function testTheLogAndTheRepairHistoryAreControlPanelPagesOnly(): void
    {
        $refused = 0;

        foreach ([fn() => $this->auditController(), fn() => $this->repairsController()] as $controller) {
            $this->request('GET', cp: false);

            try {
                $controller()->runAction('index');
                self::fail('A front-end request reached a control panel page.');
            } catch (BadRequestHttpException) {
                $refused++;
            }
        }

        self::assertSame(2, $refused);
    }

    public function testTheLogShowsWhatWasDoneWithLinksToWhatItWasDoneToAndReadingItWritesNothing(): void
    {
        $issue = $this->raise();
        $this->issues->transition($issue->id, IssueStatus::IGNORED, 'Accepted.', 1);
        $this->entry(AuditAction::REPAIR_PREVIEWED, AuditResult::NONE, 'Previewed the retry', objectType: AuditObjectType::REPAIR, objectId: '42', label: 'Retry failed jobs');
        $before = WebDoctorTables::snapshot();

        $html = $this->renderLog(['environment' => $this->environment]);

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertStringContainsString(AuditAction::ISSUE_STATUS_CHANGED->label(), $html);
        self::assertStringContainsString(AuditAction::REPAIR_PREVIEWED->label(), $html);
        self::assertStringContainsString(TestUser::USERNAME, $html);
        self::assertStringContainsString('web-doctor/issues/' . $issue->id, $html);
        self::assertStringContainsString('web-doctor/repairs/42', $html);
        self::assertStringContainsString('Accepted.', $html);
    }

    // The repair history -------------------------------------------------------------------

    public function testTheRepairHistoryHoldsEveryRepairAndShowsExactlyWhatEachFilterAsksFor(): void
    {
        $issue = $this->raise();
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $today = new DateTimeImmutable('today 12:00', $tz);

        $previewed = $this->repair($issue, RepairStatus::PREVIEWED, 'storage.createDirectories', $today->modify('-2 days'));
        $succeeded = $this->repair($issue, RepairStatus::SUCCEEDED, 'queue.retryFailedJobs', $today->modify('-1 day'), VerificationStatus::VERIFIED, executedBy: 1);
        $failed = $this->repair($issue, RepairStatus::FAILED, 'queue.retryFailedJobs', $today, executedBy: 1);

        $find = fn(array $params): array => array_map(
            static fn($r): int => $r->id,
            $this->plugin->getRepairs()->find(RepairFilter::fromParams($params + ['environment' => $this->environment]))->items,
        );

        self::assertSame([$failed, $succeeded, $previewed], $find([]));
        self::assertSame([$previewed, $succeeded, $failed], $find(['order' => 'oldest']));
        self::assertSame([$failed, $succeeded], $find(['repairAction' => 'queue.retryFailedJobs']));
        self::assertSame([$failed], $find(['status' => ['failed']]));
        self::assertSame([$succeeded], $find(['verification' => ['verified']]));
        self::assertSame([$failed, $succeeded, $previewed], $find(['user' => '1']));
        self::assertSame([$succeeded, $previewed], $find(['to' => $today->modify('-1 day')->format('Y-m-d')]));
        self::assertSame([$failed], $find(['from' => $today->format('Y-m-d')]));
    }

    public function testARepairOutlivesItsIssueAndWhoDidItOutlivesTheirAccount(): void
    {
        $issue = $this->raise(title: 'Storage directories are missing');
        $id = $this->repair($issue, RepairStatus::SUCCEEDED, 'storage.createDirectories', new DateTimeImmutable(), executedBy: 1);

        // The account and the issue both gone: the IDs are null and the names say who and what.
        RepairRecord::updateAll(['previewedBy' => null, 'executedBy' => null], ['id' => $id]);
        IssueRecord::deleteAll(['id' => $issue->id]);

        $repair = $this->plugin->getRepairs()->get($id);
        self::assertNotNull($repair);
        self::assertNull($repair->issueId);
        self::assertSame(Craft::t('web-doctor', '{name} (deleted)', ['name' => TestUser::USERNAME]), $repair->executedByLabel());

        $html = $this->render('repairs', 'detail', 'web-doctor/_repairs/_repair', ['repairId' => $id]);

        self::assertStringContainsString('Storage directories are missing', $html);
        self::assertStringContainsString(Craft::t('web-doctor', '(issue deleted)'), $html);
        self::assertStringContainsString(Craft::t('web-doctor', '{name} (deleted)', ['name' => TestUser::USERNAME]), $html);
        self::assertStringNotContainsString('web-doctor/repairs/verify', $html, 'A repair whose issue is gone was offered for verification.');
    }

    public function testARepairIsReachableOnItsOwnOrThroughItsIssueButNeverThroughAnother(): void
    {
        $issue = $this->raise();
        $other = $this->raise(component: 'other');
        $id = $this->repair($issue, RepairStatus::SUCCEEDED, 'storage.createDirectories', new DateTimeImmutable(), executedBy: 1);

        foreach ([['repairId' => $id], ['repairId' => $id, 'issueId' => $issue->id]] as $params) {
            self::assertStringContainsString('web-doctor/issues/' . $issue->id, $this->render('repairs', 'detail', 'web-doctor/_repairs/_repair', $params));
        }

        $this->request('GET');
        $this->expectException(NotFoundHttpException::class);
        $this->repairsController()->runAction('detail', ['repairId' => $id, 'issueId' => $other->id]);
    }

    public function testTheRepairListPageListsRepairsToReadersOfIssuesAndRefusesAMalformedFilter(): void
    {
        $issue = $this->raise();
        $id = $this->repair($issue, RepairStatus::FAILED, 'queue.retryFailedJobs', new DateTimeImmutable(), executedBy: 1);
        $before = WebDoctorTables::snapshot();

        $html = $this->render('repairs', 'index', 'web-doctor/_repairs/_history', [], ['environment' => $this->environment]);

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertStringContainsString('web-doctor/repairs/' . $id, $html);
        self::assertStringContainsString(Craft::t('web-doctor', 'Carried out by {name}', ['name' => TestUser::USERNAME]), $html);
        self::assertStringContainsString(RepairStatus::FAILED->label(), $html);

        $this->signIn(false, [self::ACCESS_CP, Permissions::VIEW]);
        $this->request('GET');

        try {
            $this->repairsController()->runAction('index');
            self::fail('The repair history was shown to somebody who may not read issues.');
        } catch (ForbiddenHttpException) {
        }

        $this->signIn(true);
        $this->request('GET')->setQueryParams(['repairAction' => 'Not An Id']);
        $this->expectException(BadRequestHttpException::class);
        $this->repairsController()->runAction('index');
    }


    // Who acted ----------------------------------------------------------------------------

    /**
     * @return array<string, array{string, int|null, string|null, bool}>
     */
    public static function actors(): array
    {
        return [
            'somebody signed in' => ['signedIn', 1, TestUser::USERNAME, false],
            'nobody signed in — the console, the queue' => ['nobody', null, null, false],
            'an identity lookup that fails' => ['throws', null, null, true],
            'an identity with no ID' => ['noId', null, null, true],
            'an identity with no username' => ['noName', null, null, true],
            'an identity with an ID that is not one' => ['zeroId', null, null, true],
        ];
    }

    /**
     * Who did something is Craft's answer. Nobody is an answer; not being able to ask, or an identity
     * that names nobody usable, is not — and a change that cannot say who made it does not happen.
     */
    #[DataProvider('actors')]
    public function testAnEntryNamesWhoActedAsCraftSaysAndNeverGuesses(string $who, ?int $userId, ?string $userName, bool $refused): void
    {
        $issue = $this->raise();
        $before = WebDoctorTables::snapshot();

        $this->asIdentity($who, function() use ($issue, $userId, $userName, $refused, $before): void {
            try {
                $this->issues->transition($issue->id, IssueStatus::CONFIRMED, null, null);
                self::assertFalse($refused, 'A change was recorded with a guess at who made it.');
            } catch (RuntimeException $e) {
                self::assertTrue($refused, 'A change somebody could be named for was refused: ' . $e->getMessage());
                self::assertSame($before, WebDoctorTables::snapshot(), 'A refused change left something behind.');
                self::assertNull($this->audit->tryRecord(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Started', AuditObjectType::RUN, 'run', environment: $this->environment), 'A failed entry was reported as made.');
                self::assertSame($before, WebDoctorTables::snapshot());

                return;
            }

            $entry = $this->entries()[0];
            self::assertSame($userId, $entry->userId);
            self::assertSame($userName, $entry->userName);
        });
    }

    public function testAnEntryKeepsWhoActedAfterTheirAccountIsDeleted(): void
    {
        $this->entry(AuditAction::ISSUE_STATUS_CHANGED, AuditResult::NONE, 'Moved');
        // What the foreign key does when the account is deleted.
        AuditRecord::updateAll(['userId' => null], ['environment' => $this->environment]);

        $entry = $this->entries()[0];

        self::assertTrue($entry->isIntact());
        self::assertSame(Craft::t('web-doctor', '{name} (deleted)', ['name' => TestUser::USERNAME]), $entry->userLabel());
        // Somebody whose account has gone is not nobody.
        self::assertSame([], $this->entries(new AuditFilter(withoutUser: true, environment: $this->environment)));
    }

    // Reading back what was stored ----------------------------------------------------------------

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function storedValues(): array
    {
        return [
            'an empty result' => ['result', '', 'result'],
            'an unknown result' => ['result', 'done', 'result'],
            'a result from a later version' => ['result', 'archived', 'result'],
            'an unknown action' => ['action', 'issue.archived', 'action'],
            'an unknown object' => ['objectType', 'incident', 'objectType'],
            'an empty object ID' => ['objectId', '', 'objectId'],
            'an empty moment' => ['occurredAt', '', 'occurredAt'],
            'a malformed moment' => ['occurredAt', 'yesterday', 'occurredAt'],
            'an impossible moment' => ['occurredAt', '2026-02-30 10:00:00', 'occurredAt'],
            'MySQL’s zero date' => ['occurredAt', '0000-00-00 00:00:00', 'occurredAt'],
            'details that are not JSON' => ['details', '{not json', 'details'],
            'details that are a list' => ['details', '[1,2]', 'details'],
            'details carrying a structure' => ['details', '{"payload":{"a":1}}', 'details'],
            'details that are a string' => ['details', '"text"', 'details'],
            'an issue ID that is not one' => ['issueId', '-3', 'issueId'],
            'a user named by ID but not by name' => ['userName', null, 'user'],
            'a site named by ID but not by name' => ['siteName', null, 'site'],
            'an empty environment' => ['environment', '', 'environment'],
            'an empty summary' => ['summary', '', 'summary'],
        ];
    }

    /**
     * A stored value that is not one Web Doctor writes is never read as another value — not an unknown
     * result as "no result", a broken moment as the epoch, broken details as none — and the entry is
     * still listed, saying so.
     */
    #[DataProvider('storedValues')]
    public function testAStoredValueThatIsNotOneIsReadAsUnreadableNeverAsAnother(string $column, mixed $value, string $field): void
    {
        $id = $this->entry(AuditAction::REPAIR_EXECUTED, AuditResult::SUCCEEDED, 'Carried out', objectType: AuditObjectType::REPAIR, objectId: '42', siteId: $this->siteId(), details: ['checks' => 1]);
        $record = AuditRecord::findOne($id);
        self::assertNotNull($record);
        $record->setAttribute($column, $value);

        $entry = AuditEntry::fromRecord($record);

        self::assertSame([$field], $entry->unreadable);
        self::assertFalse($entry->isIntact());

        $read = match ($field) {
            'result' => $entry->result,
            'action' => $entry->action,
            'objectType' => $entry->objectType,
            'objectId' => $entry->objectId,
            'occurredAt' => $entry->occurredAt,
            'details' => $entry->details === [] ? null : $entry->details,
            'issueId' => $entry->issueId,
            'user' => $entry->userLabel() === Craft::t('web-doctor', 'Could not be read') ? null : $entry->userLabel(),
            'site' => $entry->siteLabel() === Craft::t('web-doctor', 'Could not be read') ? null : $entry->siteLabel(),
            'environment', 'summary' => null,
            default => self::fail("No reading for $field."),
        };
        self::assertNull($read, 'An unreadable value was read as another.');
    }

    public function testAnEntryWithAMomentTheDatabaseHoldsButCannotBeReadIsListedAndSaysSo(): void
    {
        $id = $this->entry(AuditAction::ISSUE_RESOLVED, AuditResult::NONE, 'Resolved');
        AuditRecord::updateAll(['result' => 'archived'], ['id' => $id]);
        self::withoutStrictDates(static fn() => AuditRecord::updateAll(['occurredAt' => '0000-00-00 00:00:00'], ['id' => $id]));

        $entries = $this->entries();
        self::assertCount(1, $entries, 'A damaged entry was left out of the log.');
        self::assertSame(['result', 'occurredAt'], $entries[0]->unreadable);

        $html = $this->renderLog(['environment' => $this->environment]);
        self::assertStringContainsString(Craft::t('web-doctor', 'Could not be read'), $html);
        self::assertStringContainsString('result, occurredAt', $html);
        self::assertStringNotContainsString('1970', $html);
        self::assertStringNotContainsString('wd-pill--none', $html, 'An unknown result was shown as a known one.');
    }

    public function testAnEntryWhoseMomentCannotBePreparedIsNotWrittenAndNeitherIsTheChange(): void
    {
        $issue = $this->raise();
        $before = WebDoctorTables::snapshot();
        $this->issues->audit = new class() extends Audit {
            protected function moment(DateTimeImmutable $when): string
            {
                throw new RuntimeException('A moment could not be prepared for the database.');
            }
        };

        try {
            $this->issues->transition($issue->id, IssueStatus::CONFIRMED, null, 1);
            self::fail('A change was recorded with a moment nobody could prepare.');
        } catch (RuntimeException $e) {
            self::assertSame('A moment could not be prepared for the database.', $e->getMessage());
        }

        self::assertSame($before, WebDoctorTables::snapshot());
    }

    // Days, where the reader is ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, bool, string}>
     */
    public static function dayBoundaries(): array
    {
        return [
            'UTC, start' => ['UTC', '2026-01-15', false, '2026-01-15 00:00:00'],
            'UTC, end' => ['UTC', '2026-01-15', true, '2026-01-15 23:59:59'],
            'behind UTC' => ['America/New_York', '2026-01-15', false, '2026-01-15 05:00:00'],
            'ahead of UTC, by half an hour more' => ['Asia/Kolkata', '2026-01-15', false, '2026-01-14 18:30:00'],
            'the day clocks go forward, start' => ['America/New_York', '2026-03-08', false, '2026-03-08 05:00:00'],
            'the day clocks go forward, end' => ['America/New_York', '2026-03-08', true, '2026-03-09 03:59:59'],
            'the day clocks go back, start' => ['America/New_York', '2026-11-01', false, '2026-11-01 04:00:00'],
            'the day clocks go back, end' => ['America/New_York', '2026-11-01', true, '2026-11-02 04:59:59'],
            // Chile's clocks go forward at midnight: the day has no 00:00 and begins at 01:00.
            'a day with no midnight, start' => ['America/Santiago', '2026-09-06', false, '2026-09-06 04:00:00'],
            'a day with no midnight, end' => ['America/Santiago', '2026-09-06', true, '2026-09-07 02:59:59'],
            'the day before it, end' => ['America/Santiago', '2026-09-05', true, '2026-09-06 03:59:59'],
            'a day with midnight twice, end' => ['America/Santiago', '2026-04-04', true, '2026-04-05 03:59:59'],
        ];
    }

    #[DataProvider('dayBoundaries')]
    public function testADayIsTheDayWhereTheReaderIsAcrossEveryOffsetAndClockChange(string $zone, string $date, bool $end, string $utc): void
    {
        self::assertSame($utc, $end ? QueryParams::localDayEnd($date, $zone) : QueryParams::localDayStart($date, $zone));
    }

    public function testATimeZoneThatCannotBeResolvedIsAnErrorNeverUtcAndADateThatIsNotOneIsRefused(): void
    {
        try {
            QueryParams::localDayStart('2026-01-15', 'Mars/Olympus_Mons');
            self::fail('A time zone that is not one was read as some other.');
        } catch (InvalidConfigException) {
        }

        $refused = [];

        foreach (['2026-02-30', '2026-1-5', '15-01-2026', ''] as $date) {
            try {
                QueryParams::localDayEnd($date, 'UTC');
            } catch (\InvalidArgumentException) {
                $refused[] = $date;
            }
        }

        self::assertSame(['2026-02-30', '2026-1-5', '15-01-2026', ''], $refused);
    }

    public function testTheLogsDatesAreTheReadersDaysAcrossAClockChange(): void
    {
        $original = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('America/New_York');

        try {
            $at = static fn(string $utc): DateTimeImmutable => new DateTimeImmutable($utc, new DateTimeZone('UTC'));
            $ids = [
                'before' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'The day before', at: $at('2026-03-08 04:59:59')),
                'first' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Its first second', at: $at('2026-03-08 05:00:00')),
                'last' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Its last second', at: $at('2026-03-09 03:59:59')),
                'after' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'The day after', at: $at('2026-03-09 04:00:00')),
            ];
            $find = fn(array $params): array => array_map(static fn(AuditEntry $e): ?int => $e->id, $this->audit->find(AuditFilter::fromParams($params + ['environment' => $this->environment, 'order' => 'oldest']))->items);

            self::assertSame([$ids['first'], $ids['last']], $find(['from' => '2026-03-08', 'to' => '2026-03-08']));
            self::assertSame([$ids['first'], $ids['last'], $ids['after']], $find(['from' => '2026-03-08']));
            self::assertSame([$ids['before'], $ids['first'], $ids['last']], $find(['to' => '2026-03-08']));
            self::assertSame(array_values($ids), $find(['from' => '2026-03-07', 'to' => '2026-03-09']));
        } finally {
            Craft::$app->setTimeZone($original);
        }
    }

    // Dependencies -----------------------------------------------------------------------------

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function dependencies(): array
    {
        return [
            'the Issue Center’s audit trail' => [Issues::class, 'auditTrail'],
            'the Issue Center’s evidence store' => [Issues::class, 'evidenceStore'],
            'investigations’ audit trail' => [Investigations::class, 'auditTrail'],
            'investigations’ Issue Center' => [Investigations::class, 'issues'],
            'investigations’ engine' => [Investigations::class, 'engine'],
            'repairs’ audit trail' => [\Tahadudhiya\WebDoctor\services\Repairs::class, 'audit'],
            'verifications’ audit trail' => [\Tahadudhiya\WebDoctor\services\Verifications::class, 'audit'],
            'error grouping’s Issue Center' => [Errors::class, 'issues'],
            'recommendations’ investigations' => [Recommendations::class, 'investigations'],
            'the engine’s registry' => [DiagnosticEngine::class, 'registry'],
        ];
    }

    /**
     * A service is given the plugin's own dependency or the one injected, never a second one it makes
     * itself: with neither, it stops rather than going on with a copy nobody configured.
     *
     * @param class-string $class
     */
    #[DataProvider('dependencies')]
    public function testAServiceWithoutItsDependencyFailsClosedRatherThanMakingOne(string $class, string $method): void
    {
        $loaded = Craft::$app->loadedModules;
        unset(Craft::$app->loadedModules[WebDoctor::class]);
        self::assertNull(WebDoctor::getInstance());

        try {
            (new \ReflectionMethod($class, $method))->invoke(new $class());
            self::fail(sprintf('%s::%s() made a dependency of its own.', $class, $method));
        } catch (InvalidConfigException) {
            $this->addToAssertionCount(1);
        } finally {
            Craft::$app->loadedModules = $loaded;
        }
    }

    public function testNoServiceOrControllerConstructsADependencyAtRuntime(): void
    {
        $offenders = [];

        foreach (['services', 'controllers'] as $directory) {
            foreach (glob(dirname(__DIR__, 2) . "/src/$directory/*.php") ?: [] as $file) {
                if (str_contains($file, 'TestLab')) {
                    continue;
                }

                if (preg_match('/\?\?=?\s*new\s+(?!DateTime)[A-Z]/', (string)file_get_contents($file)) === 1) {
                    $offenders[] = basename($file);
                }
            }
        }

        self::assertSame([], $offenders, 'Falls back to a dependency of its own: ' . implode(', ', $offenders));
    }

    // Pages, order and bounds ---------------------------------------------------------------------

    public function testEveryHistoryBreaksATieInTheSameSecondByIdWhicheverWayRound(): void
    {
        // Stated rather than left to the index a query happens to use, which on MySQL already orders
        // ties by ID and so cannot show the term going missing.
        foreach ([[Audit::class, 'occurredAt'], [\Tahadudhiya\WebDoctor\services\Repairs::class, 'previewedAt'], [History::class, 'startedAt']] as [$class, $column]) {
            self::assertSame([$column => SORT_DESC, 'id' => SORT_DESC], $class::order(false), $class);
            self::assertSame([$column => SORT_ASC, 'id' => SORT_ASC], $class::order(true), $class);
        }
    }

    public function testEntriesInTheSameSecondNeverSwapPlacesBetweenPagesWhicheverWayRound(): void
    {
        $at = new DateTimeImmutable('-1 hour');
        $ids = [];

        // Two results, so the filtered order cannot simply follow one index.
        foreach (range(0, 8) as $i) {
            $ids[] = $this->entry(AuditAction::REPAIR_EXECUTED, $i % 2 === 0 ? AuditResult::SUCCEEDED : AuditResult::FAILED, "Entry $i", at: $at);
        }

        foreach ([false => array_reverse($ids), true => $ids] as $oldestFirst => $expected) {
            $seen = [];

            foreach ([1, 2, 3] as $page) {
                $found = $this->audit->find(new AuditFilter(results: [AuditResult::SUCCEEDED, AuditResult::FAILED], environment: $this->environment, oldestFirst: (bool)$oldestFirst, page: $page, perPage: 4));
                self::assertSame($page, $found->currentPage());
                $seen = [...$seen, ...array_map(static fn(AuditEntry $e): ?int => $e->id, $found->items)];
            }

            self::assertSame($expected, $seen);
        }

        $last = $this->audit->find(new AuditFilter(environment: $this->environment, page: 99, perPage: 4));
        self::assertSame(3, $last->currentPage());
        self::assertSame([$ids[0]], array_map(static fn(AuditEntry $e): ?int => $e->id, $last->items));
    }

    /**
     * @return array<string, array{string, array<string, mixed>, bool}>
     */
    public static function pagingRequests(): array
    {
        $out = [];

        foreach (['audit', 'repairs', 'history'] as $page) {
            $out["$page: the largest page"] = [$page, ['perPage' => '200'], true];
            $out["$page: a page too large"] = [$page, ['perPage' => '201'], false];
            $out["$page: a page size of nothing"] = [$page, ['perPage' => '0'], false];
            $out["$page: page zero"] = [$page, ['page' => '0'], false];
            $out["$page: a negative page"] = [$page, ['page' => '-1'], false];
            $out["$page: a page that is a word"] = [$page, ['page' => 'last'], false];
            $out["$page: a range that ends before it starts"] = [$page, ['from' => '2026-05-02', 'to' => '2026-05-01'], false];
            $out["$page: a date that is not one"] = [$page, ['to' => '2026-02-30'], false];
            $out["$page: a site that is not one"] = [$page, ['siteId' => 'main'], false];
        }

        return $out;
    }

    /**
     * @param 'audit'|'repairs'|'history' $page
     * @param array<string, mixed> $query
     */
    #[DataProvider('pagingRequests')]
    public function testEveryHistoryTakesItsPageAndDatesExactlyOrRefusesThem(string $page, array $query, bool $accepted): void
    {
        $before = WebDoctorTables::snapshot();

        try {
            $this->render($page, 'index', [
                'audit' => 'web-doctor/_audit/_log',
                'repairs' => 'web-doctor/_repairs/_history',
                'history' => 'web-doctor/_history/_runs',
            ][$page], [], $query);
            self::assertTrue($accepted, 'A value that is not one was read as some other value.');
        } catch (BadRequestHttpException) {
            self::assertFalse($accepted, 'A value that is one was refused.');
        }

        self::assertSame($before, WebDoctorTables::snapshot());
    }

    // Isolation -------------------------------------------------------------------------------------

    public function testEachHistoryKeepsEnvironmentsAndSitesApartAndNoParticularSiteIsNotADeletedOne(): void
    {
        $site = $this->siteId();
        $other = 'tests-' . bin2hex(random_bytes(5));
        $ids = [
            'here, on the site' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'One', siteId: $site),
            'here, no site' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Two'),
            'here, a deleted site' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Three', siteId: $site),
            'elsewhere, on the site' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Four', siteId: $site, environment: $other),
        ];
        // What deleting the site leaves: no link, and the name kept.
        AuditRecord::updateAll(['siteId' => null], ['id' => $ids['here, a deleted site']]);

        $find = fn(array $params): array => array_map(static fn(AuditEntry $e): ?int => $e->id, $this->audit->find(AuditFilter::fromParams($params + ['order' => 'oldest']))->items);

        self::assertSame([$ids['here, on the site'], $ids['here, no site'], $ids['here, a deleted site']], $find(['environment' => $this->environment]));
        self::assertSame([$ids['elsewhere, on the site']], $find(['environment' => $other]));
        self::assertSame([$ids['here, on the site']], $find(['environment' => $this->environment, 'siteId' => (string)$site]));
        self::assertSame([$ids['here, no site']], $find(['environment' => $this->environment, 'siteId' => 'none']));

        // The repair history, likewise.
        $issue = $this->raise();
        $repairs = [
            'here' => $this->repair($issue, RepairStatus::SUCCEEDED, 'storage.createDirectories', new DateTimeImmutable(), siteId: $site),
            'here, no site' => $this->repair($issue, RepairStatus::SUCCEEDED, 'storage.createDirectories', new DateTimeImmutable()),
            'here, a deleted site' => $this->repair($issue, RepairStatus::SUCCEEDED, 'storage.createDirectories', new DateTimeImmutable(), siteId: $site),
            'elsewhere' => $this->repair($issue, RepairStatus::SUCCEEDED, 'storage.createDirectories', new DateTimeImmutable(), environment: $other, siteId: $site),
        ];
        RepairRecord::updateAll(['siteId' => null], ['id' => $repairs['here, a deleted site']]);
        $findRepairs = fn(array $params): array => array_map(static fn($r): int => $r->id, $this->plugin->getRepairs()->find(RepairFilter::fromParams($params + ['order' => 'oldest']))->items);

        self::assertSame([$repairs['here'], $repairs['here, no site'], $repairs['here, a deleted site']], $findRepairs(['environment' => $this->environment]));
        self::assertSame([$repairs['elsewhere']], $findRepairs(['environment' => $other]));
        self::assertSame([$repairs['here']], $findRepairs(['environment' => $this->environment, 'siteId' => (string)$site]));
        self::assertSame([$repairs['here, no site']], $findRepairs(['environment' => $this->environment, 'siteId' => 'none']));

        AuditRecord::deleteAll(['environment' => $other]);
        RepairRecord::deleteAll(['environment' => $other]);
    }

    /**
     * Each history offers its site filter on the page, not only by URL, and shows the one chosen —
     * including "no particular site", which is not a deleted site's records.
     */
    public function testEachHistoryOffersItsSiteFilterAndShowsTheOneChosen(): void
    {
        $site = $this->siteId();
        $pages = [
            'audit' => ['web-doctor/_audit/_log', 'wd-audit-site'],
            'repairs' => ['web-doctor/_repairs/_history', 'wd-repairs-site'],
            'history' => ['web-doctor/_history/_runs', 'wd-history-site'],
        ];

        foreach ($pages as $page => [$template, $id]) {
            $html = $this->render($page, 'index', $template, [], ['siteId' => (string)$site]);

            self::assertStringContainsString('<select id="' . $id . '" name="siteId">', $html, $page);
            self::assertMatchesRegularExpression('/<option value="' . $site . '" selected>/', $html, $page);

            $html = $this->render($page, 'index', $template, [], ['siteId' => 'none']);

            self::assertStringContainsString('<option value="none" selected>', $html, $page);
        }
    }

    /**
     * Who acted and where, as a record says them: the names kept when it was made, never blank, and
     * a deleted account or site never read as nobody or as the installation.
     */
    public function testARecordNamesWhoActedAndWhereByWhatItKept(): void
    {
        self::assertSame('All sites', \Tahadudhiya\WebDoctor\helpers\SiteName::recorded(null, null));
        self::assertSame('Main (deleted)', \Tahadudhiya\WebDoctor\helpers\SiteName::recorded(null, 'Main'));
        self::assertSame('Main', \Tahadudhiya\WebDoctor\helpers\SiteName::recorded(3, 'Main'));
        self::assertSame('Site #3', \Tahadudhiya\WebDoctor\helpers\SiteName::recorded(3, null));
        self::assertSame('Could not be read', \Tahadudhiya\WebDoctor\helpers\SiteName::recorded(3, 'Main', true));

        self::assertSame('Nobody signed in (console or queue)', \Tahadudhiya\WebDoctor\helpers\Actor::label(null, null));
        self::assertSame('sam (deleted)', \Tahadudhiya\WebDoctor\helpers\Actor::label(null, 'sam'));
        self::assertSame('sam', \Tahadudhiya\WebDoctor\helpers\Actor::label(7, 'sam'));
        self::assertSame('Could not be read', \Tahadudhiya\WebDoctor\helpers\Actor::label(7, 'sam', true));
    }

    /**
     * Every list offers the environments its own records were made in, each once and in order, and
     * never an empty one — which the filter reads back as "any", so offering it would offer "any"
     * twice. One rule for all five lists, so their dropdowns cannot disagree.
     */
    public function testEveryListOffersTheEnvironmentsItsRecordsWereMadeInAndNeverAnEmptyOne(): void
    {
        $later = 'tests-zz-' . bin2hex(random_bytes(3));
        $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'One', environment: $later);
        $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Two', environment: $later);
        $empty = $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Three');
        AuditRecord::updateAll(['environment' => ''], ['id' => $empty]);

        try {
            $choices = $this->audit->knownEnvironments();

            self::assertNotContains('', $choices);
            self::assertSame(1, count(array_keys($choices, $later, true)));
            $sorted = $choices;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $choices);

            foreach ([
                [$this->plugin->getIssues(), \Tahadudhiya\WebDoctor\records\IssueRecord::tableName()],
                [$this->plugin->getErrors(), \Tahadudhiya\WebDoctor\records\ErrorGroupRecord::tableName()],
                [$this->audit, AuditRecord::tableName()],
                [$this->plugin->getRepairs(), RepairRecord::tableName()],
                [$this->plugin->getHistory(), \Tahadudhiya\WebDoctor\records\DiagnosticRunRecord::tableName()],
            ] as [$service, $table]) {
                self::assertSame(QueryParams::choicesIn($table, 'environment'), $service->knownEnvironments(), $service::class);
            }
        } finally {
            AuditRecord::deleteAll(['environment' => [$later, '']]);
        }
    }

    /**
     * Who a list can be filtered by is each person by the name on their most recent entry — the same
     * answer every time, and a renamed account read as it is now — and a kept name is never replaced
     * by an entry that kept none.
     */
    public function testEachPersonIsOfferedByTheNameOnTheirMostRecentEntry(): void
    {
        $first = $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'One');
        $second = $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Two');
        $third = $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Three');
        $user = (int)AuditRecord::findOne($first)?->userId;
        AuditRecord::updateAll(['userName' => 'zz-before-renaming'], ['id' => $first]);
        AuditRecord::updateAll(['userName' => 'zz-after-renaming'], ['id' => $second]);
        AuditRecord::updateAll(['userName' => null], ['id' => $third]);

        try {
            self::assertSame('zz-after-renaming', $this->audit->knownUsers()[$user] ?? null);
        } finally {
            AuditRecord::deleteAll(['id' => [$first, $second, $third]]);
        }
    }

    // Retention, at its boundary --------------------------------------------------------------------

    public function testRetentionRemovesOnlyWhatIsOlderThanItsPeriodToTheSecondABoundedBatchAtATime(): void
    {
        $now = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
        $boundary = $now->modify('-30 days');
        $ids = [
            'older' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Older', at: $boundary->modify('-3 seconds')),
            'oldest' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Oldest', at: $boundary->modify('-1 day')),
            'just older' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Just older', at: $boundary->modify('-1 second')),
            'exactly' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Exactly the period', at: $boundary),
            'newer' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Newer', at: $boundary->modify('+1 second')),
            'future' => $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Later than now', at: $now->modify('+1 day')),
        ];
        // Every other row Web Doctor keeps, and every audit entry outside this test's, is left alone.
        $others = array_diff_key(WebDoctorTables::snapshot(), [AuditRecord::TABLE => true]);
        $outside = AuditRecord::find()->where(['not', ['environment' => $this->environment]])->andWhere(['>=', 'occurredAt', Db::prepareDateForDb($boundary)])->count();
        $audit = new Audit(['retainDays' => 30, 'pruneBatch' => 2]);

        self::assertSame(2, $audit->prune($now), 'A round removed more than its batch.');
        $kept = AuditRecord::find()->select(['id'])->where(['environment' => $this->environment])->column();
        // Oldest first.
        self::assertNotContains((string)$ids['oldest'], array_map('strval', $kept));
        self::assertNotContains((string)$ids['older'], array_map('strval', $kept));

        $audit->prune($now);
        $kept = array_map('intval', AuditRecord::find()->select(['id'])->where(['environment' => $this->environment])->orderBy(['id' => SORT_ASC])->column());

        self::assertSame([$ids['exactly'], $ids['newer'], $ids['future']], $kept);
        self::assertSame($others, array_diff_key(WebDoctorTables::snapshot(), [AuditRecord::TABLE => true]));
        self::assertSame($outside, AuditRecord::find()->where(['not', ['environment' => $this->environment]])->andWhere(['>=', 'occurredAt', Db::prepareDateForDb($boundary)])->count());
    }

    public function testRetentionThatCannotRunCostsTheEntryNothingAndOneThatCannotBeReadIsRefusedBeforeAnything(): void
    {
        // A period that is not one is refused before the entry is written, not after.
        $before = WebDoctorTables::snapshot();

        foreach ([0, -1] as $days) {
            try {
                (new Audit(['retainDays' => $days]))->record(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Started', AuditObjectType::RUN, 'run', environment: $this->environment);
                self::fail("A retention of $days days was read as some other number.");
            } catch (InvalidConfigException) {
            }
        }

        self::assertSame($before, WebDoctorTables::snapshot());

        $failing = new class() extends Audit {
            public function prune(?DateTimeImmutable $now = null): int
            {
                throw new RuntimeException('The delete failed.');
            }
        };

        $entry = $failing->record(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Started', AuditObjectType::RUN, 'run', environment: $this->environment);
        self::assertNotNull(AuditRecord::findOne($entry->id), 'An entry was lost to retention failing.');

        $old = $this->entry(AuditAction::DIAGNOSTICS_STARTED, AuditResult::NONE, 'Long ago', at: new DateTimeImmutable('-3 years'));

        foreach ([['retainDays' => 0], ['pruneBatch' => 0]] as $config) {
            try {
                (new Audit($config))->prune();
                self::fail('Retention ran on a configuration that is not one.');
            } catch (InvalidConfigException) {
            }
        }

        self::assertNotNull(AuditRecord::findOne($old));

        // Given no moment, a period it can read is measured back from now. Its own instance, since
        // retention runs at most once an instance.
        self::assertGreaterThanOrEqual(1, (new Audit(['retainDays' => 365]))->prune());
        self::assertNull(AuditRecord::findOne($old));
    }

    // Who may read -------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function readersOfEveryHistory(): array
    {
        $view = [Permissions::VIEW];
        $issues = [Permissions::VIEW, Permissions::VIEW_ISSUES];

        return [
            'an administrator' => ['admin', [], ['audit', 'repairs', 'repair', 'history', 'run']],
            'issues and the audit trail' => ['user', [...$issues, Permissions::VIEW_AUDIT_TRAIL], ['audit', 'repairs', 'repair', 'history', 'run']],
            'issues only' => ['user', $issues, ['repairs', 'repair', 'history', 'run']],
            'the overview only' => ['user', $view, ['history', 'run']],
            'the audit trail without issues' => ['user', [...$view, Permissions::VIEW_AUDIT_TRAIL], ['history', 'run']],
            'repairs without the audit trail' => ['user', [...$issues, Permissions::RUN_REPAIRS], ['repairs', 'repair', 'history', 'run']],
            'nothing of Web Doctor' => ['user', [], []],
            // Craft nests permissions only in its own screens; one written another way is not a
            // way past the section's own.
            'issues and the audit trail without Web Doctor itself' => ['user', [Permissions::VIEW_ISSUES, Permissions::VIEW_AUDIT_TRAIL, Permissions::RUN_REPAIRS], []],
        ];
    }

    /**
     * Each page is refused on its own route, not merely left out of the navigation, and is in the
     * navigation exactly for whoever may read it.
     *
     * @param list<string> $permissions
     * @param list<string> $allowed
     */
    #[DataProvider('readersOfEveryHistory')]
    public function testEveryHistoryRouteRefusesADirectRequestFromSomebodyWhoMayNotReadIt(string $who, array $permissions, array $allowed): void
    {
        $issue = $this->raise();
        $repair = $this->repair($issue, RepairStatus::SUCCEEDED, 'storage.createDirectories', new DateTimeImmutable(), executedBy: 1);
        $run = $this->keptRun();
        $this->signIn($who === 'admin', [self::ACCESS_CP, ...$permissions]);

        foreach ([
            'audit' => ['audit', 'index', []],
            'repairs' => ['repairs', 'index', []],
            'repair' => ['repairs', 'detail', ['repairId' => $repair]],
            'history' => ['history', 'index', []],
            'run' => ['history', 'detail', ['runId' => $run]],
        ] as $route => [$controller, $action, $params]) {
            $this->request('GET');

            try {
                $this->controllerFor($controller)->runAction($action, $params);
                self::assertContains($route, $allowed, "$route was shown to somebody who may not read it.");
            } catch (ForbiddenHttpException) {
                self::assertNotContains($route, $allowed, "$route was refused to somebody who may read it.");
            }
        }

        // Offered in the navigation exactly where it may be read.
        $subnav = $this->plugin->getCpNavItem()['subnav'] ?? [];
        foreach (['audit', 'repairs', 'history'] as $entry) {
            self::assertSame(in_array($entry, $allowed, true), array_key_exists($entry, $subnav), "The $entry navigation entry does not follow who may read it.");
        }
    }

    /**
     * A history that cannot be read says so, in words, without the exception — never an empty list
     * that would read as nothing having happened.
     */
    public function testAHistoryThatCannotBeReadSaysSoAndShowsNoInternals(): void
    {
        $this->plugin->set('audit', new class() extends Audit {
            public function find(AuditFilter $filter): \Tahadudhiya\WebDoctor\models\ListPage
            {
                throw new RuntimeException('SQLSTATE[42S02]: table webdoctor_audit_log password=hunter2-internal');
            }
        });
        $this->plugin->set('repairs', new class(['issues' => $this->issues]) extends \Tahadudhiya\WebDoctor\services\Repairs {
            public function find(RepairFilter $filter): \Tahadudhiya\WebDoctor\models\ListPage
            {
                throw new RuntimeException('SQLSTATE[42S02]: table webdoctor_repairs password=hunter2-internal');
            }
        });
        $this->plugin->set('history', new class() extends History {
            public function find(RunFilter $filter): \Tahadudhiya\WebDoctor\models\ListPage
            {
                throw new RuntimeException('SQLSTATE[42S02]: table webdoctor_diagnostic_runs password=hunter2-internal');
            }
        });

        foreach ([
            ['audit', 'web-doctor/_audit/_log', 'Web Doctor could not read the audit log.'],
            ['repairs', 'web-doctor/_repairs/_history', 'Web Doctor could not read the repair history.'],
            ['history', 'web-doctor/_history/_runs', 'Web Doctor could not read the diagnostic history.'],
        ] as [$controller, $template, $said]) {
            $html = $this->render($controller, 'index', $template);

            self::assertStringContainsString(Craft::t('web-doctor', $said . ' The details are in Craft’s logs.'), $html);
            self::assertStringNotContainsString('SQLSTATE', $html);
            self::assertStringNotContainsString('hunter2', $html);
            self::assertStringNotContainsString('No ', strip_tags(preg_replace('/<option.*?<\/option>/s', '', $html) ?? ''), 'A history that could not be read was shown as an empty one.');
        }
    }

    public function testAMalformedIdOnARecordsRouteIsRefused(): void
    {
        foreach ([['repairs', 'detail', ['repairId' => '12abc']], ['history', 'detail', ['runId' => 'latest']]] as [$controller, $action, $params]) {
            $this->request('GET');

            try {
                $this->controllerFor($controller)->runAction($action, $params);
                self::fail("$controller/$action read a malformed ID as an ID.");
            } catch (BadRequestHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // Credentials -------------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function credentials(): array
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9' . '.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXZhbHVl';

        return [
            'password' => ['password', 'p'],
            'passwd' => ['passwd', 'p'],
            'secret' => ['secret', 's'],
            'token' => ['token', 't'],
            'accessToken' => ['accessToken', 'a'],
            'apiKey' => ['apiKey', 'k'],
            'authorization' => ['authorization', 'Bearer '],
            'bearer' => ['bearer', 'b'],
            'cookie' => ['cookie', 'c'],
            'privateKey' => ['privateKey', 'k'],
            'clientSecret' => ['clientSecret', 'c'],
            'a database password' => ['DB_PASSWORD', 'd'],
            'an SMTP password' => ['smtpPassword', 's'],
            'a Stripe secret key' => ['stripeSecretKey', 'sk_' . 'live_'],
            'a Worldpay credential' => ['worldpayCredential', 'w'],
            'an OAuth token' => ['oauthToken', 'o'],
            'a JWT' => ['jwt', $jwt],
        ];
    }

    /**
     * A credential never reaches the trail as a detail's name or its value, alone, inside a longer
     * string, nested, too long, or in text that is not valid UTF-8 — at the database or on the page.
     */
    #[DataProvider('credentials')]
    public function testACredentialReachesNeitherTheTableNorThePageHoweverItArrives(string $key, string $prefix): void
    {
        $secret = $prefix . 'hunter2' . bin2hex(random_bytes(6));
        $embedded = "Login failed: $key=$secret while connecting";

        $this->audit->record(
            AuditAction::REPAIR_EXECUTED,
            AuditResult::FAILED,
            "Refused: $key=$secret",
            AuditObjectType::REPAIR,
            '42',
            "token=$secret",
            environment: $this->environment,
            details: [
                $key => $secret,
                'message' => $embedded,
                'list' => [$embedded, "$key: $secret"],
                'nested' => [$key => $secret],
                'long' => str_repeat('x', 2000) . " $key=$secret",
                'malformed' => "\xC3\x28 $key=$secret \xFF",
                'dsn' => "mysql://root:$secret@db:3306/craft",
                'smtp' => "smtp://mailer:$secret@mail.example.com",
            ],
        );

        $rows = (new \craft\db\Query())->from(AuditRecord::TABLE)->where(['environment' => $this->environment])->all();
        self::assertStringNotContainsString('hunter2', (string)json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE));
        self::assertStringNotContainsString($secret, $this->renderLog(['environment' => $this->environment]));
    }


    // Diagnostic and issue history ------------------------------------------------------------------

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function storedRunValues(): array
    {
        return [
            'an unknown depth' => ['depth', 'bottomless', 'depth'],
            'an unknown execution mode' => ['mode', 'telepathy', 'mode'],
            'status counts that are not JSON' => ['statusCounts', '{', 'statusCounts'],
            'status counts of an unknown status' => ['statusCounts', '{"triumphant":1}', 'statusCounts'],
            'results that are not a list' => ['results', '{"a":1}', 'results'],
            'a result of an unknown status' => ['results', '[{"diagnosticId":"tests.audit","name":"A","category":"configuration","status":"triumphant","severity":"low","summary":""}]', 'results'],
            'a score without its weights' => ['weights', null, 'snapshot'],
            'weights that are not counts' => ['weights', '{"critical":"thirty"}', 'snapshot'],
            'a broken moment' => ['startedAt', '2026-02-30 10:00:00', 'startedAt'],
            'a user named by ID but not by name' => ['userName', null, 'user'],
        ];
    }

    /**
     * A kept run is history: what it holds that cannot be read is said to be unreadable — a score
     * without the arithmetic behind it is no score — never read as some other value.
     */
    #[DataProvider('storedRunValues')]
    public function testAKeptRunThatCannotBeReadInFullSaysWhichPartAndKeepsNoGuessedScore(string $column, mixed $value, string $field): void
    {
        $id = $this->keptRun(complete: true);
        $record = DiagnosticRunRecord::findOne($id);
        self::assertNotNull($record);
        self::assertNotNull($record->score, 'The run was not kept with a snapshot to spoil.');
        $record->setAttribute($column, $value);

        $run = HistoricalRun::fromRecord($record);

        self::assertSame([$field], $run->unreadable);

        if ($field === 'snapshot') {
            self::assertNull($run->score, 'A score was kept without the arithmetic behind it.');
        }
    }

    public function testTheDiagnosticHistoryKeepsEnvironmentsAndSitesApartAndIsBoundedByAge(): void
    {
        $here = $this->keptRun();
        $onSite = $this->keptRun(siteId: $this->siteId());
        $other = 'tests-' . bin2hex(random_bytes(5));
        $elsewhere = $this->keptRun(environment: $other);
        $history = new History();
        $find = static fn(array $params): array => array_map(static fn(HistoricalRun $r): int => $r->id, $history->find(RunFilter::fromParams($params + ['order' => 'oldest']))->items);

        self::assertSame([$here, $onSite], $find(['environment' => $this->environment]));
        self::assertSame([$onSite], $find(['environment' => $this->environment, 'siteId' => (string)$this->siteId()]));
        self::assertSame([$here], $find(['environment' => $this->environment, 'siteId' => 'none']));
        self::assertSame([$elsewhere], $find(['environment' => $other]));

        // Retention, at its boundary: a run started exactly the period ago is kept, one a second older is not.
        $now = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
        DiagnosticRunRecord::updateAll(['startedAt' => Db::prepareDateForDb($now->modify('-30 days'))], ['id' => $here]);
        DiagnosticRunRecord::updateAll(['startedAt' => Db::prepareDateForDb($now->modify('-30 days -1 second'))], ['id' => $onSite]);
        (new History(['retainDays' => 30, 'pruneBatch' => 10]))->prune($now);

        self::assertNotNull(DiagnosticRunRecord::findOne($here));
        self::assertNull(DiagnosticRunRecord::findOne($onSite));
        self::assertNotNull(DiagnosticRunRecord::findOne($elsewhere));

        DiagnosticRunRecord::deleteAll(['environment' => $other]);
    }

    public function testAnIssuesHistorySaysWhatItCannotReadRatherThanShowingSomethingElse(): void
    {
        $issue = $this->raise();
        $this->issues->transition($issue->id, IssueStatus::CONFIRMED, null, 1);
        $event = IssueEventRecord::find()->where(['issueId' => $issue->id])->orderBy(['id' => SORT_DESC])->one();
        self::assertInstanceOf(IssueEventRecord::class, $event);
        IssueEventRecord::updateAll(['type' => 'escalated', 'toStatus' => 'archived'], ['id' => $event->id]);
        self::withoutStrictDates(static fn() => IssueEventRecord::updateAll(['dateCreated' => '0000-00-00 00:00:00'], ['id' => $event->id]));

        $read = array_values(array_filter($this->issues->events($issue->id), static fn($e): bool => $e->id === (int)$event->id))[0];

        self::assertNull($read->type, 'An unknown event was read as another.');
        self::assertNull($read->toStatus);
        self::assertNull($read->occurredAt, 'An unreadable moment was read as now.');
        self::assertSame(['type', 'toStatus', 'occurredAt'], $read->unreadable);
    }

    public function testAnIssueThatComesBackIsRecordedAsAStatusChangeInTheSameActAsComingBack(): void
    {
        $issue = $this->raise();
        $this->runCheck(DiagnosticStatus::PASS);
        $this->runCheck(DiagnosticStatus::FAIL);

        $actions = array_map(static fn(AuditEntry $e): array => [$e->action, $e->details['from'] ?? null, $e->details['to'] ?? null], $this->entries(new AuditFilter(issueId: $issue->id, oldestFirst: true)));
        self::assertSame([[AuditAction::ISSUE_RESOLVED, 'new', null], [AuditAction::ISSUE_STATUS_CHANGED, 'resolved', 'new']], $actions);

        // And not without it.
        $this->runCheck(DiagnosticStatus::PASS);
        $this->issues->audit = $this->failingAudit();

        try {
            $this->runCheck(DiagnosticStatus::FAIL);
            self::fail('The issue came back without its entry.');
        } catch (RuntimeException) {
        }

        self::assertSame(IssueStatus::RESOLVED, $this->issues->get($issue->id)?->status);
    }

    // Helpers -----------------------------------------------------------------------------

    /**
     * An issue raised the way a run raises one.
     */
    private function raise(string $title = 'tests.audit reports a problem.', ?string $component = null): Issue
    {
        $context = new DiagnosticContext(environment: $this->environment);
        $now = new DateTimeImmutable();
        $result = (new DiagnosticResult(
            diagnosticId: 'tests.audit',
            name: 'Audit check',
            category: DiagnosticCategory::CONFIGURATION,
            status: DiagnosticStatus::FAIL,
            summary: $title,
            affectedComponent: $component,
        ))->withExecution($context, $now, $now, 1.0);

        // Finding a problem is not an act anybody did, so raising one records nothing in the trail.
        $this->issues->reconcile(new DiagnosticRun(context: $context, results: [$result], startedAt: $now, finishedAt: $now, durationMs: 1.0));

        $issue = $this->issues->getByFingerprint(Fingerprint::forResult($result, $this->environment, null));
        self::assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    /**
     * The audit check run again, reporting what the test says.
     */
    private function runCheck(DiagnosticStatus $status): void
    {
        $context = new DiagnosticContext(environment: $this->environment);
        $now = new DateTimeImmutable();
        $result = (new DiagnosticResult(
            diagnosticId: 'tests.audit',
            name: 'Audit check',
            category: DiagnosticCategory::CONFIGURATION,
            status: $status,
            summary: 'tests.audit has run.',
        ))->withExecution($context, $now, $now, 1.0);

        $this->issues->reconcile(new DiagnosticRun(context: $context, results: [$result], startedAt: $now, finishedAt: $now, durationMs: 1.0));
    }

    /**
     * Investigations over a registry holding only the audit check, which passes.
     */
    private function investigations(Audit $audit): Investigations
    {
        $registry = new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };
        $registry->register(new TestDiagnostic(['diagnosticId' => 'tests.audit', 'diagnosticName' => 'Audit check']));
        $issues = new Issues(['evidence' => new EvidenceStore(), 'audit' => $audit]);

        return new Investigations([
            'registry' => $registry,
            'engine' => new DiagnosticEngine(['registry' => $registry]),
            'issues' => $issues,
            'errors' => new Errors(['issues' => $issues]),
            'rootCauses' => new RootCauses(),
            'environment' => $this->environment,
            'audit' => $audit,
        ]);
    }

    private function failingAudit(): Audit
    {
        return new class() extends Audit {
            protected function save(AuditRecord $record): void
            {
                throw new RuntimeException('A Web Doctor audit entry could not be saved: refused by the test.');
            }
        };
    }

    /**
     * Records an entry through the service, then moves it to the moment the test states.
     */
    private function entry(
        AuditAction $action,
        AuditResult $result,
        string $summary,
        ?int $issueId = null,
        bool $signedIn = true,
        ?DateTimeImmutable $at = null,
        AuditObjectType $objectType = AuditObjectType::ISSUE,
        ?string $objectId = null,
        ?string $label = null,
        ?int $siteId = null,
        array $details = [],
        ?string $environment = null,
    ): int {
        $identity = Craft::$app->getUser()->getIdentity();

        if (!$signedIn) {
            Craft::$app->getUser()->setIdentity(null);
        }

        try {
            $entry = $this->audit->record($action, $result, $summary, $objectType, $objectId ?? (string)($issueId ?? 'run-' . bin2hex(random_bytes(3))), $label, $issueId, $environment ?? $this->environment, $siteId, $details);
        } finally {
            Craft::$app->getUser()->setIdentity($identity);
        }

        self::assertNotNull($entry->id);

        if ($at !== null) {
            AuditRecord::updateAll(['occurredAt' => Db::prepareDateForDb($at)], ['id' => $entry->id]);
        }

        return $entry->id;
    }

    /**
     * @return list<AuditEntry>
     */
    private function entries(?AuditFilter $filter = null): array
    {
        return $this->audit->find($filter ?? new AuditFilter(environment: $this->environment))->items;
    }

    private function repair(Issue $issue, RepairStatus $status, string $action, DateTimeImmutable $at, VerificationStatus $verification = VerificationStatus::NONE, ?int $executedBy = null, ?int $siteId = null, ?string $environment = null): int
    {
        $record = new RepairRecord();
        $record->issueId = $issue->id;
        $record->issueTitle = $issue->title;
        $record->diagnosticId = $issue->diagnosticId;
        $record->action = $action;
        $record->actionName = 'Repair ' . $action;
        $record->risk = RepairRisk::LOW->value;
        $record->status = $status->value;
        $record->verificationStatus = $verification->value;
        $record->environment = $environment ?? $this->environment;
        $record->siteId = $siteId;
        $record->siteName = $siteId === null ? null : Craft::$app->getSites()->getSiteById($siteId)?->getName();
        $record->fingerprint = str_repeat('0', 64);
        $record->definitionFingerprint = str_repeat('0', 64);
        $record->previewedBy = 1;
        $record->previewedByName = TestUser::USERNAME;
        $record->executedBy = $executedBy;
        $record->executedByName = $executedBy === null ? null : TestUser::USERNAME;
        $record->previewedAt = Db::prepareDateForDb($at);
        $record->startedAt = $status->wasExecuted() ? Db::prepareDateForDb($at) : null;
        $record->finishedAt = $status->wasExecuted() ? Db::prepareDateForDb($at) : null;

        self::assertTrue($record->save(), (string)json_encode($record->getErrors()));

        return (int)$record->id;
    }

    /**
     * Runs a callback as somebody — signed in, nobody, or an identity Craft cannot give — and puts
     * Craft's own user component and identity back afterwards.
     */
    private function asIdentity(string $who, \Closure $callback): void
    {
        $original = Craft::$app->getUser();
        $identity = $original->getIdentity();

        try {
            if ($who === 'throws') {
                Craft::$app->set('user', new class() extends \craft\console\User {
                    public function getIdentity(bool $autoRenew = true): ?\craft\elements\User
                    {
                        throw new RuntimeException('The session store is unreachable.');
                    }
                });
            } else {
                $user = new TestUser();
                $user->id = match ($who) {
                    'noId' => null,
                    'zeroId' => 0,
                    default => 1,
                };
                $user->admin = true;

                if ($who === 'noName') {
                    $user->username = null;
                }

                Craft::$app->getUser()->setIdentity($who === 'nobody' ? null : $user);
            }

            $callback();
        } finally {
            Craft::$app->set('user', $original);
            $original->setIdentity($identity);
        }
    }

    /** The primary site's ID: a site that exists, for a record to be filed under. */
    private function siteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * A diagnostic run of this test's environment kept in the history, as the overview keeps one.
     */
    private function keptRun(bool $complete = false, ?int $siteId = null, ?string $environment = null): int
    {
        $context = new DiagnosticContext(siteId: $siteId, environment: $environment ?? $this->environment);
        $now = new DateTimeImmutable();
        $check = new TestDiagnostic(['diagnosticId' => 'tests.audit']);
        $result = $check->run($context)->withExecution($context, $now, $now, 1.0);

        // Registered as the only check, the run covered every check and is kept with a score.
        return (new History())->record(new DiagnosticRun(context: $context, results: [$result], startedAt: $now, finishedAt: $now, durationMs: 1.0), $complete ? [$check] : [])->id;
    }

    private function controllerFor(string $controller): Controller
    {
        return match ($controller) {
            'audit' => $this->auditController(),
            'repairs' => $this->repairsController(),
            'history' => new HistoryController('history', $this->plugin),
            default => throw new \InvalidArgumentException($controller),
        };
    }

    /**
     * Runs a write with this connection's SQL mode relaxed, so a moment strict mode would refuse can be
     * stored the way a database without strict mode would hold it, then puts the mode back.
     */
    public static function withoutStrictDates(\Closure $write): void
    {
        $db = Craft::$app->getDb();
        $mode = (string)$db->createCommand('SELECT @@SESSION.sql_mode')->queryScalar();
        $db->createCommand("SET SESSION sql_mode = ''")->execute();

        try {
            $write();
        } finally {
            $db->createCommand('SET SESSION sql_mode = :mode', [':mode' => $mode])->execute();
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function signIn(bool $admin, array $permissions = []): void
    {
        $user = new TestUser();
        $user->id = 1;
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;

        Craft::$app->getUser()->setIdentity($user);
    }

    /**
     * A request shaped the way Craft would see one; see IssueCenterTest for why the control panel
     * flag is stated outright.
     */
    private function request(string $method, bool $cp = true): WebRequest
    {
        $base = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        $parts = parse_url($base) ?: [];
        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $trigger = Craft::$app->getConfig()->getGeneral()->cpTrigger ?? 'admin';

        $_SERVER['HTTP_HOST'] = $parts['host'] ?? 'localhost';
        $_SERVER['SERVER_NAME'] = $parts['host'] ?? 'localhost';
        $_SERVER['SERVER_PORT'] = $secure ? '443' : '80';
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor/audit" : '/actions/web-doctor/audit/index';
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        if ($secure) {
            $_SERVER['HTTPS'] = 'on';
        } else {
            unset($_SERVER['HTTPS']);
        }

        $this->originalRequest ??= Craft::$app->getRequest();
        $this->originalResponse ??= Craft::$app->getResponse();

        if (!Craft::$app->has('formattingLocale', true)) {
            Craft::$app->set('formattingLocale', Craft::$app->getI18n()->getLocaleById(Craft::$app->language));
        }

        $request = new WebRequest();
        $request->setIsCpRequest($cp);
        $request->cookieValidationKey = Craft::$app->getConfig()->getGeneral()->securityKey;

        Craft::$app->set('request', $request);
        Craft::$app->set('response', new WebResponse());

        return $request;
    }

    private function auditController(): AuditController
    {
        return new AuditController('audit', $this->plugin);
    }

    private function repairsController(): RepairsController
    {
        return new RepairsController('repairs', $this->plugin);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function renderLog(array $query): string
    {
        return $this->render('audit', 'index', 'web-doctor/_audit/_log', [], $query);
    }

    /**
     * Renders what a controller actually produced, through the real template, leaving out only
     * Craft's own control panel shell, which asks for a session a console run has none of.
     *
     * The controller is built after the request, since it reads the request as it is created.
     *
     * @param 'audit'|'repairs'|'history' $controller
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    private function render(string $controller, string $action, string $template, array $params = [], array $query = []): string
    {
        $this->request('GET')->setQueryParams($query);
        $response = $this->controllerFor($controller)->runAction($action, $params);

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return Craft::$app->getView()->renderTemplate($template, $behavior->variables, View::TEMPLATE_MODE_CP);
    }
}
