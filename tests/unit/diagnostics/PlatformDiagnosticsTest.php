<?php

namespace Tahadudhiya\WebDoctor\Tests\unit\diagnostics;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\craft\ApplicationDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\craft\CraftVersionDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\environment\EnvironmentDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\php\PhpConfigurationDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\php\PhpExtensionsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\php\PhpVersionDiagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * The platform checks: which Craft is running and in what state, and whether the PHP underneath
 * it is the one Craft asked for and configured the way Craft needs.
 *
 * Each check reads the installation through a small set of overridable methods, so every state —
 * an unsupported PHP, a skipped upgrade breakpoint, a setting PHP will not report — is exercised
 * by stating it rather than by putting a machine into it.
 */
class PlatformDiagnosticsTest extends TestCase
{
    // --- Craft itself, and the environment it believes it is running in.

    private function context(): DiagnosticContext
    {
        return new DiagnosticContext();
    }

    private function craftVersion(bool|RuntimeException $breakpointSkipped, ?string $licensed = null): DiagnosticResult
    {
        $diagnostic = new class(['skipped' => $breakpointSkipped, 'licensed' => $licensed]) extends CraftVersionDiagnostic {
            public bool|RuntimeException $skipped = false;
            public ?string $licensed = null;

            protected function version(): string
            {
                return '5.11.3';
            }

            protected function edition(): string
            {
                return 'Pro';
            }

            protected function schemaVersion(): string
            {
                return '5.4.0.0';
            }

            protected function minimumVersion(): string
            {
                return '4.5.0';
            }

            protected function breakpointSkipped(): bool
            {
                if ($this->skipped instanceof RuntimeException) {
                    throw $this->skipped;
                }

                return $this->skipped;
            }

            protected function licensedEdition(): ?string
            {
                return $this->licensed;
            }
        };

        return $diagnostic->run($this->context());
    }

    public function testAnOrdinaryInstallationReportsItsVersionAndEdition(): void
    {
        $result = $this->craftVersion(false);

        self::assertSame(DiagnosticStatus::INFO, $result->status);
        self::assertSame('5.11.3', $result->evidence()[0]->get('version'));
        self::assertSame('Pro', $result->evidence()[0]->get('edition'));
        self::assertSame('5.4.0.0', $result->evidence()[0]->get('schemaVersion'));
    }

    public function testASkippedUpgradeBreakpointIsTheMostSeriousFindingThisCheckMakes(): void
    {
        $result = $this->craftVersion(true);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertStringContainsString('4.5.0', (string)$result->recommendation);
    }

    public function testAnUnreadableUpgradeHistoryIsReportedAsNotKnowing(): void
    {
        // Not as a clean upgrade path. The answer lives in the database, and a database out of
        // reach means there is no answer rather than a good one.
        $result = $this->craftVersion(new RuntimeException('No database'));

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertCount(2, $result->evidence(), 'The version, plus the exception that stopped the rest.');
    }

    public function testTheLicensedEditionIsRecordedButNeverJudged(): void
    {
        // Craft lets a development domain run any edition it likes, and whether this is such a
        // domain is a question about the request rather than about the installation — so a
        // console run could not answer it, and a check that warned here would be wrong often.
        $result = $this->craftVersion(false, 'Solo');

        self::assertSame(DiagnosticStatus::INFO, $result->status);
        self::assertSame('Solo', $result->evidence()[0]->get('licensedEdition'));
    }

    /**
     * @param bool|RuntimeException $installed
     */
    private function application(bool|RuntimeException $installed, ?bool $maintenance = false, ?bool $live = true): DiagnosticResult
    {
        $diagnostic = new class(['installed' => $installed, 'maintenance' => $maintenance, 'live' => $live]) extends ApplicationDiagnostic {
            public bool|RuntimeException $installed = true;
            public ?bool $maintenance = false;
            public ?bool $live = true;

            protected function isInstalled(): bool
            {
                if ($this->installed instanceof RuntimeException) {
                    throw $this->installed;
                }

                return $this->installed;
            }

            protected function isInMaintenanceMode(): ?bool
            {
                return $this->maintenance;
            }

            protected function isLive(): ?bool
            {
                return $this->live;
            }

            public int|string $sites = 2;

            protected function siteCount(): int|string
            {
                return $this->sites;
            }
        };

        return $diagnostic->run($this->context());
    }

