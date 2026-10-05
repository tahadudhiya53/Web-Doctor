<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\IssueResolution;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\StoredJson;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\IssueRecord;

/**
 * A technical problem Web Doctor has detected, as it stands now: the problem across every run
 * that found it, how often and how long it has been seen, and whatever anybody decided about it.
 *
 * Immutable. The service reads a record and hands out one of these, so nothing downstream holds
 * an object it could save by accident.
 */
final class Issue
{
    use NamesUnreadable;

    /**
     * @param string $fingerprint What makes this problem this problem.
     * @param string|null $siteName The site's name when the issue was found, kept so a deleted
     * site's findings do not read as ones made about the whole installation.
     * @param array<string, mixed> $latestResult The last finding, reduced to what is displayed.
     * Its evidence is kept apart, by {@see \Tahadudhiya\WebDoctor\services\EvidenceStore}.
     */
    private function __construct(
        public readonly int $id,
        public readonly string $fingerprint,
        public readonly string $diagnosticId,
        public readonly string $diagnosticName,
        public readonly ?DiagnosticCategory $category,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $recommendation,
        public readonly ?Severity $severity,
        public readonly ?IssueStatus $status,
        public readonly ?IssueResolution $resolution,
        public readonly ?DiagnosticStatus $resultStatus,
        public readonly string $environment,
        public readonly ?int $siteId,
        public readonly ?string $siteName,
        public readonly ?string $affectedComponent,
        public readonly ?string $affectedPlugin,
        public readonly array $latestResult,
        public readonly ?string $firstRunId,
        public readonly ?string $latestRunId,
        public readonly ?string $resolvedByRunId,
        public readonly int $occurrences,
        public readonly ?DateTimeImmutable $firstDetected,
        public readonly ?DateTimeImmutable $lastDetected,
        public readonly ?DateTimeImmutable $resolvedAt,
        public readonly ?string $statusNote,
        public readonly ?DateTimeImmutable $statusChangedAt,
        public readonly ?int $statusChangedBy,
        public readonly string $uid,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Reads a stored row, strictly. A category, severity, status, resolution or result this version
     * does not know, or a moment that is not one, is null and named in `$unreadable` — never read as
     * some other value, which would put the issue in a list, a count or a decision it does not
     * belong in. An issue that is not whole can be read, and nothing may change, investigate, repair
     * or verify it until it is.
     */
    public static function fromRecord(IssueRecord $record): self
    {
        $category = DiagnosticCategory::tryFrom((string)$record->category);
        $severity = Severity::tryFrom((string)$record->severity);
        $status = IssueStatus::tryFrom((string)$record->status);
        $resolution = IssueResolution::tryFrom((string)$record->resolution);
        $resultStatus = DiagnosticStatus::tryFrom((string)$record->resultStatus);
        $firstDetected = StoredTime::read($record->firstDetected);
        $lastDetected = StoredTime::read($record->lastDetected);
        $resolvedAt = StoredTime::readOptional($record->resolvedAt);
        $statusChangedAt = StoredTime::readOptional($record->statusChangedAt);
        [$latestResult, $latestRead] = StoredJson::decode($record->latestResult);
        $unreadable = [];

        foreach ([
            'category' => $category === null,
            'severity' => $severity === null,
            'status' => $status === null,
            'resolution' => $resolution === null,
            'resultStatus' => $resultStatus === null,
            'firstDetected' => $firstDetected === null,
            'lastDetected' => $lastDetected === null,
            'resolvedAt' => $resolvedAt === false,
            'statusChangedAt' => $statusChangedAt === false,
            'latestResult' => !$latestRead,
        ] as $field => $broken) {
            if ($broken) {
                $unreadable[] = $field;
            }
        }

        return new self(
            id: (int)$record->id,
            fingerprint: (string)$record->fingerprint,
            diagnosticId: (string)$record->diagnosticId,
            diagnosticName: (string)$record->diagnosticName,
            category: $category,
            title: (string)$record->title,
            description: (string)($record->description ?? ''),
            recommendation: $record->recommendation,
            severity: $severity,
            status: $status,
            resolution: $resolution,
            resultStatus: $resultStatus,
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            siteName: $record->siteName,
            affectedComponent: $record->affectedComponent,
            affectedPlugin: $record->affectedPlugin,
            latestResult: $latestResult,
            firstRunId: $record->firstRunId,
            latestRunId: $record->latestRunId,
            resolvedByRunId: $record->resolvedByRunId,
            occurrences: (int)$record->occurrences,
            firstDetected: $firstDetected,
            lastDetected: $lastDetected,
            resolvedAt: $resolvedAt === false ? null : $resolvedAt,
            statusNote: $record->statusNote,
            statusChangedAt: $statusChangedAt === false ? null : $statusChangedAt,
            statusChangedBy: $record->statusChangedBy === null ? null : (int)$record->statusChangedBy,
            uid: (string)$record->uid,
            unreadable: $unreadable,
        );
    }

    /** Whether every field was read back as it was written. */
    public function isIntact(): bool
    {
        return $this->unreadable === [];
    }

    /**
     * Why nothing may act on this issue, or null where something may: one that cannot be read in
     * full cannot be told to be in a state any act requires.
     */
    public function integrityRefusal(): ?string
    {
        return $this->isIntact() ? null : \Craft::t('web-doctor', 'This issue’s record cannot be read in full ({fields}), so it can be read but not changed, investigated, repaired or verified.', [
            'fields' => implode(', ', $this->unreadable),
        ]);
    }

    /** Whether it is outstanding. One whose status cannot be read is not known to be. */
    public function isOpen(): bool
    {
        return $this->status?->isOpen() ?? false;
    }
}
