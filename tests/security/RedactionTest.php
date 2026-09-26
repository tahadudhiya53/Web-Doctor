<?php

namespace Tahadudhiya\WebDoctor\Tests\security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\Evidence;

/**
 * The single point Web Doctor's promise never to expose a secret rests on. If anything here
 * stops holding, every report, payload, log line and stored fact is affected at once — which is
 * why it is a security test rather than a unit test of a helper.
 */
class RedactionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveKeys(): array
    {
        $keys = [
            'password', 'dbPassword', 'DB_PASSWORD', 'db-password', 'passwd', 'pwd',
            'securityKey', 'SECURITY_KEY', 'apiKey', 'api_key', 'accessKeySecret',
            'secret', 'clientSecret', 'token', 'accessToken', 'refreshToken',
            'privateKey', 'credentials', 'Authorization', 'signature', 'passphrase',
            'hashSalt', 'DSN', 'Cookie', 'sessionId',
            // The name half of a service login.
            'DB_USER', 'dbUsername', 'SMTP_USERNAME', 'mailerUser',
            // Credentials by environment-variable convention.
            'SENDGRID_KEY', 'SMTP_PASS', 'MAILGUN_AUTH', 'PHP_AUTH_PW',
            'auth', 'api-key', 'access_token', 'private_key', 'client_secret', 'mail_password',
            'AWS_SECRET_ACCESS_KEY', 'AWS_ACCESS_KEY_ID', 'GOOGLE_APPLICATION_CREDENTIALS',
        ];

        return array_combine($keys, array_map(static fn(string $k): array => [$k], $keys));
    }

    #[DataProvider('sensitiveKeys')]
    public function testACredentialIsNeverRecorded(string $key): void
    {
        $redacted = Redaction::redact([$key => 'hunter2']);

        self::assertSame(Redaction::REDACTED, $redacted[$key]);
    }

    public function testOrdinaryKeysAreLeftAlone(): void
    {
        // Redacting everything that merely looks alarming would make evidence useless, so the
        // fragments are chosen to catch credentials and not words that contain them.
        $data = [
            'author' => 'Taha',
            'authorId' => 42,
            'keyword' => 'queue',
            'tokenizer' => 'default',
            'phpVersion' => '8.2.0',
        ];

        self::assertSame($data, Redaction::redact($data));
    }

    public function testPresenceAnswersWhetherSomethingIsSetWithoutSayingWhat(): void
    {
        self::assertSame(Redaction::PRESENT, Redaction::presence('hunter2'));
        self::assertSame(Redaction::MISSING, Redaction::presence(null));
        self::assertSame(Redaction::MISSING, Redaction::presence(''));
        self::assertSame(Redaction::MISSING, Redaction::presence('   '));
        self::assertSame(Redaction::MISSING, Redaction::presence([]));
        self::assertSame(Redaction::PRESENT, Redaction::presence(['a']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentedSensitiveWords(): array
    {
        // Every word the helper claims to know, checked against the helper rather than trusted.
        $words = [
            'password', 'passwd', 'pwd', 'secret', 'token', 'credential', 'authorization',
            'signature', 'salt', 'dsn', 'cookie', 'bearer', 'certificate', 'apikey',
            'accesskey', 'privatekey', 'securitykey', 'passphrase',
        ];

        return array_combine($words, array_map(static fn(string $w): array => [$w], $words));
    }

    #[DataProvider('documentedSensitiveWords')]
    public function testEveryDocumentedSensitiveWordIsRecognised(string $word): void
    {
        self::assertTrue(Redaction::isSensitiveKey($word));
        self::assertTrue(Redaction::isSensitiveKey(strtoupper($word)));
        self::assertTrue(Redaction::isSensitiveKey('db' . ucfirst($word)));
    }

    public function testAnAuthorizationHeaderIsRedactedEvenWithNoKeyBesideIt(): void
    {
        // HTTP clients quote these in full in exception messages, and there is no `key=` to
        // match on — just the scheme and the credential.
        // Written as a header, `Authorization` is itself a sensitive key, so the whole value
        // goes.
        $header = Redaction::redactString('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9abcdef');

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9abcdef', $header);

        // Written loose in a sentence there is no key to match on, and the scheme is what the
        // credential is recognised by.
        $loose = Redaction::redactString('Request rejected, sent Bearer eyJhbGciOiJIUzI1NiJ9abcdef instead');

        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9abcdef', $loose);
        self::assertStringContainsString('Bearer', $loose);
    }

    public function testMixedValueTypesAllSurviveRedaction(): void
    {
        $redacted = Redaction::redact([
            'string' => 'text',
            'int' => 1,
            'float' => 1.5,
            'bool' => false,
            'null' => null,
            'array' => ['a'],
            'enum' => \Tahadudhiya\WebDoctor\enums\Severity::HIGH,
            'date' => new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'object' => new \stdClass(),
        ]);

        self::assertSame('text', $redacted['string']);
        self::assertSame(1, $redacted['int']);
        self::assertSame(1.5, $redacted['float']);
        self::assertFalse($redacted['bool']);
        self::assertNull($redacted['null']);
        self::assertSame(['a'], $redacted['array']);
        self::assertSame('high', $redacted['enum']);
        self::assertSame('2026-01-01T00:00:00+00:00', $redacted['date']);
        self::assertSame('[stdClass]', $redacted['object']);
    }

    public function testAPresenceWordSurvivesBeingRedacted(): void
    {
        // Presence is what this helper produces in place of a secret. Redacting it again would
        // replace the answer with the mark that hides one, and a reader seeing "[redacted]"
        // where "Missing" belongs would never learn that a credential is not configured.
        $redacted = Redaction::redact([
            'securityKey' => Redaction::presence(null),
            'dbPassword' => Redaction::presence('hunter2'),
            'apiToken' => Redaction::UNKNOWN,
            'smtpSecret' => Redaction::INVALID,
        ]);

        self::assertSame(Redaction::MISSING, $redacted['securityKey']);
        self::assertSame(Redaction::PRESENT, $redacted['dbPassword']);
        self::assertSame(Redaction::UNKNOWN, $redacted['apiToken']);
        self::assertSame(Redaction::INVALID, $redacted['smtpSecret']);
    }

    public function testAnythingElseUnderASensitiveKeyIsStillReplaced(): void
    {
        // The exemption is exactly the four presence words and nothing near them, so a real
        // credential cannot slip through by resembling one.
        $redacted = Redaction::redact([
            'password' => 'hunter2',
            'securityKey' => 'Present and correct',
            'apiKey' => 'missing',
            'token' => ['Present'],
        ]);

        self::assertSame(Redaction::REDACTED, $redacted['password']);
        self::assertSame(Redaction::REDACTED, $redacted['securityKey']);
        self::assertSame(Redaction::REDACTED, $redacted['apiKey'], 'Case matters: this is a value, not the presence word.');
        self::assertSame(Redaction::REDACTED, $redacted['token']);
    }

    /**
     * @return array<string, array{string, string[], string[]}>
     */
    public static function freeTextCases(): array
    {
        return [
            'a credential behind an innocent label' => [
                'SMTP refused: password=fake-smtp-password token=fake-secret-token',
                ['fake-smtp-password', 'fake-secret-token'],
                ['SMTP refused'],
            ],
            'a driver error quoting the password' => [
                'SQLSTATE[HY000] [1045] Access denied: password=fake-db-password',
                ['fake-db-password'],
                ['SQLSTATE[HY000]', '1045'],
            ],
            'a credential nested several labels deep' => [
                'connect: failed: retrying: password=fake-db-password',
                ['fake-db-password'],
                ['retrying'],
            ],
            'an authorization header' => [
                'Authorization: Bearer fake-security-key-hunter2',
                ['fake-security-key-hunter2'],
                [],
            ],
            'basic auth' => [
                'Rejected Basic ZmFrZTpmYWtlLXNlY3JldA==',
                ['ZmFrZTpmYWtlLXNlY3JldA'],
                ['Rejected'],
            ],
            'a DSN carrying its own credentials' => [
                'dsn=mysql://root:fake-db-password@db:3306/craft',
                ['fake-db-password'],
                [],
            ],
            'a URL carrying credentials' => [
                'Posting to https://user:fake-secret-token@hooks.example.com/services/x failed',
                ['fake-secret-token'],
                ['hooks.example.com'],
            ],
            'a quoted value' => [
                'Connection failed for user=root, password="fake db password"',
                ['fake db password'],
                ['user=root'],
            ],
            'a value with base64 padding' => [
                'token=ZmFrZS1zZWNyZXQ== next=1',
                ['ZmFrZS1zZWNyZXQ'],
                ['next=1'],
            ],
            'an api key' => [
                'apiKey: fake-secret-token',
                ['fake-secret-token'],
                [],
            ],
            'an API error body quoted as JSON' => [
                'HTTP 401: {"error":"unauthorised","api_key":"fake-json-key","client_secret": "fake-json-secret"}',
                ['fake-json-key', 'fake-json-secret'],
                ['HTTP 401', '"error":"unauthorised"'],
            ],
            'JSON nested inside JSON' => [
                '{"data":{"credentials":{"password":"fake-nested-pw"}},"status":500}',
                ['fake-nested-pw'],
                ['"status":500'],
            ],
            'JSON with its quotes escaped' => [
                'payload={\\"access_token\\":\\"fake-escaped-token\\"}',
                ['fake-escaped-token'],
                ['payload='],
            ],
            'single-quoted pairs' => [
                "{'secret': 'fake-single-quoted'}",
                ['fake-single-quoted'],
                [],
            ],
            'a PHP array quoted in a message' => [
                "Config ['host' => 'smtp.example.com', 'password' => 'fake-arrow-pw'] rejected",
                ['fake-arrow-pw'],
                ["'host' => 'smtp.example.com'", 'rejected'],
            ],
            'an auth option' => [
                'Redis refused auth=fake-redis-auth on connect',
                ['fake-redis-auth'],
                ['Redis refused', 'on connect'],
            ],
            'a credential in a URL query' => [
                'GET https://api.example.com/v1?access_token=fake-query-token&page=2 failed',
                ['fake-query-token'],
                ['api.example.com', 'page=2'],
            ],
            'a URL whose login has no user name, as Redis URLs do' => [
                'Connecting to redis://:fake-redis-pw@cache:6379 failed',
                ['fake-redis-pw'],
                ['cache:6379'],
            ],
            'a URL password that itself contains an @' => [
                'Connecting to mysql://root:fake@db@pw@db:3306/craft failed',
                ['fake@db@pw', 'db@pw'],
                ['db:3306/craft'],
            ],
            'form and array keys, as an HTTP client quotes its parameters' => [
                'POST failed: config[password]=fake-form-pw&password[0]=fake-list-pw&api_key[]=fake-bracket-key&page=2',
                ['fake-form-pw', 'fake-list-pw', 'fake-bracket-key'],
                ['page=2'],
            ],
            'credentials spelt as one word' => [
                'PHPSESSID=fake-php-session sessionid=fake-session-id PGPASSWORD=fake-pg-password passcode=fake-passcode password2=fake-second-pw',
                ['fake-php-session', 'fake-session-id', 'fake-pg-password', 'fake-passcode', 'fake-second-pw'],
                [],
            ],
            'an authorization header with any scheme, or a short token' => [
                "Authorization: Apikey fake-apikey-scheme\nProxy-Authorization: Bearer shorty\nAccept: */*",
                ['fake-apikey-scheme', 'shorty'],
                ['Accept: */*'],
            ],
            'an authorization header quoted in JSON' => [
                '{"Authorization": "Digest username=\"x\", response=\"fake-digest\"", "status": 401}',
                ['fake-digest'],
                ['"status": 401'],
            ],
        ];
    }

    /**
     * What looks like a pair but is not one, and has to come through whole: where an exception was
     * thrown is how two errors are told apart, and MySQL's own "using password" flag is the
     * difference between no password configured and a wrong one.
     */
    public function testWhatOnlyLooksLikeACredentialPairSurvives(): void
    {
        foreach ([
            '/var/www/html/vendor/craftcms/cms/src/services/Auth.php:120',
            'yii\web\Cookie::__construct (/app/vendor/yiisoft/yii2/web/CookieCollection.php:88)',
            'craft\services\Tokens::createToken() at src/services/Tokens.php:61',
            "Access denied for user 'root'@'localhost' (using password: NO)",
            "Access denied for user 'root'@'localhost' (using password: YES)",
        ] as $text) {
            self::assertSame($text, Redaction::redactString($text));
        }
    }

    /**
     * @param string[] $mustNotSurvive
     * @param string[] $mustSurvive
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('freeTextCases')]
    public function testCredentialsAreRemovedFromFreeTextWithoutLosingWhatExplainsIt(
        string $text,
        array $mustNotSurvive,
        array $mustSurvive,
    ): void {
        // Exception messages are the main source of these, and the part that explains the
        // failure has to survive — a message reduced to "[redacted]" diagnoses nothing.
        $redacted = Redaction::redactString($text);

        foreach ($mustNotSurvive as $secret) {
            self::assertStringNotContainsString($secret, $redacted, sprintf('“%s” survived redaction.', $secret));
        }

        foreach ($mustSurvive as $kept) {
            self::assertStringContainsString($kept, $redacted, sprintf('“%s” explains the failure and should have survived.', $kept));
        }
    }

    public function testOrdinaryKeysInFreeTextAreLeftAlone(): void
    {
        // Redacting everything would make evidence useless, which is its own kind of failure.
        $text = 'keyword=search primaryKey=5 author=jane description=hello identifier=42';

        self::assertSame($text, Redaction::redactString($text));
    }

    public function testAKeyIsJudgedOnItsWholeShapeRatherThanOnContainingAWord(): void
    {
        // Compound keys are recognised however they are spelled, and words that merely contain
        // a fragment are not — redacting those would make evidence useless, which is its own
        // kind of failure.
        foreach (['key', 'dbPassword', 'DB_PASSWORD', 'db-password', 'apiKey', 'api_key', 'accessKey', 'privateKey', 'securityKey', 'sessionId', 'connectionString'] as $key) {
            self::assertTrue(Redaction::isSensitiveKey($key), "$key names a credential.");
        }

        // `user` and `pass` alone are a person and a status count; `primaryKey` spelt in camel case
        // is a column. Only the spelling of an environment variable turns a trailing KEY or PASS
        // into a credential.
        foreach (['primaryKey', 'keyword', 'author', 'description', 'tokenizer', 'identifier', 'monkey', 'salty', 'user', 'username', 'userId', 'pass', 'cacheKey', 'PRIMARY', 'KEYWORD_LIST'] as $key) {
            self::assertFalse(Redaction::isSensitiveKey($key), sprintf('%s is not a credential.', $key));
        }
    }

    public function testAKeyNamedAfterACredentialIsRedactedEvenWhenItProbablyHoldsSomethingElse(): void
    {
        // A deliberate over-redaction, recorded here so it stays a decision rather than
        // becoming an accident. `passwordPolicy` almost certainly holds a policy — but the
        // rule that makes this redactor auditable is "a key naming a credential is treated as
        // one", and softening it with a list of words that cancel the first would mean a field
        // called `passwordRules` holding an actual password would walk straight out. The cost
        // is a lost policy description; the alternative risk is a leaked credential.
        foreach (['passwordPolicy', 'passwordRules', 'tokenStrategy'] as $key) {
            self::assertTrue(Redaction::isSensitiveKey($key));
        }
    }

    public function testCredentialsAreStrippedFromEveryUrlSchemeAnInstallationMightUse(): void
    {
        foreach (['https', 'mysql', 'postgres', 'pgsql', 'smtp', 'redis', 'amqp'] as $scheme) {
            $redacted = Redaction::redactString("$scheme://user:fake-db-password@host/thing");

            self::assertStringNotContainsString('fake-db-password', $redacted, sprintf('a %s URL leaked', $scheme));
            self::assertStringContainsString("$scheme://", $redacted, 'the scheme explains what the URL was for');
            self::assertStringContainsString('host', $redacted);
        }
    }

    public function testACredentialNestedDeepInsideAStructureIsStillRemoved(): void
    {
        $redacted = Redaction::redact([
            'transport' => [
                'settings' => [
                    'smtp' => [
                        'host' => 'smtp.example.com',
                        'password' => 'fake-smtp-password',
                        'notes' => 'connect with password=fake-smtp-password',
                    ],
                ],
            ],
        ]);

        $json = (string)json_encode($redacted);

        self::assertStringNotContainsString('fake-smtp-password', $json);
        self::assertStringContainsString('smtp.example.com', $json);
    }

    public function testAStructureIsBoundedInDepthWidthAndLength(): void
    {
        // A diagnostic that stumbled onto something enormous must not be able to record it.
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'too far']]]]]]];
        $wide = array_fill(0, 500, 'x');
        $long = ['note' => str_repeat('n', 5000)];

        $redactedDeep = Redaction::redact($deep);
        $redactedWide = Redaction::redact($wide);
        $redactedLong = Redaction::redact($long);

        self::assertSame('[…]', $redactedDeep['a']['b']['c']['d']['e']['f']);
        self::assertLessThan(500, count($redactedWide));
        self::assertArrayHasKey('…', $redactedWide);
        self::assertStringEndsWith('[truncated]', $redactedLong['note']);
    }

    public function testObjectsAndResourcesAreDescribedRatherThanSerialized(): void
    {
        // Serializing an object is how a credential ends up somewhere nobody looked.
        $handle = fopen('php://memory', 'rb');

        self::assertIsResource($handle);

        try {
            $redacted = Redaction::redact([
                'config' => new \stdClass(),
                'stream' => $handle,
                'closure' => static fn(): bool => true,
            ]);

            self::assertSame('[stdClass]', $redacted['config']);
            self::assertStringStartsWith('[', (string)$redacted['stream']);
            self::assertStringNotContainsString('function', (string)$redacted['closure']);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Built at runtime rather than written out, so a secret scanner reading this file does not
     * mistake the examples for leaked credentials.
     *
     * @return array<string, array{string, string, string}> The text, what must not survive, what must.
     */
    public static function credentialShapes(): array
    {
        $jwt = 'eyJ' . 'hbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N';
        $aws = 'AKIA' . 'IOSFODNN7EXAMPLE';
        $stripe = 'sk_' . 'live_' . str_repeat('a1B2', 6);
        $github = 'gh' . 'p_' . str_repeat('x9Y8', 9);
        $gitlab = 'gl' . 'pat-' . str_repeat('Qw3_', 6);
        $slack = 'xo' . 'xb-' . '123456789012-abcdefghijKL';
        $google = 'AI' . 'za' . str_repeat('Sy0_', 9) . 'Abc';
        $sendgrid = 'S' . 'G.' . str_repeat('abcd', 5) . '.' . str_repeat('WXYZ', 5);
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEAfakefakefake\n-----END RSA PRIVATE KEY-----";

        return [
            'a private key' => ["Loaded key: $pem from disk", 'MIIEpAIBAAKCAQEAfakefakefake', 'from disk'],
            'a private key cut off before its end' => ["-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAAS", 'MIIEvQIBADANBgkqhkiG9w0BAQEFAAS', ''],
            'an AWS access key ID' => ["Uploading with $aws failed", $aws, 'Uploading with'],
            'a JSON web token' => ["Rejected $jwt as expired", $jwt, 'as expired'],
            'a Stripe key' => ["Charge failed using $stripe", $stripe, 'Charge failed'],
            'a GitHub token' => ["Clone failed for $github", $github, 'Clone failed'],
            'a GitLab token' => ["Push refused: $gitlab", $gitlab, 'Push refused'],
            'a Slack token' => ["Slack said invalid_auth for $slack", $slack, 'invalid_auth'],
            'a Google API key' => ["Maps quota exceeded for $google", $google, 'Maps quota'],
            'a SendGrid key' => ["Mail rejected: $sendgrid", $sendgrid, 'Mail rejected'],
            'a Slack webhook' => ['POST https://hooks.slack.com/services/T0001/B0002/abcDEF123 returned 404', 'T0001/B0002/abcDEF123', 'hooks.slack.com/services/'],
            'a Discord webhook' => ['POST https://discord.com/api/webhooks/1234567/abc-DEF_ghi returned 401', 'abc-DEF_ghi', 'discord.com/api/webhooks/'],
        ];
    }

    #[DataProvider('credentialShapes')]
    public function testACredentialIsRecognisedByItsShapeWhateverSurroundsIt(string $text, string $secret, string $kept): void
    {
        // No key names these. The shape is the only thing that says what they are, and an
        // exception message or a log line is exactly where they appear with nothing beside them.
        $redacted = Redaction::redactString($text);

        self::assertStringNotContainsString($secret, $redacted);
        self::assertStringContainsString(Redaction::REDACTED, $redacted);
        self::assertStringContainsString($kept, $redacted);

        // The same holds under a key nobody would think to guard.
        self::assertStringNotContainsString($secret, (string)json_encode(Redaction::redact(['note' => $text])));
    }

    public function testTheEnvironmentsOwnCredentialsAreRecognisedWhereverTheyTurnUp(): void
    {
        // A driver that quotes the password it was given, with nothing beside it to say what it
        // is, is caught because the value itself is known to be a credential.
        $variables = [
            'WEBDOCTOR_TEST_API_TOKEN' => 'Zq9-unit-secret-771',
            'WEBDOCTOR_TEST_SMTP_PASS' => 'p4ss/with+symbols',
            // Too short to look for without mangling ordinary words, and a plain word.
            'WEBDOCTOR_TEST_SHORT_SECRET' => 'ab1',
            'WEBDOCTOR_TEST_WORD_SECRET' => 'recorded',
            // Not a credential at all.
            'WEBDOCTOR_TEST_REGION' => 'eu-west-1-zone9',
        ];

        $this->forgetKnownSecrets();

        foreach ($variables as $name => $value) {
            putenv("$name=$value");
        }

        try {
            $redacted = Redaction::redactString(
                'Login rejected for Zq9-unit-secret-771 (retry p4ss/with+symbols) ab1 recorded in eu-west-1-zone9',
            );

            self::assertStringNotContainsString('Zq9-unit-secret-771', $redacted);
            self::assertStringNotContainsString('p4ss/with+symbols', $redacted);
            self::assertStringContainsString('Login rejected for', $redacted);
            self::assertStringContainsString('ab1 recorded in eu-west-1-zone9', $redacted);
        } finally {
            foreach (array_keys($variables) as $name) {
                putenv($name);
            }

            $this->forgetKnownSecrets();
        }
    }

    public function testTheShellsWorkingDirectoryIsNotMistakenForACredential(): void
    {
        // `PWD` reads as a password by name, but it holds the directory a command started in —
        // usually the installation — so treating its value as secret would cut the start off
        // every path recorded from the command line. A key named `pwd` is still redacted.
        $original = getenv('PWD');
        $this->forgetKnownSecrets();
        putenv('PWD=/srv/craft-install-7731');

        try {
            self::assertSame(
                'Failed to open /srv/craft-install-7731/storage/x.txt',
                Redaction::redactString('Failed to open /srv/craft-install-7731/storage/x.txt'),
            );
            self::assertSame(Redaction::REDACTED, Redaction::redact(['pwd' => '/srv/craft-install-7731'])['pwd']);
        } finally {
            putenv($original === false ? 'PWD' : "PWD=$original");
            $this->forgetKnownSecrets();
        }
    }

    public function testWhatRedactionLeftBehindCanBeCounted(): void
    {
        $marks = Redaction::marks(Redaction::redact([
            'password' => 'hunter2',
            'note' => 'retry with token=abc then apiKey=def',
            'long' => str_repeat('x', 5000),
            'fromEmail' => Redaction::MISSING,
        ]));

        self::assertSame(3, $marks['redacted']);
        self::assertTrue($marks['bounded']);
        self::assertSame(['redacted' => 0, 'bounded' => false], Redaction::marks(['a' => 'b', 'c' => [1, 2]]));
        self::assertTrue(Redaction::marks(Redaction::redact(array_fill(0, 500, 'x')))['bounded']);
    }

    public function testTheProseADiagnosticWritesIsRedactedBeforeAnythingReadsIt(): void
    {
        // A result's summary becomes an issue's title, a row on the dashboard and a line in every
        // report. Diagnostics — other plugins' included — write it from whatever failed, so it is
        // redacted where the result is built rather than wherever it happens to be shown.
        $result = new \Tahadudhiya\WebDoctor\models\DiagnosticResult(
            diagnosticId: 'tests.prose',
            // A name, a component and a plugin are the diagnostic's to write too, and each reaches
            // an issue's columns.
            name: 'Prose password=hunter2-name',
            category: \Tahadudhiya\WebDoctor\enums\DiagnosticCategory::EMAIL,
            status: \Tahadudhiya\WebDoctor\enums\DiagnosticStatus::FAIL,
            summary: 'SMTP refused: password=hunter2-summary',
            description: 'Connecting to smtp://mailer:hunter2-description@mail.example.com failed.',
            recommendation: 'Rotate api_key=hunter2-recommendation and try again.',
            affectedComponent: 'mailer token=hunter2-component',
            affectedPlugin: 'smtp secret=hunter2-plugin',
        );

        $json = (string)json_encode($result);

        foreach (['hunter2-summary', 'hunter2-description', 'hunter2-recommendation', 'hunter2-name', 'hunter2-component', 'hunter2-plugin'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }

        self::assertStringContainsString('SMTP refused', $result->summary);
        self::assertStringStartsWith('Prose', $result->name);
        self::assertStringContainsString('mail.example.com', $result->description);
    }

    /**
     * The environment's credentials are read once per process; a test that changes the
     * environment has to make the helper read it again, and put it back afterwards.
     */
    private function forgetKnownSecrets(): void
    {
        (new \ReflectionProperty(Redaction::class, 'knownSecrets'))->setValue(null, null);
    }

    public function testACheckNamedAfterACredentialIsRedactedEvenBeforeItHasRun(): void
    {
        // A name is whatever the contributing plugin wrote, and two places show it before any
        // result exists to have redacted it: an investigation's plan on the issue page, and a check
        // added since the dashboard's last run.
        $check = new \Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic();
        $check->diagnosticId = 'tests.named';
        $check->diagnosticName = 'Mailer token=planSecret99';

        $plan = \Tahadudhiya\WebDoctor\models\InvestigationPlan::build('tests.named', \Tahadudhiya\WebDoctor\enums\DiagnosticCategory::EMAIL, null, [$check]);
        $dashboard = \Tahadudhiya\WebDoctor\models\Dashboard::build([$check], null);

        foreach (['the plan' => json_encode($plan), 'the dashboard' => json_encode($dashboard->rows)] as $surface => $output) {
            self::assertStringNotContainsString('planSecret99', (string)$output, "A credential in a check's name reached {$surface}.");
            self::assertStringContainsString('Mailer', (string)$output);
        }
    }

    public function testMalformedTextIsRepairedRatherThanCarriedIntoStorage(): void
    {
        // One bad byte in an exception message must not become a value that cannot be encoded or
        // stored in a UTF-8 column.
        $redacted = Redaction::redactString("Connection failed: \xC3\x28 password=hunter2 \xFF");

        self::assertTrue(mb_check_encoding($redacted, 'UTF-8'));
        self::assertIsString(json_encode($redacted));
        self::assertStringNotContainsString('hunter2', $redacted);
    }

    public function testAContextCarriesNoSecretsOutOfTheProcess(): void
    {
        // The context travels into evidence and reports, so anything an option holds is
        // redacted the same way everything else is.
        $context = new DiagnosticContext(options: ['apiKey' => 'hunter2', 'window' => 60]);
        $json = $context->jsonSerialize();

        self::assertSame(Redaction::REDACTED, $json['options']['apiKey']);
        self::assertSame(60, $json['options']['window']);

        // A run is cached as a serialized PHP object, which never calls jsonSerialize(), so the
        // context has to be safe as it stands rather than only as it is rendered.
        self::assertStringNotContainsString('hunter2', serialize($context));
        self::assertSame(60, $context->option('window'));
    }

    /**
     * Every credential form the helper claims to catch, carried through every field a piece of
     * evidence has, and checked in both forms evidence leaves the process in: JSON, and the
     * serialized object the latest run is cached as.
     */
    public function testNoCredentialSurvivesInAnyFieldOfAPieceOfEvidence(): void
    {
        $jwt = 'eyJ' . 'hbGciOiJIUzI1NiJ9.eyJzdWIiOiJhdWRpdCJ9.c2lnbmF0dXJlLWF1ZGl0';
        $aws = 'AKIA' . 'AUDITAUDITAUDIT1';
        $stripe = 'sk_' . 'live_' . str_repeat('Au9t', 6);
        $github = 'gh' . 'p_' . str_repeat('Aud1', 9);
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIauditauditaudit\n-----END PRIVATE KEY-----";

        $forms = [
            'password=pw-free-text',
            '{"api_key":"ak-json"}',
            "rejected $jwt",
            "using $aws",
            "charge $stripe",
            "clone $github",
            $pem,
            'mysql://root:pw-in-dsn@db/craft',
            'https://hooks.slack.com/services/T1/B2/slackpath9',
            'Authorization: Bearer bearer-audit-token',
        ];
        $secrets = ['pw-free-text', 'ak-json', $jwt, $aws, $stripe, $github, 'MIIauditauditaudit', 'pw-in-dsn', 'T1/B2/slackpath9', 'bearer-audit-token'];

        foreach ($forms as $form) {
            $evidence = new Evidence(
                type: EvidenceType::ENVIRONMENT_VARIABLE,
                label: "label $form",
                source: "source $form",
                data: [
                    'text' => $form,
                    'nested' => ['deeper' => ['note' => $form]],
                    'password' => 'pw-under-a-key',
                    'list' => [$form],
                ],
                metadata: ['note' => $form, 'token' => 'tok-under-a-key'],
                reference: "ref $form",
            );

            $outputs = [(string)json_encode($evidence), serialize($evidence)];

            foreach ($outputs as $output) {
                foreach ([...$secrets, 'pw-under-a-key', 'tok-under-a-key'] as $secret) {
                    self::assertStringNotContainsString($secret, $output, sprintf('%s survived from “%s”.', $secret, $form));
                }
            }
        }
    }

    public function testAnExceptionAndItsTraceCannotCarryACredentialIntoEvidence(): void
    {
        $previous = new \RuntimeException('inner {"client_secret":"cs-inner-audit"}');
        $exception = new \RuntimeException('outer failed password=pw-outer-audit', 0, $previous);

        $outputs = [
            serialize(Evidence::fromThrowable($exception, 'tests.exception')),
            serialize(Evidence::stackTrace($exception, 'tests.exception')),
            (string)json_encode(Evidence::fromThrowable($exception, 'tests.exception')),
        ];

        foreach ($outputs as $output) {
            self::assertStringNotContainsString('pw-outer-audit', $output);
            self::assertStringNotContainsString('cs-inner-audit', $output);
        }
    }

    public function testBeingClientSafeNeverExemptsEvidenceFromRedaction(): void
    {
        // Client safety is a floor on top of redaction, never instead of it. Every type approved
        // for clients is checked, in every field.
        foreach (EvidenceType::cases() as $type) {
            if (!$type->isClientSafe()) {
                continue;
            }

            $evidence = new Evidence(
                type: $type,
                label: 'STRIPE_SECRET=sk-client-label',
                source: 'tests.client',
                data: ['STRIPE_SECRET' => 'sk-client-data', 'SMTP_PASS' => 'pw-client-data', 'region' => 'eu-west-1'],
                metadata: ['token' => 'tok-client-meta'],
                reference: 'https://user:pw-client-ref@example.com/x',
            );

            $output = serialize($evidence);

            foreach (['sk-client-label', 'sk-client-data', 'pw-client-data', 'tok-client-meta', 'pw-client-ref'] as $secret) {
                self::assertStringNotContainsString($secret, $output, sprintf('%s leaked through client-safe %s evidence.', $secret, $type->value));
            }

            self::assertSame('eu-west-1', $evidence->get('region'));
        }

        // Approval is a list, so the default for anything not on it — including a type stored by
        // some other version and read back as the fallback — is to withhold.
        self::assertFalse(EvidenceType::CONFIGURATION->isClientSafe());
        self::assertFalse(EvidenceType::EXCEPTION->isClientSafe());
        self::assertFalse(EvidenceType::STACK_TRACE->isClientSafe());
    }
}
