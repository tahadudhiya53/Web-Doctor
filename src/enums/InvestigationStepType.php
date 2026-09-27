<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * One entry in an investigation's timeline.
 *
 * The timeline is the investigation's own account of itself, in the order things happened:
 * that it began, what it decided to look at and why, what each check reported, what that did to
 * the Issue Center, what else was already known to be wrong nearby, and how it ended.
 */
enum InvestigationStepType: string
{
    /** Somebody asked for it. */
    case STARTED = 'started';

    /** Which checks were chosen, and which areas no check covers. */
    case PLANNED = 'planned';

    /** One check ran, and what it reported. */
    case CHECKED = 'checked';

    /** What the checks' findings did to the Issue Center. */
    case RECONCILED = 'reconciled';

    /** An issue already open in a related area, as it stood at the time. */
    case RELATED_ISSUE = 'relatedIssue';

    /** What was found, weighed against the known causes. */
    case DIAGNOSED = 'diagnosed';

    /** It finished, completely or partly. */
    case FINISHED = 'finished';

    /** It broke before it could finish. */
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::STARTED => Craft::t('web-doctor', 'Investigation started'),
            self::PLANNED => Craft::t('web-doctor', 'Checks chosen'),
            self::CHECKED => Craft::t('web-doctor', 'Check ran'),
            self::RECONCILED => Craft::t('web-doctor', 'Issue list updated'),
            self::RELATED_ISSUE => Craft::t('web-doctor', 'Related issue open'),
            self::DIAGNOSED => Craft::t('web-doctor', 'Causes weighed'),
            self::FINISHED => Craft::t('web-doctor', 'Investigation finished'),
            self::FAILED => Craft::t('web-doctor', 'Investigation failed'),
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
