<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\ExecutionMode;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Actor;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\DiagnosticRunRecord;

/**
 * One diagnostic run as it was recorded when it finished: who ran it, where, at what depth, what
 * each check answered, and — when it covered every check registered then — its health snapshot,
 * the score with the weights and the penalties that produced it.
 *
 * History, never recomputed: nothing here is worked out again from the checks as they are now. It
 * is read strictly — a value this version cannot read is null and named in `$unreadable`, never
 * another value — and redacted again as it is read.
 */
final class HistoricalRun
{
    use NamesUnreadable;

    /**
     * @param array<string, int> $statusCounts By status value.
     * @param array<string, int>|null $weights The penalty each severity cost, as applied then.
     * @param array<string, int>|null $severityCounts
     * @param list<array{diagnosticId: string, status: string, severity: string, penalty: int}>|null $contributions
     * @param list<array{diagnosticId: string, name: string, category: DiagnosticCategory|null, status: DiagnosticStatus|null, severity: Severity|null, summary: string}> $results
     * @param list<string> $unreadable
     */
    private function __construct(
        public readonly int $id,
        public readonly string $runId,
        public readonly string $environment,
        public readonly ?int $siteId,
        public readonly ?string $siteName,
        public readonly ?ExecutionMode $mode,
        public readonly ?DiagnosticDepth $depth,
        public readonly ?int $userId,
        public readonly ?string $userName,
        public readonly int $checksRegistered,
        public readonly int $checksRun,
        public readonly bool $complete,
        public readonly array $statusCounts,
        public readonly ?int $score,
        public readonly ?int $maxScore,
        public readonly ?array $weights,
        public readonly ?array $severityCounts,
        public readonly ?array $contributions,
        public readonly array $results,
        public readonly int $resultsOmitted,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly ?float $durationMs,
        public readonly array $unreadable,
    ) {
    }

    public static function fromRecord(DiagnosticRunRecord $record): self
    {
        $mode = ExecutionMode::tryFrom((string)$record->mode);
        $depth = DiagnosticDepth::tryFrom((string)$record->depth);
        $startedAt = StoredTime::read($record->startedAt);
        $finishedAt = StoredTime::read($record->finishedAt);
        [$counts, $countsRead] = self::counts($record->statusCounts, DiagnosticStatus::values());
        [$results, $resultsRead] = self::results($record->results);
        $snapshot = $record->score !== null;
        [$weights, $weightsRead] = $snapshot ? self::counts($record->weights, Severity::values()) : [null, $record->weights === null];
        [$severityCounts, $severityRead] = $snapshot ? self::counts($record->severityCounts, Severity::values()) : [null, $record->severityCounts === null];
        [$contributions, $contributionsRead] = $snapshot ? self::contributions($record->contributions) : [null, $record->contributions === null];
        $userId = $record->userId === null ? null : (int)$record->userId;
        $userName = is_string($record->userName) && $record->userName !== '' ? $record->userName : null;
        $siteId = $record->siteId === null ? null : (int)$record->siteId;
        $siteName = is_string($record->siteName) && $record->siteName !== '' ? $record->siteName : null;

        $unreadable = array_keys(array_filter([
            'mode' => $mode === null,
            'depth' => $depth === null,
            'startedAt' => $startedAt === null,
            'finishedAt' => $finishedAt === null,
            'statusCounts' => !$countsRead,
            'results' => !$resultsRead,
            // A snapshot is the score and everything behind it, or nothing: a score without its
            // arithmetic is one nobody can check.
            'snapshot' => !$weightsRead || !$severityRead || !$contributionsRead
                || ($snapshot && ($record->maxScore === null || !(bool)$record->complete)),
            'user' => $userId !== null && $userName === null,
            'site' => $siteId !== null && $siteName === null,
            'environment' => trim($record->environment) === '',
        ]));

        return new self(
            id: (int)$record->id,
            runId: (string)$record->runId,
            environment: $record->environment,
            siteId: $siteId,
            siteName: $siteName === null ? null : Redaction::redactString($siteName),
            mode: $mode,
            depth: $depth,
            userId: $userId,
            userName: $userName === null ? null : Redaction::redactString($userName),
            checksRegistered: (int)$record->checksRegistered,
            checksRun: (int)$record->checksRun,
            complete: (bool)$record->complete,
            statusCounts: $counts ?? [],
            score: in_array('snapshot', $unreadable, true) ? null : ($record->score === null ? null : (int)$record->score),
            maxScore: $record->maxScore === null ? null : (int)$record->maxScore,
            weights: $weights,
            severityCounts: $severityCounts,
            contributions: $contributions,
            results: $results,
            resultsOmitted: (int)$record->resultsOmitted,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
            unreadable: $unreadable,
        );
    }

