<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\IssueResolution;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\records\IssueRecord;

/**
 * A technical problem Web Doctor has detected, as it stands now: the problem across every run
 * that found it, how often and how long it has been seen, and whatever anybody decided about it.
 *
 * Immutable. The service reads a record and hands out one of these, so nothing downstream holds
 * an object it could save by accident.
 */
final class Issue implements JsonSerializable
{
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
        public readonly DiagnosticCategory $category,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $recommendation,
        public readonly Severity $severity,
        public readonly IssueStatus $status,
        public readonly IssueResolution $resolution,
        public readonly DiagnosticStatus $resultStatus,
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
        public readonly DateTimeImmutable $firstDetected,
        public readonly DateTimeImmutable $lastDetected,
        public readonly ?DateTimeImmutable $resolvedAt,
        public readonly ?string $statusNote,
        public readonly ?DateTimeImmutable $statusChangedAt,
        public readonly ?int $statusChangedBy,
        public readonly string $uid,
    ) {
    }

    /**
     * Reads a stored row. Enums are resolved defensively: a value this version does not have must
     * not take the Issue Center down with a `ValueError`, and an unreadable status reads as
     * outstanding rather than as closed.
     */
    public static function fromRecord(IssueRecord $record): self
    {
        return new self(
            id: (int)$record->id,
            fingerprint: (string)$record->fingerprint,
            diagnosticId: (string)$record->diagnosticId,
            diagnosticName: (string)$record->diagnosticName,
            category: DiagnosticCategory::tryFrom((string)$record->category) ?? DiagnosticCategory::CONFIGURATION,
            title: (string)$record->title,
            description: (string)($record->description ?? ''),
            recommendation: $record->recommendation,
            severity: Severity::tryFrom((string)$record->severity) ?? Severity::MEDIUM,
            status: IssueStatus::tryFrom((string)$record->status) ?? IssueStatus::NEW,
            resolution: IssueResolution::tryFrom((string)$record->resolution) ?? IssueResolution::NONE,
            resultStatus: DiagnosticStatus::tryFrom((string)$record->resultStatus) ?? DiagnosticStatus::UNKNOWN,
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            siteName: $record->siteName,
            affectedComponent: $record->affectedComponent,
            affectedPlugin: $record->affectedPlugin,
            latestResult: self::decode($record->latestResult),
            firstRunId: $record->firstRunId,
            latestRunId: $record->latestRunId,
            resolvedByRunId: $record->resolvedByRunId,
            occurrences: (int)$record->occurrences,
            firstDetected: self::time($record->firstDetected) ?? new DateTimeImmutable(),
            lastDetected: self::time($record->lastDetected) ?? new DateTimeImmutable(),
            resolvedAt: self::time($record->resolvedAt),
            statusNote: $record->statusNote,
            statusChangedAt: self::time($record->statusChangedAt),
            statusChangedBy: $record->statusChangedBy === null ? null : (int)$record->statusChangedBy,
            uid: (string)$record->uid,
        );
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Whether this issue has been seen more than once. */
    public function isRecurring(): bool
    {
        return $this->occurrences > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'diagnosticId' => $this->diagnosticId,
            'diagnosticName' => $this->diagnosticName,
            'category' => $this->category->value,
            'title' => $this->title,
            'description' => $this->description,
            'recommendation' => $this->recommendation,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'resolution' => $this->resolution->value,
            'resultStatus' => $this->resultStatus->value,
            'environment' => $this->environment,
            'siteId' => $this->siteId,
            'siteName' => $this->siteName,
            'affectedComponent' => $this->affectedComponent,
            'affectedPlugin' => $this->affectedPlugin,
            'occurrences' => $this->occurrences,
            'firstDetected' => $this->firstDetected->format(DATE_ATOM),
            'lastDetected' => $this->lastDetected->format(DATE_ATOM),
            'resolvedAt' => $this->resolvedAt?->format(DATE_ATOM),
            'firstRunId' => $this->firstRunId,
            'latestRunId' => $this->latestRunId,
            'resolvedByRunId' => $this->resolvedByRunId,
            'latestResult' => $this->latestResult,
        ];
    }

    /**
     * @return array<string, mixed>
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
            return new DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
