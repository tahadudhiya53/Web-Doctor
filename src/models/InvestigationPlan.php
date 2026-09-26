<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use JsonSerializable;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\helpers\DiagnosticMeta;
use Tahadudhiya\WebDoctor\investigations\InvestigationRules;
use Tahadudhiya\WebDoctor\investigations\RelatedArea;
use Tahadudhiya\WebDoctor\recipes\Recipe;
use yii\base\InvalidArgumentException;

/**
 * Which checks an investigation runs, and the reason for every one of them.
 *
 * Built deterministically from three things: the problem being investigated, the rule for that
 * kind of problem ({@see InvestigationRules}), and the checks actually registered here. The same
 * three always produce the same plan, so an investigation can be repeated and compared, and a
 * reader can check every choice against a stated reason rather than trusting it.
 *
 * Depth decides how far out it reaches. Shallow looks only where the problem was found — its own
 * check and the rest of its category. Normal adds the related areas; deep covers the same checks
 * as normal and asks each of them to go further.
 *
 * What the plan could not cover is part of it. A related area with no check registered here, and
 * a lead no check can follow, are listed rather than dropped: an investigation that silently
 * looked at less than it meant to would be presenting a gap as a clean result.
 */
final class InvestigationPlan implements JsonSerializable
{
    /** @var int The most checks one investigation runs, whatever the rule would reach. */
    public const MAX_CHECKS = 25;

    /** @var string The rule ID a recipe's plan is recorded under; which recipe is its own field. */
    public const RECIPE_RULE = 'recipe';

    /**
     * @param string $ruleId The rule the plan was built under.
     * @param string $ruleLabel What kind of problem it was taken to be, as a reader saw it then.
     * @param list<array{diagnosticId: string, name: string, category: string, reason: string, origin: bool}> $checks
     * What will run, in the order it runs, each with why.
     * @param list<array{area: string, reason: string, origin: bool}> $uncovered Areas worth
     * inspecting that nothing registered here covers.
     * @param list<string> $leads What is worth inspecting by hand.
     * @param int $omitted Checks the rule reached that the bound left out.
     * @param string|null $recipeId The recipe the plan was built from, for an investigation that
     * started from a symptom rather than an issue. Such a plan has no check that raised anything.
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $ruleLabel,
        public readonly DiagnosticDepth $depth,
        public readonly array $checks,
        public readonly array $uncovered = [],
        public readonly array $leads = [],
        public readonly int $omitted = 0,
        public readonly ?string $recipeId = null,
    ) {
    }

    /**
     * Plans the investigation of a problem.
     *
     * @param string $diagnosticId The check that raised the problem.
     * @param DiagnosticCategory $category Where the problem was found.
     * @param string|null $affectedPlugin The plugin the finding names, where it names one.
     * @param iterable<DiagnosticInterface> $available The checks registered here, in any order.
     */
    public static function build(
        string $diagnosticId,
        DiagnosticCategory $category,
        ?string $affectedPlugin,
        iterable $available,
        DiagnosticDepth $depth = DiagnosticDepth::NORMAL,
        int $maxChecks = self::MAX_CHECKS,
    ): self {
        $rule = InvestigationRules::for($category);

        $areas = [
            [RelatedArea::check($diagnosticId, Craft::t('web-doctor', 'The check that raised this issue, to see what it reports now.')), true],
            [RelatedArea::category($category, Craft::t('web-doctor', 'The rest of {category}, where the problem was found.', ['category' => $category->label()])), false],
        ];

        if ($depth !== DiagnosticDepth::SHALLOW) {
            if ($affectedPlugin !== null && $affectedPlugin !== '' && $category !== DiagnosticCategory::PLUGINS) {
                $areas[] = [RelatedArea::category(DiagnosticCategory::PLUGINS, Craft::t('web-doctor', 'The finding names the plugin “{plugin}”.', ['plugin' => $affectedPlugin])), false];
            }

            foreach ($rule->related as $area) {
                $areas[] = [$area, false];
            }
        }

        [$checks, $uncovered, $omitted] = self::choose($areas, $available, $maxChecks, $diagnosticId);

        return new self(
            ruleId: $rule->id,
            ruleLabel: $rule->label,
            depth: $depth,
            checks: $checks,
            uncovered: $uncovered,
            leads: $rule->leads,
            omitted: $omitted,
        );
    }

    /**
     * Plans the investigation of a symptom, from the recipe written for it.
     *
     * Chosen exactly as an issue's checks are, from the recipe's areas in the order it names them:
     * its primary areas at every depth, its related ones at normal depth and deeper. Nothing
     * raised the symptom, so no check is the origin.
     *
     * @param iterable<DiagnosticInterface> $available The checks registered here, in any order.
     */
    public static function forRecipe(
        Recipe $recipe,
        iterable $available,
        DiagnosticDepth $depth = DiagnosticDepth::NORMAL,
        int $maxChecks = self::MAX_CHECKS,
    ): self {
        $areas = array_map(static fn(RelatedArea $area): array => [$area, false], $depth === DiagnosticDepth::SHALLOW
            ? $recipe->primary
            : [...$recipe->primary, ...$recipe->related]);

        [$checks, $uncovered, $omitted] = self::choose($areas, $available, $maxChecks);

        return new self(
            ruleId: self::RECIPE_RULE,
            ruleLabel: $recipe->title,
            depth: $depth,
            checks: $checks,
            uncovered: $uncovered,
            leads: $recipe->leads,
            omitted: $omitted,
            recipeId: $recipe->id,
        );
    }

