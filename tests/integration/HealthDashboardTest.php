<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\elements\User;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\controllers\HistoryController;
use Tahadudhiya\WebDoctor\enums\AuditAction;
use Tahadudhiya\WebDoctor\enums\AuditObjectType;
use Tahadudhiya\WebDoctor\enums\AuditResult;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\AuditEntry;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\HealthSummary;
use Tahadudhiya\WebDoctor\records\AuditRecord;
use Tahadudhiya\WebDoctor\records\DiagnosticRunRecord;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\services\Audit;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\History;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\Runs;
use Tahadudhiya\WebDoctor\Tests\_support\BreakingRuleRecommendations;
use Tahadudhiya\WebDoctor\Tests\_support\ExplodingDiagnostics;
use Tahadudhiya\WebDoctor\Tests\_support\FailingCache;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingOverviewController;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\Tests\_support\WebDoctorTables;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\caching\ArrayCache;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

/**
 * The health dashboard, inside a real Craft application.
 *
 * Everything here goes through `runAction()` rather than calling the action method, so the whole
 * chain a real request meets — control panel check, CSRF validation, permission — is what is
 * being tested. Calling the action directly would test the body of a method and prove nothing
 * about who is allowed to reach it.
 */
class HealthDashboardTest extends TestCase
{
    /** @var string The permission Craft itself demands of anyone reaching the control panel. */
    private const ACCESS_CP = 'accessCp';

    /** @var string A parameter left out of the request, as against one sent empty. */
    private const MISSING = '(missing)';

    private WebDoctor $plugin;
    private TestDiagnostic $diagnostic;
    private ArrayCache $cache;
    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new ArrayCache();
        $this->plugin = $this->pluginWith(['class' => Diagnostics::class]);

        $this->diagnostic = new TestDiagnostic([
            'diagnosticId' => 'tests.dashboard',
            'diagnosticName' => 'Dashboard example check',
            'diagnosticCategory' => DiagnosticCategory::CONFIGURATION,
            'handler' => static fn(TestDiagnostic $d) => $d->build('fail', [
                'Something is broken.',
                [],
                'Fix it.',
                Severity::CRITICAL,
            ]),
        ]);

        $this->plugin->getDiagnostics()->register($this->diagnostic);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // Running the dashboard's action reconciles what it found into the Issue Center, which
        // is a real table in whoever's installation these tests run in. Only the rows this
        // test's own check could have raised are removed, matched on the ID it registered under.
        if (Craft::$app->getDb()->tableExists(IssueRecord::TABLE)) {
            IssueRecord::deleteAll(['like', 'diagnosticId', 'tests.%', false]);
        }

        // The errors this test's checks ran into, found through the sources that name them.
        if (Craft::$app->getDb()->tableExists(ErrorGroupRecord::TABLE)) {
            ErrorGroupRecord::deleteAll(['id' => ErrorSourceRecord::find()->select(['errorGroupId'])->where(['like', 'diagnosticId', 'tests.%', false])->column()]);
        }

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