    /** Whether a health score was kept for this run: only one that covered every check gets one. */
    public function hasSnapshot(): bool
    {
        return $this->score !== null;
    }

    /** Who ran it, by their name as it was, or that nobody was signed in. */
    public function userLabel(): string
    {
        return Actor::label($this->userId, $this->userName, $this->isUnreadable('user'));
    }

    /** Where it ran, by the site's name as it was then. */
    public function siteLabel(): string
    {
        return SiteName::recorded($this->siteId, $this->siteName, $this->isUnreadable('site'));
    }

    /**
     * Counts keyed by the values of a vocabulary, exactly: anything else stored is unreadable.
     *
     * @param list<string> $keys
     * @return array{0: array<string, int>|null, 1: bool}
     */
    private static function counts(?string $json, array $keys): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        if (!is_array($decoded)) {
            return [null, false];
        }

        foreach ($decoded as $key => $value) {
            if (!in_array($key, $keys, true) || !is_int($value) || $value < 0) {
                return [null, false];
            }
        }

        return [$decoded, true];
    }

    /**
     * @return array{0: list<array{diagnosticId: string, name: string, category: DiagnosticCategory|null, status: DiagnosticStatus|null, severity: Severity|null, summary: string}>, 1: bool}
     */
    private static function results(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [[], false];
        }

        $out = [];
        $read = true;

        foreach ($decoded as $item) {
            if (!is_array($item) || !is_string($item['diagnosticId'] ?? null)) {
                $read = false;

                continue;
            }

            // Each read as what it is, never cast: an array cast to text is an error, not a value.
            $category = is_string($item['category'] ?? null) ? DiagnosticCategory::tryFrom($item['category']) : null;
            $status = is_string($item['status'] ?? null) ? DiagnosticStatus::tryFrom($item['status']) : null;
            $severity = is_string($item['severity'] ?? null) ? Severity::tryFrom($item['severity']) : null;
            $read = $read && $category !== null && $status !== null && $severity !== null
                && is_string($item['name'] ?? null) && is_string($item['summary'] ?? null);

            $out[] = [
                'diagnosticId' => Redaction::redactString($item['diagnosticId']),
                'name' => Redaction::redactString(is_string($item['name'] ?? null) ? $item['name'] : ''),
                'category' => $category,
                'status' => $status,
                'severity' => $severity,
                'summary' => Redaction::redactString(is_string($item['summary'] ?? null) ? $item['summary'] : ''),
            ];
        }

        return [$out, $read];
    }

    /**
     * @return array{0: list<array{diagnosticId: string, status: string, severity: string, penalty: int}>|null, 1: bool}
     */
    private static function contributions(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [null, false];
        }

        $out = [];

        foreach ($decoded as $item) {
            if (!is_array($item) || !is_string($item['diagnosticId'] ?? null)
                || !is_string($item['status'] ?? null) || DiagnosticStatus::tryFrom($item['status']) === null
                || !is_string($item['severity'] ?? null) || Severity::tryFrom($item['severity']) === null
                || !is_int($item['penalty'] ?? null)) {
                return [null, false];
            }

            $out[] = [
                'diagnosticId' => Redaction::redactString($item['diagnosticId']),
                'status' => $item['status'],
                'severity' => $item['severity'],
                'penalty' => $item['penalty'],
            ];
        }

        return [$out, true];
    }
}
