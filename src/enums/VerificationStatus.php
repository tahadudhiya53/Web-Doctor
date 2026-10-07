<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * Whether anything has established that a repair worked.
 *
 * A repair that ran is not a problem that went away: it could have run cleanly against the wrong
 * thing, or the problem could have had a second cause. So a carried-out repair starts as `PENDING`,
 * and a verification answers — the check that found the problem run again, with whatever else the
 * repair's verification looks at. Its three answers are the last three cases, and a repair carries
 * the latest of them.
 *
 * `VERIFIED` and `FAILED` are the vocabulary's two verification results. `INCONCLUSIVE` is the answer
 * when neither could be established — a check that could not answer, jobs that have not run yet,
 * something new since the repair — because reading that as either of the other two would claim more
 * than was seen.
 */
enum VerificationStatus: string
{
    /** Nothing was carried out cleanly, so there is nothing to verify. */
    case NONE = 'none';

    /** Carried out, and not yet verified. */
    case PENDING = 'pending';

    /** The check that found the problem no longer reports it, and everything else looked at held. */
    case VERIFIED = 'verified';

    /** The check that found the problem ran again, reached a conclusion, and still reports it. */
    case FAILED = 'verification_failed';

    /** Neither could be established. */
    case INCONCLUSIVE = 'inconclusive';

    public function label(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'Nothing to verify'),
            self::PENDING => Craft::t('web-doctor', 'Awaiting verification'),
            self::VERIFIED => Craft::t('web-doctor', 'Verified'),
            self::FAILED => Craft::t('web-doctor', 'Verification failed'),
            self::INCONCLUSIVE => Craft::t('web-doctor', 'Verification inconclusive'),
        };
    }

    /** What the state amounts to, for a reader deciding how much to trust the repair. */
    public function explanation(): string
    {
        return match ($this) {
            self::NONE => Craft::t('web-doctor', 'The repair was not carried out cleanly, so there is no result to verify. Run the checks listed to see where things stand.'),
            self::PENDING => Craft::t('web-doctor', 'The repair was carried out. Nothing has verified that it worked: verifying it runs the check that found the problem again, and only that check’s answer resolves the issue.'),
            self::VERIFIED => Craft::t('web-doctor', 'The check that found the problem ran again and no longer reports it, and everything else the verification looks at held.'),
            self::FAILED => Craft::t('web-doctor', 'The repair was completed, but the check that found the problem ran again and still reports it. The issue stays open.'),
            self::INCONCLUSIVE => Craft::t('web-doctor', 'The latest verification could not establish whether the repair worked. The reasons are listed with it; verify again once they have changed, or run the checks listed.'),
        };
    }

    /** Whether this is a verification's answer, rather than where a repair stands before one. */
    public function isResult(): bool
    {
        return $this === self::VERIFIED || $this === self::FAILED || $this === self::INCONCLUSIVE;
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