    /**
     * The plugin as Craft builds it, but with a registry and a cache of this test's own — so the
     * dashboard is exercised against one known check rather than whatever the installation
     * happens to contain, and a test run never leaves a stored run behind in the host project.
     *
     * @param array<string, mixed> $registry
     */
    private function pluginWith(array $registry): WebDoctor
    {
        $config = WebDoctor::config();
        $config['components']['diagnostics'] = $registry;
        $config['components']['runs'] = ['class' => Runs::class, 'cache' => $this->cache];

        return new WebDoctor('web-doctor', Craft::$app, $config + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
    }

    /**
     * A request shaped the way Craft would see one.
     *
     * Whether a request is for the control panel is stated outright, the same way Craft states
     * it for an installation whose control panel has its own webroot: `App::webRequestConfig()`
     * reads the `CRAFT_CP` constant and configures the request with it. Yii derives its base URL
     * from the running script, which under a test runner is the PHPUnit binary, so Craft's
     * URL-scoring path cannot reach a verdict here — but the flag it would set is the same flag
     * the controller reads, so both answers are exercised for real.
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
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor" : '/actions/web-doctor/overview/run';
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

        // Craft resolves the formatting locale from the signed-in user's preferences on a
        // control panel request, which needs a session a console run does not have. Resolving it
        // from the application language first, once, keeps date formatting real without the
        // template asking for something this harness cannot provide.
        if (!Craft::$app->has('formattingLocale', true)) {
            Craft::$app->set('formattingLocale', Craft::$app->getI18n()->getLocaleById(Craft::$app->language));
        }

        $request = new WebRequest();
        $request->setIsCpRequest($cp);
        // Craft configures this from the security key when it serves a web request; a request
        // built by hand has to be told, because CSRF tokens are signed with it.
        $request->cookieValidationKey = Craft::$app->getConfig()->getGeneral()->securityKey;

        Craft::$app->set('request', $request);
        Craft::$app->set('response', new WebResponse());

        return $request;
    }

    /**
     * A POST carrying the CSRF token Craft would have issued for this request and this user.
     *
     * @param array<string, mixed> $params
     */
    private function post(array $params = [], bool $cp = true): WebRequest
    {
        $request = $this->request('POST', $cp);
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

    /**
     * @param array<string, mixed> $params
     */
    private function renderWith(\craft\web\Controller $controller, string $action, string $template, array $params = []): string
    {
        $response = $controller->runAction($action, $params);

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return Craft::$app->getView()->renderTemplate($template, $behavior->variables, View::TEMPLATE_MODE_CP);
    }

    private function controller(?WebDoctor $plugin = null): RecordingOverviewController
    {
        return new RecordingOverviewController('overview', $plugin ?? $this->plugin);
    }

    /**
     * Runs the dashboard the way Craft runs it, through the controller's whole `beforeAction`
     * chain, and renders what comes back.
     */
    private function render(?WebDoctor $plugin = null): string
    {
        $response = $this->controller($plugin)->runAction('index');

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        // The variables are the ones the controller actually supplied, and the template is the
        // real one. Only Craft's own control panel shell is left out, because it asks for a
        // session and a console run has none.
        return Craft::$app->getView()->renderTemplate(
            'web-doctor/_dashboard',
            $behavior->variables,
            View::TEMPLATE_MODE_CP,
        );
    }

    private function siteId(): ?int
    {
        try {
            return Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            return null;
        }
    }

    // Execution model ------------------------------------------------------

    public function testOpeningTheDashboardRunsNothing(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        $this->controller()->runAction('index');

        self::assertSame(0, $this->diagnostic->runs);
    }

    public function testOpeningTheDashboardWithNoPreviousRunSaysSo(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render();

        self::assertStringContainsString('No checks have been run on this site yet.', $html);
        self::assertSame(0, $this->diagnostic->runs);
    }

    public function testTheDashboardShowsWhatTheLastRunConcluded(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        // A second, ordinary page load: the results are read back, not produced again.
        $this->request('GET');
        $html = $this->render();

        self::assertSame(1, $this->diagnostic->runs);
        self::assertStringContainsString('Dashboard example check', $html);
        self::assertStringContainsString('Something is broken.', $html);
        self::assertStringContainsString('Fix it.', $html);
        self::assertStringContainsString('tests.dashboard', $html);
    }

    public function testARunIsRecordedStartingAndEndingAndATrailThatCannotBeWrittenDoesNotStopIt(): void
    {
        $this->signIn(admin: true);
        $mark = (int)AuditRecord::find()->max('id');

        // A selection that reduces to nothing runs nothing, and records nothing.
        $this->post(['diagnostics' => ['tests.notRegistered']]);
        $this->controller()->runAction('run');
        self::assertSame(0, (int)AuditRecord::find()->where(['>', 'id', $mark])->count());

        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $entries = array_map(
            static fn(mixed $r): ?AuditEntry => $r instanceof AuditRecord ? AuditEntry::fromRecord($r) : null,
            AuditRecord::find()->where(['>', 'id', $mark])->orderBy(['id' => SORT_ASC])->all(),
        );

        self::assertSame([AuditAction::DIAGNOSTICS_STARTED, AuditAction::DIAGNOSTICS_COMPLETED], array_map(static fn(?AuditEntry $e) => $e?->action, $entries));
        self::assertSame($entries[0]->objectId, $entries[1]->objectId, 'The start and the end name different runs.');
        self::assertSame(AuditObjectType::RUN, $entries[1]->objectType);
        self::assertSame(1, $entries[0]->details['checks'] ?? null);
        self::assertSame(AuditResult::SUCCEEDED, $entries[1]->result);
        self::assertSame(1, $entries[1]->details['fail'] ?? null);
        self::assertSame(TestUser::USERNAME, $entries[1]->userName);

        // The run is its own record: a trail that cannot be written is logged, and the checks still run.
        $this->plugin->set('audit', new class() extends Audit {
            protected function save(AuditRecord $record): void
            {
                throw new \RuntimeException('The audit entry would not write.');
            }
        });
        $mark = (int)AuditRecord::find()->max('id');
        $this->post(['all' => '1']);
        $controller = $this->controller();
        $controller->runAction('run');

        self::assertSame(2, $this->diagnostic->runs);
        self::assertSame('success', $controller->lastFlash()['level'] ?? null);
        self::assertSame(0, (int)AuditRecord::find()->where(['>', 'id', $mark])->count());
    }

    public function testEachRunIsKeptAsItHappenedWithAHealthSnapshotOnlyWhenItCoveredEveryCheck(): void
    {
        $this->signIn(admin: true);
        $this->plugin->getDiagnostics()->register(new TestDiagnostic(['diagnosticId' => 'tests.second', 'diagnosticName' => 'Second check']));
        $mark = (int)DiagnosticRunRecord::find()->max('id');

        $this->post(['all' => '1']);
        $this->controller()->runAction('run');
        $complete = $this->plugin->getHistory()->get((int)DiagnosticRunRecord::find()->max('id'));

        self::assertNotNull($complete);
        self::assertGreaterThan($mark, $complete->id);
        self::assertTrue($complete->complete);
        self::assertTrue($complete->hasSnapshot());
        // A critical failure costs thirty, as the published weights say.
        self::assertSame(HealthSummary::MAX_SCORE - HealthSummary::weights()['critical'], $complete->score);
        self::assertSame(HealthSummary::weights(), $complete->weights);
        self::assertSame([['diagnosticId' => 'tests.dashboard', 'status' => 'fail', 'severity' => 'critical', 'penalty' => 30]], $complete->contributions);
        self::assertSame(['tests.dashboard', 'tests.second'], array_column($complete->results, 'diagnosticId'));
        self::assertSame(TestUser::USERNAME, $complete->userName);
        self::assertSame([], $complete->unreadable);

        // The trail's entry for the run leads to it.
        $record = AuditRecord::find()->where(['action' => AuditAction::DIAGNOSTICS_COMPLETED->value, 'objectId' => $complete->runId])->one();
        self::assertInstanceOf(AuditRecord::class, $record);
        $entry = AuditEntry::fromRecord($record);
        self::assertSame('web-doctor/history/' . $complete->id, $entry->cpPath());

        // A run of one check of two is partial, and keeps no score, as the overview shows none.
        $this->post(['diagnostics' => ['tests.dashboard']]);
        $this->controller()->runAction('run');
        $partial = $this->plugin->getHistory()->get((int)DiagnosticRunRecord::find()->max('id'));
        self::assertFalse($partial->complete);
        self::assertFalse($partial->hasSnapshot());
        // Kept without a score, not with one the reader then has to refuse.
        self::assertSame([], $partial->unreadable);
        self::assertNull(DiagnosticRunRecord::findOne($partial->id)?->score);
        self::assertSame([1, 2], [$partial->checksRun, $partial->checksRegistered]);

        // History is never worked out again: a check registered since changes nothing about it.
        $this->plugin->getDiagnostics()->register(new TestDiagnostic(['diagnosticId' => 'tests.third', 'diagnosticName' => 'Third check']));
        $again = $this->plugin->getHistory()->get($complete->id);
        self::assertSame([$complete->score, $complete->checksRegistered, $complete->results], [$again->score, $again->checksRegistered, $again->results]);

        // Both pages read what was kept.
        $this->request('GET');
        $list = $this->renderWith(new HistoryController('history', $this->plugin), 'index', 'web-doctor/_history/_runs');
        self::assertStringContainsString('web-doctor/history/' . $complete->id, $list);
        self::assertStringContainsString('<strong>' . $complete->score . '</strong>', $list);
        $this->request('GET');
        $detail = $this->renderWith(new HistoryController('history', $this->plugin), 'detail', 'web-doctor/_history/_run', ['runId' => $complete->id]);
        self::assertStringContainsString('Dashboard example check', $detail);
        self::assertStringContainsString('Something is broken.', $detail);
        self::assertStringContainsString('−30', $detail);
    }

    public function testARunTheHistoryCannotKeepIsSaidAndTheTrailCallsItPartial(): void
    {
        $this->signIn(admin: true);
        $this->plugin->set('history', new class() extends History {
            protected function save(DiagnosticRunRecord $record): void
            {
                throw new \RuntimeException('The history row would not save.');
            }
        });
        $mark = (int)DiagnosticRunRecord::find()->max('id');
        $this->post(['all' => '1']);
        $controller = $this->controller();
        $controller->runAction('run');

        self::assertSame(1, $this->diagnostic->runs, 'The checks did not run.');
        self::assertSame('fail', $controller->lastFlash()['level'] ?? null);
        self::assertStringContainsString('diagnostic history', (string)$controller->lastFlash()['message']);
        self::assertSame($mark, (int)DiagnosticRunRecord::find()->max('id'), 'A run was kept that could not be.');

        $entry = AuditRecord::find()->where(['action' => AuditAction::DIAGNOSTICS_COMPLETED->value])->orderBy(['id' => SORT_DESC])->one();
        self::assertInstanceOf(AuditRecord::class, $entry);
        self::assertSame(AuditResult::PARTIAL->value, $entry->result);
    }

    public function testTheDashboardShowsTheScoreWithTheArithmeticBehindIt(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('Health score', $html);
        self::assertStringContainsString('How this score was calculated', $html);
        // One critical failure against a full score.
        self::assertStringContainsString('>70<', $html);
    }

    public function testTheRunIsRemembered(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);

        $this->controller()->runAction('run');

        $run = $this->plugin->getRuns()->latest($this->siteId());

        self::assertNotNull($run);
        self::assertSame(1, $run->count());
        self::assertSame(DiagnosticStatus::FAIL, $run->resultFor('tests.dashboard')?->status);
    }

    public function testOnlyRegisteredChecksCanBeAskedFor(): void
    {
        $this->signIn(admin: true);
        $this->post(['selected' => '1', 'diagnostics' => ['tests.dashboard', 'not.registered']]);

        $this->controller()->runAction('run');

        $run = $this->plugin->getRuns()->latest($this->siteId());

        self::assertNotNull($run);
        self::assertSame(['tests.dashboard'], array_map(
            static fn($result): string => $result->diagnosticId,
            $run->results(),
        ));
    }

    /**
     * "No particular site" is Craft saying there is no site, and nothing else. A site Craft cannot
     * tell for any other reason is a failure the page says, never a run filed as installation-wide.
     */
    public function testASiteCraftCannotTellIsSaidAndNeverReadAsNoSite(): void
    {
        $original = Craft::$app->getSites();
        // Armed only once built: Craft's own sites service asks for the current site as it starts.
        $failing = static function(\Throwable $e): \craft\services\Sites {
            $sites = new class() extends \craft\services\Sites {
                public ?\Throwable $fails = null;

                public function getCurrentSite(): \craft\models\Site
                {
                    return $this->fails === null ? parent::getCurrentSite() : throw $this->fails;
                }
            };
            $sites->fails = $e;

            return $sites;
        };

        try {
            Craft::$app->set('sites', $failing(new \craft\errors\SiteNotFoundException('No primary site exists')));
            self::assertNull(DiagnosticContext::currentSiteId());

            Craft::$app->set('sites', $failing(new \RuntimeException('The sites table could not be read.')));

            try {
                DiagnosticContext::currentSiteId();
                self::fail('A failure to read the site was read as no site.');
            } catch (\RuntimeException $e) {
                self::assertSame('The sites table could not be read.', $e->getMessage());
            }

            // The dashboard answers with the failure for the page to say, rather than failing the
            // request — and with no run, so no site is named for it. (Rendering is not asserted:
            // Craft's own template globals ask for the current site, so no page renders then. A
            // request is built while the site can be told: Craft's own request asks for it too.)
            Craft::$app->set('sites', $original);
            $this->signIn(admin: true);
            $this->request('GET');
            Craft::$app->set('sites', $failing(new \RuntimeException('The sites table could not be read.')));
            $response = $this->controller()->runAction('index');
            /** @var TemplateResponseBehavior $behavior */
            $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);
            self::assertStringContainsString('could not read the last diagnostic run', (string)$behavior->variables['failure']);
            self::assertFalse($behavior->variables['dashboard']->hasRun());

            // And a run set going then runs nothing and records nothing anywhere.
            Craft::$app->set('sites', $original);
            $this->post(['all' => '1']);
            $before = WebDoctorTables::snapshot();
            Craft::$app->set('sites', $failing(new \RuntimeException('The sites table could not be read.')));
            $controller = $this->controller();

            try {
                $controller->runAction('run');
            } catch (\Throwable $e) {
                // Craft's own redirect builds its URL from the current site; the failure had been
                // handled before it: said, and nothing run.
                self::assertInstanceOf(\RuntimeException::class, $e);
                self::assertSame('The sites table could not be read.', $e->getMessage());
            }

            self::assertSame(0, $this->diagnostic->runs);
            self::assertSame('fail', $controller->lastFlash()['level'] ?? null);
            self::assertSame($before, WebDoctorTables::snapshot());
        } finally {
            Craft::$app->set('sites', $original);
        }
    }

