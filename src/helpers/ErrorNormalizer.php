<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Craft;
use Throwable;

/**
 * Reduces what an exception says to the part that stays the same each time the same thing goes
 * wrong, so two occurrences of one error can be recognised as one.
 *
 * Every rule removes a value that varies between occurrences while the cause stays put — an ID,
 * a moment, a UUID, a token, the address a request came from, the release directory a deploy
 * created — and each rule is anchored to a shape or a word that says the value is one of those.
 * Nothing is removed for merely being a number or a name: an error code, an HTTP status, a table
 * name or a column name is often the only thing that tells two different causes apart, and two
 * unrelated errors merged into one is the worse mistake. Kept apart, the same error only makes a
 * longer list.
 */
final class ErrorNormalizer
{
    /** @var int The most of a normalised message that is kept. */
    public const MAX_MESSAGE_LENGTH = 1000;

    /** @var int How many frames from the top of a trace identify the path an error took. */
    public const STACK_DEPTH = 5;

    /**
     * @var string Words that say the number after them names one row, job or request. Plural
     * forms are matched too. "Request" and "message" are deliberately not here: the number after
     * them is as often a status — `Request 404`, `Message 550` — as an identity.
     */
    private const COUNTED_NOUNS = 'element|entry|entries|asset|user|job|order|product|variant|category|categories|tag|draft|revision|record|row|item|session|attempt|process|pid|worker|batch|offset|chunk';

    /** @var string Units a measured amount is written in. The amount varies; the unit does not. */
    private const UNITS = 'bytes?|[KMG]i?B|ms|milliseconds?|secs?|seconds?|minutes?';

