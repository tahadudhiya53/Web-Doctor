<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * Whether anything has established that a repair worked.
 *
 * A repair that ran is not a problem that went away: it could have run cleanly against the wrong
 * thing, or the problem could have had a second cause. So a carried-out repair starts as `PENDING`,
 * and it is the check that found the problem, run again, that answers. `verified` and
 * `verification_failed` are the vocabulary's two answers and arrive with whatever gives them; no
 * case is added ahead of something that could produce it.
 */
enum VerificationStatus: string
{
    /** Nothing was carried out cleanly, so there is nothing to verify. */
    case NONE = 'none';

    /** Carried out, and not yet verified. */
    case PENDING = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'Nothing to verify'),
            self::PENDING => Craft::t('web-doctor', 'Awaiting verification'),
        };
    }

    /** What the state amounts to, for a reader deciding how much to trust the repair. */
    public function explanation(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'The repair was not carried out cleanly, so there is no result to verify. Run the checks listed to see where things stand.'),
            self::PENDING => Craft::t('web-doctor', 'The repair was carried out. Nothing has verified that it worked: the issue is resolved only when the check that found the problem runs again and no longer reports it.'),
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
