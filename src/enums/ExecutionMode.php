<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * What set a diagnostic run going.
 *
 * Recorded on the run so that later — in history, reports and correlation — a run started by a
 * person can be told apart from one a schedule or a deployment pipeline started.
 */
enum ExecutionMode: string
{
    /** A person asked for it in the control panel. */
    case MANUAL = 'manual';

    /** A command line invocation. */
    case CONSOLE = 'console';

    /** A schedule asked for it. */
    case SCHEDULED = 'scheduled';

    /** An authenticated API request asked for it. */
    case API = 'api';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => Craft::t('web-doctor', 'Manual'),
            self::CONSOLE => Craft::t('web-doctor', 'Console'),
            self::SCHEDULED => Craft::t('web-doctor', 'Scheduled'),
            self::API => Craft::t('web-doctor', 'API'),
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
