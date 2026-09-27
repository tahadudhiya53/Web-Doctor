<?php

namespace Tahadudhiya\WebDoctor\models;

use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\helpers\ErrorNormalizer;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * What identifies one error: an exception, reduced to the parts that stay the same each time the
 * same thing goes wrong.
 *
 * Read from evidence rather than from an exception, because evidence is the form in which every
 * exception Web Doctor sees has already been made safe to keep — the engine records a check that
 * broke, and a check records the exception that stopped it answering — and because an
 * investigation's stored evidence can then be recognised as the same errors afterwards.
 *
 * The identity is the class, the normalised message, where it was thrown, the exceptions behind
 * it and, where a trace was recorded, the calls at the top of it. Which check saw it is not part
 * of it: a database that refuses connections is one error however many checks run into it, and
 * recognising that is the point.
 *
 * The origin keeps its line. Two throws in one file with the same message — a generic "invalid
 * value" raised from two places — are two errors, and without the line they would merge. The
 * price is that an update which shifts the lines of a file starts new groups for the errors thrown
 * in it; that splits a history rather than merging two, which is the direction to be wrong in.
 */
final class ErrorSignature
{
    /**
     * @var string The scheme these fingerprints are produced under, named inside the hash for the
     * reason {@see Fingerprint::SCHEME} gives.
     */
    public const SCHEME = 'e1';

    /**
     * @param string $class The exception's class, an anonymous one reduced to what it extends.
     * @param string $message The normalised message: what stays the same between occurrences.
     * @param string $origin Where it was thrown, the file made relative.
     * @param list<array{class: string, message: string}> $previous The exceptions behind it, normalised.
     * @param string|null $stackFingerprint The calls at the top of its trace, where one was recorded.
     * @param list<string> $frames The trace, files made relative, for a reader.
     * @param string $sample The message as this occurrence said it, redacted but not normalised.
     */
    public function __construct(
        public readonly string $class,
        public readonly string $message,
        public readonly string $origin,
        public readonly array $previous = [],
        public readonly ?string $stackFingerprint = null,
        public readonly array $frames = [],
        public readonly string $sample = '',
    ) {
    }

    /**
     * The errors a result's evidence records, each once.
     *
     * A stack trace is recorded apart from its exception and is paired with it by where both say
     * the exception was thrown. The same error recorded twice in one result is one occurrence:
     * the check ran into it once.
     *
     * @param list<Evidence> $evidence
     * @return list<self>
     */
    public static function allIn(array $evidence, ?string $root = null): array
    {
        $traces = [];

        foreach ($evidence as $item) {
            if ($item->type === EvidenceType::STACK_TRACE && $item->reference !== null) {
                $traces[$item->reference] ??= $item;
            }
        }

        $signatures = [];

        foreach ($evidence as $item) {
            $signature = self::fromEvidence($item, $item->reference === null ? null : $traces[$item->reference] ?? null, $root);

            if ($signature !== null) {
                $signatures[$signature->identity()] ??= $signature;
            }
        }

        return array_values($signatures);
    }

    /**
     * The error one piece of exception evidence records, or null when it does not record one.
     */
    public static function fromEvidence(Evidence $exception, ?Evidence $trace = null, ?string $root = null): ?self
    {
        if ($exception->type !== EvidenceType::EXCEPTION) {
            return null;
        }

        $class = self::text($exception->get('class')) ?? $exception->label;

        if (trim($class) === '') {
            return null;
        }

        $sample = self::text($exception->get('message')) ?? '';
        $origin = self::text($exception->get('origin')) ?? $exception->reference ?? '';

        $previous = [];

        foreach (is_array($exception->get('previous')) ? $exception->get('previous') : [] as $link) {
            if (is_array($link)) {
                $previous[] = [
                    'class' => ErrorNormalizer::className(self::text($link['class'] ?? null) ?? ''),
                    'message' => ErrorNormalizer::message(self::text($link['message'] ?? null) ?? '', $root),
                ];
            }
        }

        $frames = [];

        foreach ($trace !== null && is_array($trace->get('frames')) ? $trace->get('frames') : [] as $frame) {
            if (is_string($frame)) {
                $frames[] = ErrorNormalizer::frame($frame, $root);
            }
        }

        return new self(
            class: ErrorNormalizer::className($class),
            message: ErrorNormalizer::message($sample, $root),
            origin: ErrorNormalizer::origin($origin, $root),
            previous: $previous,
            stackFingerprint: ErrorNormalizer::stackFingerprint($frames),
            frames: $frames,
            sample: Redaction::redactString($sample),
        );
    }

    /**
     * Which error this is, wherever it happened.
     */
    public function identity(): string
    {
        return Fingerprint::of($this->parts());
    }

    /**
     * Which error this is, in one environment and site. Those are part of it for the reason they
     * are part of an issue's: an error in production is not the same occurrence as one in
     * staging, and what one site saw is never counted as another's.
     */
    public function fingerprint(string $environment, ?int $siteId): string
    {
        return Fingerprint::of([
            ...$this->parts(),
            $environment,
            $siteId === null ? null : (string)$siteId,
        ]);
    }

    /**
     * The class without its namespace, as a list names it.
     */
    public function shortClass(): string
    {
        $slash = strrpos($this->class, '\\');

        return $slash === false ? $this->class : substr($this->class, $slash + 1);
    }

    /**
     * @return list<string|null>
     */
    private function parts(): array
    {
        $previous = array_map(
            static fn(array $link): string => $link['class'] . "\x1e" . $link['message'],
            $this->previous,
        );

        return [
            self::SCHEME,
            $this->class,
            $this->message,
            $this->origin,
            implode("\x1d", $previous),
            $this->stackFingerprint,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
