<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\elements\User;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\IssueEventType;
use Tahadudhiya\WebDoctor\enums\IssueResolution;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Savepoint;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\IssueFilter;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\services\EvidenceStore;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingIssuesController;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\db\IntegrityException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

/**
 * The Issue Center against a real database: what becomes an issue, what counts as the same issue,
 * when one resolves, what evidence is kept behind it, who may reach any of it, and what the pages
 * actually render.
 *
 * Every test runs under an environment name of its own, and only that environment's rows are
 * removed afterwards. Nothing here truncates the tables: these tests run inside somebody's
 * development installation, and a test suite that clears a table it did not fill is a test suite
 * that eventually deletes something that mattered.
 *
 * The control panel tests go through `runAction()` rather than calling an action method, so the
 * whole chain a real request meets — the control panel check, CSRF validation, the permission —
 * is what is being tested. Calling the action directly would test the body of a method and prove
 * nothing about who is allowed to reach it.
 */
class IssueCenterTest extends TestCase
{
    /** @var string The permission Craft itself demands of anyone reaching the control panel. */
    private const ACCESS_CP = 'accessCp';

    private Issues $issues;
    private string $environment;
    private WebDoctor $plugin;
    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Craft::$app->getDb()->tableExists(IssueRecord::TABLE)) {
            self::fail(sprintf(
                'The table %s does not exist. Install Web Doctor in this project first: `php craft plugin/install web-doctor`.',
                IssueRecord::TABLE,
            ));
        }

        $this->issues = new Issues();
        $this->environment = 'tests-' . bin2hex(random_bytes(5));
        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // Only the rows these tests made. The pattern is passed through unescaped so the `%` is
        // a wildcard rather than a literal, which is the difference between removing this run's
        // rows and removing none of them.
        // Events and evidence go with their issues, through the foreign keys that exist for exactly this.
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

    // What becomes an issue --------------------------------------------------

    public function testAFailureBecomesAnIssueCarryingWhatTheCheckReported(): void
    {
        $run = $this->diagnosticRun([
            $this->finding(
                status: DiagnosticStatus::FAIL,
                summary: 'The database is unreachable.',
                severity: Severity::CRITICAL,
                description: 'Connecting threw.',
                recommendation: 'Check the credentials.',
                affectedComponent: 'connection',
            ),
        ]);

        $outcome = $this->issues->reconcile($run);
        $issue = $this->only();

        self::assertSame(1, $outcome->opened);
        self::assertSame('The database is unreachable.', $issue->title);
        self::assertSame('Connecting threw.', $issue->description);
        self::assertSame('Check the credentials.', $issue->recommendation);
        self::assertSame(Severity::CRITICAL, $issue->severity);
        self::assertSame(DiagnosticStatus::FAIL, $issue->resultStatus);
        self::assertSame(IssueStatus::NEW, $issue->status);
        self::assertSame(IssueResolution::NONE, $issue->resolution);
        self::assertSame('connection', $issue->affectedComponent);
        self::assertSame($this->environment, $issue->environment);
        self::assertSame(1, $issue->occurrences);
        self::assertFalse($issue->isRecurring());
        self::assertTrue($issue->isOpen());
        self::assertSame($run->id(), $issue->firstRunId);
        self::assertSame($run->id(), $issue->latestRunId);
        self::assertSame(DiagnosticCategory::DATABASE, $issue->category);
    }

    /**
     * A check that errored or could not tell counts against the health score, because an
     * unanswered question is not a clean bill of health — but it asserts nothing about the site,
     * so it must not appear in a list of the site's problems.
     */
    public function testAWarningOrAFailureBecomesAnIssueAndNothingElseDoes(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::WARNING)]));

        self::assertSame(DiagnosticStatus::WARNING, $this->only()->resultStatus);

        $silent = [DiagnosticStatus::PASS, DiagnosticStatus::INFO, DiagnosticStatus::SKIPPED, DiagnosticStatus::ERROR, DiagnosticStatus::UNKNOWN];

        foreach ($silent as $i => $status) {
            $this->issues->reconcile($this->diagnosticRun([$this->finding(diagnosticId: "tests.check$i", status: $status)]));
        }

        // Still the one warning: none of the five raised anything of its own.
        self::assertCount(1, $this->all());
    }

    // Deduplication ----------------------------------------------------------

    public function testTheSameProblemFoundAgainUpdatesTheSameIssue(): void
    {
        $first = $this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], at: new DateTimeImmutable('2026-01-01 10:00:00'));
        $second = $this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], at: new DateTimeImmutable('2026-01-02 10:00:00'));

        $this->issues->reconcile($first);
        $outcome = $this->issues->reconcile($second);

        $issue = $this->only();

        self::assertSame(0, $outcome->opened);
        self::assertSame(1, $outcome->updated);
        self::assertSame(2, $issue->occurrences);
        self::assertTrue($issue->isRecurring());
        self::assertSame($first->id(), $issue->firstRunId);
        self::assertSame($second->id(), $issue->latestRunId);
        self::assertLessThan($issue->lastDetected, $issue->firstDetected);
    }

    public function testAnUnchangedSightingAddsNoHistory(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));

        $issue = $this->only();

        // Three sightings, counted exactly — and one history entry, because an issue found again
        // unchanged by every run for a year must not become a thousand identical rows.
        self::assertSame(3, $issue->occurrences);
        self::assertSame([IssueEventType::DETECTED], $this->eventTypes($issue));
    }

    public function testDifferentProblemsFromTheSameCheckAreDifferentIssues(): void
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(status: DiagnosticStatus::FAIL, affectedComponent: 'gd'),
            $this->finding(status: DiagnosticStatus::FAIL, affectedComponent: 'fileinfo'),
        ]));

        self::assertCount(2, $this->all());
    }

    /**
     * A problem that gets worse, or better, is the same problem. The wording, the severity and the
     * result status are the current reading of an issue, not its identity: a check reporting
     * "3 jobs have failed" and then "17", or warning and then failing, is reporting one problem,
     * and raising a fresh issue each time would split its history in two.
     */
    public function testAProblemThatChangesSeverityOrStatusStaysOneIssue(): void
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(status: DiagnosticStatus::WARNING, summary: 'Jobs are backing up.', severity: Severity::MEDIUM),
        ]));
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(status: DiagnosticStatus::FAIL, summary: 'The queue has stopped.', severity: Severity::CRITICAL),
        ]));

        $issue = $this->only();

        self::assertSame(2, $issue->occurrences);
        self::assertSame(DiagnosticStatus::FAIL, $issue->resultStatus);
        self::assertSame(Severity::CRITICAL, $issue->severity);
        self::assertSame('The queue has stopped.', $issue->title);
        self::assertSame(IssueStatus::NEW, $issue->status);
        self::assertContains(IssueEventType::CHANGED, $this->eventTypes($issue));
    }

    public function testFindingsFromDifferentSitesNeverMerge(): void
    {
        $siteId = $this->siteId();
        self::assertNotNull($siteId, 'This installation has no site to scope a run to.');

        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], siteId: $siteId));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], siteId: null));

        self::assertCount(2, $this->all());
    }

    // Resolution -------------------------------------------------------------

    public function testACheckThatLaterPassesResolvesItsIssue(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->transition($this->only()->id, IssueStatus::INVESTIGATING, 'Waiting on the host.');
        $clean = $this->diagnosticRun([$this->finding(status: DiagnosticStatus::PASS)]);
        $outcome = $this->issues->reconcile($clean);

        $issue = $this->only();

        self::assertSame(1, $outcome->resolved);
        self::assertSame(IssueStatus::RESOLVED, $issue->status);
        self::assertSame(IssueResolution::OBSERVED_CLEAR, $issue->resolution);
        self::assertSame($clean->id(), $issue->resolvedByRunId);
        self::assertNotNull($issue->resolvedAt);
        self::assertFalse($issue->isOpen());
        self::assertContains(IssueEventType::RESOLVED, $this->eventTypes($issue));
        // The reason given for investigating does not read as the reason it is resolved.
        self::assertNull($issue->statusNote);
    }

    /**
     * The one rule that makes resolution worth anything: it takes an answer, not the absence of
     * one. A check that broke, was skipped or could not tell has established nothing.
     */
    public function testACheckThatReachesNoConclusionResolvesNothing(): void
    {
        foreach ([DiagnosticStatus::ERROR, DiagnosticStatus::SKIPPED, DiagnosticStatus::UNKNOWN] as $status) {
            $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
            $outcome = $this->issues->reconcile($this->diagnosticRun([$this->finding(status: $status)]));

            self::assertSame(0, $outcome->resolved, $status->value);
            self::assertSame(IssueStatus::NEW, $this->only()->status, $status->value);

            IssueRecord::deleteAll(['environment' => $this->environment]);
        }
    }

    public function testACheckThatWasNotRunResolvesNothing(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(diagnosticId: 'tests.absent', status: DiagnosticStatus::FAIL)]));
        $outcome = $this->issues->reconcile($this->diagnosticRun([$this->finding(diagnosticId: 'tests.other', status: DiagnosticStatus::PASS)]));

        self::assertSame(0, $outcome->resolved);
        self::assertSame(IssueStatus::NEW, $this->issueFor('tests.absent')->status);
    }

    public function testACleanRunInOneEnvironmentLeavesAnotherEnvironmentAlone(): void
    {
        $other = $this->environment;
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));

        $this->environment = 'tests-' . bin2hex(random_bytes(5));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::PASS)]));

        $record = IssueRecord::findOne(['environment' => $other]);

        self::assertNotNull($record);
        self::assertSame(IssueStatus::NEW->value, $record->status);
    }

    /**
     * A person who ignored an issue made a decision. A later passing check is not that person,
     * and closing their decision out from under them would lose the fact that it was made.
     */
    public function testACleanRunDoesNotOverturnSomebodysDecision(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->transition($this->only()->id, IssueStatus::IGNORED, 'Known, accepted for now.');

        $outcome = $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::PASS)]));

        self::assertSame(0, $outcome->resolved);
        self::assertSame(IssueStatus::IGNORED, $this->only()->status);
    }

    public function testADismissedIssueStillCountsTheTimesItIsSeen(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->transition($this->only()->id, IssueStatus::WONT_FIX, 'Not our server.');
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));

        $issue = $this->only();

        self::assertSame(IssueStatus::WONT_FIX, $issue->status);
        self::assertSame(2, $issue->occurrences);
    }

    public function testAResolvedIssueFoundAgainComesBackAndKeepsItsHistory(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::PASS)]));
        $outcome = $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));

        $issue = $this->only();

        self::assertSame(1, $outcome->recurred);
        self::assertSame(0, $outcome->opened);
        self::assertSame(IssueStatus::NEW, $issue->status);
        self::assertSame(IssueResolution::NONE, $issue->resolution);
        self::assertNull($issue->resolvedAt);
        self::assertNull($issue->resolvedByRunId);
        self::assertSame(2, $issue->occurrences);
        self::assertContains(IssueEventType::RECURRED, $this->eventTypes($issue));
    }


    // Concurrency and atomicity ----------------------------------------------

    /**
     * Two requests reconciling the same finding at once both look, both miss, and both insert.
     * The second insert must join the issue the first created rather than fail the run.
     *
     * The winner commits on a second connection after the loser has already read inside its own
     * transaction, as a real second request would. That ordering matters: under REPEATABLE READ
     * the loser's snapshot was fixed by that first read, so an ordinary re-read after the conflict
     * cannot see the winner's row at all.
     */
    public function testARequestThatLosesTheRaceToCreateAnIssueJoinsTheWinner(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $winner = $this->racedRow(IssueRecord::findOne(['environment' => $this->environment]));

        $loser = new class() extends Issues {
            /** @var callable|null */
            public $commitWinner;

            protected function findByFingerprint(string $fingerprint): ?IssueRecord
            {
                $found = parent::findByFingerprint($fingerprint);

                if ($this->commitWinner !== null) {
                    ($this->commitWinner)();
                    $this->commitWinner = null;
                }

                return $found;
            }
        };
        $loser->commitWinner = $winner;

        $outcome = $loser->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));

        self::assertSame(0, $outcome->opened);
        self::assertSame(1, $outcome->updated);

        // One row, and the detection that lost the race still counted — against the winner, with
        // no "detected" of its own. (The winner's own history went with the row it was put back as.)
        $issue = $this->only();

        self::assertSame(2, $issue->occurrences);
        self::assertSame([], $this->eventTypes($issue));
    }

    public function testTheDatabaseItselfRefusesASecondIssueForOneFingerprint(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));

        $row = IssueRecord::findOne(['environment' => $this->environment]);

        self::assertInstanceOf(IssueRecord::class, $row);

        $duplicate = $row->getAttributes(null, ['id', 'uid']);

        // The application avoids this collision; the index is what makes that avoidance safe
        // rather than merely likely — and it is the one refusal read as a lost race.
        try {
            Craft::$app->getDb()->createCommand()->insert(IssueRecord::TABLE, $duplicate)->execute();
            self::fail('A second row for one fingerprint was accepted.');
        } catch (IntegrityException $e) {
            self::assertTrue(Savepoint::isUniqueViolation($e));
        }
    }

    /**
     * Any other refusal is the database saying something is wrong, and is reported as itself: read
     * as a lost race, it would send the reconciliation looking for a winner that does not exist.
     */
    public function testAFindingTheDatabaseRefusesForAnotherReasonFailsWithThatReason(): void
    {
        // A site deleted outright between the run and its reconciliation.
        $run = $this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], siteId: 2147480000);

        try {
            $this->issues->reconcile($run);
            self::fail('A finding for a site that does not exist was stored.');
        } catch (IntegrityException $e) {
            self::assertFalse(Savepoint::isUniqueViolation($e));
        }

        self::assertSame([], $this->all());
    }

    /**
     * A status change and the record of it are one act. An issue showing a status with no history
     * behind it would be a decision nobody can account for.
     */
    public function testAStatusChangeThatCannotBeRecordedIsNotMade(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $id = $this->only()->id;

        $broken = new class() extends Issues {
            protected function logEvent(
                IssueRecord $record,
                IssueEventType $type,
                ?IssueStatus $from = null,
                ?IssueStatus $to = null,
                ?Severity $severity = null,
                ?string $note = null,
                ?string $runId = null,
                ?int $userId = null,
            ): void {
                throw new RuntimeException('The history could not be written.');
            }
        };

        try {
            $broken->transition($id, IssueStatus::INVESTIGATING, 'Looking into it.');
            self::fail('A status change survived its history failing to write.');
        } catch (RuntimeException) {
            // Expected.
        }

        $issue = $this->issues->get($id);

        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame(IssueStatus::NEW, $issue->status);
        self::assertNull($issue->statusNote);
        self::assertNull($issue->statusChangedAt);
        self::assertSame([IssueEventType::DETECTED], $this->eventTypes($issue));
    }

    /**
     * Deleting a site drops the reference and keeps the issue: most findings are about the
     * installation and merely stamped with whichever site was in view, so cascading would erase a
     * database problem because an unrelated site was removed.
     *
     * What the foreign key does on delete is reproduced by nulling the column, because deleting a
     * real site from the installation these tests run in is not something a test may do. That the
     * key is declared `SET NULL` is asserted against the migration's own source.
     */
    public function testAnIssueOutlivesTheSiteItWasFoundOn(): void
    {
        $siteId = $this->siteId();

        self::assertNotNull($siteId, 'This installation has no site to scope a run to.');

        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], siteId: $siteId));
        $before = $this->only();

        self::assertSame($siteId, $before->siteId);
        self::assertNotNull($before->siteName);

        IssueRecord::updateAll(['siteId' => null], ['id' => $before->id]);

        $after = $this->issues->get($before->id);

        self::assertInstanceOf(Issue::class, $after);
        self::assertNull($after->siteId);
        // Still says which site it was, so the finding does not start reading as one made about
        // the whole installation.
        self::assertSame($before->siteName, $after->siteName);
        self::assertSame($before->occurrences, $after->occurrences);
        self::assertSame($before->firstDetected->format(DATE_ATOM), $after->firstDetected->format(DATE_ATOM));
        self::assertNotSame([], $this->issues->events($after->id));
    }

    public function testTheStatusCountsBesideTheListAreCountedUnderTheSameFilter(): void
    {
        $this->seedVariety();

        $other = $this->environment;
        $this->environment = 'tests-' . bin2hex(random_bytes(5));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));

        // Counted for this environment only. A total over every environment, shown against a
        // list narrowed to one, would disagree with the list under it.
        $counts = $this->issues->countsByStatus(new IssueFilter(environment: $other));

        self::assertSame(3, $counts[IssueStatus::NEW->value]);

        // The status facet itself is left out, so ticking one status does not zero the others.
        $narrowed = $this->issues->countsByStatus(new IssueFilter(
            environment: $other,
            statuses: [IssueStatus::RESOLVED],
        ));

        self::assertSame(3, $narrowed[IssueStatus::NEW->value]);
    }

    // Lifecycle --------------------------------------------------------------

    public function testAPersonCanMoveAnIssueThroughTheStatusesThatAreTheirsToSet(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $id = $this->only()->id;

        foreach ([IssueStatus::CONFIRMED, IssueStatus::INVESTIGATING] as $status) {
            self::assertSame($status, $this->issues->transition($id, $status)->status);
        }
    }

    /**
     * The claim the whole Issue Center rests on. "Resolved" describes something Web Doctor
     * observed and "repairing" belongs to whatever repairs; if a request could set either, the
     * first would come to mean "somebody clicked resolved".
     */
    public function testNobodyCanDeclareAnIssueResolvedOrUnderRepair(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $id = $this->only()->id;

        foreach ([IssueStatus::RESOLVED, IssueStatus::REPAIRING] as $refused) {
            try {
                $this->issues->transition($id, $refused);
                self::fail("An issue was moved to {$refused->value} by hand.");
            } catch (InvalidArgumentException) {
                // A refusal has to leave the issue exactly as it was, history included.
                self::assertSame(IssueStatus::NEW, $this->only()->status);
                self::assertSame([IssueEventType::DETECTED], $this->eventTypes($this->only()));
            }
        }
    }

    public function testDismissingAnIssueNeedsAReason(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $id = $this->only()->id;

        foreach ([null, '', '   '] as $note) {
            try {
                $this->issues->transition($id, IssueStatus::IGNORED, $note);
                self::fail('A dismissal with no reason was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(IssueStatus::NEW, $this->only()->status);
            }
        }
    }

    public function testADismissalKeepsTheReasonItWasGiven(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $issue = $this->issues->transition($this->only()->id, IssueStatus::IGNORED, '  Accepted until the next release.  ', userId: null);

        self::assertSame(IssueStatus::IGNORED, $issue->status);
        self::assertSame('Accepted until the next release.', $issue->statusNote);
        self::assertNotNull($issue->statusChangedAt);
    }

    public function testMovingAnIssueOutOfResolvedWithdrawsTheClaimThatItWas(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::PASS)]));
        self::assertSame(IssueResolution::OBSERVED_CLEAR, $this->only()->resolution);

        $issue = $this->issues->transition($this->only()->id, IssueStatus::INVESTIGATING);

        self::assertSame(IssueResolution::NONE, $issue->resolution);
        self::assertNull($issue->resolvedAt);
        self::assertNull($issue->resolvedByRunId);
    }

    /**
     * The same status again is nothing, unless it comes with a different reason: that is somebody
     * restating their decision, and it is kept and recorded like any other.
     */
    public function testSettingTheStatusItAlreadyHasChangesNothingButANewReason(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $id = $this->only()->id;

        $this->issues->transition($id, IssueStatus::NEW);
        self::assertSame([IssueEventType::DETECTED], $this->eventTypes($this->only()));

        $this->issues->transition($id, IssueStatus::IGNORED, 'Accepted for now.');
        $this->issues->transition($id, IssueStatus::IGNORED, 'Accepted for now.');
        self::assertCount(2, $this->eventTypes($this->only()));

        $issue = $this->issues->transition($id, IssueStatus::IGNORED, 'Accepted until the host upgrades.');

        self::assertSame('Accepted until the host upgrades.', $issue->statusNote);
        self::assertSame('Accepted until the host upgrades.', $this->issues->events($id)[0]->note);
        self::assertCount(3, $this->eventTypes($this->only()));
    }

    public function testAStatusChangeIsRecordedWithWhatItMovedBetween(): void
    {
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)]));
        $this->issues->transition($this->only()->id, IssueStatus::INVESTIGATING, 'Looking into it.');

        $events = $this->issues->events($this->only()->id);
        $latest = $events[0];

        self::assertSame(IssueEventType::STATUS_CHANGED, $latest->type);
        self::assertSame(IssueStatus::NEW, $latest->fromStatus);
        self::assertSame(IssueStatus::INVESTIGATING, $latest->toStatus);
        self::assertSame('Looking into it.', $latest->note);
    }

    public function testChangingAnIssueThatDoesNotExistIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->issues->transition(0, IssueStatus::CONFIRMED);
    }

    // The evidence behind an issue ------------------------------------------

    public function testTheEvidenceBehindAFindingIsKeptWithWhereItWasGathered(): void
    {
        $siteId = $this->siteId();
        $observed = new DateTimeImmutable('2026-02-01T09:30:00+00:00');

        $run = $this->diagnosticRun([
            $this->finding(status: DiagnosticStatus::FAIL, evidence: [
                new Evidence(
                    type: EvidenceType::QUEUE_JOB,
                    label: 'Search index update',
                    source: 'tests.check',
                    data: ['occurrences' => 3, 'error' => 'Timed out'],
                    observedAt: $observed,
                    metadata: ['sampleLimit' => 10],
                    reference: 'queue job #42',
                    confidence: Confidence::HIGH,
                ),
            ]),
        ], siteId: $siteId);

        $this->issues->reconcile($run);
        $issue = $this->only();
        $latest = $this->plugin->getEvidence()->latest($issue->id, $issue->latestRunId);

        self::assertCount(1, $latest);

        $stored = $latest[0];
        $evidence = $stored->evidence;

        self::assertSame($issue->id, $stored->issueId);
        self::assertSame(EvidenceType::QUEUE_JOB, $evidence->type);
        self::assertSame('Search index update', $evidence->label);
        self::assertSame(['occurrences' => 3, 'error' => 'Timed out'], $evidence->data);
        self::assertSame(['sampleLimit' => 10], $evidence->metadata);
        self::assertSame('queue job #42', $evidence->reference);
        self::assertSame(Confidence::HIGH, $evidence->confidence);
        self::assertSame($observed->format(DATE_ATOM), $evidence->observedAt?->format(DATE_ATOM));

        // Attributed to the run that gathered it, not borrowed from the issue.
        self::assertSame('tests.check', $evidence->diagnosticId);
        self::assertSame($run->id(), $evidence->runId);
        self::assertSame($this->environment, $evidence->environment);
        self::assertSame($siteId, $evidence->siteId);
        self::assertSame(1, $stored->occurrences);

        // The issue itself no longer carries a second copy of it.
        $snapshot = (string)IssueRecord::findOne(['environment' => $this->environment])?->latestResult;

        self::assertStringNotContainsString('Search index update', $snapshot);
    }

    public function testEvidenceSeenAgainIsCountedRatherThanStoredTwice(): void
    {
        $same = fn(string $observed): Evidence => new Evidence(
            EvidenceType::QUEUE,
            'Failed jobs',
            'tests.check',
            ['failed' => 3],
            observedAt: new DateTimeImmutable($observed),
        );

        // The same fact twice in one result, then again in a second run with a later observed
        // time: one fact, seen twice.
        $first = $this->diagnosticRun([$this->finding(evidence: [$same('2026-01-01 00:00:00'), $same('2026-01-01 00:00:00')])], at: new DateTimeImmutable('-2 hours'));
        $second = $this->diagnosticRun([$this->finding(evidence: [$same('2026-01-02 00:00:00')])], at: new DateTimeImmutable('-1 hour'));

        $this->issues->reconcile($first);
        $issue = $this->only();
        $before = $this->plugin->getEvidence()->latest($issue->id, $issue->latestRunId)[0];

        $this->issues->reconcile($second);
        $issue = $this->only();
        $store = $this->plugin->getEvidence();
        $after = $store->latest($issue->id, $issue->latestRunId)[0];

        self::assertSame(1, $this->evidenceRows($issue));
        self::assertSame(2, $after->occurrences);
        self::assertSame($before->id, $after->id);
        self::assertSame($before->digest, $after->digest);
        self::assertSame($before->firstSeen->format(DATE_ATOM), $after->firstSeen->format(DATE_ATOM));
        self::assertGreaterThan($before->lastSeen, $after->lastSeen);
        self::assertSame($first->id(), $before->lastRunId);
        self::assertSame($second->id(), $after->lastRunId);
        self::assertSame($first->id(), $after->firstRunId);
        // When it was last true moves forward with the sighting that says so.
        self::assertSame('2026-01-02', $after->evidence->observedAt?->format('Y-m-d'));

        // A new reading is a new fact. The old one stops being the latest and is kept as earlier
        // evidence, so the history of what the check saw is not overwritten.
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(evidence: [new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check', ['failed' => 17])]),
        ]));

        $issue = $this->only();
        $latest = $store->latest($issue->id, $issue->latestRunId);
        $earlier = $store->earlier($issue->id, $issue->latestRunId);

        self::assertSame(2, $this->evidenceRows($issue));
        self::assertCount(1, $latest);
        self::assertSame(17, $latest[0]->evidence->get('failed'));
        self::assertSame(1, $earlier->total);
        self::assertSame(3, $earlier->items[0]->evidence->get('failed'));
    }

    /**
     * The documented bound, at its real value: exactly a hundred facts, the hundred-and-first,
     * and more than that, with the one kept alive by being seen again surviving each time.
     */
    public function testAnIssueHoldsAtMostAHundredFactsAndDropsTheLeastRecentlySeen(): void
    {
        $fact = static fn(string $name): Evidence => new Evidence(EvidenceType::QUEUE_JOB, $name, 'tests.check');
        $minute = 0;
        $run = function(array $names) use ($fact, &$minute): DiagnosticRun {
            return $this->diagnosticRun(
                [$this->finding(evidence: array_map($fact, $names))],
                at: new DateTimeImmutable(sprintf('-%d minutes', 60 - $minute++)),
            );
        };

        self::assertSame(100, (new EvidenceStore())->maxPerIssue);

        $hundred = array_map(static fn(int $n): string => "fact $n", range(1, 100));

        $this->issues->reconcile($run($hundred));
        $issue = $this->only();

        self::assertSame(100, $this->evidenceRows($issue));

        // "fact 1" is seen again; "fact 2" is now the least recently seen.
        $this->issues->reconcile($run(['fact 1', 'fact 101']));

        self::assertSame(100, $this->evidenceRows($issue));
        self::assertSame(['fact 101', 'fact 1'], $this->labelsOf($issue, 2));
        self::assertNotContains('fact 2', $this->labelsOf($issue, 100));
        self::assertContains('fact 3', $this->labelsOf($issue, 100));

        // Five more at once: the five least recently seen go, and the survivors are unchanged.
        $this->issues->reconcile($run(['fact 102', 'fact 103', 'fact 104', 'fact 105', 'fact 106']));
        $labels = $this->labelsOf($issue, 100);

        self::assertSame(100, $this->evidenceRows($issue));
        self::assertContains('fact 1', $labels);
        self::assertContains('fact 106', $labels);

        foreach (['fact 3', 'fact 4', 'fact 5', 'fact 6', 'fact 7'] as $gone) {
            self::assertNotContains($gone, $labels);
        }

        self::assertContains('fact 8', $labels);
    }

    public function testTwoRunsInTheSameSecondNeverPruneWhatTheLaterOneSaw(): void
    {
        // The table holds whole seconds, so these tie on lastSeen. The fact the second run saw
        // again has the oldest row ID, and would be the tiebreak's first victim.
        $this->issues->evidence = new EvidenceStore(['maxPerIssue' => 2]);
        $at = new DateTimeImmutable('-5 minutes');
        $fact = static fn(string $name): Evidence => new Evidence(EvidenceType::QUEUE_JOB, $name, 'tests.check');

        $this->issues->reconcile($this->diagnosticRun([$this->finding(evidence: [$fact('a'), $fact('b')])], at: $at));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(evidence: [$fact('a'), $fact('c')])], at: $at));

        $issue = $this->only();
        $labels = $this->labelsOf($issue, 10);
        sort($labels);

        self::assertSame(['a', 'c'], $labels);
    }

    public function testAnIssueHoldsABoundedAmountOfEvidenceAndKeepsTheLatest(): void
    {
        $this->issues->evidence = new EvidenceStore(['maxPerIssue' => 3]);

        for ($i = 1; $i <= 5; $i++) {
            $this->issues->reconcile($this->diagnosticRun([
                $this->finding(evidence: [new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check', ['failed' => $i])]),
            ], at: new DateTimeImmutable(sprintf('-%d minutes', 10 - $i))));
        }

        $issue = $this->only();

        self::assertSame(3, $this->evidenceRows($issue));
        self::assertSame(5, $this->issues->evidence->latest($issue->id, $issue->latestRunId)[0]->evidence->get('failed'));

        // Earlier evidence pages, and a page past the end shows the last one rather than nothing.
        $earlier = $this->issues->evidence->earlier($issue->id, $issue->latestRunId, page: 99);

        self::assertSame(2, $earlier->total);
        self::assertSame(1, $earlier->page);
        self::assertSame([4, 3], array_map(static fn($e): mixed => $e->evidence->get('failed'), $earlier->items));

        // One result reporting more than the bound keeps only as much as the bound allows.
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(evidence: array_map(
                static fn(int $n): Evidence => new Evidence(EvidenceType::QUEUE_JOB, "Job $n", 'tests.check'),
                range(1, 10),
            )),
        ]));

        self::assertSame(3, $this->evidenceRows($this->only()));
    }

    /**
     * Two requests reconciling one issue serialise on the issue's row, but the loser's snapshot was
     * fixed by its first read, before the winner committed. So the winner's fact is committed on
     * another connection just after that read: the loser cannot see it and has to rejoin it.
     */
    public function testARequestThatLosesTheRaceToStoreEvidenceJoinsTheWinner(): void
    {
        $evidence = fn(): Evidence => new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check', ['failed' => 3]);

        $this->issues->reconcile($this->diagnosticRun([$this->finding(evidence: [$evidence()])]));
        $winner = $this->racedRow(EvidenceRecord::findOne(['environment' => $this->environment]));

        $loser = new class() extends Issues {
            /** @var callable|null */
            public $commitWinner;

            protected function findByFingerprint(string $fingerprint): ?IssueRecord
            {
                $found = parent::findByFingerprint($fingerprint);

                if ($this->commitWinner !== null) {
                    ($this->commitWinner)();
                    $this->commitWinner = null;
                }

                return $found;
            }
        };
        $loser->commitWinner = $winner;

        $loser->reconcile($this->diagnosticRun([$this->finding(evidence: [$evidence()])]));

        $issue = $this->only();

        self::assertSame(1, $this->evidenceRows($issue));
        self::assertSame(2, $this->issues->evidence->latest($issue->id, $issue->latestRunId)[0]->occurrences);
    }

    public function testEvidenceIsFiledUnderTheRunItArrivedInWhateverItClaims(): void
    {
        // A run assembled without the engine carries evidence stamped with somebody else's site
        // and environment. It must still land in the context its issue was fingerprinted from.
        $forged = new Evidence(
            type: EvidenceType::QUEUE,
            label: 'Forged',
            source: 'tests.check',
            diagnosticId: 'someone.else',
            runId: 'forged-run',
            environment: 'production',
            siteId: 987654,
        );

        $context = new DiagnosticContext(environment: $this->environment);
        $at = new DateTimeImmutable();
        $run = new DiagnosticRun(
            context: $context,
            results: [$this->finding(evidence: [$forged])],
            startedAt: $at,
            finishedAt: $at,
            durationMs: 1.0,
        );

        $this->issues->reconcile($run);

        $row = EvidenceRecord::findOne(['issueId' => $this->only()->id]);

        self::assertInstanceOf(EvidenceRecord::class, $row);
        self::assertSame($this->environment, $row->environment);
        self::assertNull($row->siteId);
        self::assertSame('tests.check', $row->diagnosticId);
        self::assertSame($run->id(), $row->lastRunId);
    }

    public function testTheSameEvidenceInAnotherSiteOrEnvironmentBelongsToAnotherIssue(): void
    {
        $siteId = $this->siteId();

        self::assertNotNull($siteId);

        $evidence = static fn(): Evidence => new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check', ['failed' => 3]);
        $otherEnvironment = $this->environment . '-b';

        $this->issues->reconcile($this->diagnosticRun([$this->finding(evidence: [$evidence()])]));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(evidence: [$evidence()])], siteId: $siteId));
        $this->issues->reconcile(new DiagnosticRun(
            context: $context = new DiagnosticContext(environment: $otherEnvironment),
            results: [$this->finding(evidence: [$evidence()])->withExecution($context, new DateTimeImmutable(), new DateTimeImmutable(), 1.0)],
            startedAt: new DateTimeImmutable(),
            finishedAt: new DateTimeImmutable(),
            durationMs: 1.0,
        ));

        $rows = $this->evidenceRecords(EvidenceRecord::find()
            ->where(['like', 'environment', $this->environment . '%', false])
            ->orderBy(['id' => SORT_ASC]));

        // Three issues, one fact each, each filed under its own issue's context — never merged.
        self::assertCount(3, $rows);
        self::assertCount(3, array_unique(array_map(static fn(EvidenceRecord $r): int => (int)$r->issueId, $rows)));
        self::assertSame([null, $siteId, null], array_map(static fn(EvidenceRecord $r): ?int => $r->siteId === null ? null : (int)$r->siteId, $rows));
        self::assertSame([$this->environment, $this->environment, $otherEnvironment], array_map(static fn(EvidenceRecord $r): string => $r->environment, $rows));

        foreach ($rows as $row) {
            $issue = IssueRecord::findOne(['id' => $row->issueId]);

            self::assertSame($issue?->environment, $row->environment);
            self::assertSame($issue?->siteId === null ? null : (int)$issue->siteId, $row->siteId === null ? null : (int)$row->siteId);
        }
    }

    public function testEvidenceOutlivesResolutionAndIsStillThereWhenTheIssueComesBack(): void
    {
        $evidence = static fn(): Evidence => new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check', ['failed' => 3]);

        $this->issues->reconcile($this->diagnosticRun([$this->finding(evidence: [$evidence()])], at: new DateTimeImmutable('-3 hours')));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::PASS)], at: new DateTimeImmutable('-2 hours')));

        $resolved = $this->only();

        self::assertSame(IssueStatus::RESOLVED, $resolved->status);
        self::assertSame(1, $this->evidenceRows($resolved));
        // What the finding rested on is still the evidence behind its latest finding.
        self::assertCount(1, $this->plugin->getEvidence()->latest($resolved->id, $resolved->latestRunId));

        $this->issues->reconcile($this->diagnosticRun([$this->finding(evidence: [$evidence()])], at: new DateTimeImmutable('-1 hour')));
        $recurred = $this->only();
        $latest = $this->plugin->getEvidence()->latest($recurred->id, $recurred->latestRunId);

        self::assertSame(IssueStatus::NEW, $recurred->status);
        self::assertSame(1, $this->evidenceRows($recurred));
        self::assertSame(2, $latest[0]->occurrences);
    }

    public function testEvidenceThatCannotBeStoredTakesTheWholeReconciliationWithIt(): void
    {
        // Evidence is stored in the issue's transaction, so a failure leaves no issue pointing at
        // evidence that was never written, and no half-reconciled run.
        $this->issues->evidence = new class() extends EvidenceStore {
            public function record(int $issueId, DiagnosticResult $result, DiagnosticRun $run): int
            {
                throw new RuntimeException('The evidence table is full.');
            }
        };

        try {
            $this->issues->reconcile($this->diagnosticRun([
                $this->finding(evidence: [new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check')]),
            ]));
            self::fail('A failure to store evidence was swallowed.');
        } catch (RuntimeException) {
            self::assertSame([], $this->all());
        }
    }

    public function testAStoredRowThisVersionCannotFullyReadIsStillShownSafely(): void
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(evidence: [new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check', ['failed' => 3], confidence: Confidence::HIGH)]),
        ]));

        $issue = $this->only();

        // A type and confidence from some other version, and data that is not JSON at all.
        EvidenceRecord::updateAll(
            ['type' => 'fromTheFuture', 'confidence' => 'certainish', 'data' => '{not json', 'metadata' => '[1,'],
            ['issueId' => $issue->id],
        );

        $stored = $this->plugin->getEvidence()->latest($issue->id, $issue->latestRunId)[0];

        self::assertSame(EvidenceType::CONFIGURATION, $stored->evidence->type);
        self::assertFalse($stored->evidence->type->isClientSafe(), 'Not knowing what a fact is must never make it more visible.');
        self::assertNull($stored->evidence->confidence);
        self::assertSame([], $stored->evidence->data);
        self::assertSame([], $stored->evidence->metadata);
        self::assertSame('Failed jobs', $stored->evidence->label);
    }

    public function testMalformedTextInAFindingIsStoredRatherThanFailingTheRun(): void
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(
                summary: "Driver said \xC3\x28 something",
                evidence: [new Evidence(EvidenceType::LOG_ENTRY, "Line \xFF", 'tests.check', ['line' => "bad \xC3\x28 byte"])],
            ),
        ]));

        $issue = $this->only();

        self::assertTrue(mb_check_encoding($issue->title, 'UTF-8'));
        self::assertSame(1, $this->evidenceRows($issue));
    }

    public function testNoCredentialReachesTheDatabaseByAnyRoute(): void
    {
        // A key naming a credential, a credential inside a sentence, one recognisable by its shape,
        // one in a label, and one in the summary: every route a secret could take into storage.
        $awsKey = 'AKIA' . 'IOSFODNN7EXAMPLE';
        $exception = new RuntimeException('API said {"client_secret":"cs-in-exception"}');

        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(
                status: DiagnosticStatus::FAIL,
                summary: 'SMTP refused: password=hunter2',
                description: 'Body: {"api_key":"ak-in-description"}',
                recommendation: 'Rotate auth=auth-in-recommendation',
                evidence: [
                    Evidence::fromThrowable($exception, 'tests.check'),
                    Evidence::stackTrace($exception, 'tests.check'),
                    new Evidence(
                    type: EvidenceType::CONFIGURATION,
                    label: 'api_key=sk-live-abcdef',
                    source: 'tests.check',
                    data: [
                        'host' => 'mail.example.com',
                        'password' => 'hunter2-in-data',
                        'error' => "rejected: token=tok-in-sentence using $awsKey",
                    ],
                    metadata: ['dsn' => 'mysql://root:pw-in-metadata@db/craft'],
                    reference: 'https://user:pw-in-reference@hooks.example.com/x',
                ),
                ],
            ),
        ]));

        // A person's reason, which becomes the issue's note and a history entry.
        $issue = $this->only();
        $this->issues->transition($issue->id, IssueStatus::IGNORED, 'Pasted by mistake: password=pw-in-reason');

        $issueRow = (string)json_encode(IssueRecord::find()->where(['environment' => $this->environment])->asArray()->all());
        $eventRow = (string)json_encode(\Tahadudhiya\WebDoctor\records\IssueEventRecord::find()->where(['issueId' => $issue->id])->asArray()->all());
        $evidenceRow = (string)json_encode(EvidenceRecord::find()->where(['environment' => $this->environment])->asArray()->all());

        $secrets = ['hunter2', 'sk-live-abcdef', 'hunter2-in-data', 'tok-in-sentence', $awsKey, 'pw-in-metadata', 'pw-in-reference', 'cs-in-exception', 'ak-in-description', 'auth-in-recommendation', 'pw-in-reason'];

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $issueRow, "$secret reached the issues table.");
            self::assertStringNotContainsString($secret, $eventRow, "$secret reached the issue history.");
            self::assertStringNotContainsString($secret, $evidenceRow, "$secret reached the evidence table.");
        }

        // Nothing stored exceeds the budget a fact is held to.
        foreach ($this->evidenceRecords(EvidenceRecord::find()->where(['environment' => $this->environment])) as $row) {
            self::assertLessThanOrEqual(Evidence::MAX_DATA_BYTES, strlen((string)$row->data));
            self::assertLessThanOrEqual(Evidence::MAX_METADATA_BYTES, strlen((string)$row->metadata));
        }

        // What explains the finding is kept, and the withheld values are marked as withheld.
        self::assertStringContainsString('mail.example.com', $evidenceRow);
        self::assertStringContainsString(Redaction::REDACTED, $evidenceRow);
    }

    public function testEvidenceGoesWithTheIssueItSupports(): void
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(evidence: [new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'tests.check', ['failed' => 3])]),
        ]));

        $issue = $this->only();

        self::assertSame(1, $this->evidenceRows($issue));

        IssueRecord::deleteAll(['id' => $issue->id]);

        self::assertSame(0, $this->evidenceRows($issue));
    }

    public function testOnlyFindingsKeepEvidence(): void
    {
        // A pass describes nothing to act on, and an error or an unknown raises no issue to hang
        // anything on; their evidence stays in the latest run and nowhere else.
        $evidence = [new Evidence(EvidenceType::SYSTEM, 'State', 'tests.check', ['a' => 1])];

        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(diagnosticId: 'tests.pass', status: DiagnosticStatus::PASS, evidence: $evidence),
            $this->finding(diagnosticId: 'tests.error', status: DiagnosticStatus::ERROR, evidence: $evidence),
            $this->finding(diagnosticId: 'tests.unknown', status: DiagnosticStatus::UNKNOWN, evidence: $evidence),
        ]));

        self::assertSame(0, (int)EvidenceRecord::find()->where(['environment' => $this->environment])->count());
    }

    public function testAnIssueSurvivesBeingWrittenAndReadBack(): void
    {
        $siteId = $this->siteId();

        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(
                status: DiagnosticStatus::FAIL,
                summary: 'Something went wrong.',
                severity: Severity::HIGH,
                affectedComponent: 'widgets',
                affectedPlugin: 'commerce',
            ),
        ], siteId: $siteId));

        $issue = $this->issues->get($this->only()->id);

        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame('widgets', $issue->affectedComponent);
        self::assertSame('commerce', $issue->affectedPlugin);
        self::assertSame($siteId, $issue->siteId);
        self::assertSame(Severity::HIGH, $issue->severity);
        self::assertSame(64, strlen($issue->fingerprint));
        self::assertNotSame('', $issue->uid);
        self::assertSame($issue->fingerprint, $this->issues->getByFingerprint($issue->fingerprint)?->fingerprint);
    }

    public function testATitleLongerThanItsColumnIsKeptRatherThanLosingTheIssue(): void
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(status: DiagnosticStatus::FAIL, summary: str_repeat('a', 900)),
        ]));

        self::assertLessThanOrEqual(255, mb_strlen($this->only()->title));
    }

    public function testTheFingerprintStoredIsTheOneTheHelperProduces(): void
    {
        $result = $this->finding(status: DiagnosticStatus::FAIL);
        $run = $this->diagnosticRun([$result]);

        $this->issues->reconcile($run);

        self::assertSame(
            Fingerprint::forResult($result, $this->environment, $run->context->siteId),
            $this->only()->fingerprint,
        );
    }

    // Finding issues again ---------------------------------------------------

    public function testIssuesAreFilteredByStatusSeverityCheckAndEnvironment(): void
    {
        $this->seedVariety();

        self::assertCount(1, $this->find(new IssueFilter(environment: $this->environment, severities: [Severity::CRITICAL]))->issues);
        self::assertCount(3, $this->find(new IssueFilter(environment: $this->environment, statuses: [IssueStatus::NEW]))->issues);
        self::assertCount(1, $this->find(new IssueFilter(environment: $this->environment, diagnosticId: 'tests.high'))->issues);
        self::assertCount(3, $this->find(new IssueFilter(environment: $this->environment))->issues);
        self::assertCount(0, $this->find(new IssueFilter(environment: 'tests-nowhere'))->issues);
    }

    /**
     * "No particular site" means never associated with one. An issue whose site was deleted also
     * has a null siteId, and treating the two alike would file a finding made about one site
     * among the installation-wide ones — or let a run that never looked at that site close them —
     * the exact confusion the retained site name exists to prevent.
     */
    public function testNoParticularSiteExcludesIssuesWhoseSiteWasDeleted(): void
    {
        $siteId = $this->siteId();

        self::assertNotNull($siteId, 'This installation has no site to scope a run to.');

        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], siteId: null));
        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], siteId: $siteId));

        $installationWide = $this->issueWith(siteId: null);
        $scoped = $this->issueWith(siteId: $siteId);

        self::assertNull($installationWide->siteName);
        self::assertNotNull($scoped->siteName);

        // Before the deletion, each filter finds its own.
        self::assertSame(
            [$scoped->id],
            $this->idsOf(new IssueFilter(environment: $this->environment, siteId: $siteId)),
        );
        self::assertSame(
            [$installationWide->id],
            $this->idsOf(new IssueFilter(environment: $this->environment, withoutSite: true)),
        );

        // What the foreign key leaves behind when the site goes: the reference drops, the name
        // stays. Reproduced rather than performed, because deleting a real site rewrites the
        // surrounding project's config files.
        IssueRecord::updateAll(['siteId' => null], ['id' => $scoped->id]);

        $deleted = $this->issues->get($scoped->id);

        self::assertInstanceOf(Issue::class, $deleted);
        self::assertNull($deleted->siteId);
        self::assertSame($scoped->siteName, $deleted->siteName);

        $filter = new IssueFilter(environment: $this->environment, withoutSite: true);

        self::assertSame([$installationWide->id], $this->idsOf($filter));

        // The counts beside the list are the same query, so they exclude it too.
        self::assertSame(1, $this->issues->countsByStatus($filter)[IssueStatus::NEW->value]);

        // And a run about no particular site, finding the check clean, closes only the issue that
        // was about no particular site: it never looked at the site that was deleted.
        self::assertSame(1, $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::PASS)]))->resolved);
        self::assertSame(IssueStatus::RESOLVED, $this->issues->get($installationWide->id)?->status);
        self::assertSame(IssueStatus::NEW, $this->issues->get($scoped->id)?->status);
    }

    public function testFilteringByDateUsesWhenTheIssueWasLastSeen(): void
    {
        $this->issues->reconcile($this->diagnosticRun(
            [$this->finding(status: DiagnosticStatus::FAIL)],
            at: new DateTimeImmutable('2026-03-15 12:00:00'),
        ));

        self::assertCount(1, $this->find(new IssueFilter(environment: $this->environment, detectedFrom: '2026-03-01', detectedTo: '2026-03-31'))->issues);
        self::assertCount(0, $this->find(new IssueFilter(environment: $this->environment, detectedFrom: '2026-04-01'))->issues);
        self::assertCount(0, $this->find(new IssueFilter(environment: $this->environment, detectedTo: '2026-02-28'))->issues);
    }

    /**
     * Sorted by what a severity means, not by how it is spelled. Alphabetically `critical` comes
     * before `high` by luck; `low` before `medium` does not.
     */
    public function testSortingBySeverityFollowsTheOrderingRatherThanTheAlphabet(): void
    {
        $this->seedVariety();

        $descending = $this->find(new IssueFilter(environment: $this->environment, sort: 'severity'))->issues;
        $ascending = $this->find(new IssueFilter(environment: $this->environment, sort: 'severity', ascending: true))->issues;

        self::assertSame(
            [Severity::CRITICAL, Severity::HIGH, Severity::LOW],
            array_map(static fn(Issue $i): Severity => $i->severity, $descending),
        );
        self::assertSame(
            [Severity::LOW, Severity::HIGH, Severity::CRITICAL],
            array_map(static fn(Issue $i): Severity => $i->severity, $ascending),
        );
    }

    public function testResultsArePagedAndTheTotalIsTheWholeSet(): void
    {
        $this->seedVariety();

        $page = $this->find(new IssueFilter(environment: $this->environment, sort: 'severity', perPage: 2));

        self::assertCount(2, $page->issues);
        self::assertSame(3, $page->total);
        self::assertTrue($page->hasNextPage());
        self::assertSame(1, $page->firstPosition());
        self::assertSame(2, $page->lastPosition());

        $second = $this->find(new IssueFilter(environment: $this->environment, sort: 'severity', page: 2, perPage: 2));

        self::assertCount(1, $second->issues);
        self::assertFalse($second->hasNextPage());
        self::assertSame(3, $second->firstPosition());
        self::assertSame(3, $second->lastPosition());

        // A page past the end — a stale bookmark, or a run that resolved what was on it — is the
        // last page, not "nothing matches" with no way back.
        $beyond = $this->find(new IssueFilter(environment: $this->environment, sort: 'severity', page: 9, perPage: 2));

        self::assertSame(array_map(static fn(Issue $i): int => $i->id, $second->issues), array_map(static fn(Issue $i): int => $i->id, $beyond->issues));
        self::assertSame(2, $beyond->currentPage());
    }

    public function testTheChecksAndEnvironmentsOfferedAreTheOnesThatHaveRaisedSomething(): void
    {
        $this->seedVariety();

        $diagnostics = $this->issues->knownDiagnostics();

        self::assertArrayHasKey('tests.critical', $diagnostics);
        self::assertArrayHasKey('tests.high', $diagnostics);
        self::assertContains($this->environment, $this->issues->knownEnvironments());

        // A check renamed since is offered under the name it most recently reported, whichever
        // order the database happens to hand the two names back in.
        $critical = IssueRecord::findOne(['environment' => $this->environment, 'diagnosticId' => 'tests.critical']);
        self::assertNotNull($critical);
        $renamed = $critical->getAttributes(null, ['id', 'uid']);
        $critical->updateAttributes(['diagnosticName' => 'Old name', 'lastDetected' => '2001-01-01 00:00:00']);
        Craft::$app->getDb()->createCommand()->insert(IssueRecord::TABLE, [
            'fingerprint' => str_repeat('e', 64),
            'diagnosticName' => 'Current name',
            'uid' => \craft\helpers\StringHelper::UUID(),
        ] + $renamed)->execute();

        self::assertSame('Current name', $this->issues->knownDiagnostics()['tests.critical']);
    }

    public function testIssuesAreCountedByStatusWithoutLoadingThem(): void
    {
        $this->seedVariety();

        $counts = $this->issues->countsByStatus();

        self::assertArrayHasKey(IssueStatus::NEW->value, $counts);
        self::assertGreaterThanOrEqual(3, $counts[IssueStatus::NEW->value]);
        // Every status is present, so a page can show a zero rather than a blank.
        self::assertSame(IssueStatus::values(), array_keys($counts));
    }

    // Helpers ----------------------------------------------------------------

    private function seedVariety(): void
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(diagnosticId: 'tests.critical', status: DiagnosticStatus::FAIL, severity: Severity::CRITICAL),
            $this->finding(diagnosticId: 'tests.high', status: DiagnosticStatus::FAIL, severity: Severity::HIGH),
            $this->finding(diagnosticId: 'tests.low', status: DiagnosticStatus::WARNING, severity: Severity::LOW),
        ]));
    }

    private function find(IssueFilter $filter): \Tahadudhiya\WebDoctor\models\IssueList
    {
        return $this->issues->find($filter);
    }

    /**
     * @param list<DiagnosticResult> $results
     */
    private function diagnosticRun(array $results, ?int $siteId = null, ?DateTimeImmutable $at = null): DiagnosticRun
    {
        $at ??= new DateTimeImmutable();

        $context = new DiagnosticContext(
            siteId: $siteId,
            environment: $this->environment,
            startedAt: $at,
        );

        $stamped = array_map(
            static fn(DiagnosticResult $r): DiagnosticResult => $r->withExecution($context, $at, $at, 1.0),
            $results,
        );

        return new DiagnosticRun(
            context: $context,
            results: $stamped,
            startedAt: $at,
            finishedAt: $at,
            durationMs: 1.0,
        );
    }

    /**
     * @param list<Evidence> $evidence
     */
    private function finding(
        string $diagnosticId = 'tests.check',
        DiagnosticStatus $status = DiagnosticStatus::FAIL,
        string $summary = 'Something is wrong.',
        ?Severity $severity = null,
        string $description = '',
        ?string $recommendation = null,
        ?string $affectedComponent = null,
        ?string $affectedPlugin = null,
        array $evidence = [],
    ): DiagnosticResult {
        return new DiagnosticResult(
            diagnosticId: $diagnosticId,
            name: 'Example check',
            category: DiagnosticCategory::DATABASE,
            status: $status,
            summary: $summary,
            severity: $severity,
            description: $description,
            evidence: $evidence,
            recommendation: $recommendation,
            confidence: Confidence::LIKELY,
            affectedComponent: $affectedComponent,
            affectedPlugin: $affectedPlugin,
        );
    }

    /**
     * @return list<Issue>
     */
    private function all(): array
    {
        return $this->find(new IssueFilter(environment: $this->environment, sort: 'diagnostic', ascending: true, perPage: 100))->issues;
    }

    private function only(): Issue
    {
        $issues = $this->all();

        self::assertCount(1, $issues, 'Expected exactly one issue in this environment.');

        return $issues[0];
    }

    /**
     * Takes a stored row away and returns what puts it back as another request would: on a
     * connection of its own, committed, while the caller's transaction is still open.
     *
     * @return callable(): void
     */
    private function racedRow(?\yii\db\ActiveRecord $row): callable
    {
        self::assertNotNull($row);

        $table = $row::tableName();
        $attributes = $row->getAttributes();
        $row->delete();

        return static function() use ($table, $attributes): void {
            $other = clone Craft::$app->getDb();
            $other->open();
            $other->createCommand()->insert($table, $attributes)->execute();
            $other->close();
        };
    }

    /**
     * @return list<int>
     */
    private function idsOf(IssueFilter $filter): array
    {
        return array_map(static fn(Issue $i): int => $i->id, $this->find($filter)->issues);
    }

    private function issueWith(?int $siteId): Issue
    {
        foreach ($this->all() as $issue) {
            if ($issue->siteId === $siteId) {
                return $issue;
            }
        }

        self::fail(sprintf('No issue was found for site %s.', $siteId === null ? 'null' : (string)$siteId));
    }

    private function issueFor(string $diagnosticId): Issue
    {
        $issues = $this->find(new IssueFilter(environment: $this->environment, diagnosticId: $diagnosticId))->issues;

        self::assertCount(1, $issues, "Expected one issue for $diagnosticId.");

        return $issues[0];
    }

    /**
     * @return list<IssueEventType>
     */
    private function eventTypes(Issue $issue): array
    {
        $types = array_map(
            static fn(\Tahadudhiya\WebDoctor\models\IssueEvent $e): IssueEventType => $e->type,
            $this->issues->events($issue->id),
        );

        return array_reverse($types);
    }

    /**
     * The labels of an issue's evidence, most recently seen first.
     *
     * @return list<string>
     */
    private function labelsOf(Issue $issue, int $limit): array
    {
        return array_map('strval', EvidenceRecord::find()
            ->select(['label'])
            ->where(['issueId' => $issue->id])
            ->orderBy(['lastSeen' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->column());
    }

    /**
     * @return list<EvidenceRecord>
     */
    private function evidenceRecords(\craft\db\ActiveQuery $query): array
    {
        return array_values(array_filter($query->all(), static fn(mixed $r): bool => $r instanceof EvidenceRecord));
    }

    private function evidenceRows(Issue $issue): int
    {
        return (int)EvidenceRecord::find()->where(['issueId' => $issue->id])->count();
    }

    private function siteId(): ?int
    {
        try {
            return Craft::$app->getSites()->getPrimarySite()->id;
        } catch (\Throwable) {
            return null;
        }
    }

    // Who may reach any of it ------------------------------------------------

    public function testReadingTheIssueListNeedsItsOwnPermission(): void
    {
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $this->request('GET');

        // Reaching Web Doctor is not the same as reading what it has found — the list, or one
        // issue and its evidence.
        $issue = $this->seedIssue();

        foreach (['index' => [], 'detail' => ['issueId' => $issue->id]] as $action => $params) {
            try {
                $this->controller()->runAction($action, $params);
                self::fail("The $action action answered without the permission to read issues.");
            } catch (ForbiddenHttpException) {
                // As it should.
            }
        }
    }

    public function testChangingAnIssueNeedsMoreThanReadingOne(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->post(['issueId' => $issue->id, 'status' => IssueStatus::CONFIRMED->value]);

        try {
            $this->controller()->runAction('update-status');
            self::fail('A reader without the manage permission changed an issue.');
        } catch (ForbiddenHttpException) {
            // The issue must be exactly as it was.
            self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        }
    }

    public function testSomebodyWhoMayManageIssuesCanChangeOne(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::MANAGE_ISSUES]);
        $this->post(['issueId' => $issue->id, 'status' => IssueStatus::CONFIRMED->value]);

        $this->controller()->runAction('update-status');

        self::assertSame(IssueStatus::CONFIRMED, $this->reread($issue)->status);
    }

    public function testTheIssueCenterIsNotReachableFromTheFrontEnd(): void
    {
        $this->signIn(admin: true);
        $this->request('GET', cp: false);

        // A plugin action route answers front-end requests unless something refuses them, and a
        // list of a site's weaknesses is not something to serve to the public web.
        foreach (['index' => [], 'detail' => ['issueId' => $this->seedIssue()->id]] as $action => $params) {
            try {
                $this->controller()->runAction($action, $params);
                self::fail("The $action action answered a front-end request.");
            } catch (BadRequestHttpException) {
                // As it should.
            }
        }
    }

    public function testNoActionIsOpenedUpToAnonymousRequests(): void
    {
        // A request with no identity cannot be driven through the controller here, because Craft
        // answers it with a login redirect that needs a session. What can be asserted is that
        // nothing opts an action out of authentication in the first place.
        self::assertSame(RecordingIssuesController::ALLOW_ANONYMOUS_NEVER, $this->controller()->anonymousAccess());
    }

    public function testChangingAnIssueMustBeAPost(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        $this->expectException(MethodNotAllowedHttpException::class);
        $this->controller()->runAction('update-status');
    }

    public function testAChangeWithoutAValidTokenIsRefused(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: true);

        $request = $this->request('POST');
        $request->setBodyParams(['issueId' => $issue->id, 'status' => IssueStatus::CONFIRMED->value]);

        try {
            $this->controller()->runAction('update-status');
            self::fail('A request with no CSRF token was accepted.');
        } catch (BadRequestHttpException) {
            self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        }
    }

    /**
     * The refusal the Issue Center turns on, checked where a person would actually meet it.
     */
    public function testTheControllerWillNotDeclareAnIssueResolved(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: true);
        $this->post(['issueId' => $issue->id, 'status' => IssueStatus::RESOLVED->value]);

        $controller = $this->controller();
        $controller->runAction('update-status');

        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        self::assertSame('fail', $this->flash($controller)['level']);
    }

    public function testAChangeSaysWhetherAnythingChanged(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: true);

        $this->post(['issueId' => $issue->id, 'status' => IssueStatus::CONFIRMED->value, 'note' => '']);
        $controller = $this->controller();
        $controller->runAction('update-status');

        self::assertSame(IssueStatus::CONFIRMED, $this->reread($issue)->status);
        self::assertSame(['level' => 'success', 'message' => 'Issue updated.'], $this->flash($controller));

        // Saved again as it is: not an update, and not reported as one.
        $this->post(['issueId' => $issue->id, 'status' => IssueStatus::CONFIRMED->value, 'note' => '']);
        $controller = $this->controller();
        $controller->runAction('update-status');

        self::assertStringStartsWith('Nothing changed', $this->flash($controller)['message']);
    }

    public function testAStatusWebDoctorDoesNotHaveIsRefused(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: true);
        $this->post(['issueId' => $issue->id, 'status' => 'nonsense']);

        $controller = $this->controller();
        $controller->runAction('update-status');

        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        self::assertSame('fail', $this->flash($controller)['level']);
    }

    public function testDismissingWithoutAReasonIsRefusedAndSaysWhy(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: true);
        $this->post(['issueId' => $issue->id, 'status' => IssueStatus::IGNORED->value, 'note' => '']);

        $controller = $this->controller();
        $controller->runAction('update-status');

        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        self::assertSame('fail', $this->flash($controller)['level']);
        self::assertStringContainsString('reason', $this->flash($controller)['message']);
    }

    public function testChangingAnIssueThatDoesNotExistFailsWithoutBlowingUp(): void
    {
        $this->signIn(admin: true);
        $this->post(['issueId' => 0, 'status' => IssueStatus::CONFIRMED->value]);

        $controller = $this->controller();
        $controller->runAction('update-status');

        self::assertSame('fail', $this->flash($controller)['level']);
    }

    public function testTheListShowsTheIssuesThatHaveBeenFound(): void
    {
        $this->seedIssue(summary: 'The queue has stalled.');
        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render('index', 'web-doctor/_issues/_list');

        self::assertStringContainsString('The queue has stalled.', $html);
        self::assertStringContainsString('tests.queue', $html);
    }

    public function testTheListOpensOnWhatIsOutstanding(): void
    {
        $issue = $this->seedIssue();
        $this->issues->transition($issue->id, IssueStatus::IGNORED, 'Not now.');

        $this->signIn(admin: true);
        $this->request('GET');

        $variables = $this->variables('index');

        /** @var IssueFilter $filter */
        $filter = $variables['filter'];

        self::assertSame(IssueStatus::open(), $filter->statuses);
        self::assertTrue($filter->isFiltering());
    }

    public function testTheDetailPageShowsTheIssueAndItsHistory(): void
    {
        $issue = $this->seedIssue(summary: 'The mailer is misconfigured.');
        $this->issues->transition($issue->id, IssueStatus::INVESTIGATING, 'Checking the transport.');

        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('The mailer is misconfigured.', $html);
        self::assertStringContainsString('Checking the transport.', $html);
        self::assertStringContainsString($issue->fingerprint, $html);
    }

    /**
     * What a developer inspecting an issue needs: the evidence itself, with every withheld value
     * marked as withheld rather than left for the reader to spot, and nothing withheld revealed.
     */
    public function testTheDetailPageShowsTheEvidenceAndMarksWhatWasWithheld(): void
    {
        $issue = $this->seedIssue(evidence: [new Evidence(
            type: EvidenceType::CONFIGURATION,
            label: 'Mailer settings',
            source: 'tests.queue',
            data: [
                'transport' => 'zz-distinctive-payload-zz',
                'password' => 'hunter2-on-the-page',
                'error' => 'refused: token=tok-on-the-page',
                'fromEmail' => Redaction::MISSING,
            ],
            metadata: ['probed' => 'zz-metadata-zz'],
            reference: 'zz-reference-zz',
        )]);

        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        foreach (['Mailer settings', 'zz-distinctive-payload-zz', 'zz-metadata-zz', 'zz-reference-zz', 'refused: token='] as $expected) {
            self::assertStringContainsString($expected, $html);
        }

        foreach (['hunter2-on-the-page', 'tok-on-the-page', Redaction::REDACTED] as $absent) {
            self::assertStringNotContainsString($absent, $html, "$absent should never reach the page.");
        }

        // Two withheld values, each shown as a mark of its own, and counted in the summary line.
        self::assertSame(2, substr_count($html, 'wd-mark wd-mark--redacted" title='));
        self::assertStringContainsString('2 values withheld', $html);
        self::assertStringContainsString('wd-mark--presence-missing', $html);
    }

    public function testAWithheldValueInWhatACheckWroteIsMarkedWhereverItIsShown(): void
    {
        // The title is the check's own summary, redacted as the result was built. The list and
        // the detail page show the withheld part as a mark, never as the raw marker.
        $issue = $this->seedIssue(summary: 'Login refused: password=hunter2-in-title');
        $this->issues->transition($issue->id, IssueStatus::IGNORED, 'Retried with token=tok-in-reason');

        $this->signIn(admin: true);

        foreach ([['index', 'web-doctor/_issues/_list', ['status' => IssueStatus::values()]], ['detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]]] as [$action, $template, $params]) {
            $this->request('GET')->setQueryParams($action === 'index' ? $params : []);
            $html = $this->render($action, $template, $action === 'index' ? [] : $params);

            self::assertStringContainsString('Login refused: password=', $html);
            self::assertStringContainsString('wd-mark--redacted', $html);
            self::assertStringNotContainsString('hunter2-in-title', $html);
            self::assertStringNotContainsString('tok-in-reason', $html);
            self::assertStringNotContainsString(Redaction::REDACTED . "<", $html, "The $action page printed the raw marker.");
        }
    }

    public function testWhatEvidenceContainsNeedsItsOwnPermission(): void
    {
        $issue = $this->seedIssue(evidence: [new Evidence(
            type: EvidenceType::EXCEPTION,
            label: 'RuntimeException',
            source: 'tests.queue',
            data: ['message' => 'zz-internal-detail-zz'],
            reference: '/var/www/zz-private-path-zz.php:12',
        )]);

        $reader = [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES];

        // Somebody following the issue sees what kind of evidence it rests on and nothing inside.
        $this->signIn(admin: false, permissions: $reader);
        $this->request('GET');
        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('View evidence', $html);
        self::assertStringNotContainsString('zz-internal-detail-zz', $html);
        self::assertStringNotContainsString('zz-private-path-zz', $html);

        // The permission is what opens it.
        $this->signIn(admin: false, permissions: [...$reader, Permissions::VIEW_EVIDENCE]);
        $this->request('GET');
        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('zz-internal-detail-zz', $html);
        self::assertStringContainsString('zz-private-path-zz', $html);
    }

    public function testEvidenceThatCannotBeReadCostsThePageItsEvidenceAndNotTheIssue(): void
    {
        $issue = $this->seedIssue(summary: 'The queue has stalled.');

        $this->plugin->set('evidence', new class() extends EvidenceStore {
            public function latest(int $issueId, ?string $latestRunId): array
            {
                throw new RuntimeException('The evidence table went away: password=hunter2');
            }
        });

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('The queue has stalled.', $html);
        self::assertStringContainsString('could not read the evidence', $html);
        self::assertStringNotContainsString('hunter2', $html);
    }

    /**
     * A reader must not be offered a control that would make "resolved" mean "somebody clicked
     * resolved", and must be told why it is not there.
     */
    public function testTheDetailPageOffersNoWayToDeclareAnIssueResolved(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringNotContainsString('value="resolved"', $html);
        self::assertStringNotContainsString('value="repairing"', $html);
        self::assertStringContainsString('value="ignored"', $html);
        self::assertStringContainsString('is not on this list', $html);

        // Once it is resolved, nothing on the form is chosen for the reader: a browser left with
        // no selected option submits the first, which would reopen the issue as new. Nor is the
        // last reason offered back, to be saved as the reason for whatever is chosen next.
        IssueRecord::updateAll(['status' => IssueStatus::RESOLVED->value, 'statusNote' => 'Waiting on the host.'], ['id' => $issue->id]);
        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertMatchesRegularExpression('/<option value="" selected disabled>/', $html);
        self::assertDoesNotMatchRegularExpression('/<option value="[a-z_]+" selected>/', $html);
        self::assertMatchesRegularExpression('#<textarea id="wd-issue-note"[^>]*></textarea>#', $html);
        self::assertStringContainsString('Waiting on the host.', $html);
    }

    public function testSomebodyWhoMayOnlyReadIsOfferedNoControls(): void
    {
        $issue = $this->seedIssue();
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->request('GET');

        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringNotContainsString('web-doctor/issues/update-status', $html);
        self::assertStringNotContainsString('value="ignored"', $html);
    }

    public function testADeletedSiteStillNamesItselfRatherThanReadingAsTheWholeInstallation(): void
    {
        $siteId = $this->siteId();

        self::assertNotNull($siteId);

        $this->issues->reconcile($this->diagnosticRun([$this->finding(status: DiagnosticStatus::FAIL)], siteId: $siteId));
        $issue = $this->only();

        // The state the foreign key leaves behind when the site goes.
        IssueRecord::updateAll(['siteId' => null], ['id' => $issue->id]);

        $this->signIn(admin: true);
        $this->request('GET');

        $html = $this->render('detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString((string)$issue->siteName, $html);
        self::assertStringNotContainsString('All sites', $html);
    }

    public function testAnIssueThatDoesNotExistIsNotFound(): void
    {
        $this->signIn(admin: true);
        $this->request('GET');

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->runAction('detail', ['issueId' => 0]);
    }

    // Helpers for the control panel ------------------------------------------

    /**
     * An issue raised the way a run would raise one, ready to be looked at through a page.
     *
     * @param list<Evidence> $evidence
     */
    private function seedIssue(string $summary = 'Something is wrong.', array $evidence = []): Issue
    {
        $this->issues->reconcile($this->diagnosticRun([
            $this->finding(
                diagnosticId: 'tests.queue',
                status: DiagnosticStatus::FAIL,
                summary: $summary,
                severity: Severity::HIGH,
                evidence: $evidence,
            ),
        ]));

        return $this->only();
    }

    /**
     * The last thing an action tried to tell the reader. Asserted to exist, because an action
     * that reported nothing at all is a different failure from one that reported the wrong thing.
     *
     * @return array{level: string, message: string}
     */
    private function flash(RecordingIssuesController $controller): array
    {
        $flash = $controller->lastFlash();

        self::assertIsArray($flash, 'The action said nothing to the reader.');

        return $flash;
    }

    private function reread(Issue $issue): Issue
    {
        $fresh = $this->issues->get($issue->id);

        self::assertInstanceOf(Issue::class, $fresh);

        return $fresh;
    }

    /**
     * A request shaped the way Craft would see one. The reasoning behind stating the control
     * panel flag outright is the same as on the health dashboard: Yii derives its base URL from
     * the running script, which under a test runner is the PHPUnit binary, so Craft's
     * URL-scoring cannot reach a verdict — but the flag it would set is the one the controller
     * reads, so both answers are exercised for real.
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
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor/issues" : '/actions/web-doctor/issues/index';
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

        // Craft resolves the formatting locale from the signed-in user's preferences on a
        // control panel request, which needs a session a console run does not have.
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
     * A POST carrying the CSRF token Craft would have issued for this request and this user.
     *
     * @param array<string, mixed> $params
     */
    private function post(array $params = []): WebRequest
    {
        $request = $this->request('POST');
        $request->setBodyParams($params + [$request->csrfParam => $request->getCsrfToken()]);

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

    private function controller(): RecordingIssuesController
    {
        return new RecordingIssuesController('issues', $this->plugin);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function variables(string $action, array $params = []): array
    {
        $response = $this->controller()->runAction($action, $params);

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return $behavior->variables;
    }

    /**
     * Renders what the controller actually produced, through the real template. Only Craft's own
     * control panel shell is left out, because it asks for a session a console run has none of.
     *
     * @param array<string, mixed> $params
     */
    private function render(string $action, string $template, array $params = []): string
    {
        return Craft::$app->getView()->renderTemplate(
            $template,
            $this->variables($action, $params),
            View::TEMPLATE_MODE_CP,
        );
    }
}