    public function testSelectingNothingRunsNothing(): void
    {
        // A form where nothing is ticked posts almost what a form with no selection posts. If
        // the difference were guessed at, a reader who ticked nothing would set the whole suite
        // going without asking for it.
        $this->signIn(admin: true);
        $this->post(['selected' => '1']);

        $controller = $this->controller();
        $controller->runAction('run');

        self::assertSame(0, $this->diagnostic->runs);
        self::assertNull($this->plugin->getRuns()->latest($this->siteId()));
        self::assertSame('fail', $controller->lastFlash()['level'] ?? null);
    }

    public function testAskingForEverythingIgnoresTheSelection(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1', 'diagnostics' => ['not.registered']]);

        $this->controller()->runAction('run');

        self::assertSame(1, $this->diagnostic->runs);
    }

    /**
     * Every depth a request could send is read in DiagnosticModelTest; these prove the run is
     * handed what was read, and that a refusal comes before anything runs.
     *
     * @return array<string, array{mixed, string|null}>
     */
    public static function requestedDepths(): array
    {
        return [
            'deep' => ['deep', 'deep'],
            'missing' => [self::MISSING, 'normal'],
            'the wrong case' => ['Deep', null],
        ];
    }

    /**
     * Missing, a run is at normal depth, as the form states. Anything that is not one of the three
     * depths is refused before anything runs, is remembered or is written.
     */
    #[DataProvider('requestedDepths')]
    public function testARunIsAtExactlyTheDepthAskedForOrNotAtAll(mixed $requested, ?string $expected): void
    {
        $this->signIn(admin: true);
        $this->post($requested === self::MISSING ? ['all' => '1'] : ['all' => '1', 'depth' => $requested]);

        if ($expected === null) {
            $before = WebDoctorTables::snapshot();

            try {
                $this->controller()->runAction('run');
                self::fail('A run was started at a depth Web Doctor does not have.');
            } catch (BadRequestHttpException) {
            }

            self::assertSame(0, $this->diagnostic->runs);
            self::assertNull($this->plugin->getRuns()->latest($this->siteId()));
            self::assertSame($before, WebDoctorTables::snapshot());

            return;
        }

        $this->controller()->runAction('run');

        self::assertSame($expected, $this->plugin->getRuns()->latest($this->siteId())?->context->depth->value);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedChoices(): array
    {
        return [
            'all as a word' => [['all' => 'yes']],
            'checks keyed by name' => [['diagnostics' => ['a' => 'tests.dashboard']]],
        ];
    }

    /**
     * What to run is stated in the form's own shape or refused: `all=yes` does not run
     * everything, and a malformed selection does not run whatever part of it could be read.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('malformedChoices')]
    public function testARunWhoseChoiceIsMalformedIsRefusedWithNothingRun(array $params): void
    {
        $this->signIn(admin: true);
        $this->post($params);
        $before = WebDoctorTables::snapshot();

        try {
            $this->controller()->runAction('run');
            self::fail('A malformed choice of checks was run.');
        } catch (BadRequestHttpException) {
        }

        self::assertSame(0, $this->diagnostic->runs);
        self::assertNull($this->plugin->getRuns()->latest($this->siteId()));
        self::assertSame($before, WebDoctorTables::snapshot());
    }

    // Access ---------------------------------------------------------------

    /**
     * Every request the dashboard refuses, and how. Web Doctor's interface lives under the control
     * panel, but where a request happens to come from is not an access rule: a plugin action route
     * is reachable from the front end unless something refuses it. State changes on POST only, so a
     * run cannot be set going by following a link, and only with the token Craft issued.
     *
     * @return array<string, array{string, string, bool, list<string>, bool, string|null, class-string<\Throwable>}>
     */
    public static function refusedRequests(): array
    {
        $view = [self::ACCESS_CP, Permissions::VIEW];

        // [action, method, admin, permissions, control panel, CSRF token, refusal]
        return [
            'the dashboard from the front end' => ['index', 'GET', true, [], false, null, BadRequestHttpException::class],
            'a run from the front end' => ['run', 'POST', true, [], false, 'issued', BadRequestHttpException::class],
            'the dashboard without the view permission' => ['index', 'GET', false, [self::ACCESS_CP], true, null, ForbiddenHttpException::class],
            'a run by somebody who may only look' => ['run', 'POST', false, $view, true, 'issued', ForbiddenHttpException::class],
            'a run by GET' => ['run', 'GET', true, [], true, 'issued', MethodNotAllowedHttpException::class],
            'a run by PUT' => ['run', 'PUT', true, [], true, 'issued', MethodNotAllowedHttpException::class],
            'a run by PATCH' => ['run', 'PATCH', true, [], true, 'issued', MethodNotAllowedHttpException::class],
            'a run by DELETE' => ['run', 'DELETE', true, [], true, 'issued', MethodNotAllowedHttpException::class],
            'a run without a CSRF token' => ['run', 'POST', true, [], true, null, BadRequestHttpException::class],
            'a run with the wrong CSRF token' => ['run', 'POST', true, [], true, 'not-the-token', BadRequestHttpException::class],
        ];
    }

    /**
     * @param list<string> $permissions
     * @param class-string<\Throwable> $refusal
     */
    #[DataProvider('refusedRequests')]
    public function testTheDashboardRefusesWhatItShouldWithNothingRun(string $action, string $method, bool $admin, array $permissions, bool $cp, ?string $token, string $refusal): void
    {
        $this->signIn(admin: $admin, permissions: $permissions);
        $request = $this->request($method, $cp);
        $request->setBodyParams(['all' => '1'] + match ($token) {
            null => [],
            'issued' => [$request->csrfParam => $request->getCsrfToken()],
            default => [$request->csrfParam => $token],
        });

        try {
            $this->controller()->runAction($action);
            self::fail('The dashboard answered a request it should have refused.');
        } catch (\Throwable $e) {
            self::assertInstanceOf($refusal, $e);
        }

        self::assertSame(0, $this->diagnostic->runs);
    }

    public function testAUserWhoMayOnlyLookStillSeesTheResults(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('Dashboard example check', $html);
        // No action is offered that this user could not carry out.
        self::assertStringNotContainsString('Run all checks', $html);
        self::assertStringNotContainsString('name="diagnostics[]"', $html);
    }

    public function testAUserHoldingTheRunPermissionMayRunWithTheTokenCraftIssued(): void
    {
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::RUN]);
        $request = $this->post(['all' => '1']);

