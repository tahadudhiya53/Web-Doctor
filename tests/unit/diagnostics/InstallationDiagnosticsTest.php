<?php

namespace Tahadudhiya\WebDoctor\Tests\unit\diagnostics;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\plugins\InstalledPluginsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\plugins\PluginHealthDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\projectconfig\PendingChangesDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\projectconfig\ProjectConfigIntegrityDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * The checks about this particular installation: what is installed beside Craft, whether the
 * configuration on disk has been applied to it, and whether Craft can write where it needs to.
 */
class InstallationDiagnosticsTest extends TestCase
{
    // --- The plugins installed alongside Craft.

    /**
     * A plugin as Craft's plugins service describes one.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function plugin(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Example',
            'version' => '2.1.0',
            'edition' => 'standard',
            'isInstalled' => true,
            // Craft sets this from whether the plugin object was created, not from whether the
            // installation means to run it.
            'isEnabled' => true,
            'licenseKeyStatus' => 'valid',
            'licenseKey' => 'ABCD-EFGH-IJKL-MNOP',
            'licenseIssues' => [],
        ];
    }

    /**
     * @param array<string, array<string, mixed>>|RuntimeException $info
     * @param list<string> $switchedOff
     */
    private function inventory(array|RuntimeException $info, array $switchedOff = []): DiagnosticResult
    {
        $diagnostic = new class(['info' => $info, 'switchedOff' => $switchedOff]) extends InstalledPluginsDiagnostic {
            /** @var array<string, array<string, mixed>>|RuntimeException */
            public array|RuntimeException $info = [];

            /** @var list<string> */
            public array $switchedOff = [];

            protected function pluginInfo(): array
            {
                if ($this->info instanceof RuntimeException) {
                    throw $this->info;
                }

                return $this->info;
            }

            protected function isSwitchedOn(string $handle): bool
            {
                return !in_array($handle, $this->switchedOff, true);
            }
        };

        return $diagnostic->run(new DiagnosticContext());
    }

    public function testAProjectWithNoPluginsSaysSo(): void
    {
        $result = $this->inventory([]);

        self::assertSame(DiagnosticStatus::INFO, $result->status);
    }

    public function testEveryInstalledPluginIsRecordedAsItsOwnPieceOfEvidence(): void
    {
        // One per plugin, so a later investigation can tie a failure to the version of the
        // plugin it blames without unpicking a list.
        $result = $this->inventory([
            'alpha' => $this->plugin(['name' => 'Alpha']),
            'beta' => $this->plugin(['name' => 'Beta', 'version' => '1.0.0']),
        ]);

        self::assertCount(2, $result->evidence());
        self::assertSame('alpha', $result->evidence()[0]->get('handle'));
        self::assertSame('1.0.0', $result->evidence()[1]->get('version'));
        self::assertSame('valid', $result->evidence()[0]->get('licenseStatus'));
    }

    public function testAPluginThatIsNotInstalledIsNotInTheInventory(): void
    {
        // Craft lists every plugin Composer installed, whether or not Craft has been told to
        // install it. Only the ones this installation actually has are its inventory.
        $result = $this->inventory([
            'alpha' => $this->plugin(),
            'beta' => $this->plugin(['isInstalled' => false]),
        ]);

        self::assertCount(1, $result->evidence());
    }

    public function testSwitchedOffPluginsAreNamedWithoutBeingTreatedAsAProblem(): void
    {
        $result = $this->inventory([
            'alpha' => $this->plugin(),
            'beta' => $this->plugin(['isEnabled' => false]),
            // Switched on, but it failed to load: that is a fault, and not this check's to call a choice.
            'gamma' => $this->plugin(['isEnabled' => false]),
        ], switchedOff: ['beta']);

        self::assertSame(DiagnosticStatus::INFO, $result->status);
        self::assertStringContainsString('Switched off: beta.', $result->summary);
        self::assertTrue($result->evidence()[2]->get('enabled'));
        self::assertFalse($result->evidence()[2]->get('loaded'));
    }

    public function testALicenceKeyIsNeverRecordedInAnyForm(): void
    {
        $json = json_encode($this->inventory(['alpha' => $this->plugin()]));

        self::assertIsString($json);
        self::assertStringNotContainsString('ABCD-EFGH-IJKL-MNOP', $json);
        self::assertStringContainsString('valid', $json, 'The licence status is a fact worth keeping; the key is not.');
    }

