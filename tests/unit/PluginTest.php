<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
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
    /** @var string The version development stays on, in both Composer and the plugin class. */
    private const VERSION = '1.0.0';

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
        $permissions = [];

        foreach ((new Permissions())->definitions() as $permission => $definition) {
            $permissions[] = $permission;
            $permissions = [...$permissions, ...array_keys($definition['nested'] ?? [])];
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
        self::assertSame(self::VERSION, $schemaVersion);
    }

    public function testThereIsOnlyEverOneMigration(): void
    {
        // Schema changes are made by editing the install migration and reinstalling, so a
        // second migration file means the development workflow has been departed from.
        $migrations = glob(__DIR__ . '/../../src/migrations/*.php') ?: [];

        self::assertSame([], array_values(array_diff(array_map('basename', $migrations), ['Install.php'])));
    }

    public function testServiceComponentsAreRegistered(): void
    {
        $components = WebDoctor::config()['components'];

        self::assertSame(Permissions::class, $components['permissions']['class']);
    }

    public function testPluginHasAControlPanelSectionAndSettings(): void
    {
        $defaults = (new ReflectionClass(WebDoctor::class))->getDefaultProperties();

        self::assertTrue($defaults['hasCpSection']);
        self::assertTrue($defaults['hasCpSettings']);
    }

    public function testSettingsModelIsCreated(): void
    {
        $reflection = new ReflectionClass(WebDoctor::class);
        $settings = $reflection->getMethod('createSettingsModel')->invoke($reflection->newInstanceWithoutConstructor());

        self::assertInstanceOf(Settings::class, $settings);
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
        // own configuration may become a place to keep one.
        self::assertSame(['pluginName'], (new Settings())->attributes());
    }

    public function testEveryPermissionIsNamespacedToWebDoctor(): void
    {
        foreach ($this->allPermissions() as $permission) {
            self::assertStringStartsWith('webDoctor:', $permission);
        }
    }

    public function testRunningDiagnosticsIsGuardedSeparatelyFromViewing(): void
    {
        // Reading what a previous run concluded costs nothing; starting a run spends the site's
        // time on demand. A reader who may do the first must not automatically do the second.
        $definitions = (new Permissions())->definitions();

        self::assertArrayHasKey(Permissions::VIEW, $definitions);
        self::assertNotSame('', $definitions[Permissions::VIEW]['label']);
        self::assertArrayHasKey(Permissions::RUN, $definitions[Permissions::VIEW]['nested'] ?? []);
        self::assertNotSame(Permissions::VIEW, Permissions::RUN);
    }

    public function testOnlyPermissionsWithSomethingBehindThemAreDeclared(): void
    {
        // Permissions arrive with the features they guard. Declaring one early would offer an
        // administrator a switch that changes nothing.
        self::assertSame([Permissions::VIEW, Permissions::RUN], $this->allPermissions());
    }
}
