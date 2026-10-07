<?php

namespace Tahadudhiya\WebDoctor\helpers;

use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * Moments as the database stores them, written and read strictly.
 *
 * Craft stores a moment as UTC text in `Y-m-d H:i:s`. Writing one that cannot be prepared is an
 * error, never the current time in its place; reading one that is not exactly that shape — empty,
 * malformed, an impossible date, MySQL's zero date — is unreadable, never the epoch or now. A record
 * of when something happened that says a different time from the one it happened at is worse than a
 * record that says it cannot be read.
 */
final class StoredTime
{
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * @throws RuntimeException if Craft cannot prepare the moment for the database.
     */
    public static function forDb(DateTimeInterface $when): string
    {
        $prepared = Db::prepareDateForDb($when);

        if (!is_string($prepared) || self::read($prepared) === null) {
            throw new RuntimeException('A moment could not be prepared for the database.');
        }

        return $prepared;
    }

    /**
     * A stored moment, or null where what is stored is not one. Callers that require a moment treat
     * null as unreadable; only a column that may legitimately be empty may read null as "none".
     */
    public static function read(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new DateTimeZone('UTC'));

        // Round-tripped, so 2026-02-30 and 0000-00-00 — which PHP rolls over into other dates — are
        // refused rather than read as the date they roll over into.
        if ($parsed === false || $parsed->format(self::FORMAT) !== $value || (int)$parsed->format('Y') < 1) {
            return null;
        }

        return $parsed;
    }

    /**
     * For a column that may legitimately be empty: null when nothing is stored, the moment when one
     * is, and `false` when something is stored that is not a moment.
     */
    public static function readOptional(mixed $value): DateTimeImmutable|false|null
    {
        if ($value === null) {
            return null;
        }

        return self::read($value) ?? false;
    }
}