    public function testAnUnreadablePluginListIsReportedAsNotKnowing(): void
    {
        $result = $this->inventory(new RuntimeException('No database'));

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertTrue($result->hasEvidence());
    }

    /**
     * @param array<string, array<string, mixed>>|RuntimeException $info
     * @param string[] $recorded
     * @param string[] $switchedOn
     */
    private function health(array|RuntimeException $info, array $recorded, array $switchedOn = []): DiagnosticResult
    {
        $diagnostic = new class(['info' => $info, 'recorded' => $recorded, 'switchedOn' => $switchedOn]) extends PluginHealthDiagnostic {
            /** @var array<string, array<string, mixed>>|RuntimeException */
            public array|RuntimeException $info = [];

            /** @var string[] */
            public array $recorded = [];

            /** @var string[] */
            public array $switchedOn = [];

            protected function pluginInfo(): array
            {
                if ($this->info instanceof RuntimeException) {
                    throw $this->info;
                }

                return $this->info;
            }

            protected function recordedHandles(): array
            {
                return $this->recorded;
            }

            protected function isSwitchedOn(string $handle): bool
            {
                return in_array($handle, $this->switchedOn, true);
            }
        };

        return $diagnostic->run(new DiagnosticContext());
    }

    public function testAProjectWhereEveryPluginLoadedPasses(): void
    {
        $result = $this->health(['alpha' => $this->plugin()], ['alpha'], ['alpha']);

        self::assertSame(DiagnosticStatus::PASS, $result->status);
    }

    public function testAPluginSwitchedOffDeliberatelyIsNotAFailureToLoad(): void
    {
        // Installed, not enabled in the configuration, and so no plugin object — which is the
        // same shape as one that failed to initialise. What tells them apart is whether the
        // installation means to be running it.
        $result = $this->health(['alpha' => $this->plugin(['isEnabled' => false])], ['alpha'], switchedOn: []);

        self::assertSame(DiagnosticStatus::PASS, $result->status);
    }

    public function testAPluginSwitchedOnThatNeverLoadedIsAFailure(): void
    {
        $result = $this->health(['alpha' => $this->plugin(['isEnabled' => false])], ['alpha'], switchedOn: ['alpha']);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertSame(['alpha'], $result->evidence()[0]->get('failedToLoad'));
    }

    public function testAPluginRecordedInTheDatabaseWithNoCodeIsAFailure(): void
    {
        // Craft's plugins service iterates what Composer installed, so a row with no package
        // behind it is invisible to it — which is why the table is read as well.
        $result = $this->health(['alpha' => $this->plugin()], ['alpha', 'ghost'], ['alpha']);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(['ghost'], $result->evidence()[0]->get('missingFromProject'));
        self::assertStringContainsString('ghost', $result->summary);
    }

    public function testMissingCodeOutranksAPluginThatMerelyFailedToLoad(): void
    {
        $result = $this->health(
            ['alpha' => $this->plugin(['isEnabled' => false])],
            ['alpha', 'ghost'],
            switchedOn: ['alpha'],
        );

        self::assertStringContainsString('ghost', $result->summary);
        // Both are still recorded, so neither finding is lost to the other being reported.
        self::assertSame(['alpha'], $result->evidence()[0]->get('failedToLoad'));
    }

