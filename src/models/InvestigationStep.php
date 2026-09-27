<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use DateTimeZone;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\InvestigationStepType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\records\InvestigationStepRecord;
use Throwable;

/**
 * One entry in an investigation's timeline: that it started, what it chose to look at, what one
 * check reported and the evidence it left, what else was open nearby, how it ended.
 *
 * Evidence is rebuilt through {@see Evidence::fromArray()}, so what is read back is redacted and
 * bounded by the rules that apply now, whatever wrote it.
 */
final class InvestigationStep
{
    /**
     * @param list<Evidence> $evidence What the check recorded, as much of it as was kept.
     * @param int $evidenceCount How much the check recorded, kept or not.
     * @param bool $evidenceTruncated Whether the investigation's evidence budget left some out.
     */
    private function __construct(
        public readonly int $id,
        public readonly int $investigationId,
        public readonly int $position,
        public readonly InvestigationStepType $type,
        public readonly ?string $diagnosticId,
        public readonly ?string $diagnosticName,
        public readonly ?DiagnosticStatus $status,
        public readonly ?Severity $severity,
        public readonly ?string $summary,
        public readonly ?string $note,
        public readonly ?int $relatedIssueId,
        public readonly array $evidence,
        public readonly int $evidenceCount,
        public readonly bool $evidenceTruncated,
        public readonly ?float $durationMs,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    public static function fromRecord(InvestigationStepRecord $record): self
    {
        $evidence = [];

        if (is_string($record->evidence) && $record->evidence !== '') {
            $decoded = json_decode($record->evidence, true);

            foreach (is_array($decoded) ? $decoded : [] as $item) {
                if (is_array($item)) {
                    $evidence[] = Evidence::fromArray($item);
                }
            }
        }

        return new self(
            id: (int)$record->id,
            investigationId: (int)$record->investigationId,
            position: (int)$record->position,
            // A step this version does not know is shown as the neutral "check ran" rather than
            // taking the whole timeline down with a ValueError.
            type: InvestigationStepType::tryFrom((string)$record->type) ?? InvestigationStepType::CHECKED,
            diagnosticId: $record->diagnosticId,
            diagnosticName: $record->diagnosticName,
            status: $record->status === null ? null : DiagnosticStatus::tryFrom((string)$record->status),
            severity: $record->severity === null ? null : Severity::tryFrom((string)$record->severity),
            summary: $record->summary,
            note: $record->note,
            relatedIssueId: $record->relatedIssueId === null ? null : (int)$record->relatedIssueId,
            evidence: $evidence,
            evidenceCount: (int)$record->evidenceCount,
            evidenceTruncated: (bool)$record->evidenceTruncated,
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
            occurredAt: self::time($record->occurredAt) ?? new DateTimeImmutable(),
        );
    }

    /** Whether the check this step records reported a problem with the site. */
    public function isFinding(): bool
    {
        return $this->type === InvestigationStepType::CHECKED && $this->status?->isProblem() === true;
    }

    /** Whether the check this step records broke or could not tell. */
    public function isIncomplete(): bool
    {
        return $this->type === InvestigationStepType::CHECKED
            && ($this->status === null || $this->status === DiagnosticStatus::ERROR || $this->status === DiagnosticStatus::UNKNOWN);
    }

    /**
     * The evidence arranged for a reader, every withheld value marked as withheld.
     *
     * @return list<array{evidence: Evidence, data: array<string, mixed>, metadata: array<string, mixed>}>
     */
    public function evidenceItems(): array
    {
        return array_map(static fn(Evidence $evidence): array => [
            'evidence' => $evidence,
            'data' => EvidenceDisplay::tree($evidence->data),
            'metadata' => EvidenceDisplay::tree($evidence->metadata),
        ], $this->evidence);
    }

    private static function time(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
