<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;

/**
 * One check that ran into an error: how often, when, what its result was the last time, and the
 * issue that check's findings are recorded on, if there is one.
 */
final class ErrorSource
{
    use NamesUnreadable;

    private function __construct(
        public readonly int $errorGroupId,
        public readonly string $diagnosticId,
        public readonly string $diagnosticName,
        public readonly ?int $issueId,
        public readonly ?DiagnosticStatus $lastStatus,
        public readonly int $occurrences,
        public readonly ?DateTimeImmutable $firstSeen,
        public readonly ?DateTimeImmutable $lastSeen,
        public readonly ?string $lastRunId,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Reads a stored row, strictly: a status this version does not have, or a moment that is not one,
     * is null and named in `$unreadable` — never "could not tell", never now.
     */
    public static function fromRecord(ErrorSourceRecord $record): self
    {
        $status = DiagnosticStatus::tryFrom((string)$record->lastStatus);
        $firstSeen = StoredTime::read($record->firstSeen);
        $lastSeen = StoredTime::read($record->lastSeen);
        $unreadable = [];

        foreach ([
            'lastStatus' => $status === null,
            'firstSeen' => $firstSeen === null,
            'lastSeen' => $lastSeen === null,
        ] as $field => $broken) {
            if ($broken) {
                $unreadable[] = $field;
            }
        }

        return new self(
            errorGroupId: (int)$record->errorGroupId,
            diagnosticId: (string)$record->diagnosticId,
            diagnosticName: (string)$record->diagnosticName ?: (string)$record->diagnosticId,
            issueId: $record->issueId === null ? null : (int)$record->issueId,
            lastStatus: $status,
            occurrences: (int)$record->occurrences,
            firstSeen: $firstSeen,
            lastSeen: $lastSeen,
            lastRunId: $record->lastRunId,
            unreadable: $unreadable,
        );
    }
}
