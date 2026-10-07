<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * Where a repair stands: whether it was carried out and whether that went through. Never whether
 * it worked — that is what verification answers, and a repair that ran cleanly and fixed nothing is
 * still `SUCCEEDED` here.
 */
enum RepairStatus: string
{
    /** Previewed and waiting for somebody to confirm it. Nothing has been changed. */
    case PREVIEWED = 'previewed';

    /** Confirmed and being carried out. One left here by a request that died stays here, honestly. */
    case RUNNING = 'running';

    /** Carried out without an error. */
    case SUCCEEDED = 'succeeded';

    /** Started and stopped by an error. It may have made some of its changes. */
    case FAILED = 'failed';

    /** A preview the same person replaced with a newer one. Kept as history; never confirmable. */
    case SUPERSEDED = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::PREVIEWED => Craft::t('web-doctor', 'Previewed'),
            self::RUNNING => Craft::t('web-doctor', 'Being carried out'),
            self::SUCCEEDED => Craft::t('web-doctor', 'Carried out'),
            self::FAILED => Craft::t('web-doctor', 'Failed'),
            self::SUPERSEDED => Craft::t('web-doctor', 'Replaced by a newer preview'),
        };
    }

    /** Whether it was carried out, cleanly or not. */
    public function wasExecuted(): bool
    {
        return $this === self::SUCCEEDED || $this === self::FAILED;
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
