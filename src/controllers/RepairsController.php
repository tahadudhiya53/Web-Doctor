<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\RequestInput;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\RepairFilter;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\models\Verification;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\Verifications;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Previewing a repair, confirming it, verifying it, and reading what it did.
 *
 * Reading needs what reading an issue needs. Previewing and confirming each need Web Doctor's own
 * permission to run repairs *and* whatever Craft itself requires for the same action, and each is a
 * CSRF-protected POST. Those permissions are checked here as defence in depth; the repair service
 * checks them again itself, against Craft's signed-in user, along with everything else — the issue's
 * state, the environment, the prerequisites, the confirmation, whether the preview still holds —
 * and its refusals are shown to the person who asked.
 *
 * A repair carried out cleanly is verified straight away, in the same request, and can be verified
 * again from its page — a retried job, say, can only be told to have worked once it has run.
 * Verifying needs what carrying a repair out needs from Web Doctor, and nothing from Craft: it only
 * runs checks, which change nothing.
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
        // The section's own permission as well as this one's: Craft nests them only in its own
        // screens, and a permission set written another way can hold a child without its parent.
        $this->requirePermission(Permissions::VIEW);
        $this->requirePermission(Permissions::VIEW_ISSUES);

        return true;
    }

    /**
     * The repair history: every repair kept, previewed or carried out, filtered as the query string
     * asks. Changes nothing.
     */
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        try {
            $filter = RepairFilter::fromParams($this->request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            // The filter names only the parameter it refused, never a value.
            throw new BadRequestHttpException(Craft::t('web-doctor', 'The repair history cannot filter by that “{name}”.', ['name' => $e->getMessage()]));
        }

        $failure = null;
        $repairs = null;
        $actions = [];
        $users = [];
        $environments = [];

        try {
            $repairs = $plugin->getRepairs()->find($filter);
            $actions = $plugin->getRepairs()->knownActions();
            $users = $plugin->getRepairs()->knownUsers();
            $environments = $plugin->getRepairs()->knownEnvironments();
        } catch (Throwable $e) {
            SafeException::log('The repair history could not be read', $e);
            $failure = Craft::t('web-doctor', 'Web Doctor could not read the repair history. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_repairs/_index', [
            'title' => Craft::t('web-doctor', 'Repairs'),
            'failure' => $failure,
            'repairs' => $repairs,
            'filter' => $filter,
            'actions' => $actions,
            'users' => $users,
            'environments' => $environments,
            'sites' => SiteName::options(),
            'statuses' => RepairStatus::cases(),
            'verificationStatuses' => VerificationStatus::cases(),
        ]);
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

        // In the shape every registered repair's ID has, or refused: anything else names no repair,
        // and is never written to the log or echoed back as though it might.
        if (!is_string($actionId) || !Diagnostics::isValidId($actionId)) {
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
        // for what would actually be carried out. Only of this issue's repair: anything else is the
        // service's to refuse, without naming what another repair would have needed.
        if ($stored !== null && $stored->issueId === $issueId) {
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
            $this->flashVerification($this->verifyNow($repair, $issueId), true);
        }

        return $this->redirect($back . '#verification');
    }

    /**
     * Verifies a carried-out repair again: runs its checks and records what they find. Changes
     * nothing in the installation.
     */
    public function actionVerify(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::RUN_REPAIRS);

        $issueId = RequestInput::id($this->request->getRequiredBodyParam('issueId'));
        $repairId = RequestInput::id($this->request->getRequiredBodyParam('repairId'));
        $back = UrlHelper::cpUrl(sprintf('web-doctor/issues/%d/repairs/%d#verification', $issueId, $repairId));

        try {
            $verification = $this->plugin()->getVerifications()->verify($repairId, $issueId);
        } catch (Refusal $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect($back);
        } catch (Throwable $e) {
            SafeException::log('A repair could not be verified', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The repair could not be verified. The details are in Craft’s logs.'));

            return $this->redirect($back);
        }

        $this->flashVerification($verification, false);

        return $this->redirect($back);
    }

    /**
     * Verifies a repair just carried out. Whatever stops it, the repair stands as carried out and
     * awaiting verification, which the page says; the reason goes with it.
     */
    private function verifyNow(Repair $repair, int $issueId): Verification|string
    {
        try {
            return $this->plugin()->getVerifications()->verify($repair->id, $issueId);
        } catch (Refusal $e) {
            return $e->getMessage();
        } catch (Throwable $e) {
            SafeException::log('A repair just carried out could not be verified', $e);

            return Craft::t('web-doctor', 'The details are in Craft’s logs.');
        }
    }

    /**
     * Says what a verification concluded. "Carried out" leads only where the repair was carried
     * out in this request.
     */
    private function flashVerification(Verification|string $verification, bool $justCarriedOut): void
    {
        if (is_string($verification)) {
            $this->setSuccessFlash(Craft::t('web-doctor', 'Repair carried out. It is not yet verified: it could not be verified now. {reason}', ['reason' => $verification]));

            return;
        }

        match ($verification->result) {
            VerificationStatus::VERIFIED => $this->setSuccessFlash($justCarriedOut
                ? Craft::t('web-doctor', 'Repair carried out and verified: the check that found the problem ran again and no longer reports it.')
                : Craft::t('web-doctor', 'Verified: the check that found the problem ran again and no longer reports it.')),
            VerificationStatus::FAILED => $this->setFailFlash(Craft::t('web-doctor', 'Repair completed, but verification failed: the check that found the problem still reports it. The issue stays open.')),
            default => $this->setSuccessFlash($justCarriedOut
                ? Craft::t('web-doctor', 'Repair carried out. It is not yet verified: verification was inconclusive. The reasons are below; verify again once they have changed.')
                : Craft::t('web-doctor', 'Verification was inconclusive. The reasons are below; verify again once they have changed.')),
        };
    }

    /**
     * One repair: what it would do or did, what had to be true first, and whether it worked.
     *
     * Reached through the issue it belongs to, or on its own from the repair history — a repair
     * outlives its issue, and one whose issue was deleted is still a record of what was done.
     *
     * @param int|null $issueId The issue in the URL, which has to be the repair's own.
     * @throws NotFoundHttpException if there is no such repair, or not of that issue.
     */
    public function actionDetail(int $repairId, ?int $issueId = null): Response
    {
        $plugin = $this->plugin();

        try {
            $repair = $plugin->getRepairs()->get($repairId);
            $issue = $repair?->issueId === null ? null : $plugin->getIssues()->get($repair->issueId);
        } catch (Throwable $e) {
            SafeException::log('A repair could not be read', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That repair could not be read.'));
        }

        // Never through another issue's URL: a link can only show what it says it shows.
        if ($repair === null || ($issueId !== null && ($issue === null || $repair->issueId !== $issueId))) {
            throw new NotFoundHttpException(Craft::t('web-doctor', 'No such repair.'));
        }

        $action = $plugin->getRepairActions()->get($repair->action);
        $canRun = $plugin->getPermissions()->canRunRepairs();
        $refusal = null;

        try {
            $refusal = $issue === null
                ? Craft::t('web-doctor', 'The issue this repair was for has been deleted, so it can no longer be carried out or verified.')
                : $plugin->getRepairs()->refusal($issue);
        } catch (Throwable $e) {
            SafeException::log('Whether an issue can be repaired could not be established', $e);
            $refusal = Craft::t('web-doctor', 'Web Doctor could not establish whether this issue can be repaired. The details are in Craft’s logs.');
        }

        $verifications = [];
        $verificationsFailure = null;
        $verifyRefusal = $issue === null ? $refusal : null;

        try {
            $verifications = $plugin->getVerifications()->forRepair($repair->id);

            if ($issue !== null) {
                $verifyRefusal = $plugin->getVerifications()->refusal($repair, $issue);
            }
        } catch (Throwable $e) {
            SafeException::log('A repair\'s verifications could not be read', $e);
            $verificationsFailure = Craft::t('web-doctor', 'This repair’s verifications could not be read. The details are in Craft’s logs.');
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
            'verifications' => $verifications,
            'verificationsFailure' => $verificationsFailure,
            'verifyRefusal' => $verifyRefusal,
            'verificationLimit' => Verifications::HISTORY_LIMIT,
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
