<?php

namespace Tahadudhiya\WebDoctor\Tests\security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\helpers\Redaction;

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
        ];
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

        foreach (['primaryKey', 'keyword', 'author', 'description', 'tokenizer', 'identifier', 'monkey', 'salty'] as $key) {
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
}
