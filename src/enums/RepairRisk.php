<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * How much could go wrong in carrying out a corrective action.
 *
 * The risk of the action, never the seriousness of the problem: a critical finding can be answered
 * by something harmless, and a minor one by something that rewrites the database. Every risk is
 * stated with its reason, so it can be checked rather than taken on trust.
 */
enum RepairRisk: string
{
    /** Changes nothing that works now, or only the one thing that is broken. */
    case LOW = 'low';

    /** Changes stored state that can be put back, or that other things depend on. */
    case MEDIUM = 'medium';

    /** Rewrites or replaces data, or cannot be put back without a backup. */
    case HIGH = 'high';

    public function label(): string
    {
        return match ($this) {
            self::LOW => Craft::t('web-doctor', 'Low risk'),
            self::MEDIUM => Craft::t('web-doctor', 'Medium risk'),
            self::HIGH => Craft::t('web-doctor', 'High risk'),
        };
    }

    /** What the level means, the same wherever it is shown. */
    public function explanation(): string
    {
        return match ($this) {
            self::LOW => Craft::t('web-doctor', 'Changes nothing that works now, or only the thing that is broken.'),
            self::MEDIUM => Craft::t('web-doctor', 'Changes stored state that other things depend on. Take a backup first.'),
            self::HIGH => Craft::t('web-doctor', 'Rewrites or replaces data, and cannot be undone without a backup.'),
        };
    }
}
