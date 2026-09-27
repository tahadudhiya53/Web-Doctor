<?php

namespace Tahadudhiya\WebDoctor\investigations;

use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * One part of the installation worth inspecting alongside a problem, and why.
 *
 * Either a whole category — every check registered in it — or one check by ID. The reason is
 * the point: an investigation that looked at the queue because of a database problem has to be
 * able to say that the default queue stores its jobs in the database, or the choice is a guess
 * a reader cannot check.
 */
final class RelatedArea
{
    public readonly string $reason;

    private function __construct(
        public readonly ?DiagnosticCategory $category,
        public readonly ?string $diagnosticId,
        string $reason,
    ) {
        // Redacted as it is built: a recipe another plugin contributes writes its own reasons, and
        // a plan is shown before anything stores, and so redacts, it.
        $this->reason = Redaction::redactString($reason);
    }

    public static function category(DiagnosticCategory $category, string $reason): self
    {
        return new self($category, null, $reason);
    }

    public static function check(string $diagnosticId, string $reason): self
    {
        return new self(null, $diagnosticId, $reason);
    }

    /**
     * Whether a registered check falls within this area.
     */
    public function covers(string $diagnosticId, DiagnosticCategory $category): bool
    {
        return $this->diagnosticId !== null
            ? $this->diagnosticId === $diagnosticId
            : $this->category === $category;
    }

    /**
     * What to call the area when no check covers it.
     */
    public function label(): string
    {
        return $this->diagnosticId ?? $this->category?->label() ?? '';
    }
}
