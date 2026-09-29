<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use DateTimeImmutable;
use DateTimeZone;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\records\VerificationRecord;
use Throwable;

/**
 * One verification of a carried-out repair: which checks ran and why, what each answered, what the
 * repair should have left true, the evidence before and after, the errors met — and the answer, with
 * every reason it is not a clean one.
 *
 * Immutable, and read from its row. Everything is read back through the constructors that redact
 * and bound it, so a row is held to today's rules however it was written; anything unreadable reads
 * as the more cautious answer — an unknown result as inconclusive, a check's unknown status as one
 * that did not answer.
 */
final class Verification
{
    /** @var string The check that found the problem. Its answer decides whether the problem is gone. */
    public const ROLE_ORIGINAL = 'original';

    /** @var string A check the repair names as showing whether it worked. */
    public const ROLE_NAMED = 'named';

    /** @var string A check in the same area as the problem. */
    public const ROLE_AREA = 'area';

    /**
     * @param list<string> $failures Why the answer is not "verified", each a sentence; empty when it is.
     * @param list<array{diagnosticId: string, name: string, role: string, reason: string, status: DiagnosticStatus|null, severity: Severity|null, summary: string|null, issueId: int|null, persists: bool, appeared: bool|null}> $checks
     * Each check as it answered. A null status is a check that is not registered here and so did not
     * run; `persists` marks the problem being verified found again, and `appeared` says of any other
     * problem whether it appeared since the repair started (null where that could not be told).
     * @param list<VerificationCondition> $conditions
     * @param list<Evidence> $originalState The issue's last recorded finding before the checks ran.
     * @param list<Evidence> $currentState What the check that found the problem recorded now.
     * @param array{persisting: list<array{type: string, label: string}>, gone: list<array{type: string, label: string}>, appeared: list<array{type: string, label: string}>} $comparison
     * @param list<array{fingerprint: string, groupId: int|null, exceptionClass: string, diagnosticIds: list<string>, new: bool|null}> $errors
     */
    private function __construct(
        public readonly int $id,
        public readonly int $repairId,
        public readonly ?int $issueId,
        public readonly string $diagnosticId,
        public readonly string $action,
        public readonly string $environment,
        public readonly ?int $siteId,
        public readonly string $runId,
        public readonly VerificationStatus $result,
        public readonly array $failures,
        public readonly array $checks,
        public readonly array $conditions,
        public readonly array $originalState,
        public readonly int $originalCount,
        public readonly array $currentState,
        public readonly int $currentCount,
        public readonly array $comparison,
        public readonly array $errors,
        public readonly ?int $verifiedBy,
        public readonly DateTimeImmutable $startedAt,
        public readonly DateTimeImmutable $finishedAt,
        public readonly ?float $durationMs,
    ) {
    }

    public static function fromRecord(VerificationRecord $record): self
    {
        $result = VerificationStatus::tryFrom((string)$record->result);
        [$original, $originalCount] = self::state($record->originalState);
        [$current, $currentCount] = self::state($record->currentState);
        $comparison = self::decode($record->comparison);

        return new self(
            id: (int)$record->id,
            repairId: (int)$record->repairId,
            issueId: $record->issueId === null ? null : (int)$record->issueId,
            diagnosticId: (string)$record->diagnosticId,
            action: (string)$record->action,
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            runId: (string)$record->runId,
            // Only one of a verification's three answers can have been written; anything else is a
            // row this version cannot read, which is never read as verified.
            result: $result !== null && $result->isResult() ? $result : VerificationStatus::INCONCLUSIVE,
            failures: array_values(array_map(Redaction::redactString(...), array_filter(self::decode($record->failures), 'is_string'))),
            checks: self::checks(self::decode($record->checks)),
            conditions: array_values(array_map(
                static fn(array $c): VerificationCondition => VerificationCondition::fromArray($c),
                array_filter(self::decode($record->conditions), 'is_array'),
            )),
            originalState: $original,
            originalCount: $originalCount,
            currentState: $current,
            currentCount: $currentCount,
            comparison: [
                'persisting' => self::facts($comparison['persisting'] ?? null),
                'gone' => self::facts($comparison['gone'] ?? null),
                'appeared' => self::facts($comparison['appeared'] ?? null),
            ],
            errors: self::errors(self::decode($record->errors)),
            verifiedBy: $record->verifiedBy === null ? null : (int)$record->verifiedBy,
            startedAt: self::time($record->startedAt) ?? new DateTimeImmutable('@0'),
            finishedAt: self::time($record->finishedAt) ?? new DateTimeImmutable('@0'),
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
        );
    }

