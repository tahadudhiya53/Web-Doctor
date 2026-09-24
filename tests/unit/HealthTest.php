<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\Dashboard;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\HealthSummary;
use Tahadudhiya\WebDoctor\services\Runs;
use Tahadudhiya\WebDoctor\Tests\_support\FailingCache;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use yii\caching\ArrayCache;

/**
 * What the control panel answers with: what a set of results adds up to, how the checks that
 * exist now are reconciled with the last run there was, and where that run is kept.
 */
class HealthTest extends TestCase
{
    // --- The score, and the arithmetic behind it.

    private function aResult(DiagnosticStatus $status, ?Severity $severity = null, string $id = 'test.check'): DiagnosticResult
    {
        return new DiagnosticResult(
            diagnosticId: $id,
            name: 'Check',
            category: DiagnosticCategory::CONFIGURATION,
            status: $status,
            severity: $severity,
        );
    }

    public function testNothingWrongScoresFull(): void
    {
        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::PASS),
            $this->aResult(DiagnosticStatus::PASS),
        ]);

        self::assertSame(100, $health->score);
        self::assertSame(0, $health->countOf(DiagnosticStatus::FAIL));
        self::assertNull($health->worstSeverity);
    }

    public function testAnEmptyRunScoresFullRatherThanZero(): void
    {
        $health = HealthSummary::fromResults([]);

        self::assertSame(100, $health->score);
        self::assertSame(0, $health->total);
    }

    public function testEachSeverityCostsWhatTheWeightsSay(): void
    {
        $weights = HealthSummary::weights();

        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::FAIL, Severity::CRITICAL),
            $this->aResult(DiagnosticStatus::WARNING, Severity::MEDIUM),
        ]);

        self::assertSame(
            100 - $weights[Severity::CRITICAL->value] - $weights[Severity::MEDIUM->value],
            $health->score,
        );
    }

    public function testEveryPenaltyIsShownAgainstTheResultThatCausedIt(): void
    {
        // This is the whole reason a score is allowed to exist here.
        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::PASS, id: 'a.fine'),
            $this->aResult(DiagnosticStatus::FAIL, Severity::CRITICAL, 'b.broken'),
        ]);

        self::assertCount(1, $health->contributions);
        self::assertSame([
            'diagnosticId' => 'b.broken',
            'status' => 'fail',
            'severity' => 'critical',
            'penalty' => HealthSummary::weights()[Severity::CRITICAL->value],
        ], $health->contributions[0]);
    }

    public function testTheWeightsAreShownAlongsideTheScore(): void
    {
        $json = HealthSummary::fromResults([])->jsonSerialize();

        self::assertSame(HealthSummary::weights(), $json['weights']);
        self::assertArrayHasKey('contributions', $json);
        self::assertSame(100, $json['maxScore']);
    }

    public function testACheckThatCouldNotRunCountsAgainstHealth(): void
    {
        // An unanswered question is not a clean bill of health.
        $health = HealthSummary::fromResults([$this->aResult(DiagnosticStatus::ERROR)]);

        self::assertLessThan(100, $health->score);
        self::assertSame(1, $health->countOf(DiagnosticStatus::ERROR));
        self::assertSame(0, $health->countOf(DiagnosticStatus::WARNING));
        self::assertSame(0, $health->countOf(DiagnosticStatus::FAIL));
    }

    public function testACheckThatDidNotApplyCostsNothing(): void
    {
        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::SKIPPED),
            $this->aResult(DiagnosticStatus::INFO),
        ]);

        self::assertSame(100, $health->score);
        self::assertSame([], $health->contributions);
    }

    public function testTheScoreNeverGoesBelowZero(): void
    {
        $health = HealthSummary::fromResults(array_fill(
            0,
            20,
            $this->aResult(DiagnosticStatus::FAIL, Severity::CRITICAL),
        ));

        self::assertSame(0, $health->score);
    }

    public function testResultsAreCountedByStatus(): void
    {
        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::PASS),
            $this->aResult(DiagnosticStatus::PASS),
            $this->aResult(DiagnosticStatus::WARNING),
            $this->aResult(DiagnosticStatus::ERROR),
        ]);

        self::assertSame(4, $health->total);
        self::assertSame(2, $health->countOf(DiagnosticStatus::PASS));
        self::assertSame(1, $health->countOf(DiagnosticStatus::WARNING));
        self::assertSame(1, $health->countOf(DiagnosticStatus::ERROR));
        self::assertSame(0, $health->countOf(DiagnosticStatus::FAIL));
    }

    public function testEveryStatusIsCountedEvenWhenItDidNotOccur(): void
    {
        // So a dashboard can render every column without guarding each one.
        $counts = HealthSummary::fromResults([])->counts;

        self::assertSame(DiagnosticStatus::values(), array_keys($counts));
    }

    public function testTheWorstSeverityFoundIsReported(): void
    {
        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::WARNING, Severity::LOW),
            $this->aResult(DiagnosticStatus::FAIL, Severity::HIGH),
            $this->aResult(DiagnosticStatus::WARNING, Severity::MEDIUM),
        ]);

        self::assertSame(Severity::HIGH, $health->worstSeverity);
    }

    public function testIssuesAreCountedBySeverity(): void
    {
        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::FAIL, Severity::CRITICAL),
            $this->aResult(DiagnosticStatus::FAIL, Severity::HIGH),
            $this->aResult(DiagnosticStatus::WARNING, Severity::HIGH),
        ]);

        self::assertSame(1, $health->countOfSeverity(Severity::CRITICAL));
        self::assertSame(2, $health->countOfSeverity(Severity::HIGH));
        self::assertSame(0, $health->countOfSeverity(Severity::LOW));
    }

    public function testAPassingCheckIsNotCountedAsAnIssueOfItsSeverity(): void
    {
        // A pass has a severity like everything else. Counting it among the issues would put
        // items in a dashboard's "critical" column that nobody needs to act on.
        $health = HealthSummary::fromResults([
            $this->aResult(DiagnosticStatus::PASS, Severity::CRITICAL),
            $this->aResult(DiagnosticStatus::SKIPPED, Severity::HIGH),
        ]);

        self::assertSame(0, $health->countOfSeverity(Severity::CRITICAL));
        self::assertSame(0, $health->countOfSeverity(Severity::HIGH));
        self::assertSame(100, $health->score);
    }

    public function testEverySeverityIsCountedEvenWhenItDidNotOccur(): void
    {
        self::assertSame(Severity::values(), array_keys(HealthSummary::fromResults([])->severityCounts));
    }

    public function testAResultThatStatesNoSeverityIsWeighedByWhatItsStatusImplies(): void
    {
        $health = HealthSummary::fromResults([$this->aResult(DiagnosticStatus::WARNING)]);

        self::assertSame(
            100 - HealthSummary::weights()[Severity::MEDIUM->value],
            $health->score,
        );
    }

    // --- The dashboard: the checks that exist now against the last run there was.

    private function aDiagnostic(string $id, string $name = 'Example check', DiagnosticCategory $category = DiagnosticCategory::CONFIGURATION): TestDiagnostic
    {
        return new TestDiagnostic([
            'diagnosticId' => $id,
            'diagnosticName' => $name,
            'diagnosticCategory' => $category,
        ]);
    }

    private function aResultFor(string $id, DiagnosticStatus $status = DiagnosticStatus::PASS, ?Severity $severity = null): DiagnosticResult
    {
        return new DiagnosticResult(
            diagnosticId: $id,
            name: 'Result of ' . $id,
            category: DiagnosticCategory::CONFIGURATION,
            status: $status,
            severity: $severity,
        );
    }

    /**
     * @param DiagnosticResult[] $results
     */
    private function aRun(array $results): DiagnosticRun
    {
        $now = new DateTimeImmutable();

        return new DiagnosticRun(
            context: new DiagnosticContext(environment: 'test'),
            results: $results,
            startedAt: $now,
            finishedAt: $now,
            durationMs: 1.0,
        );
    }

    public function testWithNoDiagnosticsThereIsNothingToShow(): void
    {
        $dashboard = Dashboard::build([], null);

        self::assertFalse($dashboard->hasDiagnostics());
        self::assertFalse($dashboard->hasRun());
        self::assertFalse($dashboard->describesHealth());
        self::assertSame([], $dashboard->rows);
        self::assertSame([], $dashboard->staleRows);
        self::assertNull($dashboard->health());
    }

    public function testWithNoRunEveryCheckIsListedWithoutAResult(): void
    {
        $dashboard = Dashboard::build([$this->aDiagnostic('a.one'), $this->aDiagnostic('b.two')], null);

        self::assertTrue($dashboard->hasDiagnostics());
        self::assertFalse($dashboard->hasRun());
        self::assertCount(2, $dashboard->rows);
        self::assertNull($dashboard->rows[0]['result']);
        self::assertSame(0, $dashboard->coveredCount);
        self::assertNull($dashboard->health());
    }

    public function testACompleteRunDescribesHealth(): void
    {
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one'), $this->aDiagnostic('b.two')],
            $this->aRun([$this->aResultFor('a.one'), $this->aResultFor('b.two')]),
        );

        self::assertTrue($dashboard->isComplete());
        self::assertTrue($dashboard->describesHealth());
        self::assertSame(2, $dashboard->coveredCount);
        self::assertSame(100, $dashboard->health()?->score);
    }

    public function testAPartialRunIsNotAllowedToDescribeHealth(): void
    {
        // The whole point. Two of three checks passing is not a healthy installation — it is
        // two checks passing, and a score derived from it would be the most trusted number on
        // the page and the least earned.
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one'), $this->aDiagnostic('b.two'), $this->aDiagnostic('c.three')],
            $this->aRun([$this->aResultFor('a.one'), $this->aResultFor('b.two')]),
        );

        self::assertFalse($dashboard->isComplete());
        self::assertFalse($dashboard->describesHealth());
        self::assertSame(2, $dashboard->coveredCount);
        self::assertSame(3, $dashboard->registeredCount());

        // The arithmetic is still available — it just covers two checks, not the installation.
        self::assertSame(100, $dashboard->health()?->score);
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function coverageProvider(): array
    {
        return [
            'nothing covered' => [3, 0, false],
            'one of three' => [3, 1, false],
            'all but one' => [3, 2, false],
            'every check' => [3, 3, true],
        ];
    }

    #[DataProvider('coverageProvider')]
    public function testAScoreIsOfferedOnlyWhenEveryRegisteredCheckWasCovered(int $registered, int $covered, bool $expected): void
    {
        $diagnostics = [];
        $results = [];

        for ($i = 0; $i < $registered; $i++) {
            $diagnostics[] = $this->aDiagnostic("a.check$i");

            if ($i < $covered) {
                $results[] = $this->aResultFor("a.check$i");
            }
        }

        $dashboard = Dashboard::build($diagnostics, $this->aRun($results));

        self::assertSame($covered, $dashboard->coveredCount);
        self::assertSame($expected, $dashboard->describesHealth());
    }

    public function testACheckAddedSinceTheRunIsShownAsNotYetRun(): void
    {
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one'), $this->aDiagnostic('b.new')],
            $this->aRun([$this->aResultFor('a.one')]),
        );

        self::assertSame('b.new', $dashboard->rows[1]['id']);
        self::assertNull($dashboard->rows[1]['result']);
        self::assertTrue($dashboard->rows[1]['registered']);
    }

    public function testAResultFromACheckThatNoLongerExistsIsStillShown(): void
    {
        // A plugin uninstalled between one page load and the next must not make its findings
        // disappear without anybody being told.
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one')],
            $this->aRun([$this->aResultFor('a.one'), $this->aResultFor('gone.away', DiagnosticStatus::FAIL)]),
        );

        self::assertTrue($dashboard->hasStaleResults());
        self::assertSame(1, $dashboard->staleCount());
        self::assertSame('gone.away', $dashboard->staleRows[0]['id']);
        self::assertFalse($dashboard->staleRows[0]['registered']);
        self::assertSame(DiagnosticStatus::FAIL, $dashboard->staleRows[0]['result']->status);
    }

    public function testAStaleResultIsKeptOutOfTheCheckListing(): void
    {
        // The Database group has to be readable as the state of the database. A result from a
        // check that no longer exists is not that, so it gets its own section.
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one')],
            $this->aRun([$this->aResultFor('a.one'), $this->aResultFor('gone.away', DiagnosticStatus::FAIL)]),
        );

        self::assertCount(1, $dashboard->rows);
        self::assertSame('a.one', $dashboard->rows[0]['id']);

        foreach ($dashboard->byCategory() as $group) {
            foreach ($group['rows'] as $row) {
                self::assertTrue($row['registered']);
            }
        }
    }

    public function testAStaleResultDoesNotAffectTheHealthScore(): void
    {
        // A critical failure raised by a plugin that has been removed is not a failure this
        // installation has. Letting it hold the score down would mean the only way to answer the
        // dashboard would be to reinstall the thing that was taken away.
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one')],
            $this->aRun([
                $this->aResultFor('a.one'),
                $this->aResultFor('gone.away', DiagnosticStatus::FAIL, Severity::CRITICAL),
            ]),
        );

        self::assertSame(100, $dashboard->health()?->score);

        // The run itself still says what happened when it ran. That is a different question.
        self::assertSame(70, $dashboard->run?->health()->score);
    }

    public function testAStaleResultDoesNotAffectTheSeverityOrStatusCounts(): void
    {
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one')],
            $this->aRun([
                $this->aResultFor('a.one'),
                $this->aResultFor('gone.away', DiagnosticStatus::FAIL, Severity::CRITICAL),
                $this->aResultFor('also.gone', DiagnosticStatus::WARNING, Severity::HIGH),
            ]),
        );

        $health = $dashboard->health();

        self::assertNotNull($health);
        self::assertSame(1, $health->total);
        self::assertSame(0, $health->countOfSeverity(Severity::CRITICAL));
        self::assertSame(0, $health->countOfSeverity(Severity::HIGH));
        self::assertSame(0, $health->countOf(DiagnosticStatus::FAIL));
        self::assertSame(0, $health->countOf(DiagnosticStatus::WARNING));
        self::assertSame(1, $health->countOf(DiagnosticStatus::PASS));
        self::assertSame([], $health->contributions);
    }

    public function testAStaleResultDoesNotMakeAPartialRunLookComplete(): void
    {
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one'), $this->aDiagnostic('b.two')],
            $this->aRun([$this->aResultFor('a.one'), $this->aResultFor('gone.away')]),
        );

        self::assertSame(1, $dashboard->coveredCount);
        self::assertSame(2, $dashboard->registeredCount());
        self::assertFalse($dashboard->isComplete());
        self::assertFalse($dashboard->describesHealth());
    }

    public function testAStaleResultAloneIsNotTakenForACheckListing(): void
    {
        $dashboard = Dashboard::build([], $this->aRun([$this->aResultFor('gone.away')]));

        self::assertFalse($dashboard->hasDiagnostics());
        self::assertFalse($dashboard->describesHealth());
        self::assertTrue($dashboard->hasStaleResults());
        self::assertSame(100, $dashboard->health()?->score);
    }

    public function testOnlyRegisteredChecksAreOfferedForSelection(): void
    {
        // The selection form is built from the registered rows, so a result from a check that
        // no longer exists cannot be ticked and asked for again.
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one', 'One')],
            $this->aRun([$this->aResultFor('gone.away')]),
        );
        $offered = array_merge(...array_column($dashboard->byCategory(), 'rows'));

        self::assertSame(['a.one'], array_column($offered, 'id'));
        self::assertSame(['gone.away'], array_column($dashboard->staleRows, 'id'));
    }

    public function testTheRegistrysOrderIsTheOrderShown(): void
    {
        // The registry sorts by category and then ID. The dashboard inherits that rather than
        // establishing an order of its own, so two loads of the same site read the same way.
        $dashboard = Dashboard::build([
            $this->aDiagnostic('craft.one', category: DiagnosticCategory::CRAFT),
            $this->aDiagnostic('php.zeta', category: DiagnosticCategory::PHP),
            $this->aDiagnostic('php.alpha', category: DiagnosticCategory::PHP),
        ], null);

        self::assertSame(
            ['craft.one', 'php.zeta', 'php.alpha'],
            array_column($dashboard->rows, 'id'),
        );
    }

    public function testStaleResultsKeepTheOrderTheRunReportedThemIn(): void
    {
        $dashboard = Dashboard::build([], $this->aRun([
            $this->aResultFor('z.gone'),
            $this->aResultFor('a.gone'),
        ]));

        self::assertSame(['z.gone', 'a.gone'], array_column($dashboard->staleRows, 'id'));
    }

    public function testRowsAreGroupedByCategory(): void
    {
        $dashboard = Dashboard::build([
            $this->aDiagnostic('craft.one', category: DiagnosticCategory::CRAFT),
            $this->aDiagnostic('craft.two', category: DiagnosticCategory::CRAFT),
            $this->aDiagnostic('php.one', category: DiagnosticCategory::PHP),
        ], null);

        $groups = $dashboard->byCategory();

        self::assertCount(2, $groups);
        self::assertSame(DiagnosticCategory::CRAFT, $groups[0]['category']);
        self::assertCount(2, $groups[0]['rows']);
        self::assertCount(1, $groups[1]['rows']);
    }

    public function testACheckThatCannotSayWhatItIsCalledCostsTheNameAndNotThePage(): void
    {
        $broken = new class() extends TestDiagnostic {
            public function name(): string
            {
                throw new \RuntimeException('No name.');
            }

            public function category(): DiagnosticCategory
            {
                throw new \RuntimeException('No category.');
            }
        };
        $broken->diagnosticId = 'broken.check';

        $dashboard = Dashboard::build([$broken], null);

        self::assertSame('broken.check', $dashboard->rows[0]['id']);
        self::assertSame('broken.check', $dashboard->rows[0]['name']);
        self::assertSame(DiagnosticCategory::CONFIGURATION, $dashboard->rows[0]['category']);
    }

    public function testTheRunsOwnAccountOfACheckIsWhatIsShown(): void
    {
        // The row describes the result, so it is named the way the result names itself rather
        // than the way the check would name itself today.
        $dashboard = Dashboard::build(
            [$this->aDiagnostic('a.one', 'Renamed since')],
            $this->aRun([$this->aResultFor('a.one')]),
        );

        self::assertSame('Result of a.one', $dashboard->rows[0]['name']);
    }

    // --- Where the last run is kept.

    private ArrayCache $cache;
    private Runs $runs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new ArrayCache();
        $this->runs = new Runs(['cache' => $this->cache, 'environment' => 'test']);
    }

    private function aStoredRun(string $environment = 'test', ?int $siteId = null): DiagnosticRun
    {
        $now = new DateTimeImmutable();

        return new DiagnosticRun(
            context: new DiagnosticContext(siteId: $siteId, environment: $environment),
            results: [],
            startedAt: $now,
            finishedAt: $now,
            durationMs: 5.0,
        );
    }

    public function testARememberedRunComesBack(): void
    {
        $run = $this->aStoredRun();

        self::assertTrue($this->runs->remember($run));
        self::assertSame($run->id(), $this->runs->latest()?->id());
    }

    public function testWithNothingRememberedThereIsNoRun(): void
    {
        self::assertNull($this->runs->latest());
    }

    public function testARunFromAnotherEnvironmentIsNotShownAsThisOne(): void
    {
        // Production's answers say nothing about staging, and a dashboard that showed them as
        // though they did would be worse than an empty one.
        $this->runs->remember($this->aStoredRun(environment: 'production'));

        self::assertNull($this->runs->latest(environment: 'staging'));
        self::assertNotNull($this->runs->latest(environment: 'production'));
    }

    public function testARunFromAnotherSiteIsNotShownAsThisOne(): void
    {
        $this->runs->remember($this->aStoredRun(siteId: 1));

        self::assertNull($this->runs->latest(siteId: 2));
        self::assertNotNull($this->runs->latest(siteId: 1));
    }

    public function testASiteWideRunIsKeptApartFromASiteSpecificOne(): void
    {
        $siteWide = $this->aStoredRun();
        $this->runs->remember($siteWide);
        $this->runs->remember($this->aStoredRun(siteId: 1));

        self::assertSame($siteWide->id(), $this->runs->latest()?->id());
    }

    public function testTheMostRecentRunReplacesTheOneBeforeIt(): void
    {
        $this->runs->remember($this->aStoredRun());
        $latest = $this->aStoredRun();
        $this->runs->remember($latest);

        self::assertSame($latest->id(), $this->runs->latest()?->id());
    }

    public function testAnythingOtherThanARunIsTreatedAsNoRun(): void
    {
        // A cache hands back whatever was last written under a key, including something written
        // by a version of this plugin that no longer exists. A dashboard built from that would
        // be a failure report about Web Doctor dressed up as a health report about the site.
        $this->cache->set($this->runs->key('test', null), ['not' => 'a run']);

        self::assertNull($this->runs->latest());
    }

    public function testACacheThatCannotBeWrittenToIsReportedRatherThanThrown(): void
    {
        // The caller needs to be told the run was not kept; it must not lose the run, or the
        // request, to a cache being down.
        $runs = new Runs(['cache' => new FailingCache(), 'environment' => 'test']);

        self::assertFalse($runs->remember($this->aStoredRun()));
    }

    public function testACacheThatCannotBeReadIsTreatedAsNoRun(): void
    {
        $runs = new Runs(['cache' => new FailingCache(), 'environment' => 'test']);

        self::assertNull($runs->latest());
    }

    public function testAWriteThatSucceedsIsReportedAsSuccess(): void
    {
        $cache = new FailingCache();
        $cache->failWrites = false;
        $cache->failReads = false;
        $runs = new Runs(['cache' => $cache, 'environment' => 'test']);

        self::assertTrue($runs->remember($this->aStoredRun()));
        self::assertNotNull($runs->latest());
    }

    public function testTheKeyCarriesAFormatVersion(): void
    {
        // A stored run is a serialized object graph. When its shape changes the key changes with
        // it, so the old entry is never asked for rather than being deserialized into classes
        // that have moved on.
        self::assertMatchesRegularExpression('/:\d+:/', $this->runs->key('production', 1));
    }

    public function testTheKeyNamesTheEnvironmentAndTheSite(): void
    {
        $key = $this->runs->key('production', 3);

        self::assertStringStartsWith(Runs::CACHE_KEY_PREFIX, $key);
        self::assertStringContainsString('production', $key);
        self::assertStringContainsString('3', $key);
        self::assertNotSame($key, $this->runs->key('production', 4));
        self::assertNotSame($key, $this->runs->key('staging', 3));
    }
}
