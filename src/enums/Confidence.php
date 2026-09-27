<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * How firmly a conclusion is held.
 *
 * Nothing may claim {@see self::CONFIRMED} unless the evidence attached to the result actually
 * establishes it. Everything below that is explicitly a judgement, and says so to the reader.
 */
enum Confidence: string
{
    /** The evidence establishes this outright. */
    case CONFIRMED = 'confirmed';

    /** The evidence strongly indicates this, without proving it. */
    case HIGH = 'high';

    /** The evidence points this way. */
    case LIKELY = 'likely';

    /** Consistent with the evidence, among other explanations. */
    case POSSIBLE = 'possible';

    /** Reported for context, not offered as a conclusion. */
    case INFORMATIONAL = 'informational';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => Craft::t('web-doctor', 'Confirmed'),
            self::HIGH => Craft::t('web-doctor', 'High confidence'),
            self::LIKELY => Craft::t('web-doctor', 'Likely'),
            self::POSSIBLE => Craft::t('web-doctor', 'Possible'),
            self::INFORMATIONAL => Craft::t('web-doctor', 'Informational'),
        };
    }

    /**
     * How firmly this is held, from context to certainty. Root causes are ordered and weighed
     * through this, so the order is stated once rather than re-derived wherever they are ranked.
     */
    public function rank(): int
    {
        return match ($this) {
            self::INFORMATIONAL => 0,
            self::POSSIBLE => 1,
            self::LIKELY => 2,
            self::HIGH => 3,
            self::CONFIRMED => 4,
        };
    }
}
