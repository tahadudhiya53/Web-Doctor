<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use JsonSerializable;
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
 * to exist inside one — and redacting at the door means no later serializer can forget to.
 */
final class Evidence implements JsonSerializable
{
    /** @var array<array-key, mixed> The fact itself, already made safe to keep. */
    public readonly array $data;

    /** @var string What this evidence is, in a few words. */
    public readonly string $label;

    /** @var DateTimeImmutable When Web Doctor observed the fact. */
    public readonly DateTimeImmutable $recordedAt;

    /**
     * @param EvidenceType $type What kind of fact this is.
     * @param string $label What this evidence is, in a few words.
     * @param string $source What observed it — usually a diagnostic or collector ID.
     * @param array<array-key, mixed> $data The structured fact.
     * @param DateTimeImmutable|null $observedAt When the fact was true, where that differs from
     * when it was recorded. A log line's own timestamp belongs here, not the time it was read.
     * @param DateTimeImmutable|null $recordedAt When Web Doctor observed it; now, unless stated.
     */
    public function __construct(
        public readonly EvidenceType $type,
        string $label,
        public readonly string $source,
        array $data = [],
        public readonly ?DateTimeImmutable $observedAt = null,
        ?DateTimeImmutable $recordedAt = null,
    ) {
        $this->label = Redaction::redactString($label);
        $this->data = Redaction::redact($data);
        $this->recordedAt = $recordedAt ?? new DateTimeImmutable();
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

        return new self(
            type: EvidenceType::STACK_TRACE,
            label: 'Stack trace',
            source: $source,
            // A SafeException built elsewhere carries its own frame budget, so a smaller one
            // asked for here is applied rather than ignored.
            data: ['frames' => array_slice($safe->frames, 0, max(1, min($frames, SafeException::MAX_FRAMES)))],
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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
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
            'data' => $this->data,
            'observedAt' => $this->observedAt?->format(DATE_ATOM),
            'recordedAt' => $this->recordedAt->format(DATE_ATOM),
        ];
    }
}
