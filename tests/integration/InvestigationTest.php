<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Closure;
use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\InvestigationStatus;
use Tahadudhiya\WebDoctor\enums\InvestigationStepType;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\investigations\InvestigationRules;
use Tahadudhiya\WebDoctor\models\ConditionOutcome;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Investigation;
use Tahadudhiya\WebDoctor\models\InvestigationPlan;
use Tahadudhiya\WebDoctor\models\InvestigationStep;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\models\RootCauseAnalysis;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use Tahadudhiya\WebDoctor\records\InvestigationRecord;
use Tahadudhiya\WebDoctor\records\InvestigationStepRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\records\RootCauseRecord;
use Tahadudhiya\WebDoctor\rules\RootCauseRules;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\Errors;
use Tahadudhiya\WebDoctor\services\Investigations;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\RootCauses;
use Tahadudhiya\WebDoctor\services\Runs;
use Tahadudhiya\WebDoctor\Tests\_support\BreakingRuleRecommendations;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingInvestigationsController;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingIssuesController;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\Tests\_support\WebDoctorTables;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\caching\ArrayCache;
use yii\db\Exception as DbException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

/**
 * Investigating an issue, end to end: which checks are chosen and why, what running them
 * records, what it does to the Issue Center, what counts as nearby, who may start or read one,
 * and what the pages render.
 *
 * Planning is asserted against the checks Web Doctor actually ships with, because the plan is
 * only as good as its relationships to the real registry. Execution runs against a registry of
 * the test's own, so what an investigation reaches is exactly what the test registered — another
 * plugin contributing a check in a related category would otherwise change the answer.
 *
 * Every test runs under an environment name of its own and removes only that environment's rows;
 * investigations and their steps go with their issues.
 */
class InvestigationTest extends TestCase
{
    private const ACCESS_CP = 'accessCp';

    /** @var string A parameter left out of the request, as against one sent empty. */
    private const MISSING = '(missing)';

