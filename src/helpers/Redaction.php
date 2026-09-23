<?php

namespace Tahadudhiya\WebDoctor\helpers;

/**
 * The one place Web Doctor decides what a value may look like once it leaves the process that
 * read it: evidence, reports, API responses, webhook payloads, console output and log writes all
 * pass through here. Keeping it in one helper is what makes "never expose a secret" something
 * that can be checked rather than remembered.
 *
 * A secret is never masked into a shape that still hints at it. It is replaced outright, and
 * where the question is only whether something is configured it is answered with a presence word.
 */
final class Redaction
{
    /** What a sensitive value is replaced with. */
    public const REDACTED = '[redacted]';

    /** The vocabulary for describing a value Web Doctor may not show. */
    public const PRESENT = 'Present';
    public const MISSING = 'Missing';
    public const INVALID = 'Invalid';
    public const UNKNOWN = 'Unknown';

    /**
     * @var string[] Words that name a credential outright. A key is checked word by word, so
     * `dbPassword`, `DB_PASSWORD` and `db-password` are all caught by one entry — and
     * `tokenizer` and `author` are not caught at all. Matching on substrings instead would
     * redact enough ordinary keys to make evidence useless, which is its own kind of failure.
     */
    private const SENSITIVE_WORDS = [
        'password', 'passwd', 'pwd', 'passphrase', 'secret', 'secrets', 'token', 'credential',
        'credentials', 'authorization', 'signature', 'salt', 'dsn', 'cipher', 'cookie',
        'bearer', 'certificate', 'apikey', 'accesskey', 'privatekey', 'securitykey',
    ];

    /**
     * @var array<string, string[]> Words that only mean a credential next to another word, so
     * `apiKey` is redacted and `keyword` is not.
     */
    private const SENSITIVE_PAIRS = [
        'key' => ['api', 'access', 'private', 'public', 'security', 'secret', 'encryption', 'license', 'signing'],
        'id' => ['session'],
        'string' => ['connection'],
    ];

    /** How deep a structure is walked before the rest is dropped rather than explored. */
    private const MAX_DEPTH = 6;

    /** How many entries of one array are kept, so a stray large structure cannot be recorded. */
    private const MAX_ITEMS = 100;

    /** How long a recorded string may be. */
    private const MAX_STRING_LENGTH = 2000;

    /**
     * Whether a value is already one of the presence words, and so says nothing about the
     * secret it stands in for.
     */
    public static function isPresence(mixed $value): bool
    {
        return is_string($value)
            && in_array($value, [self::PRESENT, self::MISSING, self::INVALID, self::UNKNOWN], true);
    }

    /**
     * Whether the value behind this key is a credential and must never be recorded.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $words = self::words($key);

        if ($words === []) {
            return false;
        }

        // A key that is nothing but `key` is a credential; one that merely ends in it, like
        // `primaryKey`, is not — so the bare form is judged on its own.
        if ($words === ['key']) {
            return true;
        }

        foreach ($words as $i => $word) {
            if (in_array($word, self::SENSITIVE_WORDS, true)) {
                return true;
            }

            $qualifiers = self::SENSITIVE_PAIRS[$word] ?? null;

            if ($qualifiers !== null && $i > 0 && in_array($words[$i - 1], $qualifiers, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Breaks a key into its words, so `dbPassword`, `DB_PASSWORD` and `db-password` are all
     * read the same way.
     *
     * @return string[]
     */
    private static function words(string $key): array
    {
        $spaced = (string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $key);
        $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /**
     * Makes a structure safe to record: credentials replaced, unrepresentable values described
     * rather than serialized, and the whole thing bounded in depth, width and length.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        $safe = [];
        $kept = 0;

        foreach ($data as $key => $value) {
            if ($kept === self::MAX_ITEMS) {
                $safe['…'] = sprintf('%d more omitted', count($data) - $kept);
                break;
            }

            $kept++;

            // A presence word is what this helper produces in place of a secret, so redacting
            // it again would replace the answer with the mark that hides one — and `[redacted]`
            // where a reader expects `Missing` turns a real finding, a credential that is not
            // configured, into something that looks deliberately withheld.
            $safe[$key] = self::isSensitiveKey((string)$key) && !self::isPresence($value)
                ? self::REDACTED
                : self::redactValue($value, $depth + 1);
        }

        return $safe;
    }

    /**
     * Makes a single value safe to record. Objects and resources are described by what they
     * are, because serializing one is how a credential ends up somewhere nobody looked.
     */
    public static function redactValue(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH ? '[…]' : self::redact($value, $depth);
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return sprintf('[%s]', $value::class);
        }

        return '[' . gettype($value) . ']';
    }

    /**
     * Makes a loose string safe: credentials embedded in URLs and in `key=value` text are
     * removed, and the result is truncated so one log line cannot become the whole record.
     */
    public static function redactString(string $value): string
    {
        // Credentials in a URL's userinfo, as a database DSN or a webhook URL carries them.
        $value = (string)preg_replace('#([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]*@#i', '$1' . self::REDACTED . '@', $value);

        // An `Authorization: Bearer …` header, which carries its credential with no key beside
        // it. Exception messages from HTTP clients quote these in full.
        $value = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 ' . self::REDACTED, $value);

        // `password=…`, `api_key: …` and their kin, wherever they appear in free text.
        $value = self::redactPairs($value);

        if (strlen($value) > self::MAX_STRING_LENGTH) {
            $value = substr($value, 0, self::MAX_STRING_LENGTH) . '… [truncated]';
        }

        return $value;
    }

    /**
     * @var int How far a value is re-examined for a credential nested inside it. Each level
     * works on a strictly shorter string, so this only bounds pathological input.
     */
    private const MAX_PAIR_DEPTH = 4;

    /**
     * Removes `key=value` credentials from free text.
     *
     * The subtlety is what happens when the key is *not* a credential. An exception message
     * reads `SMTP refused: password=hunter2`, and a pattern matching any `key: value` pair
     * consumes `refused: password=hunter2` in one go — key `refused`, value `password=hunter2`.
     * Leaving that match alone then leaves the password in plain text, and the scan resumes
     * past it, so nothing else catches it either.
     *
     * So an innocent pair is not left alone: its value is examined in turn. That costs one more
     * pass over a shorter string and removes the whole class of near-misses, rather than
     * guessing which innocent words might precede a credential.
     */
    private static function redactPairs(string $value, int $depth = 0): string
    {
        if ($depth >= self::MAX_PAIR_DEPTH) {
            return $value;
        }

        return (string)preg_replace_callback(
            '/([A-Za-z_][A-Za-z0-9_.\-]*)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/',
            static fn(array $m): string => self::isSensitiveKey($m[1])
                ? $m[1] . $m[2] . self::REDACTED
                : $m[1] . $m[2] . self::redactPairs($m[3], $depth + 1),
            $value,
        );
    }

    /**
     * Describes whether something is configured without saying what it is. This is how Web
     * Doctor reports on a credential: the answer a developer needs is whether it is there.
     */
    public static function presence(mixed $value): string
    {
        if ($value === null) {
            return self::MISSING;
        }

        if (is_string($value)) {
            return trim($value) === '' ? self::MISSING : self::PRESENT;
        }

        if (is_array($value)) {
            return $value === [] ? self::MISSING : self::PRESENT;
        }

        return self::PRESENT;
    }
}
