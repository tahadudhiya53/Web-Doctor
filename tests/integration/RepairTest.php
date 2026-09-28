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
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\diagnostics\queue\FailedJobsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\IssueEventType;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\Fingerprint;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\records\RepairRecord;
use Tahadudhiya\WebDoctor\repairs\CoreRepairActions;
use Tahadudhiya\WebDoctor\repairs\CreateStorageDirectories;
use Tahadudhiya\WebDoctor\repairs\RetryFailedJobs;
use Tahadudhiya\WebDoctor\rules\RecommendationRules;
use Tahadudhiya\WebDoctor\services\EvidenceStore;
use Tahadudhiya\WebDoctor\services\Issues;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\services\RepairActions;
use Tahadudhiya\WebDoctor\services\Repairs;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingIssuesController;
use Tahadudhiya\WebDoctor\Tests\_support\RecordingRepairsController;
use Tahadudhiya\WebDoctor\Tests\_support\StubQueue;
use Tahadudhiya\WebDoctor\Tests\_support\TestRepairAction;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\Tests\_support\WebDoctorTables;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

/**
 * Repairing an issue, end to end: which actions are offered and why, what previewing reads and
 * keeps, every check made before anything is changed, carrying one out and what it leaves behind,
 * failure, duplicate execution, who may do any of it, and what the pages render.
 *
 * The safety model is exercised with an action whose every answer the test states, so each check
 * can be made to fail on its own. The two actions Web Doctor ships with are exercised against a
 * queue table and a directory of the test's own, so a real retry and a real directory creation
 * happen without touching the installation's queue or storage.
 *
 * Every test runs under an environment name of its own and removes only that environment's rows.
 */
class RepairTest extends TestCase
{
    private const ACCESS_CP = 'accessCp';

    private string $environment;
    private RepairActions $actions;
    private Issues $issues;
    private Repairs $repairs;
    private WebDoctor $plugin;
    private ?string $queueTable = null;

    /** @var list<string> Further queue tables a test copied, dropped with the first. */
    private array $extraTables = [];

    /** How often the failing service wrote a repair's ending. */
    private int $endWrites = 0;

