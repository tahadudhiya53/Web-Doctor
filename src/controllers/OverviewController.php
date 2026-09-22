<?php

namespace Tahadudhiya\WebDoctor\controllers;

use craft\web\Controller;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\web\Response;

/**
 * Web Doctor's entry point in the control panel. It reports the plugin's own state and reads
 * nothing else.
 */
class OverviewController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Permissions::VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        // Craft hands a plugin controller the plugin as its module, so this is the instance
        // serving the request rather than a lookup that could come back empty.
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $this->renderTemplate('web-doctor/_index', [
            'title' => $plugin->getSettings()->pluginName,
            'version' => $plugin->getVersion(),
            'schemaVersion' => $plugin->schemaVersion,
        ]);
    }
}
