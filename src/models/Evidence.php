<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Throwable;

/**
 * A single recorded fact, and where it came from.
 *
 * Evidence is what makes a conclusion checkable by someone who was not there when it was
 * reached: every finding Web Doctor reports can be followed back to the facts behind it. So
 * evidence is structured rather than prose, typed so it can be presented and correlated without
 * being interpreted, and attributed to whatever observed it.
 *
 * It is redacted the moment it is constructed rather than on the way out. Evidence is a record,
 * never something Web Doctor makes a decision from, so there is no reason for a raw credential
 * to exist inside one — and redacting at the door means no later serializer can forget to. It is
 * bounded at the door for the same reason: a fact is kept, an archive is not.
 *
 * Which run, site and environment it belongs to is stamped on by the engine, never claimed by
 * the diagnostic, for the reason a result's run is: a check that could name its own run could
 * name the wrong one.
 */
final class Evidence implements JsonSerializable
{
    /**
     * @var int The most the fact itself may occupy once encoded. Large enough for a stack trace
     * or a sample of queue failures; small enough that a diagnostic which stumbles onto a whole
     * log file cannot record it.
     */
    public const MAX_DATA_BYTES = 16384;

    /** @var int The most the notes about the fact may occupy once encoded. */
    public const MAX_METADATA_BYTES = 4096;

    public const MAX_LABEL_LENGTH = 255;
    public const MAX_SOURCE_LENGTH = 255;
    public const MAX_REFERENCE_LENGTH = 500;

    /** @var int How much of a long string survives when an entry has to be summarised to fit. */
    private const SUMMARY_LENGTH = 200;

    /** @var int Room kept back for the note saying how much was left out. */
    private const OMISSION_RESERVE = 64;

    /** @var array<array-key, mixed> The fact itself, already made safe to keep. */
    public readonly array $data;

    /**
     * @var array<array-key, mixed> About the fact rather than the fact: how it was gathered, what
     * bounded it, the units it is in. Made safe the same way.
     */
    public readonly array $metadata;

    /** @var string What this evidence is, in a few words. */
    public readonly string $label;

    /** @var string What observed it. */
    public readonly string $source;

    /**
     * @var string|null Where the fact can be found again — a log file and line, a queue job, a
     * table. Stored in preference to the thing itself wherever the thing is large.
     */
    public readonly ?string $reference;

    /** @var DateTimeImmutable When Web Doctor observed the fact. */
    public readonly DateTimeImmutable $recordedAt;

    /** @var bool Whether any of the fact was cut short to fit, so a reader knows it is partial. */
    public readonly bool $truncated;

    /**
     * @param EvidenceType $type What kind of fact this is.
     * @param string $label What this evidence is, in a few words.
     * @param string $source What observed it — usually a diagnostic or collector ID.
     * @param array<array-key, mixed> $data The structured fact.
     * @param DateTimeImmutable|null $observedAt When the fact was true, where that differs from
     * when it was recorded. A log line's own timestamp belongs here, not the time it was read.
     * @param DateTimeImmutable|null $recordedAt When Web Doctor observed it; now, unless stated.
     * @param array<array-key, mixed> $metadata How the fact was gathered, rather than the fact.
     * @param string|null $reference Where the fact can be found again.
     * @param Confidence|null $confidence How firmly the fact is established, where it was
     * inferred rather than read. Null is the ordinary case: something observed directly.
     * @param bool $truncated Whether whatever recorded this already cut it short.
     * @param string|null $diagnosticId The diagnostic whose result this supports; stamped by the engine.
     * @param string|null $runId The run it was gathered in; stamped by the engine.
     * @param string|null $environment The environment it was gathered in; stamped by the engine.
     * @param int|null $siteId The site in view when it was gathered; stamped by the engine.
     */
    public function __construct(
        public readonly EvidenceType $type,
        string $label,
        string $source,
        array $data = [],
        public readonly ?DateTimeImmutable $observedAt = null,
        ?DateTimeImmutable $recordedAt = null,
        array $metadata = [],
        ?string $reference = null,
        public readonly ?Confidence $confidence = null,
        bool $truncated = false,
        public readonly ?string $diagnosticId = null,
        public readonly ?string $runId = null,
        public readonly ?string $environment = null,
        public readonly ?int $siteId = null,
    ) {
        [$data, $dataCut] = self::fit(Redaction::redact($data), self::MAX_DATA_BYTES);
        [$metadata, $metadataCut] = self::fit(Redaction::redact($metadata), self::MAX_METADATA_BYTES);

        $this->data = $data;
        $this->metadata = $metadata;
        $this->label = self::shorten(Redaction::redactString($label), self::MAX_LABEL_LENGTH);
        // Usually a diagnostic ID, but another plugin's diagnostic can put anything here.
        $this->source = self::shorten(Redaction::redactString($source), self::MAX_SOURCE_LENGTH);
        $this->reference = $reference === null || $reference === ''
            ? null
            : self::shorten(Redaction::redactString($reference), self::MAX_REFERENCE_LENGTH);
        $this->recordedAt = $recordedAt ?? new DateTimeImmutable();

        // Redaction marks what it had to bound, and fitting marks what it had to leave out, so
        // either one showing up is enough to say the fact is not the whole of it.
        $this->truncated = $truncated || $dataCut || $metadataCut || Redaction::marks($data)['bounded'];
    }

