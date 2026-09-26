<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\db\Connection;
use craft\fs\Local;
use craft\mail\transportadapters\Smtp;
use craft\models\MailSettings;
use craft\models\Volume;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\database\CharsetDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\database\ConnectionDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\database\MigrationsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\email\MailerConfigurationDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\filesystem\FilesystemsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\plugins\InstalledPluginsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\plugins\PluginHealthDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\FailedJobsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueBacklogDiagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\Tests\_support\ForeignQueue;
use Tahadudhiya\WebDoctor\Tests\_support\StubbedFailedJobsDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\StubbedQueueBacklogDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\StubQueue;
use yii\mail\BaseMailer;

/**
 * What the checks that need a real Craft conclude about states they are told about.
 *
 * These four read Craft objects — a database connection, a filesystem, a set of mail settings —
 * which is why they are here rather than in the unit suite: a fixture for them is a Craft
 * object, and building one outside a booted Craft would be building a different thing.
 *
 * Nothing here writes to the site. The unreachable filesystem points at a path that does not
 * exist, the unreachable database at a port nothing listens on, and the mail settings never
 * reach a mail server.
 */
class DiagnosticBehaviourTest extends TestCase
{
    private function context(?DiagnosticDepth $depth = null): DiagnosticContext
    {
        return DiagnosticContext::current($depth ?? DiagnosticDepth::NORMAL);
    }

    /**
     * The queue checks are exercised through the engine rather than called directly, because
     * declining to run is something the engine records rather than something the check returns.
     */
    private function engine(): DiagnosticEngine
    {
        return new DiagnosticEngine(['registry' => new Diagnostics()]);
    }

    private function unreachableConnection(string $password = 'nothing'): Connection
    {
        return new Connection([
            'dsn' => 'mysql:host=127.0.0.1;port=1;dbname=web-doctor-nothing-here',
            'username' => 'nobody',
            'password' => $password,
        ]);
    }

    // --- database.connection -------------------------------------------------

    public function testAReachableDatabasePassesAndNamesTheServer(): void
    {
        $result = (new ConnectionDiagnostic())->run($this->context());

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertTrue($result->evidence()[1]->get('succeeded'));
        self::assertNotNull($result->evidence()[1]->get('serverVersion'));
    }

    public function testAnUnreachableDatabaseIsACriticalFindingRatherThanABrokenCheck(): void
    {
        $result = (new ConnectionDiagnostic(['db' => $this->unreachableConnection()]))->run($this->context());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertFalse($result->evidence()[1]->get('succeeded'));
        self::assertNotNull($result->recommendation);
    }

    public function testTheConnectionCheckNeverRecordsTheCredentialsItWasGiven(): void
    {
        $result = (new ConnectionDiagnostic(['db' => $this->unreachableConnection('fake-db-password')]))->run($this->context());
        $json = json_encode($result);

        self::assertIsString($json);
        self::assertStringNotContainsString('fake-db-password', $json);
        // The answer a developer needs is whether it is set, and that answer survives.
        self::assertSame('Present', $result->evidence()[0]->get('password'));
    }

    public function testAServerWhoseMinimumCannotBeReadIsNotKnowingRatherThanANote(): void
    {
        // Without the minimum, a server Craft does not support would raise nothing at all.
        $diagnostic = new class() extends ConnectionDiagnostic {
            protected function requiredVersion(Connection $db): ?string
            {
                return null;
            }
        };

        self::assertSame(DiagnosticStatus::UNKNOWN, $diagnostic->run($this->context())->status);
    }

    public function testAServerVersionWithABuildSuffixIsStillComparedCorrectly(): void
    {
        // `10.11.6-MariaDB-log` and `8.0.35-0ubuntu0.22.04.1` are ordinary version strings, and
        // `version_compare` reads those suffixes as pre-release markers — which would report a
        // supported server as unsupported.
        $diagnostic = new class() extends ConnectionDiagnostic {
            public function comparable(string $version): string
            {
                $method = new \ReflectionMethod(ConnectionDiagnostic::class, 'comparableVersion');

                return $method->invoke($this, $version);
            }
        };

        self::assertSame('10.11.6', $diagnostic->comparable('10.11.6-MariaDB-log'));
        self::assertSame('8.0.35', $diagnostic->comparable('8.0.35-0ubuntu0.22.04.1'));
        self::assertSame('8.0.40', $diagnostic->comparable('8.0.40'));
        // MariaDB before 11 reports itself behind a MySQL 5.5.5 prefix; the version is the second.
        self::assertSame('10.6.12', $diagnostic->comparable('5.5.5-10.6.12-MariaDB'));
        self::assertSame('16.2', $diagnostic->comparable('16.2 (Debian 16.2-1.pgdg120+2)'));
    }

