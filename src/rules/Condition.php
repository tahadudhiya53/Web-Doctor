<?php

namespace Tahadudhiya\WebDoctor\rules;

use Closure;
use Tahadudhiya\WebDoctor\enums\ConditionRole;
use Tahadudhiya\WebDoctor\models\ConditionOutcome;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\Observation;

/**
 * One thing a root-cause rule looks for, the part it plays in the rule, and how to find it.
 *
 * The description is the condition as a reader would state it, and is shown whether it was found
 * or not — so a cause always says what it rested on and what it was looking for that was not there.
 */
final class Condition
{
    /** @var int The most observations one condition keeps; the rest are counted. */
    public const MAX_OBSERVATIONS = 10;

    /**
     * @param Closure(CorrelationCase): list<Observation> $match What in the case meets it.
     */
    private function __construct(
        public readonly string $id,
        public readonly ConditionRole $role,
        public readonly string $description,
        private readonly Closure $match,
    ) {
    }

    /**
     * @param Closure(CorrelationCase): list<Observation> $match
     */
    public static function requires(string $id, string $description, Closure $match): self
    {
        return new self($id, ConditionRole::REQUIRED, $description, $match);
    }

    /**
     * @param Closure(CorrelationCase): list<Observation> $match
     */
    public static function supports(string $id, string $description, Closure $match): self
    {
        return new self($id, ConditionRole::SUPPORTING, $description, $match);
    }

    /**
     * @param Closure(CorrelationCase): list<Observation> $match
     */
    public static function confirms(string $id, string $description, Closure $match): self
    {
        return new self($id, ConditionRole::CONFIRMING, $description, $match);
    }

    /**
     * @param Closure(CorrelationCase): list<Observation> $match
     */
    public static function contradicts(string $id, string $description, Closure $match): self
    {
        return new self($id, ConditionRole::CONTRADICTING, $description, $match);
    }

    /**
     * What in the case meets this condition: each fact once, in a fixed order, and bounded.
     */
    public function assess(CorrelationCase $case): ConditionOutcome
    {
        $unique = [];

        foreach (($this->match)($case) as $observation) {
            $unique[$observation->key()] ??= $observation;
        }

        $observations = array_values($unique);
        usort($observations, [Observation::class, 'compare']);

        return new ConditionOutcome(
            id: $this->id,
            role: $this->role,
            description: $this->description,
            observations: array_slice($observations, 0, self::MAX_OBSERVATIONS),
            omitted: max(0, count($observations) - self::MAX_OBSERVATIONS),
        );
    }
}
