<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * On what grounds an issue was resolved. `RESOLVED` says it is closed; this says how much that is
 * worth, so the weaker claim cannot be read as the stronger.
 *
 * Observing the check stop reporting the problem is evidence, not proof that anything was fixed, and
 * the control panel says so. Verifying a repair is the stronger claim: the check run again on
 * purpose, against what the repair said should then be true, with nothing else it looks at out of
 * place. Only a verification that establishes that makes an issue's resolution `VERIFIED`.
 */
enum IssueResolution: string
{
    /** Not resolved. The issue is open, dismissed, or otherwise still standing. */
    case NONE = 'none';

    /** The check that raised it has since run and no longer reports the problem. */
    case OBSERVED_CLEAR = 'observedClear';

    /** A repair of it was verified: its check ran again for that and no longer reports the problem. */
    case VERIFIED = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'Not resolved'),
            self::OBSERVED_CLEAR => Craft::t('web-doctor', 'Observed clear'),
            self::VERIFIED => Craft::t('web-doctor', 'Repair verified'),
        };
    }

    /** What the claim amounts to, for a reader deciding how much to trust it. */
    public function explanation(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'This issue has not been resolved.'),
            self::OBSERVED_CLEAR => Craft::t('web-doctor', 'The check that raised this issue ran again and no longer reports the problem. That is an observation, not a verification: nothing has confirmed that the underlying cause was addressed.'),
            self::VERIFIED => Craft::t('web-doctor', 'A repair of this issue was verified: the check that raised it ran again to verify the repair and no longer reports the problem, and everything else the verification looks at held.'),
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
