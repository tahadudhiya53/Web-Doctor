<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use JsonSerializable;

/**
 * One execution of one or more diagnostics, and its identity.
 *
 * The run is what everything downstream hangs off. An issue records the run it was detected in,
 * evidence is correlated within a run, history compares one run with another, a report renders
 * one, and a scheduled or deployment check is a run someone else started. So the run ID is
 * generated once — on the context — and every result in the run carries it.
 */
final class DiagnosticRun implements JsonSerializable
{
    private ?HealthSummary $health = null;

    /**
     * @param DiagnosticResult[] $results
     */
    public function __construct(
        public readonly DiagnosticContext $context,
        public readonly array $results,
        public readonly DateTimeImmutable $startedAt,
        public readonly DateTimeImmutable $finishedAt,
        public readonly float $durationMs,
    ) {
    }

    /**
     * The identity this run is referred to by, everywhere.
     */
    public function id(): string
    {
        return $this->context->runId;
    }

    /**
     * @return DiagnosticResult[]
     */
    public function results(): array
    {
        return $this->results;
    }

    public function count(): int
    {
        return count($this->results);
    }

    public function resultFor(string $diagnosticId): ?DiagnosticResult
    {
        foreach ($this->results as $result) {
            if ($result->diagnosticId === $diagnosticId) {
                return $result;
            }
        }

        return null;
    }

    public function health(): HealthSummary
    {
        return $this->health ??= HealthSummary::fromResults($this->results);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'runId' => $this->id(),
            'context' => $this->context->jsonSerialize(),
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'finishedAt' => $this->finishedAt->format(DATE_ATOM),
            'durationMs' => $this->durationMs,
            'health' => $this->health()->jsonSerialize(),
            'results' => array_map(static fn(DiagnosticResult $r): array => $r->jsonSerialize(), $this->results),
        ];
    }
}
