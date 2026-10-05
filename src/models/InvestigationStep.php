<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\InvestigationStepType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\InvestigationStepRecord;

/**
 * One entry in an investigation's timeline: that it started, what it chose to look at, what one
 * check reported and the evidence it left, what else was open nearby, how it ended.
 *
 * Evidence is rebuilt through {@see Evidence::fromArray()}, so what is read back is redacted and
 * bounded by the rules that apply now, whatever wrote it.
 */
final class InvestigationStep
{
    use NamesUnreadable;

    /**
     * @param list<Evidence> $evidence What the check recorded, as much of it as was kept.
     * @param int $evidenceCount How much the check recorded, kept or not.
     * @param bool $evidenceTruncated Whether the investigation's evidence budget left some out.
     */
    private function __construct(
        public readonly int $id,
        public readonly int $investigationId,
        public readonly int $position,
        public readonly ?InvestigationStepType $type,
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
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly array $unreadable = [],
    ) {
    }

    public static function fromRecord(InvestigationStepRecord $record): self
    {
        $evidence = [];
        $evidenceRead = true;

        if ($record->evidence !== null) {
            $decoded = json_decode((string)$record->evidence, true);
            $evidenceRead = is_array($decoded);

            foreach (is_array($decoded) ? $decoded : [] as $item) {
                if (!is_array($item)) {
                    $evidenceRead = false;

                    continue;
                }

                $evidence[] = Evidence::fromArray($item);
            }
        }

        $type = InvestigationStepType::tryFrom((string)$record->type);
        $status = $record->status === null ? null : DiagnosticStatus::tryFrom((string)$record->status);
        $severity = $record->severity === null ? null : Severity::tryFrom((string)$record->severity);
        $occurredAt = StoredTime::read($record->occurredAt);

        return new self(
            id: (int)$record->id,
            investigationId: (int)$record->investigationId,
            position: (int)$record->position,
            // A step this version does not know is said to be unreadable, never shown as a check
            // that ran: it is null, named in `$unreadable`, and counted as nothing.
            type: $type,
            diagnosticId: $record->diagnosticId,
            diagnosticName: $record->diagnosticName,
            status: $status,
            severity: $severity,
            summary: $record->summary,
            note: $record->note,
            relatedIssueId: $record->relatedIssueId === null ? null : (int)$record->relatedIssueId,
            evidence: $evidence,
            evidenceCount: (int)$record->evidenceCount,
            evidenceTruncated: (bool)$record->evidenceTruncated,
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
            occurredAt: $occurredAt,
            unreadable: array_keys(array_filter([
                'type' => $type === null,
                'status' => $record->status !== null && $status === null,
                'severity' => $record->severity !== null && $severity === null,
                'occurredAt' => $occurredAt === null,
                'evidence' => !$evidenceRead,
            ])),
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
}
