<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\RequestInput;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Previewing a repair, confirming it, and reading what it did.
 *
 * Reading needs what reading an issue needs. Previewing and confirming each need Web Doctor's own
 * permission to run repairs *and* whatever Craft itself requires for the same action, and each is a
 * CSRF-protected POST. Those permissions are checked here as defence in depth; the repair service
 * checks them again itself, against Craft's signed-in user, along with everything else — the issue's
 * state, the environment, the prerequisites, the confirmation, whether the preview still holds —
 * and its refusals are shown to the person who asked.
 */
class RepairsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it, and a
        // repair is not something a front-end request may set going.
        $this->requireCpRequest();
        $this->requirePermission(Permissions::VIEW_ISSUES);

        return true;
    }

    /**
     * Reads what a repair would do, live, and shows it for confirmation. Changes nothing in the
     * installation.
     */
    public function actionPrepare(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::RUN_REPAIRS);

        // Read before anything else: a malformed request is refused, never read as another one.
        $issueId = RequestInput::id($this->request->getRequiredBodyParam('issueId'));
        $actionId = $this->request->getRequiredBodyParam('repairAction');

        if (!is_string($actionId) || $actionId === '') {
            throw new BadRequestHttpException(Craft::t('web-doctor', 'That is not a repair.'));
        }

        $this->requireCraftAuthorization($actionId);

        try {
            $repair = $this->plugin()->getRepairs()->prepare($issueId, $actionId);
        } catch (Refusal $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirectToPostedUrl();
        } catch (Throwable $e) {
            SafeException::log('A repair could not be previewed', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The repair could not be previewed. Nothing was changed. The details are in Craft’s logs.'));

            return $this->redirectToPostedUrl();
        }

        return $this->redirect(UrlHelper::cpUrl(sprintf('web-doctor/issues/%d/repairs/%d', $issueId, $repair->id)));
    }

    /**
     * Carries out a previewed repair that has been confirmed.
     */
    public function actionExecute(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::RUN_REPAIRS);

        $issueId = RequestInput::id($this->request->getRequiredBodyParam('issueId'));
        $repairId = RequestInput::id($this->request->getRequiredBodyParam('repairId'));
        $confirmed = RequestInput::flag($this->request->getBodyParam('confirm'));
        $acknowledged = RequestInput::names($this->request->getBodyParam('acknowledged'));
        $typed = $this->request->getBodyParam('typedConfirmation');

        if ($typed !== null && !is_string($typed)) {
            throw new BadRequestHttpException(Craft::t('web-doctor', 'That is not a valid choice.'));
        }

        $plugin = $this->plugin();
        $back = UrlHelper::cpUrl(sprintf('web-doctor/issues/%d/repairs/%d', $issueId, $repairId));

        try {
            $stored = $plugin->getRepairs()->get($repairId);
        } catch (Throwable $e) {
            SafeException::log('A repair could not be read', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The repair could not be carried out. The details are in Craft’s logs.'));

            return $this->redirect($back);
        }

        // Asked of the action the preview was made with, so the Craft permission checked is the one
        // for what would actually be carried out.
        if ($stored !== null) {
            $this->requireCraftAuthorization($stored->action);
        }

        try {
            $repair = $plugin->getRepairs()->execute($repairId, $issueId, $confirmed, $acknowledged, $typed);
        } catch (Refusal $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect($back);
        } catch (Throwable $e) {
            SafeException::log('A repair could not be carried out', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The repair could not be carried out. The details are in Craft’s logs.'));

            return $this->redirect($back);
        }

        if ($repair->status === RepairStatus::RUNNING) {
            $this->setFailFlash(Craft::t('web-doctor', 'The repair ran, but how it ended could not be recorded. It will be read as stopped within the hour and the issue put back; run the checks listed to see where things stand.'));
        } elseif ($repair->failure !== null) {
            $this->setFailFlash(Craft::t('web-doctor', 'The repair failed part-way. See below for what is known.'));
        } else {
            $this->setSuccessFlash(Craft::t('web-doctor', 'Repair carried out. It is not yet verified: run the checks listed to see whether the problem is gone.'));
        }

        return $this->redirect($back);
    }

    /**
     * One repair: what it would do or did, what had to be true first, and whether it worked.
     *
     * @throws NotFoundHttpException if there is no such repair of that issue.
     */
    public function actionDetail(int $issueId, int $repairId): Response
    {
        $plugin = $this->plugin();

        try {
            $issue = $plugin->getIssues()->get($issueId);
            $repair = $plugin->getRepairs()->get($repairId);
        } catch (Throwable $e) {
            SafeException::log('A repair could not be read', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That repair could not be read.'));
        }

        // Reached through the issue it belongs to, never through another one's URL.
        if ($issue === null || $repair === null || $repair->issueId !== $issue->id) {
            throw new NotFoundHttpException(Craft::t('web-doctor', 'No such repair.'));
        }

        $action = $plugin->getRepairActions()->get($repair->action);
        $canRun = $plugin->getPermissions()->canRunRepairs();
        $refusal = null;

        try {
            $refusal = $plugin->getRepairs()->refusal($issue);
        } catch (Throwable $e) {
            SafeException::log('Whether an issue can be repaired could not be established', $e);
            $refusal = Craft::t('web-doctor', 'Web Doctor could not establish whether this issue can be repaired. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_repairs/_detail', [
            'title' => Craft::t('web-doctor', 'Repair'),
            'issue' => $issue,
            'repair' => $repair,
            'refusal' => $refusal,
            'canRunRepairs' => $canRun,
            // Somebody may confirm only what they may carry out, by Web Doctor's rules and Craft's.
            'authorized' => $canRun && $action !== null && $action->isAuthorized(),
            'authorization' => $action === null ? null : Redaction::redactString($action->authorization()),
            'actionAvailable' => $action !== null,
            // What a repair read and changed is the installation's internals, as evidence is. Whoever
            // may carry one out has to see what they are confirming.
            'canViewDetails' => $canRun || $plugin->getPermissions()->canViewEvidence(),
        ]);
    }

    /**
     * Refuses somebody Craft would not let do the same thing by its own means. An action that is not
     * registered is left to the service, which says so in words.
     *
     * @throws ForbiddenHttpException
     */
    private function requireCraftAuthorization(string $actionId): void
    {
        $action = $this->plugin()->getRepairActions()->get($actionId);

        if ($action !== null && !$action->isAuthorized()) {
            throw new ForbiddenHttpException($action->authorization() !== '' ? Redaction::redactString($action->authorization()) : Craft::t('web-doctor', 'Craft does not allow you to do this.'));
        }
    }

    private function plugin(): WebDoctor
    {
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $plugin;
    }
}
