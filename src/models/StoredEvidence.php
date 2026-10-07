<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\helpers\StoredJson;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;

/**
 * A fact kept against an issue: the evidence itself, and how long and how often it has been seen.
 *
 * The fact stays an {@see Evidence}, rebuilt through its constructor — so what comes back out of
 * the database is redacted and bounded by exactly the rules that applied on the way in, even if
 * a row was written by something that did not apply them.
 */
final class StoredEvidence
{
    use NamesUnreadable;

    private function __construct(
        public readonly int $id,
        public readonly int $issueId,
        public readonly Evidence $evidence,
        public readonly string $digest,
        public readonly int $occurrences,
        public readonly ?DateTimeImmutable $firstSeen,
        public readonly ?DateTimeImmutable $lastSeen,
        public readonly ?string $firstRunId,
        public readonly ?string $lastRunId,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Reads a stored row, strictly. A type, confidence or moment this version cannot read, or data
     * that is not what was written, is named in `$unreadable` and the page says so. The fact itself
     * still needs a type to be carried in, and takes one that is withheld from clients, so not knowing
     * what a fact is never makes it more visible — but it is never shown as that type. Its moments
     * are never replaced by now, and the fact is marked partial when any part could not be read.
     */
    public static function fromRecord(EvidenceRecord $record): self
    {
        $type = EvidenceType::tryFrom((string)$record->type);
        $confidence = $record->confidence === null ? null : Confidence::tryFrom((string)$record->confidence);
        $observedAt = StoredTime::readOptional($record->observedAt);
        $firstSeen = StoredTime::read($record->firstSeen);
        $lastSeen = StoredTime::read($record->lastSeen);
        [$data, $dataRead] = StoredJson::decode($record->data);
        [$metadata, $metadataRead] = StoredJson::decode($record->metadata);
        $unreadable = [];

        foreach ([
            'type' => $type === null,
            'confidence' => $record->confidence !== null && $confidence === null,
            'observedAt' => $observedAt === false,
            'firstSeen' => $firstSeen === null,
            'lastSeen' => $lastSeen === null,
            'data' => !$dataRead,
            'metadata' => !$metadataRead,
        ] as $field => $broken) {
            if ($broken) {
                $unreadable[] = $field;
            }
        }

        $evidence = new Evidence(
            type: $type ?? EvidenceType::CONFIGURATION,
            label: (string)$record->label,
            source: (string)$record->source,
            data: $data,
            observedAt: $observedAt === false ? null : $observedAt,
            recordedAt: $lastSeen ?? false,
            metadata: $metadata,
            reference: $record->reference,
            confidence: $confidence,
            truncated: (bool)$record->truncated || $unreadable !== [],
            diagnosticId: (string)$record->diagnosticId,
            runId: $record->lastRunId,
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            typeKnown: $type !== null,
            readable: $dataRead && $metadataRead,
        );

        return new self(
            id: (int)$record->id,
            issueId: (int)$record->issueId,
            evidence: $evidence,
            digest: (string)$record->digest,
            occurrences: (int)$record->occurrences,
            firstSeen: $firstSeen,
            lastSeen: $lastSeen,
            firstRunId: $record->firstRunId,
            lastRunId: $record->lastRunId,
            unreadable: $unreadable,
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
}
