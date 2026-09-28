<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use DateTimeImmutable;
use DateTimeZone;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\records\RepairRecord;
use Throwable;

/**
 * One repair of an issue, as it stands: the action, its risk, what it would do, what had to be true
 * first and who confirmed it, then what it did, the state before and after, and whether anything has
 * verified that it worked.
 *
 * Immutable, and read from its row. What the action said about itself — its name, risk and how to
 * verify it — is kept on the row as it was, so a repair still reads correctly after the action has
 * changed or gone.
 */
final class Repair
{
    /**
     * @var int How long a preview may be confirmed for, in seconds. What it describes is read live,
     * and an old preview is one nobody should be confirming.
     */
    public const PREVIEW_EXPIRES_AFTER = 900;

    /**
     * @var int How long a repair may be under way before it is read as stopped, in seconds. A request
     * that died part-way leaves one running; after this it says so, and a later repair of the same
     * kind may take its place.
     */
    public const STOPPED_AFTER = 3600;

    /**
     * @param list<string> $verifyWith
     * @param list<Prerequisite> $prerequisites As they were read when it was previewed.
     * @param list<string> $acknowledged The prerequisites the person who confirmed it acknowledged.
     */
    private function __construct(
        public readonly int $id,
        public readonly ?int $issueId,
        public readonly string $issueTitle,
        public readonly string $diagnosticId,
        public readonly string $action,
        public readonly string $actionName,
        public readonly RepairRisk $risk,
        public readonly string $riskReason,
        public readonly RepairStatus $status,
        public readonly VerificationStatus $verificationStatus,
        public readonly array $verifyWith,
        public readonly string $verificationNote,
        public readonly string $environment,
        public readonly ?int $siteId,
        public readonly RepairReport $preview,
        public readonly string $fingerprint,
        public readonly string $definitionFingerprint,
        public readonly ?string $findingRunId,
        public readonly array $prerequisites,
        public readonly array $acknowledged,
        public readonly ?RepairReport $outcome,
        public readonly ?string $failure,
        public readonly ?IssueStatus $issueStatusBefore,
        public readonly ?int $previewedBy,
        public readonly ?int $executedBy,
        public readonly DateTimeImmutable $previewedAt,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly ?float $durationMs,
        private readonly bool $intact,
    ) {
    }

