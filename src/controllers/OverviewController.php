<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\helpers\RequestInput;
use Tahadudhiya\WebDoctor\models\Dashboard;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\ErrorRecording;
use Tahadudhiya\WebDoctor\models\HealthSummary;
use Tahadudhiya\WebDoctor\models\IssueReconciliation;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\web\Response;

/**
 * Web Doctor's health dashboard.
 *
 * Opening it reads the last run and executes nothing: a control panel page that quietly ran
 * eighteen checks against the database, the queue and remote filesystems would make Web Doctor
 * the performance problem it exists to find. Running is something a person asks for, on a POST,
 * with its own permission.
 */
class OverviewController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it, and
        // a diagnostic run is not something a front-end request may set going.
        $this->requireCpRequest();

        // Every action needs at least this; running additionally needs its own, checked there.
        $this->requirePermission(Permissions::VIEW);

        return true;
    }

    /**
     * Shows what the last run concluded. Executes nothing.
     */
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $siteId = DiagnosticContext::currentSiteId();
        $failure = null;

        try {
            $dashboard = Dashboard::build(
                $plugin->getDiagnostics()->all(),
                $plugin->getRuns()->latest($siteId),
            );
        } catch (Throwable $e) {
            // The registry is partly other people's code and the stored run is partly the
            // cache's. Either can fail, and the reader is owed a page that says so.
            SafeException::log('The health dashboard could not be assembled', $e);
            $dashboard = Dashboard::build([], null);
            $failure = Craft::t('web-doctor', 'Web Doctor could not read the last diagnostic run. The details are in Craft’s logs.');
        }

        // The way from a symptom to the recipe for it, for somebody who may read what a recipe found.
        // A registry that cannot be read costs the dashboard this pointer and nothing else.
        $recipes = [];

        if ($plugin->getPermissions()->canViewIssues()) {
            try {
                $recipes = $plugin->getRecipes()->all();
            } catch (Throwable $e) {
                SafeException::log('The recipes could not be read for the dashboard', $e);
            }
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_index', [
            'title' => $plugin->getSettings()->pluginName,
            'dashboard' => $dashboard,
            'health' => $dashboard->health(),
            'weights' => HealthSummary::weights(),
            'maxScore' => HealthSummary::MAX_SCORE,
            'depths' => DiagnosticDepth::cases(),
            'canRun' => $plugin->getPermissions()->canRun(),
            'siteLabel' => $this->siteLabel($dashboard->run?->context->siteId ?? $siteId),
            'failure' => $failure,
            'recipes' => $recipes,
        ]);
    }

    /**
     * Runs diagnostics, remembers the result and returns to the dashboard.
     *
     * Which checks run is stated by the request outright — every one of them, or the ones named.
     * A form where nothing is ticked and a form with no selection at all post almost the same
     * thing, and guessing between them would mean a reader who ticked nothing and pressed "run
     * selected" silently set the whole suite going.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::RUN);

        $plugin = $this->plugin();
        // Read before the boundary below, which turns failures into a notice: a malformed depth
        // is a refused request, not a run at some other depth.
        $depth = RequestInput::depth($this->request->getBodyParam('depth'));
        $all = RequestInput::flag($this->request->getBodyParam('all'));
        $requested = RequestInput::names($this->request->getBodyParam('diagnostics'));

        try {
            $selected = [];

            if (!$all) {
                // Among the checks that exist: a request can choose, but cannot introduce one. A check
                // removed since the page was drawn is dropped rather than refusing the rest.
                $selected = array_values(array_intersect($plugin->getDiagnostics()->ids(), $requested));

                if ($selected === []) {
                    $this->setFailFlash(Craft::t('web-doctor', 'No registered checks were selected, so nothing was run.'));

                    return $this->redirectToPostedUrl();
                }
            }

            $context = DiagnosticContext::current($depth);
            $engine = $plugin->getDiagnosticEngine();

            $run = $selected === []
                ? $engine->runAll($context)
                : $engine->runMany($selected, $context);
        } catch (Throwable $e) {
            // The engine contains a diagnostic that throws; nothing contains the engine itself,
            // the registry that hands it the checks, or the context that identifies the run.
            SafeException::log('A diagnostic run could not be completed', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The checks could not be run. The details are in Craft’s logs.'));

            return $this->redirectToPostedUrl();
        }

        // What follows keeps the run in three places, and each is independent of the others: a
        // run that cannot be cached is still a run that happened, and the issues and errors it
        // found are still worth keeping. So each failure is reported, and none stops the rest.
        $problems = [];

        if (!$plugin->getRuns()->remember($run)) {
            $problems[] = Craft::t('web-doctor', 'The results could not be stored, so the dashboard still shows the previous run. Check that Craft’s cache is writable.');
        }

        // Reported rather than swallowed: an Issue Center silently one run out of date is worse
        // than one that says it could not be brought up to date.
        try {
            $reconciliation = $plugin->getIssues()->reconcile($run);
        } catch (Throwable $e) {
            $reconciliation = null;
            SafeException::log('The Issue Center could not be brought up to date after a diagnostic run', $e);
            $problems[] = Craft::t('web-doctor', 'The issue list could not be updated.');
        }

        // After the issues, so the errors are related to issues as this run left them.
        try {
            $errors = $plugin->getErrors()->record($run);
        } catch (Throwable $e) {
            $errors = null;
            SafeException::log('The errors a diagnostic run recorded could not be grouped', $e);
            $problems[] = Craft::t('web-doctor', 'The errors the checks ran into could not be recorded.');
        }

        $summary = $this->summary($run, $reconciliation, $errors);

        if ($problems !== []) {
            $this->setFailFlash(Craft::t('web-doctor', 'The checks ran, but not everything could be kept.') . ' ' . implode(' ', $problems) . ' ' . Craft::t('web-doctor', 'The details are in Craft’s logs.') . ' ' . $summary);

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash($summary);

        return $this->redirectToPostedUrl();
    }

    /**
     * What a run found, in a sentence or two, saying only what there is to say.
     */
    private function summary(DiagnosticRun $run, ?IssueReconciliation $reconciliation, ?ErrorRecording $errors): string
    {
        $parts = [Craft::t('web-doctor', '{count, plural, =1{1 check ran.} other{# checks ran.}}', ['count' => $run->count()])];

        if ($reconciliation !== null) {
            $parts[] = Craft::t('web-doctor', '{opened, plural, =0{No new issues.} =1{1 new issue.} other{# new issues.}}', ['opened' => $reconciliation->opened]);

            if ($reconciliation->recurred > 0) {
                $parts[] = Craft::t('web-doctor', '{count, plural, =1{1 resolved issue came back.} other{# resolved issues came back.}}', ['count' => $reconciliation->recurred]);
            }

            if ($reconciliation->resolved > 0) {
                $parts[] = Craft::t('web-doctor', '{count, plural, =1{1 issue resolved.} other{# issues resolved.}}', ['count' => $reconciliation->resolved]);
            }
        }

        if ($errors !== null && $errors->groups > 0) {
            $parts[] = Craft::t('web-doctor', '{count, plural, =1{1 error recorded.} other{# errors recorded.}}', ['count' => $errors->groups]);
        }

        if ($errors !== null && $errors->omitted > 0) {
            $parts[] = Craft::t('web-doctor', '{omitted, plural, =1{1 more distinct error was not recorded, because as many are kept as the limit allows.} other{# more distinct errors were not recorded, because as many are kept as the limit allows.}}', ['omitted' => $errors->omitted]);
        }

        return implode(' ', $parts);
    }

    /**
     * What to call the site a run belongs to.
     *
     * A run with no site and a run whose site has since been deleted are different facts. Naming
     * the second "all sites" would take findings made while looking at one site and present them
     * as though they described the installation.
     */
    private function siteLabel(?int $siteId): string
    {
        if ($siteId === null) {
            return Craft::t('web-doctor', 'All sites');
        }

        try {
            $name = Craft::$app->getSites()->getSiteById($siteId)?->getName();
        } catch (Throwable) {
            $name = null;
        }

        return $name ?? Craft::t('web-doctor', 'Site #{id} (no longer available)', ['id' => $siteId]);
    }

    /**
     * Craft hands a plugin controller the plugin as its module, so this is the instance serving
     * the request rather than a lookup that could come back empty.
     */
    private function plugin(): WebDoctor
    {
        /** @var WebDoctor $plugin */
        $plugin = $this->module;

        return $plugin;
    }
}
