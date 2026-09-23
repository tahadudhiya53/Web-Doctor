<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * How far a diagnostic should go.
 *
 * Depth is how Web Doctor avoids becoming the performance problem it is meant to find: a
 * diagnostic reads this from its context and bounds its own work accordingly, rather than
 * every run costing whatever the most expensive possible run would cost.
 */
enum DiagnosticDepth: string
{
    /** Cheap checks only. Safe to run often. */
    case SHALLOW = 'shallow';

    /** The default balance between cost and completeness. */
    case NORMAL = 'normal';

    /** Everything, including expensive work. Belongs in a queued run. */
    case DEEP = 'deep';

    public function label(): string
    {
        return match ($this) {
            self::SHALLOW => Craft::t('web-doctor', 'Shallow'),
            self::NORMAL => Craft::t('web-doctor', 'Normal'),
            self::DEEP => Craft::t('web-doctor', 'Deep'),
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::SHALLOW => 0,
            self::NORMAL => 1,
            self::DEEP => 2,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
