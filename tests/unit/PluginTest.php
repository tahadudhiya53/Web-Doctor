<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\Settings;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\WebDoctor;

/**
 * What Craft reads from the plugin itself: the metadata it installs it by, its settings, and the
 * access model it asks Craft to guard. That Craft actually boots the class as a plugin, and that
 * a given user holds a permission, are proven in the integration suite against a real Craft.
 */
class PluginTest extends TestCase
{
    /** @var string The release version development stays on, in Composer. */
    private const VERSION = '5.0.0';

    /** @var string The schema version development stays on, in the plugin class. */
    private const SCHEMA_VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        /** @var array<string, mixed> $composer */
        $composer = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true);

        return $composer;
    }

    /**
     * @return string[]
     */
    private function allPermissions(): array
    {
        return $this->flatten((new Permissions())->definitions());
    }

    /**
     * Walks the whole tree rather than one level of it: a permission nested under a nested one
     * is still a permission Web Doctor declares, and a helper that stopped short would quietly
     * exempt the deepest ones from every rule these tests check.
     *
     * @param array<string, mixed> $definitions
     * @return list<string>
     */
    private function flatten(array $definitions): array
    {
        $permissions = [];

        foreach ($definitions as $permission => $definition) {
            $permissions[] = $permission;
            $nested = is_array($definition) ? ($definition['nested'] ?? []) : [];

            if (is_array($nested)) {
                $permissions = [...$permissions, ...$this->flatten($nested)];
            }
        }

        return $permissions;
    }

    public function testComposerMetadataMatchesPluginClass(): void
    {
        $composer = $this->composer();

        self::assertTrue(class_exists(WebDoctor::class));
        self::assertSame('craft-plugin', $composer['type']);
        self::assertSame('tahadudhiya53/craft-web-doctor', $composer['name']);
        self::assertSame(WebDoctor::class, $composer['extra']['class']);
        self::assertSame('web-doctor', $composer['extra']['handle']);
        self::assertSame('Web Doctor', $composer['extra']['name']);
        self::assertSame('src/', $composer['autoload']['psr-4']['Tahadudhiya\\WebDoctor\\']);
        self::assertSame('tests/', $composer['autoload-dev']['psr-4']['Tahadudhiya\\WebDoctor\\Tests\\']);
    }

    public function testCraftAndPhpRequirementsAreDeclared(): void
    {
        $composer = $this->composer();

        self::assertSame('^5.0.0', $composer['require']['craftcms/cms']);
        self::assertSame('>=8.2.0', $composer['require']['php']);
    }

    public function testTheVersionIsTheOneDevelopmentStaysOn(): void
    {
        // Neither version moves until the plugin is actually released. This is what fails if a
        // round of work bumps one out of habit.
        $schemaVersion = (new ReflectionClass(WebDoctor::class))->getDefaultProperties()['schemaVersion'];

        self::assertSame(self::VERSION, $this->composer()['version']);
        self::assertSame(self::SCHEMA_VERSION, $schemaVersion);
    }

    public function testThereIsOnlyEverOneMigration(): void
    {
        // Schema changes are made by editing the install migration and reinstalling, so a
        // second migration file means the development workflow has been departed from.
        $migrations = glob(__DIR__ . '/../../src/migrations/*.php') ?: [];

        self::assertSame(['Install.php'], array_map('basename', $migrations));
    }

    public function testServiceComponentsAreRegistered(): void
    {
        $classes = array_map(static fn(array $c): string => $c['class'], WebDoctor::config()['components']);
        // The local test lab's component is not part of the plugin.
        unset($classes['testLab']);
        ksort($classes);

        self::assertSame([
            'audit' => \Tahadudhiya\WebDoctor\services\Audit::class,
            'diagnosticEngine' => \Tahadudhiya\WebDoctor\services\DiagnosticEngine::class,
            'diagnostics' => \Tahadudhiya\WebDoctor\services\Diagnostics::class,
            'errors' => \Tahadudhiya\WebDoctor\services\Errors::class,
            'evidence' => \Tahadudhiya\WebDoctor\services\EvidenceStore::class,
            'history' => \Tahadudhiya\WebDoctor\services\History::class,
            'investigations' => \Tahadudhiya\WebDoctor\services\Investigations::class,
            'issues' => \Tahadudhiya\WebDoctor\services\Issues::class,
            'permissions' => Permissions::class,
            'recipes' => \Tahadudhiya\WebDoctor\services\Recipes::class,
            'recommendations' => \Tahadudhiya\WebDoctor\services\Recommendations::class,
            'repairActions' => \Tahadudhiya\WebDoctor\services\RepairActions::class,
            'repairs' => \Tahadudhiya\WebDoctor\services\Repairs::class,
            'rootCauses' => \Tahadudhiya\WebDoctor\services\RootCauses::class,
            'runs' => \Tahadudhiya\WebDoctor\services\Runs::class,
            'verificationActions' => \Tahadudhiya\WebDoctor\services\VerificationActions::class,
            'verifications' => \Tahadudhiya\WebDoctor\services\Verifications::class,
        ], $classes);
    }

    public function testPluginHasAControlPanelSectionAndSettings(): void
    {
        $defaults = (new ReflectionClass(WebDoctor::class))->getDefaultProperties();

        self::assertTrue($defaults['hasCpSection']);
        self::assertTrue($defaults['hasCpSettings']);
    }

    public function testBothControlPanelIconsExist(): void
    {
        // Craft reads them from different places: the plugin's icon from the package, the nav
        // icon from a file it looks for by name.
        self::assertFileExists(__DIR__ . '/../../src/icon.svg');
        self::assertFileExists(__DIR__ . '/../../src/icon-mask.svg');
    }

    public function testTheNavIconIsDrawnWithFillsAlone(): void
    {
        // Craft recolours the nav icon with `fill: currentColor; stroke-width: 0`, so anything
        // drawn with a stroke is erased rather than recoloured, and the icon disappears.
        self::assertStringNotContainsString('stroke', (string)file_get_contents(__DIR__ . '/../../src/icon-mask.svg'));
    }

    public function testSettingsAreUsableWithoutConfigurationAndReadFromIt(): void
    {
        self::assertSame('Web Doctor', (new Settings())->pluginName);
        self::assertTrue((new Settings())->validate());

        $configured = new Settings(['pluginName' => 'Site Health']);

        self::assertTrue($configured->validate());
        self::assertSame('Site Health', $configured->pluginName);
    }

    public function testAnUnusablePluginNameIsRefusedRatherThanLeavingTheSectionUnlabelled(): void
    {
        $empty = new Settings(['pluginName' => '']);

        self::assertFalse($empty->validate());
        self::assertArrayHasKey('pluginName', $empty->getErrors());
        self::assertFalse((new Settings(['pluginName' => str_repeat('a', 256)]))->validate());
    }

    public function testSettingsCarryNothingSecret(): void
    {
        // Web Doctor reports whether a credential is present, never what it is. Nothing in its
        // own configuration may become a place to keep one — so the list is pinned, and each
        // name is put past the same test that decides what gets redacted everywhere else.
        $attributes = (new Settings())->attributes();

        self::assertContains('pluginName', $attributes);

        // Each name is put past the same test that decides what gets redacted everywhere else,
        // so a setting that looks like somewhere to keep a credential fails here.
        foreach ($attributes as $attribute) {
            self::assertFalse(Redaction::isSensitiveKey($attribute), $attribute);
        }
    }

    public function testEveryPermissionIsNamespacedToWebDoctor(): void
    {
        foreach ($this->allPermissions() as $permission) {
            self::assertStringStartsWith('webDoctor:', $permission);
        }
    }

    /**
     * The whole permission tree, exactly. Permissions arrive with the features they guard, so
     * declaring one early would offer an administrator a switch that changes nothing.
     *
     * Each is separate because the costs differ. Reading what a run concluded costs nothing;
     * starting one spends the site's time. An issue carries decisions not everybody's to make, its
     * evidence carries internals not everybody following it needs to see, and investigating runs
     * checks on demand. A repair is the one thing that changes the installation, so it is a leaf
     * granted by nothing else. The audit trail says what people did, a different thing to show
     * somebody from what was found, and every entry is about something in the Issue Center.
     */
    public function testThePermissionTreeIsExactlyThis(): void
    {
        $definitions = (new Permissions())->definitions();
        $shape = function(array $definitions) use (&$shape): array {
            return array_map(static fn(array $d): array => $shape($d['nested'] ?? []), $definitions);
        };

        self::assertSame([
            Permissions::VIEW => [
                Permissions::RUN => [],
                Permissions::VIEW_ISSUES => [
                    Permissions::MANAGE_ISSUES => [],
                    Permissions::VIEW_EVIDENCE => [],
                    Permissions::INVESTIGATE_ISSUES => [],
                    Permissions::RUN_REPAIRS => [],
                    Permissions::VIEW_AUDIT_TRAIL => [],
                ],
            ],
        ], $shape($definitions));

        self::assertNotSame('', $definitions[Permissions::VIEW]['label']);
        // Running a repair also needs Craft's own permission for the same act, and says so.
        self::assertStringContainsString('Craft requires', $definitions[Permissions::VIEW]['nested'][Permissions::VIEW_ISSUES]['nested'][Permissions::RUN_REPAIRS]['info'] ?? '');
    }
}
