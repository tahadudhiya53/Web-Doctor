<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use DateTimeZone;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use Throwable;

/**
 * A fact kept against an issue: the evidence itself, and how long and how often it has been seen.
 *
 * The fact stays an {@see Evidence}, rebuilt through its constructor — so what comes back out of
 * the database is redacted and bounded by exactly the rules that applied on the way in, even if
 * a row was written by something that did not apply them.
 */
final class StoredEvidence
{
    private function __construct(
        public readonly int $id,
        public readonly int $issueId,
        public readonly Evidence $evidence,
        public readonly string $digest,
        public readonly int $occurrences,
        public readonly DateTimeImmutable $firstSeen,
        public readonly DateTimeImmutable $lastSeen,
        public readonly ?string $firstRunId,
        public readonly ?string $lastRunId,
    ) {
    }

    /**
     * Reads a stored row. A type or confidence this version does not have falls back rather than
     * throwing, for the reason {@see Issue::fromRecord()} gives; a type falls back to one that is
     * withheld from clients, so not knowing what a fact is never makes it more visible.
     */
    public static function fromRecord(EvidenceRecord $record): self
    {
        $evidence = new Evidence(
            type: EvidenceType::tryFrom((string)$record->type) ?? EvidenceType::CONFIGURATION,
            label: (string)$record->label,
            source: (string)$record->source,
            data: self::decode($record->data),
            observedAt: self::time($record->observedAt),
            recordedAt: self::time($record->lastSeen),
            metadata: self::decode($record->metadata),
            reference: $record->reference,
            confidence: $record->confidence === null ? null : Confidence::tryFrom((string)$record->confidence),
            truncated: (bool)$record->truncated,
            diagnosticId: (string)$record->diagnosticId,
            runId: $record->lastRunId,
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
        );

        return new self(
            id: (int)$record->id,
            issueId: (int)$record->issueId,
            evidence: $evidence,
            digest: (string)$record->digest,
            occurrences: (int)$record->occurrences,
            firstSeen: self::time($record->firstSeen) ?? new DateTimeImmutable(),
            lastSeen: self::time($record->lastSeen) ?? new DateTimeImmutable(),
            firstRunId: $record->firstRunId,
            lastRunId: $record->lastRunId,
        );
    }

    /**
     * The fact, arranged for a reader, with every withheld value marked as withheld.
     *
     * @return array<string, mixed>
     */
    public function dataTree(): array
    {
        return EvidenceDisplay::tree($this->evidence->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataTree(): array
    {
        return EvidenceDisplay::tree($this->evidence->metadata);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function time(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Stored the way Craft stores every date: UTC.
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