    public function testALicensingProblemIsAWarningRatherThanAFault(): void
    {
        // Licensing does not stop a plugin working, and it is Craft's own business, reported in
        // Craft's own interface.
        $result = $this->health(
            ['alpha' => $this->plugin(['licenseIssues' => ['invalid']])],
            ['alpha'],
            ['alpha'],
        );

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::LOW, $result->severity());
    }

    public function testAFreePluginIsNeverTreatedAsHavingALicensingProblem(): void
    {
        // Craft returns no issues for a plugin whose licence status it does not know, which is
        // the state every free plugin is in.
        $result = $this->health(
            ['alpha' => $this->plugin(['licenseKeyStatus' => 'unknown', 'licenseKey' => null, 'licenseIssues' => []])],
            ['alpha'],
            ['alpha'],
        );

        self::assertSame(DiagnosticStatus::PASS, $result->status);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function licenseIssues(): array
    {
        return [
            // These come straight from Craft's licensing service.
            'invalid' => ['invalid', true],
            'mismatched' => ['mismatched', true],
            'astray' => ['astray', true],
            // These are decided partly by whether the request may test editions, which is false
            // for every console run whatever the site — so reporting them would mean a
            // command-line check contradicting the control panel on a developer's machine.
            'wrong_edition' => ['wrong_edition', false],
            'required' => ['required', false],
            'no_trials' => ['no_trials', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('licenseIssues')]
    public function testOnlyLicensingProblemsThatMeanTheSameEverywhereAreReported(string $issue, bool $expectFinding): void
    {
        $result = $this->health(
            ['alpha' => $this->plugin(['licenseIssues' => [$issue]])],
            ['alpha'],
            ['alpha'],
        );

        self::assertSame(
            $expectFinding ? DiagnosticStatus::WARNING : DiagnosticStatus::PASS,
            $result->status,
            sprintf('licence issue “%s”', $issue),
        );

        // Either way it is recorded, so nothing is hidden — only the finding differs.
        $recorded = $expectFinding
            ? $result->evidence()[0]->get('licensing')
            : $result->evidence()[0]->get('licensingDependsOnContext');

        self::assertSame(['alpha'], $recorded);
    }

    public function testAnUnreadablePluginStateIsReportedAsNotKnowing(): void
    {
        $result = $this->health(new RuntimeException('No database'), []);

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
    }

    // --- The project config files, and whether they can be applied.

    /**
     * @param array<string, mixed>|RuntimeException $state
     */
    private function pending(array|RuntimeException $state): DiagnosticResult
    {
        $applied = [
            'externalConfigExists' => true,
            'changesPending' => false,
            'writeYamlAutomatically' => true,
            'readOnly' => false,
            'allowAdminChanges' => true,
        ];

        $diagnostic = new class(['state' => is_array($state) ? $state + $applied : $state]) extends PendingChangesDiagnostic {
            /** @var array<string, mixed>|RuntimeException */
            public array|RuntimeException $state = [];

            protected function state(): array
            {
                if ($this->state instanceof RuntimeException) {
                    throw $this->state;
                }

                return $this->state;
            }
        };

        return $diagnostic->run(new DiagnosticContext());
    }

    public function testAnAppliedProjectConfigPasses(): void
    {
        self::assertSame(DiagnosticStatus::PASS, $this->pending([])->status);
    }

    public function testAnInstallationWithNoProjectConfigFilesHasNothingToApply(): void
    {
        // Keeping configuration only in the database is a supported arrangement, not a fault.
        $result = $this->pending(['externalConfigExists' => false]);

        self::assertSame(DiagnosticStatus::INFO, $result->status);
        self::assertFalse($result->evidence()[0]->get('externalConfigExists'));
    }

    public function testPendingChangesSomebodyCanStillApplyAreAWarning(): void
    {
        $result = $this->pending(['changesPending' => true, 'allowAdminChanges' => true]);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::MEDIUM, $result->severity());
    }

    public function testPendingChangesNobodyCanApplyAreAFailure(): void
    {
        // With administrative changes switched off, nothing will apply these except a
        // deployment step, and its absence is the actual fault.
        $result = $this->pending(['changesPending' => true, 'allowAdminChanges' => false]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertStringContainsString('craft up', (string)$result->recommendation);
    }

    public function testAnUnreadableProjectConfigIsReportedAsNotKnowing(): void
    {
        $result = $this->pending(new RuntimeException('Corrupt YAML'));

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertTrue($result->hasEvidence());
    }

    /**
     * @param array{compatible: bool, issues: array<int, mixed>}|RuntimeException $check
     */
    private function integrity(array|RuntimeException $check, bool $externalConfigExists = true): ProjectConfigIntegrityDiagnostic
    {
        return new class(['check' => $check, 'exists' => $externalConfigExists]) extends ProjectConfigIntegrityDiagnostic {
            /** @var array{compatible: bool, issues: array<int, mixed>}|RuntimeException */
            public array|RuntimeException $check = ['compatible' => true, 'issues' => []];

            public bool $exists = true;

            protected function externalConfigExists(): bool
            {
                // A project config that cannot be read fails here first, exactly as it would
                // in the real check.
                if ($this->check instanceof RuntimeException) {
                    throw $this->check;
                }

                return $this->exists;
            }

            protected function schemaVersionCheck(): array
            {
                if ($this->check instanceof RuntimeException) {
                    throw $this->check;
                }

                return $this->check;
            }
        };
    }

    public function testMatchingSchemaVersionsPass(): void
    {
        $result = $this->integrity(['compatible' => true, 'issues' => []])->run(new DiagnosticContext());

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertTrue($result->evidence()[0]->get('compatible'));
    }

    public function testDisagreeingSchemaVersionsAreAFailureThatNamesWhatDisagrees(): void
    {
        $result = $this->integrity([
            'compatible' => false,
            'issues' => [
                ['cause' => 'Craft CMS', 'existing' => '5.4.0.0', 'incoming' => '5.5.0.0'],
                ['cause' => 'Commerce', 'existing' => '5.0.0.0', 'incoming' => '5.1.0.0'],
            ],
        ])->run(new DiagnosticContext());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertStringContainsString('Craft CMS', $result->summary);
        self::assertStringContainsString('Commerce', $result->summary);
    }

    public function testMalformedIssueDataStillProducesAFindingRatherThanABrokenCheck(): void
    {
        // Craft populates this structure, so it is another package's data. A shape this check
        // did not expect must not turn a real finding into an error.
        $result = $this->integrity([
            'compatible' => false,
            'issues' => [['unexpected' => 'shape'], 'not an array', ['cause' => ['nested']]],
        ])->run(new DiagnosticContext());

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertNotSame('', $result->summary);
    }

    public function testAnInstallationWithNoProjectConfigFilesIsNotAsked(): void
    {
        // With no files there is nothing whose schema versions could disagree.
        self::assertFalse($this->integrity(['compatible' => true, 'issues' => []], externalConfigExists: false)
            ->isApplicable(new DiagnosticContext()));
    }

    public function testAProjectConfigThatCannotBeReadIsStillAskedAndReportsNotKnowing(): void
    {
        // Distinct from having no files: this is a question the check exists to answer, so it
        // runs and says it could not, rather than being recorded as inapplicable.
        $diagnostic = $this->integrity(new RuntimeException('Corrupt YAML'));

        self::assertTrue($diagnostic->isApplicable(new DiagnosticContext()));
        self::assertSame(DiagnosticStatus::UNKNOWN, $diagnostic->run(new DiagnosticContext())->status);
    }

    // --- The directories Craft writes to.

    /**
     * @param array<string, string> $states
     */
    private function storage(array $states = [], bool $pathsThrow = false): DiagnosticResult
    {
        $diagnostic = new class(['states' => $states, 'pathsThrow' => $pathsThrow]) extends StoragePathsDiagnostic {
            /** @var array<string, string> */
            public array $states = [];

            public bool $pathsThrow = false;

            protected function paths(): array
            {
                if ($this->pathsThrow) {
                    throw new RuntimeException('No storage path configured');
                }

                return [
                    'storage' => '/site/storage',
                    'runtime' => '/site/storage/runtime',
                    'compiledTemplates' => '/site/storage/runtime/compiled_templates',
                    'logs' => '/site/storage/logs',
                ];
            }

            protected function stateOf(string $path): string
            {
                return $this->states[$path] ?? 'writable';
            }

            protected function processUser(): string
            {
                return 'www-data';
            }
        };

        return $diagnostic->run(new DiagnosticContext());
    }

    public function testWritableDirectoriesPass(): void
    {
        $result = $this->storage();

        self::assertSame(DiagnosticStatus::PASS, $result->status);
        self::assertSame(
            ['storage' => 'writable', 'runtime' => 'writable', 'compiledTemplates' => 'writable', 'logs' => 'writable'],
            $result->evidence()[0]->get('states'),
        );
    }

    public function testADirectoryCraftCannotWriteToIsAFailure(): void
    {
        $result = $this->storage(['/site/storage/runtime' => 'notWritable']);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame(Severity::HIGH, $result->severity());
        self::assertStringContainsString('runtime', $result->summary);
    }

    public function testAMissingDirectoryIsOnlyAWarning(): void
    {
        // Craft creates these on demand, so this becomes a failure only when it cannot.
        $result = $this->storage(['/site/storage/logs' => 'missing']);

        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame(Severity::MEDIUM, $result->severity());
    }

    public function testBeingUnableToWriteOutranksSomethingMerelyBeingAbsent(): void
    {
        $result = $this->storage([
            '/site/storage/logs' => 'missing',
            '/site/storage/runtime' => 'notWritable',
        ]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        // Both are still recorded, so the missing directory is not lost to the other finding.
        self::assertSame('missing', $result->evidence()[0]->get('states')['logs']);
    }

    public function testTheRunningUserIsNamedBecauseItIsHalfTheMismatch(): void
    {
        // A deployment that copies files as one user while PHP runs as another is the usual
        // cause, and a finding that did not name the user would leave that unsaid.
        self::assertSame('www-data', $this->storage()->evidence()[0]->get('user'));
    }

    public function testUnreadableStoragePathsAreReportedAsNotKnowing(): void
    {
        $result = $this->storage(pathsThrow: true);

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertTrue($result->hasEvidence());
    }

    public function testAFileWhereADirectoryBelongsIsNotReportedAsMissing(): void
    {
        // The two need different fixes, and calling a file "missing" sends somebody to create
        // a directory that cannot be created there.
        $result = $this->storage(['/site/storage/runtime' => 'notADirectory']);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame('notADirectory', $result->evidence()[0]->get('states')['runtime']);
        self::assertStringContainsString('runtime', $result->summary);
    }

    public function testTheProcessUserIsOnlyNamedWhenThePlatformCanSayWhoItIs(): void
    {
        // `get_current_user()` reports the owner of the running script file rather than the
        // process user, and on exactly the misconfigured deployment this check exists to find,
        // those differ. Naming the wrong user is worse than naming none.
        $diagnostic = new class() extends StoragePathsDiagnostic {
            public function user(): string
            {
                return $this->processUser();
            }
        };

        $user = $diagnostic->user();

        self::assertNotSame('', $user);

        if (!function_exists('posix_geteuid')) {
            self::assertSame('Unknown', $user);
        }
    }

    public function testADirectoryThatCannotBeInspectedIsNotReportedAsMissing(): void
    {
        // `file_exists()` answers false both for "not there" and for "not allowed to look".
        // Reporting the second as the first sends somebody to create a directory that already
        // exists — and quietly downgrades "I could not tell" to a definite finding.
        $result = $this->storage(['/site/storage/logs' => 'unknown']);

        self::assertSame(DiagnosticStatus::UNKNOWN, $result->status);
        self::assertStringContainsString('logs', $result->summary);
        self::assertSame('unknown', $result->evidence()[0]->get('states')['logs']);
    }

    public function testADefiniteFailureOutranksADirectoryThatCouldNotBeInspected(): void
    {
        $result = $this->storage([
            '/site/storage/logs' => 'unknown',
            '/site/storage/runtime' => 'notWritable',
        ]);

        self::assertSame(DiagnosticStatus::FAIL, $result->status);
    }

    /**
     * @return array<string, array{callable(string): string, string}>
     */
    public static function realPaths(): array
    {
        return [
            'an existing writable directory' => [static fn(string $base): string => $base, 'writable'],
            'a path that is not there' => [static fn(string $base): string => $base . '/not-here', 'missing'],
            'a file where a directory belongs' => [static fn(string $base): string => $base . '/occupied', 'notADirectory'],
            // `chmod -R 644 storage`: the parent can be read but not entered, so whether the
            // directory inside it exists cannot be told — which is not the same as it being absent.
            'a directory inside one that cannot be entered' => [static function(string $base): string {
                mkdir($base . '/locked/runtime', 0o755, true);
                chmod($base . '/locked', 0o644);

                return $base . '/locked/runtime';
            }, 'unknown'],
        ];
    }

    /**
     * @param callable(string): string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('realPaths')]
    public function testTheRealImplementationClassifiesRealPathsCorrectly(callable $path, string $expected): void
    {
        // The tests above state the states; this one exercises the actual `file_exists()`,
        // `is_dir()` and `is_writable()` calls, because a seam that is never checked against
        // the real thing is a seam that can quietly disagree with it.
        $base = sys_get_temp_dir() . '/web-doctor-storage-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($base, 0o755, true));
        self::assertNotFalse(file_put_contents($base . '/occupied', 'x'));

        $diagnostic = new class() extends StoragePathsDiagnostic {
            public function state(string $path): string
            {
                return $this->stateOf($path);
            }
        };

        try {
            self::assertSame($expected, $diagnostic->state($path($base)));
        } finally {
            @chmod($base . '/locked', 0o755);
            @rmdir($base . '/locked/runtime');
            @rmdir($base . '/locked');
            @unlink($base . '/occupied');
            @rmdir($base);
        }
    }
}
