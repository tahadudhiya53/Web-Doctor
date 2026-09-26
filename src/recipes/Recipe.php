<?php

namespace Tahadudhiya\WebDoctor\recipes;

use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\investigations\RelatedArea;

/**
 * An investigation that starts from a symptom rather than from an issue: "I have a 500 error".
 *
 * A recipe holds no diagnostic logic. It names the areas worth looking at for the symptom, each
 * with the reason it is worth looking at, and the investigation engine runs whatever registered
 * checks cover them — so a recipe can only ever find what the checks themselves find, and what it
 * finds lands in the Issue Center like any other finding.
 *
 * The areas are split in two, as an issue's investigation is. The primary areas are where the
 * symptom itself lives and are looked at at every depth; the related ones are what commonly causes
 * it, and are added at normal depth and deeper.
 */
final class Recipe
{
    public readonly string $title;
    public readonly string $symptom;
    public readonly string $description;

    /** @var list<RelatedArea> */
    public readonly array $primary;

    /** @var list<RelatedArea> */
    public readonly array $related;

    /** @var list<string> */
    public readonly array $leads;

    /**
     * @param string $id Permanent, recorded against every investigation run from it. The same shape
     * as a diagnostic ID, so recipes from Web Doctor and from other plugins stay distinguishable.
     * @param string $title What it is called — "500 Error Doctor".
     * @param string $symptom What somebody reaching for it would say — "I have a 500 error".
     * @param string $description What it looks at, in a sentence or two.
     * @param DiagnosticCategory $category The kind of problem the symptom is, which decides which
     * open issues count as nearby alongside the areas it looks at.
     * @param list<RelatedArea> $primary Looked at at every depth, most relevant first.
     * @param list<RelatedArea> $related Added at normal depth and deeper, most relevant first.
     * @param list<string> $leads What is worth inspecting by hand that no check here covers.
     */
    public function __construct(
        public readonly string $id,
        string $title,
        string $symptom,
        string $description,
        public readonly DiagnosticCategory $category,
        array $primary,
        array $related = [],
        array $leads = [],
    ) {
        // Typed as they are taken, so a recipe another plugin built with something that is not an
        // area fails where it was built rather than when an investigation tries to follow it.
        $area = static fn(RelatedArea $area): RelatedArea => $area;
        $this->primary = array_map($area, $primary);
        $this->related = array_map($area, $related);

        // Redacted as it is built, like every other text Web Doctor prints that it may not have
        // written: another plugin's recipe supplies its own.
        $this->title = Redaction::redactString($title);
        $this->symptom = Redaction::redactString($symptom);
        $this->description = Redaction::redactString($description);
        $this->leads = array_map(static fn(string $lead): string => Redaction::redactString($lead), $leads);
    }
}
