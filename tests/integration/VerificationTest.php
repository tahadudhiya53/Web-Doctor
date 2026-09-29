<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Closure;
use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\IssueEventType;
use Tahadudhiya\WebDoctor\enums\IssueResolution;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\RepairReport;
use Tahadudhiya\WebDoctor\models\Verification;
use Tahadudhiya\WebDoctor\models\VerificationCondition;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\records\RepairRecord;
use Tahadudhiya\WebDoctor\records\VerificationRecord;
use Tahadudhiya\WebDoctor\repairs\CoreRepairActions;
use Tahadudhiya\WebDoctor\repairs\CreateStorageDirectories;
use Tahadudhiya\WebDoctor\repairs\RetryFailedJobs;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\services\Errors;
use Tahadudhiya\WebDoctor\services\EvidenceStore;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\RepairActions;
use Tahadudhiya\WebDoctor\services\Repairs;
use Tahadudhiya\WebDoctor\services\VerificationActions;
use Tahadudhiya\WebDoctor\services\Verifications;
use Tahadudhiya\WebDoctor\Tests\_support\QueueTestJob;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingRepairsController;
use Tahadudhiya\WebDoctor\Tests\_support\StatedStoragePaths;
use Tahadudhiya\WebDoctor\Tests\_support\StubQueue;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestRepairAction;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\Tests\_support\TestVerificationAction;
use Tahadudhiya\WebDoctor\Tests\_support\WebDoctorTables;
use Tahadudhiya\WebDoctor\verifications\CoreVerificationActions;
use Tahadudhiya\WebDoctor\verifications\RetriedJobsSettled;
use Tahadudhiya\WebDoctor\verifications\StorageDirectoriesPresent;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

/**
 * Verifying a repair, end to end: what runs, the three answers and the one rule each rests on,
 * what the issue says afterwards, the evidence compared, errors and problems that appeared since,
 * what each repair should have left true, who may verify and from where, what is kept, and what
 * the repair page shows.
 *
 * The checks are ones the test states, registered in a registry of the test's own, so each answer
 * can be reached on purpose; the two verification actions Web Doctor ships with are exercised
 * against a directory and a queue table of the test's own. Every test runs under an environment
 * name of its own and removes only that environment's rows.
 */
class VerificationTest extends TestCase
{
    private string $environment;
    private Diagnostics $registry;
    private Issues $issues;
    private RepairActions $repairActions;
    private Repairs $repairs;
    private VerificationActions $verificationActions;
    private Verifications $verifications;
    private WebDoctor $plugin;
    private TestDiagnostic $check;
    private TestVerificationAction $verifier;
    private ?string $queueTable = null;

