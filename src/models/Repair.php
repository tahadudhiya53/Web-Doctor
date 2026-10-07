<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\helpers\StoredJson;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\RepairRecord;

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
    use NamesUnreadable;

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
        public readonly ?RepairRisk $risk,
        public readonly string $riskReason,
        public readonly ?RepairStatus $status,
        public readonly ?VerificationStatus $verificationStatus,
        public readonly array $verifyWith,
        public readonly string $verificationNote,
        public readonly string $environment,
        public readonly ?int $siteId,
        public readonly ?string $siteName,
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
        public readonly ?string $previewedByName,
        public readonly ?string $executedByName,
        public readonly ?DateTimeImmutable $previewedAt,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly ?float $durationMs,
        private readonly bool $intact,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Reads a stored row, strictly. A field that does not hold what Web Doctor writes — a status,
     * risk or verification answer this version does not know, a moment that is not one, a preview or
     * outcome that is not JSON — is null and named in `$unreadable`, never given a value it did not
     * have: a repair whose status cannot be read is not shown as failed, and one whose preview cannot
     * be read is not shown as having changed nothing. Such a row, like any that is not whole, can be
     * read as history and never carried out or verified.
     */
    public static function fromRecord(RepairRecord $record): self
    {
        $unreadable = [];
        [$stored, $prerequisitesRead] = StoredJson::decode($record->prerequisites);
        [$outcome, $outcomeRead] = StoredJson::decode($record->outcome);
        [$previewData, $previewRead] = StoredJson::decode($record->preview);
        [$verifyWith, $verifyWithRead] = StoredJson::decode($record->verifyWith);
        [$acknowledged, $acknowledgedRead] = StoredJson::decode($record->acknowledged);
        $prerequisites = [];

        foreach ($stored as $entry) {
            $prerequisite = is_array($entry) ? Prerequisite::fromArray($entry) : null;

            if ($prerequisite !== null) {
                $prerequisites[] = $prerequisite;
            }
        }

        $preview = RepairReport::fromArray($previewData);
        $previewWhole = $preview !== null;
        // A report that cannot be read is said to be unreadable, never shown as one that holds nothing.
        $preview ??= new RepairReport('');
        $outcomeReport = $outcome === [] ? null : RepairReport::fromArray($outcome);
        $status = RepairStatus::tryFrom((string)$record->status);
        $risk = RepairRisk::tryFrom((string)$record->risk);
        $verification = VerificationStatus::tryFrom((string)$record->verificationStatus);
        $before = $record->issueStatusBefore === null ? null : IssueStatus::tryFrom((string)$record->issueStatusBefore);
        $previewedAt = StoredTime::read($record->previewedAt);
        $startedAt = StoredTime::readOptional($record->startedAt);
        $finishedAt = StoredTime::readOptional($record->finishedAt);
        $hash = static fn(mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;

        foreach ([
            'status' => $status === null,
            'risk' => $risk === null,
            'verificationStatus' => $verification === null,
            'issueStatusBefore' => $record->issueStatusBefore !== null && $before === null,
            'previewedAt' => $previewedAt === null,
            'startedAt' => $startedAt === false,
            'finishedAt' => $finishedAt === false,
            'preview' => !$previewRead || $previewData === [] || !$previewWhole,
            'outcome' => !$outcomeRead || ($outcome !== [] && $outcomeReport === null),
            'prerequisites' => !$prerequisitesRead,
            'acknowledged' => !$acknowledgedRead,
            'verifyWith' => !$verifyWithRead,
        ] as $field => $broken) {
            if ($broken) {
                $unreadable[] = $field;
            }
        }

        // Whether every part a confirmation relies on was read back as it was written. Anything a
        // reader of this version cannot account for — an unreadable field, a prerequisite or check
        // that did not parse, a preview that disagrees with its own fingerprint — makes the row
        // history only: it can be read, never carried out.
        $intact = $unreadable === []
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
            risk: $risk,
            riskReason: (string)($record->riskReason ?? ''),
            status: $status,
            verificationStatus: $verification,
            verifyWith: array_values(array_filter($verifyWith, 'is_string')),
            verificationNote: (string)($record->verificationNote ?? ''),
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            siteName: self::name($record->siteName),
            preview: $preview,
            fingerprint: (string)$record->fingerprint,
            definitionFingerprint: (string)$record->definitionFingerprint,
            findingRunId: $record->findingRunId,
            prerequisites: $prerequisites,
            acknowledged: array_values(array_filter($acknowledged, 'is_string')),
            outcome: $outcomeReport,
            failure: $record->failure,
            issueStatusBefore: $before,
            previewedBy: $record->previewedBy === null ? null : (int)$record->previewedBy,
            executedBy: $record->executedBy === null ? null : (int)$record->executedBy,
            previewedByName: self::name($record->previewedByName),
            executedByName: self::name($record->executedByName),
            previewedAt: $previewedAt,
            startedAt: $startedAt === false ? null : $startedAt,
            finishedAt: $finishedAt === false ? null : $finishedAt,
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
            intact: $intact,
            unreadable: $unreadable,
        );
    }

    /** Where it was carried out, by the site's name as it was then. */
    public function siteLabel(): string
    {
        return SiteName::recorded($this->siteId, $this->siteName);
    }

    /** Whether it was read back whole. A row that was not can be shown but never carried out. */
    public function isIntact(): bool
    {
        return $this->intact;
    }

    /** When the preview stops being confirmable, or null where when it was made cannot be read. */
    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->previewedAt?->modify(sprintf('+%d seconds', self::PREVIEW_EXPIRES_AFTER));
    }

    /**
     * Whether the preview is too old to confirm. One whose moment cannot be read cannot be shown to
     * be recent, so it is expired.
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $expires = $this->expiresAt();

        return $this->status === RepairStatus::PREVIEWED
            && ($expires === null || ($now ?? new DateTimeImmutable())->getTimestamp() > $expires->getTimestamp());
    }

    /**
     * Whether it was left under way by a request that went away. One whose start cannot be read
     * cannot be shown to be recent either, and is read as stopped so its lock and its issue can be
     * recovered rather than held for ever.
     */
    public function hasStopped(?DateTimeImmutable $now = null): bool
    {
        if ($this->status !== RepairStatus::RUNNING) {
            return false;
        }

        if ($this->startedAt === null) {
            return $this->isUnreadable('startedAt');
        }

        return ($now ?? new DateTimeImmutable())->getTimestamp() - $this->startedAt->getTimestamp() > self::STOPPED_AFTER;
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
            $this->status === null => Craft::t('web-doctor', 'Could not be read'),
            $this->isExpired($now) => Craft::t('web-doctor', 'Preview expired'),
            $this->hasStopped($now) => Craft::t('web-doctor', 'Stopped without an ending'),
            default => $this->status->label(),
        };
    }

    /** Who previewed it, as a reader is told: their username as it was, or that the account has gone. */
    public function previewedByLabel(): ?string
    {
        return self::userLabel($this->previewedBy, $this->previewedByName);
    }

    /** Who carried it out, or null where nobody has. */
    public function executedByLabel(): ?string
    {
        return self::userLabel($this->executedBy, $this->executedByName);
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

    private static function userLabel(?int $id, ?string $name): ?string
    {
        return match (true) {
            $name !== null && $id !== null => $name,
            $name !== null => Craft::t('web-doctor', '{name} (deleted)', ['name' => $name]),
            $id !== null => Craft::t('web-doctor', 'User #{id}', ['id' => $id]),
            default => null,
        };
    }

    /**
     * A stored username, redacted on the way out as everything stored is.
     */
    private static function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Redaction::redactString($value) : null;
    }
}
