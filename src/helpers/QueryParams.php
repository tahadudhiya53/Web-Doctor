<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Craft;
use craft\db\Query;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * The one strict reading of a list page's query string: the Issue Center's, the repair history's
 * and the audit log's filters all read their parameters through it.
 *
 * A value left out, or sent empty as a form's "any" is, is null — the filter's default. A value
 * sent is taken exactly or refused with an exception naming the parameter, never a value: dropped,
 * a status nobody has would widen a list to every status, and coerced, `1.5` would be page 1.
 */
final class QueryParams
{
    /**
     * Whether a parameter was sent with something in it. Empty is what a form sends for "any".
     *
     * @param array<string, mixed> $params
     */
    public static function present(array $params, string $name): bool
    {
        return isset($params[$name]) && $params[$name] !== '' && $params[$name] !== [];
    }

    /**
     * The cases of a backed enum a parameter names, one or a list of them, each exactly.
     *
     * @template T of \BackedEnum
     * @param array<string, mixed> $params
     * @param class-string<T> $enum
     * @return list<T>
     * @throws InvalidArgumentException
     */
    public static function enums(array $params, string $name, string $enum): array
    {
        if (!self::present($params, $name)) {
            return [];
        }

        $values = is_array($params[$name]) && array_is_list($params[$name]) ? $params[$name] : [$params[$name]];
        $cases = [];

        foreach ($values as $candidate) {
            $case = is_string($candidate) ? $enum::tryFrom($candidate) : null;

            if ($case === null) {
                throw new InvalidArgumentException($name);
            }

            if (!in_array($case, $cases, true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * One of a fixed set of words, exactly, or null when none was sent.
     *
     * @param array<string, mixed> $params
     * @param list<string> $allowed
     * @throws InvalidArgumentException
     */
    public static function choice(array $params, string $name, array $allowed): ?string
    {
        if (!self::present($params, $name)) {
            return null;
        }

        if (!is_string($params[$name]) || !in_array($params[$name], $allowed, true)) {
            throw new InvalidArgumentException($name);
        }

        return $params[$name];
    }

    /**
     * Text, trimmed, no longer than a column holds, or null when none was sent. Too long is refused
     * rather than cut: cut, it would be asking for something else.
     *
     * @param array<string, mixed> $params
     * @throws InvalidArgumentException
     */
    public static function text(array $params, string $name, int $maxLength): ?string
    {
        if (!self::present($params, $name)) {
            return null;
        }

        $value = $params[$name];

        // Empty is "not sent"; only spaces is a value that is not one, refused rather than widened
        // into "any".
        if (!is_string($value) || mb_strlen(trim($value)) > $maxLength || ($value !== '' && trim($value) === '')) {
            throw new InvalidArgumentException($name);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A whole number above zero, written as one, or null when none was sent.
     *
     * @param array<string, mixed> $params
     * @throws InvalidArgumentException
     */
    public static function positive(array $params, string $name): ?int
    {
        if (!self::present($params, $name)) {
            return null;
        }

        if (!self::isPositiveNumber($params[$name])) {
            throw new InvalidArgumentException($name);
        }

        return (int)$params[$name];
    }

    /**
     * A whole number above zero, written as one: what a page or record ID can be.
     */
    public static function isPositiveNumber(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/\A[1-9]\d{0,17}\z/', $value) === 1);
    }

    /**
     * A calendar date, exactly `Y-m-d`, or null when none was sent. Parsed rather than trusted: it
     * ends up in a comparison against a datetime column.
     *
     * @param array<string, mixed> $params
     * @throws InvalidArgumentException
     */
    public static function date(array $params, string $name): ?string
    {
        if (!self::present($params, $name)) {
            return null;
        }

        $value = $params[$name];
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($name);
        }

        return $value;
    }

    /**
     * The values a list can be filtered by in one column of its own table: each once, in order, and
     * never empty or only spaces — neither can be filtered by, so offering one would offer a choice
     * that is refused or read as "any".
     *
     * @return list<string>
     */
    public static function choicesIn(string $table, string $column): array
    {
        $values = (new Query())->select([$column])->distinct()->from($table)->orderBy([$column => SORT_ASC])->column();

        return array_values(array_filter(array_map('strval', $values), static fn(string $value): bool => trim($value) !== ''));
    }

    /**
     * The first moment of a calendar day where the reader is, as the UTC timestamp the database
     * stores. Compared as strings instead, "today" would mean a window offset by the server's
     * distance from the reader for half of every day.
     *
     * The first moment, not midnight: where the clocks go forward at midnight the day begins at one
     * in the morning, and the date is still a date.
     *
     * The time zone is Craft's unless one is given. One that cannot be resolved is an error, never
     * UTC in its place: a filter that silently moved its window by hours would show a reader the
     * wrong day's records as the day they asked for.
     *
     * @throws InvalidConfigException if the time zone is not one.
     * @throws InvalidArgumentException if the date is not one.
     */
    public static function localDayStart(string $date, ?string $timeZone = null): string
    {
        return StoredTime::forDb(self::dayStart($date, self::zone($timeZone)));
    }

    /**
     * The last second of a calendar day where the reader is, as the UTC timestamp the database
     * stores: the second before the next day's first moment, however long the clocks made the day.
     *
     * @throws InvalidConfigException if the time zone is not one.
     * @throws InvalidArgumentException if the date is not one.
     */
    public static function localDayEnd(string $date, ?string $timeZone = null): string
    {
        $zone = self::zone($timeZone);
        $next = self::dayStart($date, $zone)->setTimezone(new DateTimeZone('UTC'))->modify('+36 hours')->setTimezone($zone)->format('Y-m-d');

        // In UTC: a second taken off the wall clock lands in the hour a midnight change skipped.
        return StoredTime::forDb(self::dayStart($next, $zone)->setTimezone(new DateTimeZone('UTC'))->modify('-1 second'));
    }

    /**
     * @throws InvalidConfigException
     */
    private static function zone(?string $timeZone): DateTimeZone
    {
        $timeZone ??= Craft::$app->getTimeZone();

        try {
            return new DateTimeZone($timeZone);
        } catch (Throwable $e) {
            throw new InvalidConfigException(sprintf('The time zone "%s" could not be resolved, so a date cannot be placed in it.', $timeZone), 0, $e);
        }
    }

    private static function dayStart(string $date, DateTimeZone $zone): DateTimeImmutable
    {
        // The date is checked on its own, so a calendar date is never refused for the hour its
        // clocks skipped — and 2026-02-30 is never rolled over into March.
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('date');
        }

        return (new DateTimeImmutable($date . ' 00:00:00', $zone))->setTime(0, 0);
    }
}