    /** What the answer amounts to, in one sentence. */
    public function summary(): string
    {
        return match ($this->result) {
            VerificationStatus::VERIFIED => Craft::t('web-doctor', 'Verified: the check that found the problem ran again and no longer reports it, and everything else this verification looks at held.'),
            VerificationStatus::FAILED => Craft::t('web-doctor', 'Repair completed, but verification failed: the check that found the problem ran again and still reports it.'),
            default => Craft::t('web-doctor', 'Inconclusive: this verification could not establish whether the repair worked.'),
        };
    }

    /** How a check came to be part of the verification, as a reader sees it. */
    public static function roleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_ORIGINAL => Craft::t('web-doctor', 'Found the problem'),
            self::ROLE_NAMED => Craft::t('web-doctor', 'Named by the repair'),
            default => Craft::t('web-doctor', 'Same area'),
        };
    }

    /**
     * Evidence arranged for a reader, every withheld value marked as withheld.
     *
     * @param list<Evidence> $evidence
     * @return list<array{evidence: Evidence, data: array<string, mixed>, metadata: array<string, mixed>}>
     */
    public static function evidenceItems(array $evidence): array
    {
        return array_map(static fn(Evidence $e): array => [
            'evidence' => $e,
            'data' => EvidenceDisplay::tree($e->data),
            'metadata' => EvidenceDisplay::tree($e->metadata),
        ], $evidence);
    }

    /**
     * @param array<array-key, mixed> $stored
     * @return list<array{diagnosticId: string, name: string, role: string, reason: string, status: DiagnosticStatus|null, severity: Severity|null, summary: string|null, issueId: int|null, persists: bool, appeared: bool|null}>
     */
    private static function checks(array $stored): array
    {
        $out = [];

        foreach ($stored as $check) {
            if (!is_array($check) || !is_string($check['diagnosticId'] ?? null)) {
                continue;
            }

            $text = static fn(string $key): string => is_string($check[$key] ?? null) ? Redaction::redactString($check[$key]) : '';

            $out[] = [
                'diagnosticId' => $text('diagnosticId'),
                'name' => $text('name'),
                'role' => in_array($check['role'] ?? null, [self::ROLE_ORIGINAL, self::ROLE_NAMED, self::ROLE_AREA], true) ? $check['role'] : self::ROLE_AREA,
                'reason' => $text('reason'),
                'status' => is_string($check['status'] ?? null) ? DiagnosticStatus::tryFrom($check['status']) : null,
                'severity' => is_string($check['severity'] ?? null) ? Severity::tryFrom($check['severity']) : null,
                'summary' => is_string($check['summary'] ?? null) ? Redaction::redactString($check['summary']) : null,
                'issueId' => is_int($check['issueId'] ?? null) ? $check['issueId'] : null,
                'persists' => ($check['persists'] ?? null) === true,
                'appeared' => is_bool($check['appeared'] ?? null) ? $check['appeared'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{0: list<Evidence>, 1: int}
     */
    private static function state(?string $json): array
    {
        $stored = self::decode($json);
        $evidence = [];

        foreach (is_array($stored['evidence'] ?? null) ? $stored['evidence'] : [] as $item) {
            if (is_array($item)) {
                $evidence[] = Evidence::fromArray($item);
            }
        }

        return [$evidence, max(count($evidence), is_int($stored['count'] ?? null) ? $stored['count'] : 0)];
    }

    /**
     * @return list<array{type: string, label: string}>
     */
    private static function facts(mixed $stored): array
    {
        $out = [];

        foreach (is_array($stored) ? $stored : [] as $fact) {
            if (is_array($fact) && is_string($fact['type'] ?? null) && is_string($fact['label'] ?? null)) {
                $out[] = ['type' => $fact['type'], 'label' => Redaction::redactString($fact['label'])];
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $stored
     * @return list<array{fingerprint: string, groupId: int|null, exceptionClass: string, diagnosticIds: list<string>, new: bool|null}>
     */
    private static function errors(array $stored): array
    {
        $out = [];

        foreach ($stored as $error) {
            if (!is_array($error) || !is_string($error['fingerprint'] ?? null)) {
                continue;
            }

            $out[] = [
                'fingerprint' => $error['fingerprint'],
                'groupId' => is_int($error['groupId'] ?? null) ? $error['groupId'] : null,
                'exceptionClass' => is_string($error['exceptionClass'] ?? null) ? Redaction::redactString($error['exceptionClass']) : '',
                'diagnosticIds' => array_values(array_filter(is_array($error['diagnosticIds'] ?? null) ? $error['diagnosticIds'] : [], 'is_string')),
                'new' => is_bool($error['new'] ?? null) ? $error['new'] : null,
            ];
        }

        return $out;
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