    /**
     * Records an exception as evidence, keeping what identifies it and discarding the object.
     *
     * The redaction is {@see SafeException}'s, which is the same one the result description and
     * the log line use, so there is one answer to what an exception is allowed to say.
     */
    public static function fromThrowable(Throwable|SafeException $exception, string $source): self
    {
        $safe = $exception instanceof SafeException ? $exception : SafeException::from($exception);

        return new self(
            type: EvidenceType::EXCEPTION,
            label: $safe->class,
            source: $source,
            data: $safe->toEvidenceData(),
            reference: $safe->origin,
        );
    }

    /**
     * Records where an exception was thrown from. Kept apart from the exception itself because
     * a stack trace is the part of it a client-facing report must never show.
     */
    public static function stackTrace(Throwable|SafeException $exception, string $source, int $frames = SafeException::DEFAULT_FRAMES): self
    {
        $safe = $exception instanceof SafeException
            ? $exception
            : SafeException::from($exception, $frames);

        $limit = max(1, min($frames, SafeException::MAX_FRAMES));
        // Measured against the whole trace where there is one to measure, since a SafeException
        // built from the exception has already dropped whatever did not fit.
        $available = $exception instanceof Throwable ? count($exception->getTrace()) : count($safe->frames);

        return new self(
            type: EvidenceType::STACK_TRACE,
            label: 'Stack trace',
            source: $source,
            // A SafeException built elsewhere carries its own frame budget, so a smaller one
            // asked for here is applied rather than ignored.
            data: ['frames' => array_slice($safe->frames, 0, $limit)],
            reference: $safe->origin,
            truncated: $available > $limit,
        );
    }

    /**
     * Reads back evidence written down as {@see jsonSerialize()} writes it.
     *
     * Rebuilt through the constructor, so what comes back is redacted and bounded by the rules
     * that apply now even if whatever wrote it applied none. A type this version does not have
     * falls back to one withheld from clients, so not knowing what a fact is never makes it more
     * visible.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $moment = static function(mixed $value): ?DateTimeImmutable {
            if (!is_string($value) || $value === '') {
                return null;
            }

            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                return null;
            }
        };

        $text = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new self(
            type: EvidenceType::tryFrom((string)($stored['type'] ?? '')) ?? EvidenceType::CONFIGURATION,
            label: (string)($text($stored['label'] ?? null) ?? ''),
            source: (string)($text($stored['source'] ?? null) ?? ''),
            data: is_array($stored['data'] ?? null) ? $stored['data'] : [],
            observedAt: $moment($stored['observedAt'] ?? null),
            recordedAt: $moment($stored['recordedAt'] ?? null),
            metadata: is_array($stored['metadata'] ?? null) ? $stored['metadata'] : [],
            reference: $text($stored['reference'] ?? null),
            confidence: Confidence::tryFrom((string)($stored['confidence'] ?? '')),
            truncated: (bool)($stored['truncated'] ?? false),
            diagnosticId: $text($stored['diagnosticId'] ?? null),
            runId: $text($stored['runId'] ?? null),
            environment: $text($stored['environment'] ?? null),
            siteId: is_numeric($stored['siteId'] ?? null) ? (int)$stored['siteId'] : null,
        );
    }

    /**
     * Records that something is configured, without recording what it is set to. This is how a
     * credential becomes evidence.
     */
    public static function presence(string $label, mixed $value, string $source, EvidenceType $type = EvidenceType::CONFIGURATION): self
    {
        return new self(
            type: $type,
            label: $label,
            source: $source,
            data: ['state' => Redaction::presence($value)],
        );
    }

