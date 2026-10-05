<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\AuditAction;
use Tahadudhiya\WebDoctor\enums\AuditResult;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\models\AuditFilter;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The audit log: what was done with Web Doctor, by whom, to what, where, when and how it ended.
 *
 * Reading it needs "View audit trail", nested under reading issues because every entry is about
 * something there. There is nothing to change: the trail is written by the acts it records, never
 * from here.
 */
class AuditController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it.
        $this->requireCpRequest();
        // The section's own permission as well as this one's: Craft nests them only in its own
        // screens, and a permission set written another way can hold a child without its parent.
        $this->requirePermission(Permissions::VIEW);
        $this->requirePermission(Permissions::VIEW_ISSUES);
        $this->requirePermission(Permissions::VIEW_AUDIT_TRAIL);

        return true;
    }

    /**
     * The entries, newest first unless the oldest are asked for, filtered as the query string asks.
     */
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        try {
            $filter = AuditFilter::fromParams($this->request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            // The filter names only the parameter it refused, never a value.
            throw new BadRequestHttpException(Craft::t('web-doctor', 'The audit log cannot filter by that “{name}”.', ['name' => $e->getMessage()]));
        }

        $failure = null;
        $entries = null;
        $users = [];
        $environments = [];

        try {
            $entries = $plugin->getAudit()->find($filter);
            $users = $plugin->getAudit()->knownUsers();
            $environments = $plugin->getAudit()->knownEnvironments();
        } catch (Throwable $e) {
            SafeException::log('The audit log could not be read', $e);
            $failure = Craft::t('web-doctor', 'Web Doctor could not read the audit log. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_audit/_index', [
            'title' => Craft::t('web-doctor', 'Audit log'),
            'failure' => $failure,
            'entries' => $entries,
            'filter' => $filter,
            'actions' => AuditAction::cases(),
            'results' => AuditResult::cases(),
            'users' => $users,
            'environments' => $environments,
            'sites' => SiteName::options(),
            'retainDays' => $plugin->getAudit()->retainDays,
        ]);
    }

    private function plugin(): WebDoctor
    {
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $plugin;
    }
}
