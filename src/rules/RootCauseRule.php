<?php

namespace Tahadudhiya\WebDoctor\rules;

use Tahadudhiya\WebDoctor\enums\ConditionRole;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\models\ConditionOutcome;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\Observation;
use Tahadudhiya\WebDoctor\models\RootCause;

/**
 * One known cause: what it is, the problems it can explain, what has to be found for it to be
 * considered, what makes it more or less likely, how firmly it may ever be held, and what to do
 * about it.
 *
 * Deterministic: the same case always produces the same answer. A rule never reads anything but
 * the case, and every judgement it makes is one of its written-out conditions.
 */
final class RootCauseRule
{
    /**
     * @param string $id Stable, recorded against every cause weighed under it.
     * @param string $title The cause, in a line — the diagnosis.
     * @param string $statement What it would mean, and why it would explain the problem.
     * @param list<DiagnosticCategory>|null $explains The kinds of problem it can explain. Null for
     * any: a cause about where problems come from rather than what they are.
     * @param list<Condition> $conditions What it looks for, in the order a reader should read them.
     * @param Confidence $ceiling The most firmly it is held without evidence that establishes it.
     * @param string $recommendation What to do if it is right.
     * @param list<string> $nextSteps What to look at to find out whether it is right.
     * @param string|null $limitation Why it is never held more firmly, for a rule that cannot be
     * confirmed or is held below high confidence.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $statement,
        public readonly ?array $explains,
        public readonly array $conditions,
        public readonly Confidence $ceiling,
        public readonly string $recommendation,
        public readonly array $nextSteps,
        public readonly ?string $limitation = null,
    ) {
        // Informational is not a way a cause is held, so it cannot be how firmly one may be.
        if (!in_array($ceiling, RootCause::LADDER, true)) {
            throw new \InvalidArgumentException(sprintf('The cause "%s" has the ceiling "%s", which is not a confidence a cause can be held at.', $id, $ceiling->value));
        }
    }

    /**
     * Whether a problem in this category is one this cause could explain.
     */
    public function explains(DiagnosticCategory $category): bool
    {
        return $this->explains === null || in_array($category, $this->explains, true);
    }

    /** Whether anything this rule looks for can establish it. */
    public function canConfirm(): bool
    {
        foreach ($this->conditions as $condition) {
            if ($condition->role === ConditionRole::CONFIRMING) {
                return true;
            }
        }

        return false;
    }

    /**
     * Weighs the case, or returns null when this cause is not one to consider: a problem it does
     * not explain, or something it requires that was not found.
     */
    public function assess(CorrelationCase $case): ?RootCause
    {
        if (!$this->explains($case->issue->category)) {
            return null;
        }

        $outcomes = [];

        foreach ($this->conditions as $condition) {
            $outcome = $condition->assess($case);

            // Asked first and given up on at once: a cause missing what it requires is not a weak
            // candidate, it is not a candidate, and weighing the rest would only cost time.
            if ($condition->role === ConditionRole::REQUIRED && !$outcome->met()) {
                return null;
            }

            $outcomes[] = $outcome;
        }

        $confidence = RootCause::confidenceFor($outcomes, $this->ceiling);

        return new RootCause(
            ruleId: $this->id,
            title: $this->title,
            statement: $this->statement,
            problem: $case->issue->title,
            confidence: $confidence,
            conditions: $outcomes,
            reasoning: RootCause::explain($outcomes, $this->ceiling, $confidence, $this->limitation),
            relatedIssues: $this->relatedIssues($case, $outcomes),
            recommendation: $this->recommendation,
            nextSteps: $this->nextSteps,
            limitation: $this->limitation,
        );
    }

    /**
     * The other issues the evidence for this cause came from — the ones it would explain along
     * with the problem itself. What counts against it is not among them.
     *
     * @param list<ConditionOutcome> $outcomes
     * @return list<array{id: int, title: string, severity: string}>
     */
    private function relatedIssues(CorrelationCase $case, array $outcomes): array
    {
        $ids = [];

        foreach ($outcomes as $outcome) {
            if (!$outcome->role->isFor()) {
                continue;
            }

            foreach ($outcome->observations as $observation) {
                /** @var Observation $observation */
                if ($observation->issueId !== null && $observation->issueId !== $case->issue->id) {
                    $ids[$observation->issueId] = true;
                }
            }
        }

        ksort($ids, SORT_NUMERIC);
        $related = [];

        foreach (array_keys($ids) as $id) {
            $issue = $case->issue($id);

            // A finding whose issue the case holds no snapshot of is still named, by its ID.
            $related[] = $issue === null
                ? ['id' => $id, 'title' => '', 'severity' => '']
                : ['id' => $issue->id, 'title' => $issue->title, 'severity' => $issue->severity->value];
        }

        return $related;
    }
}