    // --- database.migrations -------------------------------------------------

    /**
     * @param array{schemaCompatible: bool, pending: string[]}|RuntimeException $state
     */
    private function migrations(array|RuntimeException $state): DiagnosticResult
    {
        $diagnostic = new class(['state' => $state]) extends MigrationsDiagnostic {
            /** @var array{schemaCompatible: bool, pending: string[]}|RuntimeException */
            public array|RuntimeException $state = ['schemaCompatible' => true, 'pending' => []];

            protected function state(): array
            {
                if ($this->state instanceof RuntimeException) {
                    throw $this->state;
                }

                return $this->state;
            }
        };

        return $diagnostic->run($this->context());
    }

    public function testAnUpToDateSchemaPasses(): void
    {
        self::assertSame(DiagnosticStatus::PASS, $this->migrations(['schemaCompatible' => true, 'pending' => []])->status);
    }

    public function testPendingMigrationsAreAFailureThatNamesWhatIsWaiting(): void
    {
        $result = $this->migrations(['schemaCompatible' => true, 'pending' => ['craft', 'commerce']]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertStringContainsString('commerce', $result->summary);
        self::assertStringContainsString('craft up', (string)$result->recommendation);
    }

    public function testASchemaNewerThanTheCodeIsTreatedAsMoreSeriousThanPendingMigrations(): void
    {
        // A rolled-back deployment. Old code writing to new tables can lose data rather than
        // merely fail, which is why this outranks work that has simply not been done yet.
        $result = $this->migrations(['schemaCompatible' => false, 'pending' => ['craft']]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertStringContainsString('newer', $result->summary);
    }

    public function testAnUnreachableDatabaseMakesTheMigrationStateUnknownRatherThanClean(): void
    {
        $result = $this->migrations(new RuntimeException('No database'));

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertNotSame(DiagnosticStatus::PASS, $result->status);
        self::assertTrue($result->hasEvidence());
    }

    public function testTheMigrationCheckIncludesContentMigrations(): void
    {
        // A half-run deployment leaves those behind too, and leaving them out would report the
        // installation as up to date when it is not.
        $result = $this->migrations(['schemaCompatible' => true, 'pending' => []]);

        self::assertTrue($result->evidence()[0]->get('includesContentMigrations'));
    }

    // --- database.charset ----------------------------------------------------

    /**
     * @param array{charset: string, collation: string|null}|null $actual
     */
    private function charset(?array $actual, ?bool $mb4): DiagnosticResult
    {
        $diagnostic = new class(['actual' => $actual, 'mb4' => $mb4]) extends CharsetDiagnostic {
            /** @var array{charset: string, collation: string|null}|null */
            public ?array $actual = null;

            public ?bool $mb4 = true;

            protected function actualCharset(Connection $db): ?array
            {
                return $this->actual;
            }

            protected function supportsMb4(Connection $db): ?bool
            {
                return $this->mb4;
            }
        };

        return $diagnostic->run($this->context());
    }

    public function testAMatchingCharsetWithFourByteSupportPasses(): void
    {
        $configured = Craft::$app->getConfig()->getDb()->getCharset();
        $result = $this->charset(['charset' => $configured, 'collation' => null], true);

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertTrue($result->evidence()[0]->get('sampledTableAcceptsMb4'));
    }

    public function testTheCharsetCheckSaysExactlyHowNarrowItsFourByteAnswerIs(): void
    {
        // It samples the one table Craft uses as its own indicator. Claiming that every column
        // in the database is fine would be a confident wrong answer, which is the worst kind a
        // diagnostic can give.
        $configured = Craft::$app->getConfig()->getDb()->getCharset();
        $result = $this->charset(['charset' => $configured, 'collation' => null], true);

        self::assertStringContainsString('elements_sites', (string)$result->evidence()[0]->get('sampledTable'));
        self::assertFalse($result->evidence()[0]->get('columnsInspected'));
        self::assertStringContainsString('not inspected', $result->description);
    }

    public function testATableThatRejectsFourByteCharactersIsAWarning(): void
    {
        $configured = Craft::$app->getConfig()->getDb()->getCharset();
        $result = $this->charset(['charset' => $configured, 'collation' => null], false);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::MEDIUM, $result->severity());
        // The wording is about the table it sampled, not about every table.
        self::assertStringContainsString('element index table', $result->summary);
    }

    public function testACharsetThatDisagreesWithTheConfigurationIsAWarning(): void
    {
        $result = $this->charset(['charset' => 'latin1', 'collation' => 'latin1_swedish_ci'], true);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::LOW, $result->severity());
        self::assertSame('latin1', $result->evidence()[0]->get('databaseCharset'));

        // A newer server calling the configured `utf8` by its other name is not a disagreement;
        // four bytes against three still is.
        self::assertTrue(CharsetDiagnostic::sameCharset('utf8mb3', 'utf8'));
        self::assertTrue(CharsetDiagnostic::sameCharset('UTF8MB4', 'utf8mb4'));
        self::assertFalse(CharsetDiagnostic::sameCharset('utf8mb3', 'utf8mb4'));
    }

