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
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\ErrorNormalizer;
use Tahadudhiya\WebDoctor\helpers\EvidenceDisplay;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\ErrorSignature;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\IssueFilter;
use Tahadudhiya\WebDoctor\models\IssueList;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;

/**
 * The value objects Web Doctor's domain is made of: the context that identifies a run, the
 * results it produces, the evidence behind them, the sanitised exception any failure is reduced
 * to, the base class a check is written against, the fingerprint that decides when two findings
 * are the same problem, and the filter the Issue Center is read through.
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

        $carried = ['environment' => null, 'runId' => null, 'startedAt' => null, 'finishedAt' => null, 'durationMs' => null, 'evidence' => null];

        self::assertEquals(
            array_diff_key($result->jsonSerialize(), $carried),
            array_diff_key($copy->jsonSerialize(), $carried),
        );

        // The evidence comes across whole, and attributed to the run it was stamped with.
        $attribution = ['diagnosticId' => null, 'runId' => null, 'environment' => null, 'siteId' => null];

        self::assertCount(1, $copy->evidence());
        self::assertEquals(
            array_diff_key($result->evidence()[0]->jsonSerialize(), $attribution),
            array_diff_key($copy->evidence()[0]->jsonSerialize(), $attribution),
        );
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

    public function testAnExceptionBecomesEvidenceWithoutTheObject(): void
    {
        $evidence = Evidence::fromThrowable(new \RuntimeException('Boom', 7), 'queue.health');

        self::assertSame(EvidenceType::EXCEPTION, $evidence->type);
        self::assertSame(\RuntimeException::class, $evidence->get('class'));
        self::assertSame('Boom', $evidence->get('message'));
        self::assertSame(7, $evidence->get('code'));
        self::assertSame('queue.health', $evidence->source);
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

    public function testEvidenceCarriesTheFactWhereItCameFromAndHowItWasGathered(): void
    {
        $observed = new DateTimeImmutable('2026-03-01T08:00:00+00:00');
        $recorded = new DateTimeImmutable('2026-03-01T08:05:00+00:00');

        $evidence = new Evidence(
            type: EvidenceType::LOG_ENTRY,
            label: 'Mailer error',
            source: 'email.configuration',
            data: ['message' => 'Connection timed out'],
            observedAt: $observed,
            recordedAt: $recorded,
            metadata: ['linesRead' => 400, 'window' => 'PT1H'],
            reference: 'storage/logs/web-2026-03-01.log:1204',
            confidence: Confidence::POSSIBLE,
        );

        self::assertSame(EvidenceType::LOG_ENTRY, $evidence->type);
        self::assertSame('email.configuration', $evidence->source);
        self::assertSame(['message' => 'Connection timed out'], $evidence->data);
        self::assertSame(['linesRead' => 400, 'window' => 'PT1H'], $evidence->metadata);
        self::assertSame('storage/logs/web-2026-03-01.log:1204', $evidence->reference);
        self::assertSame(Confidence::POSSIBLE, $evidence->confidence);
        self::assertSame($observed, $evidence->observedAt);
        self::assertSame($recorded, $evidence->recordedAt);
        self::assertFalse($evidence->truncated);

        // Where it was gathered is not the diagnostic's to say; nothing has stamped it yet.
        self::assertNull($evidence->runId);
        self::assertNull($evidence->environment);
        self::assertNull($evidence->siteId);
        self::assertNull($evidence->diagnosticId);

        // Directly observed is the ordinary case, and says so by claiming no confidence at all.
        self::assertNull(Evidence::presence('Key', 'x', 'tests.source')->confidence);
    }

    public function testEvidenceIsAttributedToTheRunThatGatheredItAndCannotClaimAnother(): void
    {
        // A diagnostic that names its own run, site and environment is overruled by the engine,
        // for the same reason a result's run is stamped rather than claimed.
        $claimed = new Evidence(
            type: EvidenceType::QUEUE,
            label: 'Queue depth',
            source: 'queue.backlog',
            data: ['waiting' => 4],
            diagnosticId: 'someone.else',
            runId: 'claimed-run',
            environment: 'claimed-environment',
            siteId: 999,
        );

        $result = new DiagnosticResult(
            diagnosticId: 'queue.backlog',
            name: 'Queue backlog',
            category: DiagnosticCategory::QUEUE,
            status: DiagnosticStatus::WARNING,
            evidence: [$claimed],
        );

        $at = new DateTimeImmutable();
        $context = new DiagnosticContext(siteId: 3, environment: 'staging', runId: 'real-run');
        $evidence = $result->withExecution($context, $at, $at, 1.0)->evidence()[0];

        self::assertSame('queue.backlog', $evidence->diagnosticId);
        self::assertSame('real-run', $evidence->runId);
        self::assertSame('staging', $evidence->environment);
        self::assertSame(3, $evidence->siteId);
        self::assertSame(['waiting' => 4], $evidence->data);
        self::assertSame($claimed->recordedAt, $evidence->recordedAt);

        // A run with no particular site attributes its evidence to none, rather than to whichever
        // site the evidence claimed.
        $siteless = $result->withExecution(new DiagnosticContext(environment: 'staging'), $at, $at, 1.0)->evidence()[0];

        self::assertNull($siteless->siteId);
    }

    public function testEvidenceSerializesWholeAndIsIdentifiedByWhatItSaysRatherThanWhenItWasSeen(): void
    {
        $evidence = new Evidence(
            type: EvidenceType::DATABASE,
            label: 'Character set',
            source: 'database.charset',
            data: ['charset' => 'utf8mb4'],
            metadata: ['columnsInspected' => false],
            reference: 'elements_sites',
            confidence: Confidence::HIGH,
        );

        $json = $evidence->withAttribution('database.charset', new DiagnosticContext(siteId: 2, environment: 'production', runId: 'run-1'))
            ->jsonSerialize();

        self::assertSame([
            'type', 'label', 'source', 'reference', 'data', 'metadata', 'confidence', 'truncated',
            'redactions', 'diagnosticId', 'runId', 'environment', 'siteId', 'observedAt', 'recordedAt',
        ], array_keys($json));
        self::assertSame('database', $json['type']);
        self::assertSame('elements_sites', $json['reference']);
        self::assertSame(['columnsInspected' => false], $json['metadata']);
        self::assertSame('high', $json['confidence']);
        self::assertSame('run-1', $json['runId']);
        self::assertSame('production', $json['environment']);
        self::assertSame(2, $json['siteId']);

        // The same fact seen by another run, at another moment, is the same fact.
        $later = new Evidence(
            type: EvidenceType::DATABASE,
            label: 'Character set',
            source: 'database.charset',
            data: ['charset' => 'utf8mb4'],
            recordedAt: new DateTimeImmutable('+1 day'),
            metadata: ['columnsInspected' => false],
            reference: 'elements_sites',
            confidence: Confidence::HIGH,
        );

        self::assertSame($evidence->digest(), $later->withAttribution('database.charset', new DiagnosticContext(runId: 'run-2'))->digest());
        self::assertSame(64, strlen($evidence->digest()));
    }

    /**
     * @return array<string, array{Evidence}>
     */
    public static function differentFacts(): array
    {
        $base = ['type' => EvidenceType::QUEUE_JOB, 'label' => 'Failed job', 'source' => 'queue.failedJobs', 'data' => ['count' => 3]];

        return [
            'another reading' => [new Evidence(...['data' => ['count' => 17]] + $base)],
            'another kind of fact' => [new Evidence(...['type' => EvidenceType::QUEUE] + $base)],
            'another label' => [new Evidence(...['label' => 'Failed import'] + $base)],
            'another source' => [new Evidence(...['source' => 'queue.backlog'] + $base)],
            'another place' => [new Evidence(...['reference' => 'job #42'] + $base)],
            'other notes' => [new Evidence(...['metadata' => ['sampleLimit' => 50]] + $base)],
            'held with another confidence' => [new Evidence(...['confidence' => Confidence::POSSIBLE] + $base)],
        ];
    }

    #[DataProvider('differentFacts')]
    public function testWhatMakesItADifferentFact(Evidence $other): void
    {
        // A count that moved is a new reading worth keeping, and so is anything else about what
        // the fact says — so each of these is stored as a fact of its own.
        $base = new Evidence(type: EvidenceType::QUEUE_JOB, label: 'Failed job', source: 'queue.failedJobs', data: ['count' => 3]);

        self::assertNotSame($base->digest(), $other->digest());
    }

    public function testTheSameFactIsTheSameFactHoweverAndWheneverItIsSeen(): void
    {
        // Everything that changes between sightings — the run, when it was recorded, when it was
        // last true, the site and environment stamped on it — is left out, or every run of a
        // check reporting a moving timestamp would store a new row for the same fact.
        $fact = static fn(?DateTimeImmutable $observed, ?DateTimeImmutable $recorded): Evidence => new Evidence(
            type: EvidenceType::QUEUE_JOB,
            label: 'Search index update',
            source: 'queue.failedJobs',
            data: ['error' => 'Timed out', 'occurrences' => 3],
            observedAt: $observed,
            recordedAt: $recorded,
            metadata: ['sampleLimit' => 10],
            reference: 'queue',
            confidence: Confidence::HIGH,
        );

        $first = $fact(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), new DateTimeImmutable('2026-01-01T00:00:05+00:00'));
        $again = $fact(new DateTimeImmutable('2026-02-01T00:00:00+00:00'), new DateTimeImmutable('2026-02-01T00:00:05+00:00'))
            ->withAttribution('queue.failedJobs', new DiagnosticContext(siteId: 4, environment: 'production', runId: 'another-run'));

        self::assertSame($first->digest(), $again->digest());
        self::assertSame($first->digest(), $fact(null, null)->digest());
    }

    public function testEvidenceExactlyAtItsBudgetIsKeptWholeAndOneByteOverIsCut(): void
    {
        // The budget is bytes of the encoded fact, not characters, and the boundary is exact.
        $atLimit = $this->dataEncodingTo(Evidence::MAX_DATA_BYTES);

        self::assertSame(Evidence::MAX_DATA_BYTES, strlen(Evidence::encode($atLimit)));

        $kept = new Evidence(EvidenceType::LOG_ENTRY, 'At the limit', 'tests.budget', $atLimit);

        self::assertSame($atLimit, $kept->data);
        self::assertFalse($kept->truncated);

        $over = $this->dataEncodingTo(Evidence::MAX_DATA_BYTES + 1);
        $cut = new Evidence(EvidenceType::LOG_ENTRY, 'One byte over', 'tests.budget', $over);

        self::assertTrue($cut->truncated);
        self::assertLessThanOrEqual(Evidence::MAX_DATA_BYTES, strlen(Evidence::encode($cut->data)));

        // The notes have a budget of their own, held the same way.
        $notes = new Evidence(EvidenceType::LOG_ENTRY, 'Long notes', 'tests.budget', metadata: $this->dataEncodingTo(Evidence::MAX_METADATA_BYTES + 1));

        self::assertTrue($notes->truncated);
        self::assertLessThanOrEqual(Evidence::MAX_METADATA_BYTES, strlen(Evidence::encode($notes->metadata)));
    }

    public function testCuttingMultibyteTextNeverProducesAnInvalidFact(): void
    {
        // Three- and four-byte characters, long enough to be cut by every bound there is: the
        // string limit, the summary a too-large entry is reduced to, the label and the reference.
        $euro = str_repeat('€', 5000);
        $emoji = str_repeat('🙂', 5000);
        $nested = ['level' => ['deeper' => array_fill(0, 40, str_repeat('ü', 900))]];

        $evidence = new Evidence(
            type: EvidenceType::LOG_ENTRY,
            label: $emoji,
            source: 'tests.utf8',
            data: ['euro' => $euro, 'emoji' => $emoji, 'nested' => $nested] + array_fill_keys(range(0, 20), $euro),
            metadata: ['note' => $emoji],
            reference: $euro,
        );

        $encoded = json_encode($evidence->jsonSerialize());

        self::assertIsString($encoded, 'The fact must still encode as JSON.');
        self::assertTrue(mb_check_encoding($encoded, 'UTF-8'));
        self::assertTrue($evidence->truncated);

        foreach ([$evidence->label, (string)$evidence->reference, (string)$evidence->get('euro'), (string)$evidence->get('emoji')] as $text) {
            self::assertTrue(mb_check_encoding($text, 'UTF-8'), 'A cut landed inside a character.');
        }

        // Malformed input is repaired rather than carried into something that cannot be stored.
        $malformed = new Evidence(EvidenceType::LOG_ENTRY, "bad \xC3\x28 label", 'tests.utf8', ['line' => "bad \xFF byte"]);

        self::assertTrue(mb_check_encoding($malformed->label, 'UTF-8'));
        self::assertTrue(mb_check_encoding((string)$malformed->get('line'), 'UTF-8'));
    }

    public function testEvidenceIsHeldToABudgetAndSaysWhenItWasCut(): void
    {
        // Every entry is within what redaction allows on its own; together they are far beyond
        // what one fact may occupy.
        $data = [];

        for ($i = 0; $i < 60; $i++) {
            $data["line$i"] = str_repeat(chr(97 + $i % 26), 1500);
        }

        $data = ['first' => 'kept whole'] + $data;

        $evidence = new Evidence(
            type: EvidenceType::LOG_ENTRY,
            label: str_repeat('l', 900),
            source: 'tests.budget',
            data: $data,
            metadata: ['sample' => array_fill(0, 90, str_repeat('m', 100))],
            reference: str_repeat('r', 900),
        );

        self::assertLessThanOrEqual(Evidence::MAX_DATA_BYTES, strlen(Evidence::encode($evidence->data)));
        self::assertLessThanOrEqual(Evidence::MAX_METADATA_BYTES, strlen(Evidence::encode($evidence->metadata)));
        self::assertLessThanOrEqual(Evidence::MAX_LABEL_LENGTH, mb_strlen($evidence->label));
        self::assertLessThanOrEqual(Evidence::MAX_REFERENCE_LENGTH, mb_strlen((string)$evidence->reference));
        self::assertTrue($evidence->truncated);

        // What the diagnostic put first is what survives, and what did not survive is counted.
        self::assertSame('kept whole', $evidence->get('first'));
        self::assertMatchesRegularExpression('/^\d+ more omitted$/', (string)$evidence->get(Redaction::OMITTED_KEY));

        // Being cut is part of the fact, so it survives the fact being stamped with its run.
        self::assertTrue($evidence->withAttribution('tests.budget', new DiagnosticContext())->truncated);

        // And evidence that fitted says so.
        self::assertFalse((new Evidence(EvidenceType::SYSTEM, 'Small', 'tests.budget', ['a' => 1]))->truncated);
        self::assertTrue(Evidence::stackTrace(new \RuntimeException('Boom'), 'tests.budget', frames: 1)->truncated);
    }

    public function testWithheldValuesAreCountedSoAReaderKnowsWhatIsMissing(): void
    {
        $evidence = new Evidence(
            type: EvidenceType::CONFIGURATION,
            label: 'Mailer settings',
            source: 'email.configuration',
            data: [
                'host' => 'smtp.example.com',
                'password' => 'hunter2',
                'note' => 'retry with token=abc123 then apiKey=def456',
                'fromEmail' => Redaction::PRESENT,
            ],
        );

        // Two whole values and two inside a sentence. A presence word is an answer, not a
        // withheld value, so it is not counted.
        self::assertSame(3, $evidence->redactions());
        self::assertSame(3, $evidence->jsonSerialize()['redactions']);
        self::assertSame(0, (new Evidence(EvidenceType::SYSTEM, 'Clean', 'tests.source', ['a' => 'b']))->redactions());
    }

    public function testTheDisplayTreeMarksEverythingThatWasWithheldOrCut(): void
    {
        // A page shows a withheld value as withheld, not as a bracket a reader has to interpret.
        $tree = EvidenceDisplay::tree([
            'host' => 'smtp.example.com',
            'password' => Redaction::REDACTED,
            'message' => 'refused: password=' . Redaction::REDACTED . ' for ' . Redaction::REDACTED,
            'fromEmail' => Redaction::MISSING,
            'port' => 587,
            'secure' => true,
            'proxy' => null,
            'deep' => Redaction::DEPTH_LIMIT,
            'log' => 'a long line' . Redaction::TRUNCATED,
            'hosts' => ['a', 'b'],
            Redaction::OMITTED_KEY => '3 more omitted',
        ]);

        self::assertSame('map', $tree['kind']);
        self::assertSame('3 more omitted', $tree['omitted']);

        $nodes = array_column($tree['entries'], 'node', 'key');

        self::assertArrayNotHasKey(Redaction::OMITTED_KEY, $nodes);
        self::assertSame(['kind' => 'text', 'segments' => [['text' => 'smtp.example.com', 'redacted' => false]], 'truncated' => false], $nodes['host']);
        self::assertSame(['kind' => 'redacted'], $nodes['password']);
        self::assertSame([
            ['text' => 'refused: password=', 'redacted' => false],
            ['text' => '', 'redacted' => true],
            ['text' => ' for ', 'redacted' => false],
            ['text' => '', 'redacted' => true],
        ], $nodes['message']['segments']);
        self::assertSame(['kind' => 'presence', 'value' => Redaction::MISSING], $nodes['fromEmail']);
        self::assertSame(['kind' => 'scalar', 'value' => '587'], $nodes['port']);
        self::assertSame(['kind' => 'scalar', 'value' => 'true'], $nodes['secure']);
        self::assertSame(['kind' => 'scalar', 'value' => 'null'], $nodes['proxy']);
        self::assertSame(['kind' => 'deep'], $nodes['deep']);
        self::assertTrue($nodes['log']['truncated']);
        self::assertSame('a long line', $nodes['log']['segments'][0]['text']);
        self::assertSame('list', $nodes['hosts']['kind']);
        self::assertCount(2, $nodes['hosts']['items']);
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

    // --- The fingerprint: what makes two findings the same problem.
    //
    // This is the load-bearing decision in the Issue Center. Too much goes in and an issue
    // fragments into a new one every run, destroying its history; too little goes in and two
    // unrelated problems merge, so resolving one silently closes the other. Both directions are
    // pinned: what must match, and what must not.

    private function aFinding(
        string $diagnosticId = 'craft.version',
        DiagnosticStatus $status = DiagnosticStatus::FAIL,
        string $summary = 'Something is wrong.',
        ?Severity $severity = null,
        ?string $affectedComponent = null,
        ?string $affectedPlugin = null,
    ): DiagnosticResult {
        return new DiagnosticResult(
            diagnosticId: $diagnosticId,
            name: 'A check',
            category: DiagnosticCategory::CRAFT,
            status: $status,
            summary: $summary,
            severity: $severity,
            affectedComponent: $affectedComponent,
            affectedPlugin: $affectedPlugin,
        );
    }

    public function testTheSameProblemAlwaysFingerprintsTheSame(): void
    {
        $finding = $this->aFinding();

        self::assertSame(
            Fingerprint::forResult($finding, 'production', 1),
            Fingerprint::forResult($finding, 'production', 1),
        );
    }

    public function testTheReadingOfAProblemIsNotItsIdentity(): void
    {
        // Wording, severity and status all describe how the problem looks right now. A check
        // reporting "3 jobs have failed" and then "17 jobs have failed" — or warning and then
        // failing — is reporting one problem twice, and raising a fresh issue each time would
        // destroy the history that makes an issue worth keeping.
        $identity = Fingerprint::forResult($this->aFinding(), 'production', 1);

        $readings = [
            'wording' => $this->aFinding(summary: '17 jobs have failed.'),
            'severity' => $this->aFinding(severity: Severity::CRITICAL),
            'status' => $this->aFinding(status: DiagnosticStatus::WARNING),
        ];

        foreach ($readings as $what => $reading) {
            self::assertSame($identity, Fingerprint::forResult($reading, 'production', 1), $what);
        }
    }

    /**
     * @return array<string, array{DiagnosticResult, string, int|null}>
     */
    public static function distinctProblems(): array
    {
        $plain = new DiagnosticResult(
            diagnosticId: 'craft.version',
            name: 'A check',
            category: DiagnosticCategory::CRAFT,
            status: DiagnosticStatus::FAIL,
            summary: 'Something is wrong.',
        );

        $with = static fn(array $overrides): DiagnosticResult => new DiagnosticResult(
            diagnosticId: $overrides['id'] ?? 'craft.version',
            name: 'A check',
            category: DiagnosticCategory::CRAFT,
            status: $overrides['status'] ?? DiagnosticStatus::FAIL,
            summary: 'Something is wrong.',
            affectedComponent: $overrides['component'] ?? null,
            affectedPlugin: $overrides['plugin'] ?? null,
        );

        return [
            'another check' => [$with(['id' => 'queue.backlog']), 'production', 1],
            'another environment' => [$plain, 'staging', 1],
            'another site' => [$plain, 'production', 2],
            // A run with no particular site in view is not a run that was looking at site 1.
            'no site at all' => [$plain, 'production', null],
            'another component' => [$with(['component' => 'fileinfo']), 'production', 1],
            'another plugin' => [$with(['plugin' => 'commerce']), 'production', 1],
        ];
    }

    #[DataProvider('distinctProblems')]
    public function testWhatMakesItADifferentProblem(DiagnosticResult $other, string $environment, ?int $siteId): void
    {
        self::assertNotSame(
            Fingerprint::forResult($this->aFinding(), 'production', 1),
            Fingerprint::forResult($other, $environment, $siteId),
        );
    }

    public function testTheEncodingCannotBeGamedIntoACollision(): void
    {
        // Naming nothing and naming an empty string are different statements.
        self::assertNotSame(Fingerprint::of(['a', null, 'b']), Fingerprint::of(['a', '', 'b']));

        // The separator cannot appear in a part, so no arrangement of values can be rewritten as
        // a different arrangement with the same hash.
        self::assertNotSame(Fingerprint::of(['craft.version', 'production']), Fingerprint::of(['craft.versionproduction', '']));
        self::assertNotSame(Fingerprint::of(['a.b', 'c']), Fingerprint::of(['a', 'b.c']));
    }

    public function testAFingerprintNamesItsSchemeAndIsAFixedLengthDigest(): void
    {
        $fingerprint = Fingerprint::forResult($this->aFinding(), 'production', 1);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprint);

        // Changing what goes into a fingerprint changes what counts as the same problem, so the
        // scheme is inside the hash: old issues stop matching openly rather than two schemes'
        // answers silently merging into one issue.
        self::assertNotSame($fingerprint, Fingerprint::of([
            'craft.version',
            'production',
            '1',
            null,
            null,
        ]));
    }

    // --- The error signature: what makes two occurrences one error.
    //
    // The same balance as the issue fingerprint, with more riding on the second half. Too much
    // goes in and every occurrence of one error becomes a group of its own, because an ID or a
    // moment in its message moved; too little goes in and two different causes are counted as
    // one, which is the mistake a reader cannot see. Both directions are pinned.

    private const ROOT = '/var/www/releases/20260925120000';

    /**
     * An exception as the engine or a check records it, with its trace beside it where given.
     *
     * @param list<array{class: string, message: string}> $previous
     * @param list<string>|null $frames
     * @return list<Evidence>
     */
    private function anError(
        string $message = 'Element 4812 could not be saved.',
        string $class = 'yii\base\Exception',
        string $origin = self::ROOT . '/vendor/craftcms/cms/src/services/Elements.php:1204',
        array $previous = [],
        ?array $frames = null,
        string $source = 'craft.application',
    ): array {
        $evidence = [new Evidence(
            type: EvidenceType::EXCEPTION,
            label: $class,
            source: $source,
            data: ['class' => $class, 'message' => $message, 'code' => 0, 'origin' => $origin, 'previous' => $previous],
            reference: $origin,
        )];

        if ($frames !== null) {
            $evidence[] = new Evidence(
                type: EvidenceType::STACK_TRACE,
                label: 'Stack trace',
                source: $source,
                data: ['frames' => $frames],
                reference: $origin,
            );
        }

        return $evidence;
    }

    private function signatureOf(array $evidence): ErrorSignature
    {
        $signatures = ErrorSignature::allIn($evidence, self::ROOT);

        self::assertCount(1, $signatures);

        return $signatures[0];
    }

    /**
     * Pairs of messages from one error on two occasions: only a value that varies between
     * occurrences differs between them.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function oneErrorTwice(): array
    {
        return [
            'an element ID' => ['Element 4812 could not be saved.', 'Element 77 could not be saved.', 'Element {id} could not be saved.'],
            'an ID under its name' => ['No entry with ID 5 exists.', 'No entry with ID 90210 exists.', 'No entry with ID {id} exists.'],
            'an ID column in SQL' => ['UPDATE x SET y=1 WHERE `elementId`=512', 'UPDATE x SET y=1 WHERE `elementId`=9', 'UPDATE x SET y=1 WHERE `elementId`={id}'],
            'a snake-case ID' => ['Bad row: site_id: 3', 'Bad row: site_id: 14', 'Bad row: site_id: {id}'],
            'a list of IDs in SQL' => ['SELECT * FROM x WHERE id IN (1, 2, 3)', 'SELECT * FROM x WHERE id IN (7)', 'SELECT * FROM x WHERE id IN ({ids})'],
            'a queue job number' => ['Queue job 1182 timed out.', 'Queue job 1190 timed out.', 'Queue job {id} timed out.'],
            'an object number' => ['Object(craft\elements\Entry)#512 is not valid.', 'Object(craft\elements\Entry)#7 is not valid.', 'Object(craft\elements\Entry)#{id} is not valid.'],
            'a UUID' => ['Job 3f2504e0-4f89-11d3-9a0c-0305e82c3301 failed.', 'Job 9b2c1d7e-0000-4abc-8def-1234567890ab failed.', 'Job {uuid} failed.'],
            'an ISO 8601 moment' => ['Lock expired at 2026-09-25T12:34:56+00:00.', 'Lock expired at 2026-10-01T08:00:01Z.', 'Lock expired at {timestamp}.'],
            'an SQL moment' => ["Row changed at '2026-09-25 12:34:56'.", "Row changed at '2025-01-02 00:00:00'.", "Row changed at '{timestamp}'."],
            'an RFC 2822 moment' => ['Rejected on Thu, 25 Sep 2026 12:34:56 +0000.', 'Rejected on Mon, 06 Oct 2025 09:00:00 +0000.', 'Rejected on {timestamp}.'],
            'a Unix timestamp' => ['Token issued at 1758800000 is stale.', 'Token issued at 1758899999 is stale.', 'Token issued at {timestamp} is stale.'],
            'a time of day' => ['Rate limit reset at 12:34:56.', 'Rate limit reset at 23:01:09.', 'Rate limit reset at {time}.'],
            'a client address' => ['Blocked request from 203.0.113.7.', 'Blocked request from 198.51.100.24.', 'Blocked request from {ip}.'],
            'an email address' => ['Could not deliver to jane@example.com.', 'Could not deliver to sam.o@example.org.', 'Could not deliver to {email}.'],
            'a session hash' => ['Session a8f5f167f44f4964e6c998dee827110c expired.', 'Session 0cc175b9c0f1b6a831c399e269772661 expired.', 'Session {hex} expired.'],
            'a memory address' => ['Resource 0x7f3a2c001e80 was freed.', 'Resource 0x55d1e4a0b2 was freed.', 'Resource {hex} was freed.'],
            'a random token' => ['Upload 9fQx2LmZ8Rw3Ty7Kp1Vb5Nc0Hd4Js6Ag is incomplete.', 'Upload Zk3Pq8Wm2Xr7Ty1Lb4Nv6Hc9Js0Df5Ag is incomplete.', 'Upload {token} is incomplete.'],
            'a duplicated value' => ["Duplicate entry '45-1' for key 'idx_slug'", "Duplicate entry '7-2' for key 'idx_slug'", "Duplicate entry '{n}' for key 'idx_slug'"],
            'an amount of memory' => ['Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 65536 bytes)', 'Allowed memory size of {n} bytes exhausted (tried to allocate {n} bytes)'],
            'a duration' => ['Operation timed out after 30001 milliseconds.', 'Operation timed out after 30004 milliseconds.', 'Operation timed out after {n} milliseconds.'],
            "a URL's IDs and query" => ['GET https://api.example.com/v2/orders/8812?ref=abc failed.', 'GET https://api.example.com/v2/orders/31?page=2 failed.', 'GET https://api.example.com/v2/orders/{id}?{query} failed.'],
            'a release directory' => ['Cannot write /srv/releases/20260925120000/storage/x.txt', 'Cannot write /srv/releases/20261001080000/storage/x.txt', 'Cannot write /srv/releases/{release}/storage/x.txt'],
            'an upload temporary' => ['Cannot read /tmp/phpA1b2C3.', 'Cannot read /tmp/phpZz9Yy8.', 'Cannot read /tmp/php{tmp}.'],
            'whitespace' => ["Element  4812\n could not be saved.", 'Element 1 could not be saved.', 'Element {id} could not be saved.'],
            'a request identifier' => ['Request ID 7f3a9c2e1b4d8f60 was rejected.', 'Request ID 0b1c2d3e4f5a6b7c was rejected.', 'Request ID {hex} was rejected.'],
            'an amount too large to be mistaken for a moment' => ['Allowed memory size of 1073741824 bytes exhausted', 'Allowed memory size of 1610612736 bytes exhausted', 'Allowed memory size of {n} bytes exhausted'],
            'an IPv6 address' => ['No route to 2001:0db8:85a3:0000:0000:8a2e:0370:7334.', 'No route to fe80:0000:0000:0000:0202:b3ff:fe1e:8329.', 'No route to {ip}.'],
            'a quoted value after "entry"' => ["Duplicate entry '45' for key 'idx_slug'", "Duplicate entry '9' for key 'idx_slug'", "Duplicate entry '{n}' for key 'idx_slug'"],
            'a Unix timestamp in milliseconds' => ['Signed at 1758800000123.', 'Signed at 1758800999999.', 'Signed at {timestamp}.'],
            "a token in a URL's path" => ['GET https://api.example.com/files/9fQx2LmZ8Rw3Ty7Kp1Vb5Nc0Hd4Js6Ag failed.', 'GET https://api.example.com/files/Zk3Pq8Wm2Xr7Ty1Lb4Nv6Hc9Js0Df5Ag failed.', 'GET https://api.example.com/files/{token} failed.'],
        ];
    }

    #[DataProvider('oneErrorTwice')]
    public function testAValueThatVariesBetweenOccurrencesDoesNotMakeANewError(string $first, string $second, string $normalised): void
    {
        $a = $this->signatureOf($this->anError($first));
        $b = $this->signatureOf($this->anError($second));

        self::assertSame($normalised, $a->message);
        self::assertSame($a->fingerprint('production', 1), $b->fingerprint('production', 1));
        // What this occurrence actually said is kept beside the grouped form, not lost to it.
        self::assertSame(trim($first), trim($a->sample));
    }

    /**
     * Two errors that differ in something that tells causes apart.
     *
     * @return array<string, array{list<Evidence>, list<Evidence>}>
     */
    public static function twoDifferentErrors(): array
    {
        $test = new self('twoDifferentErrors');
        $error = static fn(array $args = []): array => $test->anError(...$args);

        return [
            'another class' => [$error(), $error(['class' => 'yii\db\Exception'])],
            // Error codes and statuses are numbers, and the only thing telling these apart.
            'another database error' => [$error(['message' => 'SQLSTATE[HY000] [2002] Connection refused']), $error(['message' => 'SQLSTATE[HY000] [1045] Access denied'])],
            'another SQLSTATE' => [$error(['message' => 'SQLSTATE[42S02]: not found']), $error(['message' => 'SQLSTATE[42S22]: not found'])],
            'another cURL error' => [$error(['message' => 'cURL error 28: failed']), $error(['message' => 'cURL error 7: failed'])],
            'another HTTP status' => [$error(['message' => 'Client error: 404 Not Found']), $error(['message' => 'Client error: 403 Not Found'])],
            'another table' => [$error(['message' => "Table 'craft_foo' doesn't exist"]), $error(['message' => "Table 'craft_bar' doesn't exist"])],
            'another table with digits' => [$error(['message' => "Table 'craft_2024_backup' doesn't exist"]), $error(['message' => "Table 'craft_2025_backup' doesn't exist"])],
            'another host' => [$error(['message' => 'GET https://api.example.com/x failed']), $error(['message' => 'GET https://api.example.org/x failed'])],
            'another path in the installation' => [$error(['message' => 'Cannot write ' . self::ROOT . '/storage/a.txt']), $error(['message' => 'Cannot write ' . self::ROOT . '/storage/b.txt'])],
            'another array key' => [$error(['message' => 'Undefined array key "handle"']), $error(['message' => 'Undefined array key "title"'])],
            'another column' => [$error(['message' => "Unknown column 'postDate' in 'field list'"]), $error(['message' => "Unknown column 'expiryDate' in 'field list'"])],
            // A long name with a digit in it is a name, not a token.
            'another class named in the message' => [$error(['message' => 'Class "App\\Oauth2AuthorizationServerFactory" not found']), $error(['message' => 'Class "App\\Oauth2ResourceOwnerServerFactory" not found'])],
            // The number after `#` is the error, unless it follows an object.
            'another error number' => [$error(['message' => 'Error #1045 - the server said no']), $error(['message' => 'Error #2002 - the server said no'])],
            // A lone quoted number is often the error code itself.
            'another quoted error code' => [$error(['message' => "Mail failed with code '550'"]), $error(['message' => "Mail failed with code '421'"])],
            // Eight hex digits or fewer is an error code, not an address.
            'another hex error code' => [$error(['message' => 'COM call failed with HRESULT 0x80070005']), $error(['message' => 'COM call failed with HRESULT 0x80004005'])],
            // After "request" or "message" the number is as often a status as an identity.
            'another status after "request"' => [$error(['message' => 'Request 404 could not be completed']), $error(['message' => 'Request 500 could not be completed'])],
            'another reply after "message"' => [$error(['message' => 'Message 550 was rejected']), $error(['message' => 'Message 421 was rejected'])],
            // A three-part version is not an address.
            'another version' => [$error(['message' => 'Requires PHP 8.2.1 or later']), $error(['message' => 'Requires PHP 8.3.0 or later'])],
            // A login in a URL is followed by the host, and the host tells two services apart.
            'another host behind a URL login' => [$error(['message' => 'Connection to redis://cache@redis-a.internal:6379 refused']), $error(['message' => 'Connection to redis://cache@redis-b.internal:6379 refused'])],
            'another package version' => [$error(['message' => 'Package craftcms/cms@5.1.0 not satisfiable']), $error(['message' => 'Package craftcms/cms@5.2.0 not satisfiable'])],
            // A large number followed by what it counts is a count, not a moment.
            'another count that looks like a moment' => [$error(['message' => 'Expected 1234567890 rows']), $error(['message' => 'Expected 1987654321 rows'])],
            'another class with several digits' => [$error(['message' => 'Class Foo2Bar3Baz4QuxQuuxCorgeGrault not found']), $error(['message' => 'Class Foo2Bar3Baz4QuxQuuxCorgeGraulx not found'])],
            'another SQL statement' => [$error(['message' => 'Deadlock found when trying to get lock on UPDATE craft_elements']), $error(['message' => 'Deadlock found when trying to get lock on DELETE craft_elements'])],
            'another plugin in the origin' => [$error(['origin' => self::ROOT . '/vendor/verbb/formie/src/Formie.php:10']), $error(['origin' => self::ROOT . '/vendor/verbb/navigation/src/Formie.php:10'])],
            'another site number' => [$error(['message' => 'Nothing in site 1']), $error(['message' => 'Nothing in site 2'])],
            'another line' => [$error(), $error(['origin' => self::ROOT . '/vendor/craftcms/cms/src/services/Elements.php:1210'])],
            'another file' => [$error(), $error(['origin' => self::ROOT . '/vendor/craftcms/cms/src/services/Entries.php:1204'])],
            'another cause behind it' => [
                $error(['previous' => [['class' => 'PDOException', 'message' => 'Connection refused']]]),
                $error(['previous' => [['class' => 'PDOException', 'message' => 'Access denied']]]),
            ],
            'a cause behind one and not the other' => [$error(), $error(['previous' => [['class' => 'PDOException', 'message' => 'x']]])],
            'another path to the throw' => [
                $error(['frames' => ['craft\services\Elements->saveElement (' . self::ROOT . '/src/A.php:10)']]),
                $error(['frames' => ['craft\services\Elements->deleteElement (' . self::ROOT . '/src/A.php:10)']]),
            ],
        ];
    }

    /**
     * @param list<Evidence> $one
     * @param list<Evidence> $other
     */
    #[DataProvider('twoDifferentErrors')]
    public function testWhatMakesItADifferentError(array $one, array $other): void
    {
        self::assertNotSame(
            $this->signatureOf($one)->fingerprint('production', 1),
            $this->signatureOf($other)->fingerprint('production', 1),
        );
    }

    public function testOneErrorIsOneErrorWhicheverCheckRanIntoItButNeverAcrossEnvironmentsOrSites(): void
    {
        // A database refusing connections is one error however many checks run into it.
        $seenByOne = $this->signatureOf($this->anError(source: 'database.migrations'));
        $seenByAnother = $this->signatureOf($this->anError(source: 'database.charset'));

        self::assertSame($seenByOne->fingerprint('production', 1), $seenByAnother->fingerprint('production', 1));

        // What one environment or site saw is never counted as another's.
        $fingerprints = [
            $seenByOne->fingerprint('production', 1),
            $seenByOne->fingerprint('staging', 1),
            $seenByOne->fingerprint('production', 2),
            $seenByOne->fingerprint('production', null),
        ];

        self::assertSame($fingerprints, array_values(array_unique($fingerprints)));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprints[0]);
        self::assertSame($seenByOne->identity(), $seenByAnother->identity());
    }

    public function testWhereTheInstallationIsDoesNotChangeWhichErrorItIs(): void
    {
        // A deploy that moves the installation to a new release directory, or code Composer
        // installed, reads the same wherever it sits; a path outside both stays as it was.
        self::assertSame('vendor/yiisoft/yii2/db/Connection.php:648', ErrorNormalizer::origin(self::ROOT . '/vendor/yiisoft/yii2/db/Connection.php:648', self::ROOT));
        self::assertSame('modules/Foo.php:12', ErrorNormalizer::origin(self::ROOT . '/modules/Foo.php:12', self::ROOT . '/'));
        self::assertSame('vendor/craftcms/cms/src/Craft.php:9', ErrorNormalizer::origin('/elsewhere/vendor/craftcms/cms/src/Craft.php:9'));
        self::assertSame('/srv/releases/{release}/app.php:3', ErrorNormalizer::origin('/srv/releases/20260101000000/app.php:3'));
        // Deployer's own numbering, as well as timestamps.
        self::assertSame('/srv/releases/{release}/app.php:3', ErrorNormalizer::origin('/srv/releases/123/app.php:3'));
        // An address in text is still one.
        self::assertSame('Mail to {email} bounced', ErrorNormalizer::message('Mail to jo.bloggs+x@example.co.uk bounced'));
        self::assertSame('/opt/app/index.php:3', ErrorNormalizer::origin('/opt/app/index.php:3'));
        self::assertSame('Cannot write @root/storage/x.txt', ErrorNormalizer::message('Cannot write ' . self::ROOT . '/storage/x.txt', self::ROOT));

        $before = $this->signatureOf($this->anError(origin: self::ROOT . '/vendor/craftcms/cms/src/Craft.php:9'));
        $after = ErrorSignature::allIn($this->anError(origin: '/var/www/releases/20261002000000/vendor/craftcms/cms/src/Craft.php:9'), '/var/www/releases/20261002000000')[0];

        self::assertSame($before->fingerprint('production', 1), $after->fingerprint('production', 1));
    }

    public function testWhatPhpGeneratesPerDeclarationIsNotPartOfWhichErrorItIs(): void
    {
        // An anonymous class is named after the file, line and a counter; a closure after the
        // line it was declared on. Neither says what went wrong.
        self::assertSame('RuntimeException@anonymous', ErrorNormalizer::className("RuntimeException@anonymous\0/app/src/Foo.php:12\$0"));
        self::assertSame('Foo@anonymous->run (src/X.php:40)', ErrorNormalizer::frame("Foo@anonymous\0/p/f.php:3\$1->run (" . self::ROOT . '/src/X.php:40)', self::ROOT));
        self::assertSame('{closure} (vendor/a/b.php:1)', ErrorNormalizer::frame('{closure:App\Foo::bar():12} (/x/vendor/a/b.php:1)'));

        $one = $this->signatureOf($this->anError(class: "RuntimeException@anonymous\0/app/a.php:1\$0", frames: ['{closure:A::b():12} (/a.php:1)']));
        $two = $this->signatureOf($this->anError(class: "RuntimeException@anonymous\0/app/a.php:1\$7", frames: ['{closure:A::b():14} (/a.php:3)']));

        self::assertSame($one->fingerprint('production', 1), $two->fingerprint('production', 1));
    }

    public function testThePathAnErrorTookIsItsTopCallsWithoutFilesOrLines(): void
    {
        $frames = static fn(string $file, int $line): array => array_map(
            static fn(int $i): string => sprintf('App\Step%d->handle (%s:%d)', $i, $file, $line + $i),
            range(1, 8),
        );

        $here = ErrorNormalizer::stackFingerprint($frames('/a/b.php', 10));

        // The same calls through files that moved, or lines that shifted, are the same path.
        self::assertSame($here, ErrorNormalizer::stackFingerprint($frames('/c/d.php', 90)));
        // Only the top of the trace counts, so what called the code that failed does not split it.
        $deeper = $frames('/a/b.php', 10);
        $deeper[7] = 'App\Elsewhere->run (/z.php:1)';
        self::assertSame($here, ErrorNormalizer::stackFingerprint($deeper));
        self::assertNull(ErrorNormalizer::stackFingerprint([]));

        // No trace and a trace are different things to have recorded.
        self::assertNotSame(
            $this->signatureOf($this->anError())->fingerprint('production', 1),
            $this->signatureOf($this->anError(frames: $frames('/a/b.php', 10)))->fingerprint('production', 1),
        );
    }

    public function testAResultsErrorsAreFoundOnceEachWithTheirTraces(): void
    {
        $evidence = [
            ...$this->anError(frames: [self::ROOT . '/nothing', 'App\A->b (' . self::ROOT . '/src/A.php:4)']),
            // The same error recorded twice in one result was still run into once. Thrown at the
            // same place, it is paired with the same trace.
            ...$this->anError('Element 99 could not be saved.'),
            ...$this->anError('Something else entirely.', origin: '/app/other.php:1'),
            new Evidence(type: EvidenceType::QUEUE, label: 'Failed jobs', source: 'queue.failedJobs', data: ['failed' => 3]),
            // A trace with no exception beside it is not an error on its own.
            new Evidence(type: EvidenceType::STACK_TRACE, label: 'Stack trace', source: 'x', data: ['frames' => ['a (b:1)']], reference: '/nowhere.php:1'),
            // Nor is exception evidence that names no class.
            new Evidence(type: EvidenceType::EXCEPTION, label: '', source: 'x', data: ['message' => 'orphan']),
        ];

        $signatures = ErrorSignature::allIn($evidence, self::ROOT);

        self::assertCount(2, $signatures);
        self::assertSame(['App\A->b (src/A.php:4)'], array_slice($signatures[0]->frames, 1));
        self::assertNotNull($signatures[0]->stackFingerprint);
        self::assertSame('Exception', $signatures[0]->shortClass());
        self::assertSame([], $signatures[1]->frames);
        self::assertSame('Something else entirely.', $signatures[1]->message);
    }

    public function testAnExceptionRecordedByTheEngineIsRecognisedFromWhatItRecorded(): void
    {
        // The real route in: an exception reduced by SafeException and recorded as evidence the
        // way the engine records a check that broke.
        $thrown = static function(int $id): \RuntimeException {
            return new \RuntimeException("Entry $id could not be found at 2026-09-25 12:00:0" . ($id % 10), 0, new \LogicException("Lookup of row $id failed"));
        };

        $one = SafeException::from($thrown(41));
        $two = SafeException::from($thrown(97));

        $a = $this->signatureOf([Evidence::fromThrowable($one, 'x.y'), Evidence::stackTrace($one, 'x.y')]);
        $b = $this->signatureOf([Evidence::fromThrowable($two, 'x.y'), Evidence::stackTrace($two, 'x.y')]);

        self::assertSame('RuntimeException', $a->class);
        self::assertSame('Entry {id} could not be found at {timestamp}', $a->message);
        self::assertSame([['class' => 'LogicException', 'message' => 'Lookup of row {id} failed']], $a->previous);
        self::assertNotSame([], $a->frames);
        self::assertSame($a->fingerprint('production', 1), $b->fingerprint('production', 1));
    }

    public function testAGroupedMessageIsBoundedAndMalformedTextStillGroups(): void
    {
        // Far past anything evidence would carry, and still one deterministic, bounded answer.
        $huge = str_repeat('Element 12 failed; ', 20000);
        self::assertSame(ErrorNormalizer::message($huge), ErrorNormalizer::message(str_replace('12', '99', $huge)));
        self::assertLessThanOrEqual(ErrorNormalizer::MAX_MESSAGE_LENGTH, mb_strlen(ErrorNormalizer::message($huge)));

        // Recorded through evidence, a huge message arrives already bounded and still groups.
        $a = $this->signatureOf($this->anError(str_repeat('Element 12 failed; ', 20000)));
        $b = $this->signatureOf($this->anError(str_repeat('Element 99 failed; ', 20000)));
        self::assertSame($a->fingerprint('production', 1), $b->fingerprint('production', 1));
        self::assertLessThan(20000, strlen($a->sample));

        $long = ErrorNormalizer::message(str_repeat('word ', 1000));

        self::assertSame(ErrorNormalizer::MAX_MESSAGE_LENGTH, mb_strlen($long));
        self::assertStringEndsWith('…', $long);

        $malformed = ErrorNormalizer::message("Bad byte \xB1 in row 5");

        self::assertTrue(mb_check_encoding($malformed, 'UTF-8'));
        self::assertStringEndsWith('row {id}', $malformed);
    }

    // --- The issue filter: the one place a URL decides what the database is asked.

    public function testTheFilterKeepsWhatItRecognises(): void
    {
        $filter = IssueFilter::fromParams([
            'status' => ['new', 'ignored'],
            'severity' => 'critical',
        ]);

        self::assertSame([IssueStatus::NEW, IssueStatus::IGNORED], $filter->statuses);
        self::assertSame([Severity::CRITICAL], $filter->severities);
        self::assertTrue($filter->hasStatus(IssueStatus::NEW));
        self::assertFalse($filter->hasStatus(IssueStatus::RESOLVED));
        self::assertTrue($filter->hasSeverity(Severity::CRITICAL));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedFilters(): array
    {
        return [
            'a status nobody has' => [['status' => ['new', 'nonsense']], 'status'],
            'a status in the wrong case' => [['status' => 'Ignored'], 'status'],
            'a status that is a list inside a list' => [['status' => [['new']]], 'status'],
            'a severity nobody has' => [['severity' => ['made-up']], 'severity'],
            'a column that cannot be sorted on' => [['sort' => 'id; DROP TABLE'], 'sort'],
            'a direction that is neither' => [['dir' => 'up'], 'dir'],
            'a date that is not one' => [['from' => '2026-02-30'], 'from'],
            'a date with a time' => [['to' => '2026-01-01 10:00'], 'to'],
            'a site that is not a number' => [['siteId' => 'None'], 'siteId'],
            'a check name longer than an ID' => [['diagnostic' => str_repeat('a', 101)], 'diagnostic'],
            'an environment that is a list' => [['environment' => ['production']], 'environment'],
            'page zero' => [['page' => '0'], 'page'],
            'a fractional page' => [['page' => '1.5'], 'page'],
            'a page with a trailing newline' => [['page' => "2\n"], 'page'],
            'a page in words' => [['page' => 'seven'], 'page'],
            'more per page than a page may hold' => [['perPage' => (string)(IssueFilter::MAX_PER_PAGE + 1)], 'perPage'],
        ];
    }

    /**
     * A value sent is taken exactly or refused, naming the parameter. Dropped, a status nobody has
     * would have widened the list to every status, closed ones included.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('refusedFilters')]
    public function testAFilterValueThatIsNotOneIsRefusedRatherThanDropped(array $params, string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        IssueFilter::fromParams($params);
    }

    public function testWhatAFormSendsForAnyKeepsTheDefault(): void
    {
        $filter = IssueFilter::fromParams(['status' => '', 'severity' => [], 'siteId' => '', 'from' => '', 'sort' => '', 'page' => '']);

        self::assertEquals(new IssueFilter(), $filter);
        self::assertSame('queue.backlog', IssueFilter::fromParams(['diagnostic' => '  queue.backlog  '])->diagnosticId);
        self::assertSame('2026-01-31', IssueFilter::fromParams(['from' => '2026-01-31'])->detectedFrom);
        self::assertSame(9, IssueFilter::fromParams(['page' => '9'])->page);
        self::assertSame(IssueFilter::MAX_PER_PAGE, IssueFilter::fromParams(['perPage' => (string)IssueFilter::MAX_PER_PAGE])->perPage);
        self::assertSame(100, (new IssueFilter(page: 3, perPage: 50))->offset());

        foreach (IssueFilter::SORTABLE as $name => $column) {
            self::assertSame($name, IssueFilter::fromParams(['sort' => $name])->sort);
            self::assertMatchesRegularExpression('/\A[A-Za-z]+\z/', $column);
        }
    }

    public function testIssuesBelongingToNoParticularSiteCanBeAskedFor(): void
    {
        $none = IssueFilter::fromParams(['siteId' => IssueFilter::NO_SITE]);

        self::assertTrue($none->withoutSite);
        self::assertNull($none->siteId);
        self::assertTrue($none->isFiltering());

        $one = IssueFilter::fromParams(['siteId' => '4']);

        self::assertSame(4, $one->siteId);
        self::assertFalse($one->withoutSite);
    }

    public function testAFilterSurvivesBeingTurnedIntoALinkAndBack(): void
    {
        $original = IssueFilter::fromParams([
            'status' => ['new', 'investigating'],
            'severity' => ['high'],
            'diagnostic' => 'queue.backlog',
            'siteId' => '2',
            'environment' => 'production',
            'from' => '2026-01-01',
            'to' => '2026-02-01',
            'sort' => 'severity',
            'dir' => 'asc',
            'page' => '3',
        ]);

        self::assertEquals($original, IssueFilter::fromParams($original->toParams()));

        // A link says what was asked for rather than restating every default.
        self::assertSame([], (new IssueFilter())->toParams());
    }

    public function testTheDefaultViewIsWhatIsOutstandingAndSaysSo(): void
    {
        // Stated rather than left to an empty filter: a list that quietly hides closed issues
        // without saying so is one a reader will eventually be misled by.
        $filter = IssueFilter::outstanding();

        self::assertSame(IssueStatus::open(), $filter->statuses);
        self::assertTrue($filter->isFiltering());
        self::assertFalse($filter->hasStatus(IssueStatus::RESOLVED));
    }

    public function testSortingTurnsTheDirectionAroundAndReturnsToTheFirstPage(): void
    {
        $first = (new IssueFilter(page: 6))->sortedBy('severity');

        self::assertSame('severity', $first->sort);
        self::assertFalse($first->ascending);
        self::assertSame(1, $first->page);

        self::assertTrue($first->sortedBy('severity')->ascending);
        // A different column starts over rather than inheriting the other one's direction.
        self::assertFalse($first->sortedBy('severity')->sortedBy('status')->ascending);

        $unsortable = new IssueFilter(sort: 'severity');
        self::assertSame($unsortable, $unsortable->sortedBy('fingerprint'));
    }

    public function testMovingPagesKeepsEverythingElse(): void
    {
        $filter = new IssueFilter(statuses: [IssueStatus::NEW], sort: 'severity', ascending: true, page: 2);
        $moved = $filter->onPage(5);

        self::assertSame(5, $moved->page);
        self::assertSame([IssueStatus::NEW], $moved->statuses);
        self::assertSame('severity', $moved->sort);
        self::assertTrue($moved->ascending);
        self::assertSame(1, $filter->onPage(0)->page);
    }

    public function testAPageOfIssuesKnowsWhereItSitsInTheWholeSet(): void
    {
        // What a page holds is the database's answer, and the positions are asserted against
        // real rows elsewhere. The arithmetic around it is this test's.
        $middle = new IssueList(issues: [], total: 240, filter: new IssueFilter(page: 2, perPage: 50));

        self::assertSame(5, $middle->pageCount());
        self::assertTrue($middle->hasPages());
        self::assertTrue($middle->hasPreviousPage());
        self::assertTrue($middle->hasNextPage());
        self::assertSame(2, $middle->currentPage());

        $empty = new IssueList(issues: [], total: 0, filter: new IssueFilter());

        self::assertTrue($empty->isEmpty());
        self::assertSame(1, $empty->pageCount());
        self::assertFalse($empty->hasPages());

        // A page past the end reports the last page, and no position at all: a page holding
        // nothing must not claim to be showing rows 4901 to 4900.
        $beyond = new IssueList(issues: [], total: 10, filter: new IssueFilter(page: 99, perPage: 50));

        self::assertSame(1, $beyond->currentPage());
        self::assertFalse($beyond->hasNextPage());
        self::assertSame(0, $beyond->firstPosition());
        self::assertSame(0, $beyond->lastPosition());
    }

    /**
     * Data whose encoded form is exactly the given number of bytes, built from entries each well
     * within the string limit so only the byte budget can be what cuts it.
     *
     * @return array<string, string>
     */
    private function dataEncodingTo(int $bytes): array
    {
        $data = [];
        $i = 0;

        while (strlen(Evidence::encode($data)) + 1500 < $bytes) {
            $data['k' . $i++] = str_repeat('a', 1400);
        }

        $data['last'] = '';
        $data['last'] = str_repeat('b', $bytes - strlen(Evidence::encode($data)));

        return $data;
    }
}
