<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * What happened to an issue: the moments worth keeping, rather than every run that saw it again
 * unchanged.
 *
 * A deliberate bound — an issue detected by every scheduled run for a year would otherwise
 * accumulate thousands of identical rows saying nothing. The exact number of sightings stays
 * accurate as a count and a timestamp on the issue itself.
 */
enum IssueEventType: string
{
    /** The issue was raised for the first time. */
    case DETECTED = 'detected';

    /** It was detected again after having been resolved. */
    case RECURRED = 'recurred';

    /** The finding changed while the issue was open — its severity, or what it says. */
    case CHANGED = 'changed';

    /** Somebody moved it through its lifecycle. */
    case STATUS_CHANGED = 'statusChanged';

    /** The check that raised it ran again and no longer reports the problem. */
    case RESOLVED = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::DETECTED => Craft::t('web-doctor', 'Detected'),
            self::RECURRED => Craft::t('web-doctor', 'Detected again'),
            self::CHANGED => Craft::t('web-doctor', 'Finding changed'),
            self::STATUS_CHANGED => Craft::t('web-doctor', 'Status changed'),
            self::RESOLVED => Craft::t('web-doctor', 'Observed clear'),
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
