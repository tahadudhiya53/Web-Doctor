<?php

namespace Tahadudhiya\WebDoctor\models;

use JsonSerializable;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use yii\base\InvalidArgumentException;

/**
 * Something that has to be true before a repair runs.
 *
 * Two kinds, kept apart because they are established differently. A checked one is read from the
 * installation by the action itself — the queue is Craft's own, the parent directory is writable —
 * and is simply met or not. An acknowledged one is something only a person can know — that a backup
 * exists, that a job is safe to run twice — and is met only by that person ticking it, every time.
 */
final class Prerequisite implements JsonSerializable
{
    public const CHECKED = 'checked';
    public const ACKNOWLEDGED = 'acknowledged';

    /** @var string A short name, the same every time, so a form can say which were acknowledged. */
    private const ID_PATTERN = '/\A[a-z][a-zA-Z0-9]{0,63}\z/';

    public readonly string $description;
    public readonly ?string $detail;

    /**
     * @param string $kind {@see self::CHECKED} or {@see self::ACKNOWLEDGED}.
     * @param bool $met Whether it held when it was read. Always false for an acknowledged one, which
     * only a person can meet.
     * @throws InvalidArgumentException for an ID or kind that is not one.
     */
    private function __construct(
        public readonly string $id,
        string $description,
        public readonly string $kind,
        public readonly bool $met,
        ?string $detail,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a prerequisite ID: a lowercase letter, then letters and digits.', $id));
        }

        if ($kind !== self::CHECKED && $kind !== self::ACKNOWLEDGED) {
            throw new InvalidArgumentException(sprintf('"%s" is not a kind of prerequisite.', $kind));
        }

        // An action's own words, but a checked one's detail can quote what it read.
        $this->description = Redaction::redactString($description);
        $this->detail = $detail === null || $detail === '' ? null : Redaction::redactString($detail);
    }

    /**
     * @param string|null $detail What was found, where it says more than met or not.
     */
    public static function checked(string $id, string $description, bool $met, ?string $detail = null): self
    {
        return new self($id, $description, self::CHECKED, $met, $detail);
    }

    public static function acknowledged(string $id, string $description): self
    {
        return new self($id, $description, self::ACKNOWLEDGED, false, null);
    }

    public function needsAcknowledging(): bool
    {
        return $this->kind === self::ACKNOWLEDGED;
    }

    /**
     * Reads a stored prerequisite. One that cannot be read is null, never a met one.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $id = $stored['id'] ?? null;
        $kind = $stored['kind'] ?? null;

        if (!is_string($id) || !is_string($kind)) {
            return null;
        }

        try {
            return new self(
                $id,
                is_string($stored['description'] ?? null) ? $stored['description'] : '',
                $kind,
                $kind === self::CHECKED && ($stored['met'] ?? null) === true,
                is_string($stored['detail'] ?? null) ? $stored['detail'] : null,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{id: string, kind: string, description: string, met: bool, detail: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'description' => $this->description,
            'met' => $this->met,
            'detail' => $this->detail,
        ];
    }
}
