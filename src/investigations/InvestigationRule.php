<?php

namespace Tahadudhiya\WebDoctor\investigations;

use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;

/**
 * What to look at when a particular kind of problem is being investigated.
 *
 * A rule names the kind of problem, the related areas worth inspecting with a reason for each,
 * and the leads worth following that no check here covers — a log, a provider's dashboard.
 * Leads are stated rather than dropped because "what else should I inspect?" has answers Web
 * Doctor cannot inspect itself, and leaving them out would make the answer look more complete
 * than it is.
 */
final class InvestigationRule
{
    /**
     * @param string $id Stable, recorded against every investigation that used the rule.
     * @param string $label The kind of problem, for a reader.
     * @param list<DiagnosticCategory> $categories The problems it applies to.
     * @param list<RelatedArea> $related What else to inspect, most relevant first.
     * @param list<string> $leads What to inspect by hand.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $categories,
        public readonly array $related,
        public readonly array $leads = [],
    ) {
    }

    public function appliesTo(DiagnosticCategory $category): bool
    {
        return in_array($category, $this->categories, true);
    }
}
