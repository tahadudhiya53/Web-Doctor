<?php

namespace Tahadudhiya\WebDoctor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\ErrorGroup;
use Tahadudhiya\WebDoctor\models\Investigation;
use Tahadudhiya\WebDoctor\models\IssueFilter;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\Investigations;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\web\assets\cp\ControlPanelAsset;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\InvalidArgumentException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Issue Center: the problems Web Doctor has found, and what has been done about them.
 *
 * Reading and changing are separately permissioned, and the change is a POST. Opening the list
 * runs no diagnostics — it reads what previous runs recorded, for the same reason the health
 * dashboard does.
 */
class IssuesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // A plugin action route is reachable from the front end unless something refuses it.
        $this->requireCpRequest();

        // Reading the Issue Center is its own permission, nested under reaching Web Doctor at
        // all. Changing an issue needs another, checked where the change happens.
        $this->requirePermission(Permissions::VIEW_ISSUES);

        return true;
    }

    /**
     * The issue list, filtered and sorted as the query string asks.
     */
    public function actionIndex(): Response
    {
        $plugin = $this->plugin();
        $params = $this->request->getQueryParams();

        // A request with nothing to say opens on what is outstanding. That default is stated
        // rather than left to an empty filter, because a list that quietly hides closed issues
        // without saying so is a list a reader will eventually be misled by.
        $filter = $params === [] || !$this->hasFilterParams($params)
            ? IssueFilter::outstanding()
            : IssueFilter::fromParams($params);

        $failure = null;

        try {
            $issues = $plugin->getIssues()->find($filter);
            $diagnostics = $plugin->getIssues()->knownDiagnostics();
            $environments = $plugin->getIssues()->knownEnvironments();
            // Counted under the same filter, minus the status facet the numbers sit beside.
            $counts = $plugin->getIssues()->countsByStatus($filter);
        } catch (Throwable $e) {
            SafeException::log('The issue list could not be read', $e);

            return $this->renderTemplate('web-doctor/_issues/_index', [
                'title' => Craft::t('web-doctor', 'Issues'),
                'failure' => Craft::t('web-doctor', 'Web Doctor could not read the issue list. The details are in Craft’s logs.'),
            ] + $this->emptyListVariables($filter));
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_issues/_index', [
            'title' => Craft::t('web-doctor', 'Issues'),
            'failure' => $failure,
            'issues' => $issues,
            'filter' => $filter,
            'counts' => $counts,
            'outstandingCount' => $this->outstanding($counts),
            'statuses' => IssueStatus::cases(),
            // Every status, so "show everything" can say so outright instead of relying on an
            // absent filter to mean it.
            'allStatuses' => IssueStatus::values(),
            'severities' => Severity::cases(),
            'diagnostics' => $diagnostics,
            'environments' => $environments,
            'sites' => $this->siteOptions(),
            'canManage' => $plugin->getPermissions()->canManageIssues(),
        ]);
    }

    /**
     * One issue: what it is, what it is based on, and everything that has happened to it.
     *
     * What its evidence contains is shown only to somebody who may see it. Everybody who may read
     * the issue sees what kind of evidence it rests on.
     *
     * @throws NotFoundHttpException if no such issue exists.
     */
    public function actionDetail(int $issueId): Response
    {
        $plugin = $this->plugin();

        try {
            $issue = $plugin->getIssues()->get($issueId);
            $events = $issue === null ? [] : $plugin->getIssues()->events($issueId, Issues::EVENT_LIMIT);
        } catch (Throwable $e) {
            SafeException::log('An issue could not be read', $e);

            throw new NotFoundHttpException(Craft::t('web-doctor', 'That issue could not be read.'));
        }

        if ($issue === null) {
            throw new NotFoundHttpException(Craft::t('web-doctor', 'No such issue.'));
        }

        // Read on its own, so evidence that cannot be read costs the page its evidence rather
        // than costing the reader the issue.
        $evidenceFailure = null;
        $latestEvidence = [];
        $earlierEvidence = null;

        try {
            $page = $this->request->getQueryParam('evidencePage');
            $latestEvidence = $plugin->getEvidence()->latest($issue->id, $issue->latestRunId);
            $earlierEvidence = $plugin->getEvidence()->earlier($issue->id, $issue->latestRunId, is_numeric($page) ? (int)$page : 1);
        } catch (Throwable $e) {
            SafeException::log('An issue\'s evidence could not be read', $e);
            $evidenceFailure = Craft::t('web-doctor', 'Web Doctor could not read the evidence behind this issue. The details are in Craft’s logs.');
        }

        // Read on its own for the same reason. What an investigation would look at is worked out
        // from the registry without running anything, so the reader can see the reasons first.
        $investigationFailure = null;
        $investigations = [];
        $investigationPlan = null;
        $investigationRefusal = null;
        $leadingCauses = [];

        try {
            $investigations = $plugin->getInvestigations()->forIssue($issue->id);
            $investigationPlan = $plugin->getInvestigations()->plan($issue);
            $investigationRefusal = $plugin->getInvestigations()->refusal($issue);
        } catch (Throwable $e) {
            SafeException::log('An issue\'s investigations could not be read', $e);
            $investigationFailure = Craft::t('web-doctor', 'Web Doctor could not read the investigations of this issue. The details are in Craft’s logs.');
        }

        // Read on its own, so a cause that cannot be read costs the list its causes rather than
        // costing the reader the investigations.
        try {
            $leadingCauses = $plugin->getRootCauses()->leading(array_map(static fn(Investigation $i): int => $i->id, $investigations));
        } catch (Throwable $e) {
            SafeException::log('An issue\'s investigations\' causes could not be read', $e);
        }

        // Read on its own for the same reason.
        $errorsFailure = null;
        $errorGroups = [];
        $errorIssues = [];

        try {
            $errorGroups = $plugin->getErrors()->forIssue($issue->id);
            $errorIssues = $plugin->getIssues()->getMany(array_values(array_unique(array_merge(
                [],
                ...array_map(static fn(ErrorGroup $group): array => $group->issueIds(), $errorGroups),
            ))));
        } catch (Throwable $e) {
            SafeException::log('An issue\'s errors could not be read', $e);
            $errorsFailure = Craft::t('web-doctor', 'Web Doctor could not read the errors related to this issue. The details are in Craft’s logs.');
        }

        $this->getView()->registerAssetBundle(ControlPanelAsset::class);

        return $this->renderTemplate('web-doctor/_issues/_detail', [
            'title' => Craft::t('web-doctor', 'Issues'),
            'issue' => $issue,
            'events' => $events,
            'siteLabel' => $this->siteLabel($issue->siteId, $issue->siteName),
            // Only the statuses a person may set, which is what makes resolution unavailable
            // here rather than merely discouraged.
            'settableStatuses' => IssueStatus::settableByHand(),
            'canManage' => $plugin->getPermissions()->canManageIssues(),
            'eventLimit' => Issues::EVENT_LIMIT,
            'latestEvidence' => $latestEvidence,
            'earlierEvidence' => $earlierEvidence,
            'evidenceFailure' => $evidenceFailure,
            'canViewEvidence' => $plugin->getPermissions()->canViewEvidence(),
            'investigations' => $investigations,
            'investigationPlan' => $investigationPlan,
            'investigationRefusal' => $investigationRefusal,
            'investigationFailure' => $investigationFailure,
            'leadingCauses' => $leadingCauses,
            'investigationLimit' => Investigations::HISTORY_LIMIT,
            'canInvestigate' => $plugin->getPermissions()->canInvestigate(),
            'depths' => DiagnosticDepth::cases(),
            'errorGroups' => $errorGroups,
            'errorIssues' => $errorIssues,
            'errorsFailure' => $errorsFailure,
        ]);
    }

    /**
     * Moves an issue through its lifecycle.
     *
     * The service refuses the statuses that are not a person's to set and insists on a reason
     * where one is owed, so this action validates the shape of the request and reports what came
     * back rather than deciding any of it a second time.
     */
    public function actionUpdateStatus(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Permissions::MANAGE_ISSUES);

        $issueId = (int)$this->request->getRequiredBodyParam('issueId');
        $requested = $this->request->getBodyParam('status');
        $status = is_string($requested) ? IssueStatus::tryFrom($requested) : null;

        if ($status === null) {
            $this->setFailFlash(Craft::t('web-doctor', 'That is not a status an issue can be in.'));

            return $this->redirectToPostedUrl();
        }

        $note = $this->request->getBodyParam('note');
        $note = is_string($note) ? $note : null;

        try {
            $before = $this->plugin()->getIssues()->get($issueId);
            $issue = $this->plugin()->getIssues()->transition($issueId, $status, $note, $this->userId());
        } catch (InvalidArgumentException $e) {
            // Refusals are the service stating its rules — a status nobody may set by hand, a
            // dismissal with no reason — and the reader is the person who needs to hear them.
            $this->setFailFlash($e->getMessage());

            return $this->redirectToPostedUrl();
        } catch (Throwable $e) {
            SafeException::log('An issue could not be updated', $e);
            $this->setFailFlash(Craft::t('web-doctor', 'The issue could not be updated. The details are in Craft’s logs.'));

            return $this->redirectToPostedUrl();
        }

        // Saving the status an issue already has, with no new reason, is not an update, and saying
        // it was would tell the reader a reason they typed had been kept.
        $unchanged = $before !== null && $before->status === $issue->status && $before->statusNote === $issue->statusNote;
        $this->setSuccessFlash($unchanged
            ? Craft::t('web-doctor', 'Nothing changed: the issue already had that status and reason.')
            : Craft::t('web-doctor', 'Issue updated.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * How many issues are still outstanding, across every open status.
     *
     * @param array<string, int> $counts
     */
    private function outstanding(array $counts): int
    {
        $total = 0;

        foreach (IssueStatus::open() as $status) {
            $total += $counts[$status->value] ?? 0;
        }

        return $total;
    }

    /**
     * Whether the query string is actually asking for something, as opposed to carrying a page
     * number or a sort left over from a link.
     *
     * @param array<string, mixed> $params
     */
    private function hasFilterParams(array $params): bool
    {
        foreach (['status', 'severity', 'diagnostic', 'siteId', 'environment', 'from', 'to'] as $key) {
            if (array_key_exists($key, $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the page needs to render nothing at all, when reading the issues failed.
     *
     * @return array<string, mixed>
     */
    private function emptyListVariables(IssueFilter $filter): array
    {
        return [
            'issues' => null,
            'filter' => $filter,
            'counts' => [],
            'outstandingCount' => 0,
            'statuses' => IssueStatus::cases(),
            'allStatuses' => IssueStatus::values(),
            'severities' => Severity::cases(),
            'diagnostics' => [],
            'environments' => [],
            'sites' => [],
            'canManage' => false,
        ];
    }

    /**
     * The sites a reader can filter by, where Craft can say.
     *
     * @return array<int, string>
     */
    private function siteOptions(): array
    {
        try {
            $sites = [];

            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $sites[$site->id] = $site->getName();
            }

            return $sites;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * What to call the site an issue belongs to.
     *
     * A finding made with no particular site in view and one made about a site that has since
     * been deleted are different facts. Deleting a site nulls the reference but leaves the name
     * behind, so the second still reads as the site it was rather than as the installation.
     */
    private function siteLabel(?int $siteId, ?string $siteName = null): string
    {
        if ($siteId === null) {
            return $siteName === null
                ? Craft::t('web-doctor', 'All sites')
                : Craft::t('web-doctor', '{name} (deleted)', ['name' => $siteName]);
        }

        try {
            $name = Craft::$app->getSites()->getSiteById($siteId)?->getName();
        } catch (Throwable) {
            $name = null;
        }

        return $name ?? $siteName ?? Craft::t('web-doctor', 'Site #{id} (no longer available)', ['id' => $siteId]);
    }

    /**
     * Who is asking, where Craft knows. Recorded against a change, never used to authorise one.
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
