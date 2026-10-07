<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * What an audited act was done to. Stored, so a case is never renamed once it exists.
 */
enum AuditObjectType: string
{
    /** A diagnostic run, identified by its run ID. */
    case RUN = 'run';
    case ISSUE = 'issue';
    case INVESTIGATION = 'investigation';
    case REPAIR = 'repair';
    case VERIFICATION = 'verification';

    public function label(): string
    {
        return match ($this) {
            self::RUN => Craft::t('web-doctor', 'Diagnostic run'),
            self::ISSUE => Craft::t('web-doctor', 'Issue'),
            self::INVESTIGATION => Craft::t('web-doctor', 'Investigation'),
            self::REPAIR => Craft::t('web-doctor', 'Repair'),
            self::VERIFICATION => Craft::t('web-doctor', 'Verification'),
        };
    }
}
