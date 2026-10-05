<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\StoredJson;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\VerificationRecord;

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
    use NamesUnreadable;

    /** @var string The check that found the problem. Its answer decides whether the problem is gone. */
    public const ROLE_ORIGINAL = 'original';

    /** @var string A check the repair names as showing whether it worked. */
    public const ROLE_NAMED = 'named';

    /** @var string A check in the same area as the problem. */
    public const ROLE_AREA = 'area';

    /**
     * @param list<string> $failures Why the answer is not "verified", each a sentence; empty when it is.
     * @param list<array{diagnosticId: string, name: string, role: string, reason: string, status: DiagnosticStatus|null, statusUnreadable: bool, severity: Severity|null, summary: string|null, issueId: int|null, persists: bool, appeared: bool|null}> $checks
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
        public readonly ?VerificationStatus $result,
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
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly ?float $durationMs,
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * Reads a stored row, strictly. An answer this version does not know is not read as inconclusive,
     * a moment that is not one is not the epoch, and a part that is not what was written — a check,
     * a condition, the evidence, the comparison or the errors — is not read as empty: each is named
     * in `$unreadable`, and the page says so.
     */
    public static function fromRecord(VerificationRecord $record): self
    {
        $unreadable = [];
        $result = VerificationStatus::tryFrom((string)$record->result);
        $result = $result !== null && $result->isResult() ? $result : null;
        [$failures, $failuresRead] = StoredJson::decode($record->failures);
        [$checks, $checksRead] = StoredJson::decode($record->checks);
        [$conditionsStored, $conditionsRead] = StoredJson::decode($record->conditions);
        [$original, $originalCount, $originalRead] = self::state($record->originalState);
        [$current, $currentCount, $currentRead] = self::state($record->currentState);
        [$comparison, $comparisonRead] = StoredJson::decode($record->comparison);
        [$errors, $errorsRead] = StoredJson::decode($record->errors);
        $startedAt = StoredTime::read($record->startedAt);
        $finishedAt = StoredTime::read($record->finishedAt);
        [$checks, $checksWhole] = self::checks($checks);
        // A comparison is written with all three lists, or not at all.
        $facts = [];
        $factsWhole = true;

        foreach (['persisting', 'gone', 'appeared'] as $list) {
            [$facts[$list], $read] = $comparison === [] ? [[], true] : self::facts($comparison[$list] ?? null);
            $factsWhole = $factsWhole && $read;
        }

        [$errorList, $errorsWhole] = self::errors($errors);
        $conditions = [];

        foreach ($conditionsStored as $stored) {
            $condition = is_array($stored) ? VerificationCondition::fromArray($stored) : null;

            if ($condition === null) {
                $conditionsRead = false;

                continue;
            }

            $conditions[] = $condition;
        }

        foreach ([
            'result' => $result === null,
            'startedAt' => $startedAt === null,
            'finishedAt' => $finishedAt === null,
            'failures' => !$failuresRead || count(array_filter($failures, 'is_string')) !== count($failures),
            'checks' => !$checksRead || !$checksWhole,
            'conditions' => !$conditionsRead,
            'originalState' => !$originalRead,
            'currentState' => !$currentRead,
            'comparison' => !$comparisonRead || !$factsWhole,
            'errors' => !$errorsRead || !$errorsWhole,
        ] as $field => $broken) {
            if ($broken) {
                $unreadable[] = $field;
            }
        }

        return new self(
            id: (int)$record->id,
            repairId: (int)$record->repairId,
            issueId: $record->issueId === null ? null : (int)$record->issueId,
            diagnosticId: (string)$record->diagnosticId,
            action: (string)$record->action,
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            runId: (string)$record->runId,
            result: $result,
            failures: array_values(array_map(Redaction::redactString(...), array_filter($failures, 'is_string'))),
            checks: $checks,
            conditions: $conditions,
            originalState: $original,
            originalCount: $originalCount,
            currentState: $current,
            currentCount: $currentCount,
            comparison: $facts,
            errors: $errorList,
            verifiedBy: $record->verifiedBy === null ? null : (int)$record->verifiedBy,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
            unreadable: $unreadable,
        );
    }

    /** What the answer amounts to, in one sentence. */
    public function summary(): string
    {
        return match ($this->result) {
            null => Craft::t('web-doctor', 'This verification’s answer could not be read.'),
            VerificationStatus::VERIFIED => Craft::t('web-doctor', 'Verified: the check that found the problem ran again and no longer reports it, and everything else this verification looks at held.'),
            VerificationStatus::FAILED => Craft::t('web-doctor', 'Repair completed, but verification failed: the check that found the problem ran again and still reports it.'),
            VerificationStatus::INCONCLUSIVE, VerificationStatus::NONE, VerificationStatus::PENDING => Craft::t('web-doctor', 'Inconclusive: this verification could not establish whether the repair worked.'),
        };
    }

    /** How a check came to be part of the verification, as a reader sees it. */
    public static function roleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_ORIGINAL => Craft::t('web-doctor', 'Found the problem'),
            self::ROLE_NAMED => Craft::t('web-doctor', 'Named by the repair'),
            self::ROLE_AREA => Craft::t('web-doctor', 'Same area'),
            default => Craft::t('web-doctor', 'Could not be read'),
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
     * Each check as it was kept. A null status is a check that did not run, which is what Web Doctor
     * writes as null; a status written as something else is unreadable, said by `statusUnreadable`,
     * and makes the checks as a whole unreadable rather than reading as "not run".
     *
     * @param array<array-key, mixed> $stored
     * @return array{0: list<array{diagnosticId: string, name: string, role: string, reason: string, status: DiagnosticStatus|null, statusUnreadable: bool, severity: Severity|null, summary: string|null, issueId: int|null, persists: bool, appeared: bool|null}>, 1: bool}
     */
    private static function checks(array $stored): array
    {
        $out = [];
        $whole = true;

        foreach ($stored as $check) {
            if (!is_array($check) || !is_string($check['diagnosticId'] ?? null)) {
                $whole = false;

                continue;
            }

            $status = ($check['status'] ?? null) === null ? null : (is_string($check['status']) ? DiagnosticStatus::tryFrom($check['status']) : null);
            $statusUnreadable = ($check['status'] ?? null) !== null && $status === null;
            $role = $check['role'] ?? null;

            $severity = is_string($check['severity'] ?? null) ? Severity::tryFrom($check['severity']) : null;

            // Anything not as written makes the checks unreadable, rather than a severity nobody can
            // read reading as none, or a "persists" that is not a yes or no reading as no.
            if ($statusUnreadable || !in_array($role, [self::ROLE_ORIGINAL, self::ROLE_NAMED, self::ROLE_AREA], true)
                || (($check['severity'] ?? null) !== null && $severity === null)
                || !is_bool($check['persists'] ?? null)
                || !(($check['appeared'] ?? null) === null || is_bool($check['appeared']))) {
                $whole = false;
            }

            $text = static fn(string $key): string => is_string($check[$key] ?? null) ? Redaction::redactString($check[$key]) : '';

            $out[] = [
                'diagnosticId' => $text('diagnosticId'),
                'name' => $text('name'),
                'role' => is_string($role) ? $role : '',
                'reason' => $text('reason'),
                'status' => $status,
                'statusUnreadable' => $statusUnreadable,
                'severity' => $severity,
                'summary' => is_string($check['summary'] ?? null) ? Redaction::redactString($check['summary']) : null,
                'issueId' => is_int($check['issueId'] ?? null) ? $check['issueId'] : null,
                'persists' => ($check['persists'] ?? null) === true,
                'appeared' => is_bool($check['appeared'] ?? null) ? $check['appeared'] : null,
            ];
        }

        return [$out, $whole];
    }

    /**
     * @return array{0: list<Evidence>, 1: int, 2: bool} The evidence, how much there was, and whether it could be read.
     */
    private static function state(?string $json): array
    {
        [$stored, $read] = StoredJson::decode($json);
        $evidence = [];

        if ($json !== null && (!is_array($stored['evidence'] ?? null) || !is_int($stored['count'] ?? null))) {
            $read = false;
        }

        foreach (is_array($stored['evidence'] ?? null) ? $stored['evidence'] : [] as $item) {
            if (!is_array($item)) {
                $read = false;

                continue;
            }

            $piece = Evidence::fromArray($item);
            $read = $read && $piece->readable;
            $evidence[] = $piece;
        }

        return [$evidence, max(count($evidence), is_int($stored['count'] ?? null) ? $stored['count'] : 0), $read];
    }

    /**
     * @return array{0: list<array{type: string, label: string}>, 1: bool} The facts, and whether they could all be read.
     */
    private static function facts(mixed $stored): array
    {
        $out = [];

        if (!is_array($stored)) {
            return [[], false];
        }

        foreach ($stored as $fact) {
            if (!is_array($fact) || !is_string($fact['type'] ?? null) || !is_string($fact['label'] ?? null)) {
                return [[], false];
            }

            $out[] = ['type' => $fact['type'], 'label' => Redaction::redactString($fact['label'])];
        }

        return [$out, true];
    }

    /**
     * @param array<array-key, mixed> $stored
     * @return array{0: list<array{fingerprint: string, groupId: int|null, exceptionClass: string, diagnosticIds: list<string>, new: bool|null}>, 1: bool}
     */
    private static function errors(array $stored): array
    {
        $out = [];

        foreach ($stored as $error) {
            if (!is_array($error) || !is_string($error['fingerprint'] ?? null)) {
                return [[], false];
            }

            $out[] = [
                'fingerprint' => $error['fingerprint'],
                'groupId' => is_int($error['groupId'] ?? null) ? $error['groupId'] : null,
                'exceptionClass' => is_string($error['exceptionClass'] ?? null) ? Redaction::redactString($error['exceptionClass']) : '',
                'diagnosticIds' => array_values(array_filter(is_array($error['diagnosticIds'] ?? null) ? $error['diagnosticIds'] : [], 'is_string')),
                'new' => is_bool($error['new'] ?? null) ? $error['new'] : null,
            ];
        }

        return [$out, true];
    }
}