    /**
     * The same fact, attributed to the result it supports and the run, environment and site it
     * was gathered in. Only the engine calls this, as the result is stamped: whatever the
     * diagnostic said about these is replaced.
     */
    public function withAttribution(string $diagnosticId, DiagnosticContext $context): self
    {
        return new self(
            type: $this->type,
            label: $this->label,
            source: $this->source,
            data: $this->data,
            observedAt: $this->observedAt,
            recordedAt: $this->recordedAt,
            metadata: $this->metadata,
            reference: $this->reference,
            confidence: $this->confidence,
            truncated: $this->truncated,
            diagnosticId: $diagnosticId,
            runId: $context->runId,
            environment: $context->environment,
            siteId: $context->siteId,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * How many values in this evidence were withheld. A reader told that two values were removed
     * reads what is left differently from one who assumes they are looking at everything.
     */
    public function redactions(): int
    {
        return Redaction::marks($this->data)['redacted']
            + Redaction::marks($this->metadata)['redacted']
            + Redaction::marks($this->label)['redacted']
            + Redaction::marks((string)$this->reference)['redacted'];
    }

    /**
     * What makes this the same fact as another: what it is, what it says, where it came from and
     * how firmly it is held.
     *
     * Everything that changes from one sighting to the next is left out — which run saw it, when
     * it was recorded, and when it was last true. A fact whose observed time moved is the same
     * fact seen again, and is counted as one; folding the time in would store a new row for every
     * run of anything that reports a moving timestamp. Where the moment is itself what
     * distinguishes two facts, the diagnostic says so in the data.
     */
    public function digest(): string
    {
        return hash('sha256', self::encode([
            'v2',
            $this->type->value,
            $this->label,
            $this->source,
            $this->reference,
            $this->confidence?->value,
            $this->data,
            $this->metadata,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->label,
            'source' => $this->source,
            'reference' => $this->reference,
            'data' => $this->data,
            'metadata' => $this->metadata,
            'confidence' => $this->confidence?->value,
            'truncated' => $this->truncated,
            'redactions' => $this->redactions(),
            'diagnosticId' => $this->diagnosticId,
            'runId' => $this->runId,
            'environment' => $this->environment,
            'siteId' => $this->siteId,
            'observedAt' => $this->observedAt?->format(DATE_ATOM),
            'recordedAt' => $this->recordedAt->format(DATE_ATOM),
        ];
    }

    /**
     * How a value is written down wherever evidence is serialized. Malformed text is substituted
     * rather than allowed to turn the whole encoding into `false`, which would store nothing and
     * say nothing about why.
     */
    public static function encode(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
    }

    /**
     * Brings a structure within a byte budget, keeping as much of it as will fit.
     *
     * Entries are kept whole in the order given while they fit, then summarised — a long string
     * cut short, a nested structure replaced by how many entries it had — and once even a summary
     * will not fit, counted instead. The first entries are the ones a diagnostic chose to put
     * first, so they are the ones kept.
     *
     * @param array<array-key, mixed> $data
     * @return array{0: array<array-key, mixed>, 1: bool} The structure, and whether it was cut.
     */
    private static function fit(array $data, int $budget): array
    {
        if (strlen(self::encode($data)) <= $budget) {
            return [$data, false];
        }

        // Redaction may already have left a note of what it omitted; it is carried into the
        // new note rather than competing with it.
        $alreadyOmitted = isset($data[Redaction::OMITTED_KEY]) ? (int)$data[Redaction::OMITTED_KEY] : 0;
        unset($data[Redaction::OMITTED_KEY]);

        $kept = [];
        $used = 2;
        $available = $budget - self::OMISSION_RESERVE;
        $omitted = 0;

        foreach ($data as $key => $value) {
            foreach ([$value, self::summarise($value)] as $candidate) {
                // Measured as an object entry, which is what the result encodes as once any
                // entry is left out of a list. The two braces out, one comma in.
                $size = strlen(self::encode((object)[$key => $candidate])) - 1;

                if ($used + $size <= $available) {
                    $kept[$key] = $candidate;
                    $used += $size;

                    continue 2;
                }
            }

            $omitted++;
        }

        if ($omitted + $alreadyOmitted > 0) {
            $kept[Redaction::OMITTED_KEY] = sprintf('%d more omitted', $omitted + $alreadyOmitted);
        }

        return [$kept, true];
    }

    /**
     * The smallest honest stand-in for a value that does not fit.
     */
    private static function summarise(mixed $value): mixed
    {
        if (is_array($value)) {
            return sprintf('[%d entries omitted]', count($value));
        }

        if (is_string($value) && mb_strlen($value) > self::SUMMARY_LENGTH) {
            return mb_substr($value, 0, self::SUMMARY_LENGTH) . Redaction::TRUNCATED;
        }

        return $value;
    }

    private static function shorten(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
