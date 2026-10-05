<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * What somebody, or Web Doctor on their behalf, did that the audit trail records.
 *
 * Only acts: running checks, investigating, the recommendations a run produced, previewing,
 * carrying out and verifying a repair, and an issue being resolved or moved by hand. Reading a page
 * is not an act and is never recorded, and a request that was refused changed nothing and is in
 * Craft's log rather than here.
 *
 * The values are stored, so a case is never renamed once it exists.
 */
enum AuditAction: string
{
    case DIAGNOSTICS_STARTED = 'diagnostics.started';
    case DIAGNOSTICS_COMPLETED = 'diagnostics.completed';
    case INVESTIGATION_STARTED = 'investigation.started';
    case INVESTIGATION_COMPLETED = 'investigation.completed';
    case RECOMMENDATIONS_GENERATED = 'recommendations.generated';
    case REPAIR_PREVIEWED = 'repair.previewed';
    case REPAIR_EXECUTED = 'repair.executed';
    case VERIFICATION_EXECUTED = 'verification.executed';
    case ISSUE_RESOLVED = 'issue.resolved';
    case ISSUE_STATUS_CHANGED = 'issue.statusChanged';

    public function label(): string
    {
        return match ($this) {
            self::DIAGNOSTICS_STARTED => Craft::t('web-doctor', 'Diagnostics started'),
            self::DIAGNOSTICS_COMPLETED => Craft::t('web-doctor', 'Diagnostics completed'),
            self::INVESTIGATION_STARTED => Craft::t('web-doctor', 'Investigation started'),
            self::INVESTIGATION_COMPLETED => Craft::t('web-doctor', 'Investigation completed'),
            self::RECOMMENDATIONS_GENERATED => Craft::t('web-doctor', 'Recommendations generated'),
            self::REPAIR_PREVIEWED => Craft::t('web-doctor', 'Repair previewed'),
            self::REPAIR_EXECUTED => Craft::t('web-doctor', 'Repair carried out'),
            self::VERIFICATION_EXECUTED => Craft::t('web-doctor', 'Verification run'),
            self::ISSUE_RESOLVED => Craft::t('web-doctor', 'Issue resolved'),
            self::ISSUE_STATUS_CHANGED => Craft::t('web-doctor', 'Issue status changed'),
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
