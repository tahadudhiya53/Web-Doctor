<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use DateTimeImmutable;
use JsonSerializable;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Throwable;

/**
 * One fact a root cause was weighed on, and where to find it again.
 *
 * Every conclusion has to be traceable to evidence, so an observation carries the way back: the
 * check that reported it, the issue it landed on, the error group it was counted against, the
 * piece of evidence it came from. Its label is what kind of fact it is, and is shown to anybody who
 * may read the investigation; its detail quotes what the fact contains, and is evidence, shown only
 * to somebody who may see evidence.
 *
 * Redacted and bounded as it is built, for the reason evidence is: it quotes what checks recorded,
 * including other plugins' checks, about installations whose failures quote credentials.
 */
final class Observation implements JsonSerializable
{
    /** @var string What a check reported. */
    public const RESULT = 'result';

    /** @var string A fact a check recorded. */
    public const EVIDENCE = 'evidence';

    /** @var string An exception a check ran into. */
    public const ERROR = 'error';

    /** @var string A problem already known to the Issue Center. */
    public const ISSUE = 'issue';

    public const MAX_LABEL_LENGTH = 255;
    public const MAX_DETAIL_LENGTH = 500;

    /** @var list<string> The order observations are listed in, most direct first. */
    private const KINDS = [self::RESULT, self::EVIDENCE, self::ERROR, self::ISSUE];

    public readonly string $label;
    public readonly ?string $detail;

    /**
     * @param string $kind One of the kinds above.
     * @param string $label What kind of fact this is, for anybody who may read the investigation.
     * @param string|null $detail What the fact contains. Evidence, so shown only to somebody who
     * may see evidence.
     * @param string|null $diagnosticId The check that recorded it.
     * @param int|null $issueId The issue it is, or landed on.
     * @param string|null $errorFingerprint The error group it was counted against, where it is an error.
     * @param string|null $evidenceDigest The piece of evidence it came from.
     * @param bool $confirmed Whether whatever recorded it established it, rather than inferring it.
     * @param DateTimeImmutable|null $at When it was true, where that is known.
     */
    public function __construct(
        public readonly string $kind,
        string $label,
        ?string $detail = null,
        public readonly ?string $diagnosticId = null,
        public readonly ?int $issueId = null,
        public readonly ?string $errorFingerprint = null,
        public readonly ?string $evidenceDigest = null,
        public readonly bool $confirmed = false,
        public readonly ?DateTimeImmutable $at = null,
    ) {
        $this->label = self::shorten(Redaction::redactString($label), self::MAX_LABEL_LENGTH);
        $this->detail = $detail === null || $detail === ''
            ? null
            : self::shorten(Redaction::redactString($detail), self::MAX_DETAIL_LENGTH);
    }

    /**
     * What makes this the same observation as another, so one fact reached twice by a condition
     * is listed once.
     */
    public function key(): string
    {
        return implode("\x1f", [
            $this->kind,
            $this->diagnosticId ?? '',
            (string)$this->issueId,
            $this->errorFingerprint ?? '',
            $this->evidenceDigest ?? '',
            $this->label,
            $this->detail ?? '',
        ]);
    }

    /**
     * Orders two observations by what they are and where they came from, never by the order they
     * happened to be found in.
     */
    public static function compare(self $a, self $b): int
    {
        return [self::position($a->kind), $a->diagnosticId ?? '', $a->issueId ?? 0, $a->label, $a->detail ?? '', $a->key()]
            <=> [self::position($b->kind), $b->diagnosticId ?? '', $b->issueId ?? 0, $b->label, $b->detail ?? '', $b->key()];
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::RESULT => Craft::t('web-doctor', 'Check result'),
            self::EVIDENCE => Craft::t('web-doctor', 'Evidence'),
            self::ERROR => Craft::t('web-doctor', 'Error'),
            self::ISSUE => Craft::t('web-doctor', 'Issue'),
            default => Craft::t('web-doctor', 'Observation'),
        };
    }

    /**
     * Reads back an observation as {@see jsonSerialize()} wrote it, through the constructor, so
     * what comes back is redacted and bounded by the rules that apply now.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $text = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $at = null;

        if ($text($stored['at'] ?? null) !== null) {
            try {
                $at = new DateTimeImmutable((string)$stored['at']);
            } catch (Throwable) {
                $at = null;
            }
        }

        $kind = is_string($stored['kind'] ?? null) ? $stored['kind'] : '';

        return new self(
            // A kind this version does not have reads as the least direct one there is.
            kind: in_array($kind, self::KINDS, true) ? $kind : self::ISSUE,
            label: (string)($text($stored['label'] ?? null) ?? ''),
            detail: $text($stored['detail'] ?? null),
            diagnosticId: $text($stored['diagnosticId'] ?? null),
            issueId: is_numeric($stored['issueId'] ?? null) ? (int)$stored['issueId'] : null,
            errorFingerprint: $text($stored['errorFingerprint'] ?? null),
            evidenceDigest: $text($stored['evidenceDigest'] ?? null),
            confirmed: (bool)($stored['confirmed'] ?? false),
            at: $at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'detail' => $this->detail,
            'diagnosticId' => $this->diagnosticId,
            'issueId' => $this->issueId,
            'errorFingerprint' => $this->errorFingerprint,
            'evidenceDigest' => $this->evidenceDigest,
            'confirmed' => $this->confirmed,
            'at' => $this->at?->format(DATE_ATOM),
        ];
    }

    private static function position(string $kind): int
    {
        $position = array_search($kind, self::KINDS, true);

        return $position === false ? count(self::KINDS) : $position;
    }

    private static function shorten(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
