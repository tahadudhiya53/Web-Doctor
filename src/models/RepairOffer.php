<?php

namespace Tahadudhiya\WebDoctor\models;

use Tahadudhiya\WebDoctor\base\RepairActionInterface;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * A repair action as a page shows it, before anything has been previewed: what it is, how risky and
 * why, how it is verified, and whether this reader could preview it now.
 *
 * Redacted as it is built. What an action says about itself is whatever its author wrote, and a page
 * shows it before any preview has stored — and redacted — a copy.
 */
final class RepairOffer
{
    public readonly string $name;
    public readonly string $description;
    public readonly string $riskReason;
    public readonly string $authorization;

    /**
     * @param list<string> $verifyWith
     * @param bool $available Whether this reader could preview it now: the issue can be repaired
     * here, they may run repairs, and Craft allows them the same action.
     */
    private function __construct(
        public readonly string $id,
        public readonly ?string $recommendation,
        string $name,
        string $description,
        public readonly RepairRisk $risk,
        string $riskReason,
        public readonly array $verifyWith,
        string $authorization,
        public readonly bool $authorized,
        public readonly bool $available,
    ) {
        $this->name = Redaction::redactString($name);
        $this->description = Redaction::redactString($description);
        $this->riskReason = Redaction::redactString($riskReason);
        $this->authorization = Redaction::redactString($authorization);
    }

    /**
     * @param bool $mayRun Whether the issue can be repaired here and this reader may run repairs.
     * @param bool $authorized Whether Craft allows this reader the same action.
     */
    public static function of(RepairActionInterface $action, bool $mayRun, bool $authorized): self
    {
        return new self(
            id: $action->id(),
            recommendation: $action->recommendation(),
            name: $action->name(),
            description: $action->description(),
            risk: $action->risk(),
            riskReason: $action->riskReason(),
            verifyWith: $action->verifyWith(),
            authorization: $action->authorization(),
            authorized: $authorized,
            available: $mayRun && $authorized,
        );
    }
}
