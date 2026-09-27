<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use RuntimeException;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RecommendationSet;
use Tahadudhiya\WebDoctor\rules\RecommendationRule;
use Tahadudhiya\WebDoctor\rules\RecommendationRules;
use Tahadudhiya\WebDoctor\services\Recommendations;

/**
 * The recommendations service with a rule for one check that breaks, ahead of the written-out
 * rules — as a rule meeting evidence it was not written for would break. Its exception quotes a
 * credential, so a page that repeated it would be caught.
 */
class BreakingRuleRecommendations extends Recommendations
{
    public string $check = '';

    public function recommend(RecommendationCase $case, ?array $rules = null): RecommendationSet
    {
        $breaks = RecommendationRule::forFinding(
            id: 'tests.breaks',
            check: $this->check,
            title: 'Breaks',
            explanation: 'Breaks.',
            action: 'Breaks.',
            rationale: 'Breaks.',
            risk: RepairRisk::LOW,
            riskReason: 'Breaks.',
            verification: 'Breaks.',
            match: static fn(): array => throw new RuntimeException('A rule broke: password=hunter2'),
        );

        return parent::recommend($case, [$breaks, ...($rules ?? RecommendationRules::all())]);
    }
}