    /**
     * Chooses the checks the areas reach, each once and for its closest reason, within the bound;
     * and lists the areas nothing registered here covers.
     *
     * @param list<array{RelatedArea, bool}> $areas Each area, and whether it is the origin.
     * @param iterable<DiagnosticInterface> $available
     * @param string|null $originId The check that raised the problem, where one did.
     * @throws InvalidArgumentException for a bound below one.
     * @return array{list<array{diagnosticId: string, name: string, category: string, reason: string, origin: bool}>, list<array{area: string, reason: string, origin: bool}>, int}
     */
    private static function choose(array $areas, iterable $available, int $maxChecks, ?string $originId = null): array
    {
        // A bound of nothing would plan an investigation of nothing; read as one, it would plan
        // something nobody configured.
        if ($maxChecks < 1) {
            throw new InvalidArgumentException(sprintf('An investigation needs a maxChecks of at least 1; %d was given.', $maxChecks));
        }

        // Asked defensively: a contributed check that throws when asked what it is must cost the
        // plan that check, not the investigation.
        $registered = [];

        foreach ($available as $diagnostic) {
            $registered[DiagnosticMeta::id($diagnostic)] ??= [
                'name' => DiagnosticMeta::name($diagnostic),
                'category' => DiagnosticMeta::category($diagnostic),
            ];
        }

        // Put in the registry's own order — category, then ID — rather than trusting the order they
        // arrived in. Once the bound cuts in, which checks are chosen depends on that order, and a
        // plan that changed with how the registry happened to list them would not be repeatable.
        uksort($registered, static fn(string $a, string $b): int => [$registered[$a]['category']->position(), $a] <=> [$registered[$b]['category']->position(), $b]);

        $checks = $uncovered = $omitted = [];

        foreach ($areas as [$area, $origin]) {
            $covered = false;

            foreach ($registered as $id => $meta) {
                if (!$area->covers($id, $meta['category'])) {
                    continue;
                }

                $covered = true;

                if (isset($checks[$id])) {
                    // Already chosen for an earlier, closer reason, which is the one kept.
                    continue;
                }

                if (count($checks) >= $maxChecks) {
                    // Keyed, so a check several areas reach is left out once, not once per area.
                    $omitted[$id] = true;

                    continue;
                }

                $checks[$id] = [
                    'diagnosticId' => $id,
                    'name' => $meta['name'],
                    'category' => $meta['category']->value,
                    'reason' => $area->reason,
                    'origin' => $origin,
                ];
            }

            if (!$covered) {
                $uncovered[] = [
                    'area' => $origin ? (string)$originId : $area->label(),
                    'reason' => $origin
                        ? Craft::t('web-doctor', 'The check that raised this issue is not installed here any more, so what it reports now cannot be asked.')
                        : $area->reason,
                    'origin' => $origin,
                ];
            }
        }

        return [array_values($checks), $uncovered, count($omitted)];
    }

    /**
     * Reads back a plan as it was stored. Anything malformed is dropped rather than trusted, so a
     * row written by another version still reads as the plan it was, as far as it can be read.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $checks = [];

        foreach ((array)($stored['checks'] ?? []) as $check) {
            if (is_array($check) && is_string($check['diagnosticId'] ?? null)) {
                $checks[] = [
                    'diagnosticId' => $check['diagnosticId'],
                    'name' => (string)($check['name'] ?? $check['diagnosticId']),
                    'category' => (string)($check['category'] ?? ''),
                    'reason' => (string)($check['reason'] ?? ''),
                    'origin' => (bool)($check['origin'] ?? false),
                ];
            }
        }

        $uncovered = [];

        foreach ((array)($stored['uncovered'] ?? []) as $area) {
            if (is_array($area)) {
                $uncovered[] = [
                    'area' => (string)($area['area'] ?? ''),
                    'reason' => (string)($area['reason'] ?? ''),
                    'origin' => (bool)($area['origin'] ?? false),
                ];
            }
        }

        return new self(
            ruleId: (string)($stored['ruleId'] ?? InvestigationRules::FALLBACK),
            ruleLabel: (string)($stored['ruleLabel'] ?? ''),
            depth: DiagnosticDepth::tryFrom((string)($stored['depth'] ?? '')) ?? DiagnosticDepth::NORMAL,
            checks: $checks,
            uncovered: $uncovered,
            leads: array_values(array_map('strval', array_filter((array)($stored['leads'] ?? []), 'is_string'))),
            omitted: (int)($stored['omitted'] ?? 0),
            recipeId: is_string($stored['recipeId'] ?? null) && $stored['recipeId'] !== '' ? $stored['recipeId'] : null,
        );
    }

    /**
     * @return list<string>
     */
    public function diagnosticIds(): array
    {
        return array_column($this->checks, 'diagnosticId');
    }

    /**
     * Whether the check that raised the problem is part of the plan — it is not, once whatever
     * contributed it has been removed.
     */
    public function includesOrigin(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['origin']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the plan should have included the check that raised the problem and could not. A
     * recipe's plan never has one to include.
     */
    public function missesOrigin(): bool
    {
        return $this->recipeId === null && !$this->includesOrigin();
    }

    /**
     * The reason a check was chosen.
     */
    public function reasonFor(string $diagnosticId): ?string
    {
        foreach ($this->checks as $check) {
            if ($check['diagnosticId'] === $diagnosticId) {
                return $check['reason'];
            }
        }

        return null;
    }

    /**
     * The categories the plan's checks belong to, which is what decides which other open issues
     * count as nearby.
     *
     * @return list<DiagnosticCategory>
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->checks as $check) {
            $category = DiagnosticCategory::tryFrom($check['category']);

            if ($category !== null && !in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'ruleLabel' => $this->ruleLabel,
            'depth' => $this->depth->value,
            'checks' => $this->checks,
            'uncovered' => $this->uncovered,
            'leads' => $this->leads,
            'omitted' => $this->omitted,
            'recipeId' => $this->recipeId,
        ];
    }
}
