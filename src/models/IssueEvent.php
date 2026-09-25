<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\IssueEventType;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;

/**
 * One thing that happened to an issue.
 *
 * Append-only and never edited, so the history says what was actually done rather than what the
 * current state implies: an issue ignored twice before being acted on is a different story from
 * one nobody ever saw.
 */
final class IssueEvent implements JsonSerializable
{
    private function __construct(
        public readonly int $id,
        public readonly int $issueId,
        public readonly IssueEventType $type,
        public readonly ?IssueStatus $fromStatus,
        public readonly ?IssueStatus $toStatus,
        public readonly ?Severity $severity,
        public readonly ?string $note,
        public readonly ?string $runId,
        public readonly ?int $userId,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /** Reads a stored row. Enums resolve defensively: a history that cannot be read is lost. */
    public static function fromRecord(IssueEventRecord $record): self
    {
        return new self(
            id: (int)$record->id,
            issueId: (int)$record->issueId,
            type: IssueEventType::tryFrom((string)$record->type) ?? IssueEventType::CHANGED,
            fromStatus: $record->fromStatus === null ? null : IssueStatus::tryFrom($record->fromStatus),
            toStatus: $record->toStatus === null ? null : IssueStatus::tryFrom($record->toStatus),
            severity: $record->severity === null ? null : Severity::tryFrom($record->severity),
            note: $record->note,
            runId: $record->runId,
            userId: $record->userId === null ? null : (int)$record->userId,
            occurredAt: self::time($record->dateCreated),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'issueId' => $this->issueId,
            'type' => $this->type->value,
            'fromStatus' => $this->fromStatus?->value,
            'toStatus' => $this->toStatus?->value,
            'severity' => $this->severity?->value,
            'note' => $this->note,
            'runId' => $this->runId,
            'userId' => $this->userId,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    private static function time(?string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable((string)$value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return new DateTimeImmutable();
        }
    }
}
