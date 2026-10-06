<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\events\RegisterDiagnosticsEvent;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\Tests\_support\ConstantIdDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\ExplodingDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use yii\base\Event;
use yii\base\InvalidArgumentException;

/**
 * The diagnostic core: the registry that holds the checks and the engine that runs them.
 *
 * Between them these two decide what a site is told and what happens when a check is wrong,
 * so the rules asserted here — one ID to one diagnostic, a stable order, and a failure that
 * never escapes the run it happened in — are the ones everything else is built on.
 */
class DiagnosticCoreTest extends TestCase
{
    // --- The registry: what checks exist, and on whose terms.

    public function testARegistryStartsEmpty(): void
    {
        self::assertSame([], (new Diagnostics())->all());
    }

    public function testADiagnosticIsRetrievedByItsId(): void
    {
        $registry = new Diagnostics();
        $registry->register($diagnostic = $this->diagnostic('craft.version'));

        self::assertSame($diagnostic, $registry->get('craft.version'));
    }

    public function testAnUnknownIdIsAnswerableRatherThanFatal(): void
    {
        self::assertNull((new Diagnostics())->get('nothing.here'));
    }

    public function testTwoDifferentDiagnosticsCannotShareAnId(): void
    {
        // Allowing it would silently replace a check and quietly change what a site is told.
        $registry = new Diagnostics();
        $registry->register($this->diagnostic('tests.constantId'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/');

        $registry->register(new ConstantIdDiagnostic());
    }

    public function testADiagnosticWithoutAnIdentityIsRefused(): void
    {
        $registry = new Diagnostics();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must declare a diagnostic ID/');

        $registry->register($this->diagnostic(''));
    }

    public function testTheFirstRegistrationOfAnIdIsTheOneThatKeepsIt(): void
    {
        // Letting a later registration replace an earlier one would make what a site is told
        // depend on plugin load order, which nobody can reproduce or reason about.
        $registry = new Diagnostics();
        $first = $this->diagnostic('craft.version');
        $second = $this->diagnostic('craft.version');

        $registry->register($first);
        $registry->register($second);

        self::assertSame($first, $registry->get('craft.version'));
        self::assertNotSame($second, $registry->get('craft.version'));
        self::assertSame(1, count($registry->all()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validIds(): array
    {
        $ids = [
            'craft.version', 'queue.failedJobs', 'projectConfig.pendingChanges',
            'database.connection', 'tests.a', 'myPlugin.someCheck', 'a.b.c', 'php.ext2',
        ];

        return array_combine($ids, array_map(static fn(string $id): array => [$id], $ids));
    }

    #[DataProvider('validIds')]
    public function testAWellFormedIdIsAccepted(string $id): void
    {
        self::assertTrue(Diagnostics::isValidId($id));

        $registry = new Diagnostics();
        $registry->register($this->diagnostic($id));

        self::assertNotNull($registry->get($id));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIds(): array
    {
        $ids = [
            'no-dot' => 'craftversion',
            'leading dot' => '.version',
            'trailing dot' => 'craft.',
            'double dot' => 'craft..version',
            'hyphen' => 'craft.web-doctor',
            'underscore' => 'craft.failed_jobs',
            'space' => 'craft. version',
            'surrounding whitespace' => ' craft.version ',
            'capitalised segment' => 'Craft.version',
            'segment starting with a digit' => 'craft.2version',
            'slash' => 'craft/version',
            'newline' => "craft.version\n",
            'overlong' => 'craft.' . str_repeat('a', 200),
        ];

        return array_map(static fn(string $id): array => [$id], $ids);
    }

    #[DataProvider('invalidIds')]
    public function testAMalformedIdIsRefusedAtRegistration(string $id): void
    {
        // An ID is recorded against issues, evidence and history for the life of the
        // installation, so a malformed one is caught while it can still be changed.
        self::assertFalse(Diagnostics::isValidId($id));

        $registry = new Diagnostics();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid ID/');

        $registry->register($this->diagnostic($id));
    }

    public function testOrderingIsByCategoryThenIdRatherThanByRegistration(): void
    {
        // Two runs of the same installation have to be comparable, which they are not if the
        // order depends on which plugin booted first.
        $registry = new Diagnostics();
        $registry->register($this->diagnostic('queue.b', category: DiagnosticCategory::QUEUE));
        $registry->register($this->diagnostic('craft.b', category: DiagnosticCategory::CRAFT));
        $registry->register($this->diagnostic('queue.a', category: DiagnosticCategory::QUEUE));
        $registry->register($this->diagnostic('craft.a', category: DiagnosticCategory::CRAFT));

        self::assertSame(['craft.a', 'craft.b', 'queue.a', 'queue.b'], $registry->ids());
    }

    public function testAnotherPluginContributesByHandlingTheClassLevelEvent(): void
    {
        // The way a Craft plugin actually registers: on the class, from its own init(), before
        // any registry instance exists.
        Event::on(
            Diagnostics::class,
            Diagnostics::EVENT_REGISTER_DIAGNOSTICS,
            $handler = function(RegisterDiagnosticsEvent $event): void {
                $event->diagnostics[] = $this->diagnostic('otherPlugin.check');
            },
        );

        try {
            self::assertNotNull((new Diagnostics())->get('otherPlugin.check'));
        } finally {
            Event::off(Diagnostics::class, Diagnostics::EVENT_REGISTER_DIAGNOSTICS, $handler);
        }
    }

    public function testAHandlerThatThrowsCostsOnlyWhatItAndLaterHandlersWouldHaveAdded(): void
    {
        $registry = new Diagnostics();
        $registry->on(Diagnostics::EVENT_REGISTER_DIAGNOSTICS, function(RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = $this->diagnostic('goodPlugin.check');
        });
        $registry->on(Diagnostics::EVENT_REGISTER_DIAGNOSTICS, static function(): void {
            throw new \RuntimeException('A contributor broke.');
        });

        // The first read, where the handler throws, and every read after it.
        self::assertSame(['goodPlugin.check'], $registry->ids());
        self::assertSame(['goodPlugin.check'], $registry->ids());
    }

    public function testContributorsAreAskedOnceRatherThanOnEveryRead(): void
    {
        $registry = new Diagnostics();
        $asked = 0;
        $registry->on(Diagnostics::EVENT_REGISTER_DIAGNOSTICS, function() use (&$asked): void {
            $asked++;
        });

        $registry->all();
        $registry->all();
        count($registry->all());

        self::assertSame(1, $asked);
    }

    /**
     * A contributor can fail in ways the registry never raises itself. Isolation has to cover
     * those too, or one plugin's bug costs a site every diagnostic it has.
     *
     * @return array<string, array{\Closure(self): array<mixed>}>
     */
    public static function badContributions(): array
    {
        return [
            'a diagnostic that cannot even name itself' => [static fn(self $t): array => [new ExplodingDiagnostic()]],
            // What a plugin pushing an ID instead of a diagnostic would produce. The event's array
            // is only typed by its docblock, so nothing stops this at runtime.
            'something that is not a diagnostic at all' => [static fn(self $t): array => ['craft.version']],
            'a malformed ID' => [static fn(self $t): array => [$t->diagnostic('not a valid id')]],
            'no ID' => [static fn(self $t): array => [$t->diagnostic('')]],
            'an ID already taken' => [static fn(self $t): array => [new ConstantIdDiagnostic()]],
        ];
    }

    /**
     * @param \Closure(self): array<mixed> $bad
     */
    #[DataProvider('badContributions')]
    public function testOnePluginsMistakeCostsOnlyItsOwnDiagnostic(\Closure $bad): void
    {
        $registry = new Diagnostics();
        $registry->register($mine = $this->diagnostic(ConstantIdDiagnostic::ID));
        $registry->on(Diagnostics::EVENT_REGISTER_DIAGNOSTICS, function(RegisterDiagnosticsEvent $event) use ($bad): void {
            $event->diagnostics = [...$event->diagnostics, ...$bad($this)];
            $event->diagnostics[] = $this->diagnostic('goodPlugin.check');
        });

        self::assertSame(['goodPlugin.check', ConstantIdDiagnostic::ID], $registry->ids());
        self::assertSame($mine, $registry->get(ConstantIdDiagnostic::ID));
    }

    // --- The engine: running them, and containing what goes wrong.

    private Diagnostics $registry;
    private DiagnosticEngine $engine;
    private DiagnosticContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new Diagnostics();
        $this->engine = new DiagnosticEngine(['registry' => $this->registry]);

        // Stated rather than taken from Craft, which is not running here.
        $this->context = new DiagnosticContext(environment: 'test');
    }

    private function diagnostic(string $id, ?callable $handler = null, DiagnosticCategory $category = DiagnosticCategory::CONFIGURATION): TestDiagnostic
    {
        $diagnostic = new TestDiagnostic([
            'diagnosticId' => $id,
            'diagnosticCategory' => $category,
        ]);

        if ($handler !== null) {
            $diagnostic->handler = \Closure::fromCallable($handler);
        }

        return $diagnostic;
    }

    public function testOneDiagnosticRunsAndReturnsItsConclusion(): void
    {
        $result = $this->engine->run($this->diagnostic(
            'craft.version',
            static fn(TestDiagnostic $d) => $d->build('warning', ['Craft is behind.']),
        ), $this->context);

        self::assertSame('craft.version', $result->diagnosticId);
        self::assertSame(DiagnosticStatus::WARNING, $result->status);
        self::assertSame('Craft is behind.', $result->summary);
    }

    public function testARegisteredDiagnosticCanBeRunByItsIdAlone(): void
    {
        $this->registry->register($this->diagnostic('craft.version'));

        self::assertSame(DiagnosticStatus::PASS, $this->engine->run('craft.version', $this->context)->status);
    }

    public function testRunningAnUnknownIdOnItsOwnSaysSoPlainly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->engine->run('nothing.here', $this->context);
    }

    public function testExecutionIsTimedAndStampedWithTheRunItBelongedTo(): void
    {
        $context = new DiagnosticContext(environment: 'staging', runId: 'fixed-run-id');

        $result = $this->engine->run($this->diagnostic('craft.version'), $context);

        self::assertSame('fixed-run-id', $result->runId);
        self::assertSame('staging', $result->environment);
        self::assertNotNull($result->durationMs);
        self::assertGreaterThanOrEqual(0.0, $result->durationMs);
        self::assertNotNull($result->startedAt);
        self::assertNotNull($result->finishedAt);
    }

    public function testACheckThatDoesNotApplyIsSkippedRatherThanRun(): void
    {
        $diagnostic = $this->diagnostic('commerce.orders');
        $diagnostic->applicable = false;

        $result = $this->engine->run($diagnostic, $this->context);

        self::assertSame(DiagnosticStatus::SKIPPED, $result->status);
        self::assertSame(0, $diagnostic->runs);
        self::assertFalse($result->status->countsTowardHealth());
    }

    public function testADiagnosticThatThrowsBecomesAnErrorResult(): void
    {
        $result = $this->engine->run($this->diagnostic(
            'database.connection',
            static fn() => throw new \RuntimeException('Connection refused'),
        ), $this->context);

        self::assertSame(DiagnosticStatus::ERROR, $result->status);
        self::assertSame('database.connection', $result->diagnosticId);
        // What it threw is evidence, read only with permission to read evidence; the prose every
        // dashboard reader sees does not repeat it.
        self::assertSame('Connection refused', $result->evidence()[0]->get('message'));
        self::assertStringNotContainsString('Connection refused', $result->description);
        self::assertFalse($result->status->isConclusive());
    }

    /**
     * A result is filed under the ID it names. One naming another check's would put its findings
     * on that check's issue, so a check that misreports its own identity is recorded as broken,
     * under the ID it was registered with.
     */
    public function testAResultUnderAnotherChecksIdIsAnErrorOfTheCheckThatReturnedIt(): void
    {
        $result = $this->engine->run($this->diagnostic(
            'tests.impostor',
            static fn() => new DiagnosticResult(diagnosticId: 'craft.version', name: 'Craft version', category: DiagnosticCategory::CRAFT, status: DiagnosticStatus::FAIL, summary: 'Borrowed.'),
        ), $this->context);

        self::assertSame('tests.impostor', $result->diagnosticId);
        self::assertSame(DiagnosticStatus::ERROR, $result->status);
        self::assertStringContainsString('craft.version', (string)$result->evidence()[0]->get('message'));
    }

    public function testAFailedDiagnosticRecordsWhatWentWrongAsEvidence(): void
    {
        // The failure is investigable like anything else Web Doctor finds, rather than being
        // swallowed.
        $result = $this->engine->run($this->diagnostic(
            'database.connection',
            static fn() => throw new \RuntimeException('Connection refused'),
        ), $this->context);

        $types = array_map(static fn($e) => $e->type, $result->evidence());

        self::assertContains(EvidenceType::EXCEPTION, $types);
        self::assertContains(EvidenceType::STACK_TRACE, $types);
        self::assertSame('database.connection', $result->evidence()[0]->source);
    }

    /**
     * The guarantee the whole engine exists for: a site with one broken check is still owed the
     * answers from every other one — whatever it throws, and even when asking a broken check its
     * name throws again inside the catch block containing the first failure.
     *
     * @return array<string, array{\Closure(self): \Tahadudhiya\WebDoctor\base\DiagnosticInterface}>
     */
    public static function brokenDiagnostics(): array
    {
        return [
            'an exception' => [static fn(self $t) => $t->diagnostic('b.broken', static fn() => throw new \RuntimeException('Boom'))],
            // What a hastily written third-party diagnostic throws: a Throwable, not an Exception.
            'an error that is not an exception' => [static fn(self $t) => $t->diagnostic('b.broken', static fn() => throw new \TypeError('Wrong type'))],
            'one that cannot even name itself' => [static fn(self $t) => new ExplodingDiagnostic()],
        ];
    }

    /**
     * @param \Closure(self): \Tahadudhiya\WebDoctor\base\DiagnosticInterface $broken
     */
    #[DataProvider('brokenDiagnostics')]
    public function testOneBrokenDiagnosticDoesNotStopTheRest(\Closure $broken): void
    {
        $before = $this->diagnostic('a.before');
        $after = $this->diagnostic('c.after', static fn(TestDiagnostic $d) => $d->build('fail', ['Bad.']));

        $run = $this->engine->runMany([$before, $broken($this), $after], $this->context);

        self::assertSame(3, $run->count());
        self::assertSame(1, $before->runs);
        self::assertSame(1, $after->runs);
        self::assertSame(DiagnosticStatus::PASS, $run->resultFor('a.before')?->status);
        self::assertSame(DiagnosticStatus::ERROR, $run->results()[1]->status);
        self::assertSame(DiagnosticStatus::FAIL, $run->resultFor('c.after')?->status);
    }

    public function testAnUnknownIdWithinARunIsReportedRatherThanEndingIt(): void
    {
        $run = $this->engine->runMany(['nothing.here', $this->diagnostic('b.fine')], $this->context);

        $unknown = $run->resultFor('nothing.here');

        self::assertSame(DiagnosticStatus::ERROR, $unknown->status);
        self::assertSame(DiagnosticStatus::PASS, $run->resultFor('b.fine')->status);
        // What was thrown is evidence, behind permission to read evidence; the prose does not repeat it.
        $thrown = (string)$unknown->evidence()[0]->get('message');
        self::assertStringContainsString('nothing.here', $thrown);
        self::assertStringNotContainsString($thrown, (string)$unknown->description);
    }

    public function testEveryResultInARunCarriesTheSameRunIdentity(): void
    {
        $run = $this->engine->runMany([
            $this->diagnostic('a.one'),
            $this->diagnostic('b.broken', static fn() => throw new \RuntimeException('Boom')),
        ], $this->context);

        foreach ($run->results() as $result) {
            self::assertSame($run->id(), $result->runId);
        }
    }

    public function testARunIsTimedAsAWhole(): void
    {
        $run = $this->engine->runMany([$this->diagnostic('a.one')], $this->context);

        self::assertGreaterThanOrEqual(0.0, $run->durationMs);

        // The run finished at or after it started, never before.
        self::assertGreaterThanOrEqual($run->startedAt, $run->finishedAt);
        self::assertLessThanOrEqual($run->finishedAt, $run->startedAt);
    }

    public function testEveryResultsChronologyIsConsistentWithItsRun(): void
    {
        $run = $this->engine->runMany([
            $this->diagnostic('a.one'),
            $this->diagnostic('b.broken', static fn() => throw new \RuntimeException('Boom')),
            $this->diagnostic('c.skipped'),
        ], $this->context);

        foreach ($run->results() as $result) {
            self::assertNotNull($result->startedAt);
            self::assertNotNull($result->finishedAt);
            self::assertNotNull($result->durationMs);

            self::assertGreaterThanOrEqual($result->startedAt, $result->finishedAt);
            self::assertGreaterThanOrEqual(0.0, $result->durationMs);

            // And a result cannot have happened outside the run that produced it.
            self::assertGreaterThanOrEqual($run->startedAt, $result->startedAt);
            self::assertLessThanOrEqual($run->finishedAt, $result->finishedAt);
        }
    }

    public function testADurationIsNeverNegativeEvenIfTheClockDisagrees(): void
    {
        // Stamped durations are floored, so a clock adjustment mid-run cannot put a negative
        // number into something that will later be summed and averaged.
        $result = $this->engine->run($this->diagnostic('a.one'), $this->context)
            ->withExecution($this->context, new \DateTimeImmutable(), new \DateTimeImmutable(), -50.0);

        self::assertSame(0.0, $result->durationMs);
    }

    public function testEverythingRegisteredRunsTogether(): void
    {
        $this->registry->register($this->diagnostic('craft.version', category: DiagnosticCategory::CRAFT));
        $this->registry->register($this->diagnostic('queue.jobs', category: DiagnosticCategory::QUEUE));

        $run = $this->engine->runAll($this->context);

        self::assertSame(2, $run->count());
        self::assertSame(['craft.version', 'queue.jobs'], array_map(
            static fn($r): string => $r->diagnosticId,
            $run->results(),
        ));
    }

    public function testDiagnosticsAreGivenTheContextTheyWereRunWith(): void
    {
        $seen = null;
        $this->engine->runMany(
            [$this->diagnostic('a.one', static function(TestDiagnostic $d, DiagnosticContext $c) use (&$seen) {
                $seen = $c;

                return $d->build('pass', ['ok']);
            })],
            new DiagnosticContext(siteId: 7, environment: 'production'),
        );

        self::assertInstanceOf(DiagnosticContext::class, $seen);
        self::assertSame(7, $seen->siteId);
        self::assertSame('production', $seen->environment);
    }

    public function testANamelessFailureIsRecordedAgainstItsClass(): void
    {
        // Less useful than an ID, and infinitely more useful than no run at all.
        $run = $this->engine->runMany([new ExplodingDiagnostic()], $this->context);
        $result = $run->results()[0];

        self::assertSame(DiagnosticStatus::ERROR, $result->status);
        self::assertSame(ExplodingDiagnostic::class, $result->diagnosticId);
    }

    public function testADiagnosticThatCannotDescribeItselfStillProducesAResult(): void
    {
        $diagnostic = new class() extends Diagnostic {
            public const ID = 'a.nameless';

            public function name(): string
            {
                throw new \RuntimeException('No name');
            }

            public function category(): DiagnosticCategory
            {
                throw new \RuntimeException('No category');
            }

            public function run(DiagnosticContext $context): DiagnosticResult
            {
                throw new \RuntimeException('And nothing works');
            }
        };

        $result = $this->engine->run($diagnostic, $this->context);

        self::assertSame(DiagnosticStatus::ERROR, $result->status);
        self::assertSame('a.nameless', $result->diagnosticId);
        self::assertSame(DiagnosticCategory::CONFIGURATION, $result->category);
    }

    public function testADiagnosticThatBreaksWhileDecidingWhetherItAppliesIsContained(): void
    {
        $diagnostic = new class() extends Diagnostic {
            public const ID = 'a.undecided';

            public function name(): string
            {
                return 'Undecided';
            }

            public function category(): DiagnosticCategory
            {
                return DiagnosticCategory::QUEUE;
            }

            public function isApplicable(DiagnosticContext $context): bool
            {
                throw new \RuntimeException('Cannot tell');
            }

            public function run(DiagnosticContext $context): DiagnosticResult
            {
                return $this->pass('Never reached.');
            }
        };

        $result = $this->engine->run($diagnostic, $this->context);

        self::assertSame(DiagnosticStatus::ERROR, $result->status);
        self::assertSame('a.undecided', $result->diagnosticId);
    }
}
