<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\models\RunFilter;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Diagnostic history: the runs set going from the dashboard, each as it finished, with the health
 * snapshot of every run that covered every check.
 *
 * What an earlier run found is what the dashboard showed then, so reading it needs what reading the
 * dashboard needs. Nothing here runs or changes anything.
 */
class HistoryController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it.
        $this->requireCpRequest();
        $this->requirePermission(Permissions::VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        try {
            $filter = RunFilter::fromParams($this->request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            // The filter names only the parameter it refused, never a value.
            throw new BadRequestHttpException(Craft::t('web-doctor', 'The diagnostic history cannot filter by that “{name}”.', ['name' => $e->getMessage()]));
        }

        $failure = null;
        $runs = null;
        $environments = [];

        try {
            $runs = $plugin->getHistory()->find($filter);
            $environments = $plugin->getHistory()->knownEnvironments();
        } catch (Throwable $e) {
            SafeException::log('The diagnostic history could not be read', $e);
            $failure = Craft::t('web-doctor', 'Web Doctor could not read the diagnostic history. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_history/_index', [
            'title' => Craft::t('web-doctor', 'History'),
            'failure' => $failure,
            'runs' => $runs,
            'filter' => $filter,
            'environments' => $environments,
            'sites' => SiteName::options(),
            'retainDays' => $plugin->getHistory()->retainDays,
            'statusLabels' => array_combine(DiagnosticStatus::values(), array_map(static fn(DiagnosticStatus $s): string => $s->label(), DiagnosticStatus::cases())),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $runId): Response
    {
        try {
            $run = $this->plugin()->getHistory()->get($runId);
        } catch (Throwable $e) {
            SafeException::log('A diagnostic run could not be read from the history', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That run could not be read.'));
        }

        if ($run === null) {
            throw new NotFoundHttpException(Craft::t('web-doctor', 'No such run.'));
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_history/_detail', [
            'title' => Craft::t('web-doctor', 'History'),
            'run' => $run,
            'statuses' => DiagnosticStatus::cases(),
            'severities' => Severity::cases(),
        ]);
    }

    private function plugin(): WebDoctor
    {
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $plugin;
    }
}
