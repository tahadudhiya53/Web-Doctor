<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The errors diagnostic runs have recorded, grouped.
 *
 * Part of the Issue Center, so reading it needs what reading issues needs. What an error said and
 * where it was thrown are the technical detail "View evidence" guards, so without it a reader sees
 * what kind of error it was, how often and when, which checks ran into it and which issues it is
 * related to — and not its message, its origin or its trace. Nothing here changes anything.
 */
class ErrorsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it.
        $this->requireCpRequest();
        $this->requirePermission(Permissions::VIEW_ISSUES);

        return true;
    }

    /**
     * The groups, most recently seen first, a page at a time.
     */
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $page = $this->request->getQueryParam('page');
        $environment = $this->request->getQueryParam('environment');
        // Only an environment errors have been recorded in, so the query string cannot ask the
        // database for anything else.
        $environments = [];
        $failure = null;
        $groups = null;
        $issues = [];

        try {
            $environments = $plugin->getErrors()->knownEnvironments();
            $environment = is_string($environment) && in_array($environment, $environments, true) ? $environment : null;
            $groups = $plugin->getErrors()->find(is_numeric($page) ? (int)$page : 1, $environment);
            $issues = $plugin->getIssues()->getMany($groups->issueIds());
        } catch (Throwable $e) {
            SafeException::log('The error list could not be read', $e);
            $environment = null;
            $failure = Craft::t('web-doctor', 'Web Doctor could not read the errors it has recorded. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_errors/_index', [
            'title' => Craft::t('web-doctor', 'Errors'),
            'failure' => $failure,
            'groups' => $groups,
            'issues' => $issues,
            'environment' => $environment,
            'environments' => $environments,
            'canViewEvidence' => $plugin->getPermissions()->canViewEvidence(),
        ]);
    }

    /**
     * One error: what it is, every check that ran into it, and the issues it is related to.
     *
     * @throws NotFoundHttpException if there is no such group.
     */
    public function actionDetail(int $groupId): Response
    {
        $plugin = $this->plugin();

        try {
            $group = $plugin->getErrors()->get($groupId);
            $issues = $group === null ? [] : $plugin->getIssues()->getMany($group->issueIds());
        } catch (Throwable $e) {
            SafeException::log('An error group could not be read', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That error could not be read.'));
        }

        if ($group === null) {
            throw new NotFoundHttpException(Craft::t('web-doctor', 'No such error.'));
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_errors/_detail', [
            'title' => Craft::t('web-doctor', 'Errors'),
            'group' => $group,
            'issues' => $issues,
            'siteLabel' => $group->siteLabel(),
            'canViewEvidence' => $plugin->getPermissions()->canViewEvidence(),
        ]);
    }

    private function plugin(): WebDoctor
    {
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $plugin;
    }
}
