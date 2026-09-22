<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\models\Settings;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\WebDoctor;

/**
 * Covers what Craft reads from the plugin itself: autoloading, the metadata Craft installs it
 * by, and the components it registers. That Craft actually boots the class as a plugin is
 * proven in the integration suite, against a real Craft application.
 */
class WebDoctorTest extends TestCase
{
    /** @var string The version development stays on, in both Composer and the plugin class. */
    private const VERSION = '1.0.0';

    public function testPluginClassAutoloads(): void
    {
        self::assertTrue(class_exists(WebDoctor::class));
    }

    public function testComposerMetadataMatchesPluginClass(): void
    {
        $composer = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertSame('craft-plugin', $composer['type']);
        self::assertSame('tahadudhiya53/craft-web-doctor', $composer['name']);
        self::assertSame(WebDoctor::class, $composer['extra']['class']);
        self::assertSame('web-doctor', $composer['extra']['handle']);
        self::assertSame('Web Doctor', $composer['extra']['name']);
    }

    public function testComposerAutoloadsBothTheSourceAndTestNamespaces(): void
    {
        $composer = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertSame('src/', $composer['autoload']['psr-4']['Tahadudhiya\\WebDoctor\\']);
        self::assertSame('tests/', $composer['autoload-dev']['psr-4']['Tahadudhiya\\WebDoctor\\Tests\\']);
    }

    public function testCraftAndPhpRequirementsAreDeclared(): void
    {
        $composer = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertSame('^5.0.0', $composer['require']['craftcms/cms']);
        self::assertSame('>=8.2.0', $composer['require']['php']);
    }

    public function testTheVersionIsTheOneDevelopmentStaysOn(): void
    {
        // Development phases are not releases, so neither version moves until the plugin is
        // actually released. This is what fails if a phase bumps one out of habit.
        $composer = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true);
        $schemaVersion = (new \ReflectionClass(WebDoctor::class))
            ->getDefaultProperties()['schemaVersion'];

        self::assertSame(self::VERSION, $composer['version']);
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
        $defaults = (new \ReflectionClass(WebDoctor::class))->getDefaultProperties();

        self::assertTrue($defaults['hasCpSection']);
        self::assertTrue($defaults['hasCpSettings']);
    }

    public function testSettingsModelIsCreated(): void
    {
        $plugin = (new \ReflectionClass(WebDoctor::class))->newInstanceWithoutConstructor();
        $settings = (new \ReflectionClass(WebDoctor::class))
            ->getMethod('createSettingsModel')
            ->invoke($plugin);

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
        $mask = (string)file_get_contents(__DIR__ . '/../../src/icon-mask.svg');

        self::assertStringNotContainsString('stroke', $mask);
    }
}
