<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use JsonSerializable;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use yii\base\InvalidArgumentException;

/**
 * Something that should be true once a particular repair has worked — the directories it created
 * still exist, the jobs it retried have run — read live when the repair is verified.
 *
 * Three states, because the third is the honest answer more often than it seems: a retried job that
 * has not run yet has neither failed again nor succeeded, and saying either would claim more than
 * was seen.
 */
final class VerificationCondition implements JsonSerializable
{
    public const HELD = 'held';
    public const NOT_HELD = 'notHeld';
    public const UNDETERMINED = 'undetermined';

    /** @var string A short name, the same every time, as a prerequisite's is. */
    private const ID_PATTERN = '/\A[a-z][a-zA-Z0-9]{0,63}\z/';

    public readonly string $description;
    public readonly ?string $detail;

    /**
     * @throws InvalidArgumentException for an ID or state that is not one.
     */
    private function __construct(
        public readonly string $id,
        string $description,
        public readonly string $state,
        ?string $detail,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a verification condition ID: a lowercase letter, then letters and digits.', $id));
        }

        if (!in_array($state, [self::HELD, self::NOT_HELD, self::UNDETERMINED], true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a state a verification condition can be in.', $state));
        }

        // An action's own words, but the detail quotes what it read.
        $this->description = Redaction::redactString($description);
        $this->detail = $detail === null || $detail === '' ? null : Redaction::redactString($detail);
    }

    /**
     * @param string|null $detail What was found, where it says more than the state.
     */
    public static function held(string $id, string $description, ?string $detail = null): self
    {
        return new self($id, $description, self::HELD, $detail);
    }

    public static function notHeld(string $id, string $description, ?string $detail = null): self
    {
        return new self($id, $description, self::NOT_HELD, $detail);
    }

    /** Neither held nor not: it could not be read, or cannot be told yet. */
    public static function undetermined(string $id, string $description, ?string $detail = null): self
    {
        return new self($id, $description, self::UNDETERMINED, $detail);
    }

    public function label(): string
    {
        return match ($this->state) {
            self::HELD => Craft::t('web-doctor', 'Holds'),
            self::NOT_HELD => Craft::t('web-doctor', 'Does not hold'),
            default => Craft::t('web-doctor', 'Cannot tell'),
        };
    }

    /**
     * Reads a stored condition. One that cannot be read is undetermined, never held.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $id = is_string($stored['id'] ?? null) ? $stored['id'] : '';
        $state = $stored['state'] ?? null;
        $description = is_string($stored['description'] ?? null) ? $stored['description'] : '';
        $detail = is_string($stored['detail'] ?? null) ? $stored['detail'] : null;

        try {
            return new self($id, $description, is_string($state) ? $state : '', $detail);
        } catch (InvalidArgumentException) {
            // Not read as undetermined, which is an answer: the verification says it cannot be read.
            return null;
        }
    }

    /**
     * @return array{id: string, state: string, description: string, detail: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'description' => $this->description,
            'detail' => $this->detail,
        ];
    }
}
