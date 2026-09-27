<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use DateTimeZone;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;
use Throwable;

/**
 * One check that ran into an error: how often, when, what its result was the last time, and the
 * issue that check's findings are recorded on, if there is one.
 */
final class ErrorSource
{
    private function __construct(
        public readonly int $errorGroupId,
        public readonly string $diagnosticId,
        public readonly string $diagnosticName,
        public readonly ?int $issueId,
        public readonly DiagnosticStatus $lastStatus,
        public readonly int $occurrences,
        public readonly DateTimeImmutable $firstSeen,
        public readonly DateTimeImmutable $lastSeen,
        public readonly ?string $lastRunId,
    ) {
    }

    /**
     * Reads a stored row. A status this version does not have reads as "could not tell", never as
     * something the check concluded.
     */
    public static function fromRecord(ErrorSourceRecord $record): self
    {
        return new self(
            errorGroupId: (int)$record->errorGroupId,
            diagnosticId: (string)$record->diagnosticId,
            diagnosticName: (string)$record->diagnosticName ?: (string)$record->diagnosticId,
            issueId: $record->issueId === null ? null : (int)$record->issueId,
            lastStatus: DiagnosticStatus::tryFrom((string)$record->lastStatus) ?? DiagnosticStatus::UNKNOWN,
            occurrences: (int)$record->occurrences,
            firstSeen: self::time($record->firstSeen) ?? new DateTimeImmutable(),
            lastSeen: self::time($record->lastSeen) ?? new DateTimeImmutable(),
            lastRunId: $record->lastRunId,
        );
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
