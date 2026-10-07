<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
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
 * Who each controller lets in is asserted where the controller is driven. What is here is that
 * none lets anybody in without signing in, the answer the permission service gives about a real
 * identity, and what the control panel navigation does with it.
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

    public function testEveryComponentResolvesToItsService(): void
    {
        foreach (WebDoctor::config()['components'] as $id => $definition) {
            if (class_exists($definition['class'])) {
                self::assertInstanceOf($definition['class'], $this->plugin->get($id), $id);
            }
        }
    }

    public function testNoControllerLetsAnybodyInWithoutSigningIn(): void
    {
        // A request with no identity is answered by Craft with a login redirect, which needs a
        // session a console run has none of. What can be asserted is the switch Craft reads to
        // decide that: no controller opts any action out of authentication, as declared or as built.
        $controllers = 0;

        foreach (glob(dirname(__DIR__, 2) . '/src/controllers/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            if ($short === 'TestLabController') {
                continue;
            }

            /** @var class-string<\craft\web\Controller> $class */
            $class = 'Tahadudhiya\\WebDoctor\\controllers\\' . $short;
            $property = new \ReflectionProperty($class, 'allowAnonymous');

            self::assertSame(\craft\web\Controller::ALLOW_ANONYMOUS_NEVER, $property->getDefaultValue(), "$short declares an anonymous action.");
            self::assertSame(\craft\web\Controller::ALLOW_ANONYMOUS_NEVER, $property->getValue(new $class('controller', $this->plugin)), "$short opens an action up as it is built.");
            $controllers++;
        }

        self::assertGreaterThanOrEqual(8, $controllers);
    }

    /**
     * Every section is behind "View Web Doctor" as well as its own permission, required first. Craft
     * nests permissions only in its own screens, so a permission set written another way can hold a
     * child without its parent — and must not be a way into the section without it.
     */
    public function testEverySectionRequiresViewingWebDoctorBeforeItsOwnPermission(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/controllers/*.php') ?: [] as $file) {
            if (basename($file) === 'TestLabController.php') {
                continue;
            }

            $source = (string)file_get_contents($file);
            self::assertSame(1, preg_match('/function beforeAction\(.*?\n    \}/s', $source, $match), basename($file));
            preg_match_all('/requirePermission\(Permissions::([A-Z_]+)\)/', $match[0], $required);

            self::assertSame('VIEW', $required[1][0] ?? null, basename($file) . ' does not require viewing Web Doctor first.');
        }
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
            [$exit, $written] = $this->runStatusCommand();

            self::assertSame(ExitCode::CONFIG, $exit);
            self::assertStringContainsString('settings:       invalid', $written);
            self::assertStringContainsString('Plugin name cannot be blank.', $written);
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
        // unknown filter fails here rather than in front of a user. Every template, so one added
        // later is held to this without anybody having to remember to list it.
        $root = dirname(__DIR__, 2) . '/src/templates/';
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $loaded = 0;

        /** @var \SplFileInfo $file */
        foreach ($templates as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            $name = 'web-doctor/' . substr($file->getPathname(), strlen($root), -5);
            self::assertSame($name, $view->getTwig()->load($name)->getTemplateName());
            $loaded++;
        }

        self::assertGreaterThanOrEqual(13, $loaded);
    }

    public function testNoTemplateInterpolatesWhereItMeantToTranslate(): void
    {
        // Twig reads `#{…}` inside a double-quoted string as interpolation, so a phrase such as
        // "Issue #{id}" handed to |t fails for want of a variable called id — or, where variables
        // are not strict, renders a phrase with a hole in it. Single quotes are literal.
        $root = dirname(__DIR__, 2) . '/src/templates/';
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match_all('/"[^"\n]*#\{[^"\n]*"\s*\|\s*t\(/', (string)file_get_contents($file->getPathname()), $matches) > 0) {
                $offenders[] = substr($file->getPathname(), strlen($root)) . ': ' . implode(', ', $matches[0]);
            }
        }

        self::assertSame([], $offenders);
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
        [$exit, $written] = $this->runStatusCommand();

        self::assertSame(ExitCode::OK, $exit);
        self::assertStringContainsString('version:        5.0.0', $written);
        self::assertStringContainsString('settings:       valid', $written);
    }

    /**
     * Runs the status command, keeping what it writes rather than sending it to the terminal running
     * the tests, so what it reports can be asserted beside its exit code.
     *
     * @return array{int, string}
     */
    private function runStatusCommand(): array
    {
        $controller = new class(WebDoctor::CONSOLE_ID, Craft::$app) extends WebDoctorController {
            public string $written = '';

            public function stdout($string): int
            {
                $this->written .= $string;

                return strlen($string);
            }

            public function stderr($string): int
            {
                $this->written .= $string;

                return strlen($string);
            }
        };
        $controller->interactive = false;
        $exit = $controller->actionStatus();

        return [$exit, $controller->written];
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

        $registered = [];
        $collect = static function(array $permissions) use (&$collect, &$registered): void {
            foreach ($permissions as $name => $permission) {
                $registered[] = $name;
                $collect($permission['nested'] ?? []);
            }
        };

        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            if (($group['heading'] ?? null) === 'Web Doctor') {
                $collect($group['permissions']);
            }
        }

        $expected = [Permissions::VIEW, Permissions::RUN, Permissions::VIEW_ISSUES, Permissions::MANAGE_ISSUES, Permissions::VIEW_EVIDENCE, Permissions::INVESTIGATE_ISSUES, Permissions::RUN_REPAIRS, Permissions::VIEW_AUDIT_TRAIL];
        // Every test registers the handler again, so the heading can appear more than once.
        $registered = array_values(array_unique($registered));
        sort($expected);
        sort($registered);

        self::assertSame($expected, $registered);
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

        foreach (array_keys($this->recordClasses()) as $record) {
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

    public function testTheRepairsTableHasWhatAPreviewIsBoundByAndOneLockAtATime(): void
    {
        // Read from the database itself: what binds a preview to its confirmation has to exist as a
        // column, and the lock that keeps one repair of a kind at a time has to be unique.
        $db = Craft::$app->getDb();
        $schema = $db->getTableSchema(\Tahadudhiya\WebDoctor\records\RepairRecord::TABLE, true);

        self::assertNotNull($schema);

        // Who previewed and who carried out are kept by name beside their IDs, which a deleted
        // account sets null: the history has to go on saying who.
        foreach (['fingerprint', 'definitionFingerprint', 'findingRunId', 'environment', 'siteId', 'issueId', 'lockKey', 'issueStatusBefore', 'status', 'verificationStatus', 'previewedByName', 'executedByName'] as $column) {
            self::assertArrayHasKey($column, $schema->columns, $column);
        }

        self::assertFalse($schema->columns['definitionFingerprint']->allowNull);
        self::assertFalse($schema->columns['fingerprint']->allowNull);

        // Every answer a verification can give has to fit where the repair keeps the latest one.
        self::assertGreaterThanOrEqual(
            max(array_map('strlen', \Tahadudhiya\WebDoctor\enums\VerificationStatus::values())),
            $schema->columns['verificationStatus']->size,
        );

        $unique = $db->getSchema()->findUniqueIndexes($schema);
        self::assertContains(['lockKey'], array_values($unique));
    }

    public function testTheAuditLogKeepsWhoWhatAndWhereByNameAndIsIndexedTheWayItIsRead(): void
    {
        // Read from the database itself. An entry outlives the user, the issue and the site it names,
        // so each is kept by name beside its link; and the log is read newest first and filtered by
        // action, result, user, issue and environment, each of which has to be an index rather than
        // a scan of a table that only grows.
        $db = Craft::$app->getDb();
        $schema = $db->getTableSchema(\Tahadudhiya\WebDoctor\records\AuditRecord::TABLE, true);

        self::assertNotNull($schema);

        foreach (['userId', 'userName', 'issueId', 'objectLabel', 'siteId', 'siteName'] as $column) {
            self::assertArrayHasKey($column, $schema->columns, $column);
            self::assertTrue($schema->columns[$column]->allowNull, $column);
        }

        foreach (['action', 'result', 'summary', 'objectType', 'environment', 'occurredAt'] as $column) {
            self::assertFalse($schema->columns[$column]->allowNull, $column);
        }

        // Every value an entry can name has to fit its column.
        self::assertGreaterThanOrEqual(max(array_map('strlen', \Tahadudhiya\WebDoctor\enums\AuditAction::values())), $schema->columns['action']->size);
        self::assertGreaterThanOrEqual(max(array_map('strlen', \Tahadudhiya\WebDoctor\enums\AuditResult::values())), $schema->columns['result']->size);

        // The column each index leads with, which is the one it can be searched by.
        $first = array_map(static fn(array $row): string => (string)$row['Column_name'], array_values(array_filter(
            $db->createCommand('SHOW INDEX FROM ' . $db->quoteTableName(\Tahadudhiya\WebDoctor\records\AuditRecord::TABLE))->queryAll(),
            static fn(array $row): bool => (int)$row['Seq_in_index'] === 1,
        )));

        foreach (['occurredAt', 'action', 'result', 'userId', 'issueId', 'environment'] as $column) {
            self::assertContains($column, $first, "Nothing indexes the audit log by $column.");
        }
    }

    public function testTheDiagnosticHistoryKeepsWhoAndWhereByNameAndAScoreOnlyWithItsArithmetic(): void
    {
        $db = Craft::$app->getDb();
        $schema = $db->getTableSchema(\Tahadudhiya\WebDoctor\records\DiagnosticRunRecord::TABLE, true);

        self::assertNotNull($schema);

        // Kept by name beside the links a deleted user or site sets null.
        foreach (['userId', 'userName', 'siteId', 'siteName', 'score', 'maxScore', 'weights', 'severityCounts', 'contributions'] as $column) {
            self::assertTrue($schema->columns[$column]->allowNull, $column);
        }

        foreach (['runId', 'environment', 'mode', 'depth', 'complete', 'statusCounts', 'results', 'startedAt', 'finishedAt'] as $column) {
            self::assertFalse($schema->columns[$column]->allowNull, $column);
        }

        self::assertContains(['runId'], array_values($db->getSchema()->findUniqueIndexes($schema)));

        $first = array_map(static fn(array $row): string => (string)$row['Column_name'], array_values(array_filter(
            $db->createCommand('SHOW INDEX FROM ' . $db->quoteTableName(\Tahadudhiya\WebDoctor\records\DiagnosticRunRecord::TABLE))->queryAll(),
            static fn(array $row): bool => (int)$row['Seq_in_index'] === 1,
        )));

        foreach (['startedAt', 'environment', 'siteId', 'userId'] as $column) {
            self::assertContains($column, $first, "Nothing indexes the diagnostic history by $column.");
        }
    }

    public function testDeletingASiteDropsTheReferenceRatherThanTheIssue(): void
    {
        // Most findings are about the installation and merely stamped with whichever site was in
        // view, so cascading would erase a database problem because an unrelated site was
        // removed. Read from the migration because deleting a real site is not a test's to do.
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');

        foreach (['IssueRecord', 'EvidenceRecord', 'InvestigationRecord', 'ErrorGroupRecord', 'RepairRecord', 'VerificationRecord'] as $record) {
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
            // A record of what was done outlives what it was done to, who did it and where.
            'webdoctor_audit_log.issueId' => 'SET NULL',
            'webdoctor_audit_log.siteId' => 'SET NULL',
            'webdoctor_audit_log.userId' => 'SET NULL',
            // A run is a record of what was found; it outlives who ran it and where.
            'webdoctor_diagnostic_runs.siteId' => 'SET NULL',
            'webdoctor_diagnostic_runs.userId' => 'SET NULL',
            // An error's sources say something only about it; an error outlives the issue it
            // was related to.
            'webdoctor_error_groups.siteId' => 'SET NULL',
            'webdoctor_error_sources.errorGroupId' => 'CASCADE',
            'webdoctor_error_sources.issueId' => 'SET NULL',
            'webdoctor_evidence.issueId' => 'CASCADE',
            'webdoctor_evidence.siteId' => 'SET NULL',
            // An investigation explains its issue and goes with it; its steps go with it in turn.
            // A step's reference to some other issue is only a reference.
            'webdoctor_investigation_steps.investigationId' => 'CASCADE',
            'webdoctor_investigation_steps.relatedIssueId' => 'SET NULL',
            'webdoctor_investigations.issueId' => 'CASCADE',
            'webdoctor_investigations.siteId' => 'SET NULL',
            'webdoctor_investigations.startedBy' => 'SET NULL',
            'webdoctor_issue_events.issueId' => 'CASCADE',
            'webdoctor_issue_events.userId' => 'SET NULL',
            'webdoctor_issues.siteId' => 'SET NULL',
            'webdoctor_issues.statusChangedBy' => 'SET NULL',
            // A repair is a record of something done to the installation, so it outlives its issue.
            'webdoctor_repairs.executedBy' => 'SET NULL',
            'webdoctor_repairs.issueId' => 'SET NULL',
            'webdoctor_repairs.previewedBy' => 'SET NULL',
            'webdoctor_repairs.siteId' => 'SET NULL',
            // A cause is what an investigation concluded, so it goes with it.
            'webdoctor_root_causes.investigationId' => 'CASCADE',
            // A verification says something only about its repair; like the repair, it outlives
            // the issue.
            'webdoctor_verifications.issueId' => 'SET NULL',
            'webdoctor_verifications.repairId' => 'CASCADE',
            'webdoctor_verifications.siteId' => 'SET NULL',
            'webdoctor_verifications.verifiedBy' => 'SET NULL',
        ], $rules);
    }

    /**
     * The tables Web Doctor's record classes say it has, as the database spells them.
     *
     * @return list<string>
     */
    private function declaredTables(): array
    {
        $tables = array_map(
            static fn(string $class): string => trim($class::TABLE, '{}%'),
            array_values($this->recordClasses()),
        );

        sort($tables);

        return $tables;
    }

    /**
     * Every record class Web Doctor ships, read from the directory rather than listed, so a table
     * added later cannot be left out of the checks on what is created and dropped.
     *
     * @return array<string, class-string<\craft\db\ActiveRecord>> Short name => class.
     */
    private function recordClasses(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/records/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            /** @var class-string<\craft\db\ActiveRecord> $class */
            $class = 'Tahadudhiya\\WebDoctor\\records\\' . $short;
            $classes[$short] = $class;
        }

        self::assertNotSame([], $classes);
        ksort($classes);

        return $classes;
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