    private ?StatedStoragePaths $storage = null;
    private ?string $directory = null;
    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Craft::$app->getDb()->tableExists(VerificationRecord::TABLE)) {
            self::fail(sprintf(
                'The table %s does not exist. Reinstall Web Doctor in this project first: `php craft plugin/uninstall web-doctor && php craft plugin/install web-doctor`.',
                VerificationRecord::TABLE,
            ));
        }

        $this->environment = 'tests-' . bin2hex(random_bytes(5));

        // Only what the test registers: a handler on the class — another plugin's, the local lab's
        // — would otherwise add checks to the area a verification reaches.
        $this->registry = new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };

        $store = new EvidenceStore();
        $this->issues = new Issues(['evidence' => $store]);
        $this->repairActions = new RepairActions();
        $this->repairs = new Repairs([
            'actions' => $this->repairActions,
            'issues' => $this->issues,
            'evidence' => $store,
            'permissions' => new Permissions(),
            'environment' => $this->environment,
        ]);
        $this->verificationActions = new VerificationActions();
        $this->verifications = $this->verificationsService();

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
        $this->plugin->set('repairActions', $this->repairActions);
        $this->plugin->set('repairs', $this->repairs);
        $this->plugin->set('issues', $this->issues);
        $this->plugin->set('verificationActions', $this->verificationActions);
        $this->plugin->set('verifications', $this->verifications);

        // The problem the tests repair: the check reports it until a test says otherwise.
        $this->check = $this->register('test.check', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('fail', ['summary' => 'test.check reports a problem.', 'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'broken'])]]));
        $this->repairActions->register(new TestRepairAction());
        // What the test repair should have left true holds, unless a test says otherwise.
        $this->verifier = new TestVerificationAction();
        $this->verificationActions->register($this->verifier);

        $this->signIn(admin: true);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        // Verifications go with their repairs; repairs outlive their issues, so they go first.
        RepairRecord::deleteAll(['like', 'environment', 'tests-%', false]);
        IssueRecord::deleteAll(['like', 'environment', 'tests-%', false]);
        ErrorGroupRecord::deleteAll(['like', 'environment', 'tests-%', false]);

        if ($this->queueTable !== null) {
            Craft::$app->getDb()->createCommand()->dropTable($this->queueTable)->execute();
            $this->queueTable = null;
        }

        if ($this->directory !== null) {
            FileHelper::removeDirectory($this->directory);
            $this->directory = null;
        }

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

    // The three answers ---------------------------------------------------------------

    public function testARepairThatWorkedIsVerifiedAndTheIssueResolvedOnThoseGrounds(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();

        $verification = $this->verifications->verify($repair->id, $issue->id);
        $issue = $this->reread($issue);

        self::assertSame(VerificationStatus::VERIFIED, $verification->result);
        self::assertSame([], $verification->failures);
        // The check that found the problem ran again, and its run is the one that resolved the issue.
        self::assertSame(1, $this->check->runs);
        self::assertSame('test.check', $verification->checks[0]['diagnosticId']);
        self::assertSame(Verification::ROLE_ORIGINAL, $verification->checks[0]['role']);
        self::assertSame(DiagnosticStatus::PASS, $verification->checks[0]['status']);
        self::assertSame(IssueStatus::RESOLVED, $issue->status);
        self::assertSame(IssueResolution::VERIFIED, $issue->resolution);
        self::assertSame($verification->runId, $issue->resolvedByRunId);
        self::assertSame(VerificationStatus::VERIFIED, $this->repairs->get($repair->id)?->verificationStatus);
        self::assertSame($repair->id, $verification->repairId);
        self::assertSame(1, $verification->verifiedBy);

        // The history says it was observed clear by that run, and verified.
        $types = array_map(static fn($e) => $e->type, $this->issues->events($issue->id));
        self::assertSame(IssueEventType::REPAIR_VERIFIED, $types[0]);
        self::assertContains(IssueEventType::RESOLVED, $types);
    }

    public function testARepairWhoseProblemRemainsFailsVerificationAndTheIssueIsNeverResolved(): void
    {
        $issue = $this->raise();
        $this->issues->transition($issue->id, IssueStatus::CONFIRMED);
        $repair = $this->repaired($issue);

        $verification = $this->verifications->verify($repair->id, $issue->id);
        $after = $this->reread($issue);

        self::assertSame(VerificationStatus::FAILED, $verification->result);
        self::assertStringContainsString('Repair completed, but verification failed', $verification->summary());
        self::assertStringContainsString('still reports it', $verification->failures[0]);
        self::assertTrue($verification->checks[0]['persists']);
        self::assertSame(IssueStatus::CONFIRMED, $after->status);
        self::assertSame(IssueResolution::NONE, $after->resolution);
        $stored = $this->repairs->get($repair->id);
        self::assertNotNull($stored);
        self::assertSame(VerificationStatus::FAILED, $stored->verificationStatus);
        // The repair itself still says it was carried out: it ran, and it did not work.
        self::assertSame(RepairStatus::SUCCEEDED, $stored->status);

        // An issue a run had already observed clear in the meantime is found again, not left resolved.
        $this->clears();
        $this->runChecks();
        self::assertSame(IssueStatus::RESOLVED, $this->reread($issue)->status);
        $this->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('fail', ['summary' => 'test.check reports a problem.', 'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'broken'])]]);

        $again = $this->verifications->verify($repair->id, $issue->id);
        $after = $this->reread($issue);

        self::assertSame(VerificationStatus::FAILED, $again->result);
        self::assertSame(IssueStatus::NEW, $after->status);
        self::assertSame(IssueResolution::NONE, $after->resolution);
    }

    /**
     * Every way a verification can fall short of an answer, and the reason it gives. None of them
     * is evidence the repair failed, and none is evidence it worked.
     *
     * @return array<string, array{Closure, string}>
     */
    public static function inconclusive(): array
    {
        return [
            'the check that found the problem could not tell' => [static function(self $t): void {
                $t->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('unknown', ['summary' => 'Could not look.']);
            }, 'could not answer (Unknown)'],
            'the check that found the problem broke' => [static function(self $t): void {
                $t->check->handler = static fn(): DiagnosticResult => throw new RuntimeException('The check broke.');
            }, 'could not answer (Error)'],
            'the check that found the problem is not registered here' => [static function(self $t): void {
                $t->unregister('test.check');
            }, 'is not registered here'],
            'whether it holds cannot be told yet' => [static function(self $t): void {
                $t->clears();
                $t->verifier(VerificationCondition::UNDETERMINED);
            }, 'cannot be told yet: The target stayed changed.'],
            'the verification action broke' => [static function(self $t): void {
                $t->clears();
                $t->verifier(VerificationCondition::HELD, throws: true);
            }, 'could not be read'],
            'the verification action established nothing' => [static function(self $t): void {
                $t->clears();
                $t->verifier->returns = [];
            }, 'could not be read'],
            'the verification action returned something that is not a condition' => [static function(self $t): void {
                $t->clears();
                $t->verifier->returns = [VerificationCondition::held('targetChanged', 'The target stayed changed.'), 'held'];
            }, 'could not be read'],
            'no verification action for this kind of repair' => [static function(self $t): void {
                $t->clears();
                $t->verifications->actions = new VerificationActions();
            }, 'No verification action is registered for “test.repair”'],
            'the check that found the problem reports another problem' => [static function(self $t): void {
                $t->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => new DiagnosticResult(
                    diagnosticId: 'test.check',
                    name: 'Check test.check',
                    category: DiagnosticCategory::CONFIGURATION,
                    status: DiagnosticStatus::WARNING,
                    summary: 'Something else about the target.',
                    evidence: [new Evidence(EvidenceType::CONFIGURATION, 'Other', 'test.check', ['state' => 'other'])],
                    affectedComponent: 'another part',
                );
            }, 'still reports a problem, though not the one being verified'],
            'the check recorded no evidence for its answer' => [static function(self $t): void {
                $t->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', ['summary' => 'Nothing wrong.']);
            }, 'recorded no evidence for its answer'],
            'the issue recorded no evidence to compare with' => [static function(self $t, Issue $i): void {
                $t->clears();
                \Tahadudhiya\WebDoctor\records\EvidenceRecord::deleteAll(['issueId' => $i->id]);
            }, 'holds no evidence, so there is nothing to compare'],
            'the issue’s evidence could not be read' => [static function(self $t): void {
                $t->clears();
                $t->verifications->evidence = new class() extends EvidenceStore {
                    public function latest(int $issueId, ?string $latestRunId): array
                    {
                        throw new RuntimeException('The evidence would not read.');
                    }
                };
            }, 'could not be read, so there is nothing to compare'],
            'a check the repair names could not answer' => [static function(self $t): void {
                $t->clears();
                $t->register('test.named', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('unknown', ['summary' => 'Could not look.']), DiagnosticCategory::QUEUE);
            }, 'Check test.named could not answer'],
            'a check the repair names is not registered here' => [static function(self $t): void {
                $t->clears();
            }, 'test.named is not registered here'],
            'a problem appeared since the repair' => [static function(self $t): void {
                $t->clears();
                $t->register('test.nearby', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('warning', ['summary' => 'Something nearby is wrong.']));
            }, 'reports a problem that appeared since the repair: Something nearby is wrong.'],
            'an error first seen since the repair' => [static function(self $t): void {
                $t->clears();
                $t->register('test.nearby', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', [
                    'summary' => 'Fine, having caught something.',
                    'evidence' => [Evidence::fromThrowable(new RuntimeException('Caught along the way.'), 'test.nearby')],
                ]));
            }, 'an error first seen since the repair'],
            'the Issue Center could not take the run' => [static function(self $t): void {
                $t->clears();
                $t->verifications->issues = new class(['evidence' => new EvidenceStore()]) extends Issues {
                    public function reconcile(DiagnosticRun $run, array $leaveOpen = []): \Tahadudhiya\WebDoctor\models\IssueReconciliation
                    {
                        throw new RuntimeException('The issue list would not update.');
                    }
                };
            }, 'The issue list could not be updated'],
            'the errors could not be recorded' => [static function(self $t): void {
                $t->clears();
                $t->register('test.nearby', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', [
                    'summary' => 'Fine, having caught something.',
                    'evidence' => [Evidence::fromThrowable(new RuntimeException('Caught along the way.'), 'test.nearby')],
                ]));
                $t->verifications->errors = new class() extends Errors {
                    public function record(DiagnosticRun $run): \Tahadudhiya\WebDoctor\models\ErrorRecording
                    {
                        throw new RuntimeException('The errors would not record.');
                    }
                };
            }, 'could not be recorded'],
        ];
    }

    /**
     * @param Closure(self, Issue): void $arrange
     */
    #[DataProvider('inconclusive')]
    public function testAVerificationThatCannotEstablishEitherAnswerIsInconclusiveAndSaysWhy(Closure $arrange, string $said): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue, ['verifying' => ['test.check', 'test.named']]);

        // Every case but the named check's absence has it registered and answering.
        if (!str_contains($said, 'test.named')) {
            $this->register('test.named', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', ['summary' => 'Fine.']), DiagnosticCategory::QUEUE);
        }

        $arrange($this, $issue);
        $verification = $this->verifications->verify($repair->id, $issue->id);
        $after = $this->reread($issue);

        self::assertSame(VerificationStatus::INCONCLUSIVE, $verification->result, implode(' | ', $verification->failures));
        self::assertStringContainsString($said, implode(' | ', $verification->failures));
        self::assertSame(VerificationStatus::INCONCLUSIVE, $this->repairs->get($repair->id)?->verificationStatus);
        // Whatever the checks found, an inconclusive verification never resolves the issue — not
        // even as an observation of its check going quiet.
        self::assertNotSame(IssueStatus::RESOLVED, $after->status);
        self::assertSame(IssueResolution::NONE, $after->resolution);
    }

    /**
     * What a repair should have left true, conclusively not true, is a failed verification however
     * quiet its check has gone — and an issue a run had observed clear in the meantime opens again.
     */
    public function testWhatTheRepairShouldHaveLeftTrueNotHoldingFailsVerificationAndReopensTheIssue(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();
        $this->runChecks();
        self::assertSame(IssueStatus::RESOLVED, $this->reread($issue)->status);
        $this->verifier(VerificationCondition::NOT_HELD);

        $verification = $this->verifications->verify($repair->id, $issue->id);
        $after = $this->reread($issue);

        self::assertSame(VerificationStatus::FAILED, $verification->result);
        self::assertStringContainsString('does not hold: The target stayed changed. (It changed back.)', implode(' | ', $verification->failures));
        self::assertSame(IssueStatus::NEW, $after->status);
        self::assertSame(IssueResolution::NONE, $after->resolution);
    }

    public function testAProblemAlreadyReportedBeforeTheRepairDoesNotStandInTheWay(): void
    {
        $nearby = $this->register('test.nearby', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('warning', ['summary' => 'Something nearby has been wrong for a while.']));
        $issue = $this->raise();
        $this->runChecks([$nearby]);
        IssueRecord::updateAll(['firstDetected' => Db::prepareDateForDb(new DateTimeImmutable('-1 day'))], ['diagnosticId' => 'test.nearby', 'environment' => $this->environment]);
        $repair = $this->repaired($issue);
        $this->clears();

        $verification = $this->verifications->verify($repair->id, $issue->id);
        $checks = array_column($verification->checks, null, 'diagnosticId');

        self::assertSame(VerificationStatus::VERIFIED, $verification->result, implode(' | ', $verification->failures));
        self::assertSame(Verification::ROLE_AREA, $checks['test.nearby']['role']);
        self::assertFalse($checks['test.nearby']['appeared']);
        self::assertNotNull($checks['test.nearby']['issueId']);
    }

    public function testEvidenceIsComparedByWhatItRecordsNotByWhenItWasSeen(): void
    {
        $fact = static fn(string $label, mixed $value): Evidence => new Evidence(EvidenceType::CONFIGURATION, $label, 'test.check', ['value' => $value], observedAt: new DateTimeImmutable());
        $issue = $this->raise([$fact('Setting A', 1), $fact('Setting B', 2)]);
        $repair = $this->repaired($issue);

        // Still failing, with one fact unchanged — seen again at a different moment — one gone and
        // one new.
        $this->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('fail', [
            'summary' => 'test.check reports a problem.',
            'evidence' => [$fact('Setting B', 2), $fact('Setting C', 3)],
        ]);

        $verification = $this->verifications->verify($repair->id, $issue->id);

        self::assertSame(VerificationStatus::FAILED, $verification->result);
        self::assertSame([['type' => 'configuration', 'label' => 'Setting B']], $verification->comparison['persisting']);
        self::assertSame([['type' => 'configuration', 'label' => 'Setting A']], $verification->comparison['gone']);
        self::assertSame([['type' => 'configuration', 'label' => 'Setting C']], $verification->comparison['appeared']);
        self::assertSame(['Setting A', 'Setting B'], array_map(static fn(Evidence $e): string => $e->label, $verification->originalState));
        self::assertSame(['Setting B', 'Setting C'], array_map(static fn(Evidence $e): string => $e->label, $verification->currentState));
        self::assertSame(2, $verification->originalCount);
    }

    public function testTheChecksRunAreTheOriginalThenThoseTheRepairNamesThenTheRestOfItsAreaEachOnce(): void
    {
        $this->register('test.aaa', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', ['summary' => 'Fine.']));
        $this->register('test.named', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', ['summary' => 'Fine.']), DiagnosticCategory::QUEUE);
        $elsewhere = $this->register('test.elsewhere', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', ['summary' => 'Fine.']), DiagnosticCategory::EMAIL);
        $issue = $this->raise();
        $repair = $this->repaired($issue, ['verifying' => ['test.check', 'test.named', 'test.aaa']]);

        $plan = $this->verifications->plan($repair, $issue);

        self::assertSame(['test.check', 'test.named', 'test.aaa'], array_column($plan, 'diagnosticId'));
        self::assertSame([Verification::ROLE_ORIGINAL, Verification::ROLE_NAMED, Verification::ROLE_NAMED], array_column($plan, 'role'));

        $this->clears();
        $this->verifications->verify($repair->id, $issue->id);

        // Nothing outside the problem's area and what the repair named.
        self::assertSame(0, $elsewhere->runs);
    }

    // What may be verified -------------------------------------------------------------

    /**
     * @return array<string, array{Closure(self, Issue, Repair): array{int, int}, string}>
     */
    public static function unverifiable(): array
    {
        return [
            'a preview not carried out' => [static function(self $t, Issue $i): array {
                return [$t->repairs->prepare($i->id, 'test.repair')->id, $i->id];
            }, 'has not been carried out'],
            'a repair that failed part-way' => [static function(self $t, Issue $i, Repair $r): array {
                RepairRecord::updateAll(['status' => RepairStatus::FAILED->value, 'verificationStatus' => VerificationStatus::NONE->value], ['id' => $r->id]);

                return [$r->id, $i->id];
            }, 'did not finish cleanly'],
            'a repair superseded by a later one' => [static function(self $t, Issue $i, Repair $r): array {
                $t->repaired($t->reread($i));

                return [$r->id, $i->id];
            }, 'A later repair of this issue'],
            'an issue being repaired' => [static function(self $t, Issue $i, Repair $r): array {
                IssueRecord::updateAll(['status' => IssueStatus::REPAIRING->value], ['id' => $i->id]);

                return [$r->id, $i->id];
            }, 'being repaired now'],
            'another environment' => [static function(self $t, Issue $i, Repair $r): array {
                $t->verifications->environment = 'tests-elsewhere';

                return [$r->id, $i->id];
            }, 'has to be verified where it was carried out'],
            'another issue’s URL' => [static function(self $t, Issue $i, Repair $r): array {
                return [$r->id, $i->id + 100000];
            }, 'No such repair of this issue'],
            'a repair filed under another site' => [static function(self $t, Issue $i, Repair $r): array {
                RepairRecord::updateAll(['siteId' => $t->primarySiteId()], ['id' => $r->id]);

                return [$r->id, $i->id];
            }, 'No such repair of this issue'],
            'a repair carried out in another environment than its issue' => [static function(self $t, Issue $i, Repair $r): array {
                RepairRecord::updateAll(['environment' => 'tests-elsewhere'], ['id' => $r->id]);

                return [$r->id, $i->id];
            }, 'has to be verified where it was carried out'],
            'an issue from another environment' => [static function(self $t, Issue $i, Repair $r): array {
                IssueRecord::updateAll(['environment' => 'tests-elsewhere'], ['id' => $i->id]);

                return [$r->id, $i->id];
            }, 'has to be verified where it was carried out'],
            'a repair whose record cannot be read in full' => [static function(self $t, Issue $i, Repair $r): array {
                RepairRecord::updateAll(['verifyWith' => '"not a list"'], ['id' => $r->id]);

                return [$r->id, $i->id];
            }, 'cannot be read in full'],
        ];
    }

    /**
     * @param Closure(self, Issue, Repair): array{int, int} $arrange
     */
    #[DataProvider('unverifiable')]
    public function testOnlyTheLatestCleanlyCarriedOutRepairOfAnIssueHereCanBeVerifiedAndARefusalWritesNothing(Closure $arrange, string $said): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        [$repairId, $issueId] = $arrange($this, $issue, $repair);
        $before = WebDoctorTables::snapshot();
        $runs = $this->check->runs;

        try {
            $this->verifications->verify($repairId, $issueId);
            self::fail('A repair that cannot be verified was verified.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame($runs, $this->check->runs, 'A check ran for a verification that was refused.');
        self::assertSame($before, WebDoctorTables::snapshot());
    }

    /**
     * @return array<string, array{Closure(self): void, string}>
     */
    public static function unauthorised(): array
    {
        return [
            'nobody signed in' => [static function(self $t): void {
                Craft::$app->getUser()->setIdentity(null);
            }, 'signed in'],
            'without Run repairs' => [static function(self $t): void {
                $t->signIn(admin: false, permissions: [Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE, Permissions::INVESTIGATE_ISSUES, Permissions::MANAGE_ISSUES]);
            }, 'Run repairs'],
        ];
    }

    /**
     * @param Closure(self): void $as
     */
    #[DataProvider('unauthorised')]
    public function testTheServiceItselfRefusesSomebodyWhoMayNotVerify(Closure $as, string $said): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $as($this);
        $before = WebDoctorTables::snapshot();

        try {
            $this->verifications->verify($repair->id, $issue->id);
            self::fail('Somebody who may not verify a repair verified one.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame($before, WebDoctorTables::snapshot());
    }

    public function testVerifyingIsAControlPanelPostWithATokenFromSomebodyWhoMayRunRepairs(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $body = ['issueId' => (string)$issue->id, 'repairId' => (string)$repair->id];
        $before = WebDoctorTables::snapshot();

        $this->signIn(admin: false, permissions: ['accessCp', Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE, Permissions::INVESTIGATE_ISSUES]);
        $this->post($body);
        $this->refused(ForbiddenHttpException::class);

        $this->signIn(admin: true);
        $this->request('GET');
        $this->refused(MethodNotAllowedHttpException::class);

        $request = $this->request('POST');
        $request->setBodyParams($body);
        $this->refused(BadRequestHttpException::class);

        $this->post($body, cp: false);
        $this->refused(BadRequestHttpException::class);

        foreach (['12abc', '-1', '', '1.5'] as $malformed) {
            $this->post(['issueId' => (string)$issue->id, 'repairId' => $malformed]);
            $this->refused(BadRequestHttpException::class);
        }

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertSame(0, $this->check->runs);

        // Allowed, it verifies and says what it found.
        $this->clears();
        $this->post($body);
        $controller = $this->controller();
        $response = $controller->runAction('verify');

        $location = (string)$response->getHeaders()->get('Location');
        self::assertStringContainsString(sprintf('web-doctor/issues/%d/repairs/%d', $issue->id, $repair->id), $location);
        self::assertStringEndsWith('#verification', $location);
        self::assertSame(['level' => 'success', 'message' => 'Verified: the check that found the problem ran again and no longer reports it.'], $controller->lastFlash());
        self::assertCount(1, $this->verifications->forRepair($repair->id));
    }

    // Carrying out, then verifying -----------------------------------------------------

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function afterCarryingOut(): array
    {
        return [
            'a repair that worked' => [true, 'success', 'Repair carried out and verified'],
            'a repair that did not' => [false, 'fail', 'Repair completed, but verification failed'],
        ];
    }

    #[DataProvider('afterCarryingOut')]
    public function testCarryingOutARepairVerifiesItStraightAwayAndSaysSo(bool $works, string $level, string $said): void
    {
        $issue = $this->raise();
        $action = $this->repairActions->get('test.repair');
        self::assertInstanceOf(TestRepairAction::class, $action);

        if ($works) {
            $action->onExecute = fn() => $this->clears();
        }

        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $this->post(['issueId' => (string)$issue->id, 'repairId' => (string)$repair->id, 'confirm' => '1']);
        $controller = $this->controller();
        $controller->runAction('execute');

        $flash = $controller->lastFlash();
        self::assertNotNull($flash);
        self::assertSame($level, $flash['level']);
        self::assertStringContainsString($said, $flash['message']);
        self::assertCount(1, $this->verifications->forRepair($repair->id));
        self::assertSame($works ? IssueStatus::RESOLVED : IssueStatus::NEW, $this->reread($issue)->status);
    }

    public function testARepairThatCannotBeVerifiedStraightAwayStillStandsAsCarriedOutAwaitingVerification(): void
    {
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        // Verification is refused here: it runs as another environment than the repair's.
        $this->verifications->environment = 'tests-elsewhere';

        $this->post(['issueId' => (string)$issue->id, 'repairId' => (string)$repair->id, 'confirm' => '1']);
        $controller = $this->controller();
        $controller->runAction('execute');

        $flash = $controller->lastFlash();
        $stored = $this->repairs->get($repair->id);
        self::assertNotNull($flash);
        self::assertNotNull($stored);
        self::assertSame('success', $flash['level']);
        self::assertStringContainsString('not yet verified', $flash['message']);
        self::assertSame(VerificationStatus::PENDING, $stored->verificationStatus);
        self::assertSame(RepairStatus::SUCCEEDED, $stored->status);
    }

    // What is kept -----------------------------------------------------------------------

    public function testAVerificationThatCannotBeRecordedLeavesNothingOfItBehind(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();
        $failing = new class($this->verificationsConfig()) extends Verifications {
            protected function save(VerificationRecord $record): void
            {
                throw new RuntimeException('The verification row would not save.');
            }
        };

        try {
            $failing->verify($repair->id, $issue->id);
            self::fail('A verification that could not be recorded was reported as made.');
        } catch (RuntimeException $e) {
            self::assertSame('The verification row would not save.', $e->getMessage());
        }

        // The run itself was an observation like any other and stands; the verification does not.
        self::assertSame([], $this->verifications->forRepair($repair->id));
        self::assertSame(VerificationStatus::PENDING, $this->repairs->get($repair->id)?->verificationStatus);
        self::assertNotSame(IssueResolution::VERIFIED, $this->reread($issue)->resolution);
        self::assertFalse(IssueEventRecord::find()->where(['issueId' => $issue->id, 'type' => IssueEventType::REPAIR_VERIFIED->value])->exists());
    }

    public function testARepairKeepsItsMostRecentVerificationsOnly(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->verifications->maxPerRepair = 2;

        $ids = [];

        foreach ([1, 2, 3] as $n) {
            $ids[] = $this->verifications->verify($repair->id, $issue->id)->id;
        }

        self::assertSame([$ids[2], $ids[1]], array_map(static fn(Verification $v): int => $v->id, $this->verifications->forRepair($repair->id)));
    }

    public function testARowThatCannotBeReadIsReadAsInconclusiveNeverAsVerified(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();
        $verification = $this->verifications->verify($repair->id, $issue->id);

        VerificationRecord::updateAll(['result' => 'fixed', 'conditions' => '[{"id":"x","state":"held!"}]', 'checks' => '[{"diagnosticId":"test.check","status":"triumphant"}]'], ['id' => $verification->id]);
        $read = $this->verifications->get($verification->id);

        self::assertSame(VerificationStatus::INCONCLUSIVE, $read?->result);
        self::assertSame(VerificationCondition::UNDETERMINED, $read->conditions[0]->state);
        self::assertNull($read->checks[0]['status']);
    }

    public function testNoCredentialReachesAVerificationsRowItsIssuesHistoryOrItsPage(): void
    {
        $secret = 'hunter2-' . bin2hex(random_bytes(4));
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('fail', [
            'summary' => "SMTP refused: password=$secret",
            'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Mailer', 'test.check', ['password' => $secret, 'dsn' => "smtp://user:$secret@mail.example.com"])],
        ]);

        $verification = $this->verifications->verify($repair->id, $issue->id);

        $row = (new \craft\db\Query())->from(VerificationRecord::TABLE)->where(['id' => $verification->id])->one();
        self::assertStringNotContainsString($secret, (string)json_encode($row));
        self::assertStringNotContainsString($secret, (string)json_encode((new \craft\db\Query())->from(IssueEventRecord::TABLE)->where(['issueId' => $issue->id])->all()));

        $this->request('GET');
        $html = $this->render('detail', ['issueId' => $issue->id, 'repairId' => $repair->id]);
        self::assertStringNotContainsString($secret, $html);
        self::assertStringNotContainsString('[redacted]', $html);
    }

    // The repair page --------------------------------------------------------------------

    public function testTheRepairPageSaysWhenARepairWasCompletedButVerificationFailedAndWhoMaySeeWhat(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('fail', [
            'summary' => 'test.check reports a problem.',
            'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['value' => 'distinctive-internal-value'])],
        ]);
        $this->verifications->verify($repair->id, $issue->id);

        $this->request('GET');
        $html = $this->render('detail', ['issueId' => $issue->id, 'repairId' => $repair->id]);

        self::assertStringContainsString('Repair completed, but verification failed.', $html);
        self::assertStringContainsString('Verification failed', $html);
        self::assertStringContainsString('Still reports the problem being verified.', $html);
        self::assertStringContainsString('web-doctor/repairs/verify', $html);
        self::assertStringContainsString('Verify again', $html);
        self::assertStringContainsString('distinctive-internal-value', $html);

        // Somebody who may read the issue sees the answer and the checks, not the evidence, and is
        // offered nothing to press.
        $this->signIn(admin: false, permissions: ['accessCp', Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->request('GET');
        $html = $this->render('detail', ['issueId' => $issue->id, 'repairId' => $repair->id]);

        self::assertStringContainsString('Repair completed, but verification failed.', $html);
        self::assertStringNotContainsString('web-doctor/repairs/verify', $html);
        self::assertStringNotContainsString('distinctive-internal-value', $html);
        self::assertStringContainsString('needs the “Run repairs” or “View evidence” permission', $html);
    }


    // The verification actions: exactly one for every repair ---------------------------

    public function testEveryShippedRepairHasExactlyOneVerificationActionWrittenOut(): void
    {
        $registry = new VerificationActions(['includeCoreActions' => true]);
        $repairs = array_map(static fn($a): string => $a->id(), CoreRepairActions::all());
        sort($repairs);

        $mapped = array_keys(CoreVerificationActions::FOR_REPAIR);
        sort($mapped);

        // Every shipped repair, and nothing else, is mapped to its one action.
        self::assertSame($repairs, $mapped);

        foreach (CoreVerificationActions::FOR_REPAIR as $repair => $class) {
            $found = $registry->forRepairAction($repair);
            self::assertCount(1, $found, $repair);
            self::assertInstanceOf($class, $found[0], $repair);
            self::assertSame($repair, $found[0]->repairAction());
        }

        self::assertCount(count(CoreVerificationActions::FOR_REPAIR), $registry->all());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedVerificationActions(): array
    {
        return [
            'an ID that is not one' => [['actionId' => 'Not-an-id']],
            'a repair action that is not one' => [['verifies' => 'not an id']],
            'a second action for the same repair' => [['actionId' => 'test.another']],
            'an ID already taken' => [['verifies' => 'test.otherRepair']],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('refusedVerificationActions')]
    public function testAVerificationActionThatCannotBeTrustedIsRefusedAndTheFirstKeepsItsPlace(array $config): void
    {
        try {
            $this->verificationActions->register(new TestVerificationAction($config));
            self::fail('A verification action that should have been refused was registered.');
        } catch (\yii\base\InvalidArgumentException) {
        }

        self::assertSame([$this->verifier], $this->verificationActions->forRepairAction('test.repair'));
    }

    public function testARepairWhoseVerificationActionFailedToRegisterIsNeverVerified(): void
    {
        // The registry Web Doctor hands out, whose one action for the test repair cannot register.
        $registry = new class(['includeCoreActions' => true]) extends VerificationActions {
            protected function core(): array
            {
                return [new TestVerificationAction(['actionId' => 'Not-an-id'])];
            }
        };
        $this->verifications->actions = $registry;
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();

        self::assertSame([], $registry->all());

        $verification = $this->verifications->verify($repair->id, $issue->id);

        self::assertSame(VerificationStatus::INCONCLUSIVE, $verification->result);
        self::assertStringContainsString('No verification action is registered', implode(' | ', $verification->failures));
        self::assertNotSame(IssueStatus::RESOLVED, $this->reread($issue)->status);

        // An action written for a repair Web Doctor does not have makes no other repair verifiable.
        $this->verifications->actions = new VerificationActions();
        $this->verifications->actions->register(new TestVerificationAction(['actionId' => 'test.elsewhere', 'verifies' => 'test.noSuchRepair']));

        self::assertSame(VerificationStatus::INCONCLUSIVE, $this->verifications->verify($repair->id, $issue->id)->result);
    }

    // Who may verify, and against what ----------------------------------------------------

    public function testSomebodyWhoMayRunRepairsVerifiesThroughTheServiceWithoutBeingAnAdmin(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();
        $this->signIn(admin: false, permissions: [Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::RUN_REPAIRS]);

        $verification = $this->verifications->verify($repair->id, $issue->id);

        self::assertSame(VerificationStatus::VERIFIED, $verification->result, implode(' | ', $verification->failures));
    }

    public function testTheServiceFailsClosedRatherThanBuildingDependenciesOfItsOwn(): void
    {
        $loaded = Craft::$app->loadedModules;
        unset(Craft::$app->loadedModules[WebDoctor::class]);

        try {
            self::assertNull(WebDoctor::getInstance());

            try {
                (new Verifications())->verify(1, 1);
                self::fail('A verification ran without its dependencies.');
            } catch (\yii\base\InvalidConfigException) {
            }
        } finally {
            Craft::$app->loadedModules = $loaded;
        }

        // Read from the source, so a fallback added later is caught however it is spelled.
        foreach (['services/Verifications.php', 'services/VerificationActions.php', 'verifications/RetriedJobsSettled.php', 'verifications/StorageDirectoriesPresent.php', 'controllers/RepairsController.php'] as $file) {
            $source = (string)file_get_contents(dirname(__DIR__, 2) . "/src/$file");
            self::assertDoesNotMatchRegularExpression('/(\?\?=?|\?:)\s*new\s+(Verifications|VerificationActions|Repairs|RepairActions|Issues|Errors|EvidenceStore|Diagnostics|DiagnosticEngine|Permissions|WebDoctor)\b/', $source, $file);
        }
    }

    // Concurrency ------------------------------------------------------------------------

    public function testOnlyOneVerificationOfAnIssueRunsAtOnceAndTheOtherIsRefusedWritingNothing(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $before = WebDoctorTables::snapshot();
        $lock = 'web-doctor:verify:' . $issue->id;
        $mutex = Craft::$app->getMutex();

        self::assertTrue($mutex->acquire($lock));

        try {
            $this->verifications->verify($repair->id, $issue->id);
            self::fail('Two verifications of one issue ran at once.');
        } catch (Refusal $e) {
            self::assertStringContainsString('being verified now', $e->getMessage());
        } finally {
            $mutex->release($lock);
        }

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertSame(0, $this->check->runs);
    }

    /**
     * Something about the issue changing while the checks ran: the answer describes a state that has
     * gone, so it is recorded as inconclusive and applied to nothing.
     *
     * @return array<string, array{Closure(self, Issue): void, IssueStatus}>
     */
    public static function changedWhileVerifying(): array
    {
        return [
            'a run recorded a finding on it' => [static function(self $t, Issue $i): void {
                IssueRecord::updateAll(['latestRunId' => 'a-later-run'], ['id' => $i->id]);
            }, IssueStatus::NEW],
            'a repair of it began' => [static function(self $t, Issue $i): void {
                IssueRecord::updateAll(['status' => IssueStatus::REPAIRING->value], ['id' => $i->id]);
            }, IssueStatus::REPAIRING],
            'somebody decided where it stands' => [static function(self $t, Issue $i): void {
                IssueRecord::updateAll(['status' => IssueStatus::IGNORED->value, 'statusNote' => 'Not ours.'], ['id' => $i->id]);
            }, IssueStatus::IGNORED],
            'a later repair of it was carried out' => [static function(self $t, Issue $i): void {
                $record = RepairRecord::find()->where(['issueId' => $i->id])->one();
                self::assertInstanceOf(RepairRecord::class, $record);
                $later = new RepairRecord($record->getAttributes(null, ['id', 'uid', 'dateCreated', 'dateUpdated', 'lockKey']));
                $later->finishedAt = Db::prepareDateForDb(new DateTimeImmutable('+1 minute'));
                self::assertTrue($later->save());
            }, IssueStatus::NEW],
        ];
    }

    /**
     * @param Closure(self, Issue): void $change
     */
    #[DataProvider('changedWhileVerifying')]
    public function testAVerificationWhoseIssueChangedWhileItRanIsInconclusiveAndChangesNothing(Closure $change, IssueStatus $stays): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->check->handler = function(TestDiagnostic $d) use ($change, $issue): DiagnosticResult {
            $change($this, $issue);

            return $d->build('pass', ['summary' => 'Nothing wrong.', 'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'fine'])]]);
        };

        $verification = $this->verifications->verify($repair->id, $issue->id);
        $after = $this->reread($issue);

        self::assertSame(VerificationStatus::INCONCLUSIVE, $verification->result);
        self::assertStringContainsString('changed while this was being verified', implode(' | ', $verification->failures));
        self::assertSame($stays, $after->status);
        self::assertSame(IssueResolution::NONE, $after->resolution);
        self::assertSame(VerificationStatus::INCONCLUSIVE, $this->repairs->get($repair->id)?->verificationStatus);
    }

    public function testVerifyingAgainReplacesTheRepairsAnswerWithTheNewest(): void
    {
        $issue = $this->raise();
        $repair = $this->repaired($issue);

        self::assertSame(VerificationStatus::FAILED, $this->verifications->verify($repair->id, $issue->id)->result);

        $this->clears();
        self::assertSame(VerificationStatus::VERIFIED, $this->verifications->verify($repair->id, $issue->id)->result);
        self::assertSame(VerificationStatus::VERIFIED, $this->repairs->get($repair->id)?->verificationStatus);
        self::assertSame(IssueResolution::VERIFIED, $this->reread($issue)->resolution);

        // Found again: the newest answer, and the issue, say so.
        $this->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('fail', ['summary' => 'test.check reports a problem.', 'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'broken'])]]);
        self::assertSame(VerificationStatus::FAILED, $this->verifications->verify($repair->id, $issue->id)->result);
        self::assertSame(VerificationStatus::FAILED, $this->repairs->get($repair->id)?->verificationStatus);
        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        self::assertSame(IssueResolution::NONE, $this->reread($issue)->resolution);
    }


    public function testAVerificationHeldByAnotherProcessRefusesThisOneThroughCraftsOwnLock(): void
    {
        // A second PHP process — its own connection, its own mutex — holds the lock, so what refuses
        // this verification is the database's lock and nothing this process remembers.
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();
        $host = dirname(__DIR__, 4);
        $lock = var_export('web-doctor:verify:' . $issue->id, true);
        $code = "require '$host/bootstrap.php'; \$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php'; "
            . "\$m = Craft::\$app->getMutex(); if (!\$m->acquire($lock)) { echo \"busy\\n\"; exit(1); } echo \"locked\\n\"; "
            . "fgets(STDIN); \$m->release($lock); echo \"released\\n\";";
        $child = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        // Never waits for ever: a child that cannot say it holds the lock fails the test instead.
        stream_set_timeout($pipes[1], 30);
        stream_set_blocking($pipes[2], false);

        try {
            $said = fgets($pipes[1]);
            self::assertSame("locked\n", $said, 'The other process did not take the lock: ' . var_export($said, true) . ' ' . stream_get_contents($pipes[2]));
            $before = WebDoctorTables::snapshot();

            try {
                $this->verifications->verify($repair->id, $issue->id);
                self::fail('A verification ran while another process held the issue’s verification lock.');
            } catch (Refusal $e) {
                self::assertStringContainsString('being verified now', $e->getMessage());
            }

            self::assertSame($before, WebDoctorTables::snapshot());
            self::assertSame(0, $this->check->runs);

            fwrite($pipes[0], "\n");
            self::assertSame("released\n", fgets($pipes[1]));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($child);
        }

        // Released, the same verification goes ahead: the lock was what stopped it.
        self::assertSame(VerificationStatus::VERIFIED, $this->verifications->verify($repair->id, $issue->id)->result);
    }

    public function testAFailedVerificationIsNotAppliedToAnIssueWhoseNewRepairBeganWhileItRan(): void
    {
        // Even the answer that only keeps an issue open is not applied once a repair of it is under
        // way: what it found describes the issue before that repair, not the one being repaired.
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->check->handler = static function(TestDiagnostic $d) use ($issue): DiagnosticResult {
            IssueRecord::updateAll(['status' => IssueStatus::REPAIRING->value], ['id' => $issue->id]);

            return $d->build('fail', ['summary' => 'test.check reports a problem.', 'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'broken'])]]);
        };

        $verification = $this->verifications->verify($repair->id, $issue->id);

        self::assertSame(VerificationStatus::INCONCLUSIVE, $verification->result);
        self::assertStringContainsString('changed while this was being verified', implode(' | ', $verification->failures));
        self::assertSame(IssueStatus::REPAIRING, $this->reread($issue)->status);
        self::assertSame(VerificationStatus::INCONCLUSIVE, $this->repairs->get($repair->id)?->verificationStatus);
    }

    public function testTheIssueIsHeldLockedFromTheDecisionUntilItIsWritten(): void
    {
        // Between reading the issue to decide what stands and writing that down, nobody else may
        // change it: another connection trying to is made to wait. Tried from inside the decision,
        // on a second connection, with a one-second wait.
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->clears();
        $this->verifier(VerificationCondition::UNDETERMINED);
        $issues = new class(['evidence' => new EvidenceStore()]) extends Issues {
            public ?int $issueId = null;
            public ?int $repairId = null;
            public ?bool $blocked = null;

            /** @var array<string, bool> Which other writes were made to wait. */
            public array $waited = [];

            protected function logEvent(IssueRecord $record, IssueEventType $type, ?IssueStatus $from = null, ?IssueStatus $to = null, ?\Tahadudhiya\WebDoctor\enums\Severity $severity = null, ?string $note = null, ?string $runId = null, ?int $userId = null): void
            {
                if ($type === IssueEventType::REPAIR_VERIFIED && $this->blocked === null) {
                    $other = clone Craft::$app->getDb();
                    $other->open();
                    $other->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();

                    $waits = static function(callable $write): bool {
                        try {
                            $write();

                            return false;
                        } catch (\yii\db\Exception) {
                            return true;
                        }
                    };

                    try {
                        $this->blocked = $waits(fn() => $other->createCommand()->update(IssueRecord::TABLE, ['status' => IssueStatus::IGNORED->value], ['id' => $this->issueId])->execute());
                        // Another verification of the same repair being recorded, and the repair
                        // itself changing, wait for this decision too.
                        $this->waited['verification'] = $waits(fn() => $other->createCommand()->insert(VerificationRecord::TABLE, [
                            'repairId' => $this->repairId, 'diagnosticId' => 'test.check', 'action' => 'test.repair', 'environment' => 'tests-other',
                            'runId' => 'another', 'result' => VerificationStatus::FAILED->value,
                            'startedAt' => Db::prepareDateForDb(new DateTimeImmutable()), 'finishedAt' => Db::prepareDateForDb(new DateTimeImmutable()),
                            'dateCreated' => Db::prepareDateForDb(new DateTimeImmutable()), 'dateUpdated' => Db::prepareDateForDb(new DateTimeImmutable()), 'uid' => \craft\helpers\StringHelper::UUID(),
                        ])->execute());
                        $this->waited['repair'] = $waits(fn() => $other->createCommand()->update(RepairRecord::TABLE, ['verificationStatus' => VerificationStatus::FAILED->value], ['id' => $this->repairId])->execute());
                    } finally {
                        $other->close();
                    }
                }

                parent::logEvent($record, $type, $from, $to, $severity, $note, $runId, $userId);
            }
        };
        $issues->issueId = $issue->id;
        $issues->repairId = $repair->id;
        $this->verifications->issues = $issues;

        self::assertSame(VerificationStatus::INCONCLUSIVE, $this->verifications->verify($repair->id, $issue->id)->result);
        self::assertTrue($issues->blocked, 'Another connection changed the issue while the verification was deciding on it.');
        self::assertSame(['verification' => true, 'repair' => true], $issues->waited);
        self::assertCount(1, $this->verifications->forRepair($repair->id));
        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
    }

    public function testAVerificationOvertakenByAnotherOfTheSameRepairChangesNothing(): void
    {
        // Another verification of the same repair is recorded while this one runs — one that got past
        // the lock, as a process configured with another mutex could. Its answer is the newer one.
        $issue = $this->raise();
        $repair = $this->repaired($issue);
        $this->check->handler = function(TestDiagnostic $d) use ($repair, $issue): DiagnosticResult {
            $other = new VerificationRecord();
            $other->repairId = $repair->id;
            $other->issueId = $issue->id;
            $other->diagnosticId = 'test.check';
            $other->action = 'test.repair';
            $other->environment = $this->environment;
            $other->runId = 'another-verification';
            $other->result = VerificationStatus::FAILED->value;
            $other->startedAt = Db::prepareDateForDb(new DateTimeImmutable());
            $other->finishedAt = Db::prepareDateForDb(new DateTimeImmutable());
            self::assertTrue($other->save());
            RepairRecord::updateAll(['verificationStatus' => VerificationStatus::FAILED->value], ['id' => $repair->id]);

            return $d->build('pass', ['summary' => 'Nothing wrong.', 'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'fine'])]]);
        };

        $verification = $this->verifications->verify($repair->id, $issue->id);
        $after = $this->reread($issue);

        self::assertSame(VerificationStatus::INCONCLUSIVE, $verification->result);
        self::assertStringContainsString('Another verification of this repair was recorded while this one ran', implode(' | ', $verification->failures));
        // Neither the issue nor the repair's answer is this one's to change.
        self::assertSame(IssueStatus::NEW, $after->status);
        self::assertSame(IssueResolution::NONE, $after->resolution);
        self::assertSame(VerificationStatus::FAILED, $this->repairs->get($repair->id)?->verificationStatus);
        self::assertFalse(IssueEventRecord::find()->where(['issueId' => $issue->id, 'type' => IssueEventType::REPAIR_VERIFIED->value])->exists());
    }

    public function testAnotherIssueTheChecksFindClearIsResolvedAsAnyRunWouldWhileTheVerifiedOneWaitsForTheAnswer(): void
    {
        $nearby = $this->register('test.nearby', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('warning', ['summary' => 'Something nearby is wrong.']));
        $issue = $this->raise();
        $this->runChecks([$nearby]);
        $other = IssueRecord::find()->where(['diagnosticId' => 'test.nearby', 'environment' => $this->environment])->one();
        self::assertInstanceOf(IssueRecord::class, $other);
        $repair = $this->repaired($issue);
        $nearby->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', ['summary' => 'Fine now.']);
        $this->clears();
        // What the repair should have left true cannot be told: the verified issue is not resolved,
        // though its check went quiet — and the nearby one, which nothing is verifying, is.
        $this->verifier(VerificationCondition::UNDETERMINED);

        self::assertSame(VerificationStatus::INCONCLUSIVE, $this->verifications->verify($repair->id, $issue->id)->result);
        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        self::assertSame(IssueStatus::RESOLVED->value, IssueRecord::findOne($other->id)->status);
        self::assertSame(IssueResolution::OBSERVED_CLEAR->value, IssueRecord::findOne($other->id)->resolution);
    }

    public function testAFailedVerificationOfAnOpenIssueLeavesItOpenThoughItsCheckWentQuiet(): void
    {
        $issue = $this->raise();
        $this->issues->transition($issue->id, IssueStatus::CONFIRMED);
        $repair = $this->repaired($issue);
        $this->clears();
        $this->verifier(VerificationCondition::NOT_HELD);

        self::assertSame(VerificationStatus::FAILED, $this->verifications->verify($repair->id, $issue->id)->result);
        self::assertSame(IssueStatus::CONFIRMED, $this->reread($issue)->status);
        self::assertSame(IssueResolution::NONE, $this->reread($issue)->resolution);
    }

    // Errors -----------------------------------------------------------------------------

    public function testAnErrorAlreadySeenBeforeTheRepairIsNotNew(): void
    {
        $nearby = $this->register('test.nearby', static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', [
            'summary' => 'Fine, having caught something.',
            'evidence' => [Evidence::fromThrowable(new RuntimeException('Caught along the way.'), 'test.nearby')],
        ]));
        $issue = $this->raise();
        $run = (new DiagnosticEngine(['registry' => $this->registry]))->runMany([$nearby], new DiagnosticContext(environment: $this->environment));
        (new Errors(['issues' => $this->issues]))->record($run);
        ErrorGroupRecord::updateAll(['firstSeen' => Db::prepareDateForDb(new DateTimeImmutable('-1 day'))], ['environment' => $this->environment]);
        $repair = $this->repaired($issue);
        $this->clears();

        $verification = $this->verifications->verify($repair->id, $issue->id);

        self::assertSame(VerificationStatus::VERIFIED, $verification->result, implode(' | ', $verification->failures));
        self::assertCount(1, $verification->errors);
        self::assertFalse($verification->errors[0]['new']);
    }

    // Retention --------------------------------------------------------------------------

    public function testARepairKeepsItsTwentyNewestVerificationsAndAnotherRepairsAreUntouched(): void
    {
        [$first, $second] = $this->raiseEach(['one', 'two']);
        $repairOfFirst = $this->repaired($first);
        $repairOfSecond = $this->repaired($second);
        $theOther = $this->verifications->verify($repairOfSecond->id, $second->id)->id;
        $ids = [];

        for ($n = 0; $n < 21; $n++) {
            $ids[] = $this->verifications->verify($repairOfFirst->id, $first->id)->id;

            if ($n === 19) {
                self::assertCount(20, $this->verifications->forRepair($repairOfFirst->id, 50));
            }
        }

        $kept = array_map(static fn(Verification $v): int => $v->id, $this->verifications->forRepair($repairOfFirst->id, 50));

        self::assertCount(20, $kept);
        self::assertNotContains($ids[0], $kept);
        self::assertContains($ids[20], $kept);
        self::assertSame([$theOther], array_map(static fn(Verification $v): int => $v->id, $this->verifications->forRepair($repairOfSecond->id)));
        self::assertFalse(VerificationRecord::find()->where(['repairId' => $repairOfFirst->id, 'id' => $ids[0]])->exists());
    }

    // Queue: whether a retried job ran is Craft's to say -------------------------------------

    /**
     * Every way a retried job can stand when its repair is verified, with the real repair, the real
     * checks and Craft's own queue running the jobs.
     *
     * @return array<string, array{list<bool>, Closure(self, StubQueue, list<int>): void, VerificationStatus, string|null}>
     */
    public static function retriedJobs(): array
    {
        return [
            'it ran without an error' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
            }, VerificationStatus::VERIFIED, null],
            'it failed again' => [[true], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
            }, VerificationStatus::FAILED, 'Failed again: #'],
            'it is still waiting' => [[false], static function(): void {
            }, VerificationStatus::INCONCLUSIVE, 'Still waiting to run'],
            'it was released by hand' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                $q->release((string)$ids[0]);
            }, VerificationStatus::INCONCLUSIVE, 'nothing shows they ran successfully'],
            'its row went and nothing says why' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                Craft::$app->getDb()->createCommand()->delete($q->tableName, ['id' => $ids[0]])->execute();
            }, VerificationStatus::INCONCLUSIVE, 'nothing shows they ran successfully'],
            'it ran, but the note of it was lost' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
                Craft::$app->getCache()->flush();
            }, VerificationStatus::INCONCLUSIVE, 'nothing shows they ran successfully'],
            'it ran under a worker whose cache is not this one' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                $shared = Craft::$app->getCache();
                Craft::$app->set('cache', new \yii\caching\ArrayCache());

                try {
                    $q->executeJob((string)$ids[0]);
                } finally {
                    Craft::$app->set('cache', $shared);
                }
            }, VerificationStatus::INCONCLUSIVE, 'nothing shows they ran successfully'],
            'it ran after somebody else retried it later' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                $t->moveRepair(-3600);
                $q->executeJob((string)$ids[0]);
            }, VerificationStatus::INCONCLUSIVE, 'nothing shows they ran successfully'],
            'it ran, retried before this repair began' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                $t->moveRepair(3600);
                $q->executeJob((string)$ids[0]);
            }, VerificationStatus::INCONCLUSIVE, 'nothing shows they ran successfully'],
            'the queue cannot be read' => [[false], static function(self $t, StubQueue $q): void {
                $broken = new StubQueue();
                $broken->tableName = '{{%wdtest_no_such_queue}}';
                $broken->channel = 'wdtest';
                $t->retriedJobsReadFrom($broken);
            }, VerificationStatus::INCONCLUSIVE, 'The queue could not be read'],
            'the repair’s record of what it retried is malformed' => [[false], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
                $t->spoilOutcome();
            }, VerificationStatus::INCONCLUSIVE, 'cannot be read from its record'],
            'two ran without an error' => [[false, false], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
                $q->executeJob((string)$ids[1]);
            }, VerificationStatus::VERIFIED, null],
            'one of two failed again' => [[false, true], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
                $q->executeJob((string)$ids[1]);
            }, VerificationStatus::FAILED, 'Failed again: #'],
            'one of two has not run' => [[false, false], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
            }, VerificationStatus::INCONCLUSIVE, 'Still waiting to run'],
            'one of two was released by hand' => [[false, false], static function(self $t, StubQueue $q, array $ids): void {
                $q->executeJob((string)$ids[0]);
                $q->release((string)$ids[1]);
            }, VerificationStatus::INCONCLUSIVE, 'nothing shows they ran successfully'],
        ];
    }

    /**
     * @param list<bool> $failing Whether each job fails when it runs.
     * @param Closure(self, StubQueue, list<int>): void $then What happens to the jobs after the retry.
     */
    #[DataProvider('retriedJobs')]
    public function testARetriedJobIsVerifiedOnlyOnCraftsWordThatItRan(array $failing, Closure $then, VerificationStatus $expected, ?string $said): void
    {
        $queue = $this->queue();
        $ids = array_map(fn(bool $fails): int => $this->failedJob($queue, $fails), $failing);
        [$issue, $repair] = $this->retried($queue, $ids);

        $then($this, $queue, $ids);
        $verification = $this->verifications->verify($repair->id, $issue->id);
        $after = $this->reread($issue);

        self::assertSame($expected, $verification->result, implode(' | ', $verification->failures));

        if ($said !== null) {
            self::assertStringContainsString($said, implode(' | ', $verification->failures));
        }

        if ($expected === VerificationStatus::VERIFIED) {
            self::assertSame([], $verification->failures);
            self::assertSame(IssueStatus::RESOLVED, $after->status);
            self::assertSame(IssueResolution::VERIFIED, $after->resolution);
        } else {
            self::assertNotSame(IssueStatus::RESOLVED, $after->status);
            self::assertNotSame(IssueResolution::VERIFIED, $after->resolution);
        }
    }

    public function testAJobNoRepairRetriedLeavesNothingInTheCacheWhenItRuns(): void
    {
        // The queue signal is heard for every job the site runs; it writes only for one a repair
        // noted, so an installation's queue costs Web Doctor a cache read per job and nothing more.
        $queue = $this->queue();
        $id = (int)$queue->push(new QueueTestJob());
        $key = static fn(): array => array_filter(
            [Craft::$app->getCache()->get('web-doctor:retried-job:v1:' . hash('sha256', implode('|', [$queue->tableName, \Tahadudhiya\WebDoctor\diagnostics\queue\QueueDiagnostic::channelOf($queue), $queue->db->dsn])) . ':' . $id)],
        );

        $queue->executeJob((string)$id);

        self::assertSame([], $key());
        self::assertSame([], $this->row($queue, $id));
    }

    // Storage: a directory the repair made, where it made it -------------------------------

    /**
     * @return array<string, array{Closure(self, string): void, VerificationStatus, string|null}>
     */
    public static function createdDirectories(): array
    {
        return [
            'still there and writable' => [static function(): void {
            }, VerificationStatus::VERIFIED, null],
            'gone again' => [static function(self $t, string $root): void {
                rmdir("$root/storage/runtime");
            }, VerificationStatus::FAILED, 'still reports it'],
            'a file where the directory was' => [static function(self $t, string $root): void {
                rmdir("$root/storage/runtime");
                file_put_contents("$root/storage/runtime", 'not a directory');
            }, VerificationStatus::FAILED, 'Not there as a directory: runtime'],
            'there but not writable' => [static function(self $t, string $root): void {
                chmod("$root/storage/runtime", 0555);
            }, VerificationStatus::FAILED, 'Not writable: runtime'],
            'cannot be looked at' => [static function(self $t, string $root): void {
                chmod($root, 0000);
            }, VerificationStatus::INCONCLUSIVE, 'could not answer'],
            'Craft now names another path for it' => [static function(self $t, string $root): void {
                mkdir("$root/elsewhere");
                $t->storagePaths(['storage' => "$root/storage", 'runtime' => "$root/elsewhere"]);
            }, VerificationStatus::INCONCLUSIVE, 'no longer at the path the repair created'],
            'the check cannot be read' => [static function(self $t): void {
                $t->storageBreaks();
            }, VerificationStatus::INCONCLUSIVE, 'could not answer'],
        ];
    }

    /**
     * @param Closure(self, string): void $then
     */
    #[DataProvider('createdDirectories')]
    public function testCreatedDirectoriesAreVerifiedThereWhereTheRepairMadeThemAndWritable(Closure $then, VerificationStatus $expected, ?string $said): void
    {
        $root = $this->directory();
        mkdir("$root/storage");
        $this->storagePaths(['storage' => "$root/storage", 'runtime' => "$root/storage/runtime"]);
        $issue = $this->raiseFromCheck($this->storage);
        $repair = $this->repairs->execute($this->repairs->prepare($issue->id, CreateStorageDirectories::ID)->id, $issue->id, true);
        self::assertSame(RepairStatus::SUCCEEDED, $repair->status, (string)$repair->failure);

        try {
            $then($this, $root);
            $verification = $this->verifications->verify($repair->id, $issue->id);
        } finally {
            @chmod($root, 0755);
            @chmod("$root/storage", 0755);
            @chmod("$root/storage/runtime", 0755);
        }

        self::assertSame($expected, $verification->result, implode(' | ', $verification->failures));

        if ($said !== null) {
            self::assertStringContainsString($said, implode(' | ', $verification->failures));
        }

        self::assertSame($expected === VerificationStatus::VERIFIED, $this->reread($issue)->status === IssueStatus::RESOLVED);
    }

    // The verification actions Web Doctor ships with -------------------------------------

    public function testTheDirectoriesARepairCreatedAreCheckedStillThereWhereItMadeThemAndWritable(): void
    {
        $root = $this->directory();
        mkdir("$root/storage");
        file_put_contents("$root/file", 'not a directory');
        $paths = ['storage' => "$root/storage", 'logs' => "$root/logs", 'runtime' => "$root/file", 'compiledTemplates' => 'relative/path'];
        $action = new StorageDirectoriesPresent(['check' => $this->storageCheck($paths)]);
        $conditions = static fn(array $created, ?array $before = null): array => array_map(
            static fn(VerificationCondition $c): string => $c->id . ':' . $c->state,
            $action->conditions(self::repairWith(CreateStorageDirectories::ID, ['created' => $created], ['toCreate' => $before ?? $paths])),
        );

        self::assertSame(['directoriesPresent:held', 'directoriesWritable:held'], $conditions(['storage']));
        self::assertSame(['directoriesPresent:notHeld', 'directoriesWritable:held'], $conditions(['storage', 'logs']));
        self::assertSame(['directoriesPresent:notHeld', 'directoriesWritable:held'], $conditions(['storage', 'runtime']));
        self::assertSame(['directoriesPresent:undetermined', 'directoriesWritable:undetermined'], $conditions(['somethingElse']));
        // A path that is not absolute, or not the one the repair recorded, is not taken on trust.
        self::assertSame(['directoriesPresent:undetermined', 'directoriesWritable:undetermined'], $conditions(['compiledTemplates']));
        self::assertSame(['directoriesPresent:undetermined', 'directoriesWritable:undetermined'], $conditions(['storage'], ['storage' => "$root/elsewhere"]));
        self::assertSame(['directoriesPresent:undetermined', 'directoriesWritable:undetermined'], $conditions(['storage'], []));

        chmod("$root/storage", 0555);
        self::assertSame(['directoriesPresent:held', 'directoriesWritable:notHeld'], $conditions(['storage']));
        chmod("$root/storage", 0755);

        // Where the record does not say what was created, nothing is taken to hold.
        foreach ([null, ['created' => 'storage'], ['created' => []], ['created' => [1]]] as $outcome) {
            self::assertSame(['directoriesPresent:undetermined'], array_map(
                static fn(VerificationCondition $c): string => $c->id . ':' . $c->state,
                $action->conditions(self::repairWith(CreateStorageDirectories::ID, $outcome, ['toCreate' => $paths])),
            ), (string)json_encode($outcome));
        }
    }

    public function testARetriedJobThatLeftTheQueueIsNeverTakenToHaveRunOnItsAbsenceAlone(): void
    {
        $queue = $this->queue();
        $waiting = $this->job($queue, fail: false);
        $failedAgain = $this->job($queue, fail: true);
        $gone = 999999;
        $action = new RetriedJobsSettled(['queue' => $queue]);
        $conditions = static fn(array|string $retried): array => array_map(
            static fn(VerificationCondition $c): string => $c->id . ':' . $c->state,
            $action->conditions(self::repairWith(RetryFailedJobs::ID, ['retried' => $retried])),
        );

        self::assertSame(['noneFailedAgain:held', 'jobsRan:undetermined'], $conditions([$gone]));
        self::assertSame(['noneFailedAgain:held', 'jobsRan:undetermined'], $conditions([$waiting]));
        self::assertSame(['noneFailedAgain:notHeld'], $conditions([$failedAgain]));
        self::assertSame(['noneFailedAgain:notHeld', 'jobsRan:undetermined'], $conditions([$gone, $failedAgain]));
        self::assertSame(['jobsRan:undetermined'], $conditions([]));
        self::assertSame(['jobsRan:undetermined'], $conditions('everything'));
        self::assertSame(['jobsRan:undetermined'], $conditions(['7']));

        // Another queue's job with the same ID is not this queue's, and says nothing about this one.
        $other = $this->job($queue, fail: true, channel: 'another');
        self::assertSame(['noneFailedAgain:held', 'jobsRan:undetermined'], $conditions([$other]));
    }

    // Helpers ----------------------------------------------------------------------------

    /**
     * A repair of the given kind whose record says it did what the outcome states.
     *
     * @param array<string, mixed>|null $outcome
     */
    private static function repairWith(string $action, ?array $outcome, array $before = []): Repair
    {
        $record = new RepairRecord();
        $record->id = 1;
        $record->action = $action;
        $record->status = RepairStatus::SUCCEEDED->value;
        $record->startedAt = Db::prepareDateForDb(new DateTimeImmutable('-1 minute'));
        $record->finishedAt = Db::prepareDateForDb(new DateTimeImmutable());
        $record->preview = Evidence::encode(new RepairReport('Would do.', [], [new Evidence(EvidenceType::SYSTEM, 'Before', $action, $before)]));
        $record->outcome = $outcome === null ? null : Evidence::encode(new RepairReport('Done.', [], [new Evidence(EvidenceType::SYSTEM, 'After', $action, $outcome)]));

        return Repair::fromRecord($record);
    }

    public function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * Issues of the test check, one per affected component, raised by one run.
     *
     * @param list<string> $components
     * @return list<Issue>
     */
    private function raiseEach(array $components): array
    {
        $context = new DiagnosticContext(environment: $this->environment);
        $now = new DateTimeImmutable();
        $results = array_map(fn(string $component): DiagnosticResult => (new DiagnosticResult(
            diagnosticId: 'test.check',
            name: 'Check test.check',
            category: DiagnosticCategory::CONFIGURATION,
            status: DiagnosticStatus::FAIL,
            summary: "test.check reports a problem with $component.",
            evidence: [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'broken'])],
            affectedComponent: $component,
        ))->withExecution($context, $now, $now, 1.0), $components);

        $this->issues->reconcile(new DiagnosticRun(context: $context, results: $results, startedAt: $now, finishedAt: $now, durationMs: 1.0));

        return array_map(function(DiagnosticResult $result): Issue {
            $issue = $this->issues->getByFingerprint(Fingerprint::forResult($result, $this->environment, null));
            self::assertInstanceOf(Issue::class, $issue);

            return $issue;
        }, $results);
    }

    /**
     * An issue raised by running a real check here, as a dashboard run would.
     */
    private function raiseFromCheck(\Tahadudhiya\WebDoctor\base\DiagnosticInterface $check): Issue
    {
        $context = new DiagnosticContext(environment: $this->environment);
        $run = (new DiagnosticEngine(['registry' => $this->registry]))->runMany([$check], $context);
        $result = $run->resultFor($check->id());
        self::assertNotNull($result);
        self::assertTrue($result->status->isProblem(), 'The check did not report the problem the test set up: ' . $result->summary);
        $this->issues->reconcile($run);

        $issue = $this->issues->getByFingerprint(Fingerprint::forResult($result, $this->environment, null));
        self::assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    /** The repair most recently carried out, as though it had run that many seconds from now. */
    public function moveRepair(int $seconds): void
    {
        $record = RepairRecord::find()->where(['environment' => $this->environment])->orderBy(['id' => SORT_DESC])->one();
        self::assertInstanceOf(RepairRecord::class, $record);
        $at = (new DateTimeImmutable())->modify(sprintf('%+d seconds', $seconds));
        RepairRecord::updateAll([
            'startedAt' => Db::prepareDateForDb($at),
            'finishedAt' => Db::prepareDateForDb($at->modify('+1 second')),
        ], ['id' => $record->id]);
    }

    /** The repair most recently carried out, with a record of what it did that says nothing usable. */
    public function spoilOutcome(): void
    {
        $record = RepairRecord::find()->where(['environment' => $this->environment])->orderBy(['id' => SORT_DESC])->one();
        self::assertInstanceOf(RepairRecord::class, $record);
        RepairRecord::updateAll(['outcome' => Evidence::encode(new RepairReport('Done.', [], [new Evidence(EvidenceType::QUEUE, 'After', RetryFailedJobs::ID, ['retried' => 'everything'])]))], ['id' => $record->id]);
    }

    /** The retried-jobs verification action reads another queue from now on. */
    public function retriedJobsReadFrom(StubQueue $queue): void
    {
        foreach ($this->verificationActions->forRepairAction(RetryFailedJobs::ID) as $action) {
            self::assertInstanceOf(RetriedJobsSettled::class, $action);
            $action->queue = $queue;
        }
    }

    /**
     * A real job in the test's queue, failed as Craft records a failure, which fails again when it
     * runs where the test says so.
     */
    private function failedJob(StubQueue $queue, bool $fails): int
    {
        $id = (int)$queue->push(new QueueTestJob(['fails' => $fails]));
        Craft::$app->getDb()->createCommand()->update($queue->tableName, [
            'attempt' => 1,
            'fail' => true,
            'dateFailed' => Db::prepareDateForDb(new DateTimeImmutable('-1 minute')),
            'error' => 'Connection refused',
        ], ['id' => $id])->execute();

        return $id;
    }

    /**
     * The failed-jobs issue raised by the real check over the test's queue, and the real retry of it
     * carried out, with the real verification action watching the same queue.
     *
     * @param list<int> $ids
     * @return array{Issue, Repair}
     */
    private function retried(StubQueue $queue, array $ids): array
    {
        $failed = new \Tahadudhiya\WebDoctor\diagnostics\queue\FailedJobsDiagnostic(['queue' => $queue]);
        $this->registry->register($failed);
        $this->registry->register(new \Tahadudhiya\WebDoctor\diagnostics\queue\QueueBacklogDiagnostic(['queue' => $queue]));
        $this->repairActions->register(new RetryFailedJobs(['queue' => $queue]));
        $this->verificationActions->register(new RetriedJobsSettled(['queue' => $queue]));
        $issue = $this->raiseFromCheck($failed);

        $repair = $this->repairs->execute($this->repairs->prepare($issue->id, RetryFailedJobs::ID)->id, $issue->id, true, ['safeToRepeat', 'causeDealtWith']);

        self::assertSame(RepairStatus::SUCCEEDED, $repair->status, (string)$repair->failure);
        self::assertSame($ids, $repair->outcome?->state[0]->data['retried'] ?? null);

        return [$issue, $repair];
    }

    /**
     * The storage check, the storage repair and its verification action, all looking at the paths
     * given — set up once, and pointed elsewhere by calling again.
     *
     * @param array<string, string> $paths
     */
    public function storagePaths(array $paths): void
    {
        if ($this->storage !== null) {
            $this->storage->stated = $paths;

            return;
        }

        $this->storage = new StatedStoragePaths(['stated' => $paths]);
        $this->registry->register($this->storage);
        $this->repairActions->register(new CreateStorageDirectories(['check' => $this->storage]));
        $this->verificationActions->register(new StorageDirectoriesPresent(['check' => $this->storage]));
    }

    /** From now on the storage check cannot read where Craft's storage is. */
    public function storageBreaks(): void
    {
        self::assertNotNull($this->storage);
        $this->storage->breaks = true;
    }

    public function register(string $id, Closure $handler, DiagnosticCategory $category = DiagnosticCategory::CONFIGURATION): TestDiagnostic
    {
        $check = new TestDiagnostic(['diagnosticId' => $id, 'diagnosticName' => "Check $id", 'diagnosticCategory' => $category, 'handler' => $handler]);
        $this->registry->register($check);

        return $check;
    }

    /** Takes a check out of the registry the verification reads, as uninstalling its plugin would. */
    public function unregister(string $id): void
    {
        $remaining = array_filter($this->registry->all(), static fn($d): bool => $d->id() !== $id);
        $this->registry = new class() extends Diagnostics {
            public function hasEventHandlers($name): bool
            {
                return false;
            }
        };

        foreach ($remaining as $check) {
            $this->registry->register($check);
        }

        $this->verifications->registry = $this->registry;
        $this->verifications->engine = new DiagnosticEngine(['registry' => $this->registry]);
    }

    /** From now on the check that found the problem no longer reports it. */
    public function clears(): void
    {
        $this->check->handler = static fn(TestDiagnostic $d): DiagnosticResult => $d->build('pass', [
            'summary' => 'Nothing wrong.',
            'evidence' => [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'fine'])],
        ]);
    }

    /** What the test repair's verification action answers from now on. */
    public function verifier(string $state, bool $throws = false): void
    {
        $this->verifier->state = $state;
        $this->verifier->throws = $throws;
    }

    /**
     * The test check's problem, raised the way a run raises one.
     *
     * @param list<Evidence> $evidence
     */
    private function raise(?array $evidence = null): Issue
    {
        $evidence ??= [new Evidence(EvidenceType::CONFIGURATION, 'Target', 'test.check', ['state' => 'broken'])];

        $context = new DiagnosticContext(environment: $this->environment);
        $now = new DateTimeImmutable();
        $result = (new DiagnosticResult(
            diagnosticId: 'test.check',
            name: 'Check test.check',
            category: DiagnosticCategory::CONFIGURATION,
            status: DiagnosticStatus::FAIL,
            summary: 'test.check reports a problem.',
            evidence: $evidence,
        ))->withExecution($context, $now, $now, 1.0);

        $this->issues->reconcile(new DiagnosticRun(context: $context, results: [$result], startedAt: $now, finishedAt: $now, durationMs: 1.0));

        $issue = $this->issues->getByFingerprint(Fingerprint::forResult($result, $this->environment, null));
        self::assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    /**
     * The checks given — the test check unless stated — run here and reconciled, as a dashboard run
     * between the repair and its verification would be.
     *
     * @param list<TestDiagnostic>|null $checks
     */
    private function runChecks(?array $checks = null): void
    {
        $run = (new DiagnosticEngine(['registry' => $this->registry]))->runMany($checks ?? [$this->check], new DiagnosticContext(environment: $this->environment));
        $this->issues->reconcile($run);
    }

    /**
     * The test repair of an issue, previewed and carried out.
     *
     * @param array<string, mixed> $config
     */
    public function repaired(Issue $issue, array $config = []): Repair
    {
        $action = $this->repairActions->get('test.repair');
        self::assertInstanceOf(TestRepairAction::class, $action);

        foreach ($config as $property => $value) {
            $action->$property = $value;
        }

        $repair = $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);
        self::assertSame(RepairStatus::SUCCEEDED, $repair->status);

        return $repair;
    }

    public function reread(Issue $issue): Issue
    {
        $fresh = $this->issues->get($issue->id);
        self::assertInstanceOf(Issue::class, $fresh);

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function verificationsConfig(): array
    {
        return [
            'repairs' => $this->repairs,
            'actions' => $this->verificationActions,
            'registry' => $this->registry,
            'engine' => new DiagnosticEngine(['registry' => $this->registry]),
            'issues' => $this->issues,
            'errors' => new Errors(['issues' => $this->issues]),
            'evidence' => new EvidenceStore(),
            'environment' => $this->environment,
        ];
    }

    private function verificationsService(): Verifications
    {
        return new Verifications($this->verificationsConfig());
    }

    /**
     * @param array<string, string> $paths
     */
    private function storageCheck(array $paths): StoragePathsDiagnostic
    {
        return new class($paths) extends StoragePathsDiagnostic {
            /**
             * @param array<string, string> $stated
             */
            public function __construct(private array $stated)
            {
                parent::__construct();
            }

            public function paths(): array
            {
                return $this->stated;
            }
        };
    }

    private function directory(): string
    {
        $this->directory = sys_get_temp_dir() . '/webdoctor-verify-' . bin2hex(random_bytes(5));
        mkdir($this->directory);

        return $this->directory;
    }

    /**
     * Craft's own queue over a table of the test's own with the real queue's shape.
     */
    private function queue(): StubQueue
    {
        $db = Craft::$app->getDb();

        if (!$db->getIsMysql()) {
            self::markTestSkipped('Copies the queue table with MySQL’s CREATE TABLE … LIKE.');
        }

        $this->queueTable = '{{%wdtest_queue_' . bin2hex(random_bytes(4)) . '}}';
        $db->createCommand(sprintf('CREATE TABLE %s LIKE %s', $db->quoteTableName($this->queueTable), $db->quoteTableName('{{%queue}}')))->execute();

        $queue = new StubQueue();
        $queue->tableName = $this->queueTable;
        $queue->channel = 'wdtest';

        return $queue;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(StubQueue $queue, int $id): array
    {
        return (new \craft\db\Query())->from($queue->tableName)->where(['id' => $id])->one() ?: [];
    }

    private function job(StubQueue $queue, bool $fail, string $channel = 'wdtest'): int
    {
        Craft::$app->getDb()->createCommand()->insert($queue->tableName, [
            'channel' => $channel,
            'job' => 'x',
            'description' => 'A job',
            'timePushed' => time() - 3600,
            'ttr' => 300,
            'attempt' => $fail ? 2 : null,
            'fail' => $fail,
            'dateFailed' => $fail ? Db::prepareDateForDb(new DateTimeImmutable('-1 minute')) : null,
            'error' => $fail ? 'Connection refused' : null,
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @param list<string> $permissions
     */
    public function signIn(bool $admin, array $permissions = []): User
    {
        $user = new TestUser();
        $user->id = 1;
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;

        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    /**
     * A request shaped the way Craft would see one; see IssueCenterTest for why the control panel
     * flag is stated outright.
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
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor/issues" : '/actions/web-doctor/repairs/verify';
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
     * @param array<string, mixed> $params
     */
    private function post(array $params = [], bool $cp = true): WebRequest
    {
        $request = $this->request('POST', $cp);
        $request->setBodyParams($params + [$request->csrfParam => $request->getCsrfToken()]);

        return $request;
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function refused(string $expected): void
    {
        try {
            $this->controller()->runAction('verify');
            self::fail("A request that should have been refused with $expected was not.");
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e, $e->getMessage());
        }
    }

    private function controller(): RecordingRepairsController
    {
        return new RecordingRepairsController('repairs', $this->plugin);
    }

    /**
     * Renders the repair page the controller actually produced, through the real template, leaving
     * out only Craft's own control panel shell.
     *
     * @param array<string, mixed> $params
     */
    private function render(string $action, array $params): string
    {
        $response = $this->controller()->runAction($action, $params);

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return Craft::$app->getView()->renderTemplate('web-doctor/_repairs/_repair', $behavior->variables, View::TEMPLATE_MODE_CP);
    }
}
