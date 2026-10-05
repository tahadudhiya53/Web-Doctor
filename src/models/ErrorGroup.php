<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;

/**
 * One error as it stands across every time it happened: what it is, how often and between when
 * it was seen, and which checks ran into it.
 *
 * Immutable, and read back through redaction, so what leaves the database is held to the rules
 * that apply now even if a row was written by something that applied none.
 */
final class ErrorGroup
{
    /**
     * @param list<array{class: string, message: string}> $previous The exceptions behind it.
     * @param list<string> $frames The latest recorded trace, files relative to the installation.
     * @param list<ErrorSource> $sources The checks that ran into it, most recently first.
     */
    private function __construct(
        public readonly int $id,
        public readonly string $fingerprint,
        public readonly string $exceptionClass,
        public readonly string $normalizedMessage,
        public readonly string $message,
        public readonly string $origin,
        public readonly array $previous,
        public readonly ?string $stackFingerprint,
        public readonly array $frames,
        public readonly string $environment,
        public readonly ?int $siteId,
        public readonly ?string $siteName,
        public readonly int $occurrences,
        public readonly ?DateTimeImmutable $firstSeen,
        public readonly ?DateTimeImmutable $lastSeen,
        public readonly ?string $firstRunId,
        public readonly ?string $lastRunId,
        public readonly array $sources = [],
        public readonly array $unreadable = [],
    ) {
    }

    /**
     * @param list<ErrorSource> $sources
     */
    public static function fromRecord(ErrorGroupRecord $record, array $sources = []): self
    {
        $previous = [];

        foreach (self::decode($record->previous) as $link) {
            if (is_array($link)) {
                $previous[] = [
                    'class' => is_string($link['class'] ?? null) ? $link['class'] : '',
                    'message' => Redaction::redactString(is_string($link['message'] ?? null) ? $link['message'] : ''),
                ];
            }
        }

        return new self(
            id: (int)$record->id,
            fingerprint: (string)$record->fingerprint,
            exceptionClass: (string)$record->exceptionClass,
            normalizedMessage: Redaction::redactString((string)$record->normalizedMessage),
            message: Redaction::redactString((string)($record->message ?? '')),
            origin: Redaction::redactString((string)$record->origin),
            previous: $previous,
            stackFingerprint: $record->stackFingerprint,
            frames: array_values(array_map(
                static fn(mixed $frame): string => Redaction::redactString((string)$frame),
                array_filter(self::decode($record->frames), 'is_string'),
            )),
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            siteName: $record->siteName,
            occurrences: (int)$record->occurrences,
            // Moments that are not ones are unreadable, never now.
            firstSeen: StoredTime::read($record->firstSeen),
            lastSeen: StoredTime::read($record->lastSeen),
            firstRunId: $record->firstRunId,
            lastRunId: $record->lastRunId,
            sources: $sources,
            unreadable: array_keys(array_filter([
                'firstSeen' => StoredTime::read($record->firstSeen) === null,
                'lastSeen' => StoredTime::read($record->lastSeen) === null,
                'previous' => !self::readable($record->previous),
                'frames' => !self::readable($record->frames),
            ])),
        );
    }

    /**
     * The class without its namespace, as a list names it.
     */
    public function shortClass(): string
    {
        return SafeException::shortName($this->exceptionClass);
    }

    /**
     * Where the error was recorded, as a reader should read it. A site deleted since keeps the
     * name it had, marked as deleted, so its errors never read as the whole installation's.
     */
    public function siteLabel(): string
    {
        return SiteName::label($this->siteId, $this->siteName);
    }

    /** Whether this error has happened more than once. */
    public function isRepeated(): bool
    {
        return $this->occurrences > 1;
    }

    /**
     * The issues the checks that ran into this error have their findings recorded on, each once.
     *
     * @return list<int>
     */
    public function issueIds(): array
    {
        $ids = [];

        foreach ($this->sources as $source) {
            if ($source->issueId !== null) {
                $ids[$source->issueId] = $source->issueId;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<array-key, mixed>
     */
    /** Whether a stored JSON column holds what was written: nothing, or a structure. */
    private static function readable(?string $json): bool
    {
        return $json === null || is_array(json_decode($json, true));
    }

    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
