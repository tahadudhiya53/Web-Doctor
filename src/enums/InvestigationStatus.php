<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * Where an investigation stands.
 *
 * Says whether the investigation got the answers it set out to get, never what those answers
 * were. An investigation in which every related check found a failure is `COMPLETED`; one in
 * which half the checks could not tell is `PARTIAL`, however clean the other half looked —
 * because a reader deciding what to do next has to know which silence is an answer and which is
 * a gap.
 */
enum InvestigationStatus: string
{
    /** Started and not yet finished. One left here by a request that died stays here, honestly. */
    case RUNNING = 'running';

    /** Every check that was planned and available reached a conclusion. */
    case COMPLETED = 'completed';

    /** It finished, but some of what was planned could not be answered. */
    case PARTIAL = 'partial';

    /** The investigation itself broke before it could finish. */
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::RUNNING => Craft::t('web-doctor', 'Running'),
            self::COMPLETED => Craft::t('web-doctor', 'Completed'),
            self::PARTIAL => Craft::t('web-doctor', 'Partly completed'),
            self::FAILED => Craft::t('web-doctor', 'Could not finish'),
        };
    }

    public function isFinished(): bool
    {
        return $this !== self::RUNNING;
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