    private string $environment;
    private Diagnostics $registry;
    private Issues $issues;
    private Investigations $investigations;
    private WebDoctor $plugin;
    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Craft::$app->getDb()->tableExists(InvestigationRecord::TABLE)) {
            self::fail(sprintf(
                'The table %s does not exist. Reinstall Web Doctor in this project first: `php craft plugin/uninstall web-doctor && php craft plugin/install web-doctor`.',
                InvestigationRecord::TABLE,
            ));
        }

        $this->environment = 'tests-' . bin2hex(random_bytes(5));

        // Only what the test registers. A handler on the class — another plugin's, the local
        // lab's — would otherwise add checks to whichever categories an investigation reaches.
        $this->registry = new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };

        $this->issues = new Issues();
        $this->investigations = new Investigations([
            'registry' => $this->registry,
            'engine' => new DiagnosticEngine(['registry' => $this->registry]),
            'issues' => $this->issues,
            'errors' => new Errors(['issues' => $this->issues]),
            'rootCauses' => new RootCauses(),
            'environment' => $this->environment,
        ]);

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
        $this->plugin->set('investigations', $this->investigations);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // Investigations and their steps go with their issues, through the foreign keys; the
        // errors the checks ran into are kept apart from both, and their sources go with them.
        IssueRecord::deleteAll(['like', 'environment', 'tests-%', false]);
        ErrorGroupRecord::deleteAll(['like', 'environment', 'tests-%', false]);

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

    // Planning ---------------------------------------------------------------

    /**
     * @return array<string, array{DiagnosticCategory, string, list<string>, list<string>}>
     */
    public static function problems(): array
    {
        return [
            'a database error' => [
                DiagnosticCategory::DATABASE,
                'database.connection',
                ['database.connection', 'database.migrations', 'database.charset', 'queue.backlog', 'queue.failedJobs', 'plugins.installed', 'plugins.health', 'environment.configuration'],
                ['email.configuration', 'php.version', 'filesystem.volumes'],
            ],
            'an email problem' => [
                DiagnosticCategory::EMAIL,
                'email.configuration',
                ['email.configuration', 'environment.configuration', 'queue.failedJobs', 'queue.backlog'],
                ['database.connection', 'plugins.installed', 'php.version'],
            ],
            'a 500 error' => [
                DiagnosticCategory::HTTP,
                'http.serverError',
                ['php.version', 'php.extensions', 'php.configuration', 'plugins.installed', 'plugins.health', 'database.connection', 'queue.backlog', 'queue.failedJobs', 'craft.version', 'craft.application', 'environment.configuration'],
                ['email.configuration', 'filesystem.volumes', 'storage.paths'],
            ],
        ];
    }

    /**
     * The three kinds of problem the relationships were written for, planned against the checks
     * Web Doctor ships with.
     *
     * @param list<string> $reached
     * @param list<string> $untouched
     */
    #[DataProvider('problems')]
    public function testEachKindOfProblemReachesTheAreasRelatedToIt(DiagnosticCategory $category, string $origin, array $reached, array $untouched): void
    {
        $plan = InvestigationPlan::build($origin, $category, null, CoreDiagnostics::all());
        $ids = $plan->diagnosticIds();

        foreach ($reached as $id) {
            self::assertContains($id, $ids, "$id should be investigated alongside a {$category->value} problem.");
            self::assertNotSame('', (string)$plan->reasonFor($id), "$id was chosen without a reason.");
        }

        foreach ($untouched as $id) {
            self::assertNotContains($id, $ids, "$id has nothing to do with a {$category->value} problem.");
        }

        // Logs are the first thing anybody would inspect, and Web Doctor does not read them, so
        // the plan has to say so rather than look complete without them.
        self::assertNotEmpty(array_filter($plan->leads, static fn(string $lead): bool => str_contains($lead, 'logs')));
    }

    public function testTheSameProblemIsAlwaysInvestigatedTheSameWayAndItsOwnCheckComesFirst(): void
    {
        $first = InvestigationPlan::build('database.connection', DiagnosticCategory::DATABASE, null, CoreDiagnostics::all());
        $again = InvestigationPlan::build('database.connection', DiagnosticCategory::DATABASE, null, array_reverse(CoreDiagnostics::all()));

        self::assertSame('database', $first->ruleId);
        self::assertSame('database.connection', $first->checks[0]['diagnosticId']);
        self::assertTrue($first->checks[0]['origin']);
        self::assertSame(1, count(array_filter(array_column($first->checks, 'origin'))));
        // What was chosen does not depend on the order the checks were handed over in.
        self::assertEqualsCanonicalizing($first->diagnosticIds(), $again->diagnosticIds());
        // And a stored plan reads back as the plan it was.
        self::assertEquals($first, InvestigationPlan::fromArray(json_decode(Evidence::encode($first), true)));
    }

    public function testAShallowInvestigationLooksOnlyWhereTheProblemWasFound(): void
    {
        $plan = InvestigationPlan::build('database.connection', DiagnosticCategory::DATABASE, 'some-plugin', CoreDiagnostics::all(), DiagnosticDepth::SHALLOW);

        self::assertSame(['database'], array_values(array_unique(array_column($plan->checks, 'category'))));

        // Deep reaches the same checks as normal; what changes is how far each one goes.
        self::assertSame(
            InvestigationPlan::build('database.connection', DiagnosticCategory::DATABASE, null, CoreDiagnostics::all())->diagnosticIds(),
            InvestigationPlan::build('database.connection', DiagnosticCategory::DATABASE, null, CoreDiagnostics::all(), DiagnosticDepth::DEEP)->diagnosticIds(),
        );
    }

    public function testWhatNoCheckHereCoversIsListedRatherThanDropped(): void
    {
        // A 500 error's related areas include configuration, which no shipped check covers, and
        // the check that raised it is not one Web Doctor ships.
        $plan = InvestigationPlan::build('http.serverError', DiagnosticCategory::HTTP, null, CoreDiagnostics::all());
        $uncovered = array_column($plan->uncovered, 'area');

        self::assertContains('http.serverError', $uncovered);
        self::assertContains(DiagnosticCategory::CONFIGURATION->label(), $uncovered);
        self::assertFalse($plan->includesOrigin());

        foreach ($plan->uncovered as $area) {
            self::assertNotSame('', $area['reason']);
        }
    }

    public function testAFindingThatNamesAPluginBringsThePluginChecksIn(): void
    {
        $without = InvestigationPlan::build('storage.paths', DiagnosticCategory::STORAGE, null, CoreDiagnostics::all());
        $with = InvestigationPlan::build('storage.paths', DiagnosticCategory::STORAGE, 'imager-x', CoreDiagnostics::all());

        self::assertNotContains('plugins.health', $without->diagnosticIds());
        self::assertContains('plugins.health', $with->diagnosticIds());
        self::assertStringContainsString('imager-x', (string)$with->reasonFor('plugins.health'));
    }

    public function testEveryRelationshipIsExplainedAndEveryShippedCategoryHasOne(): void
    {
        $ids = [];

        foreach (InvestigationRules::all() as $rule) {
            $ids[] = $rule->id;
            self::assertNotSame('', $rule->label);

            foreach ($rule->related as $area) {
                self::assertNotSame('', $area->reason, "A relationship in the {$rule->id} rule gives no reason.");
            }
        }

        self::assertSame($ids, array_unique($ids));
        self::assertNotContains(InvestigationRules::FALLBACK, $ids);

        // A problem found by any shipped check is investigated under a rule written for it, not
        // under the general fallback.
        foreach (CoreDiagnostics::all() as $diagnostic) {
            self::assertNotSame(InvestigationRules::FALLBACK, InvestigationRules::for($diagnostic->category())->id, $diagnostic->id());
        }
    }

    // Running one ------------------------------------------------------------

    public function testAnInvestigationRunsThePlannedChecksAndRecordsWhatEachReported(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'The database refused the connection.');
        $this->check('tests.connection', DiagnosticCategory::DATABASE, DiagnosticStatus::PASS, 'Connected.');
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::WARNING, '12 jobs have failed.', [
            new Evidence(type: EvidenceType::QUEUE, label: 'Failed jobs', source: 'tests.queue', data: ['failed' => 12]),
        ]);
        $mail = $this->check('tests.mail', DiagnosticCategory::EMAIL, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $investigation = $this->investigations->investigate($issue->id, userId: null);
        $steps = $this->investigations->steps($investigation->id);

        self::assertSame(InvestigationStatus::COMPLETED, $investigation->status);
        self::assertSame(['tests.origin', 'tests.connection', 'tests.queue'], $investigation->plan->diagnosticIds());
        self::assertSame(3, $investigation->checksPlanned);
        self::assertSame(3, $investigation->checksRun);
        self::assertSame(2, $investigation->checksWithProblems);
        self::assertSame(0, $investigation->checksIncomplete);
        self::assertSame(1, $investigation->evidenceCount);
        self::assertSame(Investigation::ORIGIN_PRESENT, $investigation->originOutcome());
        self::assertNotNull($investigation->runId);
        self::assertNotNull($investigation->finishedAt);
        // An email check is not related to a database problem, so it was never asked.
        self::assertSame(0, $mail->runs);

        self::assertSame([
            InvestigationStepType::STARTED,
            InvestigationStepType::PLANNED,
            InvestigationStepType::CHECKED,
            InvestigationStepType::CHECKED,
            InvestigationStepType::CHECKED,
            InvestigationStepType::RECONCILED,
            InvestigationStepType::DIAGNOSED,
            InvestigationStepType::FINISHED,
        ], array_map(static fn(InvestigationStep $s): InvestigationStepType => $s->type, $steps));

        $queue = $this->stepFor($steps, 'tests.queue');
        self::assertSame(DiagnosticStatus::WARNING, $queue->status);
        self::assertSame('12 jobs have failed.', $queue->summary);
        self::assertSame($investigation->plan->reasonFor('tests.queue'), $queue->note);
        self::assertSame(12, $queue->evidence[0]->get('failed'));
        // Attributed to where it was gathered, as every piece of evidence is.
        self::assertSame($investigation->runId, $queue->evidence[0]->runId);
        self::assertSame($this->environment, $queue->evidence[0]->environment);
    }

    /**
     * Findings go through the Issue Center's own reconciliation, so an investigation and the
     * issue list never give two answers to one question.
     */
    public function testAProblemARelatedCheckFindsBecomesAnIssueAndIsLinkedFromTheInvestigation(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::WARNING, 'Jobs are piling up.');
        $issue = $this->raise('tests.origin');

        $investigation = $this->investigations->investigate($issue->id);
        $steps = $this->investigations->steps($investigation->id);
        $raised = $this->issueFor('tests.queue');

        self::assertSame('Jobs are piling up.', $raised->title);
        self::assertSame($raised->id, $this->stepFor($steps, 'tests.queue')->relatedIssueId);
        self::assertSame($issue->id, $this->stepFor($steps, 'tests.origin')->relatedIssueId);
        // Seen again by the investigation, and still one issue rather than two.
        self::assertSame(2, $this->reread($issue)->occurrences);
        self::assertSame($investigation->runId, $this->reread($issue)->latestRunId);
        // It is a finding of this investigation, so it is not listed again as something nearby.
        self::assertSame([], $this->stepsOf($steps, InvestigationStepType::RELATED_ISSUE));
    }

    public function testWhenTheCheckBehindTheIssueNoLongerReportsItTheIssueIsObservedClear(): void
    {
        $origin = $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $origin->handler = self::respond(DiagnosticStatus::PASS, 'Connected.');
        $investigation = $this->investigations->investigate($issue->id);

        self::assertSame(Investigation::ORIGIN_CLEAR, $investigation->originOutcome());
        self::assertSame(IssueStatus::RESOLVED, $this->reread($issue)->status);
        self::assertSame($investigation->runId, $this->reread($issue)->resolvedByRunId);
    }

    public function testACheckThatBreaksLeavesTheInvestigationPartRatherThanWhollyAnswered(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $this->check('tests.connection', DiagnosticCategory::DATABASE, static function(): DiagnosticResult {
            throw new RuntimeException('The charset query failed.');
        });
        $queue = $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::PASS);
        $issue = $this->raise('tests.origin');

        $investigation = $this->investigations->investigate($issue->id);
        $broken = $this->stepFor($this->investigations->steps($investigation->id), 'tests.connection');

        self::assertSame(InvestigationStatus::PARTIAL, $investigation->status);
        self::assertSame(1, $investigation->checksIncomplete);
        self::assertSame(DiagnosticStatus::ERROR, $broken->status);
        self::assertTrue($broken->isIncomplete());
        // Contained by the engine, so everything after it still ran.
        self::assertSame(1, $queue->runs);
    }

    public function testTheErrorsAnInvestigationRunsIntoAreCountedAgainstTheirGroupsAndShown(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $locked = 41;
        // Two checks running into one error: one error recorded, however many checks met it.
        $throw = static function() use (&$locked): DiagnosticResult {
            throw new RuntimeException("Row $locked could not be locked.");
        };
        $this->check('tests.connection', DiagnosticCategory::DATABASE, $throw);
        $this->check('tests.charset', DiagnosticCategory::DATABASE, $throw);
        $issue = $this->raise('tests.origin');

        $first = $this->investigations->investigate($issue->id);
        $note = $this->stepsOf($this->investigations->steps($first->id), InvestigationStepType::RECONCILED)[0]->note;

        self::assertStringContainsString('1 error was recorded, 1 of them new.', (string)$note);

        // Investigated again, the same failure about another row is the same error.
        $locked = 97;
        $second = $this->investigations->investigate($issue->id);
        $note = $this->stepsOf($this->investigations->steps($second->id), InvestigationStepType::RECONCILED)[0]->note;
        $groups = ErrorGroupRecord::findAll(['environment' => $this->environment]);

        self::assertStringContainsString('1 error was recorded, each one already seen here.', (string)$note);
        self::assertCount(1, $groups);
        self::assertSame('Row {id} could not be locked.', $groups[0]->normalizedMessage);
        self::assertSame(4, (int)$groups[0]->occurrences);
        // Recorded where the issue was found — its environment, and no particular site — and
        // nowhere else.
        self::assertNull($groups[0]->siteId);
        self::assertSame(0, (int)ErrorGroupRecord::find()->where(['like', 'environment', 'tests-%', false])->andWhere(['not', ['environment' => $this->environment]])->count());

        // The page recognises the error from the evidence its step kept and links its group.
        $params = ['issueId' => $issue->id, 'investigationId' => $first->id];
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE]);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', $params);

        self::assertStringContainsString('id="errors"', $html);
        self::assertStringContainsString('web-doctor/errors/' . $groups[0]->id, $html);
        self::assertStringContainsString('Row {id} could not be locked.', $html);

        // What it said is evidence, and is shown only to whoever may see evidence.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $withheld = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', $params);

        self::assertStringContainsString('web-doctor/errors/' . $groups[0]->id, $withheld);
        self::assertStringNotContainsString('could not be locked', $withheld);
    }

    public function testAnIssueWhoseCheckHasGoneIsInvestigatedAsFarAsItCanBeAndSaysSo(): void
    {
        $this->check('tests.connection', DiagnosticCategory::DATABASE, DiagnosticStatus::PASS);
        $issue = $this->raise('tests.removed');

        $investigation = $this->investigations->investigate($issue->id);

        self::assertSame(InvestigationStatus::PARTIAL, $investigation->status);
        self::assertSame(Investigation::ORIGIN_UNAVAILABLE, $investigation->originOutcome());
        self::assertContains('tests.removed', array_column($investigation->plan->uncovered, 'area'));
        self::assertSame(['tests.connection'], $investigation->plan->diagnosticIds());
    }

    public function testAShallowInvestigationRunsOnlyWhereTheProblemWasFound(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $queue = $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::PASS);
        $issue = $this->raise('tests.origin');

        $investigation = $this->investigations->investigate($issue->id, DiagnosticDepth::SHALLOW);

        self::assertSame(DiagnosticDepth::SHALLOW, $investigation->depth);
        self::assertSame(['tests.origin'], $investigation->plan->diagnosticIds());
        self::assertSame(0, $queue->runs);
    }

    /**
     * Checks run here describe here. The context is stated outright, because the obvious way to
     * build one fills an absent site with the current one — and an issue about no particular site
     * investigated as a finding about one would land its findings on a different issue.
     */
    public function testAnIssueIsInvestigatedInTheSiteItWasFoundInAndNowhereElse(): void
    {
        $contexts = [];
        $this->check('tests.origin', DiagnosticCategory::DATABASE, static function(TestDiagnostic $d, DiagnosticContext $context) use (&$contexts): DiagnosticResult {
            $contexts[] = $context;

            return self::respond(DiagnosticStatus::FAIL)($d, $context);
        });

        foreach ([null, $this->primarySiteId()] as $siteId) {
            $issue = $this->raise('tests.origin', siteId: $siteId);
            $investigation = $this->investigations->investigate($issue->id);

            self::assertSame($siteId, $investigation->siteId);
            self::assertSame($siteId, end($contexts)->siteId);
            self::assertSame($this->environment, end($contexts)->environment);
            // The same issue seen again, not a second one raised under another site.
            self::assertSame(2, $this->reread($issue)->occurrences);
        }
    }

    public function testAnIssueFoundElsewhereOrOnADeletedSiteIsNotInvestigatedHere(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $elsewhere = new Investigations([
            'registry' => $this->registry,
            'issues' => $this->issues,
            'environment' => 'production',
        ]);

        $gone = $this->raise('tests.origin', siteId: $this->primarySiteId());
        IssueRecord::updateAll(['siteId' => null, 'siteName' => 'Retired site'], ['id' => $gone->id]);

        foreach ([[$elsewhere, $issue, 'production'], [$this->investigations, $gone, 'Retired site']] as [$service, $target, $named]) {
            try {
                $service->investigate($target->id);
                self::fail('An issue was investigated somewhere its checks would describe a different place.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($named, $e->getMessage());
            }

            // Refused before anything was written or run.
            self::assertSame(0, (int)InvestigationRecord::find()->where(['issueId' => $target->id])->count());
        }
    }

    public function testOpenIssuesNearbyAreRecordedAndNothingFurtherAwayIs(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin', affectedPlugin: 'seo');

        // Nearby: another open queue problem, and a problem elsewhere naming the same plugin.
        $queue = $this->raise('tests.otherQueue', DiagnosticCategory::QUEUE, severity: Severity::CRITICAL);
        $plugin = $this->raise('tests.seoHeaders', DiagnosticCategory::SECURITY, affectedPlugin: 'seo');

        // Not nearby: dismissed, in another environment, in an unrelated area.
        $ignored = $this->raise('tests.ignoredQueue', DiagnosticCategory::QUEUE);
        $this->issues->transition($ignored->id, IssueStatus::IGNORED, 'Known and accepted.');
        $this->raise('tests.farQueue', DiagnosticCategory::QUEUE, environment: $this->environment . '-other');
        $this->raise('tests.mail', DiagnosticCategory::EMAIL);

        // The queue area is reached only because a check covers it; register one that passes.
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::PASS);

        $investigation = $this->investigations->investigate($issue->id);
        $related = $this->stepsOf($this->investigations->steps($investigation->id), InvestigationStepType::RELATED_ISSUE);

        // Worst first.
        self::assertSame([$queue->id, $plugin->id], array_map(static fn(InvestigationStep $s): ?int => $s->relatedIssueId, $related));
        self::assertSame(2, $investigation->relatedIssues);
        self::assertSame(Severity::CRITICAL, $related[0]->severity);
    }

    public function testAnInvestigationThatBreaksIsKeptAsFailedAndSaysWhyWithoutLeakingAnything(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $this->investigations->engine = new class(['registry' => $this->registry]) extends DiagnosticEngine {
            public function runMany(iterable $diagnostics, ?DiagnosticContext $context = null): DiagnosticRun
            {
                throw new RuntimeException('Queue refused: SELECT * FROM users WHERE password=hunter2');
            }
        };

        $investigation = $this->investigations->investigate($issue->id);
        $steps = $this->investigations->steps($investigation->id);

        // Nothing was recorded before it stopped, so there is nothing partial to keep.
        self::assertSame(InvestigationStatus::FAILED, $investigation->status);
        self::assertSame(0, $investigation->checksRun);
        self::assertNotNull($investigation->finishedAt);
        self::assertNotNull($investigation->durationMs);
        self::assertSame(InvestigationStepType::FAILED, end($steps)->type);

        // The kind of exception, never its message: redaction takes the credential out, but not the SQL.
        self::assertStringContainsString('RuntimeException', (string)$investigation->failure);
        foreach (['hunter2', 'SELECT', 'Queue refused'] as $leak) {
            self::assertStringNotContainsString($leak, $this->storedRows($investigation));
        }
    }

    public function testEvidenceIsKeptBoundedAndNothingSecretReachesTheDatabase(): void
    {
        $this->investigations->maxEvidence = 2;
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'SMTP refused: password=hunter2', [
            new Evidence(type: EvidenceType::DATABASE_ERROR, label: 'Error', source: 'tests.origin', data: ['message' => 'Access denied, password=hunter2']),
            new Evidence(type: EvidenceType::CONFIGURATION, label: 'Credentials', source: 'tests.origin', data: ['password' => 'hunter2', 'user' => 'craft']),
            new Evidence(type: EvidenceType::CONFIGURATION, label: 'Third', source: 'tests.origin', data: ['n' => 3]),
        ]);
        $issue = $this->raise('tests.origin');

        $investigation = $this->investigations->investigate($issue->id);
        $origin = $this->stepFor($this->investigations->steps($investigation->id), 'tests.origin');

        self::assertSame(2, $investigation->evidenceCount);
        self::assertCount(2, $origin->evidence);
        self::assertSame(3, $origin->evidenceCount);
        self::assertTrue($origin->evidenceTruncated);
        // What is left is not a basis for choosing advice.
        self::assertFalse(RecommendationCase::fromStep($origin)?->evidenceComplete);
        self::assertSame(Redaction::REDACTED, $origin->evidence[1]->get('password'));
        self::assertStringNotContainsString('hunter2', $this->storedRows($investigation));
    }

    public function testAnIssueKeepsABoundedNumberOfInvestigationsAndTheyGoWithIt(): void
    {
        $this->investigations->maxPerIssue = 2;
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $first = $this->investigations->investigate($issue->id);
        $second = $this->investigations->investigate($issue->id);
        $third = $this->investigations->investigate($issue->id);

        self::assertSame([$third->id, $second->id], array_map(static fn(Investigation $i): int => $i->id, $this->investigations->forIssue($issue->id)));
        self::assertNull($this->investigations->get($first->id));
        self::assertSame(0, (int)InvestigationStepRecord::find()->where(['investigationId' => $first->id])->count());

        IssueRecord::deleteAll(['id' => $issue->id]);

        self::assertSame(0, (int)InvestigationRecord::find()->where(['issueId' => $issue->id])->count());
        self::assertSame(0, (int)InvestigationStepRecord::find()->where(['investigationId' => [$second->id, $third->id]])->count());
    }


    // Stopping part-way ------------------------------------------------------

    /**
     * The first check's result is written, the second's cannot be: what was recorded stays, the
     * counts describe exactly that, and the investigation says it stopped rather than finished.
     */
    public function testAnInvestigationThatStopsPartWayKeepsWhatItRecordedAndSaysSo(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'Still failing.', [
            new Evidence(type: EvidenceType::DATABASE_ERROR, label: 'Kept', source: 'tests.origin', data: ['kept' => true]),
        ]);
        $this->check('tests.connection', DiagnosticCategory::DATABASE, DiagnosticStatus::PASS);
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::PASS);
        $issue = $this->raise('tests.origin');

        $checked = 0;
        $service = $this->failingWhen(static function(InvestigationStepType $type) use (&$checked): bool {
            return $type === InvestigationStepType::CHECKED && ++$checked === 2;
        });

        $investigation = $service->investigate($issue->id);
        $steps = $service->steps($investigation->id);

        self::assertSame(InvestigationStatus::PARTIAL, $investigation->status);
        self::assertSame(1, $investigation->checksRun);
        self::assertSame(1, $investigation->checksWithProblems);
        self::assertSame(0, $investigation->checksIncomplete);
        self::assertSame(0, $investigation->checksSkipped);
        self::assertSame(1, $investigation->evidenceCount);
        self::assertSame(DiagnosticStatus::FAIL, $investigation->originStatus);
        self::assertNotNull($investigation->finishedAt);
        self::assertNotNull($investigation->durationMs);
        self::assertStringContainsString('RuntimeException', (string)$investigation->failure);

        self::assertSame([
            InvestigationStepType::STARTED,
            InvestigationStepType::PLANNED,
            InvestigationStepType::CHECKED,
            InvestigationStepType::FAILED,
        ], array_map(static fn(InvestigationStep $s): InvestigationStepType => $s->type, $steps));
        self::assertSame([0, 1, 2, 3], array_map(static fn(InvestigationStep $s): int => $s->position, $steps));
        self::assertTrue($this->stepFor($steps, 'tests.origin')->evidence[0]->get('kept'));
        self::assertStringNotContainsString('hunter2', $this->storedRows($investigation));

        $this->signIn(admin: true);
        $this->request('GET');
        $this->plugin->set('investigations', $service);
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        self::assertStringContainsString('This investigation stopped before it finished.', $html);
        self::assertStringContainsString('What it recorded before it stopped is below', $html);
        self::assertStringContainsString(InvestigationStatus::PARTIAL->label(), $html);
    }

    public function testAnInvestigationThatCannotRecordItsFirstStepIsFailedRatherThanLeftRunning(): void
    {
        $origin = $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $service = $this->failingWhen(static fn(InvestigationStepType $type): bool => $type === InvestigationStepType::STARTED);
        $investigation = $service->investigate($issue->id);
        $stored = $service->get($investigation->id);

        self::assertInstanceOf(Investigation::class, $stored);
        self::assertSame(InvestigationStatus::FAILED, $stored->status);
        self::assertSame(0, $stored->checksRun);
        self::assertSame(0, $origin->runs);
        self::assertSame([InvestigationStepType::FAILED], array_map(static fn(InvestigationStep $s): InvestigationStepType => $s->type, $service->steps($investigation->id)));
    }

    public function testAFinishThatCannotBeRecordedNeverLeavesTheInvestigationReadingAsCompleted(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $service = $this->failingWhen(static fn(InvestigationStepType $type): bool => $type === InvestigationStepType::FINISHED);
        $logger = \Yii::getLogger();
        \Yii::setLogger($capture = new \yii\log\Logger());

        try {
            $investigation = $service->get($service->investigate($issue->id)->id);
        } finally {
            \Yii::setLogger($logger);
        }

        // The failure is logged, through the sanitised form: what failed, then the exception with
        // its credential redacted.
        $logged = implode("\n", array_map(static fn(array $m): string => (string)$m[0], $capture->messages));
        self::assertStringContainsString('An investigation could not be completed. RuntimeException: ', $logged);
        self::assertStringNotContainsString('hunter2', $logged);

        // Every check was recorded; only the ending was not. That is a partial record, not a whole one.
        self::assertInstanceOf(Investigation::class, $investigation);
        self::assertSame(InvestigationStatus::PARTIAL, $investigation->status);
        self::assertSame(1, $investigation->checksRun);

        // Where the ending that failed would itself have been partial — a related check could not
        // tell — the stored row must still say so and when it stopped, rather than keep the
        // "running" the rolled-back ending was never able to replace.
        $this->check('tests.database', DiagnosticCategory::DATABASE, DiagnosticStatus::UNKNOWN);
        $investigation = $service->get($service->investigate($issue->id)->id);

        self::assertInstanceOf(Investigation::class, $investigation);
        self::assertSame(InvestigationStatus::PARTIAL, $investigation->status);
        self::assertNotNull($investigation->finishedAt);
        self::assertNotNull($investigation->failure);
    }

    // What an investigation keeps ---------------------------------------------

    public function testARelatedIssueReadsAsItWasWhenTheInvestigationRanWhateverHappensToItLater(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::PASS);
        $issue = $this->raise('tests.origin', affectedPlugin: 'seo');
        $queue = $this->raise('tests.otherQueue', DiagnosticCategory::QUEUE, severity: Severity::CRITICAL);
        $plugin = $this->raise('tests.seoHeaders', DiagnosticCategory::SECURITY, affectedPlugin: 'seo');
        $investigation = $this->investigations->investigate($issue->id);

        // The issue moves on after the investigation.
        IssueRecord::updateAll(['title' => 'Renamed since.', 'severity' => Severity::LOW->value], ['id' => $queue->id]);
        $this->issues->transition($queue->id, IssueStatus::WONT_FIX, 'Accepted.');

        $related = $this->stepsOf($this->investigations->steps($investigation->id), InvestigationStepType::RELATED_ISSUE);

        self::assertSame($queue->id, $related[0]->relatedIssueId);
        self::assertSame('tests.otherQueue reports a problem.', $related[0]->summary);
        self::assertSame(Severity::CRITICAL, $related[0]->severity);
        self::assertStringContainsString(IssueStatus::NEW->label() . ' at the time', (string)$related[0]->note);
        self::assertStringContainsString(DiagnosticCategory::QUEUE->label(), (string)$related[0]->note);
        self::assertStringContainsString('“seo”', (string)$related[1]->note);

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        self::assertStringContainsString('tests.otherQueue reports a problem.', $html);
        self::assertStringNotContainsString('Renamed since.', $html);
        self::assertStringContainsString("issues/{$plugin->id}", $html);
    }

    /**
     * The issue evidence store holds what an issue's own check found. Evidence from the other
     * checks an investigation ran lives on the investigation, or it would be read as the issue's.
     */
    public function testInvestigationEvidenceIsKeptApartFromTheIssueEvidenceStore(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'Still failing.', [
            new Evidence(type: EvidenceType::DATABASE_ERROR, label: 'Origin fact', source: 'tests.origin', data: ['n' => 1]),
        ]);
        $this->check('tests.connection', DiagnosticCategory::DATABASE, DiagnosticStatus::PASS, 'Fine.', [
            new Evidence(type: EvidenceType::DATABASE, label: 'Related fact', source: 'tests.connection', data: ['n' => 2]),
        ]);
        $issue = $this->raise('tests.origin');

        $investigation = $this->investigations->investigate($issue->id);

        // The issue's own check found the problem again, so its evidence joins the issue's store
        // as any run's would; the passing related check's evidence goes nowhere but the step.
        self::assertSame(['tests.origin'], array_values(array_unique(array_map('strval', EvidenceRecord::find()->select(['diagnosticId'])->where(['issueId' => $issue->id])->column()))));
        self::assertSame(0, (int)EvidenceRecord::find()->where(['diagnosticId' => 'tests.connection'])->count());
        self::assertSame('Related fact', $this->stepFor($this->investigations->steps($investigation->id), 'tests.connection')->evidence[0]->label);

        // And the store's own reads are untouched by the investigation's evidence.
        self::assertSame(['Origin fact'], array_map(
            static fn(\Tahadudhiya\WebDoctor\models\StoredEvidence $e): string => $e->evidence->label,
            (new \Tahadudhiya\WebDoctor\services\EvidenceStore())->latest($issue->id, $this->reread($issue)->latestRunId),
        ));
    }

    public function testNothingSecretReachesAnythingAnInvestigationStores(): void
    {
        $secrets = [
            'hunter2-assignment' => 'SMTP refused: password=hunter2-assignment',
            'jsonpassword1' => '{"password":"jsonpassword1"}',
            'escapedpass77' => '{\"password\":\"escapedpass77\"}',
            'singlequote99' => "'password' => 'singlequote99'",
            'dsnSecret42' => 'mysql://craft:dsnSecret42@db:3306/craft',
            'bearerTok3n' => 'Authorization: Bearer bearerTok3n',
            'eyJ' . 'hbGciOiJIUzI1NiJ9.eyJzdWIiOiJpbnYifQ.c2lnbmF0dXJlLWludg' => 'Rejected eyJ' . 'hbGciOiJIUzI1NiJ9.eyJzdWIiOiJpbnYifQ.c2lnbmF0dXJlLWludg as expired',
            'sk_' . 'live_' . str_repeat('Inv9', 6) => 'Charge failed using sk_' . 'live_' . str_repeat('Inv9', 6),
            'MIIEpAIBAAKCAQEAinvinvinv' => "Loaded -----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEAinvinvinv\n-----END RSA PRIVATE KEY----- from disk",
            'B0002/invHook123' => 'POST https://hooks.slack.com/services/T0001/B0002/invHook123 returned 404',
        ];
        $text = implode(' | ', $secrets);

        $origin = $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, $text, [
            new Evidence(
                type: EvidenceType::DATABASE_ERROR,
                label: $secrets['hunter2-assignment'],
                source: $secrets['bearerTok3n'],
                data: ['message' => $text, 'password' => 'plainPassword55', 'apiKey' => 'plainApiKey55', 'DB_USER' => 'dbUserName55'],
                metadata: ['note' => $text],
                reference: $secrets['dsnSecret42'],
            ),
        ]);
        $origin->diagnosticName = 'Check ' . $secrets['hunter2-assignment'];
        $issue = $this->raise('tests.origin');
        $this->raise('tests.otherQueue', DiagnosticCategory::QUEUE);
        // A title is what a check wrote, so it arrives redacted; written here directly, it tests the
        // snapshot on its own.
        IssueRecord::updateAll(['title' => $secrets['hunter2-assignment'] . ' ' . $secrets['bearerTok3n']], ['diagnosticId' => 'tests.otherQueue', 'environment' => $this->environment]);
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::PASS);

        $investigation = $this->investigations->investigate($issue->id);
        $stored = $this->storedRows($investigation);

        self::assertGreaterThan(0, $investigation->relatedIssues);

        foreach ([...array_keys($secrets), 'plainPassword55', 'plainApiKey55', 'dbUserName55'] as $secret) {
            self::assertStringNotContainsString($secret, $stored, "“{$secret}” reached the investigation tables.");
        }
    }

    // Bounds -----------------------------------------------------------------

    public function testAnInvestigationRunsAtMostTwentyFiveChecksEachAtMostOnceOriginFirst(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $related = [];

        // Forty checks across the database rule's related areas, far more than the bound.
        foreach (range(1, 40) as $i) {
            $category = [DiagnosticCategory::DATABASE, DiagnosticCategory::QUEUE, DiagnosticCategory::PLUGINS, DiagnosticCategory::ENVIRONMENT][$i % 4];
            $related[] = $this->check(sprintf('tests.check%02d', $i), $category, DiagnosticStatus::PASS);
        }

        $issue = $this->raise('tests.origin');
        $investigation = $this->investigations->investigate($issue->id);
        $runs = array_map(static fn(TestDiagnostic $d): int => $d->runs, $related);

        self::assertSame(InvestigationPlan::MAX_CHECKS, $investigation->checksRun);
        self::assertSame(InvestigationPlan::MAX_CHECKS - 1, array_sum($runs));
        self::assertLessThanOrEqual(1, max($runs));
        self::assertSame('tests.origin', $investigation->plan->checks[0]['diagnosticId']);
        // Each check left out is counted once, however many related areas reached it.
        self::assertSame(41 - InvestigationPlan::MAX_CHECKS, $investigation->plan->omitted);

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        self::assertStringContainsString('left out to keep the investigation bounded', $html);
    }

    public function testThePlanBoundIsExactAndDeterministicWhateverTheRegistryHolds(): void
    {
        $available = [];

        foreach (range(1, 60) as $i) {
            $diagnostic = new TestDiagnostic();
            $diagnostic->diagnosticId = sprintf('tests.big%02d', $i);
            $diagnostic->diagnosticCategory = [DiagnosticCategory::HTTP, DiagnosticCategory::PHP, DiagnosticCategory::PLUGINS, DiagnosticCategory::DATABASE, DiagnosticCategory::QUEUE, DiagnosticCategory::ENVIRONMENT][$i % 6];
            $available[] = $diagnostic;
        }

        // The same checks handed over twice, as a registry that listed them twice would.
        $plan = InvestigationPlan::build('tests.big06', DiagnosticCategory::HTTP, 'some-plugin', [...$available, ...$available]);
        $again = InvestigationPlan::build('tests.big06', DiagnosticCategory::HTTP, 'some-plugin', array_reverse($available));

        self::assertCount(InvestigationPlan::MAX_CHECKS, $plan->checks);
        self::assertSame($plan->diagnosticIds(), array_values(array_unique($plan->diagnosticIds())));
        self::assertSame('tests.big06', $plan->checks[0]['diagnosticId']);
        self::assertSame(60 - InvestigationPlan::MAX_CHECKS, $plan->omitted);
        self::assertEqualsCanonicalizing($plan->diagnosticIds(), $again->diagnosticIds());
        // Configuration is related to a failing request and nothing here covers it.
        self::assertContains(DiagnosticCategory::CONFIGURATION->label(), array_column($plan->uncovered, 'area'));
    }

    // Where it runs, and what it leaves alone ---------------------------------

    /**
     * Craft soft-deletes sites, so the reference that would be nulled usually is not: an issue can
     * still name a site that is in the trash, and must not be investigated as though it were live.
     */
    public function testAnIssueWhoseSiteIsInTheTrashIsNotInvestigated(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $primary = Craft::$app->getSites()->getPrimarySite();
        $db = Craft::$app->getDb();

        Db::insert(\craft\db\Table::SITES, [
            'groupId' => $primary->groupId,
            'primary' => false,
            'enabled' => false,
            'name' => 'Trashed test site',
            'handle' => 'wdTrashed' . bin2hex(random_bytes(3)),
            'language' => $primary->language,
            'hasUrls' => false,
            'sortOrder' => 999,
            'dateDeleted' => Db::prepareDateForDb(new DateTimeImmutable()),
            'uid' => StringHelper::UUID(),
        ]);
        $trashed = (int)$db->getLastInsertID();

        try {
            $issue = $this->raise('tests.origin', siteId: $trashed);

            try {
                $this->investigations->investigate($issue->id);
                self::fail('An issue was investigated against a site in the trash.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('has been deleted', $e->getMessage());
            }

            self::assertSame([], $this->investigations->forIssue($issue->id));
        } finally {
            IssueRecord::deleteAll(['siteId' => $trashed]);
            Db::delete(\craft\db\Table::SITES, ['id' => $trashed]);
        }
    }

    public function testInvestigatingLeavesTheIssuesOwnStatusAlone(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $fresh = $this->raise('tests.origin');
        $confirmed = $this->raise('tests.confirmed', DiagnosticCategory::QUEUE);
        $this->check('tests.confirmed', DiagnosticCategory::QUEUE, DiagnosticStatus::FAIL);
        $this->issues->transition($confirmed->id, IssueStatus::CONFIRMED);
        $before = $this->reread($confirmed);

        $this->investigations->investigate($fresh->id);
        $this->investigations->investigate($confirmed->id);

        // Looking into a problem is not a decision about it: nothing moves to investigating.
        self::assertSame(IssueStatus::NEW, $this->reread($fresh)->status);
        self::assertSame(IssueStatus::CONFIRMED, $this->reread($confirmed)->status);
        self::assertEquals($before->statusChangedAt, $this->reread($confirmed)->statusChangedAt);
    }

    public function testAnInvestigationNeverReplacesTheDashboardsLatestRun(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $runs = new Runs(['cache' => new ArrayCache(), 'environment' => $this->environment]);
        $this->plugin->set('runs', $runs);
        $now = new DateTimeImmutable();
        $dashboard = new DiagnosticRun(context: new DiagnosticContext(environment: $this->environment), results: [], startedAt: $now, finishedAt: $now, durationMs: 1.0);
        self::assertTrue($runs->remember($dashboard));

        $this->signIn(admin: true);
        $this->post(['issueId' => $issue->id]);
        $this->investigationsController()->runAction('start');

        self::assertCount(1, $this->investigations->forIssue($issue->id));
        self::assertSame($dashboard->id(), $runs->latest(null)?->id());
    }

    /**
     * What a request may ask for: missing, the depth is normal, as the form states; one of the
     * three, that one exactly; anything else — and an issue ID that is not a number — is refused
     * before anything runs or is written.
     *
     * @return array<string, array{mixed, mixed, DiagnosticDepth|null}>
     */
    public static function startRequests(): array
    {
        return [
            'shallow' => [null, 'shallow', DiagnosticDepth::SHALLOW],
            'normal' => [null, 'normal', DiagnosticDepth::NORMAL],
            'deep' => [null, 'deep', DiagnosticDepth::DEEP],
            'depth missing' => [null, self::MISSING, DiagnosticDepth::NORMAL],
            'a depth Web Doctor does not have' => [null, 'bottomless', null],
            'an empty depth' => [null, '', null],
            'the wrong case' => [null, 'Deep', null],
            'a depth as a list' => [null, ['deep'], null],
            'an issue ID that is not a number' => ['not-an-id', 'normal', null],
            'an issue ID as a list' => [['1'], 'normal', null],
            'a negative issue ID' => ['-4', 'normal', null],
            // Read as a number, this would name the real issue.
            'the real issue ID with text after it' => ['{id}abc', 'normal', null],
        ];
    }

    #[DataProvider('startRequests')]
    public function testAnInvestigationStartsExactlyAsAskedOrNotAtAll(mixed $issueId, mixed $depth, ?DiagnosticDepth $expected): void
    {
        $origin = $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'Failing.', [
            new Evidence(type: EvidenceType::CONFIGURATION, label: 'Settings', source: 'tests.origin', data: ['value' => 1]),
        ]);
        $breaks = $this->check('tests.breaks', DiagnosticCategory::DATABASE, static fn(): DiagnosticResult => throw new RuntimeException('Would leave an error group.'));
        $issue = $this->raise('tests.origin');
        $this->signIn(admin: true);

        $params = ['issueId' => is_string($issueId) ? str_replace('{id}', (string)$issue->id, $issueId) : ($issueId ?? (string)$issue->id)];

        if ($depth !== self::MISSING) {
            $params['depth'] = $depth;
        }

        $this->post($params);

        if ($expected === null) {
            $before = WebDoctorTables::snapshot();

            try {
                $this->investigationsController()->runAction('start');
                self::fail('A malformed request started an investigation.');
            } catch (BadRequestHttpException) {
            }

            // Nothing ran, and nothing was written anywhere Web Doctor keeps anything: no
            // investigation, step, issue change, evidence, error group or cause.
            self::assertSame(0, $origin->runs);
            self::assertSame(0, $breaks->runs);
            self::assertSame($before, WebDoctorTables::snapshot());

            return;
        }

        $this->investigationsController()->runAction('start');
        $made = $this->investigations->forIssue($issue->id);

        self::assertCount(1, $made);
        self::assertSame($expected, $made[0]->depth);
        self::assertSame(1, $origin->runs);
    }

    public function testARequestNamingNoIssueOrOneThatDoesNotExistChangesNothing(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $this->raise('tests.origin');
        $this->signIn(admin: true);
        $before = WebDoctorTables::snapshot();

        // No issue at all is a malformed request.
        $this->post([]);
        try {
            $this->investigationsController()->runAction('start');
            self::fail('A request naming no issue was accepted.');
        } catch (BadRequestHttpException) {
        }

        // A well-formed ID naming no issue is refused and said so.
        $this->post(['issueId' => '999999999']);
        $controller = $this->investigationsController();
        $controller->runAction('start');

        self::assertSame('fail', $controller->lastFlash()['level'] ?? null);
        self::assertSame($before, WebDoctorTables::snapshot());
    }

    public function testInvestigatingIsNotManagingAndReadingIsNotSeeingInside(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'Failing.', [
            new Evidence(type: EvidenceType::DATABASE_ERROR, label: 'Error', source: 'tests.origin', data: ['n' => 1], reference: 'table:distinctiveTable881'),
        ]);
        $issue = $this->raise('tests.origin');
        $investigation = $this->investigations->investigate($issue->id);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::INVESTIGATE_ISSUES]);
        $this->request('GET');
        $issuePage = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);
        $page = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        self::assertStringContainsString('web-doctor/investigations/start', $issuePage);
        self::assertStringNotContainsString('web-doctor/issues/update-status', $issuePage);
        // Where a fact lives is part of what it contains.
        self::assertStringNotContainsString('distinctiveTable881', $page);
        self::assertStringNotContainsString('distinctiveTable881', $issuePage);
    }

    // Who may reach it -------------------------------------------------------

    public function testStartingAnInvestigationNeedsItsOwnPermissionAndReadingOneDoesNot(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');
        $investigation = $this->investigations->investigate($issue->id);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->post(['issueId' => $issue->id]);

        try {
            $this->investigationsController()->runAction('start');
            self::fail('Somebody who may only read issues started an investigation.');
        } catch (ForbiddenHttpException) {
            self::assertCount(1, $this->investigations->forIssue($issue->id));
        }

        // Reading what one found needs what reading the issue needs, and no more.
        $this->request('GET');
        $this->investigationsController()->runAction('detail', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $this->expectException(ForbiddenHttpException::class);
        $this->investigationsController()->runAction('detail', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);
    }

    public function testStartingOneIsAControlPanelPostWithAValidToken(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');
        $this->signIn(admin: true);

        $this->request('GET');
        try {
            $this->investigationsController()->runAction('start');
            self::fail('An investigation was started by a GET.');
        } catch (MethodNotAllowedHttpException) {
        }

        $this->request('POST')->setBodyParams(['issueId' => $issue->id]);
        try {
            $this->investigationsController()->runAction('start');
            self::fail('An investigation was started without a CSRF token.');
        } catch (BadRequestHttpException) {
        }

        // Refused by the control panel check itself, before CSRF or the method are considered.
        $this->request('GET', cp: false);
        try {
            $this->investigationsController()->runAction('start');
            self::fail('An investigation was started from the front end.');
        } catch (BadRequestHttpException) {
        }

        self::assertSame([], $this->investigations->forIssue($issue->id));
        self::assertSame(RecordingInvestigationsController::ALLOW_ANONYMOUS_NEVER, $this->investigationsController()->anonymousAccess());
    }

    public function testSomebodyWhoMayInvestigateStartsOneAndIsTakenToWhatItFound(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');
        $user = $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::INVESTIGATE_ISSUES]);
        $this->post(['issueId' => $issue->id, 'depth' => DiagnosticDepth::SHALLOW->value]);

        $response = $this->investigationsController()->runAction('start');
        $made = $this->investigations->forIssue($issue->id);

        self::assertCount(1, $made);
        self::assertSame(DiagnosticDepth::SHALLOW, $made[0]->depth);
        self::assertSame($user->id, $made[0]->startedBy);
        self::assertStringContainsString("web-doctor/issues/{$issue->id}/investigations/{$made[0]->id}", (string)$response->getHeaders()->get('location'));
    }

    public function testARefusalIsExplainedToWhoeverAskedForTheInvestigation(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');
        // Craft's own environment, which is not the one the issue was recorded under.
        $this->investigations->environment = null;
        $this->signIn(admin: true);
        $this->post(['issueId' => $issue->id]);

        $controller = $this->investigationsController();
        $controller->runAction('start');
        $flash = $controller->lastFlash();

        self::assertIsArray($flash, 'The refusal was not explained to anybody.');
        self::assertSame('fail', $flash['level']);
        self::assertStringContainsString($this->environment, $flash['message']);
        self::assertSame([], $this->investigations->forIssue($issue->id));
    }

    public function testAnInvestigationIsReachableOnlyThroughTheIssueItBelongsTo(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');
        $other = $this->raise('tests.other', DiagnosticCategory::QUEUE);
        $investigation = $this->investigations->investigate($issue->id);
        $this->signIn(admin: true);
        $this->request('GET');

        $this->expectException(NotFoundHttpException::class);
        $this->investigationsController()->runAction('detail', ['issueId' => $other->id, 'investigationId' => $investigation->id]);
    }

    // What the pages show ----------------------------------------------------

    public function testTheIssuePageExplainsWhatAnInvestigationWouldDoAndOffersItOnlyToWhoeverMay(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::PASS);
        $issue = $this->raise('tests.origin');
        $reason = (string)$this->investigations->plan($issue)->reasonFor('tests.queue');

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->request('GET');
        $reader = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        // The reasons are for everybody who may read the issue, before anything has run.
        self::assertStringContainsString(htmlspecialchars($reason, ENT_QUOTES), $reader);
        self::assertStringNotContainsString('web-doctor/investigations/start', $reader);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::INVESTIGATE_ISSUES]);
        $investigator = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('web-doctor/investigations/start', $investigator);
    }

    public function testTheInvestigationPageShowsChecksSignalsEvidenceAndTimeline(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'The database refused the connection.', [
            new Evidence(type: EvidenceType::DATABASE_ERROR, label: 'Connection error', source: 'tests.origin', data: ['detail' => 'distinctive-value-7731', 'password' => 'hunter2']),
        ]);
        $this->check('tests.connection', DiagnosticCategory::DATABASE, static function(): DiagnosticResult {
            throw new RuntimeException('Broke.');
        });
        $this->check('tests.queue', DiagnosticCategory::QUEUE, DiagnosticStatus::WARNING, 'Jobs are piling up.');
        $issue = $this->raise('tests.origin');
        $nearby = $this->raise('tests.otherQueue', DiagnosticCategory::QUEUE);
        $investigation = $this->investigations->investigate($issue->id);
        $params = ['issueId' => $issue->id, 'investigationId' => $investigation->id];

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE]);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', $params);

        foreach ([
            InvestigationStatus::PARTIAL->label(),
            'The check that raised this issue ran again and still reports it.',
            'Raised this issue',
            'Checks that could not be completed',
            'Jobs are piling up.',
            "issues/{$nearby->id}",
            InvestigationStepType::STARTED->label(),
            InvestigationStepType::RELATED_ISSUE->label(),
            InvestigationStepType::FINISHED->label(),
            'distinctive-value-7731',
            'none is established unless it is marked Confirmed',
            // Nothing Web Doctor knows of explains checks it has never heard of, and it says so
            // rather than leaving the section empty.
            'None of Web Doctor’s known causes fits what this investigation found.',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }

        // Withheld values are marked, never printed as the raw marker or the value itself.
        self::assertStringNotContainsString(Redaction::REDACTED, $html);
        self::assertStringNotContainsString('hunter2', $html);

        // Without "View evidence", what kind of evidence was found and nothing it contains.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $withheld = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', $params);

        self::assertStringContainsString('Connection error', $withheld);
        self::assertStringNotContainsString('distinctive-value-7731', $withheld);
        self::assertStringContainsString('“View evidence” permission', $withheld);
    }

    // Weighing causes ---------------------------------------------------------

    /**
     * The database error, the character-set evidence and the failed write the checks recorded are
     * weighed together, and the cause they amount to is kept with the way back to each of them.
     */
    public function testAnInvestigationWeighsWhatItFoundAndKeepsEachCauseWithItsEvidence(): void
    {
        $refused = "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F\\x98\\x80' for column 'title' at row 1";

        $this->check('database.charset', DiagnosticCategory::DATABASE, DiagnosticStatus::WARNING, 'Craft’s element index table does not accept four-byte characters such as emoji.', [
            new Evidence(type: EvidenceType::DATABASE, label: 'Character set', source: 'database.charset', data: [
                'configuredCharset' => 'utf8mb4',
                'databaseCharset' => 'utf8',
                'sampledTable' => 'elements_sites',
                'sampledTableAcceptsMb4' => false,
                'columnsInspected' => false,
            ], reference: 'elements_sites'),
        ]);
        $this->check('tests.writer', DiagnosticCategory::DATABASE, static function() use ($refused): DiagnosticResult {
            throw new DbException($refused . ' using password=hunter2');
        });
        $this->check('queue.failedJobs', DiagnosticCategory::QUEUE, DiagnosticStatus::FAIL, 'Queue jobs have failed: 2.', [
            new Evidence(type: EvidenceType::QUEUE, label: 'Failed jobs', source: 'queue.failedJobs', data: ['failed' => 2]),
            new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Updating search indexes', source: 'queue.failedJobs', data: [
                'description' => 'Updating search indexes',
                'occurrences' => 2,
                'error' => $refused,
            ]),
        ]);
        $issue = $this->raise('database.charset');

        $investigation = $this->investigations->investigate($issue->id);
        $causes = (new RootCauses())->forInvestigation($investigation->id);
        $diagnosed = $this->stepsOf($this->investigations->steps($investigation->id), InvestigationStepType::DIAGNOSED);
        $queue = $this->issueFor('queue.failedJobs');

        // Weighed last, from everything the checks recorded, and said in the timeline.
        self::assertCount(1, $diagnosed);
        self::assertSame($causes[0]->title, $diagnosed[0]->summary);
        self::assertStringContainsString(sprintf('1 of the %d known causes fits what was found.', count(RootCauseRules::all())), (string)$diagnosed[0]->note);

        // Probable, on three separate signals, and nothing against it.
        self::assertSame('database.characterSet', $causes[0]->ruleId);
        self::assertSame(Confidence::LIKELY, $causes[0]->confidence);
        self::assertSame(['mismatch', 'refusedCharacters', 'failedWrite'], array_map(static fn(ConditionOutcome $c): string => $c->id, $causes[0]->supporting()));
        self::assertSame([], $causes[0]->conflicting());
        // The failed jobs are an issue of their own, raised by this investigation, that the cause
        // would explain too.
        self::assertSame([$queue->id], array_column($causes[0]->relatedIssues, 'id'));

        // Traceable: the error it quotes is the group the investigation counted it against.
        $error = $causes[0]->supporting()[1]->observations[0];
        $group = ErrorGroupRecord::findOne(['fingerprint' => $error->errorFingerprint]);
        self::assertInstanceOf(ErrorGroupRecord::class, $group);

        self::assertStringNotContainsString('hunter2', $this->storedRows($investigation));

        // What each reader sees: the cause and what it rests on for anybody who may read the
        // investigation, and what the facts contain only for somebody who may see evidence.
        $params = ['issueId' => $issue->id, 'investigationId' => $investigation->id];
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE]);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', $params);

        foreach ([
            'id="causes"',
            htmlspecialchars($causes[0]->title, ENT_QUOTES),
            Confidence::LIKELY->label(),
            'Contradicting evidence',
            'Recommended next investigation',
            'web-doctor/errors/' . $group->id,
            'web-doctor/issues/' . $queue->id,
            '#evidence-database.charset',
            'Incorrect string value',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }

        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString(Redaction::REDACTED, $html);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $withheld = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', $params);

        self::assertStringContainsString(htmlspecialchars($causes[0]->title, ENT_QUOTES), $withheld);
        self::assertStringContainsString('Failed job “Updating search indexes”', $withheld);
        self::assertStringNotContainsString('Incorrect string value', $withheld);
        self::assertStringContainsString('What those facts contain needs the “View evidence” permission.', $withheld);

        // The issue page names what each investigation found most likely.
        $issuePage = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);
        self::assertStringContainsString(htmlspecialchars($causes[0]->title, ENT_QUOTES), $issuePage);
        // Craft adds the site to a control panel URL once there is more than one, ahead of the fragment.
        self::assertMatchesRegularExpression("#investigations/{$investigation->id}(\\?[^\"\\#]*)?\\#causes\"#", $issuePage);

        // A cause is what its investigation concluded, and goes with it.
        IssueRecord::deleteAll(['id' => $issue->id]);
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $investigation->id])->count());
    }

    /**
     * The causes an investigation held likely or firmer are acted on, first and in their own words,
     * on its page and on its issue's — and only the newest investigation that weighed anything
     * speaks for the issue.
     */
    public function testRecommendationsActOnTheCausesTheNewestInvestigationWeighed(): void
    {
        $this->charsetScenario();
        $issue = $this->raise('database.charset');
        $investigation = $this->investigations->investigate($issue->id);

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        $positions = array_map(static fn(string $title): int|false => strpos($html, $title), [
            'id="recommendations"',
            'Convert the database’s character set',
            'Convert the database to utf8mb4',
            'Fix what the repeatedly failing job names before retrying it',
        ]);

        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'The issue investigated comes first, and advice for its cause before advice for its finding.');
        self::assertSame(1, substr_count($html, 'href="#cause-0"'), 'Only the problem the cause was weighed for is advised on for it.');

        // The issue page acts on the same cause, linked to where it was weighed.
        $issuePage = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);
        self::assertStringContainsString('Convert the database’s character set', $issuePage);
        self::assertMatchesRegularExpression("#investigations/{$investigation->id}(\\?[^\"\\#]*)?\\#cause-0\"#", $issuePage);

        $recommendations = $this->plugin->getRecommendations();
        $weighed = static fn(): array => array_map(static fn(RootCause $c): array => [$c->ruleId, $c->investigationId], $recommendations->causesFor($issue));

        self::assertSame([['database.characterSet', $investigation->id]], $weighed());

        // A newer investigation that stopped outright weighed nothing, so the earlier answer stands.
        $later = $this->investigations->investigate($issue->id);
        InvestigationRecord::updateAll(['status' => InvestigationStatus::FAILED->value], ['id' => $later->id]);
        RootCauseRecord::deleteAll(['investigationId' => $later->id]);
        self::assertSame([['database.characterSet', $investigation->id]], $weighed());

        // Nor does one still running.
        InvestigationRecord::updateAll(['status' => InvestigationStatus::RUNNING->value], ['id' => $later->id]);
        self::assertSame([['database.characterSet', $investigation->id]], $weighed());

        // Another issue's investigations are never this one's.
        $this->check('tests.other', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        self::assertSame([], $recommendations->causesFor($this->raise('tests.other')));

        // A stored cause whose confidence cannot be read is held at the least there is, and so is
        // not acted on — however firmly it once was.
        RootCauseRecord::updateAll(['confidence' => 'certain'], ['investigationId' => $investigation->id]);
        $causes = $recommendations->causesFor($issue);
        self::assertSame(Confidence::POSSIBLE, $causes[0]->confidence);
        self::assertNotContains('database.convertCharacterSet', array_map(
            static fn($r): string => $r->ruleId,
            $recommendations->recommend(\Tahadudhiya\WebDoctor\models\RecommendationCase::fromIssue($issue, [self::charsetEvidence()], $causes))->recommendations,
        ));

        // One that finished and found no cause is the newer answer, and replaces it.
        InvestigationRecord::updateAll(['status' => InvestigationStatus::COMPLETED->value], ['id' => $later->id]);
        self::assertSame([], $weighed());
    }

    /**
     * Showing recommendations reads; it never writes, runs a check or weighs anything. And the
     * issue page reads its investigations once, not once for the list and again for the advice.
     */
    public function testShowingRecommendationsIsReadOnlyAndReadsInvestigationsOnce(): void
    {
        $this->charsetScenario();
        $issue = $this->raise('database.charset');
        $investigation = $this->investigations->investigate($issue->id);
        $runs = array_map(static fn(\Tahadudhiya\WebDoctor\base\DiagnosticInterface $d): int => $d instanceof TestDiagnostic ? $d->runs : -1, $this->registry->all());

        $reader = [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES];
        $this->signIn(admin: false, permissions: $reader);
        $this->request('GET');
        $before = WebDoctorTables::snapshot();

        $html = '';
        $statements = $this->statementsDuring(function() use ($issue, $investigation, &$html): void {
            $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);
            $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);
        });

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertSame($runs, array_map(static fn(\Tahadudhiya\WebDoctor\base\DiagnosticInterface $d): int => $d instanceof TestDiagnostic ? $d->runs : -1, $this->registry->all()));
        self::assertSame([], array_values(array_filter($statements, static fn(string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $sql) === 1)));

        $investigationReads = array_filter($statements, static fn(string $sql): bool => preg_match('/FROM [`"]?[a-z_]*webdoctor_investigations[`"]?\s/i', $sql) === 1);
        // One on the investigation page (the investigation itself), one on the issue page.
        self::assertCount(2, $investigationReads, implode("\n", $investigationReads));

        // What a fact contains is evidence, on the investigation page as everywhere else.
        self::assertStringContainsString('Character set, recorded by Check database.charset', $html);
        self::assertStringNotContainsString('sampledTableAcceptsMb4: false', $html);
        $this->signIn(admin: false, permissions: [...$reader, Permissions::VIEW_EVIDENCE]);
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);
        self::assertStringContainsString('sampledTableAcceptsMb4: false', $html);

        // A step that cannot be read as a check's finding is advised on for nothing, and costs the
        // page nothing.
        InvestigationStepRecord::updateAll(['status' => 'broken'], ['investigationId' => $investigation->id, 'diagnosticId' => 'queue.failedJobs']);
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);
        self::assertStringNotContainsString('Fix what the repeatedly failing job names before retrying it', $html);
        self::assertStringContainsString('Convert the database to utf8mb4', $html);
    }

    public function testALeadingCauseThatCannotBeReadIsSaidRatherThanShownAsNone(): void
    {
        $this->charsetScenario();
        $issue = $this->raise('database.charset');
        $this->investigations->investigate($issue->id);
        $this->plugin->set('rootCauses', new class() extends RootCauses {
            public function leading(array $investigationIds): array
            {
                throw new RuntimeException('The causes table went away.');
            }
        });

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('Could not be read', $html);
        self::assertStringNotContainsString('<span class="light">None</span>', $html);
    }

    /**
     * A request killed outright leaves its investigation `running` for good. Long past any request's
     * time it is said to have stopped, on every page it appears on, and nothing is rewritten.
     */
    public function testAnInvestigationWhoseRequestDiedIsSaidToHaveStoppedNotToBeRunning(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');
        $investigation = $this->investigations->investigate($issue->id);
        $this->signIn(admin: true);
        $this->request('GET');

        $page = fn(): array => [
            $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]),
            $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]),
        ];

        InvestigationRecord::updateAll(['status' => InvestigationStatus::RUNNING->value, 'startedAt' => Db::prepareDateForDb(new \DateTime('-10 seconds'))], ['id' => $investigation->id]);
        foreach ($page() as $html) {
            self::assertStringContainsString('Running', $html);
            self::assertStringNotContainsString('Stopped without an ending', $html);
        }

        InvestigationRecord::updateAll(['startedAt' => Db::prepareDateForDb(new \DateTime('-2 hours'))], ['id' => $investigation->id]);
        foreach ($page() as $html) {
            self::assertStringContainsString('Stopped without an ending', $html);
        }

        self::assertSame(InvestigationStatus::RUNNING->value, InvestigationRecord::findOne($investigation->id)?->status);
    }

    public function testARuleThatBreaksOnTheInvestigationPageIsSaidAndCostsNothingElse(): void
    {
        $this->charsetScenario();
        $issue = $this->raise('database.charset');
        $investigation = $this->investigations->investigate($issue->id);
        $this->plugin->set('recommendations', new BreakingRuleRecommendations(['check' => 'database.charset']));

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        // The charset finding's own advice waits on the rule that broke; the advice for the cause
        // weighed for it, and for the other checks' findings, does not.
        self::assertStringNotContainsString('Convert the database to utf8mb4', $html);
        self::assertStringContainsString('Convert the database’s character set', $html);
        self::assertStringContainsString('Fix what the repeatedly failing job names before retrying it', $html);
        self::assertStringContainsString('could not be worked out', $html);
        self::assertStringNotContainsString('hunter2', $html);
    }

    // The finishing transaction --------------------------------------------

    /**
     * The causes, the step reporting them, the final state and the ending are committed together
     * or not at all — shown by making the ending fail after the causes were written inside it, then
     * reading the database directly.
     */
    public function testCausesAreKeptOnlyIfTheInvestigationFinishesAndARetryKeepsExactlyOneSet(): void
    {
        $this->charsetScenario();
        $issue = $this->raise('database.charset');

        // An earlier investigation that finished, whose causes must survive what follows.
        $earlier = $this->investigations->investigate($issue->id);
        $earlierCauses = $this->causeRows($earlier->id);
        self::assertNotSame([], $earlierCauses);

        $failing = $this->failingWhen(static fn(InvestigationStepType $type): bool => $type === InvestigationStepType::FINISHED);
        $failed = $failing->investigate($issue->id);
        $steps = $failing->steps($failed->id);

        // It says it stopped, and nothing it concluded inside the failed finish is left behind.
        self::assertSame(InvestigationStatus::PARTIAL, $failed->status);
        self::assertStringContainsString('RuntimeException', (string)$failed->failure);
        self::assertSame([], $this->causeRows($failed->id));
        self::assertSame([], $this->stepsOf($steps, InvestigationStepType::DIAGNOSED));
        self::assertSame(InvestigationStepType::FAILED, end($steps)->type);
        // And the timeline carries on from where the rolled-back steps would have been.
        self::assertSame(range(0, count($steps) - 1), array_map(static fn(InvestigationStep $s): int => $s->position, $steps));

        // Investigated again, successfully: one set of causes, on that investigation alone.
        $retry = $this->investigations->investigate($issue->id);
        $retrySteps = $this->investigations->steps($retry->id);

        self::assertSame(InvestigationStatus::PARTIAL, $retry->status, 'The scenario includes a check that breaks, so a whole run is partial.');
        self::assertNull($retry->failure);
        self::assertSame(InvestigationStepType::FINISHED, end($retrySteps)->type);
        self::assertCount(1, $this->stepsOf($retrySteps, InvestigationStepType::DIAGNOSED));
        self::assertSame(count($earlierCauses), count($this->causeRows($retry->id)));
        self::assertSame(range(0, count($earlierCauses) - 1), array_map(static fn(array $row): int => (int)$row['position'], $this->causeRows($retry->id)));
        self::assertSame(0, (int)RootCauseRecord::find()->where(['not', ['investigationId' => InvestigationRecord::find()->select(['id'])]])->count(), 'A cause outlived its investigation.');

        // The finished investigation's causes are exactly as they were.
        self::assertSame($earlierCauses, $this->causeRows($earlier->id));

        foreach ($this->causeRows($retry->id) as $row) {
            foreach (['supporting', 'conflicting', 'unmet', 'reasoning', 'relatedIssues', 'nextSteps'] as $column) {
                if ($row[$column] !== null) {
                    self::assertIsArray(json_decode((string)$row[$column], true), "$column is not valid JSON.");
                }
            }
        }
    }

    /**
     * What a cause requires is kept as evidence for it, as a requirement: it survives the database
     * as itself, with the facts that met it, and never counts as a signal for the confidence.
     */
    public function testACauseReadBackIsTheCauseThatWasWeighedRequirementsAndAll(): void
    {
        $this->charsetScenario();
        $issue = $this->raise('database.charset');
        $investigation = $this->investigations->investigate($issue->id);

        [$stored] = (new RootCauses())->forInvestigation($investigation->id);
        $required = $stored->supporting()[0];

        self::assertSame('mismatch', $required->id);
        self::assertSame(\Tahadudhiya\WebDoctor\enums\ConditionRole::REQUIRED, $required->role);
        self::assertCount(2, $required->observations);
        self::assertSame(
            ['databaseCharset: utf8; configuredCharset: utf8mb4', 'sampledTable: elements_sites; sampledTableAcceptsMb4: false'],
            array_map(static fn($o): ?string => $o->detail, $required->observations),
        );
        // The digest is still the digest of the evidence the check recorded.
        self::assertSame(self::charsetEvidence()->digest(), $required->observations[0]->evidenceDigest);
        self::assertSame($required->observations[0]->key(), \Tahadudhiya\WebDoctor\models\Observation::fromArray($required->observations[0]->jsonSerialize())->key());
        // Two supporting signals found; the requirement is not a third.
        self::assertSame(2, $stored->found());
        self::assertSame($stored->confidence, RootCause::confidenceFor($stored->conditions, Confidence::LIKELY));

        // Read back from the raw row a second time, it is the same cause, byte for byte.
        $record = new RootCauseRecord();
        $record->setAttributes(RootCauseRecord::find()->where(['investigationId' => $investigation->id, 'position' => 0])->asArray()->one(), false);
        self::assertSame(Evidence::encode($stored), Evidence::encode(RootCause::fromRecord($record)));

        // A row claiming more than its rule allows is data saying something the rule never could:
        // the character-set cause reads back held to its ceiling however the row was altered.
        self::assertSame(Confidence::LIKELY, RootCauseRules::all()[array_search('database.characterSet', array_map(static fn($r): string => $r->id, RootCauseRules::all()), true)]->ceiling);
        $record->confidence = Confidence::CONFIRMED->value;
        self::assertSame(Confidence::LIKELY, RootCause::fromRecord($record)->confidence);
        // Nor is it confirmed under a rule whose ceiling admits it but which has nothing that can
        // establish it, nor under a rule removed since, without a stored outcome confirming it.
        foreach (['database.serverUnreachable', 'tests.removedRule'] as $ruleId) {
            $record->ruleId = $ruleId;
            self::assertSame(Confidence::HIGH, RootCause::fromRecord($record)->confidence, $ruleId);
        }
        // A removed rule otherwise keeps what it concluded.
        $record->confidence = Confidence::HIGH->value;
        self::assertSame(Confidence::HIGH, RootCause::fromRecord($record)->confidence);

        // And the page has what it needs to show the requirement as evidence.
        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);
        self::assertStringContainsString('wd-mark--role-required', $html);
        self::assertStringContainsString('sampledTableAcceptsMb4: false', $html);
    }

    public function testWeighingThatBreaksIsSaidInTheTimelineAndCostsTheInvestigationNothing(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $this->investigations->rootCauses = new class() extends RootCauses {
            public function analyse(CorrelationCase $case, ?array $rules = null): RootCauseAnalysis
            {
                throw new RuntimeException('Weighing refused: password=hunter2');
            }
        };

        $investigation = $this->investigations->investigate($issue->id);
        $diagnosed = $this->stepsOf($this->investigations->steps($investigation->id), InvestigationStepType::DIAGNOSED);

        // Every check was answered, so the investigation is whole; only the weighing is missing.
        self::assertSame(InvestigationStatus::COMPLETED, $investigation->status);
        self::assertCount(1, $diagnosed);
        self::assertStringContainsString('could not be weighed', (string)$diagnosed[0]->note);
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $investigation->id])->count());
        self::assertStringNotContainsString('hunter2', $this->storedRows($investigation));

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        self::assertStringContainsString('could not be weighed against the known causes', $html);
    }

    public function testAnInvestigationThatStopsBeforeWeighingSaysSoRatherThanOfferingNoCause(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');

        $service = $this->failingWhen(static fn(InvestigationStepType $type): bool => $type === InvestigationStepType::RECONCILED);
        $investigation = $service->investigate($issue->id);

        self::assertSame([], $this->stepsOf($service->steps($investigation->id), InvestigationStepType::DIAGNOSED));

        $this->signIn(admin: true);
        $this->request('GET');
        $this->plugin->set('investigations', $service);
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);

        self::assertStringContainsString('The causes were not weighed, because the investigation stopped before it got that far.', $html);
        self::assertStringNotContainsString('None of Web Doctor’s known causes fits', $html);
    }

    // Stored causes that cannot be read ---------------------------------------

    public function testACauseStoredMalformedIsReadAsFarAsItCanBeAndNeverTakesAPageDown(): void
    {
        $this->check('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin');
        $investigation = $this->investigations->investigate($issue->id);

        $rows = [
            // Nothing but what the table insists on.
            ['position' => 0, 'ruleId' => 'tests.bare', 'confidence' => 'possible', 'title' => 'A bare cause'],
            // Every column something other than what it should be.
            ['position' => 1, 'ruleId' => 'tests.garbled', 'confidence' => 'certain', 'title' => 'A garbled cause',
                'supporting' => '{not json', 'conflicting' => '"a string"', 'unmet' => '[1, null, "x"]', 'reasoning' => '[["nested"], 7]',
                'relatedIssues' => '[{"id": "x"}, {"id": 999999999, "title": ["not", "text"]}]', 'nextSteps' => '{"a": {"b": 1}}', ],
            // Conditions of an older or unknown shape, observations missing everything optional.
            ['position' => 2, 'ruleId' => 'tests.legacy', 'confidence' => 'likely', 'title' => 'A legacy cause',
                'supporting' => Evidence::encode([['id' => ['x'], 'role' => 'decisive', 'description' => ['x'], 'observations' => 'none'], ['observations' => [['kind' => 'rumour'], 'x', ['label' => ['x'], 'issueId' => 'abc', 'at' => 'not a date']]]]),
                'conflicting' => Evidence::encode([['role' => 'unheard-of', 'description' => 'Against', 'observations' => [['kind' => 'error', 'label' => 'Stale', 'errorFingerprint' => str_repeat('0', 64)]]]]), ],
        ];

        foreach ($rows as $row) {
            $record = new RootCauseRecord();
            $record->setAttributes(['investigationId' => $investigation->id] + $row, false);
            self::assertTrue($record->save(false));
        }

        $causes = (new RootCauses())->forInvestigation($investigation->id);

        self::assertCount(3, $causes);
        // A confidence this version does not have reads as the least firm one.
        self::assertSame(Confidence::POSSIBLE, $causes[1]->confidence);
        self::assertSame([], $causes[1]->supporting());
        self::assertSame([['id' => 999999999, 'title' => '', 'severity' => '']], $causes[1]->relatedIssues);
        // A condition kept as evidence against the cause reads back as evidence against it.
        self::assertSame(\Tahadudhiya\WebDoctor\enums\ConditionRole::CONTRADICTING, $causes[2]->conflicting()[0]->role);

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id]);
        $issuePage = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        foreach (['A bare cause', 'A garbled cause', 'A legacy cause'] as $title) {
            self::assertStringContainsString($title, $html);
        }

        self::assertStringContainsString('A bare cause', $issuePage);

        foreach (['{not json', 'Array to string', 'Exception', 'Stack trace'] as $leak) {
            self::assertStringNotContainsString($leak, $html);
        }
    }

    // Isolation --------------------------------------------------------------

    /**
     * A cause is weighed from this environment and this site. Problems elsewhere — another site,
     * another environment, a site since deleted — and ones somebody set aside cannot strengthen it,
     * even when they name the same plugin and appeared at the same moment.
     */
    public function testNothingFromAnotherPlaceOrSetAsideCanStrengthenACause(): void
    {
        $this->check('tests.origin', DiagnosticCategory::PLUGINS, DiagnosticStatus::FAIL);
        $issue = $this->raise('tests.origin', DiagnosticCategory::PLUGINS, affectedPlugin: 'seo');

        $this->raise('tests.onSiteA', DiagnosticCategory::PLUGINS, siteId: $this->primarySiteId(), affectedPlugin: 'seo');
        $this->raise('tests.elsewhere', DiagnosticCategory::PLUGINS, affectedPlugin: 'seo', environment: $this->environment . '-other');
        $deleted = $this->raise('tests.deletedSite', DiagnosticCategory::PLUGINS, siteId: $this->primarySiteId(), affectedPlugin: 'seo');
        IssueRecord::updateAll(['siteId' => null, 'siteName' => 'Retired site'], ['id' => $deleted->id]);
        $ignored = $this->raise('tests.ignored', DiagnosticCategory::PLUGINS, affectedPlugin: 'seo');
        $this->issues->transition($ignored->id, IssueStatus::IGNORED, 'Known and accepted.');

        $offered = fn(Investigation $i): array => array_map(static fn(RootCause $c): string => $c->ruleId, (new RootCauses())->forInvestigation($i->id));

        self::assertNotContains('place.shared', $offered($this->investigations->investigate($issue->id)));

        // The one problem that is here, open and installation-wide, is what it rests on — alone.
        $here = $this->raise('tests.here', DiagnosticCategory::PLUGINS, affectedPlugin: 'seo');
        $investigation = $this->investigations->investigate($issue->id);
        $cause = array_values(array_filter((new RootCauses())->forInvestigation($investigation->id), static fn(RootCause $c): bool => $c->ruleId === 'place.shared'))[0];

        self::assertSame([$here->id], array_map(static fn($o): ?int => $o->issueId, $cause->supporting()[0]->observations));
        self::assertSame([$here->id], array_column($cause->relatedIssues, 'id'));
    }

    /**
     * An error or a failed job recorded somewhere else — even the very same error — is not
     * something this investigation ran into, and cannot satisfy a cause here.
     */
    public function testErrorsAndFailedJobsRecordedElsewhereAreNotWeighedHere(): void
    {
        $exception = new RuntimeException('Redis connection refused at cache:6379');
        $elsewhere = new DiagnosticContext(environment: $this->environment . '-other');
        $now = new DateTimeImmutable();
        $refused = "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0' for column 'title' at row 1";

        // Elsewhere: the origin and another check both ran into it, and a job failed writing.
        $run = new DiagnosticRun(context: $elsewhere, results: array_map(static fn(DiagnosticResult $r): DiagnosticResult => $r->withExecution($elsewhere, $now, $now, 1.0), [
            new DiagnosticResult(diagnosticId: 'database.charset', name: 'Charset', category: DiagnosticCategory::DATABASE, status: DiagnosticStatus::WARNING, evidence: [Evidence::fromThrowable($exception, 'database.charset')]),
            new DiagnosticResult(diagnosticId: 'tests.sessions', name: 'Sessions', category: DiagnosticCategory::DATABASE, status: DiagnosticStatus::ERROR, evidence: [Evidence::fromThrowable($exception, 'tests.sessions')]),
            new DiagnosticResult(diagnosticId: 'queue.failedJobs', name: 'Jobs', category: DiagnosticCategory::QUEUE, status: DiagnosticStatus::FAIL, evidence: [
                new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Saving entry', source: 'queue.failedJobs', data: ['description' => 'Saving entry', 'occurrences' => 3, 'error' => $refused]),
            ]),
        ]), startedAt: $now, finishedAt: $now, durationMs: 1.0);
        $this->issues->reconcile($run);
        (new Errors(['issues' => $this->issues]))->record($run);

        // Here: the charset check alone, recording the same exception.
        $this->check('database.charset', DiagnosticCategory::DATABASE, DiagnosticStatus::WARNING, 'Mismatch.', [self::charsetEvidence(), Evidence::fromThrowable($exception, 'database.charset')]);
        $issue = $this->raise('database.charset');
        $investigation = $this->investigations->investigate($issue->id);
        $causes = (new RootCauses())->forInvestigation($investigation->id);

        self::assertNotContains('error.shared', array_map(static fn(RootCause $c): string => $c->ruleId, $causes));
        $charset = array_values(array_filter($causes, static fn(RootCause $c): bool => $c->ruleId === 'database.characterSet'))[0];
        self::assertSame(Confidence::POSSIBLE, $charset->confidence);
        self::assertContains('failedWrite', array_map(static fn(ConditionOutcome $c): string => $c->id, $charset->unmet()));
    }

    // Secrets and cost -------------------------------------------------------

    /**
     * Credentials planted everywhere a cause can quote from — exception messages, check names and
     * summaries, the plugin a finding names, evidence labels and values nested inside arrays, a
     * failed job's error, and an issue's title written straight to the table — reach no row any
     * part of this stores and no page it renders.
     */
    public function testNoCredentialSurvivesIntoAnyCauseTableOrPage(): void
    {
        $secrets = [
            'hunter2-assign' => 'password=hunter2-assign',
            'apiKeyValue77' => 'api_key=apiKeyValue77',
            'secretValue77' => 'secret=secretValue77',
            'tokenValue77' => 'token=tokenValue77',
            'accessTok77' => 'access_token=accessTok77',
            'bearerTok77' => 'Authorization: Bearer bearerTok77',
            'basicAuth77' => 'Authorization: Basic basicAuth77',
            'jsonPass77' => '{"password":"jsonPass77"}',
            'escapedPass77' => '{\"password\":\"escapedPass77\"}',
            'phpArrayPass77' => "'password' => 'phpArrayPass77'",
            'dsnSecret77' => 'mysql://craft:dsnSecret77@db:3306/craft',
            'urlSecret77' => 'https://admin:urlSecret77@example.com/hook',
            'dbPassValue77' => 'DB_PASSWORD=dbPassValue77',
        ];
        $text = implode(' | ', $secrets);
        $refused = "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0' for column 'title' ";

        $origin = $this->check('database.charset', DiagnosticCategory::DATABASE, DiagnosticStatus::WARNING, 'Mismatch ' . $text, [
            new Evidence(type: EvidenceType::DATABASE, label: 'Character set ' . $secrets['hunter2-assign'], source: 'database.charset', data: [
                'configuredCharset' => 'utf8mb4',
                'databaseCharset' => 'utf8 ' . $text,
                'sampledTable' => 'elements_sites',
                'sampledTableAcceptsMb4' => false,
            ]),
        ]);
        $origin->diagnosticName = 'Charset ' . $secrets['tokenValue77'];
        $this->check('tests.writer', DiagnosticCategory::DATABASE, static function() use ($refused, $text): DiagnosticResult {
            throw new DbException($refused . $text);
        });
        $this->check('queue.failedJobs', DiagnosticCategory::QUEUE, DiagnosticStatus::FAIL, 'Jobs failed ' . $text, [
            new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Job ' . $secrets['apiKeyValue77'], source: 'queue.failedJobs', data: ['description' => 'Job', 'occurrences' => 2, 'error' => $refused . $text]),
        ]);
        $this->check('database.migrations', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL, 'Pending ' . $text, [
            new Evidence(type: EvidenceType::DATABASE, label: 'Migration state', source: 'database.migrations', data: [
                'schemaVersionCompatible' => true,
                'pendingMigrations' => ['craft', ['password' => 'nestedPass77', 'deeper' => ['api_key' => 'nestedKey77']], $secrets['dsnSecret77']],
            ]),
        ]);
        $issue = $this->raise('database.charset', affectedPlugin: 'seo ' . $secrets['secretValue77']);
        // Open nearby, first seen together, its title written straight to the table.
        $nearby = $this->raise('tests.nearbyQueue', DiagnosticCategory::QUEUE);
        IssueRecord::updateAll(['title' => mb_substr('Nearby ' . $secrets['bearerTok77'] . ' ' . $secrets['dsnSecret77'] . ' ' . $secrets['jsonPass77'] . ' ' . $secrets['urlSecret77'], 0, 255)], ['id' => $nearby->id]);

        $investigation = $this->investigations->investigate($issue->id);
        $causes = (new RootCauses())->forInvestigation($investigation->id);

        self::assertContains('deployment.incomplete', array_map(static fn(RootCause $c): string => $c->ruleId, $causes));
        self::assertContains('database.characterSet', array_map(static fn(RootCause $c): string => $c->ruleId, $causes));

        $stored = $this->storedRows($investigation) . json_encode([
            IssueRecord::find()->where(['environment' => $this->environment])->andWhere(['not', ['id' => $nearby->id]])->asArray()->all(),
            EvidenceRecord::find()->where(['environment' => $this->environment])->asArray()->all(),
            ErrorGroupRecord::find()->where(['environment' => $this->environment])->asArray()->all(),
        ]);

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->investigationsController(), 'detail', 'web-doctor/_investigations/_investigation', ['issueId' => $issue->id, 'investigationId' => $investigation->id])
            . $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        foreach ([...array_keys($secrets), 'nestedPass77', 'nestedKey77'] as $secret) {
            self::assertStringNotContainsString($secret, $stored, "“{$secret}” reached a stored row.");
            self::assertStringNotContainsString($secret, $html, "“{$secret}” reached a page.");
        }

        self::assertStringNotContainsString(Redaction::REDACTED, $html);
    }

    public function testWeighingAndReadingCausesCostAFixedNumberOfQueries(): void
    {
        $this->charsetScenario();
        $issue = $this->raise('database.charset');

        $statements = $this->statementsDuring(fn() => $this->investigations->investigate($issue->id));
        $onIssues = array_values(array_filter($statements, static fn(string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, 'webdoctor_issues') && str_contains($sql, 'IN (')));

        // The issues the findings landed on are read once for the whole case, not once per check.
        self::assertCount(1, array_filter($onIssues, static fn(string $sql): bool => !str_contains($sql, 'fingerprint')));

        foreach (range(1, 3) as $ignored) {
            $this->investigations->investigate($issue->id);
        }

        $ids = array_map(static fn(Investigation $i): int => $i->id, $this->investigations->forIssue($issue->id));
        $reads = $this->statementsDuring(static fn() => (new RootCauses())->leading($ids));

        self::assertCount(4, (new RootCauses())->leading($ids));
        self::assertCount(1, $reads, 'The leading causes of an issue\'s investigations are read in one query.');
    }

    // Helpers ----------------------------------------------------------------

    /**
     * The checks the task's example describes: a character-set mismatch, a database error refusing
     * a value for its characters, and a queue job failing the same way.
     */
    private function charsetScenario(): void
    {
        $refused = "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F\\x98\\x80' for column 'title' at row 1";

        $this->check('database.charset', DiagnosticCategory::DATABASE, DiagnosticStatus::WARNING, 'Craft’s element index table does not accept four-byte characters such as emoji.', [self::charsetEvidence()]);
        $this->check('tests.writer', DiagnosticCategory::DATABASE, static function() use ($refused): DiagnosticResult {
            throw new DbException($refused);
        });
        $this->check('queue.failedJobs', DiagnosticCategory::QUEUE, DiagnosticStatus::FAIL, 'Queue jobs have failed: 2.', [
            new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Updating search indexes', source: 'queue.failedJobs', data: ['description' => 'Updating search indexes', 'occurrences' => 2, 'error' => $refused]),
        ]);
    }

    private static function charsetEvidence(): Evidence
    {
        return new Evidence(type: EvidenceType::DATABASE, label: 'Character set', source: 'database.charset', data: [
            'configuredCharset' => 'utf8mb4',
            'databaseCharset' => 'utf8',
            'sampledTable' => 'elements_sites',
            'sampledTableAcceptsMb4' => false,
            'columnsInspected' => false,
        ], reference: 'elements_sites');
    }

    /**
     * An investigation's causes as the table holds them, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function causeRows(int $investigationId): array
    {
        return RootCauseRecord::find()
            ->select(['investigationId', 'position', 'ruleId', 'confidence', 'title', 'supporting', 'conflicting', 'unmet', 'reasoning', 'relatedIssues', 'nextSteps', 'uid'])
            ->where(['investigationId' => $investigationId])
            ->orderBy(['position' => SORT_ASC])
            ->asArray()
            ->all();
    }

    /**
     * The SQL run while the callback runs, read from Yii's own query profiling.
     *
     * @return list<string>
     */
    private function statementsDuring(Closure $callback): array
    {
        $db = Craft::$app->getDb();
        $originalLogger = \Yii::getLogger();
        $originalProfiling = $db->enableProfiling;
        $logger = new \yii\log\Logger();
        $logger->flushInterval = PHP_INT_MAX;

        \Yii::setLogger($logger);
        $db->enableProfiling = true;

        try {
            $callback();
        } finally {
            \Yii::setLogger($originalLogger);
            $db->enableProfiling = $originalProfiling;
        }

        return array_values(array_map(
            static fn(array $timing): string => (string)$timing['info'],
            $logger->getProfiling(['yii\db\Command::query', 'yii\db\Command::execute']),
        ));
    }

    /**
     * Registers a check whose outcome the test states.
     *
     * @param DiagnosticStatus|Closure(TestDiagnostic, DiagnosticContext): DiagnosticResult $outcome
     * @param list<Evidence> $evidence
     */
    private function check(string $id, DiagnosticCategory $category, DiagnosticStatus|Closure $outcome, string $summary = 'Something is wrong.', array $evidence = []): TestDiagnostic
    {
        $diagnostic = new TestDiagnostic();
        $diagnostic->diagnosticId = $id;
        $diagnostic->diagnosticName = "Check $id";
        $diagnostic->diagnosticCategory = $category;
        $diagnostic->handler = $outcome instanceof Closure ? $outcome : self::respond($outcome, $summary, $evidence);

        $this->registry->register($diagnostic);

        return $diagnostic;
    }

    /**
     * @param list<Evidence> $evidence
     * @return Closure(TestDiagnostic, DiagnosticContext): DiagnosticResult
     */
    private static function respond(DiagnosticStatus $status, string $summary = 'Something is wrong.', array $evidence = []): Closure
    {
        return static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: $status,
            summary: $summary,
            evidence: $evidence,
        );
    }

    /**
     * An issue raised the way a run would raise one.
     */
    private function raise(
        string $diagnosticId,
        DiagnosticCategory $category = DiagnosticCategory::DATABASE,
        ?int $siteId = null,
        ?string $affectedPlugin = null,
        Severity $severity = Severity::HIGH,
        ?string $environment = null,
    ): Issue {
        $context = new DiagnosticContext(siteId: $siteId, environment: $environment ?? $this->environment);
        $now = new DateTimeImmutable();
        $result = (new DiagnosticResult(
            diagnosticId: $diagnosticId,
            name: "Check $diagnosticId",
            category: $category,
            status: DiagnosticStatus::FAIL,
            summary: "$diagnosticId reports a problem.",
            severity: $severity,
            affectedPlugin: $affectedPlugin,
        ))->withExecution($context, $now, $now, 1.0);

        $this->issues->reconcile(new DiagnosticRun(context: $context, results: [$result], startedAt: $now, finishedAt: $now, durationMs: 1.0));
        $issue = $this->issues->getByFingerprint(Fingerprint::forResult($result, $context->environment, $siteId));

        self::assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    /**
     * The investigation service with one kind of step made to fail to save, as a database that
     * went away part-way would make it.
     *
     * @param Closure(InvestigationStepType): bool $when
     */
    private function failingWhen(Closure $when): Investigations
    {
        $config = [
            'registry' => $this->registry,
            'engine' => new DiagnosticEngine(['registry' => $this->registry]),
            'issues' => $this->issues,
            'errors' => new Errors(['issues' => $this->issues]),
            'rootCauses' => new RootCauses(),
            'environment' => $this->environment,
        ];

        return new class($when, $config) extends Investigations {
            /**
             * @param array<string, mixed> $config
             */
            public function __construct(private Closure $when, array $config)
            {
                parent::__construct($config);
            }

            protected function step(InvestigationRecord $investigation, int &$position, InvestigationStepType $type, DateTimeInterface $at, array $fields = []): void
            {
                if (($this->when)($type)) {
                    throw new RuntimeException('Step refused: SELECT * FROM users WHERE password=hunter2');
                }

                parent::step($investigation, $position, $type, $at, $fields);
            }
        };
    }

    private function reread(Issue $issue): Issue
    {
        $fresh = $this->issues->get($issue->id);

        self::assertInstanceOf(Issue::class, $fresh);

        return $fresh;
    }

    private function issueFor(string $diagnosticId): Issue
    {
        $record = IssueRecord::findOne(['diagnosticId' => $diagnosticId, 'environment' => $this->environment]);

        self::assertInstanceOf(IssueRecord::class, $record, "No issue was raised for $diagnosticId.");

        return Issue::fromRecord($record);
    }

    /**
     * @param list<InvestigationStep> $steps
     */
    private function stepFor(array $steps, string $diagnosticId): InvestigationStep
    {
        foreach ($steps as $step) {
            if ($step->type === InvestigationStepType::CHECKED && $step->diagnosticId === $diagnosticId) {
                return $step;
            }
        }

        self::fail("No step records $diagnosticId running.");
    }

    /**
     * @param list<InvestigationStep> $steps
     * @return list<InvestigationStep>
     */
    private function stepsOf(array $steps, InvestigationStepType $type): array
    {
        return array_values(array_filter($steps, static fn(InvestigationStep $s): bool => $s->type === $type));
    }

    /**
     * Everything an investigation wrote, as the database holds it.
     */
    private function storedRows(Investigation $investigation): string
    {
        return (string)json_encode([
            InvestigationRecord::find()->where(['id' => $investigation->id])->asArray()->all(),
            InvestigationStepRecord::find()->where(['investigationId' => $investigation->id])->asArray()->all(),
            RootCauseRecord::find()->where(['investigationId' => $investigation->id])->asArray()->all(),
        ]);
    }

    private function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * A request shaped the way Craft would see one; see IssueCenterTest for why the control
     * panel flag is stated outright.
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
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor/issues" : '/actions/web-doctor/investigations/start';
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

    /**
     * @param array<string, mixed> $params
     */
    private function post(array $params = []): WebRequest
    {
        $request = $this->request('POST');
        $request->setBodyParams($params + [$request->csrfParam => $request->getCsrfToken()]);

        return $request;
    }

    /**
     * @param string[] $permissions
     */
    private function signIn(bool $admin, array $permissions = []): User
    {
        $user = new TestUser();
        $user->id = 1;
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;

        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    private function investigationsController(): RecordingInvestigationsController
    {
        return new RecordingInvestigationsController('investigations', $this->plugin);
    }

    private function issuesController(): RecordingIssuesController
    {
        return new RecordingIssuesController('issues', $this->plugin);
    }

    /**
     * Renders what a controller actually produced, through the real template, leaving out only
     * Craft's own control panel shell, which asks for a session a console run has none of.
     *
     * @param array<string, mixed> $params
     */
    private function render(\craft\web\Controller $controller, string $action, string $template, array $params = []): string
    {
        $response = $controller->runAction($action, $params);

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return Craft::$app->getView()->renderTemplate($template, $behavior->variables, View::TEMPLATE_MODE_CP);
    }
}