    /**
     * What an exception message says, with its volatile values replaced by what kind of value
     * they were.
     *
     * @param string|null $root The installation's root path, so a path inside it reads the same
     * whichever release directory it was deployed into.
     */
    public static function message(string $message, ?string $root = null): string
    {
        $message = trim((string)preg_replace('/\s+/u', ' ', mb_scrub($message, 'UTF-8')));

        $message = self::paths($message, $root);

        // Whole URLs before anything else looks inside them, so an ID in a path segment and a
        // query string are handled as parts of an address rather than as loose text.
        $message = (string)preg_replace_callback(
            '~\bhttps?://[^\s\'"<>()]+~i',
            static fn(array $m): string => self::url($m[0]),
            $message,
        );

        $message = self::values($message);

        return self::cut($message, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * A file path, relative to the installation where it is inside it, so the same file reads
     * the same after a deploy moved the installation to a new release directory.
     */
    public static function path(string $path, ?string $root = null): string
    {
        $path = str_replace('\\', '/', trim($path));
        $root = self::normaliseRoot($root);

        if ($root !== null && str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        // Code Composer installed reads the same wherever the installation is.
        $vendor = strrpos($path, '/vendor/');

        if ($vendor !== false) {
            return substr($path, $vendor + 1);
        }

        return self::releaseDirectories($path);
    }

    /**
     * Where an exception was thrown, as `file:line` with the file made relative. The line is kept:
     * two different throws in one file are two different errors, and an error that moves line
     * because the code around it changed is safer counted twice than merged with a neighbour.
     */
    public static function origin(string $origin, ?string $root = null): string
    {
        if (preg_match('/^(.*):(\d+)$/s', trim($origin), $m) === 1) {
            return self::path($m[1], $root) . ':' . $m[2];
        }

        return self::path($origin, $root);
    }

    /**
     * An exception's class, with an anonymous class reduced to what it extends. PHP names an
     * anonymous class after the file and line it was declared on plus a counter, none of which
     * says what went wrong.
     */
    public static function className(string $class): string
    {
        return (string)preg_replace('/@anonymous.*?(?=->|::|$)/s', '@anonymous', trim($class));
    }

    /**
     * One stack frame, as {@see \Tahadudhiya\WebDoctor\models\SafeException} writes it, with its
     * file made relative. The call is kept whole; a closure's generated name is reduced to
     * `{closure}` because PHP puts the line it was declared on inside it.
     */
    public static function frame(string $frame, ?string $root = null): string
    {
        if (preg_match('/^(.*) \((.*):(\d+|\?)\)$/s', $frame, $m) !== 1) {
            return self::callable($frame);
        }

        return sprintf('%s (%s:%s)', self::callable($m[1]), self::path($m[2], $root), $m[3]);
    }

    /**
     * The path an error took, as the calls at the top of its trace, without files, lines or
     * arguments. Two traces through the same calls are the same path however the files around
     * them have moved; null when there is no trace to say.
     *
     * @param list<string> $frames
     */
    public static function stackFingerprint(array $frames): ?string
    {
        $calls = [];

        foreach (array_slice($frames, 0, self::STACK_DEPTH) as $frame) {
            $calls[] = preg_match('/^(.*) \((.*):(\d+|\?)\)$/s', $frame, $m) === 1
                ? self::callable($m[1])
                : self::callable($frame);
        }

        return $calls === [] ? null : Fingerprint::of(['stack', ...$calls]);
    }

    /**
     * The installation's root, where Craft can say. Asked for rather than assumed, because a path
     * made relative to the wrong root is a path that merely looks tidier.
     */
    public static function root(): ?string
    {
        try {
            $root = Craft::getAlias('@root', false);
        } catch (Throwable) {
            $root = false;
        }

        if (is_string($root) && $root !== '') {
            return $root;
        }

        return defined('CRAFT_BASE_PATH') && is_string(CRAFT_BASE_PATH) ? CRAFT_BASE_PATH : null;
    }

    /**
     * The values in running text that vary while the error stays the same. The order matters:
     * shapes that contain digits — a UUID, a moment, an address — are recognised whole before
     * anything looks at the digits inside them.
     */
    private static function values(string $text): string
    {
        $rules = [
            // A UUID is an identity by definition.
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            // Moments: ISO 8601 and the SQL form, with or without a time; RFC 2822, as mail and
            // HTTP write it; a time of day on its own; a Unix timestamp in seconds or
            // milliseconds from this century.
            '/\b\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:[.,]\d+)?)?(?:\s?(?:Z|UTC|[+-]\d{2}:?\d{2}))?)?\b/' => '{timestamp}',
            '/\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{1,2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}(?::\d{2})?(?: (?:[+-]\d{4}|GMT|UTC))?/' => '{timestamp}',
            // An address a request came from, or a server behind a balancer that rotates: which
            // one it was this time is a reading, and a client's address is personal data too.
            // Before times of day, whose shape part of an IPv6 address shares.
            '/\b(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}\b/i' => '{ip}',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '{ip}',
            '/\b\d{2}:\d{2}:\d{2}(?:\.\d+)?\b/' => '{time}',
            // Not when a unit follows: 1073741824 bytes is an amount, handled with the others below.
            '/\b1\d{9}(?:\d{3}|\.\d+)?\b(?!\s?(?:' . self::UNITS . ')\b)/' => '{timestamp}',
            '/\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b/' => '{email}',
            // Memory addresses and hashes. An address is nine or more hex digits; eight or fewer is
            // an error code — `0x80070005` — and kept. Long random tokens are recognised below.
            '/\b0x[0-9a-f]{9,}\b/i' => '{hex}',
            '/\b(?=[0-9a-f]*[a-f])(?=[0-9a-f]*\d)[0-9a-f]{16,}\b/i' => '{hex}',
            // A quoted value is a value rather than a name when it is a compound of digits — the
            // duplicated key in "Duplicate entry '45-1' for key 'idx'" — or all digits after a
            // word that introduces a value. A lone quoted number anywhere else is left alone: in
            // "error code '1045'" it is the only thing telling two errors apart.
            '/([\'"`])\d+(?:[-_,]\d+)+\1/' => '$1{n}$1',
            '/\b(entry|value|values)(\s+)([\'"`])\d+\3/i' => '$1$2$3{n}$3',
            // A list of IDs in SQL, however long this time.
            '/\bIN\s*\(\s*\d+(?:\s*,\s*\d+)*\s*\)/i' => 'IN ({ids})',
            // A number under a name that says it is an ID: `id`, `uid`, or a camel- or
            // snake-case name ending in one — `elementId=5`, `site_id: 2`, `"userId": 9`.
            '/\b((?:[A-Za-z]+(?:Id|ID|_id|_ID|Ids|IDs|_ids))|id|ID|Id|ids|IDs|uid|UID)([`\'"\]]?\s*(?:=|:|\bis\b)\s*[\'"]?|\s+#?)\d+\b/' => '$1$2{id}',
            // PHP's object number after a class — `Object(Entry)#512` — and nothing else: in
            // "Error #1045" the number after `#` is the error.
            '/\)#\d+\b/' => ')#{id}',
            '/\b(' . self::COUNTED_NOUNS . ')(\s+(?:(?:id|ID|number|no\.?)\s*)?[:#=]?\s*)\d+\b/i' => '$1$2{id}',
            // An amount in a unit: how much memory this allocation tried for, how long this
            // request waited. The unit says what ran out; the amount is this occurrence's.
            '/\b\d+(?:\.\d+)?\s?(' . self::UNITS . ')\b/' => '{n} $1',
        ];

        foreach ($rules as $pattern => $replacement) {
            $text = (string)preg_replace($pattern, $replacement, $text);
        }

        return self::tokens($text);
    }

    /**
     * Long random-looking tokens: 24 or more letters and digits with no separator, the digits
     * scattered through it in at least three places. A generated token has its digits spread
     * about; a name that happens to be long and contain a digit — `Oauth2AuthorizationServer` —
     * does not, and is the only thing that tells two errors about two classes apart.
     */
    private static function tokens(string $text): string
    {
        return (string)preg_replace_callback(
            '/\b[A-Za-z0-9]{24,}\b/',
            static fn(array $m): string => preg_match('/[A-Za-z]/', $m[0]) === 1 && preg_match_all('/\d+/', $m[0]) >= 3 ? '{token}' : $m[0],
            $text,
        );
    }

    /**
     * An address, keeping where it points and dropping what this request carried: the scheme,
     * host and port identify the service, and a path segment that is an ID or a token is this
     * request's. The query string and fragment are dropped whole.
     */
    private static function url(string $url): string
    {
        // A sentence ending in an address keeps its full stop.
        $trailing = '';

        if (preg_match('/[.,;:!?]+$/', $url, $m) === 1) {
            $trailing = $m[0];
            $url = substr($url, 0, -strlen($trailing));
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return $url . $trailing;
        }

        $out = strtolower($parts['scheme'] ?? 'http') . '://' . strtolower($parts['host']);

        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            $segments = array_map(static function(string $segment): string {
                if ($segment === '') {
                    return '';
                }

                if (ctype_digit($segment)) {
                    return '{id}';
                }

                return self::values($segment);
            }, explode('/', $parts['path']));

            $out .= implode('/', $segments);
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $out .= '?{query}';
        }

        return $out . $trailing;
    }

    /**
     * Paths in running text: inside the installation they read from its root, and a release
     * directory a deploy tool named after the moment it deployed reads as one.
     */
    private static function paths(string $text, ?string $root): string
    {
        $root = self::normaliseRoot($root);

        if ($root !== null) {
            $text = str_replace([$root . '/', str_replace('/', '\\', $root) . '\\'], '@root/', $text);
        }

        // PHP's upload temporaries are named at random.
        $text = (string)preg_replace('~(/tmp/php)[A-Za-z0-9]{6}\b~', '$1{tmp}', $text);

        return self::releaseDirectories($text);
    }

    private static function releaseDirectories(string $text): string
    {
        return (string)preg_replace('~/releases/\d{6,}(?=/)~', '/releases/{release}', $text);
    }

    /**
     * A call from a frame, without anything PHP generates per declaration.
     */
    private static function callable(string $call): string
    {
        $call = self::className(trim($call));

        return (string)preg_replace('/\{closure[^}]*\}/', '{closure}', $call);
    }

    private static function normaliseRoot(?string $root): ?string
    {
        if ($root === null) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', trim($root)), '/');

        return $root === '' ? null : $root;
    }

    private static function cut(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
