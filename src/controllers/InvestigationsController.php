<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\InvestigationStepType;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\RequestInput;
use Tahadudhiya\WebDoctor\models\ErrorGroup;
use Tahadudhiya\WebDoctor\models\ErrorSignature;
use Tahadudhiya\WebDoctor\models\Investigation;
use Tahadudhiya\WebDoctor\models\InvestigationStep;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Investigating an issue, and reading what an investigation found — of an issue, or of a symptom
 * through a recipe.
 *
 * Reading needs what reading an issue needs. Starting one needs its own permission and a POST,
 * because it runs checks against the installation in this request, as a diagnostic run does.
 */
class InvestigationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it, and
        // an investigation is not something a front-end request may set going.
        $this->requireCpRequest();
        $this->requirePermission(Permissions::VIEW_ISSUES);

        return true;
    }

    /**
     * Investigates an issue, then shows what the investigation found.
     *
     * The service states its own refusals — an issue from another environment, one whose site
     * has gone — and the reader is the person who needs to hear them.
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::INVESTIGATE_ISSUES);

        // Read before anything runs: a malformed request is refused, never read as another one.
        $issueId = RequestInput::id($this->request->getRequiredBodyParam('issueId'));
        $depth = RequestInput::depth($this->request->getBodyParam('depth'));

        try {
            $investigation = $this->plugin()->getInvestigations()->investigate($issueId, $depth, $this->userId());
        } catch (Refusal $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirectToPostedUrl();
        } catch (Throwable $e) {
            // The service keeps an attempt that fails part-way. What reaches here failed before
            // there was anything to keep — the issue could not be read, the record not written.
            SafeException::log('An investigation could not be started', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The investigation could not be started. The details are in Craft’s logs.'));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('web-doctor', 'Investigation finished.'));

        return $this->redirect(UrlHelper::cpUrl(sprintf('web-doctor/issues/%d/investigations/%d', $issueId, $investigation->id)));
    }

    /**
     * One investigation: what it looked at and why, what it found, and in what order.
     *
     * @throws NotFoundHttpException if there is no such investigation of that issue.
     */
    public function actionDetail(int $issueId, int $investigationId): Response
    {
        try {
            $issue = $this->plugin()->getIssues()->get($issueId);
            $investigation = $this->plugin()->getInvestigations()->get($investigationId);
        } catch (Throwable $e) {
            SafeException::log('An investigation could not be read', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That investigation could not be read.'));
        }

        // An investigation is reached through the issue it belongs to, never through another
        // one's URL, so a link can only ever show what it says it shows.
        if ($issue === null || $investigation === null || $investigation->issueId !== $issue->id) {
            throw new NotFoundHttpException(Craft::t('web-doctor', 'No such investigation.'));
        }

        return $this->show($investigation, $issue);
    }

    /**
     * One investigation a recipe ran, reached through the recipe it was run from. It is still
     * shown when the recipe is no longer registered — its plan says what it was.
     *
     * @throws NotFoundHttpException if that recipe ran no such investigation.
     */
    public function actionRecipeDetail(string $recipeId, int $investigationId): Response
    {
        try {
            $investigation = $this->plugin()->getInvestigations()->get($investigationId);
        } catch (Throwable $e) {
            SafeException::log('An investigation could not be read', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That investigation could not be read.'));
        }

        if ($investigation === null || $investigation->recipeId === null || $investigation->recipeId !== $recipeId) {
            throw new NotFoundHttpException(Craft::t('web-doctor', 'No such investigation.'));
        }

        return $this->show($investigation, null);
    }

    /**
     * What an investigation looked at and why, what it found, and in what order.
     *
     * @param Issue|null $issue The issue investigated; null for a recipe's investigation.
     */
    private function show(Investigation $investigation, ?Issue $issue): Response
    {
        $plugin = $this->plugin();

        try {
            $steps = $plugin->getInvestigations()->steps($investigation->id);
        } catch (Throwable $e) {
            SafeException::log('An investigation could not be read', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That investigation could not be read.'));
        }

        $checks = array_values(array_filter($steps, static fn(InvestigationStep $s): bool => $s->type === InvestigationStepType::CHECKED));

        // Read on its own, so errors that cannot be read cost the page its errors rather than
        // costing the reader the investigation.
        $errors = [];
        $errorIssues = [];
        $errorsFailure = null;

        try {
            $errors = $this->errorsSeen($investigation, $checks);
            $errorIssues = $plugin->getIssues()->getMany(array_values(array_unique(array_merge(
                [],
                ...array_map(static fn(array $seen): array => $seen['group']?->issueIds() ?? [], $errors),
            ))));
        } catch (Throwable $e) {
            SafeException::log('An investigation\'s errors could not be read', $e);
            $errorsFailure = Craft::t('web-doctor', 'Web Doctor could not read the errors this investigation ran into. The details are in Craft’s logs.');
        }

        // Read on its own for the same reason. The groups are looked up here rather than taken from
        // the errors above, because a cause may quote an error from evidence the timeline did not
        // have room to keep.
        $causes = [];
        $causeGroups = [];
        $causesFailure = null;

        try {
            $causes = $plugin->getRootCauses()->forInvestigation($investigation->id);
            $causeGroups = $plugin->getErrors()->byFingerprints($this->errorFingerprints($causes));
        } catch (Throwable $e) {
            SafeException::log('An investigation\'s causes could not be read', $e);
            $causesFailure = Craft::t('web-doctor', 'Web Doctor could not read the causes this investigation weighed. The details are in Craft’s logs.');
        }

        $diagnosed = array_values(array_filter($steps, static fn(InvestigationStep $s): bool => $s->type === InvestigationStepType::DIAGNOSED))[0] ?? null;

        // Chosen on their own, so a recommendation that cannot be chosen costs the page its
        // recommendations rather than costing the reader the investigation.
        $recommendations = [];
        $recommendationsFailure = null;

        try {
            $recommendations = $this->plugin()->getRecommendations()->forInvestigation($checks, $causes, $issue, $diagnosed);
        } catch (Throwable $e) {
            SafeException::log('An investigation\'s recommendations could not be chosen', $e);
            $recommendationsFailure = Craft::t('web-doctor', 'Web Doctor could not work out what to recommend for this investigation. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_investigations/_detail', [
            'title' => $issue !== null ? Craft::t('web-doctor', 'Investigation') : $investigation->plan->ruleLabel,
            'issue' => $issue,
            'recipe' => $investigation->recipeId === null ? null : $plugin->getRecipes()->get($investigation->recipeId),
            'investigation' => $investigation,
            'steps' => $steps,
            'checks' => $checks,
            'findings' => array_values(array_filter($checks, static fn(InvestigationStep $s): bool => $s->isFinding() && $s->diagnosticId !== $issue?->diagnosticId)),
            'incomplete' => array_values(array_filter($checks, static fn(InvestigationStep $s): bool => $s->isIncomplete())),
            'relatedIssues' => array_values(array_filter($steps, static fn(InvestigationStep $s): bool => $s->type === InvestigationStepType::RELATED_ISSUE)),
            'startedBy' => $this->userLabel($investigation->startedBy),
            'canViewEvidence' => $plugin->getPermissions()->canViewEvidence(),
            'errors' => $errors,
            'errorIssues' => $errorIssues,
            'errorsFailure' => $errorsFailure,
            'causes' => $causes,
            'causeGroups' => $causeGroups,
            'causesFailure' => $causesFailure,
            'diagnosed' => $diagnosed,
            'recommendations' => $recommendations,
            'recommendationsFailure' => $recommendationsFailure,
        ]);
    }

    /**
     * The error groups the causes' evidence leads to, each once.
     *
     * @param list<RootCause> $causes
     * @return list<string>
     */
    private function errorFingerprints(array $causes): array
    {
        $fingerprints = [];

        foreach ($causes as $cause) {
            foreach ($cause->conditions as $condition) {
                foreach ($condition->observations as $observation) {
                    if ($observation->errorFingerprint !== null) {
                        $fingerprints[$observation->errorFingerprint] = true;
                    }
                }
            }
        }

        return array_keys($fingerprints);
    }

    /**
     * The errors the investigation's checks ran into, recognised from the evidence each step kept
     * the same way they were counted when it ran — so each finds the group its occurrence was
     * counted against, where that group is still kept.
     *
     * @param list<InvestigationStep> $checks
     * @return list<array{signature: ErrorSignature, group: ErrorGroup|null, diagnostics: list<string>}>
     */
    private function errorsSeen(Investigation $investigation, array $checks): array
    {
        $errors = $this->plugin()->getErrors();
        $seen = [];

        foreach ($checks as $step) {
            foreach ($errors->signatures($step->evidence) as $signature) {
                $fingerprint = $signature->fingerprint($investigation->environment, $investigation->siteId);
                $seen[$fingerprint] ??= ['signature' => $signature, 'group' => null, 'diagnostics' => []];
                $seen[$fingerprint]['diagnostics'][] = $step->diagnosticName ?? (string)$step->diagnosticId;
            }
        }

        foreach ($errors->byFingerprints(array_keys($seen)) as $fingerprint => $group) {
            $seen[$fingerprint]['group'] = $group;
        }

        return array_values($seen);
    }

    /**
     * Who started it, as a reader would recognise them, where Craft still knows them.
     */
    private function userLabel(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        try {
            return Craft::$app->getUsers()->getUserById($userId)?->getFriendlyName()
                ?? Craft::t('web-doctor', 'User #{id} (no longer available)', ['id' => $userId]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Who is asking, where Craft knows. Recorded against the investigation, never used to authorise it.
     */
    private function userId(): ?int
    {
        try {
            return Craft::$app->getUser()->getIdentity()?->id;
        } catch (Throwable) {
            return null;
        }
    }

    private function plugin(): WebDoctor
    {
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $plugin;
    }
}
