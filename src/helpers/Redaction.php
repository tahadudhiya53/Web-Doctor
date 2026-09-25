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

    /**
     * The other marks this helper leaves behind. They are constants so that whatever displays a
     * redacted value can recognise them and say what happened, rather than showing a reader a
     * bracket and leaving them to guess.
     */
    public const TRUNCATED = '… [truncated]';
    public const DEPTH_LIMIT = '[…]';
    public const OMITTED_KEY = '…';

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
        'bearer', 'certificate', 'apikey', 'accesskey', 'privatekey', 'securitykey', 'pw',
        // `auth` on its own holds a login far more often than anything else — an HTTP client's
        // `auth` option, a Redis `auth`. `author` is a different word and is left alone.
        'auth',
    ];

    /**
     * @var array<string, string[]> Words that only mean a credential next to another word, so
     * `apiKey` is redacted and `keyword` is not.
     */
    private const SENSITIVE_PAIRS = [
        'key' => ['api', 'access', 'private', 'public', 'security', 'secret', 'encryption', 'license', 'signing'],
        'id' => ['session'],
        'string' => ['connection'],
        // Half of a login is still a credential. A bare `user` is left alone — it is as often a
        // person's name or ID as anything else — but the account a database or mail server is
        // reached with is not something a report needs to repeat.
        'user' => self::LOGIN_QUALIFIERS,
        'username' => self::LOGIN_QUALIFIERS,
    ];

    /** @var string[] What makes a `user` or `username` the name half of a service login. */
    private const LOGIN_QUALIFIERS = ['db', 'database', 'smtp', 'mail', 'mailer', 'ftp', 'sftp', 'proxy', 'redis'];

    /**
     * @var string[] Last words that name a credential when the key is spelt as an environment
     * variable. `SENDGRID_KEY` and `SMTP_PASS` are credentials by convention; `primaryKey` and a
     * status count under `pass` are not, and the spelling is the only thing that tells them apart.
     */
    private const ENVIRONMENT_SUFFIXES = ['key', 'pass'];

    /**
     * @var array<string, string> Credentials that announce themselves by their shape, whatever
     * key they sit under and whatever sentence they appear in. Each pattern matches a documented
     * format, so the list stays short and nothing ordinary resembles an entry on it.
     */
    private const CREDENTIAL_SHAPES = [
        // A PEM private key, whole or cut off before its end marker.
        'privateKey' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s',
        'awsAccessKeyId' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'jwt' => '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}/',
        'stripe' => '/\b[rs]k_(?:live|test)_[A-Za-z0-9]{8,}/',
        'github' => '/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})/',
        'gitlab' => '/\bglpat-[A-Za-z0-9_-]{20,}/',
        'slack' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        'googleApiKey' => '/\bAIza[0-9A-Za-z_-]{35}/',
        'sendgrid' => '/\bSG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/',
    ];

    /**
     * @var list<string> Webhook URLs whose path is the credential. The host explains what
     * the URL was for and survives; the path is the secret and does not.
     */
    private const WEBHOOK_SHAPES = [
        '#(hooks\.slack\.com/services/)[A-Za-z0-9/_-]+#',
        '#(discord(?:app)?\.com/api/webhooks/)[0-9]+/[A-Za-z0-9_-]+#',
    ];

    /**
     * @var int The shortest environment value treated as a secret to look for in free text. A
     * shorter one would be replaced inside ordinary words and make every message unreadable.
     */
    private const MIN_KNOWN_SECRET_LENGTH = 8;

    /** @var list<string>|null The environment's credentials, longest first, once read. */
    private static ?array $knownSecrets = null;

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

        if (self::isEnvironmentName($key) && in_array($words[array_key_last($words)], self::ENVIRONMENT_SUFFIXES, true)) {
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
     * Whether a key is spelt the way an environment variable is: upper case, words joined by
     * underscores.
     */
    private static function isEnvironmentName(string $key): bool
    {
        return preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+$/', $key) === 1;
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
                $safe[self::OMITTED_KEY] = sprintf('%d more omitted', count($data) - $kept);
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
            return $depth >= self::MAX_DEPTH ? self::DEPTH_LIMIT : self::redact($value, $depth);
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
     * removed, as are credentials recognisable by their shape and the values of the
     * environment's own credentials wherever they turn up, and the result is truncated so one
     * log line cannot become the whole record.
     */
    public static function redactString(string $value): string
    {
        // Malformed text is repaired first. It cannot be encoded as JSON or stored in a UTF-8
        // column, so letting it through would turn one bad byte in an exception message into a
        // reconciliation that fails to save.
        $value = mb_scrub($value, 'UTF-8');

        // The environment's own credentials, found by value. This is what catches a password a
        // driver quoted with nothing beside it to say what it was.
        $secrets = self::knownSecrets();

        if ($secrets !== []) {
            $value = str_replace($secrets, self::REDACTED, $value);
        }

        foreach (self::CREDENTIAL_SHAPES as $pattern) {
            $value = (string)preg_replace($pattern, self::REDACTED, $value);
        }

        foreach (self::WEBHOOK_SHAPES as $pattern) {
            $value = (string)preg_replace($pattern, '$1' . self::REDACTED, $value);
        }

        // Credentials in a URL's userinfo, as a database DSN or a webhook URL carries them.
        $value = (string)preg_replace('#([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]*@#i', '$1' . self::REDACTED . '@', $value);

        // An `Authorization: Bearer …` header, which carries its credential with no key beside
        // it. Exception messages from HTTP clients quote these in full.
        $value = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 ' . self::REDACTED, $value);

        // `password=…`, `api_key: …` and their kin, wherever they appear in free text.
        $value = self::redactPairs($value);

        if (strlen($value) > self::MAX_STRING_LENGTH) {
            $value = mb_strcut($value, 0, self::MAX_STRING_LENGTH) . self::TRUNCATED;
        }

        return $value;
    }

    /**
     * The values of the environment's credentials, so they can be recognised in text that gives
     * no other sign of carrying one.
     *
     * An environment variable counts when its name names a credential. Its value is only looked
     * for when it could not be mistaken for prose: long enough, and not a plain word — a
     * development database whose password is `password` would otherwise have that word removed
     * from every message Web Doctor records. Those values are still caught wherever a key names
     * them; this is the net beneath that, not a replacement for it.
     *
     * Read once per process. The environment does not change under a running request, and
     * reading it for every string would cost far more than the strings do.
     *
     * @return list<string> Longest first, so a secret containing another is replaced whole.
     */
    private static function knownSecrets(): array
    {
        if (self::$knownSecrets !== null) {
            return self::$knownSecrets;
        }

        $secrets = [];

        foreach ([getenv(), $_ENV, $_SERVER] as $variables) {
            foreach ($variables as $name => $value) {
                if (
                    is_string($name)
                    && is_string($value)
                    && strlen($value) >= self::MIN_KNOWN_SECRET_LENGTH
                    && !ctype_alpha($value)
                    && !self::isPresence($value)
                    && self::isSensitiveKey($name)
                ) {
                    $secrets[$value] = true;
                }
            }
        }

        $secrets = array_keys($secrets);
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return self::$knownSecrets = $secrets;
    }

    /**
     * What redaction left behind in a value: how many things were withheld, and whether anything
     * was cut short. This is what lets a reader be told that a value is incomplete, rather than
     * finding out by reading it.
     *
     * @return array{redacted: int, bounded: bool}
     */
    public static function marks(mixed $value): array
    {
        if (is_string($value)) {
            return [
                'redacted' => substr_count($value, self::REDACTED),
                'bounded' => $value === self::DEPTH_LIMIT || str_ends_with($value, self::TRUNCATED),
            ];
        }

        $marks = ['redacted' => 0, 'bounded' => false];

        if (!is_array($value)) {
            return $marks;
        }

        foreach ($value as $key => $item) {
            if ($key === self::OMITTED_KEY) {
                $marks['bounded'] = true;
            }

            $inner = self::marks($item);
            $marks['redacted'] += $inner['redacted'];
            $marks['bounded'] = $marks['bounded'] || $inner['bounded'];
        }

        return $marks;
    }

    /**
     * @var int How far a value is re-examined for a credential nested inside it. Each level
     * works on a strictly shorter string, so this only bounds pathological input.
     */
    private const MAX_PAIR_DEPTH = 4;

    /**
     * Removes `key=value` credentials from free text — including `"key": "value"`, the shape an
     * API's JSON error body takes when an HTTP client quotes it in an exception, with or without
     * the quotes escaped.
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
            '/([A-Za-z_][A-Za-z0-9_.\-]*)((?:\\\\?["\'])?\s*[=:]\s*)(\\\\"(?:(?!\\\\").)*\\\\"|"[^"]*"|\'[^\']*\'|[^\s,;&]+)/',
            static function(array $m) use ($depth): string {
                if (!self::isSensitiveKey($m[1])) {
                    return $m[1] . $m[2] . self::redactPairs($m[3], $depth + 1);
                }

                // A quoted value keeps its quotes, so JSON with a credential removed still reads
                // as JSON.
                $quote = preg_match('/^(\\\\"|"|\')/', $m[3], $q) === 1 ? $q[1] : '';

                return $m[1] . $m[2] . $quote . self::REDACTED . $quote;
            },
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
