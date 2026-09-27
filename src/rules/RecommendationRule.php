<?php

namespace Tahadudhiya\WebDoctor\rules;

use Closure;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\models\ConditionOutcome;
use Tahadudhiya\WebDoctor\models\Observation;
use Tahadudhiya\WebDoctor\models\Recommendation;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RootCause;

/**
 * One piece of advice: the finding or the cause it answers, when it applies, what to do, how risky
 * that is and why, what has to be in place first, and how to tell whether it worked.
 *
 * A rule answers either one check's findings — selected by what that check recorded — or one known
 * cause, once an investigation holds it at {@see self::ACTIONABLE} or more firmly. Deterministic: a
 * rule reads the case and nothing else.
 */
final class RecommendationRule
{
    /**
     * @var Confidence The least firmly a cause is held for it to be acted on. A cause held only as
     * possible is a lead to look into; the causes section says what to look at for it.
     */
    public const ACTIONABLE = Confidence::LIKELY;

    /** @var int The most facts one recommendation quotes as its basis. */
    public const MAX_BASIS = 10;

    /**
     * @param string|null $check The check whose findings it answers.
     * @param string|null $cause The known cause it answers, by its rule ID.
     * @param string|null $explanation What the finding means. Null for a cause: the cause's own
     * statement is the explanation.
     * @param string|null $action What to do. Null for a cause: the cause's own recommendation, as it
     * was concluded, is the action — so the advice and the diagnosis never say two different things.
     * @param list<string> $alsoVerifyWith Checks to run again beside the one that found the problem,
     * because the action can disturb what they look at.
     * @param list<string> $prerequisites
     * @param (Closure(RecommendationCase): list<Observation>)|null $match What in the finding selects
     * it; nothing found, and it does not apply.
     */
    private function __construct(
        public readonly string $id,
        public readonly ?string $check,
        public readonly ?string $cause,
        public readonly string $title,
        public readonly ?string $explanation,
        public readonly ?string $action,
        public readonly string $rationale,
        public readonly RepairRisk $risk,
        public readonly string $riskReason,
        public readonly string $verification,
        public readonly array $alsoVerifyWith,
        public readonly array $prerequisites,
        private readonly ?Closure $match,
    ) {
    }

    /**
     * Advice for one check's findings, where the match finds what selects it.
     *
     * @param Closure(RecommendationCase): list<Observation> $match
     * @param list<string> $prerequisites
     * @param list<string> $alsoVerifyWith
     */
    public static function forFinding(
        string $id,
        string $check,
        string $title,
        string $explanation,
        string $action,
        string $rationale,
        RepairRisk $risk,
        string $riskReason,
        string $verification,
        Closure $match,
        array $prerequisites = [],
        array $alsoVerifyWith = [],
    ): self {
        return new self($id, $check, null, $title, $explanation, $action, $rationale, $risk, $riskReason, $verification, $alsoVerifyWith, $prerequisites, $match);
    }

    /**
     * Advice for a known cause, once it is held firmly enough to act on.
     *
     * @param list<string> $prerequisites
     * @param list<string> $alsoVerifyWith
     */
    public static function forCause(
        string $id,
        string $cause,
        string $title,
        string $rationale,
        RepairRisk $risk,
        string $riskReason,
        string $verification,
        array $prerequisites = [],
        array $alsoVerifyWith = [],
    ): self {
        return new self($id, null, $cause, $title, null, null, $rationale, $risk, $riskReason, $verification, $alsoVerifyWith, $prerequisites, null);
    }

    /**
     * The advice for this finding, or null where the rule does not apply to it.
     */
    public function recommend(RecommendationCase $case): ?Recommendation
    {
        if (!$case->isFinding()) {
            return null;
        }

        if ($this->cause !== null) {
            $cause = $this->actionableCause($case);
            // A cause read back without the evidence it was found on is not acted on: the advice
            // would rest on nothing a reader could check.
            $basis = $cause === null ? [] : self::basisOf($cause);

            return $basis === [] ? null : $this->build($case, $basis, $cause);
        }

        if ($this->check !== $case->diagnosticId || $this->match === null) {
            return null;
        }

        $basis = ($this->match)($case);

        return $basis === [] ? null : $this->build($case, $basis, null);
    }

    /**
     * The checks to run again: the one that found the problem, since its answer is what resolves the
     * issue, then the others this action can disturb, each once.
     *
     * @return list<string>
     */
    public function verifyWith(string $origin): array
    {
        return array_values(array_unique([$origin, ...$this->alsoVerifyWith]));
    }

    private function actionableCause(RecommendationCase $case): ?RootCause
    {
        foreach ($case->causes as $cause) {
            if ($cause->ruleId === $this->cause && $cause->confidence->rank() >= self::ACTIONABLE->rank()) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * @param list<Observation> $basis
     */
    private function build(RecommendationCase $case, array $basis, ?RootCause $cause): Recommendation
    {
        return new Recommendation(
            ruleId: $this->id,
            diagnosticId: $case->diagnosticId,
            issueId: $case->issueId,
            problem: $case->problem,
            title: $this->title,
            explanation: $this->explanation ?? $cause->statement ?? '',
            action: $this->action ?? $cause->recommendation ?? '',
            rationale: $this->rationale,
            basis: self::bounded($basis),
            risk: $this->risk,
            riskReason: $this->riskReason,
            verification: $this->verification,
            verifyWith: $this->verifyWith($case->diagnosticId),
            prerequisites: $this->prerequisites,
            cause: $cause,
        );
    }

    /**
     * What a cause was found on: the observations behind what it requires and what supports it.
     *
     * @return list<Observation>
     */
    private static function basisOf(RootCause $cause): array
    {
        return array_merge([], ...array_map(static fn(ConditionOutcome $c): array => $c->observations, $cause->supporting()));
    }

    /**
     * Each fact once, in a fixed order rather than the order the evidence happened to arrive in, and
     * no more than a reader can follow.
     *
     * @param list<Observation> $basis
     * @return list<Observation>
     */
    private static function bounded(array $basis): array
    {
        $unique = [];

        foreach ($basis as $observation) {
            $unique[$observation->key()] ??= $observation;
        }

        $basis = array_values($unique);
        usort($basis, [Observation::class, 'compare']);

        return array_slice($basis, 0, self::MAX_BASIS);
    }
}
