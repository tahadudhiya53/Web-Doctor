<?php

namespace Tahadudhiya\WebDoctor\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\console\ExitCode;

/**
 * Web Doctor's commands. Reached as `php craft webdoctor/<command>`.
 */
class WebDoctorController extends Controller
{
    /**
     * Reports whether Web Doctor is loaded and configured, so an installation can be confirmed
     * from the command line.
     */
    public function actionStatus(): int
    {
        $plugin = WebDoctor::getInstance();

        if ($plugin === null) {
            $this->stderr("Web Doctor is not installed.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $settings = $plugin->getSettings();
        $settingsValid = $settings->validate();

        $this->stdout("Web Doctor\n", Console::FG_YELLOW);
        $this->stdout(sprintf("  version:        %s\n", $plugin->getVersion()));
        $this->stdout(sprintf("  schema version: %s\n", $plugin->schemaVersion));
        $this->stdout(sprintf("  settings:       %s\n", $settingsValid ? 'valid' : 'invalid'));

        if (!$settingsValid) {
            foreach ($settings->getErrorSummary(true) as $error) {
                $this->stderr("  - $error\n", Console::FG_RED);
            }

            return ExitCode::CONFIG;
        }

        return ExitCode::OK;
    }
}
