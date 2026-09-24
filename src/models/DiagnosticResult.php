<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;

/**
 * What one diagnostic concluded, and what it concluded it from.
 *
 * A result is structured rather than a sentence, because everything downstream reads it rather
 * than a person: health aggregation, the issue it may raise, the evidence it is traced to, the
 * recommendation offered, the repair that may be available and the verification that follows
 * one. The summary is for a reader; the rest is for Web Doctor.
 *
 * Results are immutable. The engine stamps identity and timing onto a copy once the diagnostic
 * has finished, so a diagnostic cannot claim a run it was not part of or a duration it did not
 * take.
 */
final class DiagnosticResult implements JsonSerializable
{
    /**
     * @param string $diagnosticId Which diagnostic produced this.
     * @param string $name The diagnostic's name, as a reader sees it.
     * @param DiagnosticCategory $category The part of the installation this is about.
     * @param DiagnosticStatus $status What happened when the check ran.
     * @param string $summary One line, for a person.
     * @param Severity|null $severity How much this matters; defaults to what the status implies.
     * @param string $description The longer explanation, where one helps.
     * @param Evidence[] $evidence The facts behind the conclusion.
     * @param string|null $recommendation What to do about it.
     * @param Confidence $confidence How firmly the conclusion is held.
     * @param string|null $affectedComponent What is affected, named in Web Doctor's own terms.
     * @param string|null $affectedPlugin The handle of the plugin at fault, where one is.
     * @param bool $repairAvailable Whether a repair exists for this. Never whether to run one.
     * @param bool $verificationAvailable Whether a repair of this can be verified afterwards.
     * @param string|null $environment Which environment this was found in.
     * @param string|null $runId The run this belongs to; stamped by the engine.
     * @param float|null $durationMs How long the check took; stamped by the engine.
     */
    public function __construct(
        public readonly string $diagnosticId,
        public readonly string $name,
        public readonly DiagnosticCategory $category,
        public readonly DiagnosticStatus $status,
        public readonly string $summary = '',
        public readonly ?Severity $severity = null,
        public readonly string $description = '',
        public readonly array $evidence = [],
        public readonly ?string $recommendation = null,
        public readonly Confidence $confidence = Confidence::INFORMATIONAL,
        public readonly ?string $affectedComponent = null,
        public readonly ?string $affectedPlugin = null,
        public readonly bool $repairAvailable = false,
        public readonly bool $verificationAvailable = false,
        public readonly ?string $environment = null,
        public readonly ?string $runId = null,
        public readonly ?DateTimeImmutable $startedAt = null,
        public readonly ?DateTimeImmutable $finishedAt = null,
        public readonly ?float $durationMs = null,
    ) {
    }

    /**
     * How much this matters. A diagnostic that states nothing gets what its status implies,
     * so severity is always answerable without the caller having to handle its absence.
     */
    public function severity(): Severity
    {
        return $this->severity ?? $this->status->defaultSeverity();
    }

    /**
     * @return Evidence[]
     */
    public function evidence(): array
    {
        return $this->evidence;
    }

    public function hasEvidence(): bool
    {
        return $this->evidence !== [];
    }

    /**
     * Stamps the run this result belongs to and what it cost. Only the engine calls this: it is
     * what ties a result to an execution rather than letting a diagnostic assert one.
     */
    public function withExecution(
        DiagnosticContext $context,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        float $durationMs,
    ): self {
        return $this->copy(
            environment: $context->environment,
            runId: $context->runId,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            // A clock that went backwards mid-run must not produce a negative duration, which
            // would then be summed, averaged and reported as nonsense.
            durationMs: max(0.0, $durationMs),
        );
    }

    /**
     * Produces the same result with named fields changed.
     *
     * Every field is passed through explicitly. Copying by reflecting over the object's
     * properties would be shorter, but it would also keep compiling after a field was added or
     * renamed and silently drop it here — and a field silently lost from a result is a finding
     * silently lost from a report.
     *
     * `severity` is nullable on purpose: null means "whatever the status implies". It is
     * carried across as it stands rather than resolved, so a default never freezes into a
     * statement the diagnostic did not make.
     *
     * @param Evidence[]|null $evidence
     */
    private function copy(
        ?array $evidence = null,
        ?string $environment = null,
        ?string $runId = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?float $durationMs = null,
    ): self {
        return new self(
            diagnosticId: $this->diagnosticId,
            name: $this->name,
            category: $this->category,
            status: $this->status,
            summary: $this->summary,
            severity: $this->severity,
            description: $this->description,
            evidence: $evidence ?? $this->evidence,
            recommendation: $this->recommendation,
            confidence: $this->confidence,
            affectedComponent: $this->affectedComponent,
            affectedPlugin: $this->affectedPlugin,
            repairAvailable: $this->repairAvailable,
            verificationAvailable: $this->verificationAvailable,
            environment: $environment ?? $this->environment,
            runId: $runId ?? $this->runId,
            startedAt: $startedAt ?? $this->startedAt,
            finishedAt: $finishedAt ?? $this->finishedAt,
            durationMs: $durationMs ?? $this->durationMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'diagnosticId' => $this->diagnosticId,
            'name' => $this->name,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'severity' => $this->severity()->value,
            'summary' => $this->summary,
            'description' => $this->description,
            'recommendation' => $this->recommendation,
            'confidence' => $this->confidence->value,
            'affectedComponent' => $this->affectedComponent,
            'affectedPlugin' => $this->affectedPlugin,
            'repairAvailable' => $this->repairAvailable,
            'verificationAvailable' => $this->verificationAvailable,
            'environment' => $this->environment,
            'runId' => $this->runId,
            'startedAt' => $this->startedAt?->format(DATE_ATOM),
            'finishedAt' => $this->finishedAt?->format(DATE_ATOM),
            'durationMs' => $this->durationMs,
            'evidence' => array_map(static fn(Evidence $e): array => $e->jsonSerialize(), $this->evidence),
        ];
    }
}
