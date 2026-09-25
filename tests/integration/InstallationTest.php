<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\elements\User;
use craft\web\View;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\console\controllers\WebDoctorController;
use Tahadudhiya\WebDoctor\models\Settings;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
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
            'version' => '5.0.0',
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

    public function testWebDoctorOwnsExactlyTheTablesItsRecordsDeclare(): void
    {
        // What fails first if a table appears that no record class accounts for — or if one a
        // record expects was never created.
        self::assertSame($this->declaredTables(), $this->ownedTables());
    }

    public function testEveryTableWebDoctorCreatesIsAlsoOneItRemoves(): void
    {
        // Uninstalling must leave the database as Web Doctor found it. Read from the migration's
        // own source rather than by running it, because running it would drop the issues of
        // whoever's installation these tests are running in.
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');

        foreach (['IssueRecord', 'IssueEventRecord', 'EvidenceRecord'] as $record) {
            self::assertMatchesRegularExpression(
                sprintf('/createTable\(\s*%s::TABLE\b/', $record),
                $source,
                "$record's table is never created.",
            );
            self::assertMatchesRegularExpression(
                sprintf('/dropTableIfExists\(\s*%s::TABLE\s*\)/', $record),
                $source,
                "$record's table is never dropped.",
            );
        }
    }

    public function testDeletingASiteDropsTheReferenceRatherThanTheIssue(): void
    {
        // Most findings are about the installation and merely stamped with whichever site was in
        // view, so cascading would erase a database problem because an unrelated site was
        // removed. Read from the migration because deleting a real site is not a test's to do.
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');

        foreach (['IssueRecord', 'EvidenceRecord'] as $record) {
            self::assertMatchesRegularExpression(
                "/addForeignKey\\([^;]*{$record}::TABLE,\\s*\\['siteId'\\][^;]*'SET NULL'/s",
                $source,
                "$record's site reference must be dropped, not cascaded.",
            );
        }
        self::assertStringNotContainsString("['siteId'], Table::SITES, ['id'], 'CASCADE'", $source);
    }

    public function testTheInstalledForeignKeysDeleteWhatTheyShouldAndNothingElse(): void
    {
        // Read from the database itself rather than the migration's source, so what is asserted
        // is the schema a site actually has.
        $db = Craft::$app->getDb();

        if (!$db->getIsMysql()) {
            self::markTestSkipped('Reads MySQL’s information schema.');
        }

        $rows = (new \craft\db\Query())
            ->select(['k.TABLE_NAME', 'k.COLUMN_NAME', 'k.REFERENCED_TABLE_NAME', 'r.DELETE_RULE'])
            ->from(['k' => 'information_schema.KEY_COLUMN_USAGE'])
            ->innerJoin(['r' => 'information_schema.REFERENTIAL_CONSTRAINTS'], '[[r.CONSTRAINT_NAME]] = [[k.CONSTRAINT_NAME]] AND [[r.CONSTRAINT_SCHEMA]] = [[k.CONSTRAINT_SCHEMA]]')
            ->where(['k.TABLE_SCHEMA' => $db->getSchema()->defaultSchema ?? $db->createCommand('SELECT DATABASE()')->queryScalar()])
            ->andWhere(['like', 'k.TABLE_NAME', $db->tablePrefix . 'webdoctor_%', false])
            ->all();

        $rules = [];
        $prefix = strlen((string)$db->tablePrefix);

        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_UPPER);
            $rules[substr((string)$row['TABLE_NAME'], $prefix) . '.' . $row['COLUMN_NAME']] = $row['DELETE_RULE'];
        }

        ksort($rules);

        self::assertSame([
            'webdoctor_evidence.issueId' => 'CASCADE',
            'webdoctor_evidence.siteId' => 'SET NULL',
            'webdoctor_issue_events.issueId' => 'CASCADE',
            'webdoctor_issue_events.userId' => 'SET NULL',
            'webdoctor_issues.siteId' => 'SET NULL',
            'webdoctor_issues.statusChangedBy' => 'SET NULL',
        ], $rules);
    }

    public function testWebDoctorHasOnlyEverHadOneMigration(): void
    {
        // The plugin is unreleased, so there is no installed schema anywhere that needs
        // upgrading: every change is made to the install migration in place.
        $migrations = glob(dirname(__DIR__, 2) . '/src/migrations/*.php') ?: [];

        self::assertSame(['Install.php'], array_map('basename', $migrations));
    }

    /**
     * The tables Web Doctor's record classes say it has, as the database spells them.
     *
     * @return list<string>
     */
    private function declaredTables(): array
    {
        $tables = array_map(
            static fn(string $table): string => trim($table, '{}%'),
            [IssueRecord::TABLE, IssueEventRecord::TABLE, \Tahadudhiya\WebDoctor\records\EvidenceRecord::TABLE],
        );

        sort($tables);

        return $tables;
    }

    /**
     * The tables that actually exist under Web Doctor's prefix.
     *
     * @return list<string>
     */
    private function ownedTables(): array
    {
        $tables = array_values(array_filter(
            Craft::$app->getDb()->getSchema()->getTableNames(),
            static fn(string $table): bool => str_starts_with($table, 'webdoctor_'),
        ));

        sort($tables);

        return $tables;
    }
}
