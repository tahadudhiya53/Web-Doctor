<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * The recommendations chosen for one finding, and the rules that broke while they were chosen.
 *
 * A rule that breaks costs the finding that rule and nothing else, and is counted here rather than
 * dropped: "no rule covers this" and "the rule that covers this could not be applied" are different
 * answers, and a reader has to be able to tell them apart.
 */
final class RecommendationSet
{
    /**
     * @param list<Recommendation> $recommendations In the order they are given.
     * @param list<string> $failed The IDs of the rules that broke, sorted.
     * @param bool $partialEvidence Whether the finding's rules were not consulted because only part
     * of its evidence was kept — a third answer, beside "no rule covers this" and "a rule broke".
     */
    public function __construct(
        public readonly array $recommendations = [],
        public readonly array $failed = [],
        public readonly bool $partialEvidence = false,
    ) {
    }

    /** Whether no rule applies — as against rules that could not be applied, or not consulted. */
    public function nothingApplies(): bool
    {
        return $this->recommendations === [] && $this->failed === [] && !$this->partialEvidence;
    }
}