    /**
     * Reads a stored row. Anything unreadable reads as the more cautious answer: an unknown risk as
     * high, an unknown status as already carried out, so a row this version cannot read is never
     * offered for confirmation.
     */
    public static function fromRecord(RepairRecord $record): self
    {
        $prerequisites = [];
        $stored = self::decode($record->prerequisites);

        foreach ($stored as $entry) {
            $prerequisite = is_array($entry) ? Prerequisite::fromArray($entry) : null;

            if ($prerequisite !== null) {
                $prerequisites[] = $prerequisite;
            }
        }

        $outcome = self::decode($record->outcome);
        $preview = RepairReport::fromArray(self::decode($record->preview));
        $verifyWith = self::decode($record->verifyWith);
        $acknowledged = self::decode($record->acknowledged);
        $hash = static fn(mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;

        // Whether every part a confirmation relies on was read back as it was written. Anything a
        // reader of this version cannot account for — an unknown status or risk, a prerequisite
        // or check that did not parse, a preview that disagrees with its own fingerprint — makes
        // the row history only: it can be read, never carried out.
        $intact = RepairStatus::tryFrom((string)$record->status) !== null
            && RepairRisk::tryFrom((string)$record->risk) !== null
            && $hash($record->fingerprint)
            && $hash($record->definitionFingerprint)
            && $preview->fingerprint !== null
            && hash_equals((string)$record->fingerprint, $preview->fingerprint)
            && array_is_list($stored)
            && count($prerequisites) === count($stored)
            && $verifyWith !== [] && array_is_list($verifyWith) && count(array_filter($verifyWith, 'is_string')) === count($verifyWith)
            && array_is_list($acknowledged) && count(array_filter($acknowledged, 'is_string')) === count($acknowledged)
            && trim((string)$record->environment) !== '';

        return new self(
            id: (int)$record->id,
            issueId: $record->issueId === null ? null : (int)$record->issueId,
            issueTitle: (string)$record->issueTitle,
            diagnosticId: (string)$record->diagnosticId,
            action: (string)$record->action,
            actionName: (string)$record->actionName,
            risk: RepairRisk::tryFrom((string)$record->risk) ?? RepairRisk::HIGH,
            riskReason: (string)($record->riskReason ?? ''),
            status: RepairStatus::tryFrom((string)$record->status) ?? RepairStatus::FAILED,
            verificationStatus: VerificationStatus::tryFrom((string)$record->verificationStatus) ?? VerificationStatus::NONE,
            verifyWith: array_values(array_filter($verifyWith, 'is_string')),
            verificationNote: (string)($record->verificationNote ?? ''),
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            preview: $preview,
            fingerprint: (string)$record->fingerprint,
            definitionFingerprint: (string)$record->definitionFingerprint,
            findingRunId: $record->findingRunId,
            prerequisites: $prerequisites,
            acknowledged: array_values(array_filter($acknowledged, 'is_string')),
            outcome: $outcome === [] ? null : RepairReport::fromArray($outcome),
            failure: $record->failure,
            issueStatusBefore: IssueStatus::tryFrom((string)$record->issueStatusBefore),
            previewedBy: $record->previewedBy === null ? null : (int)$record->previewedBy,
            executedBy: $record->executedBy === null ? null : (int)$record->executedBy,
            previewedAt: self::time($record->previewedAt) ?? new DateTimeImmutable('@0'),
            startedAt: self::time($record->startedAt),
            finishedAt: self::time($record->finishedAt),
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
            intact: $intact,
        );
    }

    /** Whether it was read back whole. A row that was not can be shown but never carried out. */
    public function isIntact(): bool
    {
        return $this->intact;
    }

    /** When the preview stops being confirmable. */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->previewedAt->modify(sprintf('+%d seconds', self::PREVIEW_EXPIRES_AFTER));
    }

    /** Whether the preview is too old to confirm. */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->status === RepairStatus::PREVIEWED
            && ($now ?? new DateTimeImmutable())->getTimestamp() > $this->expiresAt()->getTimestamp();
    }

    /** Whether it was left under way by a request that went away. */
    public function hasStopped(?DateTimeImmutable $now = null): bool
    {
        return $this->status === RepairStatus::RUNNING
            && $this->startedAt !== null
            && ($now ?? new DateTimeImmutable())->getTimestamp() - $this->startedAt->getTimestamp() > self::STOPPED_AFTER;
    }

    /** Whether somebody may still confirm it. */
    public function awaitsConfirmation(?DateTimeImmutable $now = null): bool
    {
        return $this->intact && $this->status === RepairStatus::PREVIEWED && !$this->isExpired($now);
    }

    /** Where it stands, as a reader should be told it. */
    public function statusLabel(?DateTimeImmutable $now = null): string
    {
        return match (true) {
            $this->isExpired($now) => Craft::t('web-doctor', 'Preview expired'),
            $this->hasStopped($now) => Craft::t('web-doctor', 'Stopped without an ending'),
            default => $this->status->label(),
        };
    }

    /**
     * The prerequisites a person has to acknowledge before confirming.
     *
     * @return list<Prerequisite>
     */
    public function toAcknowledge(): array
    {
        return array_values(array_filter($this->prerequisites, static fn(Prerequisite $p): bool => $p->needsAcknowledging()));
    }

    /** Whether every prerequisite it could check held when it was previewed. */
    public function checkedPrerequisitesMet(): bool
    {
        foreach ($this->prerequisites as $prerequisite) {
            if (!$prerequisite->needsAcknowledging() && !$prerequisite->met) {
                return false;
            }
        }

        return true;
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
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
