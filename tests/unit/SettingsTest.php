<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\models\Settings;

/**
 * Web Doctor's configuration: what it is without being told anything, and what it refuses.
 */
class SettingsTest extends TestCase
{
    public function testDefaultsAreUsableWithoutConfiguration(): void
    {
        $settings = new Settings();

        self::assertSame('Web Doctor', $settings->pluginName);
        self::assertTrue($settings->validate());
    }

    public function testPluginNameIsReadFromConfiguration(): void
    {
        $settings = new Settings(['pluginName' => 'Site Health']);

        self::assertTrue($settings->validate());
        self::assertSame('Site Health', $settings->pluginName);
    }

    public function testAnEmptyPluginNameIsRefusedRatherThanLeavingTheSectionUnlabelled(): void
    {
        $settings = new Settings(['pluginName' => '']);

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('pluginName', $settings->getErrors());
    }

    public function testAnOverlongPluginNameIsRefused(): void
    {
        $settings = new Settings(['pluginName' => str_repeat('a', 256)]);

        self::assertFalse($settings->validate());
    }

    public function testSettingsCarryNothingSecret(): void
    {
        // Web Doctor reports whether a credential is present, never what it is. Nothing in its
        // own configuration may become a place to keep one.
        self::assertSame(['pluginName'], (new Settings())->attributes());
    }
}
