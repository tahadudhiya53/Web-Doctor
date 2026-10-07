<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\IssueEventType;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;

/**
 * One thing that happened to an issue.
 *
 * Append-only and never edited, so the history says what was actually done rather than what the
 * current state implies: an issue ignored twice before being acted on is a different story from
 * one nobody ever saw.
 */
final class IssueEvent
{
    use NamesUnreadable;

    /**
     * @param list<string> $unreadable The fields the stored row held something other than a value
     * of; each is null rather than a guess.
     */
    private function __construct(
        public readonly int $id,
        public readonly int $issueId,
        public readonly ?IssueEventType $type,
        public readonly ?IssueStatus $fromStatus,
        public readonly ?IssueStatus $toStatus,
        public readonly ?Severity $severity,
        public readonly ?string $note,
        public readonly ?string $runId,
        public readonly ?int $userId,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Reads a stored row, strictly. A kind of event, a status or a severity this version does not
     * know is not read as some other one, and a moment that is not one is not read as now: each is
     * null and named in `$unreadable`, and the history says it cannot be read. An empty status or
     * severity column is what was written where there was none.
     */
    public static function fromRecord(IssueEventRecord $record): self
    {
        $type = IssueEventType::tryFrom((string)$record->type);
        $from = $record->fromStatus === null ? null : IssueStatus::tryFrom($record->fromStatus);
        $to = $record->toStatus === null ? null : IssueStatus::tryFrom($record->toStatus);
        $severity = $record->severity === null ? null : Severity::tryFrom($record->severity);
        $occurredAt = StoredTime::read($record->dateCreated);
        $unreadable = [];

        foreach ([
            'type' => $type === null,
            'fromStatus' => $record->fromStatus !== null && $from === null,
            'toStatus' => $record->toStatus !== null && $to === null,
            'severity' => $record->severity !== null && $severity === null,
            'occurredAt' => $occurredAt === null,
        ] as $field => $broken) {
            if ($broken) {
                $unreadable[] = $field;
            }
        }

        return new self(
            id: (int)$record->id,
            issueId: (int)$record->issueId,
            type: $type,
            fromStatus: $from,
            toStatus: $to,
            severity: $severity,
            note: $record->note,
            runId: $record->runId,
            userId: $record->userId === null ? null : (int)$record->userId,
            occurredAt: $occurredAt,
            unreadable: $unreadable,
        );
    }
}
