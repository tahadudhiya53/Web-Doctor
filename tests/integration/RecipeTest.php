<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Closure;
use Craft;
use craft\elements\User;
use craft\mail\transportadapters\Smtp;
use craft\models\MailSettings;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\diagnostics\email\MailerConfigurationDiagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\InvestigationStatus;
use Tahadudhiya\WebDoctor\enums\InvestigationStepType;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\DiagnosticMeta;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\investigations\RelatedArea;
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
use Tahadudhiya\WebDoctor\models\IssueReconciliation;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\models\RootCauseAnalysis;
use Tahadudhiya\WebDoctor\recipes\CoreRecipes;
use Tahadudhiya\WebDoctor\recipes\Recipe;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use Tahadudhiya\WebDoctor\records\InvestigationRecord;
use Tahadudhiya\WebDoctor\records\InvestigationStepRecord;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\records\RootCauseRecord;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\Errors;
use Tahadudhiya\WebDoctor\services\Investigations;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\Recipes;
use Tahadudhiya\WebDoctor\services\RootCauses;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingInvestigationsController;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingIssuesController;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingOverviewController;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingRecipesController;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\base\Event;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\Exception as DbException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

/**
 * The recipes, end to end: which checks each one runs and why, the registry other plugins add to,
 * what running one records and does to the Issue Center, which problem its causes are weighed for,
 * who may run or read one, and what the pages render.
 *
 * What each recipe looks at is asserted against the checks Web Doctor actually ships with, because
 * a recipe is only as good as its reach into the real registry. Running one uses a registry of the
 * test's own holding stand-ins under the shipped checks' IDs and categories, so what a recipe
 * reaches is exactly what the test states and nothing about the host installation decides the
 * answer.
 *
 * Every test runs under an environment name of its own and removes only that environment's rows.
 */
class RecipeTest extends TestCase
{
    private const ACCESS_CP = 'accessCp';

    private string $environment;
    private Diagnostics $registry;
    private Issues $issues;
    private Investigations $investigations;
    private WebDoctor $plugin;