        self::assertTrue($request->enableCsrfValidation, 'CSRF validation must not be switched off.');

        $this->controller()->runAction('run');

        self::assertSame(1, $this->diagnostic->runs);
    }

    // CSRF -----------------------------------------------------------------

    public function testTheDashboardFormCarriesACsrfToken(): void
    {
        $this->signIn(admin: true);
        $request = $this->request('GET');

        self::assertStringContainsString('name="' . $request->csrfParam . '"', $this->render());
    }

    // Redirects ------------------------------------------------------------

    public function testAnUnsignedRedirectIsRefused(): void
    {
        // Craft validates the posted redirect against its own hash, so a tampered one is a bad
        // request rather than a way to send an administrator somewhere else after a run.
        $this->signIn(admin: true);
        $this->post(['all' => '1', 'redirect' => 'https://evil.example.test/']);

        $this->expectException(BadRequestHttpException::class);
        $this->controller()->runAction('run');
    }

    public function testTheRedirectCraftSignedIsFollowed(): void
    {
        $this->signIn(admin: true);
        $this->post([
            'all' => '1',
            'redirect' => Craft::$app->getSecurity()->hashData('web-doctor'),
        ]);

        $response = $this->controller()->runAction('run');

        self::assertSame(302, $response->statusCode);
        self::assertStringContainsString('web-doctor', (string)($response->headers->get('location') ?? ''));
    }

    // Stale results --------------------------------------------------------

    public function testAResultFromACheckThatIsGoneStaysVisibleWithoutAffectingHealth(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        // The check that produced the failure is taken away, as an uninstalled plugin's would be.
        $plugin = $this->pluginWith(['class' => Diagnostics::class]);
        $plugin->getDiagnostics()->register(new TestDiagnostic([
            'diagnosticId' => 'tests.other',
            'diagnosticName' => 'Another check',
        ]));

        $this->request('GET');
        $html = $this->render($plugin);

        // Still on the page, and clearly marked.
        self::assertStringContainsString('Dashboard example check', $html);
        self::assertStringContainsString('No longer registered', $html);

        // But it is not this installation's failure any more, so it holds nothing down and is
        // not one of the checks the run had to cover.
        self::assertStringNotContainsString('Health score', $html);
        self::assertStringContainsString('covered 0 of 1 registered checks', $html);
    }

    public function testAStaleResultLeavesACompleteRunCompleteAndScoredCleanly(): void
    {
        // Every registered check covered and passing, alongside a critical failure from a check
        // that is gone. The run is complete, so a score is shown — and it is 100, because the
        // departed plugin's failure is not this installation's failure.
        $plugin = $this->pluginWith(['class' => Diagnostics::class]);
        $plugin->getDiagnostics()->register(new TestDiagnostic([
            'diagnosticId' => 'tests.passing',
            'diagnosticName' => 'Passing check',
        ]));

        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller($plugin)->runAction('run');

        $stored = $plugin->getRuns()->latest($this->siteId());
        self::assertNotNull($stored);

        $plugin->getRuns()->remember(new DiagnosticRun(
            context: $stored->context,
            results: [...$stored->results(), new DiagnosticResult(
                diagnosticId: 'departed.plugin',
                name: 'Departed plugin check',
                category: DiagnosticCategory::CONFIGURATION,
                status: DiagnosticStatus::FAIL,
                summary: 'This plugin is no longer installed.',
                severity: Severity::CRITICAL,
            )],
            startedAt: $stored->startedAt,
            finishedAt: $stored->finishedAt,
            durationMs: $stored->durationMs,
        ));

        $this->request('GET');
        $html = $this->render($plugin);

        self::assertStringContainsString('Health score', $html);
        self::assertStringContainsString('>100<', $html);
        self::assertStringContainsString('No longer registered', $html);
        self::assertStringContainsString('Departed plugin check', $html);
    }

    // Failure handling -----------------------------------------------------

    public function testADashboardThatCannotBeAssembledSaysSoSafely(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render($this->pluginWith(['class' => ExplodingDiagnostics::class]));

        self::assertStringContainsString('could not read the last diagnostic run', $html);
        self::assertStringNotContainsString(ExplodingDiagnostics::SECRET, $html);
        self::assertStringNotContainsString('hunter2', $html);
    }

    public function testARunThatCannotBeStartedIsReportedRatherThanThrown(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);

        $controller = $this->controller($this->pluginWith(['class' => ExplodingDiagnostics::class]));
        $controller->runAction('run');

        $flash = $controller->lastFlash();

        self::assertNotNull($flash);
        self::assertSame('fail', $flash['level']);
        self::assertStringContainsString('could not be run', $flash['message']);
        self::assertStringNotContainsString('hunter2', $flash['message']);
    }

    /**
     * A cache that cannot be read is said on the page, never shown as a dashboard that has not been
     * run yet — which would be a statement about the site that nobody established.
     */
    public function testACacheThatCannotBeReadIsSaidRatherThanShownAsNoRunYet(): void
    {
        $this->plugin->getRuns()->cache = new FailingCache();
        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('could not read the last diagnostic run', $html);
    }

    /**
     * The cache, the issues and the errors are three independent places a run is kept. One that
     * cannot be written — the Redis that is down — must not cost the other two the run's findings.
     */
    public function testARunThatCannotBeCachedStillReachesTheIssueCenter(): void
    {
        $this->plugin->getRuns()->cache = new FailingCache(['failReads' => false]);
        $this->signIn(admin: true);
        $this->post(['all' => '1']);

        $controller = $this->controller();
        $controller->runAction('run');
        $flash = $controller->lastFlash();

        self::assertNotNull($flash);
        self::assertSame('fail', $flash['level']);
        self::assertStringContainsString('results could not be stored', $flash['message']);
        self::assertStringContainsString('1 new issue.', $flash['message']);
        self::assertSame(1, (int)IssueRecord::find()->where(['diagnosticId' => 'tests.dashboard'])->count());
    }

    public function testTheErrorsARunRanIntoAreRecordedAndSaidSo(): void
    {
        // A check that breaks raises no issue, but the error it ran into is kept and counted.
        $this->diagnostic->handler = static fn() => throw new \RuntimeException('Element 4812 could not be saved.');
        $this->signIn(admin: true);
        $this->post(['all' => '1']);

        $controller = $this->controller();
        $controller->runAction('run');

        $flash = $controller->lastFlash();
        $group = ErrorGroupRecord::findOne([
            'id' => ErrorSourceRecord::find()->select(['errorGroupId'])->where(['diagnosticId' => 'tests.dashboard'])->column(),
        ]);

        self::assertNotNull($flash);
        self::assertSame('success', $flash['level']);
        self::assertStringContainsString('1 error recorded.', $flash['message']);
        self::assertInstanceOf(ErrorGroupRecord::class, $group);
        self::assertSame('Element {id} could not be saved.', $group->normalizedMessage);
    }

    public function testARunThatRanIntoNoErrorRecordsNoneAndSaysNothingAboutThem(): void
    {
        // The registered check fails, with no exception behind it: an issue, and no error.
        $this->signIn(admin: true);
        $this->post(['all' => '1']);

        $controller = $this->controller();
        $controller->runAction('run');
        $flash = $controller->lastFlash();

        self::assertNotNull($flash);
        self::assertSame('success', $flash['level']);
        self::assertStringNotContainsString('error', strtolower(str_replace('issue', '', $flash['message'])));
        self::assertSame(0, (int)ErrorSourceRecord::find()->where(['diagnosticId' => 'tests.dashboard'])->count());
    }

    // Presentation ---------------------------------------------------------

    /**
     * The dashboard is whole for every reader who may open it — not only for one who may also read
     * issues, whose page carries more. Run and all, for somebody holding only "View Web Doctor".
     */
    public function testTheDashboardRendersInFullForAReaderWhoMayOnlyViewIt(): void
    {
        $this->plugin->getRuns()->remember($this->plugin->getDiagnosticEngine()->runAll(DiagnosticContext::current()));
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('tests.dashboard', $html);
        self::assertStringContainsString((string)DiagnosticContext::currentEnvironment(), $html);
        self::assertStringNotContainsString('Something specific going wrong?', $html);
    }

    public function testEvidenceIsSummarisedRatherThanPutOnThePage(): void
    {
        // Evidence is redacted as it is recorded, so nothing here should be a credential in the
        // first place. The dashboard still shows only what a piece of evidence is, never what it
        // holds — so a diagnostic that records something it should not cannot publish it here.
        $this->diagnostic->handler = static fn(TestDiagnostic $d) => $d->build('warning', [
            'Something to look at.',
            [new Evidence(
                type: EvidenceType::CONFIGURATION,
                label: 'Mail transport token=zz-label-secret',
                source: 'tests.dashboard',
                data: [
                    'host' => 'smtp.example.test',
                    'note' => 'ZZZ-DISTINCTIVE-VALUE-ZZZ',
                    'apiKey' => 'sk-live-should-never-appear',
                    'dsn' => 'mysql://root:hunter2@db/craft',
                ],
            )],
        ]);

        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('Mail transport', $html);
        self::assertStringContainsString('Configuration', $html);

        foreach (['ZZZ-DISTINCTIVE-VALUE-ZZZ', 'smtp.example.test', 'sk-live-should-never-appear', 'hunter2', 'zz-label-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }

        // A label with something withheld from it shows the withheld part as a mark, never as the
        // bracketed marker a reader would have to interpret.
        self::assertStringContainsString('Mail transport token=<span class="wd-mark wd-mark--redacted"', $html);
        self::assertStringNotContainsString(\Tahadudhiya\WebDoctor\helpers\Redaction::REDACTED, $html);

        // A check that breaks is shown as broken; what it threw — which can quote SQL, a path or a
        // host that redaction leaves — is its evidence, not its prose.
        $this->diagnostic->handler = static fn() => throw new \RuntimeException('SQLSTATE[42S02]: SELECT * FROM zz_private_table WHERE host = "db.internal.test"');
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');
        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('This check failed to run.', $html);

        foreach (['zz_private_table', 'db.internal.test', 'SQLSTATE'] as $detail) {
            self::assertStringNotContainsString($detail, $html);
        }
    }

    public function testEachFindingCarriesItsRecommendationWithoutWhatItsEvidenceContains(): void
    {
        $this->plugin->getDiagnostics()->register(new TestDiagnostic([
            'diagnosticId' => 'queue.failedJobs',
            'diagnosticName' => 'Failed queue jobs',
            'diagnosticCategory' => DiagnosticCategory::QUEUE,
            'handler' => static fn(TestDiagnostic $d) => $d->build('fail', [
                'Queue jobs have failed: 1.',
                [
                    new Evidence(type: EvidenceType::QUEUE, label: 'Failed jobs', source: 'queue.failedJobs', data: ['failed' => 1, 'examined' => 1]),
                    new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Failed job', source: 'queue.failedJobs', data: ['description' => 'Sending email', 'occurrences' => 1, 'error' => 'ZZZ-JOB-ERROR-ZZZ']),
                ],
            ]),
        ]));

        // Stored the way a run is remembered, without the run action: that would raise an issue
        // under a shipped check's ID in whichever installation these tests run in.
        $run = $this->plugin->getDiagnosticEngine()->runAll(DiagnosticContext::current());
        self::assertTrue($this->plugin->getRuns()->remember($run));

        $this->signIn(admin: true);
        $this->request('GET');
        $before = WebDoctorTables::snapshot();
        $html = $this->render();

        // Opening the page ran nothing and wrote nothing: the advice is read from the run shown.
        self::assertSame(1, $this->diagnostic->runs);
        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertStringContainsString('Read each failed job’s error, and retry only when it is safe', $html);
        self::assertStringContainsString('wd-pill--risk-medium', $html);
        self::assertStringContainsString('<code>queue.failedJobs</code>', $html);
        // The dashboard shows what kind of fact advice rests on, never what it contains.
        self::assertStringContainsString('Failed job, recorded by Failed queue jobs', $html);
        self::assertStringNotContainsString('Sending email', $html);
        self::assertStringNotContainsString('ZZZ-JOB-ERROR-ZZZ', $html);

        // A finding no rule answers keeps its check's own advice, said to be the check's.
        self::assertStringContainsString('The check’s own advice', $html);
        self::assertStringContainsString('Fix it.', $html);
    }

    public function testARuleThatBreaksIsSaidAndNoLaterRuleIsGivenInItsPlace(): void
    {
        $this->diagnostic->handler = static fn(TestDiagnostic $d) => $d->build('fail', ['Something is broken.']);
        $this->plugin->getDiagnostics()->register(new TestDiagnostic([
            'diagnosticId' => 'queue.failedJobs',
            'diagnosticName' => 'Failed queue jobs',
            'diagnosticCategory' => DiagnosticCategory::QUEUE,
            'handler' => static fn(TestDiagnostic $d) => $d->build('fail', [
                'Queue jobs have failed: 1.',
                [new Evidence(type: EvidenceType::QUEUE, label: 'Failed jobs', source: 'queue.failedJobs', data: ['failed' => 1])],
            ]),
        ]));
        $this->plugin->getRuns()->remember($this->plugin->getDiagnosticEngine()->runAll(DiagnosticContext::current()));
        $this->plugin->set('recommendations', new BreakingRuleRecommendations(['check' => 'queue.failedJobs']));

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render();

        // The broken rule comes first for its check, so the advice after it is not given instead.
        self::assertStringNotContainsString('Read each failed job’s error, and retry only when it is safe', $html);
        self::assertStringContainsString('could not be worked out', $html);
        // The row, and every other row, still shows.
        self::assertStringContainsString('Queue jobs have failed: 1.', $html);
        self::assertStringContainsString('Something is broken.', $html);
        self::assertStringNotContainsString('hunter2', $html);

        // Recommendations that cannot be chosen at all are said, not left to read as a check's own
        // advice being all there is; the results still show.
        $this->plugin->set('recommendations', new class() extends \Tahadudhiya\WebDoctor\services\Recommendations {
            public function forResult(DiagnosticResult $result): \Tahadudhiya\WebDoctor\models\RecommendationSet
            {
                throw new \RuntimeException('The rules would not load.');
            }
        });
        $html = $this->render();

        self::assertStringContainsString('could not work out what to recommend for these results', $html);
        self::assertStringContainsString('Queue jobs have failed: 1.', $html);
    }

    public function testARunFromTheControlPanelKeepsTheEvidenceBehindWhatItFinds(): void
    {
        // The whole path a person sets going: the run action, the engine stamping the run, the
        // reconciliation, and the evidence landing against the issue it supports.
        $this->diagnostic->handler = static fn(TestDiagnostic $d) => $d->build('warning', [
            'Something to look at.',
            [new Evidence(
                type: EvidenceType::QUEUE,
                label: 'Queue depth',
                source: 'tests.dashboard',
                data: ['waiting' => 12, 'apiKey' => 'sk-should-never-be-stored'],
                runId: 'claimed-by-the-check',
            )],
        ]);

        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $issue = IssueRecord::findOne(['diagnosticId' => 'tests.dashboard']);

        self::assertInstanceOf(IssueRecord::class, $issue);

        $row = \Tahadudhiya\WebDoctor\records\EvidenceRecord::findOne(['issueId' => $issue->id]);

        self::assertInstanceOf(\Tahadudhiya\WebDoctor\records\EvidenceRecord::class, $row);
        self::assertSame('Queue depth', $row->label);
        self::assertSame($issue->latestRunId, $row->lastRunId);
        self::assertNotSame('claimed-by-the-check', $row->lastRunId);
        self::assertSame($issue->environment, $row->environment);
        self::assertStringNotContainsString('sk-should-never-be-stored', (string)$row->data);
    }

    public function testAnExceptionFromACheckIsNotRepeatedRawOnThePage(): void
    {
        $this->diagnostic->handler = static function(): never {
            throw new \RuntimeException('Connection failed: password=hunter2-DASHBOARD');
        };

        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('Dashboard example check', $html);
        self::assertStringNotContainsString('hunter2-DASHBOARD', $html);
    }

    public function testTheExecutionModeIsLabelledAsAModeAndNotAsAPerson(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('Execution mode', $html);
        self::assertStringNotContainsString('Started by', $html);
    }

    public function testStatusAndSeverityAreReadableWithoutColour(): void
    {
        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $this->request('GET');
        $html = $this->render();

        // The pills carry their own words, so the outcome survives a monochrome screen.
        self::assertStringContainsString('>Failed</span>', $html);
        self::assertStringContainsString('>Critical</span>', $html);
    }

    public function testEveryCheckboxHasAnAccessibleName(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('id="wd-check-tests-dashboard"', $html);
        self::assertStringContainsString('for="wd-check-tests-dashboard"', $html);
        self::assertStringContainsString('Include Dashboard example check in the next run', $html);
    }

    public function testAnInstallationWithNoChecksSaysSoRatherThanShowingAnEmptyPage(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render($this->pluginWith(['class' => Diagnostics::class]));

        self::assertStringContainsString('No checks are registered, so there is nothing to run.', $html);
        self::assertStringNotContainsString('Health score', $html);
    }

    public function testLongTextIsRenderedAndEscapedRatherThanBreakingThePage(): void
    {
        $name = str_repeat('Extraordinarily descriptive check name ', 8);
        $summary = str_repeat('A summary long enough to be a paragraph in its own right. ', 20);

        $this->diagnostic->diagnosticName = $name;
        $this->diagnostic->handler = static fn(TestDiagnostic $d) => $d->build('warning', [
            $summary . '<script>alert(1)</script>',
        ]);

        $this->signIn(admin: true);
        $this->post(['all' => '1']);
        $this->controller()->runAction('run');

        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString(trim($summary), $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // Scoping --------------------------------------------------------------

    public function testASiteThatCannotBeResolvedIsNotCalledAllSites(): void
    {
        // Seeded directly, because a site cannot be deleted out from under a request. The branch
        // is what matters: a known site ID that no longer names a site must not be reported as
        // though the run had covered every site.
        $runs = $this->plugin->getRuns();
        $now = new \DateTimeImmutable();

        $this->cache->set($runs->key(Craft::$app->env, $this->siteId()), new DiagnosticRun(
            context: new DiagnosticContext(siteId: 999999, environment: Craft::$app->env),
            results: [],
            startedAt: $now,
            finishedAt: $now,
            durationMs: 1.0,
        ));

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render();

        self::assertStringContainsString('Site #999999 (no longer available)', $html);
        self::assertStringNotContainsString('All sites', $html);
    }
}
