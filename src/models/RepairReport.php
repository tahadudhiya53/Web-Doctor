<?php

namespace Tahadudhiya\WebDoctor\models;

use JsonSerializable;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * What a repair would do or did: a line saying so, each change it names, and the state it read,
 * as evidence.
 *
 * The same shape before and after. As a preview it carries the fingerprint of the state it would act
 * on, so the service can tell, when somebody confirms, whether that state is still the one they
 * looked at. As an outcome it has none.
 *
 * Made safe and small as it is built, as evidence is: text is redacted and cut to length, the list
 * of changes is bounded with the rest counted, and the state is {@see Evidence}, which redacts and
 * bounds itself.
 */
final class RepairReport implements JsonSerializable
{
    /** @var int The most changes one report names; the rest are counted. */
    public const MAX_ITEMS = 50;

    /** @var int The longest one change is written out. */
    public const MAX_ITEM_LENGTH = 500;

    /** @var int The longest the summary is written out. */
    public const MAX_SUMMARY_LENGTH = 1000;

    /** @var int The most pieces of state one report keeps. */
    public const MAX_STATE = 5;

    public readonly string $summary;

    /** @var list<string> */
    public readonly array $items;

    /** @var int How many further changes there were, beyond those named. */
    public readonly int $omitted;

    /** @var list<Evidence> */
    public readonly array $state;

    public readonly ?string $fingerprint;

    /**
     * @param list<string> $items Each change, as a line a reader can check.
     * @param list<Evidence> $state The state read, before or after.
     * @param string|null $fingerprint For a preview, {@see self::fingerprintOf()} the state it
     * would act on; null for an outcome.
     * @param int $omitted Changes already left out by whatever built the list.
     */
    public function __construct(
        string $summary,
        array $items = [],
        array $state = [],
        ?string $fingerprint = null,
        int $omitted = 0,
    ) {
        $this->summary = self::cut(Redaction::redactString($summary), self::MAX_SUMMARY_LENGTH);

        $items = array_values(array_filter($items, 'is_string'));
        $this->items = array_map(
            static fn(string $item): string => self::cut(Redaction::redactString($item), self::MAX_ITEM_LENGTH),
            array_slice($items, 0, self::MAX_ITEMS),
        );
        $this->omitted = max(0, $omitted) + max(0, count($items) - self::MAX_ITEMS);

        $this->state = array_slice($state, 0, self::MAX_STATE);
        $this->fingerprint = $fingerprint !== null && preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) === 1 ? $fingerprint : null;
    }

    /**
     * What identifies the state a repair would act on — every job, every path — computed from the
     * whole of it rather than the bounded list a reader is shown, so a change beyond the fiftieth
     * item still changes the fingerprint.
     */
    public static function fingerprintOf(mixed $identity): string
    {
        return hash('sha256', 'r1|' . Evidence::encode($identity));
    }

    /**
     * The state arranged for a reader, every withheld value marked as withheld.
     *
     * @return list<array{evidence: Evidence, data: array<string, mixed>, metadata: array<string, mixed>}>
     */
    public function evidenceItems(): array
    {
        return array_map(static fn(Evidence $evidence): array => [
            'evidence' => $evidence,
            'data' => EvidenceDisplay::tree($evidence->data),
            'metadata' => EvidenceDisplay::tree($evidence->metadata),
        ], $this->state);
    }

    /**
     * Reads a stored report back through the constructor, so what was stored is held to today's
     * bounds and redaction. Something that is not a report reads as an empty one.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $state = [];

        foreach (is_array($stored['state'] ?? null) ? $stored['state'] : [] as $evidence) {
            if (is_array($evidence)) {
                $state[] = Evidence::fromArray($evidence);
            }
        }

        return new self(
            summary: is_string($stored['summary'] ?? null) ? $stored['summary'] : '',
            items: is_array($stored['items'] ?? null) ? array_values($stored['items']) : [],
            state: $state,
            fingerprint: is_string($stored['fingerprint'] ?? null) ? $stored['fingerprint'] : null,
            omitted: is_int($stored['omitted'] ?? null) ? $stored['omitted'] : 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'summary' => $this->summary,
            'items' => $this->items,
            'omitted' => $this->omitted,
            'state' => array_map(static fn(Evidence $e): array => $e->jsonSerialize(), $this->state),
            'fingerprint' => $this->fingerprint,
        ];
    }

    private static function cut(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
