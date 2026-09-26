<?php

namespace Tahadudhiya\WebDoctor\Tests\security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\ErrorSignature;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\IssueSnapshot;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\RootCauses;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Yii;
use yii\log\Logger;

/**
 * The regression test for the way a diagnostic tool most plausibly leaks a credential.
 *
 * A driver puts the DSN in the exception message. An HTTP client puts the Authorization header
 * in it. Web Doctor then catches that exception and has six chances to repeat it: the result
 * description, the serialized result, the evidence, the log line, the error group it is counted
 * against, and the root cause that quotes it. Every one of them is checked here, because five out
 * of six being safe is the same as none of them being safe.
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
            // What error grouping keeps: the grouped message, the occurrence's own wording, the
            // origin, the chain behind it and the trace.
            'the error group' => (string)json_encode(array_map(
                static fn(ErrorSignature $s): array => get_object_vars($s),
                ErrorSignature::allIn($result->evidence()),
            )),
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

    public function testGroupingAnErrorNeverBringsBackWhatRedactionRemoved(): void
    {
        // Normalising works on text that has already been redacted, and the marker it leaves is
        // not a value any rule rewrites — so a withheld value stays withheld in the grouped form,
        // and the error is still recognisable by what is left.
        $result = $this->engine->run(
            new TestDiagnostic([
                'diagnosticId' => 'tests.leaky',
                'handler' => static fn() => throw new \RuntimeException(self::MESSAGE),
            ]),
            $this->context,
        );

        $signature = ErrorSignature::allIn($result->evidence())[0];

        self::assertStringStartsWith('Database failed password=[redacted]', $signature->message);
        self::assertStringContainsString('[redacted]', $signature->sample);
        self::assertNotSame([], $signature->frames);
    }

    public function testACauseQuotingAnExceptionQuotesNoCredential(): void
    {
        // A refused login is the one exception a cause quotes as proof, and drivers put the DSN in
        // exactly that message.
        $exception = new \PDOException('SQLSTATE[HY000] [1045] Access denied for user ' . self::MESSAGE);
        $result = new DiagnosticResult(
            diagnosticId: 'database.connection',
            name: 'Database connection',
            category: DiagnosticCategory::DATABASE,
            status: DiagnosticStatus::FAIL,
            summary: 'Craft cannot connect to its database.',
            evidence: [
                new Evidence(EvidenceType::DATABASE_ERROR, 'Connection attempt', 'database.connection', ['succeeded' => false]),
                Evidence::fromThrowable($exception, 'database.connection'),
            ],
            confidence: Confidence::CONFIRMED,
        );
        $issue = new IssueSnapshot(1, 'database.connection', DiagnosticCategory::DATABASE, 'Craft cannot connect to its database.', Severity::CRITICAL, IssueStatus::NEW, new \DateTimeImmutable());

        $causes = (new RootCauses())->analyse(new CorrelationCase($issue, [$result]))->causes;
        $quoted = (string)json_encode($causes);

        // It is still quoted, and still identifiable as the refusal it proves.
        self::assertStringContainsString('Access denied', $quoted);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $quoted, 'A credential from an exception message reached the cause that quotes it.');
        }
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
        // Frames name files and functions, never arguments — which is where a credential sits in
        // a trace: a connection opened with a password throws from inside the call it was
        // handed to.
        $connect = static function(string $password): never {
            throw new \RuntimeException('The connection was refused.');
        };

        try {
            $connect(self::SECRETS[0]);
        } catch (\RuntimeException $e) {
            $safe = SafeException::from($e);
        }

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, implode("\n", $safe->frames));
        }
    }

    /**
     * Everything Web Doctor logs about a failure goes through one sanitised form: a sentence
     * saying what failed, then the exception's class, redacted message and origin — directly,
     * from the engine, and from a contributed check the registry refused.
     */
    public function testEveryFailureIsLoggedThroughTheOneSanitisedForm(): void
    {
        $exception = new \RuntimeException(self::MESSAGE);
        SafeException::log('Something could not be done', $exception);

        self::assertSame(
            sprintf('Something could not be done. %s at %s', SafeException::from($exception)->summary(), SafeException::from($exception)->origin),
            $this->loggedMessages(),
        );

        $registry = new Diagnostics();
        $registry->on(Diagnostics::EVENT_REGISTER_DIAGNOSTICS, static function(\Tahadudhiya\WebDoctor\events\RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = new TestDiagnostic(['diagnosticId' => 'not valid password=hunter2']);
        });
        $registry->all();

        $this->engine->run(new TestDiagnostic(['diagnosticId' => 'tests.leaky', 'handler' => static fn() => throw new \RuntimeException(self::MESSAGE)]), $this->context);

        $logged = $this->loggedMessages();
        self::assertStringContainsString('A contributed diagnostic could not be registered. ', $logged);
        self::assertStringContainsString('The diagnostic "tests.leaky" failed. RuntimeException: ', $logged);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $logged);
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