    /** The action a test registered most recently, for a provider to change. */
    private ?TestRepairAction $lastAction = null;
    private ?string $directory = null;
    private ?Component $originalRequest = null;
    private ?Component $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Craft::$app->getDb()->tableExists(RepairRecord::TABLE)) {
            self::fail(sprintf(
                'The table %s does not exist. Reinstall Web Doctor in this project first: `php craft plugin/uninstall web-doctor && php craft plugin/install web-doctor`.',
                RepairRecord::TABLE,
            ));
        }

        $this->environment = 'tests-' . bin2hex(random_bytes(5));
        $this->actions = new RepairActions();
        $this->issues = new Issues(['evidence' => new EvidenceStore()]);
        $this->repairs = new Repairs([
            'actions' => $this->actions,
            'issues' => $this->issues,
            'evidence' => new EvidenceStore(),
            'permissions' => new Permissions(),
            'environment' => $this->environment,
        ]);

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
        $this->plugin->set('repairActions', $this->actions);
        $this->plugin->set('repairs', $this->repairs);
        $this->plugin->set('issues', $this->issues);

        // The service authorises the signed-in user itself, so every test says who that is; an
        // admin unless a test is about somebody else.
        $this->signIn(admin: true);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        try {
            // Whatever a test did, it never leaves a running repair without its lock, an ended one
            // holding one, or an issue repairing with nothing repairing it.
            if ($this->status()->isSuccess()) {
                $this->assertConsistent();
            }
        } finally {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    private function cleanUp(): void
    {

        // Repairs outlive their issues, so they are removed first — by environment, and by issue for any
        // whose environment a test deliberately spoiled.
        RepairRecord::deleteAll(['or',
            ['like', 'environment', 'tests-%', false],
            ['issueId' => IssueRecord::find()->select(['id'])->where(['like', 'environment', 'tests-%', false])],
        ]);
        IssueRecord::deleteAll(['like', 'environment', 'tests-%', false]);

        foreach ([...($this->queueTable === null ? [] : [$this->queueTable]), ...$this->extraTables] as $table) {
            Craft::$app->getDb()->createCommand()->dropTable($table)->execute();
        }

        $this->queueTable = null;
        $this->extraTables = [];

        if ($this->directory !== null) {
            @chmod($this->directory, 0755);
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
    }

    // The actions offered --------------------------------------------------------

    public function testEachShippedActionAnswersAShippedCheckAsItsOwnRecommendationDoes(): void
    {
        // A repair carries out a recommendation Web Doctor already gives by hand, for the same check,
        // at the same risk — the advice and the action must never disagree about what is dangerous —
        // and is verified by checks Web Doctor ships with, the one that found the problem first.
        $registry = new RepairActions(['includeCoreActions' => true]);
        $rules = [];

        foreach (RecommendationRules::all() as $rule) {
            $rules[$rule->id] = $rule;
        }

        $shipped = array_map(static fn($d): string => $d->id(), CoreDiagnostics::all());

        self::assertSame(['queue.retryFailedJobs', 'storage.createDirectories'], array_map(static fn($a): string => $a->id(), $registry->all()));
        self::assertCount(count(CoreRepairActions::all()), $registry->all());

        foreach ($registry->all() as $action) {
            $rule = $rules[(string)$action->recommendation()] ?? null;

            self::assertNotNull($rule, $action->id());
            self::assertSame($action->diagnosticId(), $rule->check, $action->id());
            self::assertSame($rule->risk, $action->risk(), $action->id());
            self::assertContains($action->diagnosticId(), $shipped, $action->id());
            self::assertSame($action->diagnosticId(), $action->verifyWith()[0], $action->id());
            self::assertSame([], array_diff($action->verifyWith(), $shipped), $action->id());
            self::assertNotSame(RepairRisk::HIGH, $action->risk(), 'Nothing Web Doctor ships with rewrites data.');
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedActions(): array
    {
        return [
            'an ID that is not one' => [['actionId' => 'Not-an-id']],
            'no check to verify with' => [['verifying' => []]],
            'another check verified first' => [['verifying' => ['other.check', 'test.check']]],
        ];
    }

    /**
     * An action nobody can identify, or whose result the check that found the problem would not be
     * asked about, is not one Web Doctor offers.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('malformedActions')]
    public function testAnActionThatCannotBeIdentifiedOrVerifiedIsRefused(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RepairActions())->register(new TestRepairAction($config));
    }

    public function testTheFirstRegistrationOfAnActionKeepsIt(): void
    {
        $first = new TestRepairAction();
        $registry = new RepairActions();
        $registry->register($first);

        try {
            $registry->register(new TestRepairAction());
            self::fail('A second action took an ID already registered.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame($first, $registry->get('test.repair'));
    }

    public function testOnlyActionsThatAnswerTheLatestFindingAreOffered(): void
    {
        $answers = $this->action();
        $this->action(['actionId' => 'test.elsewhere', 'check' => 'other.check']);
        $this->action(['actionId' => 'test.notThis', 'applicable' => false]);
        $issue = $this->raise();

        self::assertSame([$answers], $this->repairs->available($issue, []));

        // A resolved issue has nothing left to act on.
        $this->setStatus($issue, IssueStatus::RESOLVED);
        self::assertSame([], $this->repairs->available($this->reread($issue), []));
    }

    // Previewing -----------------------------------------------------------------

    public function testPreviewingReadsLiveKeepsWhatItShowedAndChangesNothing(): void
    {
        $action = $this->action(['needsAcknowledgement' => true]);
        $issue = $this->raise();
        $events = count($this->issues->events($issue->id));

        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        self::assertSame(RepairStatus::PREVIEWED, $repair->status);
        self::assertSame(VerificationStatus::NONE, $repair->verificationStatus);
        self::assertSame(0, $action->executions);
        self::assertSame('Would change the target.', $repair->preview->summary);
        self::assertSame(['Change the target'], $repair->preview->items);
        self::assertSame(['target' => ['item' => 1]], $repair->preview->state[0]->data);
        self::assertSame($repair->preview->fingerprint, $repair->fingerprint);
        self::assertSame(['targetReadable', 'backupTaken'], array_map(static fn($p): string => $p->id, $repair->prerequisites));
        self::assertSame(['backupTaken'], array_map(static fn($p): string => $p->id, $repair->toAcknowledge()));
        self::assertSame(['test.check'], $repair->verifyWith);
        self::assertSame(RepairRisk::LOW, $repair->risk);
        self::assertSame($issue->title, $repair->issueTitle);
        self::assertSame($this->environment, $repair->environment);
        self::assertSame(1, $repair->previewedBy);

        // The issue is exactly as it was: previewing is not doing.
        self::assertSame($issue->status, $this->reread($issue)->status);
        self::assertCount($events, $this->issues->events($issue->id));
    }

    /**
     * @return array<string, array{Closure(self, Issue): (string|null), string, bool}>
     */
    public static function unrepairable(): array
    {
        return [
            'a resolved issue' => [static fn(self $t, Issue $i) => $t->setStatus($i, IssueStatus::RESOLVED), 'resolved', true],
            'an ignored issue' => [static fn(self $t, Issue $i) => $t->setStatus($i, IssueStatus::IGNORED), 'decision not to act', true],
            'a won’t-fix issue' => [static fn(self $t, Issue $i) => $t->setStatus($i, IssueStatus::WONT_FIX), 'decision not to act', true],
            'an issue already being repaired' => [static function(self $t, Issue $i): ?string {
                $t->setStatus($i, IssueStatus::REPAIRING);
                $t->runningRepairOf($i, new DateTimeImmutable());

                return null;
            }, 'already being repaired', true],
            'an issue from another environment' => [static function(self $t, Issue $i): ?string {
                IssueRecord::updateAll(['environment' => 'tests-elsewhere'], ['id' => $i->id]);

                return null;
            }, 'environment', true],
            'an action for another check' => [static fn() => 'test.elsewhere', 'different check', false],
            'an action that does not answer the finding' => [static fn() => 'test.notThis', 'does not answer', false],
            'an action that does not exist' => [static fn() => 'test.nothing', 'no repair called', false],
        ];
    }

    /**
     * @param Closure(self, Issue): (string|null) $arrange Makes the issue unrepairable, or names the action to ask for.
     */
    #[DataProvider('unrepairable')]
    public function testNothingIsPreviewedForAnIssueThatCannotBeRepairedHere(Closure $arrange, string $said, bool $issueRefused): void
    {
        $this->action();
        $this->action(['actionId' => 'test.elsewhere', 'check' => 'other.check']);
        $this->action(['actionId' => 'test.notThis', 'applicable' => false]);
        $issue = $this->raise();
        $actionId = $arrange($this, $issue) ?? 'test.repair';
        $before = $this->previews();

        try {
            $this->repairs->prepare($issue->id, $actionId);
            self::fail('A repair was previewed for something that cannot be repaired here.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        // Where the issue itself is the reason, the issue page says so, and offers no button that fails.
        self::assertSame($issueRefused, $this->repairs->refusal($this->reread($issue)) !== null);

        self::assertSame($before, $this->previews());
    }

    public function testAPreviewThatCannotBeMadeIsRefusedWithoutWritingAnything(): void
    {
        $issue = $this->raise();
        $before = WebDoctorTables::snapshot();

        foreach ([
            'reading it throws' => ['onPreview' => static function(): void {
                throw new RuntimeException('SELECT password FROM users WHERE password=hunter2');
            }],
            'it has no fingerprint' => ['fingerprinted' => false],
        ] as $how => $config) {
            $this->actions = new RepairActions();
            $this->repairs->actions = $this->actions;
            $this->action($config);

            try {
                $this->repairs->prepare($issue->id, 'test.repair');
                self::fail("A preview was kept when $how.");
            } catch (\Throwable $e) {
                // A preview with no fingerprint is the action's defect, not the reader's refusal.
                self::assertStringNotContainsString('hunter2', $e->getMessage(), $how);
            }
        }

        self::assertSame($before, WebDoctorTables::snapshot());
    }

    public function testANewPreviewSupersedesOnlyTheSamePersonsWaitingPreviewAndKeepsEveryoneAsHistory(): void
    {
        $this->action();
        $this->action(['actionId' => 'test.other']);
        $issue = $this->raise();

        $first = $this->repairs->prepare($issue->id, 'test.repair');
        // Somebody else's preview of the same repair, and this person's of another repair.
        $someoneElses = $this->repairs->prepare($issue->id, 'test.repair');
        RepairRecord::updateAll(['previewedBy' => null], ['id' => $someoneElses->id]);
        $otherRepair = $this->repairs->prepare($issue->id, 'test.other');
        $carriedOut = $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);
        $expired = $this->repairs->prepare($issue->id, 'test.repair');
        $this->age($expired, Repair::PREVIEW_EXPIRES_AFTER + 60);

        $latest = $this->repairs->prepare($issue->id, 'test.repair');

        // Replaced, never deleted: every preview stays readable as history.
        self::assertSame(RepairStatus::SUPERSEDED, $this->repairs->get($first->id)?->status);
        self::assertSame(RepairStatus::SUPERSEDED, $this->repairs->get($expired->id)?->status);
        self::assertSame(RepairStatus::PREVIEWED, $this->repairs->get($someoneElses->id)?->status);
        self::assertSame(RepairStatus::PREVIEWED, $this->repairs->get($otherRepair->id)?->status);
        self::assertSame(RepairStatus::SUCCEEDED, $this->repairs->get($carriedOut->id)?->status);
        self::assertSame(RepairStatus::PREVIEWED, $this->repairs->get($latest->id)?->status);
        self::assertCount(6, $this->previews());

        $this->request('GET');
        $page = $this->render($this->controller(), 'detail', 'web-doctor/_repairs/_repair', ['issueId' => $issue->id, 'repairId' => $first->id]);
        self::assertStringContainsString('replaced by a newer one of the same repair', $page);
        self::assertStringNotContainsString('web-doctor/repairs/execute', $page);

        try {
            $this->repairs->execute($first->id, $issue->id, true);
            self::fail('A superseded preview was carried out.');
        } catch (Refusal $e) {
            self::assertStringContainsString('replaced by a newer one', $e->getMessage());
        }
    }

    // Confirming -----------------------------------------------------------------

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function unconfirmed(): array
    {
        return [
            'not confirmed' => [[], false, [], null, 'not confirmed'],
            'a prerequisite not acknowledged' => [['needsAcknowledgement' => true], true, [], null, 'A backup of the target exists.'],
            'an acknowledgement of something else' => [['needsAcknowledgement' => true], true, ['somethingElse'], null, 'did not ask about'],
            'the right one and an extra one' => [['needsAcknowledgement' => true], true, ['backupTaken', 'somethingElse'], null, 'did not ask about'],
            'the same one twice' => [['needsAcknowledgement' => true], true, ['backupTaken', 'backupTaken'], null, 'more than once'],
            'one that is not text' => [['needsAcknowledgement' => true], true, [7], null, 'did not ask about'],
            'a keyed list' => [['needsAcknowledgement' => true], true, ['a' => 'backupTaken'], null, 'did not ask about'],
            'an acknowledgement when none was asked for' => [[], true, ['backupTaken'], null, 'did not ask about'],
            'a high-risk repair with an empty string typed' => [['riskLevel' => RepairRisk::HIGH], true, [], '', 'type the name of the environment'],
            'a high-risk repair with the environment in capitals' => [['riskLevel' => RepairRisk::HIGH], true, [], '(ENVIRONMENT)', 'type the name of the environment'],
            'a high-risk repair with space around the environment' => [['riskLevel' => RepairRisk::HIGH], true, [], ' (environment) ', 'type the name of the environment'],
            'a high-risk repair with a newline after the environment' => [['riskLevel' => RepairRisk::HIGH], true, [], "(environment)\n", 'type the name of the environment'],
            'a high-risk repair with nothing typed' => [['riskLevel' => RepairRisk::HIGH], true, [], null, 'type the name of the environment'],
            'a high-risk repair with the wrong environment typed' => [['riskLevel' => RepairRisk::HIGH], true, [], 'production', 'type the name of the environment'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<mixed> $acknowledged
     */
    #[DataProvider('unconfirmed')]
    public function testNothingIsCarriedOutUnlessItIsConfirmedAsItsRiskRequires(array $config, bool $confirmed, array $acknowledged, ?string $typed, string $said): void
    {
        $action = $this->action($config);
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $typed = $typed === null ? null : str_replace(['(environment)', '(ENVIRONMENT)'], [$this->environment, strtoupper($this->environment)], $typed);

        try {
            $this->repairs->execute($repair->id, $issue->id, $confirmed, $acknowledged, $typed);
            self::fail('A repair was carried out without the confirmation it needs.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame(0, $action->executions);
        $this->assertStillWaiting($repair, $issue);
    }

    public function testAHighRiskRepairIsCarriedOutOnceTheEnvironmentIsTyped(): void
    {
        $action = $this->action(['riskLevel' => RepairRisk::HIGH]);
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        $done = $this->repairs->execute($repair->id, $issue->id, true, [], $this->environment);

        self::assertSame(RepairStatus::SUCCEEDED, $done->status);
        self::assertSame(1, $action->executions);
    }

    public function testAnExpiredPreviewCannotBeConfirmed(): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $this->age($repair, Repair::PREVIEW_EXPIRES_AFTER + 1);

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('An expired preview was carried out.');
        } catch (Refusal $e) {
            self::assertStringContainsString('expired', $e->getMessage());
        }

        self::assertSame(0, $action->executions);
        self::assertTrue($this->repairs->get($repair->id)?->isExpired());
    }

    public function testARepairIsCarriedOutRecordedAndLeavesTheIssueWhereItStoodUnresolved(): void
    {
        $seen = null;
        $issue = $this->raise();
        $action = $this->action([
            'needsAcknowledgement' => true,
            // While it runs, the issue says a repair is under way.
            'onExecute' => function() use (&$seen, $issue): void {
                $seen = $this->reread($issue)->status;
            },
        ]);
        $this->issues->transition($issue->id, IssueStatus::CONFIRMED);
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        $done = $this->repairs->execute($repair->id, $issue->id, true, ['backupTaken']);
        $issue = $this->reread($issue);

        self::assertSame(1, $action->executions);
        self::assertSame(IssueStatus::REPAIRING, $seen);
        self::assertSame(RepairStatus::SUCCEEDED, $done->status);
        // Carried out is not fixed: only the check that found the problem, run again, can say so.
        self::assertSame(VerificationStatus::PENDING, $done->verificationStatus);
        self::assertSame(IssueStatus::CONFIRMED, $issue->status);
        self::assertNotSame(IssueStatus::RESOLVED, $issue->status);
        self::assertSame(IssueStatus::CONFIRMED, $done->issueStatusBefore);
        self::assertSame('Changed the target.', $done->outcome?->summary);
        self::assertSame(['target' => 'changed'], $done->outcome->state[0]->data);
        self::assertSame(['target' => ['item' => 1]], $done->preview->state[0]->data);
        self::assertSame(['backupTaken'], $done->acknowledged);
        self::assertSame(1, $done->executedBy);
        self::assertNull($done->failure);
        self::assertNotNull($done->startedAt);
        self::assertNotNull($done->finishedAt);
        self::assertNull(RepairRecord::findOne($done->id)?->lockKey);

        // The history says a repair started and finished, and back to where it stood.
        $events = array_values(array_filter($this->issues->events($issue->id), static fn($e): bool => in_array($e->type, [IssueEventType::REPAIR_STARTED, IssueEventType::REPAIR_FINISHED], true)));

        self::assertSame([IssueEventType::REPAIR_FINISHED, IssueEventType::REPAIR_STARTED], array_map(static fn($e) => $e->type, $events));
        self::assertSame([IssueStatus::REPAIRING, IssueStatus::CONFIRMED], [$events[0]->fromStatus, $events[0]->toStatus]);
        self::assertSame([IssueStatus::CONFIRMED, IssueStatus::REPAIRING], [$events[1]->fromStatus, $events[1]->toStatus]);
        self::assertStringContainsString('not yet verified', (string)$events[0]->note);
    }

    // What is carried out is what was confirmed ----------------------------------

    /**
     * @return array<string, array{Closure(TestRepairAction, self, Issue): void, string}>
     */
    public static function changedSincePreview(): array
    {
        return [
            'what it acts on' => [static function(TestRepairAction $a): void {
                $a->target = ['item' => 2];
            }, 'no longer what the preview showed'],
            'a prerequisite it checks' => [static function(TestRepairAction $a): void {
                $a->prerequisiteMet = false;
            }, 'no longer holds'],
            'its risk' => [static function(TestRepairAction $a): void {
                $a->riskLevel = RepairRisk::MEDIUM;
            }, 'changed since it was previewed'],
            'the issue, set aside by somebody' => [static function(TestRepairAction $a, self $t, Issue $i): void {
                $t->setStatus($i, IssueStatus::IGNORED);
            }, 'decision not to act'],
            'the issue, observed clear by a run' => [static function(TestRepairAction $a, self $t, Issue $i): void {
                $t->setStatus($i, IssueStatus::RESOLVED);
            }, 'resolved'],
            'whether it answers the finding' => [static function(TestRepairAction $a): void {
                $a->applicable = false;
            }, 'does not answer'],
        ];
    }

    /**
     * @param Closure(TestRepairAction, self, Issue): void $change
     */
    #[DataProvider('changedSincePreview')]
    public function testARepairIsRefusedWhenAnythingItWasConfirmedOnHasChanged(Closure $change, string $said): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $change($action, $this, $issue);
        $status = $this->reread($issue)->status;

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('A repair was carried out on something other than what was confirmed.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame(0, $action->executions);
        self::assertSame($status, $this->reread($issue)->status);
        $record = RepairRecord::findOne($repair->id);
        self::assertSame(RepairStatus::PREVIEWED->value, $record?->status);
        self::assertNull($record->lockKey);
    }

    public function testAnActionThatRefusesAsItActsChangesNothingAndGivesThePreviewBack(): void
    {
        // The action reads its target once more as it acts; a change in that last moment is a
        // refusal, not a failure, because nothing was done.
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        $refusing = new class() extends TestRepairAction {
            public function execute(\Tahadudhiya\WebDoctor\models\RepairContext $context, \Tahadudhiya\WebDoctor\models\RepairReport $preview): \Tahadudhiya\WebDoctor\models\RepairReport
            {
                throw new Refusal('The target changed just before it was acted on.');
            }
        };
        $this->actions = new RepairActions();
        $this->actions->register($refusing);
        $this->repairs->actions = $this->actions;

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('The refusal was not passed on.');
        } catch (Refusal $e) {
            self::assertStringContainsString('changed just before', $e->getMessage());
        }

        self::assertSame(0, $action->executions);
        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        $record = RepairRecord::findOne($repair->id);
        self::assertSame(RepairStatus::PREVIEWED->value, $record?->status);
        self::assertNull($record->lockKey);
    }

    // Failure ----------------------------------------------------------------------

    public function testAnActionThatFailsPartWayIsRecordedAsFailedAndTheIssueGoesBack(): void
    {
        $this->action(['onExecute' => static function(): void {
            throw new RuntimeException('Deadlock in INSERT INTO users (password) VALUES (hunter2)');
        }]);
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        $done = $this->repairs->execute($repair->id, $issue->id, true);

        self::assertSame(RepairStatus::FAILED, $done->status);
        // A failure has no result to verify; the checks listed say where things stand.
        self::assertSame(VerificationStatus::NONE, $done->verificationStatus);
        self::assertNull($done->outcome);
        // Named by its kind, never by its message, which can quote SQL redaction does not remove.
        self::assertStringContainsString('RuntimeException', (string)$done->failure);
        self::assertStringNotContainsString('hunter2', (string)$done->failure);
        self::assertStringNotContainsString('INSERT', (string)$done->failure);
        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        self::assertNull(RepairRecord::findOne($done->id)?->lockKey);

        // And the next repair of its kind is not held up by it.
        $this->actions = new RepairActions();
        $this->repairs->actions = $this->actions;
        $again = $this->action();
        $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);

        self::assertSame(1, $again->executions);
    }

    public function testEveryAttemptIsLoggedWithWhatWhereAndWhoAndNothingSecret(): void
    {
        $original = \Yii::getLogger();
        $logger = new \yii\log\Logger();
        \Yii::setLogger($logger);

        try {
            $this->action(['onExecute' => static function(): void {
                throw new RuntimeException('password=hunter2');
            }]);
            $issue = $this->raise();
            $repair = $this->repairs->prepare($issue->id, 'test.repair');

            try {
                $this->repairs->execute($repair->id, $issue->id, false);
            } catch (Refusal) {
            }

            $this->repairs->execute($repair->id, $issue->id, true);
        } finally {
            \Yii::setLogger($original);
        }

        $lines = array_values(array_filter(
            array_map(static fn(array $m): string => (string)$m[0], $logger->messages),
            static fn(string $line): bool => str_contains($line, 'Repair ') || str_contains($line, 'repair '),
        ));
        $text = implode("\n", $lines);

        self::assertStringContainsString(sprintf('Repair %d (test.repair, low risk) previewed for issue %d in the "%s" environment by user 1.', $repair->id, $issue->id, $this->environment), $text);
        self::assertStringContainsString(sprintf('Repair %d of issue %d refused for user 1: Nothing was carried out: the repair was not confirmed.', $repair->id, $issue->id), $text);
        self::assertStringContainsString(sprintf('Repair %d (test.repair, low risk) failed for issue %d', $repair->id, $issue->id), $text);
        self::assertStringNotContainsString('hunter2', implode("\n", array_map(static fn(array $m): string => (string)$m[0], $logger->messages)));
    }

    // Duplicate execution ----------------------------------------------------------

    public function testAPreviewIsCarriedOutAtMostOnce(): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        $this->repairs->execute($repair->id, $issue->id, true);

        foreach (['confirmed again' => RepairStatus::SUCCEEDED, 'confirmed while running' => RepairStatus::RUNNING] as $how => $status) {
            // Running means holding the lock; ended means holding none.
            RepairRecord::updateAll([
                'status' => $status->value,
                'lockKey' => $status === RepairStatus::RUNNING ? Repairs::lockKey('test.repair', $this->environment) : null,
            ], ['id' => $repair->id]);

            try {
                $this->repairs->execute($repair->id, $issue->id, true);
                self::fail("A preview was carried out a second time when $how.");
            } catch (Refusal $e) {
                self::assertStringContainsString('already', $e->getMessage(), $how);
            }
        }

        self::assertSame(1, $action->executions);
    }

    public function testOfTwoConfirmationsOfOnePreviewAtOnceOnlyOneGetsThrough(): void
    {
        // The other request confirms the same preview after this one has read it as waiting and
        // before this one claims it — the window only a single conditional write can close.
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        $this->repairs->issues = new class($repair->id, $this->environment) extends Issues {
            public function __construct(private int $repairId, private string $environment)
            {
                parent::__construct(['evidence' => new EvidenceStore()]);
            }

            public function get(int $id): ?Issue
            {
                // The other request's claim, as the claim writes it: running, and holding the lock.
                RepairRecord::updateAll(
                    ['status' => RepairStatus::RUNNING->value, 'lockKey' => Repairs::lockKey('test.repair', $this->environment)],
                    ['id' => $this->repairId, 'status' => RepairStatus::PREVIEWED->value],
                );

                return parent::get($id);
            }
        };

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('Both confirmations were carried out.');
        } catch (Refusal $e) {
            self::assertStringContainsString('already been carried out, or is being carried out now', $e->getMessage());
        }

        self::assertSame(0, $action->executions);
        self::assertNotSame(IssueStatus::REPAIRING, $this->reread($issue)->status);
    }

    public function testTwoRepairsOfTheSameKindCannotRunAtOnce(): void
    {
        // Two issues, one kind of repair: what it acts on belongs to the installation, so the
        // second waits for the first rather than running beside it.
        $action = $this->action();
        [$first, $second] = $this->raiseEach(['one', 'another']);
        $running = $this->runningRepairOf($first, new DateTimeImmutable());
        $repair = $this->repairs->prepare($second->id, 'test.repair');

        try {
            $this->repairs->execute($repair->id, $second->id, true);
            self::fail('Two repairs of the same kind ran at once.');
        } catch (Refusal $e) {
            self::assertStringContainsString('Another repair of this kind', $e->getMessage());
        }

        self::assertSame(0, $action->executions);
        $this->assertStillWaiting($repair, $second);
        self::assertSame(RepairStatus::RUNNING->value, RepairRecord::findOne($running)?->status);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function stoppedRepairs(): array
    {
        return [
            // Found when this issue is next previewed or confirmed.
            'of this issue' => [true],
            // Found only when its lock is next asked for, by a repair of the same kind elsewhere.
            'of another issue' => [false],
        ];
    }

    #[DataProvider('stoppedRepairs')]
    public function testARepairLeftRunningByARequestThatDiedIsEndedAndItsIssuePutBack(bool $sameIssue): void
    {
        $action = $this->action();
        [$issue, $other] = $this->raiseEach(['one', 'other']);
        $stuck = $sameIssue ? $issue : $other;
        $this->issues->transition($stuck->id, IssueStatus::CONFIRMED);
        $this->setStatus($stuck, IssueStatus::REPAIRING);
        $stopped = $this->runningRepairOf($stuck, new DateTimeImmutable(sprintf('-%d seconds', Repair::STOPPED_AFTER + 60)), IssueStatus::CONFIRMED);

        // Not refused as "already being repaired": nothing is.
        self::assertNull($this->repairs->refusal($this->reread($stuck)));
        self::assertTrue($this->repairs->get($stopped)?->hasStopped());

        $done = $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);
        $ended = $this->repairs->get($stopped);

        self::assertSame(RepairStatus::SUCCEEDED, $done->status);
        self::assertSame(1, $action->executions);
        self::assertSame(RepairStatus::FAILED, $ended->status);
        self::assertStringContainsString('Stopped without an ending', (string)$ended->failure);
        self::assertNull(RepairRecord::findOne($stopped)?->lockKey);
        self::assertSame(IssueStatus::CONFIRMED, $this->reread($stuck)->status);
    }

    // The shipped actions, for real --------------------------------------------------

    public function testMissingStorageDirectoriesAreCreatedAndNothingThatExistsIsTouched(): void
    {
        $root = $this->directory();
        mkdir("$root/storage");
        file_put_contents("$root/storage/keep.txt", 'untouched');
        $check = $this->storageCheck([
            'storage' => "$root/storage",
            'runtime' => "$root/storage/runtime",
            'logs' => "$root/storage/logs/deeper",
        ]);
        $this->actions->register(new CreateStorageDirectories(['check' => $check]));
        $issue = $this->raiseFrom($check);

        $repair = $this->repairs->prepare($issue->id, CreateStorageDirectories::ID);

        self::assertSame(RepairRisk::LOW, $repair->risk);
        self::assertCount(2, $repair->preview->items);
        self::assertTrue($repair->checkedPrerequisitesMet());
        self::assertDirectoryDoesNotExist("$root/storage/runtime");

        $done = $this->repairs->execute($repair->id, $issue->id, true);

        self::assertSame(RepairStatus::SUCCEEDED, $done->status, (string)$done->failure);
        self::assertDirectoryExists("$root/storage/runtime");
        self::assertDirectoryExists("$root/storage/logs/deeper");
        self::assertSame('untouched', file_get_contents("$root/storage/keep.txt"));
        self::assertSame(['logs', 'runtime'], $done->outcome?->state[0]->data['created'] ?? null);
        self::assertSame('pass', $check->run(new DiagnosticContext(environment: $this->environment))->status->value);
    }

    public function testTheStorageRepairIsRefusedWhereADirectoryCannotBeCreated(): void
    {
        $root = $this->directory();
        mkdir("$root/locked");
        chmod("$root/locked", 0555);

        if (is_writable("$root/locked")) {
            self::markTestSkipped('This process can write anywhere, so a read-only directory cannot be stated.');
        }

        $check = $this->storageCheck(['storage' => "$root/locked/storage"]);
        $this->actions->register(new CreateStorageDirectories(['check' => $check]));
        $issue = $this->raiseFrom($check);
        $repair = $this->repairs->prepare($issue->id, CreateStorageDirectories::ID);

        self::assertFalse($repair->checkedPrerequisitesMet());

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('A directory was attempted beneath one PHP cannot write to.');
        } catch (Refusal $e) {
            self::assertStringContainsString('no longer holds', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist("$root/locked/storage");
    }

    public function testFailedJobsAreRetriedWithCraftsOwnRetryAndOnlyThoseOfThisQueue(): void
    {
        $queue = $this->queue();
        $mine = [$this->job($queue, fail: true, description: 'Sending email', attempt: 3), $this->job($queue, fail: true, description: 'Indexing', attempt: 1)];
        $foreign = $this->job($queue, fail: true, channel: 'someone-else');
        $waiting = $this->job($queue, fail: false, description: 'Waiting');
        $this->actions->register(new RetryFailedJobs(['queue' => $queue]));
        $issue = $this->raiseFrom(new FailedJobsDiagnostic(['queue' => $queue]));

        $repair = $this->repairs->prepare($issue->id, RetryFailedJobs::ID);

        self::assertSame(RepairRisk::MEDIUM, $repair->risk);
        self::assertCount(2, $repair->preview->items);
        self::assertStringContainsString('Sending email', $repair->preview->items[0]);
        self::assertSame(['safeToRepeat', 'causeDealtWith'], array_map(static fn($p): string => $p->id, $repair->toAcknowledge()));
        self::assertTrue($repair->checkedPrerequisitesMet());

        // Both acknowledgements are needed, and nothing is retried without them.
        try {
            $this->repairs->execute($repair->id, $issue->id, true, ['safeToRepeat']);
            self::fail('Jobs were retried without every acknowledgement.');
        } catch (Refusal) {
        }

        self::assertSame(1, (int)$this->row($queue, $mine[0])['fail']);

        $done = $this->repairs->execute($repair->id, $issue->id, true, ['safeToRepeat', 'causeDealtWith']);

        self::assertSame(RepairStatus::SUCCEEDED, $done->status, (string)$done->failure);

        foreach ($mine as $id) {
            $row = $this->row($queue, $id);
            self::assertSame(0, (int)$row['fail']);
            self::assertSame(0, (int)$row['attempt']);
            self::assertNull($row['error']);
        }

        self::assertSame(1, (int)$this->row($queue, $foreign)['fail'], 'Another queue’s job was retried.');
        self::assertSame('Waiting', $this->row($queue, $waiting)['description']);
        self::assertSame($mine, $done->outcome?->state[0]->data['retried'] ?? null);
    }

    /**
     * @return array<string, array{Closure(self, StubQueue, int): void, string}>
     */
    public static function jobsNotToRetry(): array
    {
        return [
            'one ran out of memory' => [static function(self $t, StubQueue $q, int $id): void {
                $t->updateJob($q, $id, ['error' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)']);
            }, 'ran out of memory'],
            'one failed again since the preview' => [static function(self $t, StubQueue $q, int $id): void {
                $t->updateJob($q, $id, ['attempt' => 9]);
            }, 'no longer what the preview showed'],
            'somebody retried them since the preview' => [static function(self $t, StubQueue $q, int $id): void {
                $t->updateJob($q, $id, ['fail' => false]);
            }, 'no longer'],
        ];
    }

    /**
     * @param Closure(self, StubQueue, int): void $change
     */
    #[DataProvider('jobsNotToRetry')]
    public function testFailedJobsAreNotRetriedWhenRetryingWouldRepeatTheFailureOrTheyChanged(Closure $change, string $said): void
    {
        $queue = $this->queue();
        $id = $this->job($queue, fail: true, description: 'Generating transforms');
        $this->actions->register(new RetryFailedJobs(['queue' => $queue]));
        $issue = $this->raiseFrom(new FailedJobsDiagnostic(['queue' => $queue]));
        $repair = $this->repairs->prepare($issue->id, RetryFailedJobs::ID);
        $change($this, $queue, $id);
        $before = $this->row($queue, $id);

        try {
            $this->repairs->execute($repair->id, $issue->id, true, ['safeToRepeat', 'causeDealtWith']);
            self::fail('A job was retried that should not have been.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame($before, $this->row($queue, $id));
    }

    public function testRetryingJobsNeedsCraftsOwnQueueManagerPermission(): void
    {
        $action = new RetryFailedJobs();

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::RUN_REPAIRS]);
        self::assertFalse($action->isAuthorized());

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::RUN_REPAIRS, 'utility:queue-manager']);
        self::assertTrue($action->isAuthorized());
    }

    // Who may, and how ---------------------------------------------------------------

    public function testOnlySomebodyWhoMayRunRepairsAndWhomCraftAllowsCanPreviewOrConfirm(): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $readers = [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::MANAGE_ISSUES, Permissions::VIEW_EVIDENCE, Permissions::INVESTIGATE_ISSUES];
        $bodies = [
            'prepare' => ['issueId' => (string)$issue->id, 'repairAction' => 'test.repair'],
            'execute' => ['issueId' => (string)$issue->id, 'repairId' => (string)$repair->id, 'confirm' => '1'],
        ];

        foreach ($bodies as $route => $body) {
            // Every other permission Web Doctor has, and still not this.
            $this->signIn(admin: false, permissions: $readers);
            $this->post($body);

            try {
                $this->controller()->runAction($route);
                self::fail("Somebody who may not run repairs reached $route.");
            } catch (ForbiddenHttpException) {
            }

            // Web Doctor's permission, but Craft would not allow the same thing by its own means.
            $action->authorized = false;
            $this->signIn(admin: false, permissions: [...$readers, Permissions::RUN_REPAIRS]);
            $this->post($body);

            try {
                $this->controller()->runAction($route);
                self::fail("Somebody Craft would refuse reached $route.");
            } catch (ForbiddenHttpException $e) {
                self::assertSame('Craft would not let you do this yourself.', $e->getMessage());
            }

            $action->authorized = true;
        }

        self::assertSame(0, $action->executions);
        $this->assertStillWaiting($repair, $issue);

        // Allowed by both, the preview is shown and then carried out.
        $this->signIn(admin: false, permissions: [...$readers, Permissions::RUN_REPAIRS]);
        $this->post($bodies['prepare']);
        $controller = $this->controller();
        $response = $controller->runAction('prepare');
        $previewed = $this->repairs->forIssue($issue->id)[0];

        self::assertStringContainsString(sprintf('web-doctor/issues/%d/repairs/%d', $issue->id, $previewed->id), (string)$response->getHeaders()->get('Location'));

        $this->post(['issueId' => (string)$issue->id, 'repairId' => (string)$previewed->id, 'confirm' => '1']);
        $controller = $this->controller();
        $controller->runAction('execute');

        $flash = $controller->lastFlash();
        self::assertNotNull($flash);
        self::assertSame('success', $flash['level']);
        self::assertStringContainsString('not yet verified', $flash['message']);
        self::assertSame(1, $action->executions);
    }

    public function testRepairsAreRefusedFromTheFrontEndByGetOrWithoutAToken(): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $this->signIn(admin: true);
        $body = ['issueId' => (string)$issue->id, 'repairAction' => 'test.repair'];
        $before = WebDoctorTables::snapshot();

        foreach ([
            'a GET' => [fn() => $this->request('GET'), MethodNotAllowedHttpException::class],
            'no CSRF token' => [fn() => $this->request('POST')->setBodyParams($body), BadRequestHttpException::class],
            'the front end' => [fn() => $this->post($body, cp: false), BadRequestHttpException::class],
        ] as $how => [$request, $refusal]) {
            foreach (['prepare', 'execute'] as $route) {
                $request();

                try {
                    $this->controller()->runAction($route);
                    self::fail("A repair was reached from $how.");
                } catch (\Throwable $e) {
                    self::assertInstanceOf($refusal, $e, "$how, $route");
                }
            }
        }

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertSame(0, $action->executions);
        self::assertSame(RecordingRepairsController::ALLOW_ANONYMOUS_NEVER, $this->controller()->anonymousAccess());
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function malformedRequests(): array
    {
        return [
            'an issue ID with letters' => ['prepare', ['issueId' => '12abc', 'repairAction' => 'test.repair']],
            'an issue ID with a newline' => ['prepare', ['issueId' => "12\n", 'repairAction' => 'test.repair']],
            'an issue ID of zero' => ['prepare', ['issueId' => '0', 'repairAction' => 'test.repair']],
            'a negative issue ID' => ['prepare', ['issueId' => '-1', 'repairAction' => 'test.repair']],
            'an issue ID with spaces' => ['prepare', ['issueId' => ' (issue) ', 'repairAction' => 'test.repair']],
            'an issue ID sent twice' => ['prepare', ['issueId' => ['(issue)', '(issue)'], 'repairAction' => 'test.repair']],
            'a missing issue ID' => ['prepare', ['repairAction' => 'test.repair']],
            'a missing action' => ['prepare', ['issueId' => '(issue)']],
            'a repair ID of zero' => ['execute', ['issueId' => '(issue)', 'repairId' => '0', 'confirm' => '1']],
            'a repair ID with a newline' => ['execute', ['issueId' => '(issue)', 'repairId' => "(repair)\n", 'confirm' => '1']],
            'a repair ID sent twice' => ['execute', ['issueId' => '(issue)', 'repairId' => ['(repair)', '(repair)'], 'confirm' => '1']],
            'a confirmation sent twice' => ['execute', ['issueId' => '(issue)', 'repairId' => '(repair)', 'confirm' => ['1', '1']]],
            'an action that is a list' => ['prepare', ['issueId' => '(issue)', 'repairAction' => ['test.repair']]],
            'an empty action' => ['prepare', ['issueId' => '(issue)', 'repairAction' => '']],
            'a repair ID that is not one' => ['execute', ['issueId' => '(issue)', 'repairId' => '1.5', 'confirm' => '1']],
            'a confirmation that is not one' => ['execute', ['issueId' => '(issue)', 'repairId' => '(repair)', 'confirm' => 'yes']],
            'acknowledgements that are not a list' => ['execute', ['issueId' => '(issue)', 'repairId' => '(repair)', 'confirm' => '1', 'acknowledged' => 'backupTaken']],
            'acknowledgements that are not text' => ['execute', ['issueId' => '(issue)', 'repairId' => '(repair)', 'confirm' => '1', 'acknowledged' => [['backupTaken']]]],
            'a typed confirmation that is not text' => ['execute', ['issueId' => '(issue)', 'repairId' => '(repair)', 'confirm' => '1', 'typedConfirmation' => ['tests']]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('malformedRequests')]
    public function testAMalformedRequestIsRefusedBeforeAnythingIsReadOrWritten(string $route, array $body): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $fill = static function(mixed $v) use (&$fill, $issue, $repair): mixed {
            return is_array($v)
                ? array_map($fill, $v)
                : (is_string($v) ? str_replace(['(issue)', '(repair)'], [(string)$issue->id, (string)$repair->id], $v) : $v);
        };
        $body = array_map($fill, $body);
        $before = WebDoctorTables::snapshot();

        $this->signIn(admin: true);
        $this->post($body);

        try {
            $this->controller()->runAction($route);
            self::fail('A malformed request was acted on.');
        } catch (BadRequestHttpException) {
        }

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertSame(0, $action->executions);
    }

    public function testARepairIsReachableOnlyUnderItsOwnIssue(): void
    {
        $action = $this->action();
        [$issue, $other] = $this->raiseEach(['one', 'other']);
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $this->signIn(admin: true);
        $this->request('GET');

        try {
            $this->controller()->runAction('detail', ['issueId' => $other->id, 'repairId' => $repair->id]);
            self::fail('A repair was shown under another issue’s URL.');
        } catch (NotFoundHttpException) {
        }

        // Nor carried out through one.
        try {
            $this->repairs->execute($repair->id, $other->id, true);
            self::fail('A repair was carried out through another issue.');
        } catch (Refusal) {
        }

        self::assertSame(0, $action->executions);
    }

    // What the pages say ---------------------------------------------------------------

    public function testTheIssuePageOffersTheRepairWithItsRiskOnlyToSomebodyWhoMayRunItAndTheAdvicePointsToIt(): void
    {
        $root = $this->directory();
        $check = $this->storageCheck(['storage' => "$root/storage"]);
        $this->actions->register(new CreateStorageDirectories(['check' => $check]));
        $issue = $this->raiseFrom($check);
        $readers = [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE];

        $this->signIn(admin: false, permissions: $readers);
        $this->request('GET');
        $html = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('id="repairs"', $html);
        self::assertStringContainsString('Create the missing storage directories', $html);
        self::assertStringContainsString('wd-pill--risk-low', $html);
        // Somebody who may not run it is told a repair exists, never that they can carry it out.
        self::assertStringContainsString('Web Doctor has a repair for this, but it cannot be carried out here now', $html);
        self::assertStringNotContainsString('Web Doctor can carry this out once you have previewed and confirmed it', $html);
        self::assertStringNotContainsString('web-doctor/repairs/prepare', $html);

        $this->signIn(admin: false, permissions: [...$readers, Permissions::RUN_REPAIRS]);
        $this->request('GET');
        $html = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString('web-doctor/repairs/prepare', $html);
        self::assertStringContainsString('Preview this repair', $html);
        self::assertStringContainsString('Web Doctor can carry this out once you have previewed and confirmed it', $html);
        // `action` is the field Craft routes a POST by, so a form of Web Doctor's never sends another.
        self::assertDoesNotMatchRegularExpression('/name="action" value="(?!web-doctor\/)/', $html, 'A form field would override the route Craft posts to.');
        self::assertStringContainsString('name="repairAction" value="storage.createDirectories"', $html);

        // And nothing was written by showing it.
        self::assertSame([], $this->repairs->forIssue($issue->id));
    }

    public function testTheRepairPageAsksForExactlyTheConfirmationItNeedsAndShowsDetailsOnlyToThoseWhoMaySeeThem(): void
    {
        $this->action(['needsAcknowledgement' => true, 'riskLevel' => RepairRisk::HIGH, 'target' => ['path' => '/var/secret-place']]);
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $params = ['issueId' => $issue->id, 'repairId' => $repair->id];

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::RUN_REPAIRS]);
        $this->request('GET');
        $html = $this->render($this->controller(), 'detail', 'web-doctor/_repairs/_repair', $params);

        self::assertStringContainsString('web-doctor/repairs/execute', $html);
        self::assertStringContainsString('name="acknowledged[]" value="backupTaken"', $html);
        self::assertStringContainsString('name="typedConfirmation"', $html);
        self::assertStringContainsString('“' . $this->environment . '”', $html);
        self::assertStringContainsString('name="confirm" value="1"', $html);
        self::assertStringContainsString('/var/secret-place', $html);

        // Somebody who may only read the issue sees what kind of repair it is, not what it read,
        // and is offered nothing to confirm.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);
        $this->request('GET');
        $html = $this->render($this->controller(), 'detail', 'web-doctor/_repairs/_repair', $params);

        self::assertStringContainsString('Test repair', $html);
        self::assertStringContainsString('High risk', $html);
        self::assertStringNotContainsString('web-doctor/repairs/execute', $html);
        self::assertStringNotContainsString('/var/secret-place', $html);
        self::assertStringContainsString('need the “Run repairs” or “View evidence” permission', $html);
    }

    public function testACarriedOutRepairsPageSaysItIsAwaitingVerificationAndHowToVerifyIt(): void
    {
        $this->action();
        $issue = $this->raise();
        $done = $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);

        $this->signIn(admin: true);
        $this->request('GET');
        $html = $this->render($this->controller(), 'detail', 'web-doctor/_repairs/_repair', ['issueId' => $issue->id, 'repairId' => $done->id]);

        self::assertStringContainsString('Awaiting verification', $html);
        self::assertStringContainsString('<code>test.check</code>', $html);
        self::assertStringContainsString('Changed the target.', $html);
        self::assertStringNotContainsString('web-doctor/repairs/execute', $html);
        self::assertStringNotContainsString('[redacted]', $html);
    }

    // Authorisation at the service ------------------------------------------------------

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function unauthorised(): array
    {
        $readers = [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::MANAGE_ISSUES, Permissions::VIEW_EVIDENCE, Permissions::INVESTIGATE_ISSUES];

        return [
            'nobody signed in' => [static fn(self $t) => $t->signOut(), 'somebody signed in'],
            'every permission but Run repairs' => [static fn(self $t) => $t->signIn(admin: false, permissions: $readers), '“Run repairs” permission'],
            'Run repairs, but Craft would not allow the action' => [static function(self $t) use ($readers): void {
                $t->signIn(admin: false, permissions: [...$readers, Permissions::RUN_REPAIRS]);
                $t->lastAction->authorized = false;
            }, 'Craft would not let you'],
        ];
    }

    /**
     * Called straight on the service, with no controller in front of it: the service refuses on its
     * own, from Craft's signed-in user, and writes nothing.
     *
     * @param Closure(self): void $as
     */
    #[DataProvider('unauthorised')]
    public function testTheServiceItselfRefusesSomebodyWhoMayNotRepairWhatever(Closure $as, string $said): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $before = WebDoctorTables::snapshot();
        $as($this);

        foreach ([
            'preview' => fn() => $this->repairs->prepare($issue->id, 'test.repair'),
            'confirmation' => fn() => $this->repairs->execute($repair->id, $issue->id, true),
        ] as $what => $call) {
            try {
                $call();
                self::fail("The service allowed a $what without authorisation.");
            } catch (Refusal $e) {
                self::assertStringContainsString($said, $e->getMessage(), $what);
            }
        }

        self::assertSame($before, WebDoctorTables::snapshot());
        self::assertSame(0, $action->executions);
    }

    public function testWhoPreviewedARepairGrantsNobodyElseTheRightToCarryItOut(): void
    {
        // The preview was an admin's; the person confirming it is judged on their own.
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES]);

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('The previewer’s rights were borrowed.');
        } catch (Refusal) {
        }

        // And a non-admin holding both permissions is allowed, as Craft would allow them.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::RUN_REPAIRS]);
        $done = $this->repairs->execute($repair->id, $issue->id, true);

        self::assertSame(1, $action->executions);
        // Recorded as who was signed in, not as anything a caller could name.
        self::assertSame(1, $done->executedBy);
    }

    public function testTheServiceFailsClosedRatherThanBuildingDependenciesOfItsOwn(): void
    {
        // With Web Doctor not installed to hand them over, and none injected, a repair stops — it is
        // never carried out against a registry, an Issue Center or permissions nobody configured.
        $loaded = Craft::$app->loadedModules;
        unset(Craft::$app->loadedModules[WebDoctor::class]);

        try {
            self::assertNull(WebDoctor::getInstance());

            foreach ([
                'prepare' => static fn() => (new Repairs())->prepare(1, 'test.repair'),
                'execute' => static fn() => (new Repairs())->execute(1, 1, true),
                'the page’s list' => fn() => (new Repairs(['permissions' => new Permissions()]))->available($this->raise(), []),
            ] as $what => $call) {
                try {
                    $call();
                    self::fail("$what ran without its dependencies.");
                } catch (\yii\base\InvalidConfigException) {
                }
            }
        } finally {
            Craft::$app->loadedModules = $loaded;
        }

        // Read from the source, so a fallback added later is caught however it is spelled.
        foreach (['services/Repairs.php', 'services/RepairActions.php', 'controllers/RepairsController.php'] as $file) {
            $source = (string)file_get_contents(dirname(__DIR__, 2) . "/src/$file");
            self::assertDoesNotMatchRegularExpression('/(\?\?|\?:)\s*new\s+(RepairActions|Repairs|Issues|EvidenceStore|Permissions|WebDoctor)\b/', $source, $file);
        }
    }

    // What was previewed is what is carried out -----------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function changedDefinitions(): array
    {
        return [
            'its name' => [['label' => 'A different repair']],
            'its risk' => [['riskLevel' => RepairRisk::MEDIUM]],
            'why its risk is what it is' => [['reason' => 'For another reason.']],
            'how it is verified' => [['verificationText' => 'Something else shows it worked.']],
            'the checks it verifies with' => [['verifying' => ['test.check', 'other.check']]],
            'the recommendation it carries out' => [['recommendationId' => 'test.advice']],
            'a prerequisite’s ID' => [['checkedId' => 'targetReachable']],
            'a prerequisite’s description' => [['checkedDescription' => 'The target can be reached.']],
            'an acknowledged prerequisite’s description' => [['ackDescription' => 'A snapshot of the target exists.']],
            'an acknowledged prerequisite’s ID' => [['ackId' => 'snapshotTaken']],
            'an acknowledged prerequisite becoming a checked one' => [['ackAsChecked' => true]],
        ];
    }

    /**
     * The installation's state is exactly what was previewed; only what the action says about itself
     * has changed. That is not what the person agreed to.
     *
     * @param array<string, mixed> $change
     */
    #[DataProvider('changedDefinitions')]
    public function testARepairWhoseDefinitionChangedSinceItsPreviewIsRefusedThoughTheStateIsUnchanged(array $change): void
    {
        $action = $this->action(['needsAcknowledgement' => true]);
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        foreach ($change as $property => $value) {
            $action->{$property} = $value;
        }

        try {
            $this->repairs->execute($repair->id, $issue->id, true, ['backupTaken'], $this->environment);
            self::fail('A changed repair was carried out on the strength of an older preview.');
        } catch (Refusal $e) {
            self::assertStringContainsString('changed since it was previewed', $e->getMessage());
        }

        self::assertSame(0, $action->executions);
        $this->assertStillWaiting($repair, $issue);
    }

    /**
     * @return array<string, array{bool, bool, string|null}>
     */
    public static function stateAndDefinition(): array
    {
        return [
            'both unchanged' => [false, false, null],
            'the state changed' => [true, false, 'no longer what the preview showed'],
            'the definition changed' => [false, true, 'changed since it was previewed'],
            // The state is checked first, so it is the state that is named.
            'both changed' => [true, true, 'no longer what the preview showed'],
        ];
    }

    #[DataProvider('stateAndDefinition')]
    public function testBothFingerprintsHaveToHoldAndEitherAloneRefuses(bool $stateChanged, bool $definitionChanged, ?string $said): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        if ($stateChanged) {
            $action->target = ['item' => 2];
        }

        if ($definitionChanged) {
            $action->label = 'Another repair';
        }

        if ($said === null) {
            self::assertSame(RepairStatus::SUCCEEDED, $this->repairs->execute($repair->id, $issue->id, true)->status);
            self::assertSame(1, $action->executions);

            return;
        }

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('A repair was carried out with a fingerprint that no longer holds.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame(0, $action->executions);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function unbound(): array
    {
        return [
            'the issue was found again since the preview' => [static fn(self $t) => $t->raiseEach(['one', 'other']), 'found again since'],
            'the preview names another site' => [static fn(self $t, Issue $i, Repair $r) => RepairRecord::updateAll(['siteId' => $t->primarySiteId()], ['id' => $r->id]), 'different check or site'],
            'the preview names another check' => [static fn(self $t, Issue $i, Repair $r) => RepairRecord::updateAll(['diagnosticId' => 'other.check'], ['id' => $r->id]), 'different check or site'],
            'the preview was made in another environment' => [static fn(self $t, Issue $i, Repair $r) => RepairRecord::updateAll(['environment' => 'tests-production'], ['id' => $r->id]), 'previewed in the “tests-production” environment'],
            'the issue’s site has been deleted' => [static fn(self $t, Issue $i) => IssueRecord::updateAll(['siteName' => 'Gone'], ['id' => $i->id]), 'has been deleted'],
            'the installation is running as another environment' => [static function(self $t): void {
                $t->repairs->environment = 'tests-elsewhere';
            }, 'environment'],
            'the issue is being repaired' => [static function(self $t, Issue $i): void {
                $t->setStatus($i, IssueStatus::REPAIRING);
                $t->runningRepairOf($i, new DateTimeImmutable(), IssueStatus::NEW, 'test.elsewhere');
            }, 'already being repaired'],
            'the issue is won’t fix' => [static fn(self $t, Issue $i) => $t->setStatus($i, IssueStatus::WONT_FIX), 'decision not to act'],
            'the preview is reached through another issue' => [static fn() => null, 'No such repair of this issue'],
        ];
    }

    /**
     * A repair is carried out against exactly the issue, check, site, environment and finding it was
     * previewed for, or not at all.
     *
     * @param Closure(self, Issue, Repair): void $change
     */
    #[DataProvider('unbound')]
    public function testARepairIsCarriedOutOnlyWhereAndForWhatItWasPreviewed(Closure $change, string $said): void
    {
        $action = $this->action();
        [$issue, $other] = $this->raiseEach(['one', 'other']);
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $change($this, $issue, $repair);
        $through = str_contains($said, 'No such repair') ? $other : $issue;

        try {
            $this->repairs->execute($repair->id, $through->id, true);
            self::fail('A repair was carried out somewhere other than where it was previewed.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame(0, $action->executions);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function corruptRows(): array
    {
        return [
            'an unknown status' => [['status' => 'maybe']],
            'an unknown risk' => [['risk' => 'negligible']],
            'a preview that is not JSON' => [['preview' => '{not json']],
            'a preview disagreeing with its fingerprint' => [['fingerprint' => str_repeat('a', 64)]],
            'a definition fingerprint that is not one' => [['definitionFingerprint' => 'x']],
            'a prerequisite that does not read' => [['prerequisites' => '[{"id":7,"kind":"guessed"}]']],
            'prerequisites that are not a list' => [['prerequisites' => '{"a":1}']],
            'no checks to verify with' => [['verifyWith' => '[]']],
            'no environment' => [['environment' => ' ']],
        ];
    }

    /**
     * A row this version cannot read in full can be read as history, never carried out.
     *
     * @param array<string, mixed> $columns
     */
    #[DataProvider('corruptRows')]
    public function testARepairRowThatCannotBeReadInFullIsNeverCarriedOut(array $columns): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        RepairRecord::updateAll($columns, ['id' => $repair->id]);

        $read = $this->repairs->get($repair->id);

        self::assertNotNull($read);
        self::assertFalse($read->isIntact());
        self::assertFalse($read->awaitsConfirmation());

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('A corrupt repair was carried out.');
        } catch (Refusal $e) {
            self::assertStringContainsString('cannot be read in full', $e->getMessage());
        }

        self::assertSame(0, $action->executions);

        // Nor is it offered for confirmation on its page.
        $this->request('GET');
        $html = $this->render($this->controller(), 'detail', 'web-doctor/_repairs/_repair', ['issueId' => $issue->id, 'repairId' => $repair->id]);
        self::assertStringNotContainsString('web-doctor/repairs/execute', $html);
        self::assertStringContainsString('can be read as history but never carried out', $html);
    }

    public function testCarryingOutARepairChangesOnlyItsLifecycleNeverWhatWasPreviewed(): void
    {
        $this->action(['needsAcknowledgement' => true]);
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');
        $identity = ['issueId', 'issueTitle', 'diagnosticId', 'findingRunId', 'action', 'actionName', 'risk', 'riskReason', 'verifyWith', 'verificationNote', 'environment', 'siteId', 'preview', 'fingerprint', 'definitionFingerprint', 'prerequisites', 'previewedBy', 'previewedAt'];
        $read = static fn(): array => RepairRecord::find()->select($identity)->where(['id' => $repair->id])->asArray()->one() ?? [];
        $before = $read();

        $this->repairs->execute($repair->id, $issue->id, true, ['backupTaken']);

        self::assertSame($before, $read());
    }

    // Ending a repair, and recovering from an ending that could not be written -----------------

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function endingFailures(): array
    {
        return [
            'the repair’s row will not write' => ['row'],
            'putting the issue back fails' => ['issue'],
            'the commit fails' => ['commit'],
        ];
    }

    /**
     * However the ending fails, nothing of it stands and nothing is tried a second time: the repair is
     * running, holding its lock, its issue repairing — the one recoverable state — and it is recovered
     * from once the repair reads as stopped, never read as having succeeded.
     */
    #[DataProvider('endingFailures')]
    public function testAnEndingThatCannotBeWrittenLeavesOnlyTheRecoverableStateAndIsRecovered(string $failAt): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $this->issues->transition($issue->id, IssueStatus::CONFIRMED);
        $working = $this->repairs;
        $failing = $this->failing($failAt);
        $repair = $failing->prepare($issue->id, 'test.repair');

        $done = $failing->execute($repair->id, $issue->id, true);

        self::assertSame(1, $action->executions);
        self::assertSame(1, $this->endWrites, 'The ending was written more than once.');
        self::assertSame(RepairStatus::RUNNING, $done->status);
        self::assertNotNull(RepairRecord::findOne($done->id)->lockKey);
        self::assertSame(IssueStatus::REPAIRING, $this->reread($issue)->status);
        $this->assertConsistent();

        // Until it reads as stopped, nothing else of its kind starts here.
        $this->repairs = $working;

        try {
            $this->repairs->prepare($issue->id, 'test.repair');
            self::fail('A second repair started while the first one’s end was unknown.');
        } catch (Refusal $e) {
            self::assertStringContainsString('already being repaired', $e->getMessage());
        }

        // Once it does, it is ended honestly and the issue put back.
        RepairRecord::updateAll(['startedAt' => Db::prepareDateForDb(new DateTimeImmutable(sprintf('-%d seconds', Repair::STOPPED_AFTER + 60)))], ['id' => $done->id]);
        $next = $this->repairs->prepare($issue->id, 'test.repair');
        $ended = $this->repairs->get($done->id);

        self::assertSame(RepairStatus::FAILED, $ended->status);
        self::assertStringContainsString('Stopped without an ending', (string)$ended->failure);
        self::assertSame(VerificationStatus::NONE, $ended->verificationStatus);
        self::assertSame(IssueStatus::CONFIRMED, $this->reread($issue)->status);
        $this->assertConsistent();

        $this->repairs->execute($next->id, $issue->id, true);
        self::assertSame(2, $action->executions);
        $this->assertConsistent();
    }

    public function testARequestThatOutlivedItsTakeoverCannotRewriteItsEndingOrTheIssueANewerRepairHolds(): void
    {
        // Repair A is still running when it is read as stopped and taken over by B, a repair of the same
        // kind for another issue. When A's request finally finishes, it finds its row already ended
        // and writes nothing: not its own ending, not its issue, and not B's.
        [$first, $second] = $this->raiseEach(['one', 'other']);
        $this->issues->transition($first->id, IssueStatus::INVESTIGATING);
        $nested = false;
        $repairB = null;
        $action = $this->action(['onExecute' => function() use (&$nested, &$repairB, $first, $second): void {
            if ($nested) {
                return;
            }

            $nested = true;
            RepairRecord::updateAll(
                ['startedAt' => Db::prepareDateForDb(new DateTimeImmutable(sprintf('-%d seconds', Repair::STOPPED_AFTER + 60)))],
                ['issueId' => $first->id, 'status' => RepairStatus::RUNNING->value],
            );
            $repairB = $this->repairs->execute($this->repairs->prepare($second->id, 'test.repair')->id, $second->id, true);
        }]);
        $repairA = $this->repairs->prepare($first->id, 'test.repair');

        $a = $this->repairs->execute($repairA->id, $first->id, true);

        self::assertSame(RepairStatus::FAILED, $a->status);
        self::assertStringContainsString('Stopped without an ending', (string)$a->failure);
        self::assertSame(RepairStatus::SUCCEEDED, $repairB?->status);
        self::assertSame(RepairStatus::SUCCEEDED, $this->repairs->get($repairB->id)->status);
        // A's issue was put back by the takeover, once, and not touched again by A's late ending.
        self::assertSame(IssueStatus::INVESTIGATING, $this->reread($first)->status);
        self::assertSame(IssueStatus::NEW, $this->reread($second)->status);
        $this->assertConsistent();

        // And a repair taken over is never carried out again.
        try {
            $this->repairs->execute($repairA->id, $first->id, true);
            self::fail('A repair taken over as stopped was carried out again.');
        } catch (Refusal $e) {
            self::assertStringContainsString('already been carried out', $e->getMessage());
        }

        self::assertSame(2, $action->executions);
    }

    public function testALateEndingNeverPutsBackAnIssueANewerRepairOfItIsCarryingOut(): void
    {
        // A's request outlived the hour; A was ended as stopped and B, a new repair of the same issue,
        // is running. A's ending arrives now — mid-B — and must leave the issue repairing under B.
        $issue = $this->raise();
        $this->setStatus($issue, IssueStatus::REPAIRING);
        $zombie = RepairRecord::findOne($this->runningRepairOf($issue, new DateTimeImmutable(sprintf('-%d seconds', Repair::STOPPED_AFTER + 60)), IssueStatus::NEW));
        self::assertNotNull($zombie);
        $finish = new \ReflectionMethod(Repairs::class, 'finish');
        $during = null;
        $this->action(['onExecute' => function() use ($finish, $zombie, $issue, &$during): void {
            $finish->invoke($this->repairs, $zombie, IssueStatus::NEW, null, null, 1.0, 1);
            $during = $this->reread($issue)->status;
        }]);

        $b = $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);

        self::assertSame(IssueStatus::REPAIRING, $during, 'A late ending put the issue back in the middle of a newer repair.');
        self::assertSame(RepairStatus::SUCCEEDED, $b->status);
        self::assertSame(RepairStatus::FAILED, $this->repairs->get($zombie->id)->status);
        self::assertStringContainsString('Stopped without an ending', (string)$this->repairs->get($zombie->id)->failure);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function safetyOrder(): array
    {
        $expire = static fn(self $t, Issue $i, Repair $r) => $t->age($r, Repair::PREVIEW_EXPIRES_AFTER + 60);

        // [what is wrong, first, then second; the refusal that has to be the one given]
        return [
            'signed out, and expired' => [[static fn(self $t) => $t->signOut(), $expire], 'somebody signed in', true],
            'carried out already, and another environment' => [[static fn(self $t, Issue $i, Repair $r) => RepairRecord::updateAll(['status' => RepairStatus::SUCCEEDED->value], ['id' => $r->id]), static function(self $t): void {
                $t->repairs->environment = 'tests-elsewhere';
            }], 'already been carried out', false],
            'another environment, and ignored' => [[static function(self $t): void {
                $t->repairs->environment = 'tests-elsewhere';
            }, static fn(self $t, Issue $i) => $t->setStatus($i, IssueStatus::IGNORED)], 'previewed in the', false],
            'ignored, and found again' => [[static fn(self $t, Issue $i) => $t->setStatus($i, IssueStatus::IGNORED), static fn(self $t) => $t->raise()], 'decision not to act', false],
            'found again, and Craft would not allow it' => [[static fn(self $t) => $t->raise(), static function(self $t): void {
                $t->lastAction->authorized = false;
            }], 'found again since', false],
            'Craft would not allow it, and expired' => [[static function(self $t): void {
                $t->lastAction->authorized = false;
            }, $expire], 'Craft would not let you', false],
            'expired, and not confirmed' => [[$expire, static fn() => null], 'expired', false],
            'the state changed, and the definition' => [[static function(self $t): void {
                $t->lastAction->target = ['item' => 2];
            }, static function(self $t): void {
                $t->lastAction->label = 'Another';
            }], 'no longer what the preview showed', true],
            'a prerequisite no longer holds, and the state changed' => [[static function(self $t): void {
                $t->lastAction->prerequisiteMet = false;
            }, static function(self $t): void {
                $t->lastAction->target = ['item' => 2];
            }], 'no longer holds', true],
        ];
    }

    /**
     * With two things wrong at once, the one earlier in the safety order is the one refused — so each
     * check is known to run before the next.
     *
     * @param list<Closure> $wrongs
     */
    #[DataProvider('safetyOrder')]
    public function testChecksRunInTheSafetyOrder(array $wrongs, string $said, bool $confirmed): void
    {
        $this->action();
        $issue = $this->raise();
        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        foreach ($wrongs as $wrong) {
            $wrong($this, $issue, $repair);
        }

        try {
            $this->repairs->execute($repair->id, $issue->id, $confirmed);
            self::fail('Nothing was refused.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame(0, $this->lastAction->executions);
    }

    public function testAnIssueLeftRepairingWithNoRepairUnderWayIsPutBackWhereItsRepairFoundIt(): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $finished = $this->runningRepairOf($issue, new DateTimeImmutable('-5 minutes'), IssueStatus::INVESTIGATING);
        RepairRecord::updateAll(['status' => RepairStatus::FAILED->value, 'lockKey' => null], ['id' => $finished]);
        $this->setStatus($issue, IssueStatus::REPAIRING);

        self::assertNull($this->repairs->refusal($this->reread($issue)));

        $repair = $this->repairs->prepare($issue->id, 'test.repair');

        self::assertSame(IssueStatus::INVESTIGATING, $this->reread($issue)->status);
        $this->repairs->execute($repair->id, $issue->id, true);
        self::assertSame(1, $action->executions);
        self::assertSame(IssueStatus::INVESTIGATING, $this->reread($issue)->status);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function stoppingBoundaries(): array
    {
        return [
            'a second short of the limit' => [Repair::STOPPED_AFTER - 1, false],
            'exactly at the limit' => [Repair::STOPPED_AFTER, false],
            'a second past it' => [Repair::STOPPED_AFTER + 1, true],
        ];
    }

    /**
     * Stopping and expiring are decided by the server's clock, against what was stored.
     */
    #[DataProvider('stoppingBoundaries')]
    public function testARepairIsReadAsStoppedOnlyPastItsLimitAndAPreviewExpiresOnlyPastItsOwn(int $elapsed, bool $past): void
    {
        $this->action();
        $issue = $this->raise();
        $running = $this->repairs->get($this->runningRepairOf($issue, new DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')), IssueStatus::NEW, 'test.elsewhere'));
        $preview = $this->repairs->prepare($issue->id, 'test.repair');
        RepairRecord::updateAll(['previewedAt' => '2026-01-01 00:00:00'], ['id' => $preview->id]);
        $preview = $this->repairs->get($preview->id);
        $at = static fn(int $seconds): DateTimeImmutable => new DateTimeImmutable('2026-01-01 00:00:00 UTC +' . $seconds . ' seconds');

        self::assertSame($past, $running?->hasStopped($at($elapsed)));
        self::assertSame($elapsed > Repair::PREVIEW_EXPIRES_AFTER, $preview?->isExpired($at($elapsed)));
        self::assertFalse($preview->isExpired($at(Repair::PREVIEW_EXPIRES_AFTER)));
        self::assertTrue($preview->isExpired($at(Repair::PREVIEW_EXPIRES_AFTER + 1)));
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function stoppedLeftovers(): array
    {
        return [
            'where its issue stood is unreadable' => ['perhaps', true],
            'its issue has been deleted' => [IssueStatus::NEW->value, false],
        ];
    }

    #[DataProvider('stoppedLeftovers')]
    public function testAStoppedRepairIsEndedEvenWhenWhatItLeftBehindCannotBeFullyRead(?string $before, bool $issueKept): void
    {
        $action = $this->action();
        [$issue, $other] = $this->raiseEach(['one', 'other']);
        $this->setStatus($other, IssueStatus::REPAIRING);
        $stopped = $this->runningRepairOf($other, new DateTimeImmutable(sprintf('-%d seconds', Repair::STOPPED_AFTER + 60)));
        RepairRecord::updateAll(['issueStatusBefore' => $before], ['id' => $stopped]);

        if (!$issueKept) {
            IssueRecord::deleteAll(['id' => $other->id]);
        }

        // Its lock is the one this repair needs, so claiming it ends the stopped one.
        $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);

        self::assertSame(1, $action->executions);
        self::assertSame(RepairStatus::FAILED, $this->repairs->get($stopped)?->status);
        self::assertNull(RepairRecord::findOne($stopped)?->lockKey);

        if ($issueKept) {
            // Put back somewhere open, never left repairing, when where it stood cannot be read.
            self::assertSame(IssueStatus::CONFIRMED, $this->reread($other)->status);
        }
    }

    // Duplicates and concurrency, further -----------------------------------------------------

    public function testLocksAreHeldPerEnvironmentSoAnotherEnvironmentsRepairDoesNotBlockThisOne(): void
    {
        $action = $this->action();
        $issue = $this->raise();
        $elsewhere = $this->runningRepairOf($issue, new DateTimeImmutable(), IssueStatus::NEW, 'test.repair', 'tests-another-environment');
        RepairRecord::updateAll(['issueId' => null], ['id' => $elsewhere]);

        $this->repairs->execute($this->repairs->prepare($issue->id, 'test.repair')->id, $issue->id, true);

        self::assertSame(1, $action->executions);
        self::assertNotSame(Repairs::lockKey('test.repair', $this->environment), Repairs::lockKey('test.repair', 'tests-another-environment'));
        RepairRecord::deleteAll(['id' => $elsewhere]);
    }

    public function testALosingConcurrentConfirmationLeavesTheIssueAsTheWinnerLeftIt(): void
    {
        // The winner is carrying out the repair; the loser must neither run it nor touch the issue.
        $action = $this->action();
        [$first, $second] = $this->raiseEach(['one', 'other']);
        $this->setStatus($first, IssueStatus::REPAIRING);
        $this->runningRepairOf($first, new DateTimeImmutable(), IssueStatus::NEW);
        $this->issues->transition($second->id, IssueStatus::INVESTIGATING);
        $repair = $this->repairs->prepare($second->id, 'test.repair');

        try {
            $this->repairs->execute($repair->id, $second->id, true);
            self::fail('The losing confirmation went through.');
        } catch (Refusal) {
        }

        self::assertSame(0, $action->executions);
        self::assertSame(IssueStatus::REPAIRING, $this->reread($first)->status);
        self::assertSame(IssueStatus::INVESTIGATING, $this->reread($second)->status);
    }

    // The shipped actions, further -------------------------------------------------------------

    public function testOnlyTheMissingStorageDirectoriesAreCreatedAndNothingExistingChanges(): void
    {
        $root = $this->directory();
        mkdir("$root/storage", 0750);
        touch("$root/storage/keep.txt", 1_000_000_000);
        $permissions = fileperms("$root/storage");
        $mtime = filemtime("$root/storage/keep.txt");
        $check = $this->storageCheck([
            'storage' => "$root/storage",
            'runtime' => "$root/storage/runtime",
            'logs' => "$root/storage/logs",
            'compiledTemplates' => "$root/storage/compiled/templates",
        ]);
        $this->actions->register(new CreateStorageDirectories(['check' => $check]));
        $issue = $this->raiseFrom($check);

        $done = $this->repairs->execute($this->repairs->prepare($issue->id, CreateStorageDirectories::ID)->id, $issue->id, true);

        self::assertSame(RepairStatus::SUCCEEDED, $done->status);
        self::assertSame(['compiledTemplates', 'logs', 'runtime'], $done->outcome?->state[0]->data['created'] ?? null);
        clearstatcache();
        self::assertSame($permissions, fileperms("$root/storage"));
        self::assertSame($mtime, filemtime("$root/storage/keep.txt"));
        self::assertSame(['compiled', 'keep.txt', 'logs', 'runtime'], array_values(array_diff(scandir("$root/storage") ?: [], ['.', '..'])));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function storageChangedSincePreview(): array
    {
        return [
            'the directory appeared' => [static fn(string $root) => mkdir("$root/storage/runtime"), 'no longer'],
            'another directory went missing' => [static fn(string $root) => rmdir("$root/storage/logs"), 'no longer what the preview showed'],
        ];
    }

    /**
     * @param Closure(string): void $change
     */
    #[DataProvider('storageChangedSincePreview')]
    public function testStorageDirectoriesAreNotCreatedWhenWhatIsMissingChangedSinceThePreview(Closure $change, string $said): void
    {
        $root = $this->directory();
        mkdir("$root/storage");
        mkdir("$root/storage/logs");
        $check = $this->storageCheck(['storage' => "$root/storage", 'runtime' => "$root/storage/runtime", 'logs' => "$root/storage/logs"]);
        $this->actions->register(new CreateStorageDirectories(['check' => $check]));
        $issue = $this->raiseFrom($check);
        $repair = $this->repairs->prepare($issue->id, CreateStorageDirectories::ID);
        $change($root);
        $listing = scandir("$root/storage");

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('Directories were created that the preview did not show.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame($listing, scandir("$root/storage"));
    }

    public function testALinkWhoseTargetHasGoneIsNotCreatedOver(): void
    {
        $root = $this->directory();
        mkdir("$root/storage");
        symlink("$root/nowhere", "$root/storage/logs");
        $check = $this->storageCheck(['storage' => "$root/storage", 'logs' => "$root/storage/logs"]);
        $this->actions->register(new CreateStorageDirectories(['check' => $check]));
        $issue = $this->raiseFrom($check);
        $repair = $this->repairs->prepare($issue->id, CreateStorageDirectories::ID);

        self::assertFalse($repair->checkedPrerequisitesMet());

        try {
            $this->repairs->execute($repair->id, $issue->id, true);
            self::fail('A directory was created over a dangling link.');
        } catch (Refusal) {
        }

        self::assertTrue(is_link("$root/storage/logs"));
        self::assertDirectoryDoesNotExist("$root/nowhere");
    }

    public function testAtMostFiftyJobsAreRetriedTheOldestAndEachReadIsBoundedWhateverTheCount(): void
    {
        $queue = $this->queue();
        $ids = array_map(fn(int $i): int => $this->job($queue, fail: true, description: "Job $i"), range(1, RetryFailedJobs::MAX_JOBS + 1));
        $action = new RetryFailedJobs(['queue' => $queue]);
        $this->actions->register($action);
        $issue = $this->raiseFrom(new FailedJobsDiagnostic(['queue' => $queue]));
        $context = new \Tahadudhiya\WebDoctor\models\RepairContext($issue, \Tahadudhiya\WebDoctor\models\RecommendationCase::fromIssue($issue, []), $this->environment, 1);

        // The same number of statements to preview fifty-one jobs as to preview one.
        $many = count($this->statementsDuring(static fn() => $action->preview($context)));
        $repair = $this->repairs->prepare($issue->id, RetryFailedJobs::ID);

        self::assertCount(RetryFailedJobs::MAX_JOBS, $repair->preview->items);

        $done = $this->repairs->execute($repair->id, $issue->id, true, ['safeToRepeat', 'causeDealtWith']);

        self::assertSame(array_slice($ids, 0, RetryFailedJobs::MAX_JOBS), $done->outcome?->state[0]->data['retried'] ?? null);
        self::assertSame(1, (int)$this->row($queue, $ids[RetryFailedJobs::MAX_JOBS])['fail'], 'A job past the bound was retried.');

        Craft::$app->getDb()->createCommand()->delete($queue->tableName)->execute();
        $this->job($queue, fail: true);
        self::assertSame($many, count($this->statementsDuring(static fn() => $action->preview($context))));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function queueChangedSincePreview(): array
    {
        return [
            'a job was released' => [static fn(self $t, StubQueue $q, array $ids) => Craft::$app->getDb()->createCommand()->delete($q->tableName, ['id' => $ids[1]])->execute(), 'no longer what the preview showed'],
            'a job was replaced by another' => [static function(self $t, StubQueue $q, array $ids): void {
                Craft::$app->getDb()->createCommand()->delete($q->tableName, ['id' => $ids[1]])->execute();
                $t->job($q, fail: true, description: 'A newcomer');
            }, 'no longer what the preview showed'],
            'a job’s failure time moved' => [static fn(self $t, StubQueue $q, array $ids) => $t->updateJob($q, $ids[0], ['dateFailed' => Db::prepareDateForDb(new DateTimeImmutable('-1 minute'))]), 'no longer what the preview showed'],
            'the queue is another table with the same rows' => [static fn(self $t, StubQueue $q) => $t->copyQueueTable($q), 'no longer what the preview showed'],
            'the queue is another channel' => [static function(self $t, StubQueue $q): void {
                $q->channel = 'someone-else';
            }, 'no longer'],
            'one ran out of memory, beside one that did not' => [static fn(self $t, StubQueue $q, array $ids) => $t->updateJob($q, $ids[1], ['error' => 'PHP Fatal error: Allowed memory size of 268435456 bytes exhausted (tried to allocate 4096 bytes)']), 'ran out of memory'],
        ];
    }

    /**
     * @param Closure(self, StubQueue, list<int>): void $change
     */
    #[DataProvider('queueChangedSincePreview')]
    public function testNoJobIsRetriedWhenTheQueueOrItsFailedJobsChangedSinceThePreview(Closure $change, string $said): void
    {
        $queue = $this->queue();
        $ids = [$this->job($queue, fail: true, description: 'One'), $this->job($queue, fail: true, description: 'Two')];
        $this->actions->register(new RetryFailedJobs(['queue' => $queue]));
        $issue = $this->raiseFrom(new FailedJobsDiagnostic(['queue' => $queue]));
        $repair = $this->repairs->prepare($issue->id, RetryFailedJobs::ID);
        $change($this, $queue, $ids);
        $rows = (new \craft\db\Query())->from($queue->tableName)->orderBy(['id' => SORT_ASC])->all();

        try {
            $this->repairs->execute($repair->id, $issue->id, true, ['safeToRepeat', 'causeDealtWith']);
            self::fail('Jobs were retried that the preview did not show.');
        } catch (Refusal $e) {
            self::assertStringContainsString($said, $e->getMessage());
        }

        self::assertSame($rows, (new \craft\db\Query())->from($queue->tableName)->orderBy(['id' => SORT_ASC])->all());
    }

    public function testARetryThatFailsPartWayIsRecordedAsFailedWithWhatItDidAndTheIssuePutBack(): void
    {
        $queue = $this->queue(new class() extends StubQueue {
            public int $calls = 0;

            public function retry(string $id): void
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('Deadlock updating queue WHERE password=hunter2');
                }

                parent::retry($id);
            }
        });
        $ids = [$this->job($queue, fail: true), $this->job($queue, fail: true), $this->job($queue, fail: true)];
        $this->actions->register(new RetryFailedJobs(['queue' => $queue]));
        $issue = $this->raiseFrom(new FailedJobsDiagnostic(['queue' => $queue]));

        $done = $this->repairs->execute($this->repairs->prepare($issue->id, RetryFailedJobs::ID)->id, $issue->id, true, ['safeToRepeat', 'causeDealtWith']);

        self::assertSame(RepairStatus::FAILED, $done->status);
        self::assertSame(VerificationStatus::NONE, $done->verificationStatus);
        self::assertStringNotContainsString('hunter2', (string)$done->failure);
        self::assertStringNotContainsString('Deadlock', (string)$done->failure);
        self::assertSame(0, (int)$this->row($queue, $ids[0])['fail'], 'The job before the failure was retried.');
        self::assertSame(1, (int)$this->row($queue, $ids[2])['fail'], 'A job after the failure was retried.');
        self::assertNull(RepairRecord::findOne($done->id)?->lockKey);
        self::assertSame(IssueStatus::NEW, $this->reread($issue)->status);
        self::assertStringNotContainsString('hunter2', (string)json_encode(RepairRecord::find()->where(['id' => $done->id])->asArray()->one()));
    }

    public function testNothingPostedCanChooseWhatARepairChanges(): void
    {
        // A request names the issue, the preview and the confirmation. Whatever else it carries —
        // paths, jobs, tables, the environment — is never read.
        $queue = $this->queue();
        $mine = $this->job($queue, fail: true);
        $foreign = $this->job($queue, fail: true, channel: 'someone-else');
        $this->actions->register(new RetryFailedJobs(['queue' => $queue]));
        $issue = $this->raiseFrom(new FailedJobsDiagnostic(['queue' => $queue]));
        $repair = $this->repairs->prepare($issue->id, RetryFailedJobs::ID);

        $this->post([
            'issueId' => (string)$issue->id,
            'repairId' => (string)$repair->id,
            'confirm' => '1',
            'acknowledged' => ['safeToRepeat', 'causeDealtWith'],
            'jobIds' => [(string)$foreign],
            'ids' => [(string)$foreign],
            'tableName' => '{{%queue}}',
            'channel' => 'someone-else',
            'path' => '/etc',
            'environment' => 'production',
            'siteId' => '1',
            'diagnosticId' => 'storage.paths',
            'repairAction' => 'storage.createDirectories',
            'fingerprint' => str_repeat('0', 64),
        ]);
        $this->controller()->runAction('execute');

        self::assertSame(0, (int)$this->row($queue, $mine)['fail']);
        self::assertSame(1, (int)$this->row($queue, $foreign)['fail'], 'A posted job ID was retried.');
        self::assertSame(RepairStatus::SUCCEEDED, $this->repairs->get($repair->id)?->status);
        self::assertSame(RetryFailedJobs::ID, $this->repairs->get($repair->id)->action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function advertisedRepairs(): array
    {
        $runner = [self::ACCESS_CP, Permissions::VIEW, Permissions::VIEW_ISSUES, Permissions::VIEW_EVIDENCE, Permissions::RUN_REPAIRS];

        return [
            'an open issue, somebody who may run it' => [null, $runner, true, 'Web Doctor can carry this out once you have previewed and confirmed it'],
            'an ignored issue' => [IssueStatus::IGNORED, $runner, true, 'cannot be carried out here now'],
            'somebody Craft would not allow' => [null, $runner, false, 'cannot be carried out here now'],
            'a resolved issue' => [IssueStatus::RESOLVED, $runner, true, 'This issue is resolved, so there is nothing to act on'],
        ];
    }

    /**
     * The advice points at a repair only when there is one for this finding, and says it can be
     * previewed only when this reader, here and now, could.
     *
     * @param list<string> $permissions
     */
    #[DataProvider('advertisedRepairs')]
    public function testTheAdviceOffersARepairOnlyWhereItCouldActuallyBePrepared(?IssueStatus $status, array $permissions, bool $authorized, string $said): void
    {
        $root = $this->directory();
        $check = $this->storageCheck(['storage' => "$root/storage"]);
        $this->actions->register(new class(['check' => $check, 'allowed' => $authorized]) extends CreateStorageDirectories {
            public bool $allowed = true;

            public function isAuthorized(): bool
            {
                return $this->allowed;
            }
        });
        $issue = $this->raiseFrom($check);

        if ($status !== null) {
            $this->setStatus($issue, $status);
        }

        $this->signIn(admin: false, permissions: $permissions);
        $this->request('GET');
        $html = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);

        self::assertStringContainsString($said, $html);

        if ($said !== 'Web Doctor can carry this out once you have previewed and confirmed it') {
            self::assertStringNotContainsString('Web Doctor can carry this out once you have previewed and confirmed it', $html);
            self::assertStringNotContainsString('web-doctor/repairs/prepare', $html);
        }
    }

    // Secrets ----------------------------------------------------------------------------------

    public function testNoCredentialReachesARepairsRowItsIssuesHistoryItsPageOrTheLog(): void
    {
        $secrets = [
            'password=hunter2-a', 'passwd: hunter2-b', 'DB_PASSWORD=hunter2-c', 'api_key=hunter2-d',
            'Authorization: Bearer hunter2-e', 'secret=hunter2-f', 'token=hunter2-g',
            'https://user:hunter2-h@example.com/x', '{"password":"hunter2-i"}', "SELECT * FROM users WHERE pwd='hunter2-j'",
        ];
        $original = \Yii::getLogger();
        $logger = new \yii\log\Logger();
        \Yii::setLogger($logger);

        try {
            $action = new class($secrets) extends TestRepairAction {
                /**
                 * @param list<string> $secrets
                 */
                public function __construct(private array $secrets)
                {
                    parent::__construct([
                        'label' => 'Repair ' . $secrets[0],
                        'reason' => 'Because ' . $secrets[1],
                        'verificationText' => 'Check ' . $secrets[2],
                        'checkedDescription' => 'Reads ' . $secrets[3],
                        'needsAcknowledgement' => true,
                        'ackDescription' => 'Acknowledge ' . $secrets[4],
                    ]);
                }

                public function preview(\Tahadudhiya\WebDoctor\models\RepairContext $context): \Tahadudhiya\WebDoctor\models\RepairReport
                {
                    return new \Tahadudhiya\WebDoctor\models\RepairReport(
                        'Would do ' . $this->secrets[5],
                        [$this->secrets[6], $this->secrets[7]],
                        [new Evidence(EvidenceType::CONFIGURATION, 'State ' . $this->secrets[8], 'test.repair', ['query' => $this->secrets[9], 'password' => 'hunter2-k'])],
                        \Tahadudhiya\WebDoctor\models\RepairReport::fingerprintOf($this->target),
                    );
                }

                public function execute(\Tahadudhiya\WebDoctor\models\RepairContext $context, \Tahadudhiya\WebDoctor\models\RepairReport $preview): \Tahadudhiya\WebDoctor\models\RepairReport
                {
                    throw new RuntimeException(implode(' ', $this->secrets));
                }
            };
            $this->actions->register($action);
            $issue = $this->raise();
            $repair = $this->repairs->prepare($issue->id, 'test.repair');
            $done = $this->repairs->execute($repair->id, $issue->id, true, ['backupTaken']);

            $this->request('GET');
            $html = $this->render($this->controller(), 'detail', 'web-doctor/_repairs/_repair', ['issueId' => $issue->id, 'repairId' => $done->id]);
            $issuePage = $this->render($this->issuesController(), 'detail', 'web-doctor/_issues/_issue', ['issueId' => $issue->id]);
        } finally {
            \Yii::setLogger($original);
        }

        $stored = (string)json_encode([
            RepairRecord::find()->where(['id' => $done->id])->asArray()->one(),
            \Tahadudhiya\WebDoctor\records\IssueEventRecord::find()->where(['issueId' => $issue->id])->asArray()->all(),
        ]);
        $log = implode("\n", array_map(static fn(array $m): string => (string)$m[0], $logger->messages));

        foreach (range('a', 'k') as $letter) {
            foreach (['row and history' => $stored, 'repair page' => $html, 'issue page' => $issuePage, 'log' => $log] as $where => $text) {
                self::assertStringNotContainsString("hunter2-$letter", $text, "hunter2-$letter reached the $where.");
            }
        }

        self::assertSame(RepairStatus::FAILED, $done->status);
        self::assertStringNotContainsString(Redaction::REDACTED, $html);
        self::assertStringNotContainsString(Redaction::REDACTED, $issuePage);
        self::assertStringContainsString('example.com', $html);
    }

    // Helpers for the tests above --------------------------------------------------------------

    /**
     * The service with one stage of a repair's ending made to fail, as a database that went away there
     * would: the row, putting the issue back, or the commit. Counts how often the ending is written.
     */
    private function failing(string $failAt): Repairs
    {
        $issues = new class(['evidence' => new EvidenceStore()]) extends Issues {
            public bool $failEndRepair = false;

            public function endRepair(int $issueId, IssueStatus $restore, string $note, ?int $userId = null): void
            {
                if ($this->failEndRepair) {
                    throw new RuntimeException('Putting the issue back failed.');
                }

                parent::endRepair($issueId, $restore, $note, $userId);
            }
        };
        $issues->failEndRepair = $failAt === 'issue';

        $this->endWrites = 0;
        $config = [
            'actions' => $this->actions,
            'issues' => $issues,
            'evidence' => new EvidenceStore(),
            'permissions' => new Permissions(),
            'environment' => $this->environment,
        ];
        $onWrite = function(): void {
            $this->endWrites++;
        };

        return new class($failAt, $onWrite, $config) extends Repairs {
            /**
             * @param array<string, mixed> $config
             */
            public function __construct(private string $failAt, private Closure $onWrite, array $config)
            {
                parent::__construct($config);
            }

            protected function writeEnd(RepairRecord $record, array $columns, string $lockKey): int
            {
                ($this->onWrite)();

                if ($this->failAt === 'row') {
                    throw new RuntimeException('The repair row would not write.');
                }

                return parent::writeEnd($record, $columns, $lockKey);
            }

            protected function commit(\yii\db\Transaction $transaction): void
            {
                if ($this->failAt === 'commit') {
                    throw new RuntimeException('The commit failed.');
                }

                parent::commit($transaction);
            }
        };
    }

    /**
     * What every repair of this test's environment, and every issue, must be true of at any moment
     * between requests: a running repair holds its lock, an ended one holds none, and an issue is
     * repairing only while a repair of it is running.
     */
    private function assertConsistent(): void
    {
        foreach (RepairRecord::find()->where(['like', 'environment', 'tests-%', false])->all() as $record) {
            self::assertInstanceOf(RepairRecord::class, $record);
            $running = $record->status === RepairStatus::RUNNING->value;
            self::assertSame($running, $record->lockKey !== null, "Repair {$record->id} is {$record->status} with the lock " . ($record->lockKey === null ? 'released' : 'held') . '.');
        }

        foreach (IssueRecord::find()->where(['environment' => $this->environment, 'status' => IssueStatus::REPAIRING->value])->all() as $issue) {
            self::assertInstanceOf(IssueRecord::class, $issue);
            self::assertTrue(
                RepairRecord::find()->where(['issueId' => $issue->id, 'status' => RepairStatus::RUNNING->value])->exists(),
                "Issue {$issue->id} is repairing with no repair of it running.",
            );
        }
    }

    public function signOut(): void
    {
        Craft::$app->getUser()->setIdentity(null);
    }

    public function copyQueueTable(StubQueue $queue): void
    {
        $db = Craft::$app->getDb();
        $copy = '{{%wdtest_queue_' . bin2hex(random_bytes(4)) . '}}';
        $db->createCommand(sprintf('CREATE TABLE %s LIKE %s', $db->quoteTableName($copy), $db->quoteTableName($queue->tableName)))->execute();
        $db->createCommand(sprintf('INSERT INTO %s SELECT * FROM %s', $db->quoteTableName($copy), $db->quoteTableName($queue->tableName)))->execute();
        $this->extraTables[] = $copy;
        $queue->tableName = $copy;
    }

    /**
     * @return list<string>
     */
    private function statementsDuring(Closure $callback): array
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

    public function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    // Helpers ------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function action(array $config = []): TestRepairAction
    {
        $action = new TestRepairAction($config);
        $this->actions->register($action);
        $this->lastAction = $action;

        return $action;
    }

    /**
     * An issue raised the way a run would raise one.
     */
    private function raise(): Issue
    {
        return $this->raiseEach([null])[0];
    }

    /**
     * Several issues of the test check, one per affected component, raised by one run — a later run
     * of the same check that did not report an earlier finding would observe that one clear.
     *
     * @param list<string|null> $components
     * @return list<Issue>
     */
    private function raiseEach(array $components): array
    {
        $context = new DiagnosticContext(environment: $this->environment);
        $now = new DateTimeImmutable();
        $results = array_map(fn(?string $component): DiagnosticResult => (new DiagnosticResult(
            diagnosticId: 'test.check',
            name: 'Check test.check',
            category: DiagnosticCategory::CONFIGURATION,
            status: DiagnosticStatus::FAIL,
            summary: 'test.check reports a problem.',
            affectedComponent: $component,
        ))->withExecution($context, $now, $now, 1.0), $components);

        $this->issues->reconcile(new DiagnosticRun(context: $context, results: $results, startedAt: $now, finishedAt: $now, durationMs: 1.0));

        return array_map(fn(DiagnosticResult $result): Issue => $this->issueFor($result, $context), $results);
    }

    /**
     * An issue raised from a real check's result.
     */
    private function raiseFrom(\Tahadudhiya\WebDoctor\base\DiagnosticInterface $check): Issue
    {
        $context = new DiagnosticContext(environment: $this->environment, depth: DiagnosticDepth::NORMAL);
        $now = new DateTimeImmutable();
        $result = $check->run($context)->withExecution($context, $now, $now, 1.0);

        self::assertTrue($result->status->isProblem(), 'The check did not report the problem the test set up: ' . $result->summary);

        return $this->reconcile($result, $context);
    }

    private function reconcile(DiagnosticResult $result, DiagnosticContext $context): Issue
    {
        $now = new DateTimeImmutable();
        $this->issues->reconcile(new DiagnosticRun(context: $context, results: [$result], startedAt: $now, finishedAt: $now, durationMs: 1.0));

        return $this->issueFor($result, $context);
    }

    private function issueFor(DiagnosticResult $result, DiagnosticContext $context): Issue
    {
        $issue = $this->issues->getByFingerprint(Fingerprint::forResult($result, $context->environment, null));

        self::assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    private function reread(Issue $issue): Issue
    {
        $fresh = $this->issues->get($issue->id);

        self::assertInstanceOf(Issue::class, $fresh);

        return $fresh;
    }

    /**
     * Sets an issue's status directly, as a decision or a run committed elsewhere would leave it.
     */
    public function setStatus(Issue $issue, IssueStatus $status): ?string
    {
        IssueRecord::updateAll(['status' => $status->value], ['id' => $issue->id]);

        return null;
    }

    /**
     * A repair of this issue under way since the moment given, holding the test action's lock.
     */
    public function runningRepairOf(Issue $issue, DateTimeImmutable $since, ?IssueStatus $before = null, string $action = 'test.repair', ?string $environment = null): int
    {
        $record = new RepairRecord();
        $record->issueId = $issue->id;
        $record->issueTitle = $issue->title;
        $record->diagnosticId = $issue->diagnosticId;
        $record->action = $action;
        $record->actionName = 'Test repair';
        $record->risk = RepairRisk::LOW->value;
        $record->status = RepairStatus::RUNNING->value;
        $record->verificationStatus = VerificationStatus::NONE->value;
        $record->environment = $environment ?? $this->environment;
        $record->fingerprint = str_repeat('0', 64);
        $record->definitionFingerprint = str_repeat('0', 64);
        $record->findingRunId = $issue->latestRunId;
        $record->issueStatusBefore = $before?->value;
        $record->lockKey = Repairs::lockKey($action, $environment ?? $this->environment);
        $record->previewedAt = Db::prepareDateForDb($since);
        $record->startedAt = Db::prepareDateForDb($since);

        self::assertTrue($record->save(), (string)json_encode($record->getErrors()));

        return (int)$record->id;
    }

    public function age(Repair $repair, int $seconds): void
    {
        RepairRecord::updateAll(['previewedAt' => Db::prepareDateForDb(new DateTimeImmutable("-$seconds seconds"))], ['id' => $repair->id]);
    }

    private function assertStillWaiting(Repair $repair, Issue $issue): void
    {
        $record = RepairRecord::findOne($repair->id);

        self::assertSame(RepairStatus::PREVIEWED->value, $record?->status);
        self::assertNull($record->lockKey);
        self::assertNull($record->startedAt);
        self::assertNotSame(IssueStatus::REPAIRING, $this->reread($issue)->status);
    }

    /**
     * @return list<int>
     */
    private function previews(): array
    {
        return array_map('intval', RepairRecord::find()->select(['id'])->where(['environment' => $this->environment])->column());
    }

    /**
     * The storage check looking at directories the test states.
     *
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
        $this->directory = sys_get_temp_dir() . '/webdoctor-repair-' . bin2hex(random_bytes(5));
        mkdir($this->directory);

        return $this->directory;
    }

    /**
     * Craft's own queue, over a table of the test's own with the real queue's shape, so jobs are
     * really retried without a single one being added to the installation's queue.
     */
    private function queue(?StubQueue $queue = null): StubQueue
    {
        $db = Craft::$app->getDb();

        if (!$db->getIsMysql()) {
            self::markTestSkipped('Copies the queue table with MySQL’s CREATE TABLE … LIKE.');
        }

        $this->queueTable = '{{%wdtest_queue_' . bin2hex(random_bytes(4)) . '}}';
        $db->createCommand(sprintf('CREATE TABLE %s LIKE %s', $db->quoteTableName($this->queueTable), $db->quoteTableName('{{%queue}}')))->execute();

        $queue ??= new StubQueue();
        $queue->tableName = $this->queueTable;
        $queue->channel = 'wdtest';

        return $queue;
    }

    private function job(StubQueue $queue, bool $fail, string $description = 'A job', int $attempt = 1, string $channel = 'wdtest'): int
    {
        Craft::$app->getDb()->createCommand()->insert($queue->tableName, [
            'channel' => $channel,
            'job' => 'x',
            'description' => $description,
            'timePushed' => time() - 3600,
            'ttr' => 300,
            'attempt' => $fail ? $attempt : null,
            'fail' => $fail,
            'dateFailed' => $fail ? Db::prepareDateForDb(new DateTimeImmutable('-10 minutes')) : null,
            'error' => $fail ? 'Connection refused' : null,
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @param array<string, mixed> $columns
     */
    public function updateJob(StubQueue $queue, int $id, array $columns): void
    {
        Craft::$app->getDb()->createCommand()->update($queue->tableName, $columns, ['id' => $id])->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(StubQueue $queue, int $id): array
    {
        return (new \craft\db\Query())->from($queue->tableName)->where(['id' => $id])->one() ?: [];
    }

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
        $_SERVER['REQUEST_URI'] = $cp ? "/$trigger/web-doctor/issues" : '/actions/web-doctor/repairs/execute';
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

    private function controller(): RecordingRepairsController
    {
        return new RecordingRepairsController('repairs', $this->plugin);
    }

    private function issuesController(): RecordingIssuesController
    {
        return new RecordingIssuesController('issues', $this->plugin);
    }

    /**
     * Renders what a controller actually produced, through the real template, leaving out only
     * Craft's own control panel shell, which asks for a session a console run has none of.
     *
     * @param array<string, mixed> $params
     */
    private function render(\craft\web\Controller $controller, string $action, string $template, array $params = []): string
    {
        $response = $controller->runAction($action, $params);

        /** @var TemplateResponseBehavior $behavior */
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        return Craft::$app->getView()->renderTemplate($template, $behavior->variables, View::TEMPLATE_MODE_CP);
    }
}
