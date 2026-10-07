<?php

namespace Tahadudhiya\WebDoctor\models;

use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\ConditionRole;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * One condition of a root-cause rule, as it was found: what it looks for, the part it plays, and
 * the observations that met it — none, when it was looked for and not found.
 */
final class ConditionOutcome implements JsonSerializable
{
    public readonly string $description;

    /**
     * @param list<Observation> $observations What met it, as many as were kept.
     * @param int $omitted How many more met it than were kept.
     */
    public function __construct(
        public readonly string $id,
        public readonly ConditionRole $role,
        string $description,
        public readonly array $observations = [],
        public readonly int $omitted = 0,
    ) {
        $this->description = Redaction::redactString($description);
    }

    public function met(): bool
    {
        return $this->observations !== [];
    }

    /**
     * Whether this establishes the cause. Found is not enough: at least one observation has to
     * come from something that established what it recorded, because a check that only suspected
     * a fact cannot turn it into proof by being quoted.
     */
    public function confirms(): bool
    {
        if ($this->role !== ConditionRole::CONFIRMING) {
            return false;
        }

        foreach ($this->observations as $observation) {
            if ($observation->confirmed) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stored outcome, or null where it cannot be read: a role this version does not have, or an
     * observation that cannot be read. Never read with another role in its place — evidence kept
     * against a cause must not come back as evidence for it, nor the other way round.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $observations = [];

        foreach ((array)($stored['observations'] ?? []) as $item) {
            $observation = is_array($item) ? Observation::fromArray($item) : null;

            // One observation that cannot be read leaves the condition unreadable, not smaller.
            if ($observation === null) {
                return null;
            }

            $observations[] = $observation;
        }

        $role = ConditionRole::tryFrom(is_string($stored['role'] ?? null) ? $stored['role'] : '');

        // A role this version does not have is not read as one it does: for and against are not
        // interchangeable.
        if ($role === null) {
            return null;
        }

        // Anything that is not what it should be reads as empty rather than being cast: casting an
        // array to text is a warning, and a warning in development mode is an exception.
        $text = static fn(mixed $value): string => is_string($value) ? $value : '';

        return new self(
            id: $text($stored['id'] ?? null),
            role: $role,
            description: $text($stored['description'] ?? null),
            observations: $observations,
            omitted: is_numeric($stored['omitted'] ?? null) ? max(0, (int)$stored['omitted']) : 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'description' => $this->description,
            'observations' => array_map(static fn(Observation $o): array => $o->jsonSerialize(), $this->observations),
            'omitted' => $this->omitted,
        ];
    }
}
