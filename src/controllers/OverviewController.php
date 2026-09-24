<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\models\Dashboard;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\HealthSummary;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\dashboard\DashboardAsset;
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
        $siteId = $this->currentSiteId();
        $failure = null;

        try {
            $dashboard = Dashboard::build(
                $plugin->getDiagnostics()->all(),
                $plugin->getRuns()->latest($siteId),
            );
        } catch (Throwable $e) {
            // The registry is partly other people's code and the stored run is partly the
            // cache's. Either can fail, and the reader is owed a page that says so.
            $this->logFailure('The health dashboard could not be assembled', $e);
            $dashboard = Dashboard::build([], null);
            $failure = Craft::t('web-doctor', 'Web Doctor could not read the last diagnostic run. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(DashboardAsset::class);

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

        try {
            $selected = [];

            if (!$this->request->getBodyParam('all')) {
                $selected = $this->selectedIds($plugin->getDiagnostics()->ids());

                if ($selected === []) {
                    $this->setFailFlash(Craft::t('web-doctor', 'No registered checks were selected, so nothing was run.'));

                    return $this->redirectToPostedUrl();
                }
            }

            $context = DiagnosticContext::current($this->depth());
            $engine = $plugin->getDiagnosticEngine();

            $run = $selected === []
                ? $engine->runAll($context)
                : $engine->runMany($selected, $context);
        } catch (Throwable $e) {
            // The engine contains a diagnostic that throws; nothing contains the engine itself,
            // the registry that hands it the checks, or the context that identifies the run.
            $this->logFailure('A diagnostic run could not be completed', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The checks could not be run. The details are in Craft’s logs.'));

            return $this->redirectToPostedUrl();
        }

        // A run that cannot be stored is still a run that happened, and its results are already
        // in hand — so the failure is reported rather than turned into a failed request.
        if (!$plugin->getRuns()->remember($run)) {
            $this->setFailFlash(Craft::t('web-doctor', 'The checks ran, but the results could not be stored. Check that Craft’s cache is writable.'));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t('web-doctor', '{count, plural, =1{1 check ran.} other{# checks ran.}}', ['count' => $run->count()]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Which checks the request asked for, reduced to the ones that exist.
     *
     * One field, read explicitly and matched against the registry, so a request can choose among
     * the checks that exist but cannot introduce one. The registry's order is kept.
     *
     * @param string[] $registered
     * @return string[]
     */
    private function selectedIds(array $registered): array
    {
        $requested = $this->request->getBodyParam('diagnostics');

        if (!is_array($requested)) {
            return [];
        }

        $requested = array_filter($requested, static fn(mixed $id): bool => is_string($id));

        return array_values(array_intersect($registered, $requested));
    }

    /**
     * How far the run should go. A depth Web Doctor does not have is the normal one, rather than
     * a failed request over a query string.
     */
    private function depth(): DiagnosticDepth
    {
        $requested = $this->request->getBodyParam('depth');

        return is_string($requested)
            ? DiagnosticDepth::tryFrom($requested) ?? DiagnosticDepth::NORMAL
            : DiagnosticDepth::NORMAL;
    }

    /**
     * The site being looked at, where Craft can say. A run is remembered against it, so a
     * multi-site installation never shows one site's findings under another's name.
     */
    private function currentSiteId(): ?int
    {
        try {
            return Craft::$app->getSites()->getCurrentSite()->id;
        } catch (Throwable) {
            return null;
        }
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
     * Records a failure of Web Doctor's own through the same sanitised representation everything
     * else goes through, so the log cannot become the boundary that leaks what the page does not.
     */
    private function logFailure(string $what, Throwable $exception): void
    {
        $safe = SafeException::from($exception);

        Craft::error(sprintf('%s. %s at %s', $what, $safe->summary(), $safe->origin), WebDoctor::LOG_CATEGORY);
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
