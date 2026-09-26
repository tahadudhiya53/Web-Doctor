<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\diagnostics\craft\ApplicationDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\environment\EnvironmentDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\plugins\PluginHealthDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\projectconfig\PendingChangesDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\projectconfig\ProjectConfigIntegrityDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;
use Tahadudhiya\WebDoctor\enums\ConditionRole;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory as Category;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus as Status;
use Tahadudhiya\WebDoctor\enums\EvidenceType as Type;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\ConditionOutcome;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Observation;
use Tahadudhiya\WebDoctor\models\Recommendation;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\rules\RecommendationRule;
use Tahadudhiya\WebDoctor\rules\RecommendationRules;
use Tahadudhiya\WebDoctor\rules\RootCauseRules;
use Tahadudhiya\WebDoctor\services\Recommendations;

/**
 * Recommendations: which advice a finding gets, what it rests on, how risky it is, and how its
 * success is checked — and that the same finding always gets the same advice.
 *
 * Findings are produced by the real checks wherever a check can run without an installation, and
 * otherwise stated with the evidence keys the check records, because a rule is only as good as its
 * reading of the evidence it will actually be given.
 */
class RecommendationTest extends TestCase
{
    private const OUT_OF_MEMORY = 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)';

    // --- Selection: every finding a shipped check makes, and the advice it gets.

    /**
     * @return array<string, array{Closure(): DiagnosticResult, string, list<string>, RepairRisk}>
     */
    public static function findings(): array
    {
        return [
            // Run through the real checks.
            'Craft is not installed' => [static fn(): DiagnosticResult => self::application(installed: false), 'craft.settleDatabaseBeforeInstalling', ['craft.application', 'database.connection', 'database.migrations'], RepairRisk::HIGH],
            'maintenance mode' => [static fn(): DiagnosticResult => self::application(maintenance: true), 'craft.finishInterruptedUpdate', ['craft.application', 'database.migrations'], RepairRisk::MEDIUM],
            'plugin code missing' => [static fn(): DiagnosticResult => self::pluginHealth(recorded: ['gone', 'seo']), 'plugins.reinstateOrUninstall', ['plugins.health'], RepairRisk::MEDIUM],
            'plugin did not load' => [static fn(): DiagnosticResult => self::pluginHealth(loaded: false), 'plugins.findLoadFailure', ['plugins.health', 'plugins.installed'], RepairRisk::LOW],
            'plugin licensing' => [static fn(): DiagnosticResult => self::pluginHealth(licenseIssues: ['invalid']), 'plugins.resolveLicensing', ['plugins.health'], RepairRisk::LOW],
            'storage not writable' => [static fn(): DiagnosticResult => self::storage('notWritable'), 'storage.grantWriteAccess', ['storage.paths'], RepairRisk::MEDIUM],
            'storage missing' => [static fn(): DiagnosticResult => self::storage('missing'), 'storage.createDirectories', ['storage.paths'], RepairRisk::LOW],
            'no security key' => [static fn(): DiagnosticResult => self::environment(['securityKey' => '']), 'environment.restoreSecurityKey', ['environment.configuration'], RepairRisk::HIGH],
            'dev mode in production' => [static fn(): DiagnosticResult => self::environment(['devMode' => true]), 'environment.disableDevMode', ['environment.configuration'], RepairRisk::LOW],
            'project config pending' => [static fn(): DiagnosticResult => self::pending(allowAdminChanges: true), 'projectConfig.applyPending', ['projectConfig.pendingChanges', 'projectConfig.integrity'], RepairRisk::MEDIUM],
            'project config pending, admin changes off' => [static fn(): DiagnosticResult => self::pending(allowAdminChanges: false), 'projectConfig.applyPending', ['projectConfig.pendingChanges', 'projectConfig.integrity'], RepairRisk::MEDIUM],
            'project config schema mismatch' => [static fn(): DiagnosticResult => self::integrity(), 'projectConfig.alignSchemaVersions', ['projectConfig.integrity', 'projectConfig.pendingChanges'], RepairRisk::MEDIUM],

            // Stated as the checks record them.
            'breakpoint skipped' => [static fn(): DiagnosticResult => self::reported('craft.version', Status::FAIL, [self::evidence(Type::CRAFT_VERSION, 'Craft', 'craft.version', ['version' => '5.11.3', 'edition' => 'Pro', 'schemaVersion' => '5.4.0.0'])]), 'craft.redoSkippedBreakpoint', ['craft.version', 'database.migrations'], RepairRisk::HIGH],
            'unsupported PHP' => [static fn(): DiagnosticResult => self::reported('php.version', Status::FAIL, [self::evidence(Type::PHP_VERSION, 'PHP', 'php.version', ['version' => '8.1.2', 'fullVersion' => '8.1.2', 'sapi' => 'fpm-fcgi', 'required' => '^8.2'])]), 'php.moveToSupportedVersion', ['php.version', 'php.extensions', 'php.configuration'], RepairRisk::MEDIUM],
            'missing extensions' => [static fn(): DiagnosticResult => self::reported('php.extensions', Status::FAIL, [self::evidence(Type::CONFIGURATION, 'PHP extensions', 'php.extensions', ['sapi' => 'cli', 'required' => ['gd', 'intl'], 'loaded' => ['gd'], 'missing' => ['intl'], 'recommended' => [], 'recommendedMissing' => []])]), 'php.installExtensions', ['php.extensions'], RepairRisk::LOW],
            'save_comments off' => [static fn(): DiagnosticResult => self::reported('php.configuration', Status::FAIL, [self::phpConfiguration(saveComments: false)]), 'php.keepDocblockComments', ['php.configuration'], RepairRisk::LOW],
            'low PHP limits' => [static fn(): DiagnosticResult => self::reported('php.configuration', Status::WARNING, [self::phpConfiguration(memoryLimit: '128M')]), 'php.raiseLimits', ['php.configuration'], RepairRisk::LOW],
            'no database connection' => [static fn(): DiagnosticResult => self::reported('database.connection', Status::FAIL, self::connectionFailed()), 'database.establishConnection', ['database.connection', 'database.migrations', 'database.charset'], RepairRisk::LOW],
            'database server too old' => [static fn(): DiagnosticResult => self::reported('database.connection', Status::FAIL, [self::evidence(Type::DATABASE, 'Database server', 'database.connection', ['succeeded' => true, 'driver' => 'MySQL', 'serverVersion' => '5.6.0', 'required' => '8.0.17'])]), 'database.upgradeServer', ['database.connection', 'database.charset'], RepairRisk::HIGH],
            'schema newer than code' => [static fn(): DiagnosticResult => self::reported('database.migrations', Status::FAIL, [self::migrations(compatible: false, pending: ['craft'])]), 'database.deployMigratedVersion', ['database.migrations', 'craft.version'], RepairRisk::HIGH],
            'pending migrations' => [static fn(): DiagnosticResult => self::reported('database.migrations', Status::FAIL, [self::migrations(compatible: true, pending: ['craft', 'plugin:seo'])]), 'database.runPendingMigrations', ['database.migrations', 'projectConfig.pendingChanges'], RepairRisk::MEDIUM],
            'no four-byte characters' => [static fn(): DiagnosticResult => self::reported('database.charset', Status::WARNING, [self::charset('utf8mb4', 'utf8mb4', false)]), 'database.convertToUtf8mb4', ['database.charset'], RepairRisk::HIGH],
            'charset mismatch' => [static fn(): DiagnosticResult => self::reported('database.charset', Status::WARNING, [self::charset('latin1', 'utf8mb4', true)]), 'database.alignCharset', ['database.charset'], RepairRisk::MEDIUM],
            'queue stalled' => [static fn(): DiagnosticResult => self::reported('queue.backlog', Status::FAIL, [self::backlog(waiting: 12, oldest: 7200)]), 'queue.startRunner', ['queue.backlog', 'queue.failedJobs'], RepairRisk::LOW],
            'job overrunning' => [static fn(): DiagnosticResult => self::reported('queue.backlog', Status::WARNING, [self::backlog(waiting: 3, oldest: 60, running: 1, runningFor: 900, limit: 300)]), 'queue.checkOverrunningJob', ['queue.backlog'], RepairRisk::MEDIUM],
            'large backlog' => [static fn(): DiagnosticResult => self::reported('queue.backlog', Status::WARNING, [self::backlog(waiting: 250, oldest: 120, running: 1, runningFor: 20, limit: 300)]), 'queue.watchBacklog', ['queue.backlog'], RepairRisk::LOW],
            'jobs out of memory' => [static fn(): DiagnosticResult => self::reported('queue.failedJobs', Status::FAIL, self::failedJobs([['Generating transforms', self::OUT_OF_MEMORY, 1]])), 'queue.raiseMemoryThenRetry', ['queue.failedJobs', 'php.configuration'], RepairRisk::MEDIUM],
            'a job failing repeatedly' => [static fn(): DiagnosticResult => self::reported('queue.failedJobs', Status::FAIL, self::failedJobs([['Sending email', 'Connection could not be established with host smtp.example.com', 3]])), 'queue.fixRepeatedFailure', ['queue.failedJobs'], RepairRisk::MEDIUM],
            'failed jobs' => [static fn(): DiagnosticResult => self::reported('queue.failedJobs', Status::FAIL, self::failedJobs([['Updating search indexes', 'Index not found', 1]])), 'queue.inspectThenRetry', ['queue.failedJobs'], RepairRisk::MEDIUM],
            'failed jobs, errors hidden' => [static fn(): DiagnosticResult => self::reported('queue.failedJobs', Status::FAIL, self::failedJobs([['Updating search indexes', null, 1]])), 'queue.inspectThenRetry', ['queue.failedJobs'], RepairRisk::MEDIUM],
            'volume without a filesystem' => [static fn(): DiagnosticResult => self::reported('filesystem.volumes', Status::FAIL, self::volume('missing')), 'filesystem.reconnectVolume', ['filesystem.volumes'], RepairRisk::MEDIUM],
            'volume unreachable' => [static fn(): DiagnosticResult => self::reported('filesystem.volumes', Status::FAIL, self::volume('unreachable')), 'filesystem.restoreAccess', ['filesystem.volumes'], RepairRisk::LOW],
            'transport cannot be built' => [static fn(): DiagnosticResult => self::reported('email.configuration', Status::FAIL, [self::mailSettings(fromEmail: Redaction::PRESENT), Evidence::fromThrowable(new RuntimeException('Class "Missing\\Transport" not found'), 'email.configuration')]), 'email.chooseTransport', ['email.configuration'], RepairRisk::LOW],
            'no sender address' => [static fn(): DiagnosticResult => self::reported('email.configuration', Status::FAIL, [self::mailSettings(fromEmail: Redaction::MISSING)]), 'email.completeSettings', ['email.configuration'], RepairRisk::LOW],
            'SMTP credentials missing' => [static fn(): DiagnosticResult => self::reported('email.configuration', Status::FAIL, [self::mailSettings(fromEmail: Redaction::PRESENT), self::evidence(Type::ENVIRONMENT_VARIABLE, 'Mail transport', 'email.configuration', ['transportType' => 'craft\\mail\\transportadapters\\Smtp', 'host' => 'smtp.example.com', 'useAuthentication' => Redaction::PRESENT, 'password' => Redaction::MISSING])]), 'email.completeSettings', ['email.configuration'], RepairRisk::LOW],
        ];
    }

    /**
     * Each finding gets exactly the advice written for it, rests on what the check recorded, is
     * verified by running that check again first, and offers no repair Web Doctor cannot perform.
     *
     * @param Closure(): DiagnosticResult $finding
     * @param list<string> $verifyWith
     */
    #[DataProvider('findings')]
    public function testEachFindingGetsTheAdviceWrittenForIt(Closure $finding, string $ruleId, array $verifyWith, RepairRisk $risk): void
    {
        $result = $finding();

        self::assertTrue($result->status->isProblem(), 'The fixture is not a finding.');

        $recommendations = (new Recommendations())->forResult($result)->recommendations;

        self::assertSame([$ruleId], array_map(static fn(Recommendation $r): string => $r->ruleId, $recommendations));

        $recommendation = $recommendations[0];
        self::assertSame($result->diagnosticId, $recommendation->diagnosticId);
        self::assertSame($verifyWith, $recommendation->verifyWith);
        self::assertSame($risk, $recommendation->risk);
        self::assertFalse($recommendation->automaticRepair);
        self::assertNull($recommendation->cause);

        // Traceable: every fact it rests on is the finding or evidence the check itself recorded.
        self::assertNotSame([], $recommendation->basis);

        foreach ($recommendation->basis as $observation) {
            self::assertSame($result->diagnosticId, $observation->diagnosticId);

            if ($observation->kind === Observation::EVIDENCE) {
                self::assertContains($observation->evidenceDigest, array_map(static fn(Evidence $e): string => $e->digest(), $result->evidence()));
            }
        }
    }

    public function testEveryAdviceForAFindingIsReachedByAFindingAShippedCheckMakes(): void
    {
        $reached = [];

        foreach (self::findings() as [$finding]) {
            foreach ((new Recommendations())->forResult($finding())->recommendations as $recommendation) {
                $reached[$recommendation->ruleId] = true;
            }
        }

        $written = array_map(static fn(RecommendationRule $r): string => $r->id, array_filter(RecommendationRules::all(), static fn(RecommendationRule $r): bool => $r->check !== null));

        self::assertSame([], array_values(array_diff($written, array_keys($reached))), 'Advice no finding reaches.');
    }

    // --- The three kinds of advice the brief names.

    public function testProjectConfigOutOfStepIsAppliedThroughCraftAndVerifiedByTheConsistencyChecks(): void
    {
        [$recommendation] = (new Recommendations())->forResult(self::pending(allowAdminChanges: true))->recommendations;

        self::assertStringContainsString('`php craft up`', $recommendation->action);
        self::assertStringContainsString('`php craft project-config/apply`', $recommendation->action);
        self::assertSame(RepairRisk::MEDIUM, $recommendation->risk);
        self::assertSame(['projectConfig.pendingChanges', 'projectConfig.integrity'], $recommendation->verifyWith);
        self::assertStringContainsString('changesPending: true', implode(' ', array_map(static fn(Observation $o): string => (string)$o->detail, $recommendation->basis)));
    }

    public function testAFailedJobIsInspectedAndRetriedOnlyWhenRetryingIsSafe(): void
    {
        [$recommendation] = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, self::failedJobs([['Sending email', 'SMTP refused the message', 1]])))->recommendations;

        self::assertStringContainsString('read the error recorded for each failed job', $recommendation->action);
        self::assertStringContainsString('safe to run again', $recommendation->action);
        self::assertStringContainsString('safe to run again', implode(' ', $recommendation->prerequisites));
        // The error it rests on is quoted, for somebody who may see evidence.
        self::assertStringContainsString('SMTP refused the message', implode(' ', array_map(static fn(Observation $o): string => (string)$o->detail, $recommendation->basis)));

        // A job that cannot succeed as things stand is not simply retried.
        [$memory] = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, self::failedJobs([['Generating transforms', self::OUT_OF_MEMORY, 4]])))->recommendations;
        self::assertStringContainsString('Raise memory_limit', $memory->action);
        self::assertStringContainsString('Then retry', $memory->action);
    }

    public function testAssetsWhoseFilesCannotBeFoundAreNeverDeletedToClearTheFinding(): void
    {
        foreach (['missing', 'unreachable'] as $state) {
            [$recommendation] = (new Recommendations())->forResult(self::reported('filesystem.volumes', Status::FAIL, self::volume($state)))->recommendations;

            self::assertStringContainsString('Verify the filesystem', $recommendation->action);
            self::assertStringContainsString('verify the asset records', $recommendation->action);
            self::assertStringContainsString('Do not delete', $recommendation->action);
        }
    }

    // --- What is and is not advised.

    /**
     * @return array<string, array{Status}>
     */
    public static function notFindings(): array
    {
        return array_combine(
            array_map(static fn(Status $s): string => $s->value, [Status::PASS, Status::INFO, Status::ERROR, Status::UNKNOWN, Status::SKIPPED]),
            array_map(static fn(Status $s): array => [$s], [Status::PASS, Status::INFO, Status::ERROR, Status::UNKNOWN, Status::SKIPPED]),
        );
    }

    /**
     * Only a finding about the site is acted on: a pass has nothing to fix, and a check that broke or
     * could not tell established nothing to act on — whatever evidence it happens to hold.
     */
    #[DataProvider('notFindings')]
    public function testOnlyAFindingIsAdvisedOn(Status $status): void
    {
        $result = self::reported('queue.failedJobs', $status, self::failedJobs([['Sending email', 'SMTP refused', 3]]));

        self::assertSame([], (new Recommendations())->forResult($result)->recommendations);
        self::assertSame([], (new Recommendations())->recommend(RecommendationCase::fromResult($result, 7, [self::cause('queue.outOfMemory', Confidence::CONFIRMED)]))->recommendations);
    }

    public function testAFindingNoRuleAnswersGetsNoAdviceRatherThanAGuess(): void
    {
        $contributed = new DiagnosticResult(
            diagnosticId: 'otherPlugin.check',
            name: 'Another plugin’s check',
            category: Category::QUEUE,
            status: Status::FAIL,
            summary: 'Something is wrong.',
            evidence: self::failedJobs([['Sending email', 'SMTP refused', 3]]),
            recommendation: 'Its own advice.',
        );

        self::assertSame([], (new Recommendations())->forResult($contributed)->recommendations);

        // Nor is a shipped check's advice given for evidence that does not select it.
        self::assertSame([], (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, []))->recommendations);
    }

    public function testTheCheckDecidesWhichOfItsAdviceComesFirstNotTheOrderOfTheEvidence(): void
    {
        // A schema newer than the code outranks the migrations it also lists, as it does in the check.
        $both = self::reported('database.migrations', Status::FAIL, [self::migrations(compatible: false, pending: ['craft'])]);
        self::assertSame(['database.deployMigratedVersion'], array_map(static fn(Recommendation $r): string => $r->ruleId, (new Recommendations())->forResult($both)->recommendations));

        // One recommendation per finding: memory first, however the jobs were listed.
        $jobs = self::failedJobs([['Sending email', 'SMTP refused', 3], ['Generating transforms', self::OUT_OF_MEMORY, 2]]);
        $forwards = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, $jobs))->recommendations;
        $backwards = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, array_reverse($jobs)))->recommendations;

        self::assertSame(['queue.raiseMemoryThenRetry'], array_map(static fn(Recommendation $r): string => $r->ruleId, $forwards));
        self::assertSame(json_encode($forwards), json_encode($backwards));

        // And what the advice rests on is listed in a fixed order, not the order it arrived in.
        $several = self::failedJobs([['Sending email', 'SMTP refused', 1], ['Updating search indexes', 'Index not found', 1]]);
        $forwards = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, $several))->recommendations;
        $backwards = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, array_reverse($several)))->recommendations;

        self::assertCount(3, $forwards[0]->basis);
        self::assertSame(json_encode($forwards), json_encode($backwards));
    }

    // --- Advice for a weighed cause.

    public function testACauseHeldLikelyIsActedOnFirstInItsOwnWordsWithTheFindingsAdviceAfter(): void
    {
        $cause = self::cause('database.characterSet', Confidence::LIKELY);
        $case = RecommendationCase::fromResult(self::reported('database.charset', Status::WARNING, [self::charset('utf8', 'utf8mb4', false)]), 42, [$cause]);

        $recommendations = (new Recommendations())->recommend($case)->recommendations;

        self::assertSame(['database.convertCharacterSet', 'database.convertToUtf8mb4'], array_map(static fn(Recommendation $r): string => $r->ruleId, $recommendations));

        [$forCause] = $recommendations;
        self::assertSame($cause, $forCause->cause);
        // The action and the explanation are the cause's own, as concluded, so the advice and the
        // diagnosis never say two different things.
        self::assertSame($cause->recommendation, $forCause->action);
        self::assertSame($cause->statement, $forCause->explanation);
        self::assertSame(42, $forCause->issueId);
        self::assertSame(['database.charset'], $forCause->verifyWith);
        // What the cause was found on is what the advice rests on.
        self::assertSame(
            array_map(static fn(Observation $o): string => $o->key(), $cause->supporting()[0]->observations),
            array_map(static fn(Observation $o): string => $o->key(), $forCause->basis),
        );
    }

    public function testACauseHeldOnlyAsPossibleIsALeadAndNotSomethingToActOn(): void
    {
        $case = RecommendationCase::fromResult(self::reported('database.charset', Status::WARNING, [self::charset('utf8', 'utf8mb4', false)]), 42, [self::cause('database.characterSet', Confidence::POSSIBLE)]);

        self::assertSame(['database.convertToUtf8mb4'], array_map(static fn(Recommendation $r): string => $r->ruleId, (new Recommendations())->recommend($case)->recommendations));
    }

    public function testSeveralCausesAreActedOnMostFirmlyHeldFirst(): void
    {
        $finding = self::reported('database.connection', Status::FAIL, self::connectionFailed());
        $case = RecommendationCase::fromResult($finding, 9, [
            self::cause('environment.settingMissing', Confidence::LIKELY, position: 1),
            self::cause('database.credentialsRejected', Confidence::HIGH, position: 0),
        ]);

        self::assertSame(
            ['database.correctCredentials', 'environment.defineSetting', 'database.establishConnection'],
            array_map(static fn(Recommendation $r): string => $r->ruleId, (new Recommendations())->recommend($case)->recommendations),
        );
    }

    /**
     * @return array<string, array{Confidence, bool}>
     */
    public static function confidences(): array
    {
        return [
            'possible' => [Confidence::POSSIBLE, false],
            'likely' => [Confidence::LIKELY, true],
            'high' => [Confidence::HIGH, true],
            'confirmed' => [Confidence::CONFIRMED, true],
        ];
    }

    /**
     * The confidence is the one the cause was weighed at, read and never recomputed: a cause is
     * acted on from Likely up, and a Possible one is a lead.
     */
    #[DataProvider('confidences')]
    public function testACauseIsActedOnFromLikelyUpAtTheConfidenceItWasWeighedAt(Confidence $confidence, bool $actedOn): void
    {
        $case = RecommendationCase::fromResult(self::reported('queue.backlog', Status::FAIL, [self::backlog(waiting: 12, oldest: 7200)]), 3, [self::cause('queue.notProcessing', $confidence)]);
        $ids = array_map(static fn(Recommendation $r): string => $r->ruleId, (new Recommendations())->recommend($case)->recommendations);

        self::assertSame($actedOn ? ['queue.startWorker', 'queue.startRunner'] : ['queue.startRunner'], $ids);
    }

    public function testACauseNoRuleAnswersOrWhoseEvidenceCannotBeReadIsNotActedOn(): void
    {
        $finding = self::reported('queue.backlog', Status::FAIL, [self::backlog(waiting: 12, oldest: 7200)]);

        // A cause from a rule no longer written out has no advice to give.
        $unknown = RecommendationCase::fromResult($finding, 3, [self::cause('removed.cause', Confidence::CONFIRMED)]);
        self::assertSame(['queue.startRunner'], array_map(static fn(Recommendation $r): string => $r->ruleId, (new Recommendations())->recommend($unknown)->recommendations));

        // Nor does one read back without the evidence it was found on: advice with nothing behind
        // it would not be traceable to anything.
        $bare = new RootCause(ruleId: 'queue.notProcessing', title: 'Nothing is taking jobs off the queue', statement: 'Stalled.', problem: 'P', confidence: Confidence::HIGH, conditions: [], reasoning: [], relatedIssues: [], recommendation: 'Start a worker.', nextSteps: []);
        $unread = RecommendationCase::fromResult($finding, 3, [$bare]);
        self::assertSame(['queue.startRunner'], array_map(static fn(Recommendation $r): string => $r->ruleId, (new Recommendations())->recommend($unread)->recommendations));
    }

    public function testTheOrderCausesArriveInAndDuplicateRulesChangeNothing(): void
    {
        $finding = self::reported('database.connection', Status::FAIL, self::connectionFailed());
        $causes = [self::cause('database.credentialsRejected', Confidence::HIGH, position: 0), self::cause('environment.settingMissing', Confidence::LIKELY, position: 1)];
        $rules = RecommendationRules::all();

        $expected = json_encode((new Recommendations())->recommend(RecommendationCase::fromResult($finding, 9, $causes)));
        $reversedCauses = json_encode((new Recommendations())->recommend(RecommendationCase::fromResult($finding, 9, array_reverse($causes))));
        $duplicated = json_encode((new Recommendations())->recommend(RecommendationCase::fromResult($finding, 9, $causes), [...$rules, ...$rules]));

        self::assertSame($expected, $reversedCauses);
        self::assertSame($expected, $duplicated);

        // Advice for causes follows the causes, not the order the rules were written in; advice for
        // the finding follows the check's precedence, which is the order written, by design.
        $causeRules = array_values(array_filter($rules, static fn(RecommendationRule $r): bool => $r->cause !== null));
        $findingRules = array_values(array_filter($rules, static fn(RecommendationRule $r): bool => $r->check !== null));
        self::assertSame($expected, json_encode((new Recommendations())->recommend(RecommendationCase::fromResult($finding, 9, $causes), [...array_reverse($causeRules), ...$findingRules])));
    }

    public function testTheSameInputsGiveTheSameAdviceEveryTime(): void
    {
        $evidence = [...self::failedJobs([['Sending email', 'SMTP refused', 1], ['Generating transforms', self::OUT_OF_MEMORY, 3], ['Updating search indexes', 'Index not found', 2]]), self::phpConfiguration(memoryLimit: '128M')];
        $causes = [self::cause('queue.outOfMemory', Confidence::HIGH, position: 0), self::cause('error.shared', Confidence::LIKELY, position: 1)];
        $expected = json_encode((new Recommendations())->recommend(RecommendationCase::fromResult(self::reported('queue.failedJobs', Status::FAIL, $evidence), 11, $causes)));

        for ($run = 0; $run < 100; $run++) {
            $reverse = $run % 2 === 1;
            $case = RecommendationCase::fromResult(
                self::reported('queue.failedJobs', Status::FAIL, $reverse ? array_reverse($evidence) : $evidence),
                11,
                $reverse ? array_reverse($causes) : $causes,
            );

            self::assertSame($expected, json_encode((new Recommendations())->recommend($case)), "Run $run differed.");
        }
    }

    // --- What the advice rests on.

    public function testTheBasisIsOnlyWhatTheRuleReadOnceEachAndBounded(): void
    {
        // Evidence the rule does not read is not quoted, however much of it there is.
        $unrelated = self::evidence(Type::CONFIGURATION, 'Mail settings', 'queue.failedJobs', ['host' => 'zz-unrelated-zz']);
        $job = self::failedJobs([['Sending email', 'SMTP refused', 1]]);
        [$recommendation] = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, [...$job, $unrelated, $job[1]]))->recommendations;

        self::assertStringNotContainsString('zz-unrelated-zz', (string)json_encode($recommendation->basis));
        // The count and the one job: the job recorded twice is quoted once.
        self::assertCount(2, $recommendation->basis);

        // A great many failed jobs are quoted no further than a reader can follow.
        $many = self::failedJobs(array_map(static fn(int $n): array => ["Job $n", "Failure $n", 1], range(1, RecommendationRule::MAX_BASIS + 5)));
        [$bounded] = (new Recommendations())->forResult(self::reported('queue.failedJobs', Status::FAIL, $many))->recommendations;
        self::assertCount(RecommendationRule::MAX_BASIS, $bounded->basis);
    }

    // --- Verification.

    public function testVerificationRunsTheOriginFirstThenWhatTheRuleDeclaresEachOnceInOrder(): void
    {
        $rule = static fn(array $also): RecommendationRule => RecommendationRule::forFinding(
            id: 'tests.rule',
            check: 'tests.origin',
            title: 'T',
            explanation: 'E',
            action: 'A',
            rationale: 'R',
            risk: RepairRisk::LOW,
            riskReason: 'RR',
            verification: 'V',
            match: static fn(): array => [],
            alsoVerifyWith: $also,
        );

        self::assertSame(['tests.origin'], $rule([])->verifyWith('tests.origin'));
        self::assertSame(['tests.origin', 'queue.backlog'], $rule(['queue.backlog'])->verifyWith('tests.origin'));
        self::assertSame(['tests.origin', 'queue.backlog'], $rule(['tests.origin', 'queue.backlog', 'queue.backlog'])->verifyWith('tests.origin'));
        self::assertSame(['tests.origin', 'php.configuration', 'queue.backlog'], $rule(['php.configuration', 'queue.backlog'])->verifyWith('tests.origin'));
    }

    // --- The model.

    public function testARecommendationSaysEverythingItIsMadeOfAndOffersNoRepair(): void
    {
        [$recommendation] = (new Recommendations())->forResult(self::reported('projectConfig.pendingChanges', Status::WARNING, [
            self::evidence(Type::PROJECT_CONFIG, 'Project config state', 'projectConfig.pendingChanges', ['changesPending' => true, 'allowAdminChanges' => true]),
        ]))->recommendations;
        $json = $recommendation->jsonSerialize();

        self::assertSame(
            ['ruleId', 'diagnosticId', 'issueId', 'problem', 'title', 'explanation', 'action', 'rationale', 'basis', 'risk', 'riskReason', 'verification', 'verifyWith', 'prerequisites', 'cause', 'automaticRepair'],
            array_keys($json),
        );

        foreach (['problem', 'title', 'explanation', 'action', 'rationale', 'riskReason', 'verification'] as $field) {
            self::assertNotSame('', $json[$field], "$field is empty.");
        }

        self::assertNotSame([], $json['prerequisites']);
        self::assertFalse($json['automaticRepair']);
        // Advice, in the words of advice: nothing it says claims Web Doctor did it.
        self::assertDoesNotMatchRegularExpression('/\b(?:Web Doctor|was) (?:applied|retried|deleted|repaired|fixed)\b/i', (string)json_encode($json));
    }

    // --- The rules themselves.

    public function testEveryRuleIsCompleteAndVerifiedByChecksThatExist(): void
    {
        $shipped = array_map(static fn($d): string => $d->id(), CoreDiagnostics::all());
        $ids = [];

        foreach (RecommendationRules::all() as $rule) {
            $ids[] = $rule->id;

            self::assertTrue(($rule->check === null) !== ($rule->cause === null), "{$rule->id} must answer a check or a cause, not both.");

            foreach ([$rule->title, $rule->rationale, $rule->riskReason, $rule->verification] as $text) {
                self::assertNotSame('', trim($text), "{$rule->id} leaves something unsaid.");
            }

            if ($rule->check !== null) {
                self::assertContains($rule->check, $shipped, "{$rule->id} answers a check Web Doctor does not ship.");
                self::assertNotSame('', trim((string)$rule->explanation));
                self::assertNotSame('', trim((string)$rule->action));
            }

            // Verified by running the check that found the problem again first, then the checks the
            // action can disturb — every one of them a check that exists, each once.
            $verifyWith = $rule->verifyWith($rule->check ?? 'tests.origin');
            self::assertSame($rule->check ?? 'tests.origin', $verifyWith[0]);
            self::assertSame($verifyWith, array_values(array_unique($verifyWith)));
            self::assertSame([], array_values(array_diff($rule->alsoVerifyWith, $shipped)), "{$rule->id} is verified by a check Web Doctor does not ship.");
        }

        self::assertSame($ids, array_values(array_unique($ids)), 'Rule IDs must be unique.');
    }

    public function testEveryKnownCauseHasExactlyOneAdviceAndEveryAdviceForACauseAnswersAKnownOne(): void
    {
        $causes = array_map(static fn($r): string => $r->id, RootCauseRules::all());
        $answered = array_values(array_filter(array_map(static fn(RecommendationRule $r): ?string => $r->cause, RecommendationRules::all())));

        sort($causes);
        sort($answered);

        self::assertSame($causes, $answered);
    }

    public function testEveryShippedCheckThatCanReportAFindingHasAdviceForIt(): void
    {
        $answered = array_unique(array_filter(array_map(static fn(RecommendationRule $r): ?string => $r->check, RecommendationRules::all())));
        $unanswered = [];

        foreach (CoreDiagnostics::classes() as $class) {
            $source = (string)file_get_contents((string)(new ReflectionClass($class))->getFileName());

            if (preg_match('/->(?:fail|warning)\(|DiagnosticStatus::(?:FAIL|WARNING)/', $source) === 1 && !in_array((new $class())->id(), $answered, true)) {
                $unanswered[] = (new $class())->id();
            }
        }

        self::assertSame([], $unanswered);
    }

    public function testARuleThatBreaksCostsOnlyItself(): void
    {
        $breaks = RecommendationRule::forFinding(
            id: 'tests.breaks',
            check: 'queue.failedJobs',
            title: 'Breaks',
            explanation: 'Breaks.',
            action: 'Breaks.',
            rationale: 'Breaks.',
            risk: RepairRisk::LOW,
            riskReason: 'Breaks.',
            verification: 'Breaks.',
            match: static fn(): array => throw new RuntimeException('A rule that breaks'),
        );

        $case = RecommendationCase::fromResult(self::reported('queue.failedJobs', Status::FAIL, self::failedJobs([['Sending email', 'SMTP refused', 1]])));

        // Ahead of the rule that matches, it ends the search: the check's order decides, and the
        // rule after a broken one may advise exactly what the broken one would have warned against.
        $recommendations = (new Recommendations())->recommend($case, [$breaks, ...RecommendationRules::all()]);

        self::assertSame([], $recommendations->recommendations);
        // Said, so a page can tell a rule that broke from no rule applying.
        self::assertSame(['tests.breaks'], $recommendations->failed);
        self::assertFalse($recommendations->nothingApplies());

        // Behind it, it is never reached.
        $recommendations = (new Recommendations())->recommend($case, [...RecommendationRules::all(), $breaks]);

        self::assertSame(['queue.inspectThenRetry'], array_map(static fn(Recommendation $r): string => $r->ruleId, $recommendations->recommendations));
        self::assertSame([], $recommendations->failed);
    }

    public function testTheSameFindingIsAdvisedOnTheSameWayWhereverItIsRead(): void
    {
        // A result, and the step an investigation kept of it, read the same way.
        $result = self::reported('storage.paths', Status::FAIL, self::storage('notWritable')->evidence());
        $asResult = (new Recommendations())->recommend(RecommendationCase::fromResult($result, 5))->recommendations;
        $asStep = (new Recommendations())->recommend(new RecommendationCase(
            diagnosticId: $result->diagnosticId,
            name: $result->name,
            status: $result->status,
            severity: $result->severity(),
            problem: $result->summary,
            evidence: array_map(static fn(Evidence $e): Evidence => Evidence::fromArray($e->jsonSerialize()), array_reverse($result->evidence())),
            issueId: 5,
        ))->recommendations;

        self::assertNotSame([], $asResult);
        self::assertSame(json_encode($asResult), json_encode($asStep));

        // A step that kept only part of what its check recorded is not advised on from the rest.
        $partial = (new Recommendations())->recommend(new RecommendationCase(
            diagnosticId: $result->diagnosticId,
            name: $result->name,
            status: $result->status,
            severity: $result->severity(),
            problem: $result->summary,
            evidence: array_slice($result->evidence(), 0, 1),
            evidenceComplete: false,
        ));

        self::assertSame([], $partial->recommendations);
        self::assertTrue($partial->partialEvidence);
        self::assertFalse($partial->nothingApplies());
    }

    // --- Findings, as the checks produce them.

    private static function application(bool $installed = true, bool $maintenance = false): DiagnosticResult
    {
        $check = new class(['installed' => $installed, 'maintenance' => $maintenance]) extends ApplicationDiagnostic {
            public bool $installed = true;
            public bool $maintenance = false;

            protected function isDbConnectionValid(): bool
            {
                return true;
            }

            protected function isInstalled(): bool
            {
                return $this->installed;
            }

            protected function isInMaintenanceMode(): bool
            {
                return $this->maintenance;
            }

            protected function isLive(): bool
            {
                return true;
            }

            protected function siteCount(): int
            {
                return 1;
            }
        };

        return $check->run(new DiagnosticContext());
    }

    /**
     * @param list<string> $recorded
     * @param list<string> $licenseIssues
     */
    private static function pluginHealth(array $recorded = ['seo'], bool $loaded = true, array $licenseIssues = []): DiagnosticResult
    {
        $check = new class(['recorded' => $recorded, 'loaded' => $loaded, 'licenseIssues' => $licenseIssues]) extends PluginHealthDiagnostic {
            /** @var list<string> */
            public array $recorded = [];
            public bool $loaded = true;

            /** @var list<string> */
            public array $licenseIssues = [];

            protected function pluginInfo(): array
            {
                return ['seo' => ['name' => 'SEO', 'isInstalled' => true, 'isEnabled' => $this->loaded, 'licenseIssues' => $this->licenseIssues]];
            }

            protected function recordedHandles(): array
            {
                return $this->recorded;
            }

            protected function isSwitchedOn(string $handle): bool
            {
                return true;
            }
        };

        return $check->run(new DiagnosticContext());
    }

    private static function storage(string $state): DiagnosticResult
    {
        $check = new class(['state' => $state]) extends StoragePathsDiagnostic {
            public string $state = 'writable';

            protected function paths(): array
            {
                return ['storage' => '/site/storage', 'logs' => '/site/storage/logs'];
            }

            protected function stateOf(string $path): string
            {
                return $path === '/site/storage/logs' ? $this->state : 'writable';
            }

            protected function processUser(): string
            {
                return 'www-data';
            }
        };

        return $check->run(new DiagnosticContext());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function environment(array $overrides): DiagnosticResult
    {
        $check = new class(['values' => $overrides + [ 'environment' => 'production', 'devMode' => false, 'allowAdminChanges' => false, 'allowUpdates' => false, 'runQueueAutomatically' => true, 'timezone' => 'UTC', 'securityKey' => 'a-real-looking-security-key-value', 'craftEnvironmentVariable' => 'production', ]]) extends EnvironmentDiagnostic {
            /** @var array<string, mixed> */
            public array $values = [];

            protected function settings(): array
            {
                return $this->values;
            }
        };

        return $check->run(new DiagnosticContext());
    }

    private static function pending(bool $allowAdminChanges): DiagnosticResult
    {
        $check = new class(['allow' => $allowAdminChanges]) extends PendingChangesDiagnostic {
            public bool $allow = true;

            protected function state(): array
            {
                return ['externalConfigExists' => true, 'changesPending' => true, 'writeYamlAutomatically' => true, 'readOnly' => false, 'allowAdminChanges' => $this->allow];
            }
        };

        return $check->run(new DiagnosticContext());
    }

    private static function integrity(): DiagnosticResult
    {
        $check = new class() extends ProjectConfigIntegrityDiagnostic {
            protected function externalConfigExists(): bool
            {
                return true;
            }

            protected function schemaVersionCheck(): array
            {
                return ['compatible' => false, 'issues' => [['cause' => 'Craft', 'existing' => '5.4.0.0', 'incoming' => '5.5.0.0']]];
            }
        };

        return $check->run(new DiagnosticContext());
    }

    // --- Findings, as the checks record them.

    /**
     * @param list<Evidence> $evidence
     */
    private static function reported(string $diagnosticId, Status $status, array $evidence): DiagnosticResult
    {
        return new DiagnosticResult(
            diagnosticId: $diagnosticId,
            name: "Check $diagnosticId",
            category: Category::CONFIGURATION,
            status: $status,
            summary: "$diagnosticId reports this.",
            evidence: $evidence,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function evidence(Type $type, string $label, string $source, array $data): Evidence
    {
        return new Evidence(type: $type, label: $label, source: $source, data: $data);
    }

    private static function phpConfiguration(bool $saveComments = true, string $memoryLimit = '512M'): Evidence
    {
        return self::evidence(Type::CONFIGURATION, 'PHP configuration', 'php.configuration', [
            'sapi' => 'fpm-fcgi',
            'memory_limit' => $memoryLimit,
            'max_execution_time' => '120',
            'upload_max_filesize' => '16M',
            'post_max_size' => '32M',
            'opcache' => true,
            'opcache.save_comments' => $saveComments,
        ]);
    }

    /**
     * @return list<Evidence>
     */
    private static function connectionFailed(): array
    {
        return [
            self::evidence(Type::CONFIGURATION, 'Database settings', 'database.connection', ['driver' => 'mysql', 'server' => 'db', 'user' => Redaction::PRESENT, 'password' => Redaction::PRESENT]),
            self::evidence(Type::DATABASE_ERROR, 'Connection attempt', 'database.connection', ['succeeded' => false]),
            Evidence::fromThrowable(new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'), 'database.connection'),
        ];
    }

    /**
     * @param list<string> $pending
     */
    private static function migrations(bool $compatible, array $pending): Evidence
    {
        return self::evidence(Type::DATABASE, 'Migration state', 'database.migrations', ['schemaVersionCompatible' => $compatible, 'pendingMigrations' => $pending, 'codeSchemaVersion' => '5.4.0.0', 'includesContentMigrations' => true]);
    }

    private static function charset(string $database, string $configured, bool $acceptsMb4): Evidence
    {
        return self::evidence(Type::DATABASE, 'Character set', 'database.charset', [
            'driver' => 'MySQL',
            'configuredCharset' => $configured,
            'configuredCollation' => null,
            'databaseCharset' => $database,
            'databaseCollation' => null,
            'sampledTable' => 'elements_sites',
            'sampledTableAcceptsMb4' => $acceptsMb4,
            'columnsInspected' => false,
        ]);
    }

    private static function backlog(int $waiting, int $oldest, int $running = 0, ?int $runningFor = null, ?int $limit = null): Evidence
    {
        return self::evidence(Type::QUEUE, 'Queue depth', 'queue.backlog', [
            'waiting' => $waiting,
            'running' => $running,
            'delayed' => 0,
            'oldestWaitingSeconds' => $oldest,
            'longestRunningSeconds' => $runningFor,
            'longestRunningTimeLimit' => $limit,
            'runQueueAutomatically' => true,
        ]);
    }

    /**
     * @param list<array{0: string, 1: string|null, 2: int}> $jobs Description, error, times failed.
     * @return list<Evidence>
     */
    private static function failedJobs(array $jobs): array
    {
        $total = array_sum(array_column($jobs, 2));
        $evidence = [self::evidence(Type::QUEUE, 'Failed jobs', 'queue.failedJobs', ['failed' => $total, 'examined' => $total, 'distinctJobsInSample' => count($jobs), 'sampleLimit' => 10, 'sampleIsComplete' => true])];

        foreach ($jobs as [$description, $error, $times]) {
            $evidence[] = self::evidence(Type::QUEUE_JOB, $description, 'queue.failedJobs', ['description' => $description, 'occurrences' => $times, 'firstFailed' => 1700000000, 'lastFailed' => 1700000600, 'error' => $error]);
        }

        return $evidence;
    }

    /**
     * @return list<Evidence>
     */
    private static function volume(string $state): array
    {
        return [
            self::evidence(Type::FILESYSTEM, 'Configured filesystems', 'filesystem.volumes', ['filesystems' => ['images'], 'volumes' => 1, 'probed' => true]),
            self::evidence(Type::FILESYSTEM, 'Images', 'filesystem.volumes', ['volume' => 'images', 'filesystem' => 'images', 'state' => $state]),
        ];
    }

    private static function mailSettings(string $fromEmail): Evidence
    {
        return self::evidence(Type::CONFIGURATION, 'Mail settings', 'email.configuration', [
            'transportType' => 'craft\\mail\\transportadapters\\Smtp',
            'fromEmail' => $fromEmail,
            'fromName' => Redaction::PRESENT,
            'replyToEmail' => Redaction::MISSING,
            'template' => Redaction::MISSING,
            'siteOverrides' => 0,
            'runQueueAutomatically' => true,
        ]);
    }

    /**
     * A cause as an investigation would have weighed it, resting on one supporting fact.
     */
    private static function cause(string $ruleId, Confidence $confidence, int $position = 0): RootCause
    {
        return new RootCause(
            ruleId: $ruleId,
            title: "The cause $ruleId",
            statement: "What $ruleId would mean.",
            problem: 'The problem.',
            confidence: $confidence,
            conditions: [new ConditionOutcome(
                id: 'found',
                role: ConditionRole::REQUIRED,
                description: 'Something was found',
                observations: [new Observation(kind: Observation::EVIDENCE, label: "Evidence for $ruleId", detail: 'value: 1', diagnosticId: 'tests.origin', evidenceDigest: str_repeat('a', 64))],
            )],
            reasoning: ['Because.'],
            relatedIssues: [],
            recommendation: "Do what $ruleId says.",
            nextSteps: [],
            position: $position,
            id: 100 + $position,
            investigationId: 77,
        );
    }
}
