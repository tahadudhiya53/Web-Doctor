<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * Where an issue stands. Two cases are deliberately not a person's to set.
 *
 * `RESOLVED` is established by a later run of the check that raised the issue, never asserted —
 * a button that set it would make the word mean "somebody clicked resolved". `REPAIRING` belongs
 * to whatever performs a repair, and nothing does yet.
 *
 * Somebody who has decided an issue needs no action says so with `IGNORED` or `WONT_FIX`, which
 * are honest about being judgements rather than outcomes and require a reason for that reason.
 */
enum IssueStatus: string
{
    /** Detected and not yet looked at. */
    case NEW = 'new';

    /** Somebody is working out what is going on. */
    case INVESTIGATING = 'investigating';

    /** Looked at and accepted as real. */
    case CONFIRMED = 'confirmed';

    /** A repair is under way. Reserved: nothing performs repairs yet. */
    case REPAIRING = 'repairing';

    /** The check that raised it no longer reports the problem. Never set by hand. */
    case RESOLVED = 'resolved';

    /** Seen, and deliberately not acted on for now. */
    case IGNORED = 'ignored';

    /** Seen, and deliberately never going to be acted on. */
    case WONT_FIX = 'wont_fix';

    public function label(): string
    {
        return match ($this) {
            self::NEW => Craft::t('web-doctor', 'New'),
            self::INVESTIGATING => Craft::t('web-doctor', 'Investigating'),
            self::CONFIRMED => Craft::t('web-doctor', 'Confirmed'),
            self::REPAIRING => Craft::t('web-doctor', 'Repairing'),
            self::RESOLVED => Craft::t('web-doctor', 'Resolved'),
            self::IGNORED => Craft::t('web-doctor', 'Ignored'),
            self::WONT_FIX => Craft::t('web-doctor', 'Won’t fix'),
        };
    }

    /**
     * Whether the issue is still outstanding. Only an open issue can be observed clear by a later
     * run: a decision somebody made is not a passing check's to overturn.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::NEW, self::INVESTIGATING, self::CONFIRMED, self::REPAIRING => true,
            self::RESOLVED, self::IGNORED, self::WONT_FIX => false,
        };
    }

    /**
     * Whether this status is somebody's standing decision rather than a state of the site. A
     * dismissed issue found again stays dismissed; the decision is theirs to reverse.
     */
    public function isDismissal(): bool
    {
        return $this === self::IGNORED || $this === self::WONT_FIX;
    }

    /** Whether choosing this status requires the person to say why. */
    public function requiresReason(): bool
    {
        return $this->isDismissal();
    }

    /**
     * Whether a person may move an issue into this status. Stated as what is refused, because the
     * two exclusions are the point.
     */
    public function isSettableByHand(): bool
    {
        return $this !== self::RESOLVED && $this !== self::REPAIRING;
    }

    /** Where this sits in the lifecycle, so sorting by status reads as progress not alphabet. */
    public function position(): int
    {
        return array_search($this, self::cases(), true) ?: 0;
    }

    /**
     * The statuses a person may choose from, in the order they are offered.
     *
     * @return list<self>
     */
    public static function settableByHand(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $s): bool => $s->isSettableByHand()));
    }

    /**
     * The statuses that mean an issue is still outstanding.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $s): bool => $s->isOpen()));
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
