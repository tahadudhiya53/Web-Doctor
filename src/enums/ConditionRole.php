<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * What one condition of a root-cause rule does to the cause it belongs to.
 *
 * A cause is weighed by what was found for it and against it, and every condition says in
 * advance which of those it is. That is what makes the confidence a cause ends up with something
 * a reader can check rather than a number to trust.
 */
enum ConditionRole: string
{
    /** Without it the cause is not considered at all. */
    case REQUIRED = 'required';

    /** Makes the cause more likely when it is found. */
    case SUPPORTING = 'supporting';

    /**
     * Establishes the cause outright when it is found — but only from a check that established
     * what it recorded, and only with nothing counting against it.
     */
    case CONFIRMING = 'confirming';

    /** Counts against the cause when it is found. */
    case CONTRADICTING = 'contradicting';

    public function label(): string
    {
        return match ($this) {
            self::REQUIRED => Craft::t('web-doctor', 'Required'),
            self::SUPPORTING => Craft::t('web-doctor', 'Supports it'),
            self::CONFIRMING => Craft::t('web-doctor', 'Establishes it'),
            self::CONTRADICTING => Craft::t('web-doctor', 'Counts against it'),
        };
    }

    /** Whether finding it is evidence for the cause. */
    public function isFor(): bool
    {
        return $this !== self::CONTRADICTING;
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
