<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\controllers\ErrorsController;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\ErrorGroup;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\Errors;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingIssuesController;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Error grouping, end to end: what a run's errors are recorded as, what counts as the same error
 * across runs and checks, how an error is related to issues, what is bounded, what is never
 * stored, who may read the list and what the pages render.
 *
 * Errors come from real exceptions thrown inside real checks and contained by the real engine,
 * so what is grouped is exactly what the engine records. Every test runs under an environment of
 * its own and removes only that environment's rows.
 */
class ErrorIntelligenceTest extends TestCase
{
    private const ACCESS_CP = 'accessCp';

    private string $environment;
    private Diagnostics $registry;
    private DiagnosticEngine $engine;
    private Issues $issues;
    private Errors $errors;
    private WebDoctor $plugin;
    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Craft::$app->getDb()->tableExists(ErrorGroupRecord::TABLE)) {
            self::fail(sprintf(
                'The table %s does not exist. Reinstall Web Doctor in this project first: `php craft plugin/uninstall web-doctor && php craft plugin/install web-doctor`.',
                ErrorGroupRecord::TABLE,
            ));
        }

        $this->environment = 'tests-' . bin2hex(random_bytes(5));

        // Only what the test registers, for the reason InvestigationTest gives.
        $this->registry = new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };

        $this->engine = new DiagnosticEngine(['registry' => $this->registry]);
        $this->issues = new Issues();
        $this->errors = new Errors(['issues' => $this->issues]);

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
        $this->plugin->set('errors', $this->errors);
        $this->plugin->set('issues', $this->issues);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // Sources go with their groups, and events, evidence and links with their issues.
        ErrorGroupRecord::deleteAll(['like', 'environment', 'tests-%', false]);
        IssueRecord::deleteAll(['like', 'environment', 'tests-%', false]);

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }

        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }

        parent::tearDown();
    }

    // What an error is recorded as -------------------------------------------

    public function testAnErrorACheckRanIntoIsRecordedWithWhatIdentifiesIt(): void
    {
        $this->breaking('tests.broken', 4812);

        $recording = $this->errors->record($this->runChecks());
        $group = $this->onlyGroup();

        self::assertSame(1, $recording->occurrences);
        self::assertSame(1, $recording->created);
        self::assertSame(RuntimeException::class, $group->exceptionClass);
        self::assertSame('RuntimeException', $group->shortClass());
        self::assertSame('Element {id} could not be saved at {timestamp}.', $group->normalizedMessage);
        self::assertStringContainsString('Element 4812 could not be saved', $group->message);
        self::assertSame(1, $group->occurrences);
        self::assertEquals($group->firstSeen, $group->lastSeen);
        // Where it was thrown reads from the installation, not from wherever it happens to sit.
        self::assertStringStartsWith('plugins/Web-Doctor/tests/integration/ErrorIntelligenceTest.php:', $group->origin);
        self::assertNotSame([], $group->frames);
        self::assertNotNull($group->stackFingerprint);
        self::assertSame($this->environment, $group->environment);

        self::assertCount(1, $group->sources);
        self::assertSame('tests.broken', $group->sources[0]->diagnosticId);
        self::assertSame('Check tests.broken', $group->sources[0]->diagnosticName);
        // A check that broke raises no issue, and none was raised before.
        self::assertSame(DiagnosticStatus::ERROR, $group->sources[0]->lastStatus);
        self::assertNull($group->sources[0]->issueId);
    }

    public function testTheSameErrorWithOtherIdsAndMomentsIsCountedAgainstOneGroup(): void
    {
        $check = $this->breaking('tests.broken', 4812);
        $first = new DateTimeImmutable('-2 hours');

        $this->errors->record($this->runChecks(at: $first));

        // The same failure on a later run, about another element at another moment.
        $check->handler = self::throwsFor(77);
        $recording = $this->errors->record($this->runChecks(at: new DateTimeImmutable()));

        $group = $this->onlyGroup();

        self::assertSame(0, $recording->created);
        self::assertSame(2, $group->occurrences);
        self::assertSame($first->getTimestamp(), $group->firstSeen->getTimestamp());
        self::assertGreaterThan($group->firstSeen, $group->lastSeen);
        // The latest occurrence's own wording is what is shown beside the grouped form.
        self::assertStringContainsString('Element 77 could not be saved', $group->message);
        self::assertSame(2, $group->sources[0]->occurrences);
    }

    public function testDifferentErrorsStayApart(): void
    {
        $this->breaking('tests.one', 1);
        $this->check('tests.two', static fn() => throw new \LogicException('Field layout 3 has no tabs.'));
        $this->check('tests.three', static fn() => throw new RuntimeException("Table 'craft_foo' doesn't exist."));

        $recording = $this->errors->record($this->runChecks());

        self::assertSame(3, $recording->groups);
        self::assertCount(3, $this->storedGroups());
    }

    public function testOneErrorManyChecksRanIntoIsOneGroupNamingEachOfThem(): void
    {
        // A database refusing connections, caught and reported by two checks that each needed it.
        $refused = self::refusal();
        $this->check('tests.migrations', self::caught($refused));
        $this->check('tests.charset', self::caught($refused));

        $recording = $this->errors->record($this->runChecks());
        $group = $this->onlyGroup();

        self::assertSame(2, $recording->occurrences);
        self::assertSame(1, $recording->groups);
        self::assertSame(2, $group->occurrences);
        self::assertEqualsCanonicalizing(['tests.charset', 'tests.migrations'], array_map(static fn($s) => $s->diagnosticId, $group->sources));
        // Caught and reported, so there is no trace, and each check could not tell.
        self::assertSame([], $group->frames);
        self::assertSame(DiagnosticStatus::UNKNOWN, $group->sources[0]->lastStatus);
    }

    public function testErrorsInAnotherEnvironmentOrSiteAreNeverCountedTogether(): void
    {
        $this->breaking('tests.broken', 1);

        $this->errors->record($this->runChecks());
        $this->errors->record($this->runChecks(siteId: $this->primarySiteId()));
        $this->errors->record($this->runChecks(environment: $this->environment . '-staging'));

        self::assertCount(3, ErrorGroupRecord::find()->where(['like', 'environment', $this->environment . '%', false])->all());
    }

    public function testARunWithNoErrorsRecordsNothing(): void
    {
        $this->check('tests.fine', static fn(TestDiagnostic $d) => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: DiagnosticStatus::FAIL,
            summary: 'A problem, but no exception.',
        ));

        $recording = $this->errors->record($this->runChecks());

        self::assertSame(0, $recording->occurrences);
        self::assertSame([], $this->storedGroups());
    }

    // How an error is related to issues ---------------------------------------

    public function testAnErrorIsRelatedToTheIssueItsChecksFindingsAreRecordedOn(): void
    {
        // A failure that carries the exception behind it raises an issue, and the error is
        // related to that issue.
        $refused = self::refusal();
        $check = $this->check('tests.connection', static fn(TestDiagnostic $d) => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: DiagnosticStatus::FAIL,
            summary: 'The database refused the connection.',
            evidence: [Evidence::fromThrowable($refused, $d->id())],
        ));
        $this->check('tests.unrelated', self::caught($refused));

        $run = $this->runChecks();
        $this->issues->reconcile($run);
        $this->errors->record($run);

        $issue = IssueRecord::findOne(['environment' => $this->environment, 'diagnosticId' => 'tests.connection']);
        self::assertInstanceOf(IssueRecord::class, $issue);

        $group = $this->onlyGroup();
        $bySource = [];

        foreach ($group->sources as $source) {
            $bySource[$source->diagnosticId] = $source->issueId;
        }

        self::assertSame((int)$issue->id, $bySource['tests.connection']);
        // A check that could not tell raised nothing, so it is related to nothing.
        self::assertNull($bySource['tests.unrelated']);
        self::assertSame([(int)$issue->id], $group->issueIds());
        self::assertSame([$group->id], array_map(static fn(ErrorGroup $g): int => $g->id, $this->errors->forIssue((int)$issue->id)));

        // The same check breaking on a later run raises no issue of its own, but its findings
        // here are still recorded on the one it raised before.
        $check->handler = static fn() => throw new RuntimeException('Something else broke.');
        $later = $this->runChecks(at: new DateTimeImmutable('+1 minute'));
        $this->issues->reconcile($later);
        $this->errors->record($later);

        self::assertCount(2, $this->errors->forIssue((int)$issue->id));
    }

    public function testAnErrorOutlivesItsIssueAndItsSourcesGoWithIt(): void
    {
        $refused = self::refusal();
        $this->check('tests.connection', static fn(TestDiagnostic $d) => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: DiagnosticStatus::FAIL,
            summary: 'Refused.',
            evidence: [Evidence::fromThrowable($refused, $d->id())],
        ));

        $run = $this->runChecks();
        $this->issues->reconcile($run);
        $this->errors->record($run);

        IssueRecord::deleteAll(['environment' => $this->environment]);

        $group = $this->onlyGroup();
        self::assertNull($group->sources[0]->issueId);

        ErrorGroupRecord::deleteAll(['id' => $group->id]);
        self::assertSame(0, (int)ErrorSourceRecord::find()->where(['errorGroupId' => $group->id])->count());
    }

    // Races, bounds and secrets ------------------------------------------------

    public function testARequestThatLosesTheRaceToCreateAGroupJoinsTheWinner(): void
    {
        $this->breaking('tests.broken', 1);
        $this->errors->record($this->runChecks());

        // The winner's group is put back on another connection once the loser has read inside its
        // own transaction, as a real second request's would be: the loser's snapshot cannot see
        // it, the unique index refuses its insert, and it counts against the winner.
        $row = ErrorGroupRecord::findOne(['environment' => $this->environment]);
        self::assertNotNull($row);
        $attributes = $row->getAttributes();
        $row->delete();

        $loser = new class(['issues' => $this->issues]) extends Errors {
            /** @var callable|null */
            public $commitWinner;

            protected function groupsByFingerprint(array $fingerprints): array
            {
                $found = parent::groupsByFingerprint($fingerprints);

                if ($this->commitWinner !== null) {
                    ($this->commitWinner)();
                    $this->commitWinner = null;
                }

                return $found;
            }
        };
        $loser->commitWinner = static function() use ($attributes): void {
            $other = clone Craft::$app->getDb();
            $other->open();
            $other->createCommand()->insert(ErrorGroupRecord::TABLE, $attributes)->execute();
            $other->close();
        };

        $recording = $loser->record($this->runChecks(at: new DateTimeImmutable('+1 minute')));
        $group = $this->onlyGroup();

        self::assertSame(0, $recording->created);
        self::assertSame((int)$attributes['id'], $group->id);
        self::assertSame(2, $group->occurrences);
        // The winner's source went with the row it was put back as; the loser's sighting is its own.
        self::assertSame(1, $group->sources[0]->occurrences);
    }

    public function testAPlaceHoldsABoundedNumberOfGroupsAndTheLeastRecentlySeenGoFirst(): void
    {
        $this->errors->maxGroups = 3;
        $check = $this->breaking('tests.varied', 1);
        $base = new DateTimeImmutable('-1 hour');

        // Four different errors, each on a run of its own, a minute apart.
        foreach (['alpha', 'beta', 'gamma', 'delta'] as $i => $word) {
            $check->handler = static fn() => throw new RuntimeException("The $word step failed.");
            $this->errors->record($this->runChecks(at: $base->modify("+$i minutes")));
        }

        $messages = array_map(static fn(ErrorGroup $g): string => $g->normalizedMessage, $this->storedGroups());

        self::assertSame(['The delta step failed.', 'The gamma step failed.', 'The beta step failed.'], $messages);

        // Another place's groups are not this place's to push out.
        $this->errors->record($this->runChecks(environment: $this->environment . '-other', at: $base->modify('+10 minutes')));
        self::assertCount(3, $this->storedGroups());
    }

    public function testOneRunWithMoreDistinctErrorsThanTheLimitKeepsExactlyTheLimit(): void
    {
        $this->errors->maxGroups = 10;
        $stages = range('a', 'y');
        $this->check('tests.many', self::caughtAll($stages));

        $first = $this->errors->record($this->runChecks(at: new DateTimeImmutable('-1 hour')));

        // The first ten the run met are kept, the rest are counted as left out. The list shows
        // the most recently seen first, and among equals the newest.
        self::assertSame(10, $first->groups);
        self::assertSame(15, $first->omitted);
        self::assertSame(array_map(static fn(string $s): string => "Stage $s failed.", array_reverse(array_slice($stages, 0, 10))), $this->messagesInOrder());

        // The same run again keeps the same ten and counts them, rather than trading them for
        // the ones it left out last time.
        $again = $this->errors->record($this->runChecks(at: new DateTimeImmutable('-30 minutes')));

        self::assertSame(0, $again->created);
        self::assertSame(15, $again->omitted);
        self::assertSame(array_map(static fn(string $s): string => "Stage $s failed.", array_reverse(array_slice($stages, 0, 10))), $this->messagesInOrder());
        self::assertSame([2], array_values(array_unique(array_map(static fn(ErrorGroup $g): int => $g->occurrences, $this->storedGroups()))));

        // New errors on a later run push out the least recently seen, and never more than
        // needed: exactly the limit is left.
        $this->registry = $this->emptyRegistry();
        $this->engine = new DiagnosticEngine(['registry' => $this->registry]);
        $this->check('tests.new', self::caughtAll(['x', 'y', 'z']));
        $later = $this->errors->record($this->runChecks());

        self::assertSame(3, $later->created);
        self::assertSame(0, $later->omitted);
        self::assertCount(10, $this->storedGroups());
        self::assertSame(
            ['Stage z failed.', 'Stage y failed.', 'Stage x failed.', 'Stage j failed.', 'Stage i failed.', 'Stage h failed.', 'Stage g failed.', 'Stage f failed.', 'Stage e failed.', 'Stage d failed.'],
            $this->messagesInOrder(),
        );
    }

    public function testErrorsAlreadyKeptComeFirstWhenARunMeetsMoreThanTheLimit(): void
    {
        $this->errors->maxGroups = 2;
        $this->check('tests.many', self::caughtAll(['c']));
        $this->errors->record($this->runChecks(at: new DateTimeImmutable('-1 hour')));

        // A run meeting a, b and c keeps c — whose count a reader is already following — and a.
        $this->registry = $this->emptyRegistry();
        $this->engine = new DiagnosticEngine(['registry' => $this->registry]);
        $this->check('tests.many', self::caughtAll(['a', 'b', 'c']));
        $recording = $this->errors->record($this->runChecks());

        self::assertSame(1, $recording->omitted);
        self::assertEqualsCanonicalizing(['Stage a failed.', 'Stage c failed.'], $this->messagesInOrder());
    }

    public function testADeletedSitesErrorsAreNotTheInstallationsToBePushedOut(): void
    {
        $this->breaking('tests.broken', 1);
        $this->errors->record($this->runChecks(siteId: $this->primarySiteId(), at: new DateTimeImmutable('-1 hour')));

        // What a site's hard deletion leaves: the reference nulled by the foreign key, the name kept.
        ErrorGroupRecord::updateAll(['siteId' => null], ['environment' => $this->environment]);

        $this->errors->maxGroups = 1;
        $this->registry = $this->emptyRegistry();
        $this->engine = new DiagnosticEngine(['registry' => $this->registry]);
        $this->check('tests.other', static fn() => throw new \LogicException('Installation-wide failure.'));
        $this->errors->record($this->runChecks());

        $groups = $this->storedGroups();
        $labels = array_map(static fn(ErrorGroup $g): string => $g->siteLabel(), $groups);

        self::assertCount(2, $groups);
        self::assertContains('All sites', $labels);
        self::assertContains(Craft::$app->getSites()->getPrimarySite()->getName() . ' (deleted)', $labels);
    }

    public function testAGroupNeverExistsWithoutTheSourceThatExplainsIt(): void
    {
        $this->breaking('tests.broken', 1);

        $failing = new class(['issues' => $this->issues]) extends Errors {
            protected function recordSource(int $groupId, DiagnosticResult $result, DiagnosticRun $run, string $seenAt, ?int $issueId): void
            {
                throw new RuntimeException('Source refused.');
            }
        };

        try {
            $failing->record($this->runChecks());
            self::fail('A recording whose source could not be written reported success.');
        } catch (RuntimeException $e) {
            self::assertSame('Source refused.', $e->getMessage());
        }

        self::assertSame([], $this->storedGroups());
    }

    public function testAGroupsCountIsAlwaysTheSumOfItsChecksCounts(): void
    {
        $refused = self::refusal();
        $this->check('tests.migrations', self::caught($refused));
        $charset = $this->check('tests.charset', self::caught($refused));

        $this->errors->record($this->runChecks(at: new DateTimeImmutable('-2 hours')));
        $this->errors->record($this->runChecks(at: new DateTimeImmutable('-1 hour')));
        $charset->handler = self::caught(new RuntimeException('Something unrelated.'));
        $this->errors->record($this->runChecks());

        foreach ($this->storedGroups() as $group) {
            self::assertSame($group->occurrences, array_sum(array_map(static fn($s): int => $s->occurrences, $group->sources)));
            self::assertCount(count(array_unique(array_map(static fn($s): string => $s->diagnosticId, $group->sources))), $group->sources);
        }

        // Two checks on two runs, then one: five occurrences of the refusal, one of the other.
        $counts = [];

        foreach ($this->storedGroups() as $group) {
            $counts[$group->normalizedMessage] = $group->occurrences;
        }

        ksort($counts);
        self::assertSame(['SQLSTATE[HY000] [2002] The server refused the connection to {ip} after {n} ms password=[redacted]' => 5, 'Something unrelated.' => 1], $counts);
    }

    public function testAnOversizedExceptionIsStoredBoundedAndWithoutItsSecret(): void
    {
        $this->check('tests.huge', static fn() => throw new RuntimeException(str_repeat('Element 12 failed password=hunter2; ', 8000)));

        $this->errors->record($this->runChecks());
        $row = ErrorGroupRecord::findOne(['environment' => $this->environment]);

        self::assertInstanceOf(ErrorGroupRecord::class, $row);
        self::assertLessThanOrEqual(1000, mb_strlen($row->normalizedMessage));
        self::assertLessThan(5000, strlen((string)$row->message));
        self::assertStringNotContainsString('hunter2', (string)json_encode(ErrorGroupRecord::find()->where(['environment' => $this->environment])->asArray()->all()));
    }

    public function testSourcesAreReadAndCountedInOneStatementEachHoweverManyChecksRanIntoAnError(): void
    {
        $refused = self::refusal();

        foreach (range(1, 6) as $i) {
            $this->check("tests.reader$i", self::caught($refused));
        }

        $this->errors->record($this->runChecks(at: new DateTimeImmutable('-1 hour')));

        // Seen again by all six: their sources are read together and counted together.
        $statements = $this->statementsDuring(fn() => $this->errors->record($this->runChecks()));
        $onSources = array_values(array_filter($statements, static fn(string $sql): bool => str_contains($sql, 'webdoctor_error_sources')));

        self::assertCount(1, array_filter($onSources, static fn(string $sql): bool => stripos(ltrim($sql), 'SELECT') === 0), implode("\n", $onSources));
        self::assertCount(1, array_filter($onSources, static fn(string $sql): bool => stripos(ltrim($sql), 'UPDATE') === 0), implode("\n", $onSources));
        self::assertCount(0, array_filter($onSources, static fn(string $sql): bool => stripos(ltrim($sql), 'INSERT') === 0));

        $group = $this->onlyGroup();
        self::assertSame(12, $group->occurrences);
        self::assertSame([2], array_values(array_unique(array_map(static fn($s): int => $s->occurrences, $group->sources))));
    }

    public function testTheLimitHoldsAtOneAndLeavesRoomAtItsDefault(): void
    {
        $this->errors->maxGroups = 1;
        $this->check('tests.many', self::caughtAll(['a', 'b', 'c']));

        $recording = $this->errors->record($this->runChecks());

        self::assertSame(1, $recording->groups);
        self::assertSame(2, $recording->omitted);
        self::assertSame(['Stage a failed.'], $this->messagesInOrder());

        // At the default of 500, twenty-five distinct errors are all kept.
        $default = new Errors(['issues' => $this->issues]);
        $this->registry = $this->emptyRegistry();
        $this->engine = new DiagnosticEngine(['registry' => $this->registry]);
        $this->check('tests.many', self::caughtAll(range('a', 'y')));
        $recording = $default->record($this->runChecks(environment: $this->environment . '-default'));

        self::assertSame(500, $default->maxGroups);
        self::assertSame(25, $recording->groups);
        self::assertSame(0, $recording->omitted);
    }

    public function testALimitBelowOneIsRefusedRatherThanReadAsAnotherNumber(): void
    {
        $this->breaking('tests.broken', 1);

        foreach ([0, -5] as $limit) {
            $this->errors->maxGroups = $limit;

            try {
                $this->errors->record($this->runChecks());
                self::fail("A limit of $limit was accepted.");
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString((string)$limit, $e->getMessage());
            }
        }

        // Refused before anything was kept.
        self::assertSame([], $this->storedGroups());
    }

    public function testTheSameErrorOnTwoSitesIsTwoErrorsAndOneSitesLimitLeavesTheOtherAlone(): void
    {
        $sites = array_slice(Craft::$app->getSites()->getAllSiteIds(), 0, 2);

        if (count($sites) < 2) {
            self::markTestSkipped('Needs an installation with two sites.');
        }

        [$one, $two] = array_map('intval', $sites);
        $this->breaking('tests.broken', 1);
        $this->errors->record($this->runChecks(siteId: $one, at: new DateTimeImmutable('-1 hour')));
        $this->errors->record($this->runChecks(siteId: $two, at: new DateTimeImmutable('-1 hour')));

        self::assertCount(2, $this->storedGroups());

        $this->errors->maxGroups = 1;
        $this->registry = $this->emptyRegistry();
        $this->engine = new DiagnosticEngine(['registry' => $this->registry]);
        $this->check('tests.other', static fn() => throw new \LogicException('Only on the first site.'));
        $this->errors->record($this->runChecks(siteId: $one));

        $bySite = [];

        foreach ($this->storedGroups() as $group) {
            $bySite[$group->siteId][] = $group->normalizedMessage;
        }

        self::assertSame(['Only on the first site.'], $bySite[$one]);
        self::assertSame(['Element {id} could not be saved at {timestamp}.'], $bySite[$two]);
    }

    public function testNoCredentialReachesAnyTableOrTheStoredRunByAnyRoute(): void
    {
        $secrets = self::secrets();
        $message = self::leakyMessage();
        $leak = new RuntimeException($message, 0, new \PDOException('SQLSTATE[HY000] [1045] Access denied password=chain-value-4471'));

        // A finding carrying the exception, which becomes an issue with evidence; a check that
        // breaks with it; both grouped; the run stored as the dashboard stores it; the issue
        // investigated.
        $this->check('tests.finding', static fn(TestDiagnostic $d) => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: "Check {$message}",
            category: $d->category(),
            status: DiagnosticStatus::FAIL,
            summary: "Refused: $message",
            description: $message,
            evidence: [Evidence::fromThrowable($leak, $d->id()), Evidence::stackTrace($leak, $d->id())],
            recommendation: $message,
        ));
        $this->check('tests.breaking', static fn() => throw $leak);

        $run = $this->runChecks();
        $this->issues->reconcile($run);
        $this->errors->record($run);

        $runs = new \Tahadudhiya\WebDoctor\services\Runs(['cache' => new \yii\caching\ArrayCache(), 'environment' => $this->environment]);
        self::assertTrue($runs->remember($run));
        self::assertNotNull($runs->latest(null));

        $issue = IssueRecord::findOne(['environment' => $this->environment, 'diagnosticId' => 'tests.finding']);
        self::assertInstanceOf(IssueRecord::class, $issue);

        $investigations = new \Tahadudhiya\WebDoctor\services\Investigations([
            'registry' => $this->registry,
            'engine' => $this->engine,
            'issues' => $this->issues,
            'errors' => $this->errors,
            'environment' => $this->environment,
        ]);
        $investigation = $investigations->investigate((int)$issue->id);

        $groupIds = ErrorGroupRecord::find()->select(['id'])->where(['environment' => $this->environment])->column();
        $issueIds = IssueRecord::find()->select(['id'])->where(['environment' => $this->environment])->column();
        $stored = (string)json_encode([
            ErrorGroupRecord::find()->where(['id' => $groupIds])->asArray()->all(),
            ErrorSourceRecord::find()->where(['errorGroupId' => $groupIds])->asArray()->all(),
            IssueRecord::find()->where(['id' => $issueIds])->asArray()->all(),
            \Tahadudhiya\WebDoctor\records\IssueEventRecord::find()->where(['issueId' => $issueIds])->asArray()->all(),
            \Tahadudhiya\WebDoctor\records\EvidenceRecord::find()->where(['issueId' => $issueIds])->asArray()->all(),
            \Tahadudhiya\WebDoctor\records\InvestigationRecord::find()->where(['id' => $investigation->id])->asArray()->all(),
            \Tahadudhiya\WebDoctor\records\InvestigationStepRecord::find()->where(['investigationId' => $investigation->id])->asArray()->all(),
        ]) . serialize($runs->latest(null));

        self::assertNotSame([], $groupIds);

        foreach ([...$secrets, 'chain-value-4471'] as $secret) {
            self::assertStringNotContainsString($secret, $stored, "A credential reached storage: $secret");
        }

        // And what identifies the error survived.
        self::assertStringContainsString('Connect failed password=[redacted]', $stored);
    }

    public function testAnEmptyListSaysSoAndALongListPages(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        // No errors in this environment: the environment is not one that exists, so the whole
        // list is shown — and what is asserted is that the page for no groups says so.
        $empty = $this->errors->find(1, $this->environment);
        self::assertTrue($empty->isEmpty());
        self::assertSame(1, $empty->pageCount());

        $this->check('tests.many', self::caughtAll(array_map(static fn(int $i): string => 'step' . chr(96 + intdiv($i, 26) + 1) . chr(97 + $i % 26), range(0, Errors::PAGE_SIZE))));
        $this->errors->record($this->runChecks());

        $first = $this->render('index', 'web-doctor/_errors/_list', [], ['environment' => $this->environment]);
        $second = $this->render('index', 'web-doctor/_errors/_list', [], ['environment' => $this->environment, 'page' => '2']);

        self::assertStringContainsString('Page 1 of 2', $first);
        self::assertStringContainsString('page=2', $first);
        self::assertStringContainsString('Page 2 of 2', $second);
        self::assertSame(1, substr_count($second, 'web-doctor/errors/'));
        // A caught error raises no issue, and the row says there is none.
        self::assertStringContainsString('None', $second);
    }

    public function testTwoRunsInTheSameSecondNeverPruneWhatTheLaterOneSaw(): void
    {
        $this->errors->maxGroups = 1;
        $check = $this->breaking('tests.broken', 1);
        $moment = new DateTimeImmutable('-5 minutes');

        $this->errors->record($this->runChecks(at: $moment));
        $check->handler = static fn() => throw new \LogicException('Another failure.');
        $this->errors->record($this->runChecks(at: $moment));

        $groups = $this->storedGroups();

        self::assertCount(1, $groups);
        self::assertSame('Another failure.', $groups[0]->normalizedMessage);
    }

    public function testNoCredentialAnExceptionCarriesReachesTheErrorTables(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9' . '.eyJzdWIiOiIxMjM0NTY3ODkwIn0' . '.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

        $aws = 'AKIA' . 'IOSFODNN7EXAMPLE';

        $this->check('tests.leaky', static fn() => throw new RuntimeException(
            "Connect failed password=hunter2 dsn=mysql://root:s3cr3t-pass@db:3306/craft auth: $jwt"
            . ' Authorization: Bearer bearer-value-5521'
            . ' body {\"api_key\":\"escaped-json-value\"} {"client_secret": "plain-json-value"}'
            . " array('smtpPassword' => 'php-array-value')"
            . ' GET https://svc.example.com/x?access_token=query-value-8812'
            . ' https://mailer:url-pass-3310@smtp.example.com'
            . " key $aws",
            0,
            new \PDOException('SQLSTATE[HY000] [1045] Access denied, token=secret-token-value'),
        ));

        $this->errors->record($this->runChecks());

        $stored = (string)json_encode([
            ErrorGroupRecord::find()->where(['environment' => $this->environment])->asArray()->all(),
            ErrorSourceRecord::find()->where(['errorGroupId' => ErrorGroupRecord::find()->select(['id'])->where(['environment' => $this->environment])])->asArray()->all(),
        ]);

        foreach (['hunter2', 's3cr3t-pass', 'secret-token-value', $jwt, 'bearer-value-5521', 'escaped-json-value', 'plain-json-value', 'php-array-value', 'query-value-8812', 'url-pass-3310', $aws] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }

        // What identifies the error survives the redaction.
        self::assertStringContainsString('Connect failed password=[redacted]', $stored);
        self::assertStringContainsString('1045', $stored);
    }

    // Who may read it, and what the pages render --------------------------------

    public function testReadingErrorsNeedsWhatReadingIssuesNeeds(): void
    {
        $group = $this->seedGroup();
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $this->request('GET');

        foreach (['index' => [], 'detail' => ['groupId' => $group->id]] as $action => $params) {
            try {
                $this->controller()->runAction($action, $params);
                self::fail("The $action action answered without the permission to read issues.");
            } catch (ForbiddenHttpException) {
                // As it should.
            }
        }
    }

    public function testErrorsAreNotReachableFromTheFrontEndOrWithoutSigningIn(): void
    {
        $group = $this->seedGroup();
        $this->signIn(admin: true);
        $this->request('GET', cp: false);

        foreach (['index' => [], 'detail' => ['groupId' => $group->id]] as $action => $params) {
            try {
                $this->controller()->runAction($action, $params);
                self::fail("The $action action answered a front-end request.");
            } catch (BadRequestHttpException) {
                // As it should.
            }
        }

        // Nothing opts an action out of authentication; see IssueCenterTest for why that is what
        // can be asserted.
        $allowAnonymous = (new \ReflectionProperty(Controller::class, 'allowAnonymous'))->getValue($this->controller());
        self::assertSame(Controller::ALLOW_ANONYMOUS_NEVER, $allowAnonymous);
    }

    public function testAnErrorThatDoesNotExistIsNotFound(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->runAction('detail', ['groupId' => 999999999]);
    }

    public function testTheListShowsWhatHappenedToEveryReaderAndWhatItSaidOnlyToThoseWhoMaySeeIt(): void
    {
        $group = $this->seedGroup(withIssue: true);
        $issue = IssueRecord::findOne(['environment' => $this->environment]);
        self::assertInstanceOf(IssueRecord::class, $issue);

        // A reader of issues sees the kind of error, how often and when, the checks and the issue.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->request('GET');
        $list = $this->render('index', 'web-doctor/_errors/_list', [], ['environment' => $this->environment]);
        $detail = $this->render('detail', 'web-doctor/_errors/_group', ['groupId' => $group->id]);

        foreach ([$list, $detail] as $html) {
            self::assertStringContainsString('RuntimeException', $html);
            self::assertStringContainsString('Check tests.connection', $html);
            self::assertStringContainsString((string)$issue->title, $html);
            self::assertStringContainsString('web-doctor/issues/' . $issue->id, $html);
            // What it said and where it was thrown are the internals "View evidence" guards.
            self::assertStringNotContainsString('refused the connection to', $html);
            self::assertStringNotContainsString('ErrorIntelligenceTest.php', $html);
            self::assertStringContainsString('“View evidence” permission', $html);
        }

        // With it, the grouped message, the latest occurrence, the origin and the trace.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE]);
        $this->request('GET');
        $list = $this->render('index', 'web-doctor/_errors/_list', [], ['environment' => $this->environment]);
        $detail = $this->render('detail', 'web-doctor/_errors/_group', ['groupId' => $group->id]);

        self::assertStringContainsString('refused the connection to {ip} after {n} ms', $list);
        self::assertStringContainsString('refused the connection to {ip} after {n} ms', $detail);
        self::assertStringContainsString('ErrorIntelligenceTest.php', $detail);
        self::assertStringContainsString('Grouped as', $detail);

        // Withheld values are marked, never printed as the raw marker or the value itself.
        foreach ([$list, $detail] as $html) {
            self::assertStringNotContainsString(Redaction::REDACTED, $html);
            self::assertStringNotContainsString('hunter2', $html);
            self::assertStringContainsString('wd-mark--redacted', $html);
        }
    }

    public function testAMalformedOrStaleRequestShowsTheListRatherThanFailing(): void
    {
        $this->seedGroup();
        $this->signIn(admin: true);
        $this->request('GET');

        // A page past the end, a page that is not a number, and an environment nothing was
        // recorded in: the list, on a page that exists, with no filter the database never knew.
        foreach ([['page' => '9999'], ['page' => 'abc'], ['page' => '-3'], ['environment' => "x' OR 1=1 --"]] as $query) {
            $html = $this->render('index', 'web-doctor/_errors/_list', [], $query);

            self::assertStringContainsString('Page 1 of', $html);
            self::assertStringContainsString('RuntimeException', $html);
        }

        // A path segment that is not an ID never reaches the controller: the route only matches
        // digits. An ID that names nothing is not found.
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->runAction('detail', ['groupId' => 0]);
    }

    public function testLongNamesMessagesAndCountsRenderWhole(): void
    {
        $long = str_repeat('VeryLongSegment', 12);
        $this->check('tests.long', static fn() => throw new RuntimeException('Failed in ' . str_repeat('/deeply/nested/directory', 40) . '/file.php'));
        $this->errors->record($this->runChecks());
        ErrorGroupRecord::updateAll(['occurrences' => 1234567, 'exceptionClass' => "App\\$long\\Exception"], ['environment' => $this->environment]);
        $group = $this->onlyGroup();

        $this->signIn(admin: true);
        $this->request('GET');
        $list = $this->render('index', 'web-doctor/_errors/_list', [], ['environment' => $this->environment]);
        $detail = $this->render('detail', 'web-doctor/_errors/_group', ['groupId' => $group->id]);

        self::assertStringContainsString('1234567', $list);
        self::assertStringContainsString($long, $detail);
        self::assertStringContainsString('wd-fingerprint', $detail);
    }

    public function testTheIssuePageShowsTheErrorsBehindItsCheck(): void
    {
        $group = $this->seedGroup(withIssue: true);
        $issue = IssueRecord::findOne(['environment' => $this->environment]);
        self::assertInstanceOf(IssueRecord::class, $issue);

        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => (int)$issue->id], [], new RecordingIssuesController('issues', $this->plugin));

        self::assertStringContainsString('id="errors"', $html);
        self::assertStringContainsString('web-doctor/errors/' . $group->id, $html);
        self::assertStringContainsString('This issue', $html);
    }

    // Helpers ----------------------------------------------------------------

    /**
     * Registers a check whose outcome the test states.
     *
     * @param \Closure(TestDiagnostic, DiagnosticContext): (DiagnosticResult|null) $handler
     */
    private function check(string $id, \Closure $handler): TestDiagnostic
    {
        $diagnostic = new TestDiagnostic();
        $diagnostic->diagnosticId = $id;
        $diagnostic->diagnosticName = "Check $id";
        $diagnostic->diagnosticCategory = DiagnosticCategory::DATABASE;
        $diagnostic->handler = $handler;

        $this->registry->register($diagnostic);

        return $diagnostic;
    }

    /**
     * A check that breaks, the way a check that cannot save an element would.
     */
    private function breaking(string $id, int $elementId): TestDiagnostic
    {
        return $this->check($id, self::throwsFor($elementId));
    }

    /**
     * Throws from one place whatever the element, so every occurrence has the same origin and
     * the same path — only the values in the message differ.
     */
    private static function throwsFor(int $elementId): \Closure
    {
        return static fn() => throw new RuntimeException(sprintf(
            'Element %d could not be saved at %s.',
            $elementId,
            (new DateTimeImmutable())->modify("+$elementId seconds")->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * A refused connection, as a database driver words it. Built in one place so every check that
     * reports it reports the same exception, as they would against a database that is down.
     */
    private static function refusal(): RuntimeException
    {
        return new RuntimeException('SQLSTATE[HY000] [2002] The server refused the connection to 10.0.0.5 after 3001 ms password=hunter2');
    }

    /**
     * A check that caught one exception per stage, all thrown from one place, and reports them
     * all as why it could not tell.
     *
     * @param list<string> $stages
     */
    private static function caughtAll(array $stages): \Closure
    {
        return static fn(TestDiagnostic $d) => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: DiagnosticStatus::UNKNOWN,
            summary: 'Several stages failed.',
            evidence: array_map(
                static fn(string $stage): Evidence => Evidence::fromThrowable(new RuntimeException("Stage $stage failed."), $d->id()),
                $stages,
            ),
        );
    }

    /**
     * This test's grouped messages, most recently seen first and then newest first.
     *
     * @return list<string>
     */
    private function messagesInOrder(): array
    {
        return array_map(static fn(ErrorGroup $g): string => $g->normalizedMessage, $this->storedGroups());
    }

    /**
     * The SQL run while the callback runs, read from Yii's own query profiling.
     *
     * @return list<string>
     */
    private function statementsDuring(\Closure $callback): array
    {
        $db = Craft::$app->getDb();
        $originalLogger = \Yii::getLogger();
        $originalProfiling = $db->enableProfiling;
        $logger = new \yii\log\Logger();
        $logger->flushInterval = PHP_INT_MAX;

        \Yii::setLogger($logger);
        $db->enableProfiling = true;

        try {
            $callback();
        } finally {
            \Yii::setLogger($originalLogger);
            $db->enableProfiling = $originalProfiling;
        }

        return array_values(array_map(
            static fn(array $timing): string => (string)$timing['info'],
            $logger->getProfiling(['yii\db\Command::query', 'yii\db\Command::execute']),
        ));
    }

    /** @return list<string> */
    private static function secrets(): array
    {
        return ['hunter2', 's3cr3t-pass', 'bearer-value-5521', 'escaped-json-value', 'plain-json-value', 'php-array-value', 'query-value-8812', 'url-pass-3310', 'AKIA' . 'IOSFODNN7EXAMPLE', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'];
    }

    private static function leakyMessage(): string
    {
        return 'Connect failed password=hunter2 dsn=mysql://root:s3cr3t-pass@db:3306/craft'
            . ' auth: eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'
            . ' Authorization: Bearer bearer-value-5521'
            . ' body {\"api_key\":\"escaped-json-value\"} {"client_secret": "plain-json-value"}'
            . " array('smtpPassword' => 'php-array-value')"
            . ' GET https://svc.example.com/x?access_token=query-value-8812'
            . ' https://mailer:url-pass-3310@smtp.example.com'
            . ' key AKIA' . 'IOSFODNN7EXAMPLE';
    }

    private function emptyRegistry(): Diagnostics
    {
        return new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };
    }

    /**
     * A check that catches an exception and reports it as the reason it could not tell.
     */
    private static function caught(\Throwable $exception): \Closure
    {
        return static fn(TestDiagnostic $d) => new DiagnosticResult(
            diagnosticId: $d->id(),
            name: $d->name(),
            category: $d->category(),
            status: DiagnosticStatus::UNKNOWN,
            summary: 'The check could not tell.',
            evidence: [Evidence::fromThrowable($exception, $d->id())],
        );
    }

    /**
     * Runs every registered check under this test's environment, as of the given moment.
     */
    private function runChecks(?int $siteId = null, ?string $environment = null, ?DateTimeImmutable $at = null): DiagnosticRun
    {
        $at ??= new DateTimeImmutable();
        $context = new DiagnosticContext(siteId: $siteId, environment: $environment ?? $this->environment, startedAt: $at);
        $run = $this->engine->runAll($context);

        // Restated as of the moment asked for, so that when it was seen is the test's to decide.
        return new DiagnosticRun(context: $context, results: $run->results(), startedAt: $at, finishedAt: $at, durationMs: 1.0);
    }

    /**
     * A group behind an issue, with a withheld value in its message.
     */
    private function seedGroup(bool $withIssue = false): ErrorGroup
    {
        $refused = self::refusal();

        $this->check('tests.connection', $withIssue
            ? static fn(TestDiagnostic $d) => new DiagnosticResult(
                diagnosticId: $d->id(),
                name: $d->name(),
                category: $d->category(),
                status: DiagnosticStatus::FAIL,
                summary: 'The database refused the connection.',
                evidence: [Evidence::fromThrowable($refused, $d->id())],
            )
            : static fn() => throw $refused);

        $run = $this->runChecks();

        if ($withIssue) {
            $this->issues->reconcile($run);
        }

        $this->errors->record($run);

        return $this->onlyGroup();
    }

    /**
     * This test's groups, most recently seen first.
     *
     * @return list<ErrorGroup>
     */
    private function storedGroups(): array
    {
        return $this->errors->find(1, $this->environment)->items;
    }

    private function onlyGroup(): ErrorGroup
    {
        $groups = $this->storedGroups();

        self::assertCount(1, $groups, 'Expected exactly one error group in this environment.');

        return $groups[0];
    }

    private function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * A request shaped the way Craft would see one; see IssueCenterTest for why the control
     * panel flag is stated outright.
     */
    private function request(string $method, bool $cp = true): WebRequest
    {
        $base = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        $parts = parse_url($base) ?: [];
        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $trigger = Craft::$app->getConfig()->getGeneral()->cpTrigger ?? 'admin';

        $_SERVER['HTTP_HOST'] = $parts['host'] ?? 'localhost';
        $_SERVER['SERVER_NAME'] = $parts['host'] ?? 'localhost';
        $_SERVER['SERVER_PORT'] = $secure ? '443' : '80';
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor/errors" : '/actions/web-doctor/errors/index';
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        if ($secure) {
            $_SERVER['HTTPS'] = 'on';
        } else {
            unset($_SERVER['HTTPS']);
        }

        $this->originalRequest ??= Craft::$app->getRequest();
        $this->originalResponse ??= Craft::$app->getResponse();

        if (!Craft::$app->has('formattingLocale', true)) {
            Craft::$app->set('formattingLocale', Craft::$app->getI18n()->getLocaleById(Craft::$app->language));
        }

        $request = new WebRequest();
        $request->setIsCpRequest($cp);
        $request->cookieValidationKey = Craft::$app->getConfig()->getGeneral()->securityKey;

        Craft::$app->set('request', $request);
        Craft::$app->set('response', new WebResponse());

        return $request;
    }

    /**
     * @param string[] $permissions
     */
    private function signIn(bool $admin, array $permissions = []): User
    {
        $user = new TestUser();
        $user->id = 1;
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;

        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    private function controller(): ErrorsController
    {
        return new ErrorsController('errors', $this->plugin);
    }

    /**
     * Renders what a controller actually produced, through the real template, leaving out only
     * Craft's own control panel shell, which asks for a session a console run has none of.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    private function render(string $action, string $template, array $params = [], array $query = [], ?Controller $controller = null): string
    {
        Craft::$app->getRequest()->setQueryParams($query);

        $response = ($controller ?? $this->controller())->runAction($action, $params);

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return Craft::$app->getView()->renderTemplate($template, $behavior->variables, View::TEMPLATE_MODE_CP);
    }
}
