<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\ExecutionMode;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;

/**
 * The value objects a diagnostic run is made of: the context that identifies it, the results it
 * produces, the evidence behind them, the sanitised exception any failure is reduced to, and the
 * base class a check is written against.
 */
class DiagnosticModelTest extends TestCase
{
    // --- The context: a run's identity and what a diagnostic is told about it.

    public function testARunGetsAnIdentityWithoutBeingGivenOne(): void
    {
        $context = new DiagnosticContext();

        self::assertNotSame('', $context->runId);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $context->runId);
    }

    public function testTwoRunsAreDistinguishable(): void
    {
        self::assertNotSame((new DiagnosticContext())->runId, (new DiagnosticContext())->runId);
    }

    public function testARunIdentityCanBeSuppliedSoAResumedRunStaysOneRun(): void
    {
        $context = new DiagnosticContext(runId: 'fixed-run-id');

        self::assertSame('fixed-run-id', $context->runId);
    }

    public function testDefaultsAreTheOnesSafeToRunAnywhere(): void
    {
        $context = new DiagnosticContext();

        self::assertSame(DiagnosticDepth::NORMAL, $context->depth);
        self::assertSame(ExecutionMode::MANUAL, $context->mode);
        self::assertNull($context->siteId);
    }

    public function testOptionsAreReadableWithAStatedFallback(): void
    {
        $context = new DiagnosticContext(options: ['window' => 3600]);

        self::assertSame(3600, $context->option('window'));
        self::assertSame(60, $context->option('missing', 60));
    }

    public function testDepthIsComparableSoADiagnosticCanBoundItsOwnWork(): void
    {
        self::assertTrue(DiagnosticDepth::DEEP->isAtLeast(DiagnosticDepth::NORMAL));
        self::assertFalse(DiagnosticDepth::SHALLOW->isAtLeast(DiagnosticDepth::NORMAL));
    }

    public function testAContextCarriesNoSecretsOutOfTheProcess(): void
    {
        // The context travels into evidence and reports, so anything an option holds is
        // redacted the same way everything else is.
        $context = new DiagnosticContext(options: ['apiKey' => 'hunter2', 'window' => 60]);
        $json = $context->jsonSerialize();

        self::assertSame(Redaction::REDACTED, $json['options']['apiKey']);
        self::assertSame(60, $json['options']['window']);
    }

    public function testARunWithNoSiteSaysSoRatherThanInventingOne(): void
    {
        // A console command, a queue job and an application still booting can all run with no
        // current site. A made-up site ID would attribute findings to a site nobody looked at.
        $context = new DiagnosticContext(siteId: null);

        self::assertNull($context->siteId);
        self::assertNull($context->jsonSerialize()['siteId']);
    }

    public function testSerializationCarriesTheRunIdentity(): void
    {
        $context = new DiagnosticContext(
            siteId: 1,
            environment: 'production',
            mode: ExecutionMode::SCHEDULED,
            depth: DiagnosticDepth::DEEP,
            runId: 'fixed-run-id',
        );

        self::assertSame([
            'runId' => 'fixed-run-id',
            'siteId' => 1,
            'environment' => 'production',
            'mode' => 'scheduled',
            'depth' => 'deep',
        ], array_intersect_key($context->jsonSerialize(), array_flip([
            'runId', 'siteId', 'environment', 'mode', 'depth',
        ])));
    }

    // --- The result: what one check concluded.

    private function aResult(
        DiagnosticStatus $status = DiagnosticStatus::PASS,
        ?Severity $severity = null,
        array $evidence = [],
    ): DiagnosticResult {
        return new DiagnosticResult(
            diagnosticId: 'craft.version',
            name: 'Craft version',
            category: DiagnosticCategory::CRAFT,
            status: $status,
            summary: 'Craft is up to date.',
            severity: $severity,
            evidence: $evidence,
        );
    }

    public function testAResultIsStructuredRatherThanASentence(): void
    {
        $json = $this->aResult()->jsonSerialize();

        foreach ([
            'diagnosticId', 'name', 'category', 'status', 'severity', 'summary', 'description',
            'recommendation', 'confidence', 'affectedComponent', 'affectedPlugin',
            'repairAvailable', 'verificationAvailable', 'environment', 'runId', 'startedAt',
            'finishedAt', 'durationMs', 'evidence',
        ] as $key) {
            self::assertArrayHasKey($key, $json);
        }
    }

    public function testSeverityIsAlwaysAnswerableEvenWhenNoneWasStated(): void
    {
        self::assertSame(Severity::HIGH, $this->aResult(DiagnosticStatus::FAIL)->severity());
        self::assertSame(Severity::MEDIUM, $this->aResult(DiagnosticStatus::WARNING)->severity());
        self::assertSame(Severity::INFO, $this->aResult(DiagnosticStatus::PASS)->severity());
    }

    public function testAStatedSeverityOverridesWhatTheStatusWouldImply(): void
    {
        // A warning can matter more than warnings usually do, and the diagnostic is the one
        // that knows.
        $result = $this->aResult(DiagnosticStatus::WARNING, Severity::HIGH);

        self::assertSame(Severity::HIGH, $result->severity());
    }

    /**
     * @return array<string, array{DiagnosticStatus, Severity}>
     */
    public static function statusSeverityPairs(): array
    {
        return [
            'a failure that matters as much as anything can' => [DiagnosticStatus::FAIL, Severity::CRITICAL],
            'a failure that barely matters' => [DiagnosticStatus::FAIL, Severity::LOW],
            'a warning about something serious' => [DiagnosticStatus::WARNING, Severity::HIGH],
            'a pass, noted for the record' => [DiagnosticStatus::PASS, Severity::INFO],
            'a check that broke over something minor' => [DiagnosticStatus::ERROR, Severity::LOW],
        ];
    }

    /**
     * Status and severity answer different questions, so every combination of them is a result
     * Web Doctor can produce and must carry unchanged.
     */
    #[DataProvider('statusSeverityPairs')]
    public function testStatusAndSeverityAreAssignedIndependently(DiagnosticStatus $status, Severity $severity): void
    {
        $result = $this->aResult($status, $severity);

        self::assertSame($status, $result->status);
        self::assertSame($severity, $result->severity());

        $json = $result->jsonSerialize();

        self::assertSame($status->value, $json['status']);
        self::assertSame($severity->value, $json['severity']);
    }

    public function testAFailureIsAConclusionAboutTheSiteHoweverMuchItMatters(): void
    {
        $result = $this->aResult(DiagnosticStatus::FAIL, Severity::CRITICAL);

        self::assertTrue($result->status->isConclusive());
        self::assertTrue($result->status->isProblem());
        self::assertSame(Severity::CRITICAL, $result->severity());
    }

    public function testTheWholeDocumentedContractSurvivesACopy(): void
    {
        // Every field of the result contract is carried across explicitly when the engine stamps
        // a run onto a result. A field silently dropped here is a finding silently dropped from
        // a report, so the whole serialized shape is compared rather than a few fields.
        $result = new DiagnosticResult(
            diagnosticId: 'queue.failedJobs',
            name: 'Failed queue jobs',
            category: DiagnosticCategory::QUEUE,
            status: DiagnosticStatus::FAIL,
            summary: 'Three jobs have failed.',
            severity: Severity::CRITICAL,
            description: 'Jobs failed in the last hour.',
            evidence: [new Evidence(EvidenceType::QUEUE_JOB, 'Job', 'queue.failedJobs')],
            recommendation: 'Retry them.',
            confidence: Confidence::CONFIRMED,
            affectedComponent: 'queue',
            affectedPlugin: 'some-plugin',
            repairAvailable: true,
            verificationAvailable: true,
        );

        $copy = $result->withExecution(
            new DiagnosticContext(environment: 'production', runId: 'fixed-run-id'),
            new \DateTimeImmutable('2026-01-01T12:00:00+00:00'),
            new \DateTimeImmutable('2026-01-01T12:00:01+00:00'),
            12.5,
        );

        $carried = ['environment' => null, 'runId' => null, 'startedAt' => null, 'finishedAt' => null, 'durationMs' => null];

        self::assertEquals(
            array_diff_key($result->jsonSerialize(), $carried),
            array_diff_key($copy->jsonSerialize(), $carried),
        );
        self::assertCount(1, $copy->evidence());
        self::assertSame('some-plugin', $copy->affectedPlugin);
        self::assertTrue($copy->repairAvailable);
        self::assertTrue($copy->verificationAvailable);
    }

    public function testTheOptionalContractFieldsDefaultToClaimingNothing(): void
    {
        // A result says a repair is available only when one is, and claims no confidence it
        // has not earned.
        $result = $this->aResult();

        self::assertFalse($result->repairAvailable);
        self::assertFalse($result->verificationAvailable);
        self::assertNull($result->recommendation);
        self::assertNull($result->affectedComponent);
        self::assertNull($result->affectedPlugin);
        self::assertSame(Confidence::INFORMATIONAL, $result->confidence);
    }

    public function testTheRunAResultBelongsToIsStampedOnRatherThanClaimed(): void
    {
        $context = new DiagnosticContext(environment: 'production', runId: 'fixed-run-id');
        $started = new \DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $finished = new \DateTimeImmutable('2026-01-01T12:00:01+00:00');

        $stamped = $this->aResult()->withExecution($context, $started, $finished, 12.5);

        self::assertSame('fixed-run-id', $stamped->runId);
        self::assertSame('production', $stamped->environment);
        self::assertSame(12.5, $stamped->durationMs);
        self::assertSame($started, $stamped->startedAt);
        self::assertSame($finished, $stamped->finishedAt);
    }

    public function testASeverityLeftToTheStatusStaysThatWayAcrossACopy(): void
    {
        // Carrying a resolved severity across would quietly freeze a default into a statement.
        $copy = $this->aResult(DiagnosticStatus::WARNING)->withExecution(
            new DiagnosticContext(),
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            1.0,
        );

        self::assertNull($copy->severity);
        self::assertSame(Severity::MEDIUM, $copy->severity());
    }

    public function testASerializedResultNamesItsSeverityEvenWhenNoneWasStated(): void
    {
        $json = $this->aResult(DiagnosticStatus::FAIL)->jsonSerialize();

        self::assertSame('fail', $json['status']);
        self::assertSame('high', $json['severity']);
        self::assertSame('craft', $json['category']);
    }

    // --- The run: one execution, held together by one identity.

    private function aRun(DiagnosticResult ...$results): DiagnosticRun
    {
        return new DiagnosticRun(
            context: new DiagnosticContext(runId: 'fixed-run-id'),
            results: $results,
            startedAt: new DateTimeImmutable('2026-01-01T12:00:00+00:00'),
            finishedAt: new DateTimeImmutable('2026-01-01T12:00:02+00:00'),
            durationMs: 2000.0,
        );
    }

    private function aRunResult(string $id, DiagnosticStatus $status, DiagnosticCategory $category = DiagnosticCategory::CONFIGURATION): DiagnosticResult
    {
        return new DiagnosticResult(
            diagnosticId: $id,
            name: $id,
            category: $category,
            status: $status,
        );
    }

    public function testARunIsIdentifiedByTheContextThatStartedIt(): void
    {
        // One identity, generated once, so an issue, a piece of evidence, a report and a
        // history entry all point at the same thing.
        $run = $this->aRun();

        self::assertSame('fixed-run-id', $run->id());
        self::assertSame($run->context->runId, $run->id());
    }

    public function testAResultIsFoundByTheDiagnosticThatProducedIt(): void
    {
        $run = $this->aRun($this->aRunResult('craft.version', DiagnosticStatus::PASS));

        self::assertNotNull($run->resultFor('craft.version'));
        self::assertNull($run->resultFor('nothing.here'));
    }

    public function testARunAddsUpToAHealthSummaryComputedOnce(): void
    {
        $run = $this->aRun(
            $this->aRunResult('a.fine', DiagnosticStatus::PASS),
            $this->aRunResult('b.warn', DiagnosticStatus::WARNING),
            $this->aRunResult('c.bad', DiagnosticStatus::FAIL),
            // A check that broke is kept apart from findings: it has said nothing about the site.
            $this->aRunResult('d.broken', DiagnosticStatus::ERROR),
        );
        $health = $run->health();

        self::assertLessThan(100, $health->score);
        self::assertSame(1, $health->countOf(DiagnosticStatus::WARNING));
        self::assertSame(1, $health->countOf(DiagnosticStatus::FAIL));
        self::assertSame(1, $health->countOf(DiagnosticStatus::ERROR));
        self::assertSame(1, $health->countOf(DiagnosticStatus::PASS));
        self::assertSame($health, $run->health());
    }

    public function testARunSerializesWholeSoAReportOrTheApiCanRenderIt(): void
    {
        $json = $this->aRun($this->aRunResult('craft.version', DiagnosticStatus::PASS))->jsonSerialize();

        self::assertSame('fixed-run-id', $json['runId']);
        self::assertSame(2000.0, $json['durationMs']);
        self::assertArrayHasKey('health', $json);
        self::assertArrayHasKey('context', $json);
        self::assertCount(1, $json['results']);
        self::assertSame('craft.version', $json['results'][0]['diagnosticId']);
    }

    // --- Evidence: the facts behind a conclusion, made safe as they are recorded.

    public function testEvidenceIsTypedAndAttributedToWhatObservedIt(): void
    {
        $evidence = new Evidence(
            type: EvidenceType::PHP_VERSION,
            label: 'PHP version',
            source: 'php.version',
            data: ['version' => '8.2.0'],
        );

        self::assertSame(EvidenceType::PHP_VERSION, $evidence->type);
        self::assertSame('php.version', $evidence->source);
        self::assertSame('8.2.0', $evidence->get('version'));
    }

    public function testACredentialCannotBeStoredInEvidenceAtAll(): void
    {
        // Redaction happens as the evidence is built, not on the way out, so no later
        // serializer can be the one that forgets.
        $evidence = new Evidence(
            type: EvidenceType::CONFIGURATION,
            label: 'Database connection',
            source: 'database.connection',
            data: ['server' => 'db', 'password' => 'hunter2'],
        );

        self::assertSame(Redaction::REDACTED, $evidence->get('password'));
        self::assertStringNotContainsString('hunter2', json_encode($evidence) ?: '');
    }

    public function testALabelCarryingACredentialIsRedactedToo(): void
    {
        $evidence = new Evidence(
            type: EvidenceType::LOG_ENTRY,
            label: 'Connection failed for password=hunter2',
            source: 'log.reader',
        );

        self::assertStringNotContainsString('hunter2', $evidence->label);
    }

    public function testAnExceptionBecomesEvidenceWithoutTheObject(): void
    {
        $evidence = Evidence::fromThrowable(new \RuntimeException('Boom', 7), 'queue.health');

        self::assertSame(EvidenceType::EXCEPTION, $evidence->type);
        self::assertSame(\RuntimeException::class, $evidence->get('class'));
        self::assertSame('Boom', $evidence->get('message'));
        self::assertSame(7, $evidence->get('code'));
        self::assertSame('queue.health', $evidence->source);
    }

    public function testAnExceptionMessageCarryingACredentialIsRedacted(): void
    {
        $evidence = Evidence::fromThrowable(
            new \RuntimeException('SQLSTATE[HY000]: password=hunter2'),
            'database.connection',
        );

        self::assertStringNotContainsString('hunter2', json_encode($evidence) ?: '');
    }

    public function testAStackTraceIsRecordedSeparatelyAndBounded(): void
    {
        // Separate because it is the part a client-facing report must never show.
        $evidence = Evidence::stackTrace(new \RuntimeException('Boom'), 'queue.health', frames: 3);

        self::assertSame(EvidenceType::STACK_TRACE, $evidence->type);
        self::assertLessThanOrEqual(3, count($evidence->get('frames', [])));
        self::assertFalse($evidence->type->isClientSafe());
    }

    public function testACredentialIsRecordedOnlyAsWhetherItIsThere(): void
    {
        $present = Evidence::presence('Security key', 'a-real-key', 'security.key');
        $missing = Evidence::presence('Security key', null, 'security.key');

        self::assertSame(Redaction::PRESENT, $present->get('state'));
        self::assertSame(Redaction::MISSING, $missing->get('state'));
        self::assertStringNotContainsString('a-real-key', json_encode($present) ?: '');
    }

    public function testEvidenceSerializesToItsStructureRatherThanProse(): void
    {
        $evidence = new Evidence(
            type: EvidenceType::CRAFT_VERSION,
            label: 'Craft version',
            source: 'craft.version',
            data: ['version' => '5.0.0'],
            observedAt: new \DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        );

        $json = $evidence->jsonSerialize();

        self::assertSame('craftVersion', $json['type']);
        self::assertSame('craft.version', $json['source']);
        self::assertSame(['version' => '5.0.0'], $json['data']);
        self::assertSame('2026-01-01T12:00:00+00:00', $json['observedAt']);
    }

    public function testOnlyTypesDeliberatelyApprovedAreClientSafe(): void
    {
        // Client safety is stated as what is allowed, so a type added later is withheld until
        // someone decides otherwise. Adding a type and making it client-safe fails here, which
        // is the point: the decision gets made rather than defaulted into. Everything that
        // carries internals — traces, database errors, requests, filesystem detail — is out.
        $safe = array_values(array_filter(
            EvidenceType::cases(),
            static fn(EvidenceType $t): bool => $t->isClientSafe(),
        ));

        self::assertSame([
            EvidenceType::PLUGIN_VERSION,
            EvidenceType::CRAFT_VERSION,
            EvidenceType::PHP_VERSION,
            EvidenceType::ENVIRONMENT_VARIABLE,
            EvidenceType::TIMESTAMP,
            EvidenceType::MEASUREMENT,
        ], $safe);
        self::assertGreaterThan(count($safe), count(EvidenceType::cases()) - count($safe));
    }

    public function testAStackTraceCannotBeAskedForUnbounded(): void
    {
        foreach ([-10, 0, 100_000] as $requested) {
            $frames = Evidence::stackTrace(new \RuntimeException('Boom'), 'tests.bounds', $requested)->get('frames', []);

            self::assertIsArray($frames);
            self::assertLessThanOrEqual(SafeException::MAX_FRAMES, count($frames));
        }
    }

    public function testAnAlreadySanitisedExceptionIsAcceptedWithoutBeingRedoneDifferently(): void
    {
        // Evidence, the result description and the log all read from one representation, so
        // handing it the representation rather than the exception must give the same answer.
        $exception = new \RuntimeException('Failed password=hunter2');

        $fromThrowable = Evidence::fromThrowable($exception, 'tests.source');
        $fromSafe = Evidence::fromThrowable(SafeException::from($exception), 'tests.source');

        self::assertSame($fromThrowable->data, $fromSafe->data);
        self::assertStringNotContainsString('hunter2', (string)json_encode($fromSafe));
    }

    // --- The one sanitised representation of an exception.

    public function testWhatIdentifiesTheExceptionIsKept(): void
    {
        $safe = SafeException::from(new \RuntimeException('Connection refused', 7));

        self::assertSame(\RuntimeException::class, $safe->class);
        self::assertSame('Connection refused', $safe->message);
        self::assertSame(7, $safe->code);
        self::assertStringContainsString('DiagnosticModelTest.php:', $safe->origin);
    }

    public function testTheSummaryNamesTheClassAndTheMessage(): void
    {
        $safe = SafeException::from(new \RuntimeException('Connection refused'));

        self::assertSame('RuntimeException: Connection refused', $safe->summary());
    }

    public function testAnExceptionWithNoMessageStillSummarisesToSomething(): void
    {
        self::assertSame('RuntimeException', SafeException::from(new \RuntimeException())->summary());
    }

    public function testTheChainBehindAnExceptionIsKeptAndSanitised(): void
    {
        $safe = SafeException::from(
            new \RuntimeException('outer', 0, new \LogicException('inner password=hunter2')),
        );

        self::assertCount(1, $safe->previous);
        self::assertSame(\LogicException::class, $safe->previous[0]['class']);
        self::assertStringNotContainsString('hunter2', $safe->previous[0]['message']);
    }

    public function testALongChainIsBounded(): void
    {
        $exception = new \RuntimeException('depth 0');

        for ($i = 1; $i <= 12; $i++) {
            $exception = new \RuntimeException("depth $i", 0, $exception);
        }

        self::assertLessThanOrEqual(5, count(SafeException::from($exception)->previous));
    }

    public function testFramesAreBoundedToWhatWasAskedFor(): void
    {
        $safe = SafeException::from(new \RuntimeException('Boom'), frames: 2);

        self::assertLessThanOrEqual(2, count($safe->frames));
    }

    public function testAnUnreasonableFrameCountIsClampedRatherThanHonoured(): void
    {
        // Evidence stays bounded whatever a caller asks for, including a caller that asks for
        // a negative number of frames or for all of them.
        self::assertLessThanOrEqual(SafeException::MAX_FRAMES, count(SafeException::from(new \RuntimeException('Boom'), frames: 100_000)->frames));
        self::assertNotSame([], SafeException::from($this->deepException(), frames: -5)->frames);
        self::assertLessThanOrEqual(1, count(SafeException::from($this->deepException(), frames: -5)->frames));
        self::assertLessThanOrEqual(1, count(SafeException::from($this->deepException(), frames: 0)->frames));
    }

    public function testFramesNameTheCallSiteWithoutItsArguments(): void
    {
        $frames = SafeException::from($this->deepException())->frames;

        self::assertNotSame([], $frames);
        self::assertStringContainsString('DiagnosticModelTest', $frames[0]);
        self::assertStringNotContainsString('hunter2', implode("\n", $frames));
    }

    public function testEverySurfaceOfTheRepresentationIsSerializable(): void
    {
        $json = SafeException::from(new \RuntimeException('Boom'))->jsonSerialize();

        foreach (['class', 'message', 'code', 'origin', 'previous', 'frames'] as $key) {
            self::assertArrayHasKey($key, $json);
        }
    }

    public function testEvidenceDataLeavesTheTraceOut(): void
    {
        // A stack trace is recorded as its own evidence, because it is the part a client-facing
        // report must never show.
        self::assertArrayNotHasKey('frames', SafeException::from(new \RuntimeException('Boom'))->toEvidenceData());
    }

    /**
     * Thrown from a nested call so there is a trace with arguments in it to not leak.
     */
    private function deepException(): \RuntimeException
    {
        try {
            $this->throwWithSecretArgument('hunter2');
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    private function throwWithSecretArgument(string $password): never
    {
        throw new \RuntimeException('Boom');
    }

    // --- The base class a diagnostic is written against.

    /**
     * A diagnostic written the way a real one is: identity declared as a constant and nothing
     * else overridden, so what the base class supplies on its own is what is under test.
     */
    private function aConstantIdDiagnostic(): Diagnostic
    {
        return new class() extends Diagnostic {
            public const ID = 'tests.constantId';

            public function name(): string
            {
                return 'Constant ID check';
            }

            public function category(): DiagnosticCategory
            {
                return DiagnosticCategory::CRAFT;
            }

            public function run(DiagnosticContext $context): DiagnosticResult
            {
                return $this->pass('Nothing wrong.');
            }
        };
    }

    public function testTheBaseClassSatisfiesTheContract(): void
    {
        self::assertInstanceOf(DiagnosticInterface::class, $this->aConstantIdDiagnostic());
    }

    public function testIdentityIsDeterministic(): void
    {
        // Issues, evidence and history are recorded against the ID, so two instances of the
        // same check must be the same check.
        self::assertSame('tests.constantId', ($this->aConstantIdDiagnostic())->id());
        self::assertSame(($this->aConstantIdDiagnostic())->id(), ($this->aConstantIdDiagnostic())->id());
    }

    public function testADiagnosticThatDeclaresNoIdentityHasNone(): void
    {
        // Rather than inventing one that would drift between versions. The registry is what
        // refuses it.
        self::assertSame('', Diagnostic::ID);
    }

    public function testACheckAppliesUnlessItSaysOtherwise(): void
    {
        self::assertTrue(($this->aConstantIdDiagnostic())->isApplicable(new DiagnosticContext()));
    }

    public function testResultsAreAttributedToTheDiagnosticThatMadeThem(): void
    {
        // A result that named itself could name the wrong check, and everything downstream
        // keys off that name.
        $diagnostic = new TestDiagnostic([
            'diagnosticId' => 'tests.attribution',
            'diagnosticName' => 'Attribution check',
            'diagnosticCategory' => DiagnosticCategory::QUEUE,
        ]);

        $result = $diagnostic->build('pass', ['Nothing wrong.']);

        self::assertSame('tests.attribution', $result->diagnosticId);
        self::assertSame('Attribution check', $result->name);
        self::assertSame(DiagnosticCategory::QUEUE, $result->category);
    }

    public function testTheBuildersProduceTheStatusTheyName(): void
    {
        $diagnostic = new TestDiagnostic();

        self::assertSame(DiagnosticStatus::PASS, $diagnostic->build('pass', ['ok'])->status);
        self::assertSame(DiagnosticStatus::INFO, $diagnostic->build('info', ['note'])->status);
        self::assertSame(DiagnosticStatus::WARNING, $diagnostic->build('warning', ['hmm'])->status);
        self::assertSame(DiagnosticStatus::FAIL, $diagnostic->build('fail', ['bad'])->status);
        self::assertSame(DiagnosticStatus::SKIPPED, $diagnostic->build('skipped', ['n/a'])->status);
        self::assertSame(DiagnosticStatus::UNKNOWN, $diagnostic->build('unknown', ['cannot tell'])->status);
    }

    public function testAFindingCanCarryItsOwnSeverityAndRecommendation(): void
    {
        $result = (new TestDiagnostic())->build('warning', [
            'Three jobs have failed.',
            [],
            'Retry them.',
            Severity::HIGH,
        ]);

        self::assertSame(Severity::HIGH, $result->severity());
        self::assertSame('Retry them.', $result->recommendation);
    }

    public function testEvidenceIsAttributedToTheDiagnosticThatObservedIt(): void
    {
        $diagnostic = new TestDiagnostic(['diagnosticId' => 'tests.evidence']);

        $evidence = $diagnostic->build('pass', [
            'Nothing wrong.',
            [new \Tahadudhiya\WebDoctor\models\Evidence(EvidenceType::PHP_VERSION, 'PHP', 'tests.evidence')],
        ])->evidence()[0];

        self::assertSame('tests.evidence', $evidence->source);
    }
}