    public function testADriverThatReportsNoCharsetIsSkippedRatherThanGuessedAt(): void
    {
        self::assertSame(DiagnosticStatus::SKIPPED, $this->charset(null, true)->status);
    }

    public function testAnUnsampleableTableIsNotKnowingRatherThanFine(): void
    {
        // The sample is the question the check exists to answer; not taking it is not knowing.
        $configured = Craft::$app->getConfig()->getDb()->getCharset();
        $result = $this->charset(['charset' => $configured, 'collation' => null], null);

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertNull($result->evidence()[0]->get('sampledTableAcceptsMb4'));
    }

    // --- filesystem.volumes --------------------------------------------------

    private function volume(string $handle): Volume
    {
        return new Volume(['handle' => $handle, 'name' => ucfirst($handle)]);
    }

    /**
     * @param Volume[] $volumes
     */
    private function filesystems(array $volumes, ?callable $resolve = null, ?DiagnosticDepth $depth = null): DiagnosticResult
    {
        $diagnostic = new class(['stated' => $volumes, 'resolve' => $resolve]) extends FilesystemsDiagnostic {
            /** @var Volume[] */
            public array $stated = [];

            /** @var callable|null */
            public $resolve = null;

            protected function volumes(): array
            {
                return $this->stated;
            }

            protected function filesystems(): array
            {
                return [];
            }

            protected function filesystemFor(Volume $volume): ?\craft\base\FsInterface
            {
                return $this->resolve === null ? null : ($this->resolve)($volume);
            }
        };

        return $diagnostic->run($this->context($depth));
    }

    public function testAReachableVolumePasses(): void
    {
        $result = $this->filesystems(
            [$this->volume('images')],
            static fn(): Local => new Local(['path' => Craft::$app->getPath()->getStoragePath(false)]),
        );

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertSame('reachable', $result->evidence()[1]->get('state'));
    }

