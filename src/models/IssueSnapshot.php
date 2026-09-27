<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;

/**
 * An issue as root-cause analysis sees it: what it is about, where, and its history — when it was
 * first seen and how often since.
 *
 * Kept apart from {@see Issue} so the analysis depends on the facts it weighs and nothing else,
 * and can be stated outright by a test rather than read from a table.
 */
final class IssueSnapshot
{
    /**
     * @param bool $newThisRun Whether the run being analysed is the one that first found it. When
     * a problem started is then unknown — it was found now because it was looked for now — so it
     * says nothing about what else began at the same time.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $diagnosticId,
        public readonly DiagnosticCategory $category,
        public readonly string $title,
        public readonly Severity $severity,
        public readonly IssueStatus $status,
        public readonly DateTimeImmutable $firstDetected,
        public readonly int $occurrences = 1,
        public readonly ?string $affectedPlugin = null,
        public readonly ?string $affectedComponent = null,
        public readonly bool $newThisRun = false,
    ) {
    }

    public static function fromIssue(Issue $issue, ?string $runId = null): self
    {
        return new self(
            id: $issue->id,
            diagnosticId: $issue->diagnosticId,
            category: $issue->category,
            title: $issue->title,
            severity: $issue->severity,
            status: $issue->status,
            firstDetected: $issue->firstDetected,
            occurrences: $issue->occurrences,
            affectedPlugin: $issue->affectedPlugin,
            affectedComponent: $issue->affectedComponent,
            newThisRun: $runId !== null && $issue->firstRunId === $runId,
        );
    }
}
