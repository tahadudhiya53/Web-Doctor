<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use JsonSerializable;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;

/**
 * An exception reduced to what Web Doctor is allowed to repeat.
 *
 * Exceptions are the likeliest way a credential escapes a diagnostic tool: a driver puts the DSN
 * in the message, a client library puts the Authorization header in it, and that message then
 * travels into a result description, a log line, a report and an API response. Redaction happens
 * once, here, and everything downstream consumes this rather than the `Throwable` — so there is
 * one sanitisation strategy and nothing else has the raw message to leak.
 */
final class SafeException implements JsonSerializable
{
    /** How many stack frames are kept. A trace is evidence, not an archive. */
    public const DEFAULT_FRAMES = 15;
    public const MAX_FRAMES = 50;

    /** How far down the `previous` chain is followed, so a cycle or a deep chain is bounded. */
    private const MAX_PREVIOUS = 5;

    /** @var string The exception's class name. */
    public readonly string $class;

    /** @var string Its message, redacted. */
    public readonly string $message;

    /** @var string Where it was thrown, as `file:line`. */
    public readonly string $origin;

    /**
     * A class's name without its namespace, as a reader is told what kind of thing went wrong.
     */
    public static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    /**
     * What kind of exception this is, by its short class name — the whole of what a reader is told
     * where its message could quote SQL that redaction leaves. An anonymous class is named by what it
     * extends, since its own name holds the path of the file that declared it.
     */
    public static function kind(Throwable $exception): string
    {
        $class = $exception::class;

        if (str_contains($class, '@anonymous')) {
            $class = get_parent_class($exception) ?: 'Exception';
        }

        return self::shortName($class);
    }

    /**
     * @param list<array{class: string, message: string}> $previous The redacted chain behind it.
     * @param list<string> $frames Where it came from, bounded.
     */
    private function __construct(
        string $class,
        string $message,
        public readonly int|string $code,
        string $origin,
        public readonly array $previous,
        public readonly array $frames,
    ) {
        $this->class = $class;
        $this->message = $message;
        $this->origin = $origin;
    }

    public static function from(Throwable $exception, int $frames = self::DEFAULT_FRAMES): self
    {
        return new self(
            class: $exception::class,
            message: Redaction::redactString($exception->getMessage()),
            code: $exception->getCode(),
            origin: sprintf('%s:%d', $exception->getFile(), $exception->getLine()),
            previous: self::chain($exception),
            frames: self::frames($exception, $frames),
        );
    }

    /**
     * Writes a failure to Craft's log through the sanitised form, never the raw exception: a
     * driver's error can quote its own DSN. One sentence saying what failed, then what happened.
     */
    public static function log(string $what, Throwable|self $exception): void
    {
        $safe = $exception instanceof self ? $exception : self::from($exception);

        Craft::error(sprintf('%s. %s at %s', $what, $safe->summary(), $safe->origin), WebDoctor::LOG_CATEGORY);
    }

    /**
     * One line naming what went wrong, safe to put in a result description or a log.
     */
    public function summary(): string
    {
        return $this->message === ''
            ? $this->class
            : sprintf('%s: %s', $this->class, $this->message);
    }

    /**
     * @return array<string, mixed> The fact, as evidence records it.
     */
    public function toEvidenceData(): array
    {
        return [
            'class' => $this->class,
            'message' => $this->message,
            'code' => $this->code,
            'origin' => $this->origin,
            'previous' => $this->previous,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toEvidenceData() + ['frames' => $this->frames];
    }

    /**
     * The exceptions behind this one, each redacted the same way. Bounded, because a wrapped
     * exception chain can be arbitrarily long and, if it loops, endless.
     *
     * @return list<array{class: string, message: string}>
     */
    private static function chain(Throwable $exception): array
    {
        $chain = [];
        $seen = [];
        $previous = $exception->getPrevious();

        while ($previous !== null && count($chain) < self::MAX_PREVIOUS) {
            $key = spl_object_id($previous);

            if (isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $chain[] = [
                'class' => $previous::class,
                'message' => Redaction::redactString($previous->getMessage()),
            ];

            $previous = $previous->getPrevious();
        }

        return $chain;
    }

    /**
     * Where the exception came from. Arguments are never included: they are the other place a
     * credential hides in a trace, and the call site is what a reader needs.
     *
     * @return list<string>
     */
    private static function frames(Throwable $exception, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_FRAMES));
        $frames = [];

        foreach (array_slice($exception->getTrace(), 0, $limit) as $frame) {
            $frames[] = Redaction::redactString(sprintf(
                '%s%s%s (%s:%s)',
                $frame['class'] ?? '',
                isset($frame['class']) ? ($frame['type'] ?? '::') : '',
                $frame['function'],
                $frame['file'] ?? 'unknown',
                $frame['line'] ?? '?',
            ));
        }

        return $frames;
    }
}