    public function testAVolumeWithNoFilesystemBehindItIsAFailure(): void
    {
        $result = $this->filesystems([$this->volume('orphaned')]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertStringContainsString('orphaned', $result->summary);
    }

    public function testAVolumeWhoseStorageIsNotThereIsAFailure(): void
    {
        $result = $this->filesystems(
            [$this->volume('remote')],
            static fn(): Local => new Local(['path' => '/web-doctor-no-such-directory']),
        );

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame('unreachable', $result->evidence()[1]->get('state'));
    }

    public function testAFilesystemThatThrowsIsUnreachableRatherThanABrokenCheck(): void
    {
        $result = $this->filesystems([$this->volume('exploding')], static function(): Local {
            return new class(['path' => '/tmp']) extends Local {
                public function directoryExists(string $path): bool
                {
                    throw new RuntimeException('Credentials rejected for user=root password=hunter2');
                }
            };
        });

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame('unreachable', $result->evidence()[1]->get('state'));
        self::assertNotNull($result->evidence()[1]->get('reason'));
    }

    public function testAFilesystemErrorCannotCarryACredentialIntoTheResult(): void
    {
        $result = $this->filesystems([$this->volume('exploding')], static function(): Local {
            return new class(['path' => '/tmp']) extends Local {
                public function directoryExists(string $path): bool
                {
                    throw new RuntimeException('Rejected: password=fake-smtp-password token=fake-secret-token');
                }
            };
        });

        $json = json_encode($result);

        self::assertIsString($json);
        self::assertStringNotContainsString('fake-smtp-password', $json);
        self::assertStringNotContainsString('fake-secret-token', $json);
    }

    public function testAShallowRunNeverReachesOutToStorage(): void
    {
        $result = $this->filesystems(
            [$this->volume('remote')],
            static fn(): Local => new Local(['path' => '/web-doctor-no-such-directory']),
            DiagnosticDepth::SHALLOW,
        );

        // The volume is unreachable, but a shallow run declined to find out — and says so
        // rather than reporting it as fine.
        self::assertSame(DiagnosticStatus::INFO, $result->status);
        self::assertFalse($result->evidence()[0]->get('probed'));
        self::assertSame('notProbed', $result->evidence()[1]->get('state'));
    }

    public function testAnInstallationWithNoVolumesIsSkipped(): void
    {
        self::assertSame(DiagnosticStatus::SKIPPED, $this->filesystems([])->status);
    }

    // --- email.configuration -------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     */
    private function mail(array $overrides = []): DiagnosticResult
    {
        $settings = new MailSettings($overrides + [
            'fromEmail' => 'site@example.com',
            'fromName' => 'Example',
            'transportType' => Smtp::class,
            'transportSettings' => [
                'host' => 'smtp.example.com',
                'port' => '587',
                'useAuthentication' => true,
                'username' => 'apikey',
                'password' => 'fake-smtp-password',
            ],
        ]);

        return (new MailerConfigurationDiagnostic(['settings' => $settings]))->run($this->context());
    }

    public function testCompleteMailSettingsPassWithoutClaimingDeliveryWasTested(): void
    {
        $result = $this->mail();

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertStringContainsString('not tested', $result->summary);
    }

    public function testAMissingSenderAddressIsAFailure(): void
    {
        $result = $this->mail(['fromEmail' => null]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertStringContainsString('sender address', $result->summary);
    }

    public function testASenderAddressPointingAtAnUndefinedEnvironmentVariableSaysSo(): void
    {
        // A different fault from nobody having filled the setting in, and it is fixed somewhere
        // else entirely.
        $result = $this->mail(['fromEmail' => '$WEB_DOCTOR_NOT_DEFINED']);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertStringContainsString('environment variable', $result->summary);
    }

    /**
     * Recorded as missing, not as an empty value, whether the host is blank or absent altogether:
     * that is the word the "a setting the environment lacks" cause reads.
     */
    public function testAMissingSmtpHostIsAFailure(): void
    {
        foreach ([['host' => '', 'useAuthentication' => false], ['useAuthentication' => false]] as $settings) {
            $result = $this->mail(['transportSettings' => $settings]);

            self::assertSame(DiagnosticStatus::FAIL, $result->status);
            self::assertStringContainsString('SMTP host', $result->summary);

            $transport = array_values(array_filter($result->evidence(), static fn(Evidence $e): bool => $e->label === 'Mail transport'));
            self::assertSame(Redaction::MISSING, $transport[0]->get('host'));
        }
    }

    public function testAuthenticationSwitchedOnWithNoCredentialsIsAFailure(): void
    {
        $result = $this->mail(['transportSettings' => [
            'host' => 'smtp.example.com',
            'useAuthentication' => true,
            'username' => '',
            'password' => '',
        ]]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertStringContainsString('user name', $result->summary);
        self::assertStringContainsString('password', $result->summary);
    }

    public function testCredentialsAreNotRequiredWhenAuthenticationIsOff(): void
    {
        $result = $this->mail(['transportSettings' => ['host' => 'smtp.example.com', 'useAuthentication' => false]]);

        self::assertSame(DiagnosticStatus::PASS, $result->status);
    }

    public function testATransportThatCannotBeBuiltIsAFailure(): void
    {
        $result = $this->mail(['transportType' => 'Some\\Removed\\Plugin\\Transport']);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertStringContainsString('transport', $result->summary);
    }

    public function testNoSmtpCredentialIsEverRecordedAsAValue(): void
    {
        $json = json_encode($this->mail());

        self::assertIsString($json);
        self::assertStringNotContainsString('fake-smtp-password', $json);
        // The user name is treated exactly like the password, because a great many mail
        // services use an API token as the user name.
        self::assertStringNotContainsString('apikey', $json);
        self::assertStringContainsString('Present', $json);
    }

    public function testATransportSettingThisCheckHasNeverHeardOfIsStillOnlyReportedAsPresence(): void
    {
        // A transport contributed by a plugin can carry anything. Naming only the few keys that
        // are safe to show, and reducing everything else to presence, is what stops a key
        // nobody anticipated leaking a credential.
        $result = $this->mail(['transportSettings' => [
            'host' => 'smtp.example.com',
            'useAuthentication' => false,
            'oauthRefreshThing' => 'fake-secret-token',
        ]]);

        $json = json_encode($result);

        self::assertIsString($json);
        self::assertStringNotContainsString('fake-secret-token', $json);
    }

    public function testTheMailCheckNeverSends(): void
    {
        // A diagnostic that sent a test message would deliver real mail to a real address every
        // time anybody ran a check. Craft announces every send before it happens, so listening
        // for that is the direct way to hold this to it.
        $sends = 0;
        $mailer = Craft::$app->getMailer();

        $mailer->on(BaseMailer::EVENT_BEFORE_SEND, static function() use (&$sends): void {
            $sends++;
        });

        try {
            // Including the branch that builds a transport, which is the closest this check
            // ever comes to a mail server.
            $this->mail();
            $this->mail(['transportSettings' => ['host' => 'smtp.example.com', 'useAuthentication' => false]]);

            self::assertSame(0, $sends, 'The mail check must never deliver a message.');
        } finally {
            $mailer->off(BaseMailer::EVENT_BEFORE_SEND);
        }
    }

    // --- plugins.health, against the real Craft plugin service ---------------

    public function testCraftStillDescribesPluginsTheWayThisCheckReadsThem(): void
    {
        // The whole of plugins.health rests on two Craft behaviours: that `getAllPluginInfo()`
        // reports `isInstalled` and `isEnabled`, and that `isEnabled` means "the plugin object
        // came into being" while `isPluginEnabled()` means "the installation intends to run
        // it". Fixtures cannot verify that; only Craft can.
        $plugins = Craft::$app->getPlugins();
        $info = $plugins->getAllPluginInfo();

        self::assertNotSame([], $info, 'This installation should have plugins to inspect.');

        foreach ($info as $handle => $plugin) {
            self::assertArrayHasKey('isInstalled', $plugin, sprintf('%s: Craft no longer reports isInstalled.', $handle));
            self::assertArrayHasKey('isEnabled', $plugin, sprintf('%s: Craft no longer reports isEnabled.', $handle));
            self::assertArrayHasKey('licenseIssues', $plugin, sprintf('%s: Craft no longer reports licenseIssues.', $handle));

            if (!$plugin['isInstalled']) {
                continue;
            }

            // A plugin whose object exists must be one the installation means to run. The
            // reverse is what a failure to initialise looks like, and is what the check reports.
            if ($plugin['isEnabled']) {
                self::assertTrue($plugins->isPluginEnabled($handle), sprintf('%s loaded without being switched on.', $handle));
                self::assertNotNull($plugins->getPlugin($handle), sprintf('%s is reported as enabled but has no instance.', $handle));
            }
        }
    }

    public function testAHealthyInstallationsPluginsPass(): void
    {
        $result = (new PluginHealthDiagnostic())->run($this->context());

        self::assertSame(DiagnosticStatus::PASS, $result->status, $result->summary);
        self::assertSame([], $result->evidence()[0]->get('failedToLoad'));
        self::assertSame([], $result->evidence()[0]->get('missingFromProject'));
    }

    public function testAPluginRecordedWithNoCodeIsCaughtAgainstRealCraftData(): void
    {
        // Real plugin info from Craft, with one handle added to what the database is said to
        // hold — which is exactly the state a package removed from `composer.json` without
        // being uninstalled leaves behind.
        $diagnostic = new class() extends PluginHealthDiagnostic {
            protected function recordedHandles(): array
            {
                return [...parent::recordedHandles(), 'web-doctor-ghost'];
            }
        };

        $result = $diagnostic->run($this->context());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertSame(['web-doctor-ghost'], $result->evidence()[0]->get('missingFromProject'));
    }

    public function testTheRealPluginInventoryNeverCarriesALicenceKey(): void
    {
        // Against Craft's real data rather than a fixture, because the key this must not leak
        // is one Craft actually puts in the array it hands over.
        $json = (string)json_encode((new InstalledPluginsDiagnostic())->run($this->context()));

        foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $handle => $plugin) {
            $key = $plugin['licenseKey'] ?? null;

            if (is_string($key) && strlen($key) >= 8) {
                self::assertStringNotContainsString($key, $json, sprintf('%s leaked its licence key.', $handle));
            }
        }

        self::assertStringNotContainsString('licenseKey', $json, 'The key should not even be named.');
    }

    // --- queue.backlog and queue.failedJobs ----------------------------------

    public function testQueueChecksDeclineToRunAgainstAQueueTheyCannotRead(): void
    {
        $context = DiagnosticContext::current();
        $engine = $this->engine();

        foreach ([new FailedJobsDiagnostic(), new QueueBacklogDiagnostic()] as $diagnostic) {
            $diagnostic->queue = new ForeignQueue();

            self::assertFalse($diagnostic->isApplicable($context));
            self::assertSame(
                DiagnosticStatus::SKIPPED,
                $engine->run($diagnostic, $context)->status,
                sprintf('%s should decline rather than guess at a queue it cannot read.', $diagnostic->id()),
            );
        }
    }

    public function testFailedJobsAreReportedAsAFailureWithTheErrorBehindThem(): void
    {
        $diagnostic = new StubbedFailedJobsDiagnostic([
            'queue' => new StubQueue(),
            'failed' => 3,
            'failures' => [
                ['description' => 'Updating search indexes', 'occurrences' => 2, 'firstFailed' => null, 'lastFailed' => null, 'error' => 'Column not found'],
                ['description' => 'Generating transform', 'occurrences' => 1, 'firstFailed' => null, 'lastFailed' => null, 'error' => 'Volume unreachable'],
            ],
        ]);

        $result = $this->engine()->run($diagnostic, DiagnosticContext::current());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        // The same job failing twice is what makes this a condition rather than a one-off, and
        // the severity says so.
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertCount(3, $result->evidence(), 'A summary, plus one piece of evidence per distinct job.');
        self::assertSame(2, $result->evidence()[1]->get('occurrences'));
    }

    public function testFailuresAreNotExaminedAtAllAtShallowDepth(): void
    {
        $diagnostic = new StubbedFailedJobsDiagnostic([
            'queue' => new StubQueue(),
            'failed' => 2,
            'failures' => [
                ['description' => 'Updating search indexes', 'occurrences' => 2, 'firstFailed' => null, 'lastFailed' => null, 'error' => 'Column not found'],
            ],
        ]);

        $result = $this->engine()->run($diagnostic, DiagnosticContext::current(DiagnosticDepth::SHALLOW));

        self::assertSame(DiagnosticStatus::FAIL, $result->status, 'The count is still a finding, however shallow the run.');
        self::assertCount(1, $result->evidence());
        self::assertSame(0, $result->evidence()[0]->get('examined'));
        // Said as it is, rather than as a sample of none.
        self::assertSame('Queue jobs have failed: 2.', $result->summary);
        self::assertStringContainsString('not read at this depth', $result->description);
    }

    public function testAQueueThatCannotBeReadIsReportedAsNotKnowing(): void
    {
        // Not as a queue with nothing in it. An unanswered question is not a clean bill of
        // health, and the run's health score counts it accordingly.
        $diagnostic = new StubbedFailedJobsDiagnostic(['queue' => new StubQueue(), 'unreadable' => true]);

        $result = $this->engine()->run($diagnostic, DiagnosticContext::current());

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertTrue($result->hasEvidence(), 'The exception that stopped it is what says why.');
    }

    public function testAStalledQueueIsAFailureAndABusyOneIsNot(): void
    {
        $stalled = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'waiting' => 4,
            'waitingForSeconds' => 3600,
        ]);

        $busy = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'waiting' => 400,
            'waitingForSeconds' => 5,
        ]);

        $engine = $this->engine();
        $context = DiagnosticContext::current();

        self::assertSame(DiagnosticStatus::FAIL, $engine->run($stalled, $context)->status);
        self::assertSame(DiagnosticStatus::WARNING, $engine->run($busy, $context)->status);
    }

    public function testAJobRunningLongerThanItAllowsItselfIsAWarning(): void
    {
        // Craft keeps when a worker last reported in and how long the job said it would need.
        // Past that, either the job needs longer than it declares or its worker went away.
        $overrunning = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'reserved' => 1,
            'running' => ['seconds' => 900, 'ttr' => 300, 'overrunning' => true],
        ]);

        $result = $this->engine()->run($overrunning, DiagnosticContext::current());

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::MEDIUM, $result->severity());
        self::assertSame(900, $result->evidence()[0]->get('longestRunningSeconds'));
        self::assertSame(300, $result->evidence()[0]->get('longestRunningTimeLimit'));
    }

    public function testAJobStillWithinItsTimeLimitIsNotAFinding(): void
    {
        $working = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'reserved' => 1,
            'running' => ['seconds' => 30, 'ttr' => 300, 'overrunning' => false],
        ]);

        self::assertSame(DiagnosticStatus::PASS, $this->engine()->run($working, DiagnosticContext::current())->status);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function waitTimes(): array
    {
        // STALLED_AFTER is 1800 seconds.
        return [
            'just under the stall threshold' => [1799, 'pass'],
            'exactly at the stall threshold' => [1800, 'fail'],
            'just over the stall threshold' => [1801, 'fail'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('waitTimes')]
    public function testTheStalledThresholdIsExactlyWhereItSaysItIs(int $seconds, string $expected): void
    {
        $diagnostic = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'waiting' => 1,
            'waitingForSeconds' => $seconds,
        ]);

        self::assertSame($expected, $this->engine()->run($diagnostic, DiagnosticContext::current())->status->value);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function backlogSizes(): array
    {
        // LARGE_BACKLOG is 100 jobs.
        return [
            'just under a large backlog' => [99, 'pass'],
            'exactly a large backlog' => [100, 'warning'],
            'just over a large backlog' => [101, 'warning'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backlogSizes')]
    public function testTheBacklogThresholdIsExactlyWhereItSaysItIs(int $waiting, string $expected): void
    {
        $diagnostic = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'waiting' => $waiting,
            'waitingForSeconds' => 5,
        ]);

        self::assertSame($expected, $this->engine()->run($diagnostic, DiagnosticContext::current())->status->value);
    }

    public function testAnOverrunningJobIsJudgedAgainstItsOwnLimitNotAFixedOne(): void
    {
        // Each job declares how long it expects to need, so the only honest comparison is
        // against that rather than against a number Web Doctor picked.
        $within = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'reserved' => 1,
            'running' => ['seconds' => 3600, 'ttr' => 7200, 'overrunning' => false],
        ]);

        $over = new StubbedQueueBacklogDiagnostic([
            'queue' => new StubQueue(),
            'reserved' => 1,
            'running' => ['seconds' => 61, 'ttr' => 60, 'overrunning' => true],
        ]);

        self::assertSame(DiagnosticStatus::PASS, $this->engine()->run($within, DiagnosticContext::current())->status);
        self::assertSame(DiagnosticStatus::WARNING, $this->engine()->run($over, DiagnosticContext::current())->status);
    }

    public function testASampledSetOfFailuresSaysThatItWasSampled(): void
    {
        // Claiming to have characterised every failure after reading ten of them would be a
        // conclusion the evidence does not support.
        $sampled = new StubbedFailedJobsDiagnostic([
            'queue' => new StubQueue(),
            'failed' => 5000,
            'failures' => [
                ['description' => 'Updating search indexes', 'occurrences' => 10, 'firstFailed' => null, 'lastFailed' => null, 'error' => 'Column not found'],
            ],
        ]);

        $result = $this->engine()->run($sampled, DiagnosticContext::current());

        self::assertSame(5000, $result->evidence()[0]->get('failed'));
        self::assertSame(10, $result->evidence()[0]->get('examined'));
        self::assertFalse($result->evidence()[0]->get('sampleIsComplete'));
        self::assertStringContainsString('examined', $result->summary);
        self::assertStringContainsString('not read', $result->description);
    }

    public function testACompleteSetOfFailuresDoesNotClaimToHaveBeenSampled(): void
    {
        $complete = new StubbedFailedJobsDiagnostic([
            'queue' => new StubQueue(),
            'failed' => 2,
            'failures' => [
                ['description' => 'Sending email', 'occurrences' => 2, 'firstFailed' => null, 'lastFailed' => null, 'error' => 'Refused'],
            ],
        ]);

        $result = $this->engine()->run($complete, DiagnosticContext::current());

        self::assertTrue($result->evidence()[0]->get('sampleIsComplete'));
        self::assertStringNotContainsString('examined', $result->summary);
        self::assertStringNotContainsString('not read', $result->description);
    }

    public function testAQueueThatCannotBeCountedIsReportedAsNotKnowing(): void
    {
        $diagnostic = new StubbedQueueBacklogDiagnostic(['queue' => new StubQueue(), 'unreadable' => true]);

        self::assertSame(DiagnosticStatus::UNKNOWN, $this->engine()->run($diagnostic, DiagnosticContext::current())->status);
    }

    /**
     * A queue can keep its jobs on a connection of its own, and the checks read that one. Here it
     * names a table prefix with no queue table behind it, so reading it cannot succeed — where
     * reading the application's default connection instead would have counted another queue's rows.
     */
    public function testTheQueueIsReadOnItsOwnConnection(): void
    {
        if (!Craft::$app->getQueue() instanceof \craft\queue\Queue) {
            self::markTestSkipped('This installation does not use Craft’s database-backed queue.');
        }

        $db = clone Craft::$app->getDb();
        $db->tablePrefix = 'webdoctor_absent_';
        $queue = new \craft\queue\Queue(['db' => $db]);

        foreach ([new QueueBacklogDiagnostic(['queue' => $queue]), new FailedJobsDiagnostic(['queue' => $queue])] as $diagnostic) {
            self::assertSame(DiagnosticStatus::UNKNOWN, $this->engine()->run($diagnostic, DiagnosticContext::current())->status, $diagnostic->id());
        }
    }

    public function testReadingTheQueueNeverChangesIt(): void
    {
        // Craft's own counting methods release timed-out jobs before counting, which writes.
        // A health check must not quietly retry somebody's jobs, so the real diagnostics are
        // run here against the real queue and the table is compared before and after.
        $table = Craft::$app->getQueue() instanceof \craft\queue\Queue
            ? Craft::$app->getQueue()->tableName
            : null;

        if ($table === null) {
            self::markTestSkipped('This installation does not use Craft’s database-backed queue.');
        }

        $snapshot = static fn(): array => (new \craft\db\Query())
            ->select(['id', 'timeUpdated', 'dateReserved', 'progress', 'fail'])
            ->from([$table])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $before = $snapshot();

        $this->engine()->run(new QueueBacklogDiagnostic(), DiagnosticContext::current());
        $this->engine()->run(new FailedJobsDiagnostic(), DiagnosticContext::current(DiagnosticDepth::DEEP));

        self::assertSame($before, $snapshot(), 'Reading the queue changed it.');
    }

    public function testTheQueueCountsAgreeWithCraftsOwn(): void
    {
        // The counts are read with this plugin's queries rather than Craft's methods, because
        // Craft's write before they count. That is only defensible if the two agree, so they
        // are compared — Craft's are called last, after the no-write check above.
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof \craft\queue\Queue) {
            self::markTestSkipped('This installation does not use Craft’s database-backed queue.');
        }

        $diagnostic = new class() extends QueueBacklogDiagnostic {
            /**
             * @return array{waiting: int, delayed: int, reserved: int}
             */
            public function read(\craft\queue\Queue $queue): array
            {
                return $this->counts($queue);
            }
        };

        $mine = $diagnostic->read($queue);

        self::assertSame($queue->getTotalWaiting(), $mine['waiting']);
        self::assertSame($queue->getTotalDelayed(), $mine['delayed']);
        self::assertSame($queue->getTotalReserved(), $mine['reserved']);
    }

    public function testAFailedJobsErrorCannotCarryACredentialIntoEvidence(): void
    {
        // Craft shows job errors to administrators and in development mode. Being allowed to
        // see an error is not the same as being shown a credential that happened to be in one,
        // so redaction applies regardless of who is looking.
        $diagnostic = new StubbedFailedJobsDiagnostic([
            'queue' => new StubQueue(),
            'failed' => 1,
            'failures' => [[
                'description' => 'Sending email',
                'occurrences' => 1,
                'firstFailed' => null,
                'lastFailed' => null,
                'error' => 'SMTP refused: password=fake-smtp-password token=fake-secret-token '
                    . 'Authorization: Bearer fake-security-key-hunter2 dsn=mysql://root:fake-db-password@db/craft',
            ]],
        ]);

        $result = $this->engine()->run($diagnostic, DiagnosticContext::current());
        $json = (string)json_encode($result);

        foreach (['fake-smtp-password', 'fake-secret-token', 'fake-security-key-hunter2', 'fake-db-password'] as $secret) {
            self::assertStringNotContainsString($secret, $json, "The queue error carried $secret into the result.");
        }

        self::assertStringContainsString('SMTP refused', $json, 'The part of the error that explains the failure should survive.');
    }
}
