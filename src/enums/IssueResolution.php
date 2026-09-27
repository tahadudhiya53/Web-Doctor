<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * On what grounds an issue was resolved. `RESOLVED` says it is closed; this says how much that is
 * worth, so the weaker claim cannot be read as the stronger.
 *
 * What Web Doctor can establish today is that the check stopped reporting the problem. That is
 * evidence, not proof that anything was fixed, and the control panel says so. A deliberate re-run
 * against a stated expectation is a stronger claim and gets its own case when something makes it.
 */
enum IssueResolution: string
{
    /** Not resolved. The issue is open, dismissed, or otherwise still standing. */
    case NONE = 'none';

    /** The check that raised it has since run and no longer reports the problem. */
    case OBSERVED_CLEAR = 'observedClear';

    public function label(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'Not resolved'),
            self::OBSERVED_CLEAR => Craft::t('web-doctor', 'Observed clear'),
        };
    }

    /** What the claim amounts to, for a reader deciding how much to trust it. */
    public function explanation(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'This issue has not been resolved.'),
            self::OBSERVED_CLEAR => Craft::t('web-doctor', 'The check that raised this issue ran again and no longer reports the problem. That is an observation, not a verification: nothing has confirmed that the underlying cause was addressed.'),
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