    public function testAnInstalledAndServingCraftPasses(): void
    {
        $result = $this->application(true);

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertSame(2, $result->evidence()[0]->get('sites'));
    }

    public function testACraftThatIsNotInstalledIsACriticalFailure(): void
    {
        $result = $this->application(false);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertFalse($result->evidence()[0]->get('installed'));
    }

    public function testMaintenanceModeIsAWarning(): void
    {
        $result = $this->application(true, maintenance: true);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::MEDIUM, $result->severity());
    }

    public function testBeingOfflineIsReportedRatherThanJudged(): void
    {
        // Taking a site offline is deliberate, and Web Doctor cannot tell a planned maintenance
        // window from an accident.
        $result = $this->application(true, live: false);

        self::assertSame(DiagnosticStatus::INFO, $result->status);
    }

    public function testAnUnreachableInstallationStateIsReportedAsNotKnowing(): void
    {
        $result = $this->application(new RuntimeException('No database'));

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertTrue($result->hasEvidence());
    }

    public function testAServingStateThatCannotBeReadIsNotReportedAsServingFine(): void
    {
        // The bypass this guards against: Craft being installed says nothing about whether it
        // is serving, so passing here would be the check vouching for something it failed to
        // look at.
        $result = $this->application(true, maintenance: null, live: null);

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertSame('Unknown', $result->evidence()[0]->get('maintenanceMode'));
        self::assertSame('Unknown', $result->evidence()[0]->get('live'));
        self::assertStringContainsString('maintenanceMode', $result->summary);
        self::assertStringContainsString('live', $result->summary);
    }

    public function testEitherServingStateBeingUnreadableIsEnoughToWithholdAPass(): void
    {
        foreach ([['maintenance' => null, 'live' => true], ['maintenance' => false, 'live' => null]] as $states) {
            $result = $this->application(true, maintenance: $states['maintenance'], live: $states['live']);

            self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        }
    }

    public function testAKnownProblemOutranksAStateThatCouldNotBeRead(): void
    {
        // Maintenance mode is a definite finding; not knowing whether the system is live does
        // not make it less definite.
        $result = $this->application(true, maintenance: true, live: null);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
    }

    public function testAnUnreadableSiteCountDoesNotWithholdAPass(): void
    {
        // How many sites there are is context, not a verdict about whether Craft is serving.
        $diagnostic = new class() extends ApplicationDiagnostic {
            protected function isInstalled(): bool
            {
                return true;
            }

            protected function isInMaintenanceMode(): ?bool
            {
                return false;
            }

            protected function isLive(): ?bool
            {
                return true;
            }

            public int|string $sites = 'Unknown';

            protected function siteCount(): int|string
            {
                return $this->sites;
            }
        };

        $result = $diagnostic->run($this->context());

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertSame('Unknown', $result->evidence()[0]->get('sites'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function environment(array $overrides = []): DiagnosticResult
    {
        $ordinary = [
            'environment' => 'production',
            'devMode' => false,
            'allowAdminChanges' => false,
            'allowUpdates' => false,
            'runQueueAutomatically' => true,
            'timezone' => 'UTC',
            'securityKey' => 'a-real-looking-security-key-value',
            'craftEnvironmentVariable' => 'production',
        ];

        $diagnostic = new class(['values' => $overrides + $ordinary]) extends EnvironmentDiagnostic {
            /** @var array<string, mixed> */
            public array $values = [];

            protected function settings(): array
            {
                return $this->values;
            }
        };

        return $diagnostic->run(new DiagnosticContext());
    }

    public function testAnOrdinaryProductionEnvironmentIsReportedWithoutAFinding(): void
    {
        self::assertSame(DiagnosticStatus::INFO, $this->environment()->status);
    }

    public function testAMissingSecurityKeyIsACriticalFailure(): void
    {
        $result = $this->environment(['securityKey' => '']);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertSame('Missing', $result->evidence()[1]->get('CRAFT_SECURITY_KEY'));
    }

    public function testTheSecurityKeyIsOnlyEverRecordedAsPresentOrMissing(): void
    {
        $key = 'fake-security-key-hunter2';
        $json = json_encode($this->environment(['securityKey' => $key]));

        self::assertIsString($json);
        self::assertStringNotContainsString($key, $json);
        self::assertStringContainsString('Present', $json);
    }

    public function testDevelopmentModeOutsideADevelopmentEnvironmentIsAWarning(): void
    {
        $result = $this->environment(['devMode' => true]);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        // The environment's own name is the only evidence for what kind of environment this
        // is, so the finding is offered as a reading of that name rather than as established
        // fact — and the confidence has to say so.
        self::assertSame('possible', $result->confidence->value);
    }

    public function testDevelopmentModeOnADevelopmentMachineIsHowItIsMeantToBe(): void
    {
        foreach (['dev', 'local', 'development', 'DEV'] as $environment) {
            $result = $this->environment(['devMode' => true, 'environment' => $environment]);

            self::assertSame(DiagnosticStatus::INFO, $result->status, sprintf('“%s” names a development environment.', $environment));
        }
    }

    public function testAnUnfamiliarEnvironmentNameIsReadAsAServedOne(): void
    {
        // Assuming the safer reading of a name nobody recognises is the only defensible
        // default: the cost of a warning on a developer's machine is far lower than the cost
        // of silence on a server.
        self::assertSame(DiagnosticStatus::WARNING, $this->environment(['devMode' => true, 'environment' => 'sandbox'])->status);
    }

    // --- The PHP underneath it.

    private function version(string $running, ?string $required): PhpVersionDiagnostic
    {
        return new class(['running' => $running, 'requirement' => $required]) extends PhpVersionDiagnostic {
            public string $running = '';
            public ?string $requirement = null;

            protected function runtimeVersion(): string
            {
                return $this->running;
            }

            protected function fullRuntimeVersion(): string
            {
                return $this->running;
            }

            protected function sapi(): string
            {
                return 'cli';
            }

            protected function requirement(): ?string
            {
                return $this->requirement;
            }
        };
    }

    public function testASupportedPhpVersionPasses(): void
    {
        $result = $this->version('8.3.1', '^8.2')->run($this->context());

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertSame('8.3.1', $result->evidence()[0]->get('version'));
        self::assertSame('^8.2', $result->evidence()[0]->get('required'));
    }

    public function testAnUnsupportedPhpVersionIsACriticalFailure(): void
    {
        $result = $this->version('8.1.0', '^8.2')->run($this->context());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        // Nothing else can be trusted on an unsupported PHP, which is what earns this the top
        // severity rather than merely a high one.
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertNotNull($result->recommendation);
    }

    public function testAnUnreadableRequirementIsReportedAsNotKnowing(): void
    {
        // Rather than as a pass. A requirement Web Doctor could not read says nothing about
        // whether this PHP is supported.
        $result = $this->version('8.3.1', null)->run($this->context());

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertNull($result->evidence()[0]->get('required'));
    }

    public function testARequirementThatCannotBeInterpretedIsReportedAsNotKnowing(): void
    {
        $result = $this->version('8.3.1', 'not a constraint')->run($this->context());

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
    }

    public function testThePhpVersionResultNamesTheInterpreterItInspected(): void
    {
        // The web server and the command line routinely run different builds, so a finding
        // that did not say which one it looked at would send people to the wrong php.ini.
        $result = $this->version('8.3.1', '^8.2')->run($this->context());

        self::assertSame('cli', $result->evidence()[0]->get('sapi'));
    }

    /**
     * @param string[] $required
     * @param string[] $loaded
     */
    private function extensions(array $required, array $loaded, array $recommended = ['imagick']): PhpExtensionsDiagnostic
    {
        return new class([ 'requiredExtensions' => $required, 'loadedExtensions' => $loaded, 'recommendedExtensions' => $recommended, ]) extends PhpExtensionsDiagnostic {
            /** @var string[] */
            public array $requiredExtensions = [];

            /** @var string[] */
            public array $loadedExtensions = [];

            /** @var string[] */
            public array $recommendedExtensions = [];

            protected function required(): array
            {
                return $this->requiredExtensions;
            }

            protected function recommended(): array
            {
                return $this->recommendedExtensions;
            }

            protected function isLoaded(string $extension): bool
            {
                return in_array($extension, $this->loadedExtensions, true);
            }

            protected function sapi(): string
            {
                return 'fpm-fcgi';
            }
        };
    }

    public function testEveryRequiredAndRecommendedExtensionPresentPasses(): void
    {
        $result = $this->extensions(['gd', 'mbstring', 'pdo'], ['gd', 'mbstring', 'pdo', 'imagick'])->run($this->context());

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertSame([], $result->evidence()[0]->get('missing'));
        self::assertSame([], $result->evidence()[0]->get('recommendedMissing'));
    }

    public function testAMissingRequiredExtensionIsACriticalFailure(): void
    {
        $result = $this->extensions(['pdo', 'intl'], ['pdo', 'gd', 'imagick'])->run($this->context());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertSame(['intl'], $result->evidence()[0]->get('missing'));
        self::assertStringContainsString('intl', (string)$result->recommendation);
    }

    public function testAMissingGdIsAFailureBecauseCraftRequiresItOutright(): void
    {
        // Craft's requirements mark GD mandatory and Imagick optional. A server with Imagick
        // and no GD does not meet Craft's requirements however capable it looks, so treating
        // the two as interchangeable would report a real problem as fine.
        $result = $this->extensions(['gd', 'pdo'], ['pdo', 'imagick'])->run($this->context());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::CRITICAL, $result->severity());
        self::assertSame(['gd'], $result->evidence()[0]->get('missing'));
    }

    public function testAMissingRecommendedExtensionIsReportedWithoutBeingAFinding(): void
    {
        // Craft marks Imagick `'mandatory' => false` and works without it, so this is
        // information rather than a fault.
        $result = $this->extensions(['gd', 'pdo'], ['gd', 'pdo'])->run($this->context());

        self::assertSame(DiagnosticStatus::INFO, $result->status);
        self::assertSame(['imagick'], $result->evidence()[0]->get('recommendedMissing'));
        self::assertStringContainsString('imagick', $result->summary);
    }

    public function testAMissingRequirementOutranksAMissingRecommendation(): void
    {
        $result = $this->extensions(['gd', 'pdo'], [])->run($this->context());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        // Both are still recorded, so neither is lost to the other being reported.
        self::assertSame(['gd', 'pdo'], $result->evidence()[0]->get('missing'));
        self::assertSame(['imagick'], $result->evidence()[0]->get('recommendedMissing'));
    }

    public function testAnUnreadableExtensionListIsReportedAsNotKnowing(): void
    {
        $result = $this->extensions([], ['pdo'])->run($this->context());

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
    }

    public function testTheExtensionResultNamesTheInterpreterItInspected(): void
    {
        $result = $this->extensions(['pdo'], ['pdo', 'gd', 'imagick'])->run($this->context());

        self::assertSame('fpm-fcgi', $result->evidence()[0]->get('sapi'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configuration(array $overrides = []): DiagnosticResult
    {
        $healthy = [
            'sapi' => 'fpm-fcgi',
            'memory_limit' => '512M',
            'max_execution_time' => '120',
            'upload_max_filesize' => '32M',
            'post_max_size' => '64M',
            'opcache' => true,
            'opcache.save_comments' => true,
        ];

        $diagnostic = new class(['values' => $overrides + $healthy]) extends PhpConfigurationDiagnostic {
            /** @var array<string, mixed> */
            public array $values = [];

            protected function settings(): array
            {
                return $this->values;
            }
        };

        return $diagnostic->run(new DiagnosticContext());
    }

    public function testASensiblyConfiguredPhpPasses(): void
    {
        self::assertSame(DiagnosticStatus::PASS, $this->configuration()->status);
    }

    public function testAnOpcodeCacheDiscardingCommentsIsAFailure(): void
    {
        // Craft and its plugins read docblocks while running, so this breaks them in ways that
        // look like anything but a PHP setting — which is why it outranks the other findings
        // here rather than joining them in a list.
        $result = $this->configuration(['opcache.save_comments' => false]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
    }

    public function testCommentsAreNotAFindingWhenThereIsNoOpcodeCache(): void
    {
        $result = $this->configuration(['opcache' => false, 'opcache.save_comments' => false]);

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertTrue($result->evidence()[0]->get('opcache.save_comments'));
    }

    public function testTooLittleMemoryIsAWarning(): void
    {
        $result = $this->configuration(['memory_limit' => '128M']);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertStringContainsString('memory_limit', $result->summary);
    }

    public function testAnUnlimitedMemoryLimitIsNotAFinding(): void
    {
        // `-1` normalises to a negative byte count, which is no limit at all rather than a
        // very small one.
        self::assertSame(DiagnosticStatus::PASS, $this->configuration(['memory_limit' => '-1'])->status);
    }

    public function testAShortExecutionLimitIsAWarningAndNoLimitIsNot(): void
    {
        self::assertSame(DiagnosticStatus::WARNING, $this->configuration(['max_execution_time' => '10'])->status);

        // Zero means no limit, which is the command line's ordinary state.
        self::assertSame(DiagnosticStatus::PASS, $this->configuration(['max_execution_time' => '0'])->status);
    }

    public function testAnUploadLimitLargerThanThePostLimitIsAWarning(): void
    {
        $result = $this->configuration(['upload_max_filesize' => '128M', 'post_max_size' => '64M']);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertStringContainsString('post_max_size', $result->summary);
    }

    public function testSeveralConfigurationProblemsAreAllReported(): void
    {
        $result = $this->configuration(['memory_limit' => '64M', 'max_execution_time' => '5']);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertStringContainsString('memory_limit', $result->summary);
        self::assertStringContainsString('max_execution_time', $result->summary);
    }

    public function testTheConfigurationResultNamesTheInterpreterItInspected(): void
    {
        self::assertSame('fpm-fcgi', $this->configuration()->evidence()[0]->get('sapi'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function memoryLimits(): array
    {
        return [
            'below what Craft asks for' => ['255M', true],
            'exactly what Craft asks for' => ['256M', false],
            'above what Craft asks for' => ['257M', false],
            'the same figure in kilobytes' => ['262144K', false],
            'one kilobyte short' => ['262143K', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('memoryLimits')]
    public function testTheMemoryThresholdIsExactlyWhereItSaysItIs(string $limit, bool $expectWarning): void
    {
        // A threshold that is off by one is a diagnostic that tells somebody to change a
        // setting that is already correct, which is how people stop trusting it.
        $result = $this->configuration(['memory_limit' => $limit]);

        self::assertSame(
            $expectWarning ? DiagnosticStatus::WARNING : DiagnosticStatus::PASS,
            $result->status,
            sprintf('memory_limit of %s', $limit),
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function executionLimits(): array
    {
        return [
            'below the minimum' => ['29', true],
            'exactly the minimum' => ['30', false],
            'above the minimum' => ['31', false],
            'no limit at all' => ['0', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('executionLimits')]
    public function testTheExecutionTimeThresholdIsExactlyWhereItSaysItIs(string $seconds, bool $expectWarning): void
    {
        $result = $this->configuration(['max_execution_time' => $seconds]);

        self::assertSame(
            $expectWarning ? DiagnosticStatus::WARNING : DiagnosticStatus::PASS,
            $result->status,
            sprintf('max_execution_time of %s', $seconds),
        );
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function uploadLimits(): array
    {
        return [
            'upload under the post limit' => ['31M', '32M', false],
            'upload equal to the post limit' => ['32M', '32M', false],
            'upload over the post limit' => ['33M', '32M', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uploadLimits')]
    public function testTheUploadThresholdIsExactlyWhereItSaysItIs(string $upload, string $post, bool $expectWarning): void
    {
        // Equal is fine: the limit that bites is the smaller of the two, and equal means
        // neither is smaller.
        $result = $this->configuration(['upload_max_filesize' => $upload, 'post_max_size' => $post]);

        self::assertSame($expectWarning ? DiagnosticStatus::WARNING : DiagnosticStatus::PASS, $result->status);
    }

    public function testAConfigurationPhpWillNotReportIsNotJudgedAsHealthy(): void
    {
        // The bypass this guards against: an unreadable setting normalises to zero, zero means
        // "no limit", and the check would have passed on the strength of the parts it could
        // read rather than saying it could not read the rest.
        $result = $this->configuration(['memory_limit' => null, 'post_max_size' => null]);

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertStringContainsString('memory_limit', $result->summary);
        self::assertStringContainsString('post_max_size', $result->summary);
        self::assertSame('Unknown', $result->evidence()[0]->get('memory_limit'));
    }

    public function testAnUnreadableSettingIsNotConfusedWithNoLimit(): void
    {
        // `0` and `-1` genuinely mean no limit and are fine; null means nobody could tell.
        self::assertSame(DiagnosticStatus::PASS, $this->configuration(['max_execution_time' => '0'])->status);
        self::assertSame(DiagnosticStatus::UNKNOWN, $this->configuration(['max_execution_time' => null])->status);
    }

    public function testAnOpcodeCacheProblemIsStillReportedWhenOtherSettingsAreUnreadable(): void
    {
        // A definite failure outranks not knowing about something else.
        $result = $this->configuration(['memory_limit' => null, 'opcache.save_comments' => false]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
    }
}
