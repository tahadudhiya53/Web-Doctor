<?php

namespace Tahadudhiya\WebDoctor\Tests\security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Yii;
use yii\log\Logger;

/**
 * The regression test for the way a diagnostic tool most plausibly leaks a credential.
 *
 * A driver puts the DSN in the exception message. An HTTP client puts the Authorization header
 * in it. Web Doctor then catches that exception and has four chances to repeat it: the result
 * description, the serialized result, the evidence, and the log line. Every one of them is
 * checked here, because three out of four being safe is the same as none of them being safe.
 *
 * If this fails, a real credential is reaching real output. Do not adjust the assertion to suit
 * the code.
 */
class ExceptionLeakageTest extends TestCase
{
    /** @var string[] Values that must never appear in anything Web Doctor emits. */
    private const SECRETS = ['hunter2', 'secret-token', 'sk-live-abcdef123456', 'aws-secret-value'];

    private const MESSAGE = 'Database failed password=hunter2 token=secret-token '
        . 'apiKey=sk-live-abcdef123456 dsn=mysql://root:aws-secret-value@db:3306/craft';

    private DiagnosticEngine $engine;
    private DiagnosticContext $context;
    private Logger $logger;
    private ?Logger $originalLogger = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new DiagnosticEngine(['registry' => new Diagnostics()]);
        $this->context = new DiagnosticContext(environment: 'test');

        // A logger of this test's own, so what the engine writes can be read back without
        // depending on a configured log target or a file on disk.
        $this->originalLogger = Yii::getLogger();
        $this->logger = new Logger();
        Yii::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        Yii::setLogger($this->originalLogger);

        parent::tearDown();
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function leakyExceptions(): array
    {
        return [
            'a plain exception' => [new \RuntimeException(self::MESSAGE)],
            'an exception wrapping another' => [
                new \RuntimeException('Diagnostic failed', 0, new \PDOException(self::MESSAGE)),
            ],
            'an exception that is not an Exception' => [new \TypeError(self::MESSAGE)],
        ];
    }

    /**
     * @param \Throwable $exception
     */
    #[DataProvider('leakyExceptions')]
    public function testNothingAnExceptionSaysReachesAnyOutput(\Throwable $exception): void
    {
        $result = $this->engine->run(
            new TestDiagnostic([
                'diagnosticId' => 'tests.leaky',
                'handler' => static fn() => throw $exception,
            ]),
            $this->context,
        );

        $surfaces = [
            'the result description' => $result->description,
            'the serialized result' => (string)json_encode($result),
            'the evidence' => (string)json_encode($result->evidence()),
            'the log' => $this->loggedMessages(),
        ];

        foreach ($surfaces as $name => $output) {
            foreach (self::SECRETS as $secret) {
                self::assertStringNotContainsString(
                    $secret,
                    $output,
                    sprintf('A credential from an exception message reached %s.', $name),
                );
            }
        }
    }

    public function testTheFailureIsStillReportedUsefullyAfterRedaction(): void
    {
        // Redaction that removed the whole message would be safe and useless. What identifies
        // the failure has to survive.
        $result = $this->engine->run(
            new TestDiagnostic([
                'diagnosticId' => 'tests.leaky',
                'handler' => static fn() => throw new \RuntimeException(self::MESSAGE),
            ]),
            $this->context,
        );

        self::assertStringContainsString('RuntimeException', $result->description);
        self::assertStringContainsString('Database failed', $result->description);
        self::assertStringContainsString('[redacted]', $result->description);
        self::assertStringContainsString('tests.leaky', $this->loggedMessages());
    }

    public function testEveryExceptionInAChainIsSanitised(): void
    {
        $safe = SafeException::from(
            new \RuntimeException('outer', 0, new \PDOException(self::MESSAGE)),
        );

        self::assertNotSame([], $safe->previous);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, (string)json_encode($safe));
        }
    }

    public function testAStackTraceCannotCarryACredentialOut(): void
    {
        // Frames name files and functions, never arguments — which is the other place a
        // credential sits in a trace.
        $safe = SafeException::from(new \RuntimeException(self::MESSAGE));

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, implode("\n", $safe->frames));
        }
    }

    private function loggedMessages(): string
    {
        return implode("\n", array_map(
            static fn(array $message): string => (string)($message[0] ?? ''),
            $this->logger->messages,
        ));
    }
}
