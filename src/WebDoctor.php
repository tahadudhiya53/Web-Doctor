<?php

namespace Tahadudhiya\WebDoctor;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use Tahadudhiya\WebDoctor\console\controllers\WebDoctorController;
use Tahadudhiya\WebDoctor\models\Settings;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\Runs;
use yii\base\Event;

/**
 * Web Doctor — diagnostics and maintenance for Craft CMS.
 *
 * @property-read DiagnosticEngine $diagnosticEngine
 * @property-read Diagnostics $diagnostics
 * @property-read Permissions $permissions
 * @property-read Runs $runs
 * @property-read Settings $settings
 */
class WebDoctor extends Plugin
{
    /** @var string The category Web Doctor logs and translates under. */
    public const LOG_CATEGORY = 'web-doctor';

    /**
     * @var string The console controller ID Web Doctor's commands answer to, so commands read
     * `php craft webdoctor/<command>` rather than following the hyphenated plugin handle.
     */
    public const CONSOLE_ID = 'webdoctor';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'diagnostics' => [
                    'class' => Diagnostics::class,
                    // The registry the plugin hands out is the one that holds Web Doctor's own
                    // checks. A registry built directly stays empty, so a caller running a set
                    // of its own gets exactly that set.
                    'includeCoreDiagnostics' => true,
                ],
                'diagnosticEngine' => ['class' => DiagnosticEngine::class],
                'permissions' => ['class' => Permissions::class],
                'runs' => ['class' => Runs::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // All three only attach event handlers, so they are registered immediately rather than
        // deferred: nothing here touches the database, the filesystem or the network, and a
        // handler that is attached before Craft finishes booting cannot be missed by whatever
        // fires first.
        $this->registerConsoleCommands();
        $this->registerCpRoutes();
        $this->getPermissions()->register();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null || !$this->getPermissions()->canView()) {
            return null;
        }

        $item['label'] = $this->getSettings()->pluginName;

        return $item;
    }

    /**
     * Every diagnostic Web Doctor knows about, its own and any a plugin has contributed.
     */
    public function getDiagnostics(): Diagnostics
    {
        return $this->get('diagnostics');
    }

    /**
     * Runs diagnostics. Kept apart from the registry so that holding the list of checks and
     * executing them stay separate concerns.
     */
    public function getDiagnosticEngine(): DiagnosticEngine
    {
        /** @var DiagnosticEngine $engine */
        $engine = $this->get('diagnosticEngine');

        // Tied to this plugin instance's registry rather than to whichever instance happens to
        // be the installed one.
        $engine->registry ??= $this->getDiagnostics();

        return $engine;
    }

    public function getPermissions(): Permissions
    {
        return $this->get('permissions');
    }

    /**
     * Where a finished run is kept, so the control panel can show what was concluded without
     * concluding it again.
     */
    public function getRuns(): Runs
    {
        return $this->get('runs');
    }

    /**
     * @return Settings
     */
    public function getSettings(): ?Model
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('web-doctor/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['web-doctor'] = 'web-doctor/overview/index';
        });
    }

    /**
     * Craft reaches a plugin's commands through its handle. Web Doctor's commands are grouped
     * under one controller so they read as `webdoctor/<command>`.
     */
    private function registerConsoleCommands(): void
    {
        if (Craft::$app instanceof ConsoleApplication) {
            Craft::$app->controllerMap[self::CONSOLE_ID] = WebDoctorController::class;
        }
    }
}