    /** @var array<string, TestDiagnostic> The stand-ins, by the shipped ID each answers to. */
    private array $checks = [];

    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Craft::$app->getDb()->getTableSchema(InvestigationRecord::TABLE, true)?->getColumn('recipeId')) {
            self::fail(sprintf(
                'The table %s has no recipeId column. Reinstall Web Doctor in this project first: `php craft plugin/uninstall web-doctor && php craft plugin/install web-doctor`.',
                InvestigationRecord::TABLE,
            ));
        }

        $this->environment = 'tests-' . bin2hex(random_bytes(5));

        // Only what the test registers, for the reason InvestigationTest gives.
        $this->registry = new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };

        $this->issues = new Issues();
        // One registry for the service and the plugin, so a recipe a test adds is the one both see.
        $recipes = self::coreRecipes();
        $this->investigations = new Investigations([
            'registry' => $this->registry,
            'engine' => new DiagnosticEngine(['registry' => $this->registry]),
            'issues' => $this->issues,
            'errors' => new Errors(['issues' => $this->issues]),
            'rootCauses' => new RootCauses(),
            'recipes' => $recipes,
            'environment' => $this->environment,
        ]);

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
        $this->plugin->set('investigations', $this->investigations);
        $this->plugin->set('recipes', $recipes);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // A recipe's investigations belong to no issue, so they do not go with one.
        InvestigationRecord::deleteAll(['like', 'environment', 'tests-%', false]);
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

    // What each recipe looks at ------------------------------------------------

    /**
     * Each recipe, the checks it runs at normal depth in the order it runs them, the ones it runs at
     * shallow depth, and the areas it names that no shipped check covers.
     *
     * @return array<string, array{string, list<string>, list<string>, list<string>}>
     */
    public static function recipes(): array
    {
        return [
            '500 Error Doctor' => [
                'http.serverError',
                [
                    'php.configuration', 'php.extensions', 'php.version',
                    'craft.application', 'craft.version',
                    'plugins.health', 'plugins.installed',
                    'database.charset', 'database.connection', 'database.migrations',
                    'storage.paths',
                    'queue.backlog', 'queue.failedJobs',
                    'environment.configuration',
                ],
                [
                    'php.configuration', 'php.extensions', 'php.version',
                    'craft.application', 'craft.version',
                    'plugins.health', 'plugins.installed',
                    'database.charset', 'database.connection', 'database.migrations',
                ],
                // Nothing shipped inspects configuration, and the plan says so rather than skipping it.
                [DiagnosticCategory::CONFIGURATION->label()],
            ],
            'Email Doctor' => [
                'email.notSending',
                ['email.configuration', 'environment.configuration', 'queue.failedJobs', 'queue.backlog'],
                ['email.configuration', 'environment.configuration'],
                [],
            ],
            'Queue Doctor' => [
                'queue.jobsFailing',
                ['queue.failedJobs', 'queue.backlog', 'php.configuration', 'database.connection', 'plugins.health', 'plugins.installed'],
                ['queue.failedJobs', 'queue.backlog'],
                [],
            ],
            'Database Doctor' => [
                'database.failing',
                ['database.connection', 'database.migrations', 'database.charset', 'environment.configuration', 'projectConfig.pendingChanges', 'queue.failedJobs', 'plugins.health', 'plugins.installed'],
                ['database.connection', 'database.migrations', 'database.charset'],
                [],
            ],
            'Deployment Doctor' => [
                'deployment.verify',
                [
                    'craft.application', 'craft.version',
                    'php.configuration', 'php.extensions', 'php.version',
                    'plugins.health', 'plugins.installed',
                    'projectConfig.integrity', 'projectConfig.pendingChanges',
                    'database.migrations',
                    'environment.configuration',
                    'queue.backlog', 'queue.failedJobs',
                    'filesystem.volumes',
                    'storage.paths',
                    'database.connection',
                ],
                [
                    'craft.application', 'craft.version',
                    'php.configuration', 'php.extensions', 'php.version',
                    'plugins.health', 'plugins.installed',
                    'projectConfig.integrity', 'projectConfig.pendingChanges',
                    'database.migrations',
                    'environment.configuration',
                ],
                [],
            ],
        ];
    }

    /**
     * Planned against the checks Web Doctor ships with. Every check has the reason it is run, the
     * areas nothing covers are listed, and what cannot be inspected here is stated as a lead.
     *
     * @param list<string> $normal
     * @param list<string> $shallow
     * @param list<string> $uncovered
     */
    #[DataProvider('recipes')]
    public function testEachRecipeLooksWhereItsSymptomComesFromAndSaysWhy(string $recipeId, array $normal, array $shallow, array $uncovered): void
    {
        $recipe = self::coreRecipes()->get($recipeId);
        self::assertInstanceOf(Recipe::class, $recipe);

        $plan = InvestigationPlan::forRecipe($recipe, CoreDiagnostics::all());

        self::assertSame($normal, $plan->diagnosticIds());
        self::assertSame($shallow, InvestigationPlan::forRecipe($recipe, CoreDiagnostics::all(), DiagnosticDepth::SHALLOW)->diagnosticIds());
        // Deep asks each check to go further; it does not reach further.
        self::assertSame($normal, InvestigationPlan::forRecipe($recipe, CoreDiagnostics::all(), DiagnosticDepth::DEEP)->diagnosticIds());
        self::assertSame($uncovered, array_column($plan->uncovered, 'area'));

        self::assertSame(InvestigationPlan::RECIPE_RULE, $plan->ruleId);
        self::assertSame($recipeId, $plan->recipeId);
        self::assertSame($recipe->title, $plan->ruleLabel);
        self::assertSame(0, $plan->omitted);
        // Nothing raised a symptom, so no check is the origin and none is missing.
        self::assertFalse($plan->includesOrigin());
        self::assertFalse($plan->missesOrigin());

        foreach ($plan->checks as $check) {
            self::assertNotSame('', trim($check['reason']), "{$check['diagnosticId']} is run with no reason.");
        }

        self::assertNotEmpty($plan->leads);

        // Read back as it was stored, recipe and all.
        self::assertEquals($plan, InvestigationPlan::fromArray(json_decode((string)json_encode($plan), true)));
    }

    public function testRecipesAreOfferedInTheirOwnOrderAndOtherPluginsCanAddTheirs(): void
    {
        $contributed = new Recipe(
            id: 'otherPlugin.syncFailing',
            title: 'Sync Doctor',
            symptom: 'Entries are not syncing',
            description: 'Looks at the queue the sync runs on.',
            category: DiagnosticCategory::PLUGINS,
            primary: [RelatedArea::category(DiagnosticCategory::QUEUE, 'The sync runs on the queue.')],
        );
        $another = new Recipe('anotherPlugin.slow', 'Slow Doctor', 'It is slow', 'Looks at PHP.', DiagnosticCategory::PERFORMANCE, [RelatedArea::category(DiagnosticCategory::PHP, 'PHP limits.')]);
        $clashing = new Recipe('queue.jobsFailing', 'Impostor', 'Queue jobs are failing', 'Not the real one.', DiagnosticCategory::QUEUE, [RelatedArea::check('queue.failedJobs', 'Failed jobs.')]);
        $malformed = new Recipe('Not-An-ID', 'Malformed', 'Something', 'Nothing.', DiagnosticCategory::QUEUE, [RelatedArea::check('queue.failedJobs', 'Failed jobs.')]);
        $empty = new Recipe('otherPlugin.nothing', 'Empty', 'Something', 'Looks at nothing.', DiagnosticCategory::QUEUE, []);

        Event::on(Recipes::class, Recipes::EVENT_REGISTER_RECIPES, static function($event) use ($contributed, $another, $clashing, $malformed, $empty): void {
            array_push($event->recipes, $contributed, $clashing, $malformed, 'not a recipe', $empty, $another);
        });

        try {
            $registry = new Recipes(['includeCoreRecipes' => true]);
            $ids = array_map(static fn(Recipe $r): string => $r->id, $registry->all());

            // Web Doctor's own first, as offered, then the contributed ones by ID; each broken one
            // costs only itself.
            self::assertSame([...array_map(static fn(Recipe $r): string => $r->id, CoreRecipes::all()), 'anotherPlugin.slow', 'otherPlugin.syncFailing'], $ids);
            self::assertSame('Queue Doctor', $registry->get('queue.jobsFailing')?->title);
            self::assertNull($registry->get('Not-An-ID'));
        } finally {
            Event::off(Recipes::class, Recipes::EVENT_REGISTER_RECIPES);
        }
    }

    // Running one ------------------------------------------------------------------

    /**
     * Each recipe, with a problem in the area its symptom lives in.
     *
     * @return array<string, array{string, string}>
     */
    public static function findings(): array
    {
        return [
            '500 Error Doctor' => ['http.serverError', 'php.configuration'],
            'Email Doctor' => ['email.notSending', 'email.configuration'],
            'Queue Doctor' => ['queue.jobsFailing', 'queue.failedJobs'],
            'Database Doctor' => ['database.failing', 'database.connection'],
            'Deployment Doctor' => ['deployment.verify', 'database.migrations'],
        ];
    }

    /**
     * A recipe runs exactly its plan through the engine; what the checks find goes through the Issue
     * Center like any finding, is linked from the investigation, and is the problem its causes are
     * weighed for. Nothing outside the plan is asked.
     */
    #[DataProvider('findings')]
    public function testEachRecipeRunsItsChecksAndItsFindingsBecomeIssues(string $recipeId, string $failing): void
    {
        $this->standIns([$failing => DiagnosticStatus::FAIL]);
        $expected = $this->investigations->planRecipe($this->recipe($recipeId))->diagnosticIds();

        $investigation = $this->investigations->runRecipe($recipeId);
        $steps = $this->investigations->steps($investigation->id);
        $issue = $this->issueFor($failing);

        self::assertSame($recipeId, $investigation->recipeId);
        self::assertNull($investigation->issueId);
        self::assertSame(InvestigationStatus::COMPLETED, $investigation->status);
        self::assertSame($expected, $investigation->plan->diagnosticIds());
        self::assertSame(count($expected), $investigation->checksRun);
        self::assertSame(1, $investigation->checksWithProblems);
        // No check raised a symptom, so there is no origin to report on.
        self::assertNull($investigation->originOutcome());
        self::assertSame($this->environment, $investigation->environment);
        self::assertSame(DiagnosticContext::currentSiteId(), $investigation->siteId);

        foreach ($this->checks as $id => $check) {
            self::assertSame(in_array($id, $expected, true) ? 1 : 0, $check->runs, "$id ran the wrong number of times.");
        }

        self::assertSame([
            InvestigationStepType::STARTED,
            InvestigationStepType::PLANNED,
            ...array_fill(0, count($expected), InvestigationStepType::CHECKED),
            InvestigationStepType::RECONCILED,
            InvestigationStepType::DIAGNOSED,
            InvestigationStepType::FINISHED,
        ], array_map(static fn(InvestigationStep $s): InvestigationStepType => $s->type, $steps));

        // The symptom is what the investigation started from.
        self::assertSame($this->recipe($recipeId)->symptom, $steps[0]->summary);
        self::assertNull($steps[0]->relatedIssueId);
        self::assertSame($issue->id, $this->stepFor($steps, $failing)->relatedIssueId);

        $diagnosed = $this->stepsOf($steps, InvestigationStepType::DIAGNOSED)[0];
        self::assertSame($issue->id, $diagnosed->relatedIssueId);
        self::assertStringStartsWith(sprintf('Weighed for the most serious problem found, “%s”.', $issue->title), (string)$diagnosed->note);
    }

    public function testCausesAreWeighedForTheWorstProblemFoundAndOfEqualsTheFirstTheRecipeRan(): void
    {
        $this->standIns([
            'database.migrations' => [DiagnosticStatus::WARNING, Severity::MEDIUM],
            'database.charset' => [DiagnosticStatus::FAIL, Severity::HIGH],
            'plugins.health' => [DiagnosticStatus::FAIL, Severity::HIGH],
        ]);

        $worst = $this->diagnosedFor($this->investigations->runRecipe('database.failing'));

        // Charset and plugin health tie on severity; the Database Doctor runs charset first.
        self::assertSame($this->issueFor('database.charset')->id, $worst->relatedIssueId);
    }

    /**
     * The task's own example, reached from the symptom rather than from an issue: the character-set
     * evidence, the refused write and the failed job amount to one cause, held as likely.
     */
    public function testTheDatabaseDoctorReachesTheCauseItsFindingsAmountTo(): void
    {
        $refused = "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F\\x98\\x80' for column 'title' at row 1";

        $this->standIns([
            'database.charset' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                diagnosticId: $d->id(),
                name: $d->name(),
                category: $d->category(),
                status: DiagnosticStatus::WARNING,
                summary: 'Craft’s element index table does not accept four-byte characters such as emoji.',
                evidence: [new Evidence(type: EvidenceType::DATABASE, label: 'Character set', source: 'database.charset', data: [
                    'configuredCharset' => 'utf8mb4',
                    'databaseCharset' => 'utf8',
                    'sampledTable' => 'elements_sites',
                    'sampledTableAcceptsMb4' => false,
                    'columnsInspected' => false,
                ], reference: 'elements_sites')],
            ),
            'queue.failedJobs' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                diagnosticId: $d->id(),
                name: $d->name(),
                category: $d->category(),
                status: DiagnosticStatus::FAIL,
                summary: 'Queue jobs have failed: 2.',
                severity: Severity::MEDIUM,
                evidence: [new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Updating search indexes', source: 'queue.failedJobs', data: ['description' => 'Updating search indexes', 'occurrences' => 2, 'error' => $refused])],
            ),
        ]);

        $investigation = $this->investigations->runRecipe('database.failing');
        $causes = (new RootCauses())->forInvestigation($investigation->id);

        self::assertSame($this->issueFor('database.charset')->id, $this->diagnosedFor($investigation)->relatedIssueId);
        self::assertSame('database.characterSet', $causes[0]->ruleId ?? null);
        self::assertSame(Confidence::LIKELY, $causes[0]->confidence);
        // The failed jobs are an issue of their own that the cause would explain too.
        self::assertSame([$this->issueFor('queue.failedJobs')->id], array_column($causes[0]->relatedIssues, 'id'));

        // What it recommends: the cause is acted on for the problem it was weighed for, which comes
        // first; the failed jobs get the advice their own evidence selects, and not the cause's.
        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->renderInvestigation($investigation);
        $positions = array_map(static fn(string $title): int|false => strpos($html, $title), [
            'Convert the database’s character set',
            'Convert the database to utf8mb4',
            'Fix what the repeatedly failing job names before retrying it',
        ]);

        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
        self::assertSame(1, substr_count($html, 'href="#cause-0"'));
    }

    public function testARecipeThatFindsNothingSaysThereWasNothingToWeigh(): void
    {
        $this->standIns();

        $investigation = $this->investigations->runRecipe('queue.jobsFailing');
        $diagnosed = $this->diagnosedFor($investigation);

        self::assertSame(InvestigationStatus::COMPLETED, $investigation->status);
        self::assertSame(0, $investigation->checksWithProblems);
        self::assertNull($diagnosed->relatedIssueId);
        self::assertSame('None of the checks reported a problem, so there was nothing to weigh the known causes against.', $diagnosed->note);
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $investigation->id])->count());
        self::assertSame(0, (int)IssueRecord::find()->where(['environment' => $this->environment])->count());
    }

    /**
     * A recipe runs where a diagnostic run from the dashboard would, so its findings land on the
     * issues such a run raised rather than beside them; and it records what else is open there.
     */
    public function testARecipeFindsTheIssuesARunWouldRaiseAndWhatIsOpenNearby(): void
    {
        $this->standIns(['queue.failedJobs' => DiagnosticStatus::FAIL]);
        $siteId = DiagnosticContext::currentSiteId();
        $existing = $this->raise('queue.failedJobs', DiagnosticCategory::QUEUE, $siteId);
        $nearby = $this->raise('tests.otherQueue', DiagnosticCategory::QUEUE, $siteId);
        $elsewhere = $this->raise('tests.farQueue', DiagnosticCategory::QUEUE, $siteId, 'tests-elsewhere-' . bin2hex(random_bytes(3)));
        $unrelated = $this->raise('tests.mail', DiagnosticCategory::EMAIL, $siteId);

        $investigation = $this->investigations->runRecipe('queue.jobsFailing');
        $steps = $this->investigations->steps($investigation->id);

        self::assertSame($existing->id, $this->stepFor($steps, 'queue.failedJobs')->relatedIssueId);
        self::assertSame(2, $this->reread($existing)->occurrences);

        $related = array_map(static fn(InvestigationStep $s): ?int => $s->relatedIssueId, $this->stepsOf($steps, InvestigationStepType::RELATED_ISSUE));
        self::assertSame([$nearby->id], $related);
        self::assertNotContains($elsewhere->id, $related);
        self::assertNotContains($unrelated->id, $related);
    }

    public function testAnUnknownRecipeIsRefusedAndNothingIsRecorded(): void
    {
        $this->standIns();

        try {
            $this->investigations->runRecipe('tests.noSuchRecipe');
            self::fail('A recipe that does not exist was run.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('tests.noSuchRecipe', $e->getMessage());
        }

        self::assertSame([], $this->investigations->recipeHistory());
    }

    public function testARecipeKeepsABoundedNumberOfInvestigationsHereAndNoneOfAnothers(): void
    {
        $this->standIns();
        $this->investigations->maxPerRecipe = 2;
        $email = $this->investigations->runRecipe('email.notSending');

        $kept = [];

        for ($i = 0; $i < 3; $i++) {
            $kept[] = $this->investigations->runRecipe('queue.jobsFailing')->id;
        }

        $history = $this->investigations->recipeHistory();
        $ids = array_map(static fn(Investigation $i): int => $i->id, $history);

        // The oldest went; the other recipe's is untouched.
        self::assertSame([$kept[2], $kept[1], $email->id], $ids);
        self::assertSame(0, (int)InvestigationStepRecord::find()->where(['investigationId' => $kept[0]])->count());

        // Read a few of each, the bound is each recipe's: the recipe run less recently is not
        // pushed out by one run more often.
        $ids = array_map(static fn(Investigation $i): int => $i->id, $this->investigations->recipeHistory(1));
        self::assertSame([$kept[2], $email->id], $ids);
    }

    /**
     * What the mailer is configured with is reported only as present or missing, in everything the
     * Email Doctor stores and everything its page shows.
     */
    public function testTheEmailDoctorNeverRevealsTheMailersSecrets(): void
    {
        $password = 'smtp-secret-' . bin2hex(random_bytes(6));
        $mailer = new MailerConfigurationDiagnostic();
        $mailer->settings = new MailSettings([
            'fromEmail' => '',
            'fromName' => 'Example',
            'transportType' => Smtp::class,
            'transportSettings' => [
                'host' => 'smtp.example.com',
                'port' => '587',
                'useAuthentication' => true,
                'username' => 'apikey',
                'password' => $password,
            ],
        ]);
        $this->standIns();
        $this->registry = $this->withReplaced($mailer);

        $investigation = $this->investigations->runRecipe('email.notSending');

        // It found the missing sender, so the run is not a pass that proves nothing.
        self::assertSame(DiagnosticStatus::FAIL, $this->stepFor($this->investigations->steps($investigation->id), 'email.configuration')->status);

        $stored = (string)json_encode([
            InvestigationRecord::find()->where(['id' => $investigation->id])->asArray()->all(),
            InvestigationStepRecord::find()->where(['investigationId' => $investigation->id])->asArray()->all(),
            RootCauseRecord::find()->where(['investigationId' => $investigation->id])->asArray()->all(),
            IssueRecord::find()->where(['environment' => $this->environment])->asArray()->all(),
        ]);

        self::assertStringNotContainsString($password, $stored);
        self::assertStringContainsString(Redaction::PRESENT, $stored);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE]);
        $this->request('GET');
        $html = $this->renderInvestigation($investigation);

        self::assertStringNotContainsString($password, $html);
        self::assertStringNotContainsString(Redaction::REDACTED, $html);
    }

    // Who may, and the pages --------------------------------------------------------

    public function testTheRecipesPageOffersEachSymptomAndRunsOnlyForWhoeverMayInvestigate(): void
    {
        $this->standIns();
        $past = $this->investigations->runRecipe('http.serverError');

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->request('GET');
        $reader = $this->render($this->recipesController(), 'index', 'web-doctor/_recipes/_list');

        foreach (CoreRecipes::all() as $recipe) {
            self::assertStringContainsString(htmlspecialchars($recipe->symptom, ENT_QUOTES), $reader);
            self::assertStringContainsString('id="recipe-' . $recipe->id . '"', $reader);
        }

        // Why each check is looked at is for every reader, before anything has run, and so is what
        // a recipe found before.
        self::assertStringContainsString(htmlspecialchars((string)$this->investigations->planRecipe($this->recipe('http.serverError'))->reasonFor('storage.paths'), ENT_QUOTES), $reader);
        self::assertStringContainsString("web-doctor/recipes/http.serverError/investigations/{$past->id}", $reader);
        self::assertStringNotContainsString('web-doctor/recipes/run', $reader);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::INVESTIGATE_ISSUES]);
        $investigator = $this->render($this->recipesController(), 'index', 'web-doctor/_recipes/_list');

        self::assertStringContainsString('web-doctor/recipes/run', $investigator);
        self::assertStringContainsString('Run 500 Error Doctor', $investigator);

        // Opening the page ran nothing.
        foreach ($this->checks as $check) {
            self::assertLessThanOrEqual(1, $check->runs);
        }

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $this->expectException(ForbiddenHttpException::class);
        $this->recipesController()->runAction('index');
    }

    public function testRunningARecipeNeedsTheInvestigatePermissionAndAControlPanelPostWithAToken(): void
    {
        $this->standIns();

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->post(['recipeId' => 'queue.jobsFailing']);

        try {
            $this->recipesController()->runAction('run');
            self::fail('Somebody who may only read issues ran a recipe.');
        } catch (ForbiddenHttpException) {
        }

        $this->signIn(admin: true);

        foreach ([
            'a GET' => [fn() => $this->request('GET'), MethodNotAllowedHttpException::class],
            'no CSRF token' => [fn() => $this->request('POST')->setBodyParams(['recipeId' => 'queue.jobsFailing']), BadRequestHttpException::class],
            'the front end' => [fn() => $this->request('POST', cp: false), BadRequestHttpException::class],
        ] as $how => [$request, $refusal]) {
            $request();

            try {
                $this->recipesController()->runAction('run');
                self::fail("A recipe was run from $how.");
            } catch (\Throwable $e) {
                self::assertInstanceOf($refusal, $e, $how);
            }
        }

        self::assertSame([], $this->investigations->recipeHistory());
        self::assertSame(RecordingRecipesController::ALLOW_ANONYMOUS_NEVER, $this->recipesController()->anonymousAccess());
    }

    public function testSomebodyWhoMayInvestigateRunsARecipeAndIsTakenToWhatItFound(): void
    {
        $this->standIns();
        $user = $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::INVESTIGATE_ISSUES]);

        $this->post(['recipeId' => 'tests.noSuchRecipe']);
        $controller = $this->recipesController();
        $controller->runAction('run');

        self::assertSame('fail', $controller->lastFlash()['level'] ?? null);
        self::assertCount(0, $this->investigations->recipeHistory());

        $this->post(['recipeId' => 'queue.jobsFailing', 'depth' => DiagnosticDepth::SHALLOW->value]);
        $response = $this->recipesController()->runAction('run');
        $made = $this->investigations->recipeHistory();

        self::assertCount(1, $made);
        self::assertSame(DiagnosticDepth::SHALLOW, $made[0]->depth);
        self::assertSame(['queue.failedJobs', 'queue.backlog'], $made[0]->plan->diagnosticIds());
        self::assertSame($user->id, $made[0]->startedBy);
        self::assertStringContainsString("web-doctor/recipes/queue.jobsFailing/investigations/{$made[0]->id}", (string)$response->getHeaders()->get('location'));
    }

    public function testARecipesInvestigationIsReachableOnlyThroughTheRecipeItWasRunFrom(): void
    {
        $this->standIns();
        $investigation = $this->investigations->runRecipe('queue.jobsFailing');
        $issue = $this->raise('tests.origin');
        $this->signIn(admin: true);
        $this->request('GET');

        foreach ([
            'another recipe' => ['recipe-detail', ['recipeId' => 'email.notSending', 'investigationId' => $investigation->id]],
            'an issue' => ['detail', ['issueId' => $issue->id, 'investigationId' => $investigation->id]],
        ] as $through => [$action, $params]) {
            try {
                $this->investigationsController()->runAction($action, $params);
                self::fail("A recipe's investigation was reached through $through.");
            } catch (NotFoundHttpException) {
            }
        }

        // And an issue's investigation is not reachable as a recipe's.
        $this->checks['tests.origin'] = $this->standIn('tests.origin', DiagnosticCategory::DATABASE, DiagnosticStatus::FAIL);
        $ofIssue = $this->investigations->investigate($issue->id);

        $this->expectException(NotFoundHttpException::class);
        $this->investigationsController()->runAction('recipe-detail', ['recipeId' => 'queue.jobsFailing', 'investigationId' => $ofIssue->id]);
    }

    public function testARecipesInvestigationPageSaysWhatWasRunForWhatAndWhatItWeighed(): void
    {
        $this->standIns(['queue.failedJobs' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: DiagnosticStatus::FAIL,
            summary: 'Queue jobs have failed: 3.',
            evidence: [new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Sending email', source: 'queue.failedJobs', data: ['description' => 'Sending email', 'error' => 'distinctive-value-5521'])],
        )]);
        $investigation = $this->investigations->runRecipe('queue.jobsFailing');
        $issue = $this->issueFor('queue.failedJobs');

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE]);
        $this->request('GET');
        $html = $this->renderInvestigation($investigation);

        foreach ([
            'Queue Doctor: Queue jobs are failing or not running',
            'Back to the recipes',
            'A recipe investigates a symptom rather than an issue.',
            'Queue jobs have failed: 3.',
            "web-doctor/issues/{$issue->id}",
            'Open the problem the causes were weighed for',
            'distinctive-value-5521',
            InvestigationStepType::FINISHED->label(),
        ] as $expected) {
            self::assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $html);
        }

        // Nothing raised a symptom, so nothing is marked as having raised it.
        self::assertStringNotContainsString('Raised this issue', $html);
        self::assertStringNotContainsString('Back to the issue', $html);

        // Without "View evidence", what kind of evidence was found and nothing it contains.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $withheld = $this->renderInvestigation($investigation);

        self::assertStringContainsString('Sending email', $withheld);
        self::assertStringNotContainsString('distinctive-value-5521', $withheld);

        // A recipe that found nothing weighed nothing, and does not claim no cause fits.
        $this->signIn(admin: true);
        $this->checks['queue.failedJobs']->handler = static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult($d->id(), $d->name(), $d->category(), DiagnosticStatus::PASS, 'Nothing has failed.');
        $clean = $this->renderInvestigation($this->investigations->runRecipe('queue.jobsFailing'));

        self::assertStringContainsString('there was nothing to weigh the known causes against', $clean);
        self::assertStringNotContainsString('None of Web Doctor’s known causes fits', $clean);
        self::assertStringNotContainsString('How confidence is decided', $clean);
    }

    public function testCausesThatCannotBeReadAreSaidToBeUnreadNotAbsent(): void
    {
        $this->charsetStandIns();
        $this->investigations->runRecipe('database.failing');
        $this->plugin->set('rootCauses', new class() extends RootCauses {
            public function leading(array $investigationIds): array
            {
                throw new RuntimeException('The causes table cannot be read.');
            }
        });

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->recipesController(), 'index', 'web-doctor/_recipes/_list');
        $row = substr($html, (int)strpos($html, 'id="recipe-database.failing"'));

        self::assertStringContainsString('Could not be read', $row);
        self::assertStringNotContainsString('>None<', substr($row, 0, (int)strpos($row, '</table>')));
    }

    public function testTheDashboardPointsFromASymptomToItsRecipeForWhoeverMayReadWhatItFinds(): void
    {
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->request('GET');
        $html = $this->render(new RecordingOverviewController('overview', $this->plugin), 'index', 'web-doctor/_dashboard');

        self::assertStringContainsString('Something specific going wrong?', $html);
        self::assertStringContainsString('#recipe-http.serverError', $html);
        self::assertStringContainsString(htmlspecialchars($this->recipe('http.serverError')->symptom, ENT_QUOTES), $html);

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $html = $this->render(new RecordingOverviewController('overview', $this->plugin), 'index', 'web-doctor/_dashboard');

        self::assertStringNotContainsString('Something specific going wrong?', $html);
    }

    // Configuration and input ---------------------------------------------------

    /**
     * A bound that cannot be one is refused before anything is read or written, rather than read
     * as the nearest one that could be.
     *
     * @return array<string, array{string, int}>
     */
    public static function invalidBounds(): array
    {
        return [
            'no checks' => ['maxChecks', 0],
            'negative checks' => ['maxChecks', -3],
            'negative evidence' => ['maxEvidence', -1],
            'no investigations per issue' => ['maxPerIssue', 0],
            'no investigations per recipe' => ['maxPerRecipe', 0],
            'negative investigations per recipe' => ['maxPerRecipe', -5],
            'no nearby issues' => ['relatedLimit', 0],
        ];
    }

    #[DataProvider('invalidBounds')]
    public function testABoundThatCannotBeOneIsRefusedBeforeAnythingRuns(string $property, int $value): void
    {
        $this->standIns();
        $this->investigations->$property = $value;

        foreach ([
            'planning' => fn() => $this->investigations->planRecipe($this->recipe('queue.jobsFailing')),
            'running' => fn() => $this->investigations->runRecipe('queue.jobsFailing'),
        ] as $what => $call) {
            try {
                $call();
                self::fail("$property = $value was accepted for $what.");
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString($property, $e->getMessage());
            }
        }

        $this->investigations->$property = 20;
        self::assertCount(0, $this->investigations->recipeHistory());
        self::assertSame(0, array_sum(array_map(static fn(TestDiagnostic $d): int => $d->runs, $this->checks)));
    }

    public function testTheSmallestValidBoundsAreHonouredExactly(): void
    {
        $this->standIns(['queue.failedJobs' => DiagnosticStatus::FAIL]);
        $this->investigations->maxChecks = 1;
        $this->investigations->maxEvidence = 0;

        $investigation = $this->investigations->runRecipe('queue.jobsFailing');

        self::assertSame(['queue.failedJobs'], $investigation->plan->diagnosticIds());
        // The normal plan has six checks; five were left out, each counted once.
        self::assertSame(5, $investigation->plan->omitted);
        self::assertSame(0, $investigation->evidenceCount);

        foreach ([0, -1] as $bound) {
            try {
                InvestigationPlan::forRecipe($this->recipe('queue.jobsFailing'), CoreDiagnostics::all(), DiagnosticDepth::NORMAL, $bound);
                self::fail("A plan was built with maxChecks = $bound.");
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->investigations->recipeHistory(0);
    }

    /**
     * A plan read back from a row is history, not configuration: what cannot be read is dropped or
     * read as the least it can claim, and never takes the page down.
     */
    public function testAStoredPlanThatCannotBeFullyReadIsReadAsFarAsItCanBe(): void
    {
        $plan = InvestigationPlan::fromArray([
            'ruleId' => InvestigationPlan::RECIPE_RULE,
            'depth' => 'bottomless',
            'recipeId' => ['not', 'a', 'string'],
            'checks' => [['diagnosticId' => 'queue.failedJobs', 'reason' => 'Kept.'], 'garbage', ['name' => 'no id']],
            'leads' => ['A lead.', 42],
        ]);

        self::assertSame(['queue.failedJobs'], $plan->diagnosticIds());
        self::assertNull($plan->recipeId);
        self::assertSame(DiagnosticDepth::NORMAL, $plan->depth);
        self::assertSame(['A lead.'], $plan->leads);
    }

    /**
     * Each depth runs exactly the checks its plan names, each once, and hands the checks the depth
     * that was asked for; missing, the depth is normal, as every run form states. Anything else is
     * refused before anything runs or is written.
     *
     * @return array<string, array{mixed, DiagnosticDepth|null}>
     */
    public static function requestedDepths(): array
    {
        return [
            'shallow' => ['shallow', DiagnosticDepth::SHALLOW],
            'normal' => ['normal', DiagnosticDepth::NORMAL],
            'deep' => ['deep', DiagnosticDepth::DEEP],
            'missing' => [self::MISSING, DiagnosticDepth::NORMAL],
            'a depth Web Doctor does not have' => ['bottomless', null],
            'empty' => ['', null],
            'the wrong case' => ['Deep', null],
            'a list' => [['deep'], null],
            'a number' => ['1', null],
        ];
    }

    #[DataProvider('requestedDepths')]
    public function testARecipeRunsAtExactlyTheDepthAskedForOrNotAtAll(mixed $requested, ?DiagnosticDepth $expected): void
    {
        $seen = $this->recordingDepths();
        $this->signIn(admin: true);
        $this->post($requested === self::MISSING ? ['recipeId' => 'queue.jobsFailing'] : ['recipeId' => 'queue.jobsFailing', 'depth' => $requested]);

        if ($expected === null) {
            try {
                $this->recipesController()->runAction('run');
                self::fail('A recipe ran at a depth Web Doctor does not have.');
            } catch (BadRequestHttpException) {
            }

            self::assertSame([], $seen->depths);
            self::assertCount(0, $this->investigations->recipeHistory());
            self::assertSame(0, (int)IssueRecord::find()->where(['environment' => $this->environment])->count());

            return;
        }

        $this->recipesController()->runAction('run');
        $made = $this->investigations->recipeHistory();

        self::assertCount(1, $made);
        self::assertSame($expected, $made[0]->depth);
        self::assertSame($made[0]->plan->diagnosticIds(), array_keys($seen->depths));
        self::assertSame([$expected], array_values(array_unique($seen->depths, SORT_REGULAR)));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedRuns(): array
    {
        return [
            'no recipe' => [[]],
            'an empty recipe' => [['recipeId' => '']],
            'a list for a recipe' => [['recipeId' => ['queue.jobsFailing']]],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('malformedRuns')]
    public function testARequestThatNamesNoUsableRecipeIsRefusedWithNothingRun(array $params): void
    {
        $this->standIns();
        $this->signIn(admin: true);
        $this->post($params);

        try {
            $this->recipesController()->runAction('run');
            self::fail('A malformed request ran a recipe.');
        } catch (BadRequestHttpException) {
        }

        self::assertCount(0, $this->investigations->recipeHistory());
        self::assertSame(0, array_sum(array_map(static fn(TestDiagnostic $d): int => $d->runs, $this->checks)));
    }

    // Planning -----------------------------------------------------------------------

    /**
     * The same recipe and checks always plan the same way, however the registry lists them.
     *
     * @param list<string> $normal
     * @param list<string> $shallow
     * @param list<string> $uncovered
     */
    #[DataProvider('recipes')]
    public function testEachRecipePlansIdenticallyWhateverOrderTheChecksArriveIn(string $recipeId, array $normal, array $shallow, array $uncovered): void
    {
        $recipe = $this->recipe($recipeId);
        $plans = [];

        foreach ([CoreDiagnostics::all(), array_reverse(CoreDiagnostics::all()), CoreDiagnostics::all()] as $available) {
            foreach (DiagnosticDepth::cases() as $depth) {
                $plans[$depth->value][] = json_encode(InvestigationPlan::forRecipe($recipe, $available, $depth));
            }
        }

        foreach ($plans as $depth => $encoded) {
            self::assertCount(1, array_unique($encoded), "The $depth plan changed with the order of the registry.");
        }

        self::assertSame($normal, InvestigationPlan::forRecipe($recipe, array_reverse(CoreDiagnostics::all()))->diagnosticIds());
        self::assertSame($shallow, InvestigationPlan::forRecipe($recipe, array_reverse(CoreDiagnostics::all()), DiagnosticDepth::SHALLOW)->diagnosticIds());
        self::assertSame($uncovered, array_column(InvestigationPlan::forRecipe($recipe, array_reverse(CoreDiagnostics::all()))->uncovered, 'area'));
    }

    /**
     * An area named twice, an area several areas reach, a check that does not exist and a category
     * nothing covers: each check is chosen once for its first reason, the bound counts each check
     * it leaves out once, and what nothing covers is listed rather than dropped or run.
     */
    public function testRepeatedMissingAndOverlappingAreasArePlannedTruthfully(): void
    {
        $recipe = new Recipe(
            id: 'otherPlugin.overlapping',
            title: 'Overlap Doctor',
            symptom: 'Overlaps',
            description: 'Names areas more than once.',
            category: DiagnosticCategory::QUEUE,
            primary: [
                RelatedArea::check('queue.failedJobs', 'First reason.'),
                RelatedArea::check('queue.failedJobs', 'Second reason.'),
                RelatedArea::check('otherPlugin.gone', 'A check that is not installed.'),
            ],
            related: [
                RelatedArea::category(DiagnosticCategory::QUEUE, 'The whole queue.'),
                RelatedArea::category(DiagnosticCategory::COMMERCE, 'Nothing covers this.'),
                RelatedArea::check('queue.backlog', 'Named again.'),
            ],
        );

        $plan = InvestigationPlan::forRecipe($recipe, CoreDiagnostics::all());

        self::assertSame(['queue.failedJobs', 'queue.backlog'], $plan->diagnosticIds());
        self::assertSame('First reason.', $plan->reasonFor('queue.failedJobs'));
        self::assertSame('The whole queue.', $plan->reasonFor('queue.backlog'));
        self::assertSame(['otherPlugin.gone', DiagnosticCategory::COMMERCE->label()], array_column($plan->uncovered, 'area'));
        self::assertSame([false, false], array_column($plan->uncovered, 'origin'));
        self::assertSame(0, $plan->omitted);

        // Bounded to one: the backlog is reached by two areas and left out once.
        $bounded = InvestigationPlan::forRecipe($recipe, CoreDiagnostics::all(), DiagnosticDepth::NORMAL, 1);
        self::assertSame(['queue.failedJobs'], $bounded->diagnosticIds());
        self::assertSame(1, $bounded->omitted);

        // A check the plan names that is not installed is not run and does not break the recipe.
        $this->standIns();
        $this->recipesRegistry()->register($recipe);
        $investigation = $this->investigations->runRecipe('otherPlugin.overlapping');

        self::assertSame(InvestigationStatus::COMPLETED, $investigation->status);
        self::assertSame(['queue.failedJobs', 'queue.backlog'], array_map(static fn(InvestigationStep $s): ?string => $s->diagnosticId, $this->stepsOf($this->investigations->steps($investigation->id), InvestigationStepType::CHECKED)));
    }

    /**
     * Each recipe at each depth, run: exactly the checks its plan names, each once, told the depth
     * asked for, and nothing outside the plan.
     *
     * @return array<string, array{string, DiagnosticDepth, list<string>}>
     */
    public static function recipesAtEachDepth(): array
    {
        $cases = [];

        foreach (self::recipes() as $name => [$recipeId, $normal, $shallow]) {
            $cases["$name, shallow"] = [$recipeId, DiagnosticDepth::SHALLOW, $shallow];
            $cases["$name, normal"] = [$recipeId, DiagnosticDepth::NORMAL, $normal];
            $cases["$name, deep"] = [$recipeId, DiagnosticDepth::DEEP, $normal];
        }

        return $cases;
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('recipesAtEachDepth')]
    public function testEachRecipeRunsItsPlannedChecksAtEachDepthAndNothingElse(string $recipeId, DiagnosticDepth $depth, array $expected): void
    {
        $seen = $this->recordingDepths();

        $investigation = $this->investigations->runRecipe($recipeId, $depth);

        self::assertSame($expected, $investigation->plan->diagnosticIds());
        self::assertSame($expected, array_keys($seen->depths));
        self::assertSame(array_fill(0, count($expected), $depth), array_values($seen->depths));

        foreach ($this->checks as $id => $check) {
            self::assertSame(in_array($id, $expected, true) ? 1 : 0, $check->runs, "$id ran the wrong number of times at $depth->value.");
        }

        self::assertSame($depth, $investigation->depth);
        self::assertSame(count($expected), $investigation->checksRun);
    }

    // The problem causes are weighed for ------------------------------------------------

    public function testTheWorstProblemIsChosenTheSameWayWhateverOrderTheChecksArriveIn(): void
    {
        $outcomes = [
            'database.migrations' => [DiagnosticStatus::WARNING, Severity::MEDIUM],
            'database.charset' => [DiagnosticStatus::FAIL, Severity::HIGH],
            'plugins.health' => [DiagnosticStatus::FAIL, Severity::HIGH],
            // Not a finding, however severe it says it is.
            'database.connection' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult($d->id(), $d->name(), $d->category(), DiagnosticStatus::UNKNOWN, 'Could not tell.', Severity::CRITICAL),
        ];

        $this->standIns($outcomes, reversed: true);
        $first = $this->diagnosedFor($this->investigations->runRecipe('database.failing'))->relatedIssueId;
        $second = $this->diagnosedFor($this->investigations->runRecipe('database.failing'))->relatedIssueId;

        self::assertSame($this->issueFor('database.charset')->id, $first);
        self::assertSame($first, $second);
        self::assertSame(0, (int)IssueRecord::find()->where(['environment' => $this->environment, 'diagnosticId' => 'database.connection'])->count());
    }

    /**
     * The problem weighed is the one this run found here: an issue from the same check recorded in
     * another environment or on another site is never the one weighed.
     */
    public function testTheProblemWeighedIsTheOneFoundHereNotOneRecordedElsewhere(): void
    {
        $this->standIns(['queue.failedJobs' => DiagnosticStatus::FAIL]);
        $otherSite = $this->otherSiteId();
        $elsewhere = [
            $this->raise('queue.failedJobs', DiagnosticCategory::QUEUE, DiagnosticContext::currentSiteId(), 'tests-elsewhere-' . bin2hex(random_bytes(3))),
            $this->raise('queue.failedJobs', DiagnosticCategory::QUEUE, $otherSite),
        ];

        $weighed = $this->diagnosedFor($this->investigations->runRecipe('queue.jobsFailing'))->relatedIssueId;
        $own = $this->issues->getByFingerprint(Fingerprint::forResult(
            new DiagnosticResult('queue.failedJobs', 'Check queue.failedJobs', DiagnosticCategory::QUEUE, DiagnosticStatus::FAIL),
            $this->environment,
            DiagnosticContext::currentSiteId(),
        ));

        self::assertInstanceOf(Issue::class, $own);
        self::assertSame($own->id, $weighed);
        self::assertNotContains($weighed, array_map(static fn(Issue $i): int => $i->id, $elsewhere));
    }

    /**
     * When the most serious problem is not recorded as an issue here — the Issue Center could not
     * be updated, the issue cannot be read, or what is found for it belongs somewhere else — the
     * causes are not weighed, and the timeline says so rather than that nothing was found.
     *
     * @return array<string, array{string}>
     */
    public static function unrecordedProblems(): array
    {
        return [
            'the Issue Center could not be updated' => ['reconcile'],
            'the issue cannot be read' => ['get'],
            'the issue found is from another environment' => ['foreign'],
        ];
    }

    #[DataProvider('unrecordedProblems')]
    public function testAProblemNotRecordedAsAnIssueHereIsNotWeighedAndSaysSo(string $failure): void
    {
        $foreign = $failure === 'foreign'
            ? $this->raise('queue.failedJobs', DiagnosticCategory::QUEUE, DiagnosticContext::currentSiteId(), 'tests-foreign-' . bin2hex(random_bytes(3)))->id
            : 0;

        $issues = new class($failure, $foreign) extends Issues {
            public function __construct(private string $failure, private int $foreign)
            {
                parent::__construct();
            }

            public function reconcile(DiagnosticRun $run): IssueReconciliation
            {
                if ($this->failure === 'reconcile') {
                    throw new RuntimeException('The issue table is gone.');
                }

                return parent::reconcile($run);
            }

            public function get(int $id): ?Issue
            {
                return $this->failure === 'get' ? null : parent::get($id);
            }

            public function idsByFingerprint(array $fingerprints): array
            {
                return $this->failure === 'foreign' ? array_fill_keys($fingerprints, $this->foreign) : parent::idsByFingerprint($fingerprints);
            }
        };

        $this->investigations = $this->service(['issues' => $issues, 'errors' => new Errors(['issues' => $issues])]);
        $this->standIns(['queue.failedJobs' => DiagnosticStatus::FAIL]);

        $investigation = $this->investigations->runRecipe('queue.jobsFailing');
        $diagnosed = $this->diagnosedFor($investigation);

        self::assertNull($diagnosed->relatedIssueId);
        self::assertStringContainsString('could not be matched to an issue recorded here, so the known causes were not weighed', (string)$diagnosed->note);
        self::assertStringNotContainsString('None of the checks reported a problem', (string)$diagnosed->note);
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $investigation->id])->count());
        self::assertSame(1, $investigation->checksWithProblems);
        // The run is still recorded: what the checks found is still what they found.
        self::assertSame(InvestigationStatus::COMPLETED, $investigation->status);
    }

    // The Issue Center, evidence and errors -------------------------------------------------

    /**
     * A recipe is one more run as far as the Issue Center is concerned: the same problem found again
     * is the same issue seen again, with the same evidence counted rather than copied; a problem
     * that goes is observed clear; one that comes back reopens the issue; and a check that could not
     * tell or broke raises nothing.
     */
    public function testARecipesFindingsFollowTheIssueCentersOwnRules(): void
    {
        $evidence = [new Evidence(type: EvidenceType::QUEUE, label: 'Failed jobs', source: 'queue.failedJobs', data: ['failed' => 3])];
        $this->standIns([
            'queue.failedJobs' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult($d->id(), $d->name(), $d->category(), DiagnosticStatus::FAIL, 'Queue jobs have failed: 3.', evidence: $evidence),
            'queue.backlog' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult($d->id(), $d->name(), $d->category(), DiagnosticStatus::UNKNOWN, 'Could not tell.'),
            'php.configuration' => static function(): DiagnosticResult {
                throw new RuntimeException('Broke.');
            },
        ]);

        $first = $this->investigations->runRecipe('queue.jobsFailing');
        $second = $this->investigations->runRecipe('queue.jobsFailing');
        $issue = $this->issueFor('queue.failedJobs');

        self::assertSame(1, (int)IssueRecord::find()->where(['environment' => $this->environment])->count(), 'Only the finding raises an issue, once.');
        self::assertSame(2, $issue->occurrences);
        self::assertSame($first->runId, $issue->firstRunId);

        $rows = EvidenceRecord::find()->where(['issueId' => $issue->id])->all();
        self::assertCount(1, $rows);
        self::assertInstanceOf(EvidenceRecord::class, $rows[0]);
        self::assertSame(2, (int)$rows[0]->occurrences);
        self::assertSame('queue.failedJobs', $rows[0]->diagnosticId);
        self::assertSame($this->environment, $rows[0]->environment);
        self::assertSame(DiagnosticContext::currentSiteId(), $rows[0]->siteId === null ? null : (int)$rows[0]->siteId);
        self::assertSame($second->runId, $rows[0]->lastRunId);

        $this->checks['queue.failedJobs']->handler = static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult($d->id(), $d->name(), $d->category(), DiagnosticStatus::PASS, 'Nothing has failed.');
        $this->investigations->runRecipe('queue.jobsFailing');
        self::assertSame(IssueStatus::RESOLVED, $this->reread($issue)->status);

        $this->checks['queue.failedJobs']->handler = static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult($d->id(), $d->name(), $d->category(), DiagnosticStatus::FAIL, 'Queue jobs have failed: 1.');
        $this->investigations->runRecipe('queue.jobsFailing');
        $back = $this->reread($issue);

        self::assertTrue($back->status->isOpen());
        self::assertSame($issue->id, $back->id);
        self::assertSame(1, (int)IssueRecord::find()->where(['environment' => $this->environment])->count());
    }

    /**
     * The exceptions a recipe's checks run into are grouped as any run's are: the same error in two
     * checks is one error, a different one is another, and another environment's are its own.
     */
    public function testTheErrorsARecipeRunsIntoAreGroupedAsAnyRunsAre(): void
    {
        $lock = static function(): DiagnosticResult {
            throw new RuntimeException('Lock wait timeout exceeded for job 4411; password=hunter2-lock');
        };
        $this->standIns([
            'database.connection' => $lock,
            'database.migrations' => $lock,
            'database.charset' => static function(): DiagnosticResult {
                throw new RuntimeException('Table elements_sites is marked as crashed');
            },
        ]);

        $this->investigations->runRecipe('database.failing');
        $groups = ErrorGroupRecord::find()->where(['environment' => $this->environment])->all();

        self::assertCount(2, $groups);

        $sources = [];
        foreach ($groups as $group) {
            self::assertInstanceOf(ErrorGroupRecord::class, $group);
            $sources[] = ErrorSourceRecord::find()->select(['diagnosticId'])->where(['errorGroupId' => $group->id])->orderBy(['diagnosticId' => SORT_ASC])->column();
        }
        usort($sources, static fn(array $a, array $b): int => count($b) <=> count($a));
        self::assertSame([['database.connection', 'database.migrations'], ['database.charset']], $sources);

        // Run again: counted, not listed again.
        $this->investigations->runRecipe('database.failing');
        self::assertSame(2, (int)ErrorGroupRecord::find()->where(['environment' => $this->environment])->count());

        // Another environment's are its own.
        $other = 'tests-other-' . bin2hex(random_bytes(3));
        $this->service(['environment' => $other])->runRecipe('database.failing');
        self::assertSame(2, (int)ErrorGroupRecord::find()->where(['environment' => $other])->count());
        self::assertSame(2, (int)ErrorGroupRecord::find()->where(['environment' => $this->environment])->count());

        $stored = (string)json_encode(ErrorGroupRecord::find()->where(['like', 'environment', 'tests-%', false])->asArray()->all());
        self::assertStringNotContainsString('hunter2-lock', $stored);
    }

    /**
     * Credentials of every shape a mailer, an environment, a job or an exception carries, and none
     * of them reaches anything a recipe stores, any page that shows it, or the log.
     */
    public function testNoCredentialARecipesChecksMeetReachesAnyTablePageOrLog(): void
    {
        $token = bin2hex(random_bytes(6));
        $secrets = [
            'smtp' => "smtp-pass-$token",
            'api' => "ak$token" . 'Q9',
            'bearer' => "bearer$token" . 'abcdefXYZ123',
            'client' => "client-secret-$token",
            'pem' => "MIIEvQIBADANBgkqhkiG9w0BAQEFAASC$token",
            'dsn' => "dsnpass$token",
        ];
        $pem = "-----BEGIN PRIVATE KEY-----\n{$secrets['pem']}\n-----END PRIVATE KEY-----";
        $dsn = "mysql://craft:{$secrets['dsn']}@db.internal:3306/craft";

        $mailer = new MailerConfigurationDiagnostic();
        $mailer->settings = new MailSettings([
            'fromEmail' => '',
            'fromName' => 'Example',
            'transportType' => Smtp::class,
            'transportSettings' => ['host' => 'smtp.example.com', 'port' => '587', 'useAuthentication' => true, 'username' => 'apikey', 'password' => $secrets['smtp']],
        ]);

        $this->standIns([
            'environment.configuration' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                $d->id(),
                $d->name(),
                $d->category(),
                DiagnosticStatus::FAIL,
                "Mailer refused: api_key={$secrets['api']}",
                description: "Authorization: Bearer {$secrets['bearer']}",
                evidence: [new Evidence(type: EvidenceType::CONFIGURATION, label: 'Mailer', source: 'environment.configuration', data: ['client_secret' => $secrets['client'], 'dsn' => $dsn, 'key' => $pem])],
            ),
            'queue.failedJobs' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                $d->id(),
                $d->name(),
                $d->category(),
                DiagnosticStatus::FAIL,
                'Queue jobs have failed: 1.',
                evidence: [new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Sending email', source: 'queue.failedJobs', data: ['description' => 'Sending email', 'error' => "SMTP 535 for $dsn with password={$secrets['smtp']}"])],
            ),
            'queue.backlog' => static function() use ($secrets, $dsn): DiagnosticResult {
                throw new RuntimeException("Could not reach $dsn: Authorization: Bearer {$secrets['bearer']}");
            },
        ]);
        $this->withReplaced($mailer);

        $logged = $this->logsDuring(function() use (&$investigation): void {
            $investigation = $this->investigations->runRecipe('email.notSending');
        });
        self::assertInstanceOf(Investigation::class, $investigation);

        $issueIds = IssueRecord::find()->select(['id'])->where(['environment' => $this->environment])->column();
        $stored = (string)json_encode([
            IssueRecord::find()->where(['environment' => $this->environment])->asArray()->all(),
            IssueEventRecord::find()->where(['issueId' => $issueIds])->asArray()->all(),
            EvidenceRecord::find()->where(['issueId' => $issueIds])->asArray()->all(),
            InvestigationRecord::find()->where(['id' => $investigation->id])->asArray()->all(),
            InvestigationStepRecord::find()->where(['investigationId' => $investigation->id])->asArray()->all(),
            RootCauseRecord::find()->where(['investigationId' => $investigation->id])->asArray()->all(),
            ErrorGroupRecord::find()->where(['environment' => $this->environment])->asArray()->all(),
            ErrorSourceRecord::find()->where(['errorGroupId' => ErrorGroupRecord::find()->select(['id'])->where(['environment' => $this->environment])])->asArray()->all(),
        ]);

        // Something was stored, and the settings are there as presence.
        self::assertNotEmpty($issueIds);
        self::assertStringContainsString(Redaction::PRESENT, $stored);
        self::assertSame(1, (int)ErrorGroupRecord::find()->where(['environment' => $this->environment])->count());

        $this->signIn(admin: true);
        $this->request('GET');
        $pages = [
            'the investigation' => $this->renderInvestigation($investigation),
            'the recipes page' => $this->render($this->recipesController(), 'index', 'web-doctor/_recipes/_list'),
            'the issue' => $this->render(new RecordingIssuesController('issues', $this->plugin), 'detail', 'web-doctor/_issues/_issue', ['issueId' => (int)$issueIds[0]]),
        ];

        foreach ($secrets as $kind => $secret) {
            self::assertStringNotContainsString($secret, $stored, "The $kind credential reached the database.");
            self::assertStringNotContainsString($secret, implode("\n", $logged), "The $kind credential reached the log.");

            foreach ($pages as $page => $html) {
                self::assertStringNotContainsString($secret, $html, "The $kind credential reached $page.");
            }
        }

        foreach ($pages as $page => $html) {
            self::assertStringNotContainsString(Redaction::REDACTED, $html, "The raw marker reached $page.");
        }
    }

    // Causes -------------------------------------------------------------------------------

    /**
     * A recipe weighs its leading problem exactly as investigating that issue would weigh the same
     * findings — the same cause, the same confidence, the same support and the same contradiction —
     * and a check that could not complete leaves the recipe partly completed, not wrong.
     */
    public function testARecipeWeighsItsLeadingProblemExactlyAsInvestigatingItWould(): void
    {
        $refused = "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F\\x98\\x80' for column 'title' at row 1";

        $this->standIns([
            'database.charset' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                diagnosticId: $d->id(),
                name: $d->name(),
                category: $d->category(),
                status: DiagnosticStatus::WARNING,
                summary: 'Craft’s element index table does not accept four-byte characters such as emoji.',
                severity: Severity::HIGH,
                evidence: [self::charsetEvidence()],
            ),
            'queue.failedJobs' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                diagnosticId: $d->id(),
                name: $d->name(),
                category: $d->category(),
                status: DiagnosticStatus::FAIL,
                summary: 'Queue jobs have failed: 2.',
                severity: Severity::MEDIUM,
                evidence: [new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Updating search indexes', source: 'queue.failedJobs', data: ['description' => 'Updating search indexes', 'occurrences' => 2, 'error' => $refused])],
            ),
            // A database error that has nothing to do with characters counts against the cause.
            'database.migrations' => static function(): DiagnosticResult {
                throw new DbException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');
            },
        ]);

        $recipe = $this->investigations->runRecipe('database.failing');
        $fromRecipe = (new RootCauses())->forInvestigation($recipe->id);

        $issue = $this->issueFor('database.charset');
        self::assertSame($issue->id, $this->diagnosedFor($recipe)->relatedIssueId);
        self::assertSame(InvestigationStatus::PARTIAL, $recipe->status);
        self::assertSame(1, $recipe->checksIncomplete);

        $fromIssue = (new RootCauses())->forInvestigation($this->investigations->investigate($issue->id)->id);

        $shape = static fn(array $causes): array => array_map(static fn(RootCause $c): array => [
            $c->ruleId,
            $c->confidence,
            array_map(static fn(ConditionOutcome $o): string => $o->id, $c->supporting()),
            array_map(static fn(ConditionOutcome $o): string => $o->id, $c->conflicting()),
        ], $causes);

        self::assertNotEmpty($fromRecipe);
        self::assertSame($shape($fromIssue), $shape($fromRecipe));
        self::assertSame('database.characterSet', $fromRecipe[0]->ruleId);
        // Supported, then a step down for what counts against it; and nothing a recipe finds can
        // be established from checks that did not establish it.
        self::assertSame(Confidence::POSSIBLE, $fromRecipe[0]->confidence);
        self::assertSame(['otherDatabaseErrors'], array_map(static fn(ConditionOutcome $o): string => $o->id, $fromRecipe[0]->conflicting()));

        foreach ($fromRecipe as $cause) {
            self::assertNotSame(Confidence::CONFIRMED, $cause->confidence);
        }
    }

    public function testWeighingThatBreaksIsSaidAndCostsTheRecipeNothingElse(): void
    {
        $this->investigations = $this->service(['rootCauses' => new class() extends RootCauses {
            public function analyse(CorrelationCase $case, ?array $rules = null): RootCauseAnalysis
            {
                throw new RuntimeException('The rules broke.');
            }
        }]);
        $this->standIns(['queue.failedJobs' => DiagnosticStatus::FAIL]);

        $investigation = $this->investigations->runRecipe('queue.jobsFailing');

        self::assertSame(InvestigationStatus::COMPLETED, $investigation->status);
        self::assertStringContainsString('could not be weighed against the known causes', (string)$this->diagnosedFor($investigation)->note);
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $investigation->id])->count());
    }

    // Failure part-way ----------------------------------------------------------------------------

    /**
     * @return array<string, array{InvestigationStepType}>
     */
    public static function failingSteps(): array
    {
        $cases = [];

        foreach ([
            InvestigationStepType::STARTED,
            InvestigationStepType::PLANNED,
            InvestigationStepType::CHECKED,
            InvestigationStepType::RECONCILED,
            InvestigationStepType::RELATED_ISSUE,
            InvestigationStepType::DIAGNOSED,
            InvestigationStepType::FINISHED,
        ] as $type) {
            $cases[$type->value] = [$type];
        }

        return $cases;
    }

    /**
     * Whichever step cannot be written, the recipe's investigation ends as something other than
     * running or completed, its counts are exactly what it has steps for, its timeline has no gap,
     * no cause is kept behind it, and a retry keeps exactly one set without touching it.
     */
    #[DataProvider('failingSteps')]
    public function testARecipeThatCannotRecordAStepEndsTruthfullyAndLeavesNothingBehind(InvestigationStepType $failing): void
    {
        $this->charsetStandIns();
        $this->raise('tests.nearbyDatabase', DiagnosticCategory::DATABASE, DiagnosticContext::currentSiteId());

        $failed = $this->failingWhen(static fn(InvestigationStepType $type): bool => $type === $failing)->runRecipe('database.failing');
        $steps = $this->investigations->steps($failed->id);

        self::assertContains($failed->status, [InvestigationStatus::PARTIAL, InvestigationStatus::FAILED]);
        self::assertNotNull($failed->finishedAt);
        self::assertNotNull($failed->failure);
        self::assertSame(range(0, count($steps) - 1), array_map(static fn(InvestigationStep $s): int => $s->position, $steps));
        self::assertSame(count($this->stepsOf($steps, InvestigationStepType::CHECKED)), $failed->checksRun);
        self::assertSame($failed->checksRun > 0 ? InvestigationStatus::PARTIAL : InvestigationStatus::FAILED, $failed->status);
        self::assertSame(InvestigationStepType::FAILED, $steps[array_key_last($steps)]->type);
        self::assertSame([], $this->stepsOf($steps, InvestigationStepType::FINISHED));
        self::assertSame([], $this->stepsOf($steps, InvestigationStepType::DIAGNOSED));
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $failed->id])->count());

        $retry = $this->investigations->runRecipe('database.failing');

        self::assertSame(InvestigationStatus::COMPLETED, $retry->status);
        self::assertGreaterThan(0, (int)RootCauseRecord::find()->where(['investigationId' => $retry->id])->count());
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $failed->id])->count());
        self::assertSame($failed->status, $this->investigations->get($failed->id)?->status);
    }

    public function testARecipeWhoseCausesCannotBeKeptIsPartlyCompletedWithNoCauseBehindIt(): void
    {
        $this->charsetStandIns();
        $this->investigations = $this->service(['rootCauses' => new class() extends RootCauses {
            public function record(int $investigationId, array $causes): void
            {
                throw new RuntimeException('The causes table is full.');
            }
        }]);

        $investigation = $this->investigations->runRecipe('database.failing');
        $steps = $this->investigations->steps($investigation->id);

        self::assertSame(InvestigationStatus::PARTIAL, $investigation->status);
        self::assertSame([], $this->stepsOf($steps, InvestigationStepType::DIAGNOSED));
        self::assertSame(range(0, count($steps) - 1), array_map(static fn(InvestigationStep $s): int => $s->position, $steps));
        self::assertSame(0, (int)RootCauseRecord::find()->where(['investigationId' => $investigation->id])->count());
    }

    // Place, history and extension ---------------------------------------------------------------

    /**
     * Two recipes on two sites in two environments: each place keeps its own history, newest first,
     * pruned only within itself, and its own issues; issue investigations are never recipe history.
     */
    public function testEachRecipeSiteAndEnvironmentKeepsItsOwnHistoryAndIssues(): void
    {
        $this->standIns(['queue.failedJobs' => DiagnosticStatus::FAIL, 'email.configuration' => DiagnosticStatus::FAIL]);
        $this->investigations->maxPerRecipe = 2;
        $sites = [(int)DiagnosticContext::currentSiteId(), $this->otherSiteId()];
        $made = [];

        foreach ($sites as $siteId) {
            $this->inSite($siteId, function() use ($siteId, &$made): void {
                for ($i = 0; $i < 3; $i++) {
                    foreach (['queue.jobsFailing', 'email.notSending'] as $recipeId) {
                        $made[$siteId][$recipeId][] = $this->investigations->runRecipe($recipeId)->id;
                    }
                }
            });
        }

        $other = 'tests-other-' . bin2hex(random_bytes(3));
        $elsewhere = $this->service(['environment' => $other])->runRecipe('queue.jobsFailing');
        $ofIssue = $this->investigations->investigate($this->issueFor('queue.failedJobs')->id);

        foreach ($sites as $siteId) {
            $history = $this->inSite($siteId, fn(): array => array_map(static fn(Investigation $i): int => $i->id, $this->investigations->recipeHistory()));
            $kept = [...array_slice($made[$siteId]['queue.jobsFailing'], 1), ...array_slice($made[$siteId]['email.notSending'], 1)];
            rsort($kept);

            self::assertSame($kept, $history, "Site $siteId kept the wrong investigations, or in the wrong order.");
            self::assertNotContains($elsewhere->id, $history);
            self::assertNotContains($ofIssue->id, $history);

            // Each site's findings are that site's issues.
            self::assertSame(1, (int)IssueRecord::find()->where(['environment' => $this->environment, 'siteId' => $siteId, 'diagnosticId' => 'queue.failedJobs'])->count());
        }

        // Another environment's run is untouched by this one's pruning, and is its own issue.
        self::assertNotNull($this->investigations->get($elsewhere->id));
        self::assertSame(1, (int)IssueRecord::find()->where(['environment' => $other])->count());
        // An issue's investigation is not pruned by recipes either.
        self::assertNotNull($this->investigations->get($ofIssue->id));
    }

    /**
     * A recipe another plugin contributes is planned, run, recorded, weighed and listed like one of
     * Web Doctor's own; a check it names that is not installed is listed as not covered.
     */
    public function testAContributedRecipeWorksEndToEndLikeWebDoctorsOwn(): void
    {
        $this->standIns(['queue.failedJobs' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
            $d->id(),
            $d->name(),
            $d->category(),
            DiagnosticStatus::FAIL,
            'Queue jobs have failed: 4.',
            evidence: [new Evidence(type: EvidenceType::QUEUE, label: 'Failed jobs', source: 'queue.failedJobs', data: ['failed' => 4])],
        )]);
        $this->recipesRegistry()->register(new Recipe(
            id: 'otherPlugin.syncFailing',
            title: 'Sync Doctor',
            symptom: 'Entries are not syncing',
            description: 'Looks at the queue the sync runs on.',
            category: DiagnosticCategory::PLUGINS,
            primary: [RelatedArea::check('queue.failedJobs', 'The sync runs on the queue.'), RelatedArea::check('otherPlugin.remote', 'The remote API.')],
        ));

        $this->signIn(admin: true);
        $this->post(['recipeId' => 'otherPlugin.syncFailing', 'depth' => 'normal']);
        $this->recipesController()->runAction('run');
        $made = $this->investigations->recipeHistory();

        self::assertCount(1, $made);
        self::assertSame(['queue.failedJobs'], $made[0]->plan->diagnosticIds());
        self::assertSame(['otherPlugin.remote'], array_column($made[0]->plan->uncovered, 'area'));

        $issue = $this->issueFor('queue.failedJobs');
        self::assertSame($issue->id, $this->diagnosedFor($made[0])->relatedIssueId);
        self::assertSame(1, (int)EvidenceRecord::find()->where(['issueId' => $issue->id, 'diagnosticId' => 'queue.failedJobs'])->count());

        $this->request('GET');
        $list = $this->render($this->recipesController(), 'index', 'web-doctor/_recipes/_list');
        self::assertStringContainsString('Entries are not syncing', $list);
        self::assertStringContainsString("web-doctor/recipes/otherPlugin.syncFailing/investigations/{$made[0]->id}", $list);
        self::assertStringContainsString('Sync Doctor: Entries are not syncing', $this->renderInvestigation($made[0]));
    }

    /**
     * Contributors that pass something that is not a recipe, build one out of something that is
     * not an area, or throw, cost only their own recipes; the log names what failed without
     * repeating a credential it quoted.
     */
    public function testABrokenContributorCostsOnlyItsOwnRecipes(): void
    {
        $good = new Recipe('goodPlugin.fine', 'Fine Doctor', 'It is fine', 'Looks at the queue.', DiagnosticCategory::QUEUE, [RelatedArea::category(DiagnosticCategory::QUEUE, 'The queue.')]);
        // What a contributor with no static analysis might pass: a check ID where an area belongs.
        $notAnArea = unserialize(serialize('queue.failedJobs'));
        $breakers = [
            'a contributor that throws' => static function(): void {
                throw new RuntimeException('Contributor exploded: password=hunter2-contrib');
            },
            'a recipe built from something that is not an area' => static function() use ($notAnArea): void {
                new Recipe('badPlugin.broken', 'Broken', 'Something', 'Nothing.', DiagnosticCategory::QUEUE, [$notAnArea]);
            },
        ];

        foreach ($breakers as $case => $breaker) {
            Event::on(Recipes::class, Recipes::EVENT_REGISTER_RECIPES, static function($event) use ($good): void {
                $event->recipes[] = $good;
                $event->recipes[] = new \stdClass();
            });
            Event::on(Recipes::class, Recipes::EVENT_REGISTER_RECIPES, $breaker);

            $ids = [];

            try {
                $registry = new Recipes(['includeCoreRecipes' => true]);
                $logged = $this->logsDuring(function() use ($registry, &$ids): void {
                    $ids = array_map(static fn(Recipe $r): string => $r->id, $registry->all());
                });
            } finally {
                Event::off(Recipes::class, Recipes::EVENT_REGISTER_RECIPES);
            }

            // Web Doctor's own, and what was contributed before it broke.
            self::assertSame([...array_map(static fn(Recipe $r): string => $r->id, CoreRecipes::all()), 'goodPlugin.fine'], $ids, $case);
            // The object that was not a recipe, and the contributor that broke.
            self::assertCount(2, $logged, $case);
            self::assertStringNotContainsString('hunter2-contrib', implode("\n", $logged), $case);
        }
    }

    /**
     * Everything a contributor writes — the name, the symptom, the description, every reason and
     * every lead — is redacted as the recipe is built, and the page that shows its plan before
     * anything has run shows each withheld value as a mark.
     */
    public function testAContributedRecipesOwnWordsAreRedactedWhereverTheyAreShown(): void
    {
        $recipe = new Recipe(
            id: 'otherPlugin.leaky',
            title: 'Doctor password=hunter2-title',
            symptom: 'token=hunter2-symptom',
            description: 'Uses api_key=hunter2-desc',
            category: DiagnosticCategory::QUEUE,
            primary: [RelatedArea::check('queue.failedJobs', 'Connects with secret=hunter2-reason')],
            related: [RelatedArea::check('otherPlugin.gone', 'Not installed; client_secret=hunter2-uncovered')],
            leads: ['Look at mysql://craft:hunter2-lead@db/craft'],
        );

        foreach ([$recipe->title, $recipe->symptom, $recipe->description, $recipe->primary[0]->reason, $recipe->related[0]->reason, ...$recipe->leads] as $text) {
            self::assertStringNotContainsString('hunter2', $text);
        }

        $this->standIns();
        $this->recipesRegistry()->register($recipe);
        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->recipesController(), 'index', 'web-doctor/_recipes/_list');

        self::assertStringContainsString('id="recipe-otherPlugin.leaky"', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString(Redaction::REDACTED, $html);
    }

    // Reading ------------------------------------------------------------------------------------

    /**
     * Who may do what, answered by the controllers themselves: the page and the run are control
     * panel only, the page needs "View issues", running needs "Investigate issues" and a POST with
     * a token, and neither ever lets a visitor in without an identity.
     */
    public function testEveryRouteToARecipeAnswersOnlyToWhoeverMay(): void
    {
        $this->standIns();
        $cp = [self::ACCESS_CP];
        $view = [self::ACCESS_CP, Permissions::VIEW];
        $issues = [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES];

        // [action, method, admin, permissions, control panel, refusal]
        $cases = [
            'the page, control panel access only' => ['index', 'GET', false, $cp, true, ForbiddenHttpException::class],
            'the page, Web Doctor only' => ['index', 'GET', false, $view, true, ForbiddenHttpException::class],
            'the page, from the front end' => ['index', 'GET', true, [], false, BadRequestHttpException::class],
            'a run, control panel access only' => ['run', 'POST', false, $cp, true, ForbiddenHttpException::class],
            'a run, Web Doctor only' => ['run', 'POST', false, $view, true, ForbiddenHttpException::class],
            'a run, reading issues only' => ['run', 'POST', false, $issues, true, ForbiddenHttpException::class],
            'a run, from the front end' => ['run', 'POST', true, [], false, BadRequestHttpException::class],
            'a run by GET' => ['run', 'GET', true, [], true, MethodNotAllowedHttpException::class],
        ];

        foreach ($cases as $case => [$action, $method, $admin, $permissions, $inCp, $refusal]) {
            $this->signIn(admin: $admin, permissions: $permissions);
            $request = $this->request($method, cp: $inCp);

            if ($method === 'POST') {
                $request->setBodyParams(['recipeId' => 'queue.jobsFailing', $request->csrfParam => $request->getCsrfToken()]);
            }

            try {
                $this->recipesController()->runAction($action);
                self::fail("$case was allowed.");
            } catch (\Throwable $e) {
                self::assertInstanceOf($refusal, $e, $case);
            }
        }

        self::assertCount(0, $this->investigations->recipeHistory());
        self::assertSame(0, array_sum(array_map(static fn(TestDiagnostic $d): int => $d->runs, $this->checks)));
        self::assertSame(RecordingRecipesController::ALLOW_ANONYMOUS_NEVER, $this->recipesController()->anonymousAccess());
        self::assertSame(RecordingInvestigationsController::ALLOW_ANONYMOUS_NEVER, $this->investigationsController()->anonymousAccess());

        // Somebody who may investigate, and an admin, each run one.
        foreach ([[false, [...$issues, Permissions::INVESTIGATE_ISSUES]], [true, []]] as $i => [$admin, $permissions]) {
            $this->signIn(admin: $admin, permissions: $permissions);
            $this->post(['recipeId' => 'queue.jobsFailing']);
            $this->recipesController()->runAction('run');
            self::assertCount($i + 1, $this->investigations->recipeHistory());
        }
    }

    /**
     * A recipe's investigation is found under its own recipe or not at all. Every reader who may
     * read issues reads every environment's and site's, as the rest of the Issue Center does, so
     * one recorded elsewhere is shown, saying where it was run.
     */
    public function testARecipesInvestigationIsFoundOnlyWhereItIsAndSaysWhereItRan(): void
    {
        $this->standIns();
        $investigation = $this->investigations->runRecipe('queue.jobsFailing');
        $other = 'tests-other-' . bin2hex(random_bytes(3));
        $elsewhere = $this->service(['environment' => $other])->runRecipe('queue.jobsFailing');
        $gone = $this->investigations->runRecipe('queue.jobsFailing');
        InvestigationRecord::deleteAll(['id' => $gone->id]);

        $this->signIn(admin: true);
        $this->request('GET');

        foreach ([
            'a nonexistent investigation' => ['queue.jobsFailing', 999999999],
            'a deleted investigation' => ['queue.jobsFailing', $gone->id],
            'a recipe that does not exist' => ['otherPlugin.nothing', $investigation->id],
        ] as $case => [$recipeId, $investigationId]) {
            try {
                $this->investigationsController()->runAction('recipe-detail', ['recipeId' => $recipeId, 'investigationId' => $investigationId]);
                self::fail("$case was found.");
            } catch (NotFoundHttpException) {
            }
        }

        $html = $this->renderInvestigation($elsewhere);
        self::assertStringContainsString($other, $html);
    }

    // Helpers --------------------------------------------------------------------

    private const MISSING = '(missing)';

    private function service(array $overrides = []): Investigations
    {
        return new Investigations($overrides + [
            'registry' => $this->registry,
            'engine' => new DiagnosticEngine(['registry' => $this->registry]),
            'issues' => $this->issues,
            'errors' => new Errors(['issues' => $this->issues]),
            'rootCauses' => new RootCauses(),
            'recipes' => $this->investigations->recipes,
            'environment' => $this->environment,
        ]);
    }

    private function recipesRegistry(): Recipes
    {
        $recipes = $this->investigations->recipes;

        self::assertInstanceOf(Recipes::class, $recipes);

        return $recipes;
    }

    /**
     * Stand-ins that pass and record the depth each was asked to run at, in the order they ran.
     */
    private function recordingDepths(): object
    {
        $seen = new class() {
            /** @var array<string, DiagnosticDepth> */
            public array $depths = [];
        };

        $this->standIns();

        foreach ($this->checks as $check) {
            $check->handler = static function(TestDiagnostic $d, DiagnosticContext $context) use ($seen): DiagnosticResult {
                $seen->depths[$d->id()] = $context->depth;

                return new DiagnosticResult($d->id(), $d->name(), $d->category(), DiagnosticStatus::PASS, 'Nothing wrong.');
            };
        }

        return $seen;
    }

    private function charsetStandIns(): void
    {
        $this->standIns([
            'database.charset' => static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                diagnosticId: $d->id(),
                name: $d->name(),
                category: $d->category(),
                status: DiagnosticStatus::WARNING,
                summary: 'Craft’s element index table does not accept four-byte characters such as emoji.',
                evidence: [self::charsetEvidence()],
            ),
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
     * The service with one kind of step made to fail to save, the first time it is written.
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
            'recipes' => $this->investigations->recipes,
            'environment' => $this->environment,
        ];

        return new class($when, $config) extends Investigations {
            private bool $failed = false;

            /**
             * @param array<string, mixed> $config
             */
            public function __construct(private Closure $when, array $config)
            {
                parent::__construct($config);
            }

            protected function step(InvestigationRecord $investigation, int &$position, InvestigationStepType $type, \DateTimeInterface $at, array $fields = []): void
            {
                if (!$this->failed && ($this->when)($type)) {
                    $this->failed = true;

                    throw new RuntimeException('Step refused: password=hunter2-step');
                }

                parent::step($investigation, $position, $type, $at, $fields);
            }
        };
    }

    /**
     * Runs the callback with another site current, and puts the current one back.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function inSite(int $siteId, Closure $callback): mixed
    {
        $sites = Craft::$app->getSites();
        $original = $sites->getCurrentSite();
        $site = $sites->getSiteById($siteId);

        self::assertNotNull($site);
        $sites->setCurrentSite($site);

        try {
            return $callback();
        } finally {
            $sites->setCurrentSite($original);
        }
    }

    private function otherSiteId(): int
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ($site->id !== DiagnosticContext::currentSiteId()) {
                return (int)$site->id;
            }
        }

        self::markTestSkipped('This installation has only one site.');
    }

    /**
     * What was logged while the callback ran.
     *
     * @return list<string>
     */
    private function logsDuring(Closure $callback): array
    {
        $original = \Yii::getLogger();
        $logger = new \yii\log\Logger();
        $logger->flushInterval = PHP_INT_MAX;
        \Yii::setLogger($logger);

        try {
            $callback();
        } finally {
            \Yii::setLogger($original);
        }

        return array_values(array_map(static fn(array $m): string => is_string($m[0]) ? $m[0] : (string)json_encode($m[0]), $logger->messages));
    }

    private static function coreRecipes(): Recipes
    {
        // Only Web Doctor's own, for the reason the registry is the test's own.
        return new class(['includeCoreRecipes' => true]) extends Recipes {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };
    }

    private function recipe(string $id): Recipe
    {
        $recipe = self::coreRecipes()->get($id);

        self::assertInstanceOf(Recipe::class, $recipe);

        return $recipe;
    }

    /**
     * Registers a stand-in for every check Web Doctor ships with, under its ID and category, passing
     * unless the test states otherwise.
     *
     * @param array<string, DiagnosticStatus|array{DiagnosticStatus, Severity}|Closure(TestDiagnostic, DiagnosticContext): DiagnosticResult> $outcomes
     * @param bool $reversed Registered in the opposite order to the one Web Doctor lists them in.
     */
    private function standIns(array $outcomes = [], bool $reversed = false): void
    {
        $all = CoreDiagnostics::all();

        foreach ($reversed ? array_reverse($all) : $all as $shipped) {
            $id = DiagnosticMeta::id($shipped);
            $this->checks[$id] = $this->standIn($id, DiagnosticMeta::category($shipped), $outcomes[$id] ?? DiagnosticStatus::PASS);
        }
    }

    /**
     * @param DiagnosticStatus|array{DiagnosticStatus, Severity}|Closure(TestDiagnostic, DiagnosticContext): DiagnosticResult $outcome
     */
    private function standIn(string $id, DiagnosticCategory $category, DiagnosticStatus|array|Closure $outcome): TestDiagnostic
    {
        [$status, $severity] = is_array($outcome) ? $outcome : [$outcome, null];

        $diagnostic = new TestDiagnostic();
        $diagnostic->diagnosticId = $id;
        $diagnostic->diagnosticName = "Check $id";
        $diagnostic->diagnosticCategory = $category;
        $diagnostic->handler = $status instanceof Closure ? $status : static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: $status,
            summary: $status->isProblem() ? "$id reports a problem." : 'Nothing wrong.',
            severity: $severity,
        );

        $this->registry->register($diagnostic);

        return $diagnostic;
    }

    /**
     * The test's registry with one stand-in replaced by a real check, and the service pointed at it.
     */
    private function withReplaced(\Tahadudhiya\WebDoctor\base\DiagnosticInterface $real): Diagnostics
    {
        $registry = new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };

        $registry->register($real);

        foreach ($this->registry->all() as $diagnostic) {
            if (DiagnosticMeta::id($diagnostic) !== $real->id()) {
                $registry->register($diagnostic);
            }
        }

        $this->investigations->registry = $registry;
        $this->investigations->engine = new DiagnosticEngine(['registry' => $registry]);

        return $registry;
    }

    /**
     * An issue raised the way a run would raise one.
     */
    private function raise(string $diagnosticId, DiagnosticCategory $category = DiagnosticCategory::DATABASE, ?int $siteId = null, ?string $environment = null): Issue
    {
        $context = new DiagnosticContext(siteId: $siteId, environment: $environment ?? $this->environment);
        $now = new DateTimeImmutable();
        $result = (new DiagnosticResult(
            diagnosticId: $diagnosticId,
            name: "Check $diagnosticId",
            category: $category,
            status: DiagnosticStatus::FAIL,
            summary: "$diagnosticId reports a problem.",
            severity: Severity::HIGH,
        ))->withExecution($context, $now, $now, 1.0);

        $this->issues->reconcile(new DiagnosticRun(context: $context, results: [$result], startedAt: $now, finishedAt: $now, durationMs: 1.0));
        $issue = $this->issues->getByFingerprint(Fingerprint::forResult($result, $context->environment, $siteId));

        self::assertInstanceOf(Issue::class, $issue);

        return $issue;
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

    private function diagnosedFor(Investigation $investigation): InvestigationStep
    {
        $diagnosed = $this->stepsOf($this->investigations->steps($investigation->id), InvestigationStepType::DIAGNOSED);

        self::assertCount(1, $diagnosed);

        return $diagnosed[0];
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
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor/recipes" : '/actions/web-doctor/recipes/run';
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

    private function recipesController(): RecordingRecipesController
    {
        return new RecordingRecipesController('recipes', $this->plugin);
    }

    private function investigationsController(): RecordingInvestigationsController
    {
        return new RecordingInvestigationsController('investigations', $this->plugin);
    }

    private function renderInvestigation(Investigation $investigation): string
    {
        return $this->render($this->investigationsController(), 'recipe-detail', 'web-doctor/_investigations/_investigation', [
            'recipeId' => (string)$investigation->recipeId,
            'investigationId' => $investigation->id,
        ]);
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
