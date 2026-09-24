<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\elements\User;
use craft\web\View;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\console\controllers\WebDoctorController;
use Tahadudhiya\WebDoctor\models\Settings;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\console\ExitCode;

/**
 * Boots Web Doctor inside a real Craft application, which is the only place the plugin's
 * bootstrap, components, settings and access model can be shown to actually hold together.
 *
 * Who the controller lets in is asserted where the controller is driven, in the dashboard tests.
 * What is here is the answer the permission service gives about a real identity, and what the
 * control panel navigation does with it.
 */
class InstallationTest extends TestCase
{
    private WebDoctor $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        // The same shape Craft builds a plugin with, minus what Craft derives from the
        // installed package.
        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '1.0.0',
            'developer' => 'Taha Dudhiya',
        ]);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        parent::tearDown();
    }

    /**
     * Craft answers `can()` from the database, which needs a saved user, and this installation
     * is a Solo licence that refuses to create a second one — so the permissions are stated and
     * everything under test runs exactly as it does in production.
     *
     * @param string[] $permissions
     */
    private function signIn(bool $admin, array $permissions = []): User
    {
        $user = new TestUser();
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;

        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    public function testCraftIsRunning(): void
    {
        self::assertInstanceOf(ConsoleApplication::class, Craft::$app);
    }

    public function testThePluginBootstrapsWithoutError(): void
    {
        self::assertSame('web-doctor', $this->plugin->id);
    }

    public function testComponentsResolveToTheirServices(): void
    {
        self::assertInstanceOf(Permissions::class, $this->plugin->getPermissions());
    }

    public function testSettingsResolveAndValidate(): void
    {
        $settings = $this->plugin->getSettings();

        self::assertInstanceOf(Settings::class, $settings);
        self::assertTrue($settings->validate());
    }

    public function testCraftResolvesTheCommandFromItsRoute(): void
    {
        // Asked the way the command line asks, rather than by reading the map it was put in.
        $resolved = Craft::$app->createController(WebDoctor::CONSOLE_ID . '/status');

        self::assertIsArray($resolved);
        self::assertInstanceOf(WebDoctorController::class, $resolved[0]);
        self::assertSame('status', $resolved[1]);
    }

    public function testTheStatusCommandFailsWhenTheSettingsAreInvalid(): void
    {
        $settings = WebDoctor::getInstance()?->getSettings();
        self::assertNotNull($settings);

        $original = $settings->pluginName;
        $settings->pluginName = '';

        try {
            $controller = new WebDoctorController(WebDoctor::CONSOLE_ID, Craft::$app);
            $controller->interactive = false;

            self::assertSame(ExitCode::CONFIG, $controller->actionStatus());
        } finally {
            $settings->pluginName = $original;
        }
    }

    public function testSettingsArriveTheWayCraftMergesAConfigFile(): void
    {
        // Craft reads config/web-doctor.php and hands the result over as the plugin's settings.
        $plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'settings' => ['pluginName' => 'Site Health'],
        ]);

        self::assertSame('Site Health', $plugin->getSettings()->pluginName);
        self::assertTrue($plugin->getSettings()->validate());
    }

    public function testTheControlPanelTemplatesCompile(): void
    {
        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        // Loading compiles the template and everything it extends, so a broken tag or an
        // unknown filter fails here rather than in front of a user.
        self::assertSame('web-doctor/_index', $view->getTwig()->load('web-doctor/_index')->getTemplateName());
        self::assertSame('web-doctor/_dashboard', $view->getTwig()->load('web-doctor/_dashboard')->getTemplateName());
        self::assertSame('web-doctor/_settings', $view->getTwig()->load('web-doctor/_settings')->getTemplateName());
    }

    public function testTheSettingsFormRendersItsField(): void
    {
        $settings = WebDoctor::getInstance()?->getSettings();
        self::assertNotNull($settings);

        $html = Craft::$app->getView()->renderTemplate('web-doctor/_settings', [
            'settings' => $settings,
        ], View::TEMPLATE_MODE_CP);

        self::assertStringContainsString('name="pluginName"', $html);
        self::assertStringContainsString($settings->pluginName, $html);
    }

    public function testCommandsRunAndReportSuccess(): void
    {
        $controller = new WebDoctorController(WebDoctor::CONSOLE_ID, Craft::$app);
        // Console output goes straight to the terminal, so the exit code is what is worth
        // asserting: it is also what a deployment pipeline reads.
        $controller->interactive = false;

        self::assertSame(ExitCode::OK, $controller->actionStatus());
    }

    public function testOnlyAnAdminOrSomebodyHoldingThePermissionMayView(): void
    {
        $this->signIn(admin: true);
        self::assertTrue($this->plugin->getPermissions()->canView());

        $this->signIn(admin: false, permissions: [Permissions::VIEW]);
        self::assertTrue($this->plugin->getPermissions()->canView());

        $this->signIn(admin: false, permissions: ['someOtherPlugin:doThing']);
        self::assertFalse($this->plugin->getPermissions()->canView());

        // A request with no identity — a console command, a logged-out visitor — never passes.
        Craft::$app->getUser()->setIdentity(null);
        self::assertFalse($this->plugin->getPermissions()->canView());
    }

    public function testTheControlPanelSectionFollowsTheViewPermissionAndTheConfiguredName(): void
    {
        $this->signIn(admin: false, permissions: [Permissions::VIEW]);
        $item = $this->plugin->getCpNavItem();

        self::assertIsArray($item);
        self::assertSame('Web Doctor', $item['label']);
        self::assertSame('web-doctor', $item['url']);

        $this->plugin->getSettings()->pluginName = 'Site Health';
        self::assertSame('Site Health', $this->plugin->getCpNavItem()['label'] ?? null);

        $this->signIn(admin: false);
        self::assertNull($this->plugin->getCpNavItem());
    }

    public function testPermissionsAreRegisteredWithCraft(): void
    {
        $this->plugin->getPermissions()->register();

        $permissions = Craft::$app->getUserPermissions()->getAllPermissions();
        $headings = array_column($permissions, 'heading');

        self::assertContains('Web Doctor', $headings);
    }

    public function testNothingIsInstalledIntoTheDatabaseYet(): void
    {
        // Web Doctor owns no tables at this point. This is what fails first if one appears
        // without the install migration that is supposed to create and drop it.
        $tables = array_filter(
            Craft::$app->getDb()->getSchema()->getTableNames(),
            static fn(string $table): bool => str_starts_with($table, 'webdoctor_'),
        );

        self::assertSame([], array_values($tables));
    }
}
