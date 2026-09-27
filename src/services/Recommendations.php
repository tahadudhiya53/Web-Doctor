<?php

namespace Tahadudhiya\WebDoctor\services;

use Tahadudhiya\WebDoctor\enums\InvestigationStatus;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Investigation;
use Tahadudhiya\WebDoctor\models\InvestigationStep;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\Recommendation;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RecommendationSet;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\rules\RecommendationRule;
use Tahadudhiya\WebDoctor\rules\RecommendationRules;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;

/**
 * Chooses what to recommend for a finding, from the written-out rules.
 *
 * Nothing is stored: a recommendation is advice about a finding as it stands, so it is chosen again
 * each time it is shown, from the same rules and the same evidence, and comes out the same.
 */
class Recommendations extends Component
{
    /** @var Investigations|null Where an issue's investigations are read; the plugin's own unless set. */
    public ?Investigations $investigations = null;

    /** @var RootCauses|null Where the causes they weighed are read; the plugin's own unless set. */
    public ?RootCauses $rootCauses = null;

    /**
     * The recommendations for a finding: first, for each cause weighed for it that is held firmly
     * enough to act on, the advice for that cause, most firmly held first; then the first advice for
     * the check's finding whose evidence selects it.
     *
     * A rule that breaks costs this finding that rule, never the page it is shown on, and is named
     * in what comes back so the page can say so.
     *
     * @param list<RecommendationRule>|null $rules The rules to choose from, the written-out ones unless given.
     */
    public function recommend(RecommendationCase $case, ?array $rules = null): RecommendationSet
    {
        if (!$case->isFinding()) {
            return new RecommendationSet();
        }

        $rules ??= RecommendationRules::all();
        $out = [];
        $failed = [];

        foreach ($case->causes as $cause) {
            foreach ($rules as $rule) {
                if ($rule->cause === $cause->ruleId && !isset($out[$rule->id]) && !isset($failed[$rule->id])) {
                    $this->attempt($rule, $case, $out, $failed);
                }
            }
        }

        // The check's own order decides, so the search stops at the first rule that matched or
        // broke: passing a broken rule by would give a lower-precedence rule's advice, which may
        // be the very action the broken one warns against.
        if ($case->evidenceComplete) {
            foreach ($rules as $rule) {
                if ($rule->check === $case->diagnosticId && !isset($failed[$rule->id]) && ($this->attempt($rule, $case, $out, $failed) || isset($failed[$rule->id]))) {
                    break;
                }
            }
        }

        $failed = array_keys($failed);
        sort($failed, SORT_STRING);

        return new RecommendationSet(array_values($out), $failed, partialEvidence: !$case->evidenceComplete);
    }

    /**
     * The recommendations for a check's result as it was just reported. No cause has been weighed
     * for a result on its own.
     */
    public function forResult(DiagnosticResult $result): RecommendationSet
    {
        return $this->recommend(RecommendationCase::fromResult($result));
    }

    /**
     * What to do about each problem the checks reported, from the evidence each step kept. The
     * causes weighed apply to the problem they were weighed for — the issue investigated, or for a
     * recipe the most serious problem found — and that problem comes first.
     *
     * @param list<InvestigationStep> $checks
     * @param list<RootCause> $causes
     * @return list<array{step: InvestigationStep, recommendations: RecommendationSet}>
     */
    public function forInvestigation(array $checks, array $causes, ?Issue $issue, ?InvestigationStep $diagnosed): array
    {
        $weighedFor = static fn(InvestigationStep $step): bool => $issue !== null
            ? $step->diagnosticId === $issue->diagnosticId
            : $diagnosed?->relatedIssueId !== null && $step->relatedIssueId === $diagnosed->relatedIssueId;

        $first = [];
        $rest = [];

        foreach ($checks as $step) {
            $subject = $weighedFor($step);
            $case = $step->isFinding() ? RecommendationCase::fromStep($step, $subject ? $causes : []) : null;

            if ($case === null) {
                continue;
            }

            $entry = ['step' => $step, 'recommendations' => $this->recommend($case)];

            if ($subject) {
                $first[] = $entry;
            } else {
                $rest[] = $entry;
            }
        }

        return [...$first, ...$rest];
    }

    /**
     * The recommendations for an issue, from the evidence its latest finding left and the causes its
     * latest investigation weighed. A resolved issue has nothing left to act on.
     *
     * @param iterable<Evidence> $evidence
     * @param list<Investigation>|null $investigations The issue's investigations, newest first,
     * where the caller has already read them; read here otherwise.
     */
    public function forIssue(Issue $issue, iterable $evidence, ?array $investigations = null): RecommendationSet
    {
        if ($issue->status === IssueStatus::RESOLVED) {
            return new RecommendationSet();
        }

        return $this->recommend(RecommendationCase::fromIssue($issue, $evidence, $this->causesFor($issue, $investigations)));
    }

    /**
     * The causes weighed by the newest investigation of an issue that got as far as weighing them.
     * Newer answers replace older ones — including an investigation that found no cause — and one
     * still running or stopped outright has nothing to say.
     *
     * @param list<Investigation>|null $investigations The issue's investigations, newest first,
     * where the caller has already read them; read here otherwise.
     * @return list<RootCause>
     */
    public function causesFor(Issue $issue, ?array $investigations = null): array
    {
        foreach ($investigations ?? $this->investigations()->forIssue($issue->id) as $investigation) {
            // Only the issue's own: a list handed over is checked, never trusted to be this issue's.
            if ($investigation->issueId !== $issue->id) {
                continue;
            }

            if (in_array($investigation->status, [InvestigationStatus::COMPLETED, InvestigationStatus::PARTIAL], true)) {
                return $this->rootCauses()->forInvestigation($investigation->id);
            }
        }

        return [];
    }

    /**
     * Applies one rule, keeping what it recommends or noting that it broke.
     *
     * @param array<string, Recommendation> $out
     * @param array<string, true> $failed
     * @return bool Whether it recommended anything.
     */
    private function attempt(RecommendationRule $rule, RecommendationCase $case, array &$out, array &$failed): bool
    {
        try {
            $recommendation = $rule->recommend($case);
        } catch (Throwable $e) {
            SafeException::log(sprintf('The recommendation rule %s could not be applied', $rule->id), $e);
            $failed[$rule->id] = true;

            return false;
        }

        if ($recommendation === null) {
            return false;
        }

        $out[$rule->id] = $recommendation;

        return true;
    }

    private function investigations(): Investigations
    {
        return $this->investigations ??= WebDoctor::getInstance()?->getInvestigations() ?? new Investigations();
    }

    private function rootCauses(): RootCauses
    {
        return $this->rootCauses ??= WebDoctor::getInstance()?->getRootCauses() ?? new RootCauses();
    }
}
