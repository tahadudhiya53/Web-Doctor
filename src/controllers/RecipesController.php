<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\Actor;
use Tahadudhiya\WebDoctor\helpers\RequestInput;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\Investigation;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The recipes: investigations that start from a symptom — "I have a 500 error" — rather than from
 * an issue.
 *
 * Reading needs what reading issues needs, because what a recipe found is issues and evidence.
 * Running one is an investigation, so it needs what investigating needs, and a POST.
 */
class RecipesController extends Controller
{
    /** @var int How many of each recipe's recent investigations the page lists. */
    public const HISTORY_PER_RECIPE = 5;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it, and
        // a recipe is not something a front-end request may set going.
        $this->requireCpRequest();
        // The section's own permission as well as this one's: Craft nests them only in its own
        // screens, and a permission set written another way can hold a child without its parent.
        $this->requirePermission(Permissions::VIEW);
        $this->requirePermission(Permissions::VIEW_ISSUES);

        return true;
    }

    /**
     * Every recipe, with what it would look at and why, and its recent investigations here.
     * Opening the page runs nothing.
     */
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        // Where a recipe would run, as the service decides it, so the page says what will happen.
        $environment = $plugin->getInvestigations()->environment();
        $siteId = null;

        $recipes = [];
        $plans = [];
        $failure = null;

        try {
            // Inside the boundary: a site Craft cannot tell is a page that says so, never "no site".
            $siteId = DiagnosticContext::currentSiteId();
            $recipes = $plugin->getRecipes()->all();

            foreach ($recipes as $recipe) {
                $plans[$recipe->id] = $plugin->getInvestigations()->planRecipe($recipe);
            }
        } catch (Throwable $e) {
            SafeException::log('The recipes could not be read', $e);
            $failure = Craft::t('web-doctor', 'Web Doctor could not read its recipes. The details are in Craft’s logs.');
        }

        // Read on its own, so a history that cannot be read costs the page its history rather than
        // costing the reader the recipes.
        $history = [];
        $historyFailure = null;

        try {
            foreach ($plugin->getInvestigations()->recipeHistory(self::HISTORY_PER_RECIPE) as $investigation) {
                $history[(string)$investigation->recipeId][] = $investigation;
            }
        } catch (Throwable $e) {
            SafeException::log('The recipes\' investigations could not be read', $e);
            $historyFailure = Craft::t('web-doctor', 'Web Doctor could not read the investigations recipes have run. The details are in Craft’s logs.');
        }

        // Read on its own for the same reason.
        $leadingCauses = [];
        $causesFailure = false;

        try {
            $leadingCauses = $plugin->getRootCauses()->leading(array_map(
                static fn(Investigation $i): int => $i->id,
                array_merge([], ...array_values($history)),
            ));
        } catch (Throwable $e) {
            SafeException::log('The causes recipes weighed could not be read', $e);
            // Said on the page, so a cause that could not be read never reads as no cause.
            $causesFailure = true;
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_recipes/_index', [
            'title' => Craft::t('web-doctor', 'Recipes'),
            'recipes' => $recipes,
            'plans' => $plans,
            'history' => $history,
            'failure' => $failure,
            'historyFailure' => $historyFailure,
            'leadingCauses' => $leadingCauses,
            'causesFailure' => $causesFailure,
            'environment' => $environment,
            'siteName' => $siteId === null ? null : SiteName::label($siteId),
            'canInvestigate' => $plugin->getPermissions()->canInvestigate(),
            'depths' => DiagnosticDepth::cases(),
        ]);
    }

    /**
     * Runs a recipe, then shows what it found. A request naming no recipe, or a depth Web Doctor
     * does not have, is refused before anything runs.
     *
     * @throws BadRequestHttpException
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::INVESTIGATE_ISSUES);

        $recipeId = $this->request->getRequiredBodyParam('recipeId');

        if (!is_string($recipeId) || $recipeId === '') {
            throw new BadRequestHttpException(Craft::t('web-doctor', 'The request does not name a recipe.'));
        }

        $depth = RequestInput::depth($this->request->getBodyParam('depth'));

        try {
            $investigation = $this->plugin()->getInvestigations()->runRecipe($recipeId, $depth, $this->userId());
        } catch (Refusal $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirectToPostedUrl();
        } catch (Throwable $e) {
            SafeException::log('A recipe could not be run', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The recipe could not be run. The details are in Craft’s logs.'));

            return $this->redirectToPostedUrl();
        }

        // What its page will advise for each finding, recorded once, as the investigation produced it.
        $this->plugin()->getAudit()->investigationRecommendations($investigation, null, $this->plugin()->getRecommendations());

        $this->setSuccessFlash(Craft::t('web-doctor', 'Investigation finished.'));

        return $this->redirect(UrlHelper::cpUrl(self::detailPath($investigation)));
    }

    /**
     * Where a recipe's investigation is read.
     */
    public static function detailPath(Investigation $investigation): string
    {
        return sprintf('web-doctor/recipes/%s/investigations/%d', (string)$investigation->recipeId, $investigation->id);
    }

    /**
     * Who is asking, where Craft knows. Recorded against the investigation, never used to authorise it.
     */
    private function userId(): ?int
    {
        // Craft's answer, or an error: a failed lookup is never recorded as nobody having asked.
        return Actor::current()[0];
    }

    private function plugin(): WebDoctor
    {
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $plugin;
    }
}
