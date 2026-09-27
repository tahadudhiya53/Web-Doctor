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
     * @param array<array-key, mixed> $stored
     * @param ConditionRole $fallback The part it played, for a role this version does not have —
     * taken from where it was stored, so something kept as evidence against a cause never reads
     * back as evidence for it.
     */
    public static function fromArray(array $stored, ConditionRole $fallback = ConditionRole::SUPPORTING): self
    {
        $observations = [];

        foreach ((array)($stored['observations'] ?? []) as $item) {
            if (is_array($item)) {
                $observations[] = Observation::fromArray($item);
            }
        }

        // Anything that is not what it should be reads as empty rather than being cast: casting an
        // array to text is a warning, and a warning in development mode is an exception.
        $text = static fn(mixed $value): string => is_string($value) ? $value : '';

        return new self(
            id: $text($stored['id'] ?? null),
            role: ConditionRole::tryFrom($text($stored['role'] ?? null)) ?? $fallback,
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
