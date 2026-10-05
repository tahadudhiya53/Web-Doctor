<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * How an audited act ended — whether it did what it set out to, never what it found. A diagnostic
 * run that found ten problems succeeded; a verification that established the repair did not work
 * failed, because establishing that a repair worked is what a verification sets out to do.
 */
enum AuditResult: string
{
    /** It did what it set out to. */
    case SUCCEEDED = 'succeeded';

    /** It did some of it: a run whose findings could not all be kept, an investigation that stopped part-way. */
    case PARTIAL = 'partial';

    /** It was attempted and did not do what it set out to. */
    case FAILED = 'failed';

    /** It ran, and could not establish an answer either way. */
    case INCONCLUSIVE = 'inconclusive';

    /** The entry records a beginning or a decision, which has no result of its own. */
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::SUCCEEDED => Craft::t('web-doctor', 'Succeeded'),
            self::PARTIAL => Craft::t('web-doctor', 'Partly'),
            self::FAILED => Craft::t('web-doctor', 'Failed'),
            self::INCONCLUSIVE => Craft::t('web-doctor', 'Inconclusive'),
            self::NONE => Craft::t('web-doctor', 'No result'),
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
