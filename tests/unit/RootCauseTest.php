<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use Closure;
use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\enums\ConditionRole;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory as Category;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus as Status;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\ConditionOutcome;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\InvestigationPlan;
use Tahadudhiya\WebDoctor\models\IssueSnapshot;
use Tahadudhiya\WebDoctor\models\Observation;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\rules\Condition;
use Tahadudhiya\WebDoctor\rules\RootCauseRule;
use Tahadudhiya\WebDoctor\rules\RootCauseRules;
use Tahadudhiya\WebDoctor\services\RootCauses;
use yii\db\Exception as DbException;

/**
 * Root-cause analysis: which causes a set of findings fits, how firmly each is held and why, what
 * counts against it, and that the same findings always produce the same answer in the same order.
 *
 * Every case is stated the way the shipped checks actually record their evidence — the same keys,
 * the same types, the same exceptions — because a rule is only as good as its reading of real
 * evidence, and a fixture shaped differently would test a rule nobody runs.
 */
class RootCauseTest extends TestCase
{
    private const CHARACTER_ERROR = "SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F\\x98\\x80' for column 'title' at row 1";
    private const LOGIN_REFUSED = "SQLSTATE[HY000] [1045] Access denied for user 'craft'@'172.18.0.3' (using password: YES)";
    private const UNREACHABLE = 'SQLSTATE[HY000] [2002] Connection refused';
    private const OUT_OF_MEMORY = 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)';

    // --- Positive correlation: separate signals, one probable cause.

    public function testADatabaseErrorACharsetMismatchAndAFailedWriteAreOneProbableCause(): void
    {
        $analysis = (new RootCauses())->analyse(self::case(self::issue('database.charset', Category::DATABASE), [
            self::charset(database: 'utf8', configured: 'utf8mb4', sampledAcceptsMb4: false),
            self::broke('content.save', Category::CONTENT, new DbException(self::CHARACTER_ERROR)),
            self::failedJobs([['Updating search indexes', self::CHARACTER_ERROR, 1]]),
        ]));

        $cause = self::cause($analysis->causes, 'database.characterSet');

        // Probable, and no more: the rule samples one table, and says so.
        self::assertSame(Confidence::LIKELY, $cause->confidence);
        self::assertSame(['mismatch', 'refusedCharacters', 'failedWrite'], array_map(static fn(ConditionOutcome $c): string => $c->id, $cause->supporting()));
        self::assertSame([], $cause->conflicting());
        self::assertSame([], $cause->unmet());
        self::assertNotNull($cause->limitation);
        self::assertStringContainsString($cause->limitation, implode(' ', $cause->reasoning));

        // Every fact it rests on can be followed back: the check, the evidence, the error group.
        [$mismatch, $refused, $write] = $cause->supporting();
        // Both readings of the one piece of evidence are quoted: the sampled table, and the two
        // character sets that disagree.
        self::assertCount(2, $mismatch->observations);
        self::assertSame('database.charset', $mismatch->observations[0]->diagnosticId);
        self::assertNotNull($mismatch->observations[0]->evidenceDigest);
        self::assertStringContainsString('sampledTableAcceptsMb4: false', implode(' ', array_map(static fn(Observation $o): string => (string)$o->detail, $mismatch->observations)));
        self::assertSame(Observation::ERROR, $refused->observations[0]->kind);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$refused->observations[0]->errorFingerprint);
        self::assertStringContainsString('Incorrect string value', (string)$refused->observations[0]->detail);
        self::assertSame('queue.failedJobs', $write->observations[0]->diagnosticId);
    }

    /**
     * Each known cause, stated as the checks would record it, fires at the confidence its evidence
     * earns — and with the evidence for it traceable, so the rule is tested by what it concludes
     * rather than by how it is written.
     *
     * @return array<string, array{string, Closure(): CorrelationCase, Confidence}>
     */
    public static function everyKnownCause(): array
    {
        return [
            'credentials the server refused' => ['database.credentialsRejected', static fn(): CorrelationCase => self::case(
                self::issue('database.connection', Category::DATABASE),
                [self::connectionFailed(self::LOGIN_REFUSED, user: Redaction::PRESENT, password: Redaction::PRESENT)],
            ), Confidence::CONFIRMED],
            'a server nothing reaches' => ['database.serverUnreachable', static fn(): CorrelationCase => self::case(
                self::issue('database.connection', Category::DATABASE),
                [self::connectionFailed(self::UNREACHABLE), self::broke('database.migrations', Category::DATABASE, new PDOException(self::UNREACHABLE))],
            ), Confidence::HIGH],
            'a character set that refuses writes' => ['database.characterSet', static fn(): CorrelationCase => self::case(
                self::issue('queue.failedJobs', Category::QUEUE),
                [self::charset(sampledAcceptsMb4: false), self::failedJobs([['Saving entry', self::CHARACTER_ERROR, 2]]), self::broke('content.save', Category::CONTENT, new DbException(self::CHARACTER_ERROR))],
            ), Confidence::LIKELY],
            'a queue nothing runs' => ['queue.notProcessing', static fn(): CorrelationCase => self::case(
                self::issue('queue.backlog', Category::QUEUE),
                [self::backlog(waiting: 12, oldest: 7200, running: 0, automatic: false)],
            ), Confidence::HIGH],
            'jobs that run out of memory' => ['queue.outOfMemory', static fn(): CorrelationCase => self::case(
                self::issue('queue.failedJobs', Category::QUEUE),
                [self::failedJobs([['Generating transforms', self::OUT_OF_MEMORY, 3]]), self::php('128M')],
            ), Confidence::CONFIRMED],
            'a deployment left half-applied' => ['deployment.incomplete', static fn(): CorrelationCase => self::case(
                self::issue('database.migrations', Category::DATABASE),
                [self::migrations(['craft', 'plugin:seo']), self::projectConfig(pending: true)],
            ), Confidence::LIKELY],
            'problems in one plugin' => ['place.shared', static fn(): CorrelationCase => self::case(
                self::issue('seo.sitemap', Category::PLUGINS, plugin: 'seo'),
                [self::pluginHealth(failedToLoad: ['seo'])],
                [self::issue('seo.headers', Category::SECURITY, id: 2, plugin: 'seo', firstDetected: '-20 minutes')],
            ), Confidence::LIKELY],
            'one error under several checks' => ['error.shared', static fn(): CorrelationCase => self::sharedErrorCase(), Confidence::LIKELY],
            'a setting the environment lacks' => ['environment.settingMissing', static fn(): CorrelationCase => self::case(
                self::issue('environment.configuration', Category::ENVIRONMENT),
                [self::environment(securityKey: Redaction::MISSING)],
            ), Confidence::CONFIRMED],
            'an SMTP host the environment lacks' => ['environment.settingMissing', static fn(): CorrelationCase => self::case(
                self::issue('email.configuration', Category::EMAIL),
                [self::reported('email.configuration', Category::EMAIL, Status::FAIL, [
                    new Evidence(EvidenceType::ENVIRONMENT_VARIABLE, 'Mail transport', 'email.configuration', ['transportType' => 'craft\mail\transportadapters\Smtp', 'host' => Redaction::MISSING, 'port' => '587']),
                ])],
            ), Confidence::CONFIRMED],
        ];
    }

    /**
     * @param Closure(): CorrelationCase $case
     */
    #[DataProvider('everyKnownCause')]
    public function testEveryKnownCauseFiresOnTheEvidenceItWasWrittenFor(string $ruleId, Closure $case, Confidence $expected): void
    {
        $cause = self::cause((new RootCauses())->analyse($case())->causes, $ruleId);

        self::assertSame($expected, $cause->confidence);
        self::assertNotSame('', $cause->title);
        self::assertNotSame('', $cause->recommendation);
        self::assertNotSame([], $cause->nextSteps);

        foreach ($cause->supporting() as $condition) {
            foreach ($condition->observations as $observation) {
                self::assertTrue(
                    $observation->diagnosticId !== null || $observation->issueId !== null || $observation->errorFingerprint !== null,
                    "An observation behind {$ruleId} cannot be followed back to anything.",
                );
            }
        }

        // The last line of the reasoning says where the arithmetic came out.
        self::assertStringContainsString($expected->label(), $cause->reasoning[count($cause->reasoning) - 1]);
    }

    /**
     * No evidence, no cause: with nothing gathered, nothing is offered, whatever the problem.
     */
    #[DataProvider('everyKnownCause')]
    public function testACauseIsNeverOfferedWithoutWhatItRequires(string $ruleId, Closure $case): void
    {
        $issue = $case()->issue;

        self::assertSame([], (new RootCauses())->analyse(new CorrelationCase($issue))->causes, "{$ruleId} was offered with nothing to rest on.");
    }

    // --- Unrelated evidence: it neither makes a cause nor moves one.

    public function testACauseIsOnlyOfferedForAProblemItCouldExplain(): void
    {
        // Everything the character-set cause needs, gathered while investigating an email problem.
        $evidence = [
            self::charset(sampledAcceptsMb4: false),
            self::broke('content.save', Category::CONTENT, new DbException(self::CHARACTER_ERROR)),
            self::reported('email.configuration', Category::EMAIL, Status::FAIL),
        ];

        $email = (new RootCauses())->analyse(self::case(self::issue('email.configuration', Category::EMAIL), $evidence));
        $database = (new RootCauses())->analyse(self::case(self::issue('database.charset', Category::DATABASE), $evidence));

        self::assertNotContains('database.characterSet', array_map(static fn(RootCause $c): string => $c->ruleId, $email->causes));
        self::assertContains('database.characterSet', array_map(static fn(RootCause $c): string => $c->ruleId, $database->causes));
    }

    public function testEvidenceAboutSomethingElseLeavesACauseExactlyAsItWas(): void
    {
        $issue = self::issue('database.charset', Category::DATABASE);
        $alone = [self::charset(database: 'latin1', configured: 'utf8mb4', sampledAcceptsMb4: false)];
        $unrelated = [
            ...$alone,
            // An error that is not about characters and not from the database, and facts about
            // parts of the installation the cause says nothing about.
            self::broke('email.configuration', Category::EMAIL, new RuntimeException('Expected response code 250 but got code "550"')),
            self::php('512M'),
            self::backlog(waiting: 0, oldest: null, running: 0, automatic: true),
        ];

        $before = self::cause((new RootCauses())->analyse(self::case($issue, $alone))->causes, 'database.characterSet');
        $after = self::cause((new RootCauses())->analyse(self::case($issue, $unrelated))->causes, 'database.characterSet');

        self::assertSame(Confidence::POSSIBLE, $after->confidence);
        self::assertSame([], $after->conflicting());
        self::assertSame(Evidence::encode($before), Evidence::encode($after));
    }

    /**
     * Errors whose words resemble what a cause looks for, from checks the cause says nothing about.
     * Each of these once satisfied a condition it had no business satisfying.
     *
     * @return array<string, array{string, string, list<DiagnosticResult>}>
     */
    public static function lookalikes(): array
    {
        return [
            'a missing file is not an unreachable server' => ['database.serverUnreachable', 'unreachable', [
                self::connectionFailed('SQLSTATE[HY000] [9999] Unexpected handshake'),
                self::broke('filesystem.volumes', Category::FILESYSTEM, new RuntimeException('fopen(/var/www/storage/x.txt): No such file or directory')),
            ]],
            'an HTTP timeout is not an unreachable server' => ['database.serverUnreachable', 'unreachable', [
                self::connectionFailed('SQLSTATE[HY000] [9999] Unexpected handshake'),
                self::broke('tests.http', Category::HTTP, new RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds')),
            ]],
            'another check’s refused login is not the connection’s' => ['database.credentialsRejected', 'loginRefused', [
                self::connectionFailed('SQLSTATE[HY000] [9999] Unexpected handshake'),
                self::broke('tests.replica', Category::DATABASE, new PDOException(self::LOGIN_REFUSED)),
            ]],
            'a mailer’s character set is not the database’s' => ['database.characterSet', 'refusedCharacters', [
                self::charset(sampledAcceptsMb4: false),
                self::broke('email.configuration', Category::EMAIL, new RuntimeException('Unsupported character set "iso-2022-jp" and collation')),
            ]],
        ];
    }

    /**
     * @param list<DiagnosticResult> $results
     */
    #[DataProvider('lookalikes')]
    public function testAnErrorThatOnlyResemblesWhatACauseLooksForDoesNotSupportIt(string $ruleId, string $condition, array $results): void
    {
        $issue = $ruleId === 'database.characterSet' ? self::issue('database.charset', Category::DATABASE) : self::issue('database.connection', Category::DATABASE);
        $cause = self::cause((new RootCauses())->analyse(self::case($issue, $results))->causes, $ruleId);

        self::assertContains($condition, array_map(static fn(ConditionOutcome $c): string => $c->id, $cause->unmet()));
    }

    public function testMemoryThatSomethingElseRanOutOfIsNotAQueueJobRunningOutOfMemory(): void
    {
        $issue = self::issue('queue.failedJobs', Category::QUEUE);
        $offered = static fn(array $results): array => array_map(static fn(RootCause $c): string => $c->ruleId, (new RootCauses())->analyse(self::case($issue, $results))->causes);

        // A job that failed for another reason, a cache server's own memory, and PHP running out
        // of memory in a check rather than a job.
        self::assertNotContains('queue.outOfMemory', $offered([
            self::failedJobs([['Sending email', 'Expected response code 250 but got code "550"', 4]]),
            self::broke('tests.cache', Category::CRAFT, new RuntimeException('OOM command not allowed when used memory > maxmemory')),
            self::broke('tests.images', Category::ASSETS, new RuntimeException(self::OUT_OF_MEMORY)),
        ]));
        self::assertContains('queue.outOfMemory', $offered([self::failedJobs([['Generating transforms', self::OUT_OF_MEMORY, 1]])]));
    }

    // --- Issue history: only what still stands now, and only what began before this look.

    public function testOnlyProblemsStillOpenAndAlreadyKnownCountAsHistory(): void
    {
        $issue = self::issue('seo.sitemap', Category::PLUGINS, plugin: 'seo');
        $near = static fn(int $id, IssueStatus $status, bool $new = false): IssueSnapshot => new IssueSnapshot(
            id: $id,
            diagnosticId: "seo.check{$id}",
            category: Category::PLUGINS,
            title: "Check {$id}",
            severity: Severity::HIGH,
            status: $status,
            firstDetected: new DateTimeImmutable('2026-05-01 11:30:00'),
            affectedPlugin: 'seo',
            newThisRun: $new,
        );

        // A decision somebody made, an outcome already established, and a problem this very run
        // found for the first time: none of them is history this cause may rest on.
        $excluded = [$near(2, IssueStatus::IGNORED), $near(3, IssueStatus::WONT_FIX), $near(4, IssueStatus::RESOLVED), $near(5, IssueStatus::NEW, new: true)];

        self::assertNotContains('place.shared', array_map(static fn(RootCause $c): string => $c->ruleId, (new RootCauses())->analyse(self::case($issue, [], array_slice($excluded, 0, 3)))->causes));

        $cause = self::cause((new RootCauses())->analyse(self::case($issue, [], [...$excluded, $near(6, IssueStatus::CONFIRMED)]))->causes, 'place.shared');
        $together = array_values(array_filter($cause->conditions, static fn(ConditionOutcome $c): bool => $c->id === 'appearedTogether'))[0];

        // The one still open and already known supports it; the one found now is the same place
        // but says nothing about when anything began.
        self::assertSame([5, 6], array_map(static fn(Observation $o): ?int => $o->issueId, $cause->supporting()[0]->observations));
        self::assertSame([6], array_map(static fn(Observation $o): ?int => $o->issueId, $together->observations));
    }

    public function testADeploymentIsNeverTakenAsProvenWithoutARecordedDeployment(): void
    {
        $issue = self::issue('database.migrations', Category::DATABASE);
        $everythingElse = [self::migrations(['craft'], compatible: false), self::projectConfig(pending: true)];
        $others = [self::issue('projectConfig.pendingChanges', Category::PROJECT_CONFIG, id: 2, firstDetected: '-50 minutes')];

        $without = self::cause((new RootCauses())->analyse(self::case($issue, $everythingElse, $others))->causes, 'deployment.incomplete');

        // Three of the four signals, and the one missing is the deployment itself.
        self::assertSame(Confidence::LIKELY, $without->confidence);
        self::assertSame(['deployedJustBefore'], array_map(static fn(ConditionOutcome $c): string => $c->id, $without->unmet()));

        $deployment = self::reported('tests.deploys', Category::DEPLOYMENT, Status::INFO, [
            new Evidence(EvidenceType::DEPLOYMENT, 'Deployment', 'tests.deploys', ['release' => '2026.05.01'], observedAt: new DateTimeImmutable('2026-05-01 10:30:00')),
        ]);
        $with = self::cause((new RootCauses())->analyse(self::case($issue, [...$everythingElse, $deployment], $others))->causes, 'deployment.incomplete');

        // Everything found makes it high confidence, and still never confirmed: nothing here can
        // establish that a deployment is what left the work undone.
        self::assertSame(Confidence::HIGH, $with->confidence);
        self::assertFalse(array_values(array_filter(RootCauseRules::all(), static fn(RootCauseRule $r): bool => $r->id === 'deployment.incomplete'))[0]->canConfirm());
    }

    // --- Conflicting evidence: it is found, named, and lowers the cause.

    public function testEvidenceAgainstACauseLowersItAndIsShownBesideWhatSupportsIt(): void
    {
        $issue = self::issue('database.charset', Category::DATABASE);
        $supported = [
            self::charset(sampledAcceptsMb4: false),
            self::broke('content.save', Category::CONTENT, new DbException(self::CHARACTER_ERROR)),
            self::failedJobs([['Saving entry', self::CHARACTER_ERROR, 1]]),
        ];
        // The database also failed in a way that has nothing to do with characters.
        $contested = [...$supported, self::broke('database.migrations', Category::DATABASE, new PDOException(self::UNREACHABLE))];

        $clean = self::cause((new RootCauses())->analyse(self::case($issue, $supported))->causes, 'database.characterSet');
        $cause = self::cause((new RootCauses())->analyse(self::case($issue, $contested))->causes, 'database.characterSet');

        self::assertSame(Confidence::LIKELY, $clean->confidence);
        self::assertSame(Confidence::POSSIBLE, $cause->confidence);
        self::assertSame(['otherDatabaseErrors'], array_map(static fn(ConditionOutcome $c): string => $c->id, $cause->conflicting()));
        self::assertStringContainsString('Connection refused', (string)$cause->conflicting()[0]->observations[0]->detail);
        // What supports it is still all there; the conflict is added, not substituted.
        self::assertCount(3, $cause->supporting());
        // And what the conflict lends its weight to is itself not a candidate here: the connection
        // check did not fail, so an unreachable server is not something this case can offer.
        self::assertNotContains('database.serverUnreachable', array_map(static fn(RootCause $c): string => $c->ruleId, (new RootCauses())->analyse(self::case($issue, $contested))->causes));
    }

    public function testTwoCausesThatCannotBothBeTrueAreEachContradictedByTheOthersEvidence(): void
    {
        // The server answered and refused the login, and another check read the database anyway.
        $analysis = (new RootCauses())->analyse(self::case(self::issue('database.connection', Category::DATABASE), [
            self::connectionFailed(self::LOGIN_REFUSED, user: Redaction::PRESENT, password: Redaction::PRESENT),
            self::migrations([]),
        ]));

        $credentials = self::cause($analysis->causes, 'database.credentialsRejected');
        $unreachable = self::cause($analysis->causes, 'database.serverUnreachable');

        // Established evidence is not proof once something counts against it.
        self::assertSame(Confidence::LIKELY, $credentials->confidence);
        self::assertSame(['answeredElsewhere'], array_map(static fn(ConditionOutcome $c): string => $c->id, $credentials->conflicting()));
        self::assertSame(Confidence::POSSIBLE, $unreachable->confidence);
        self::assertSame(['loginRefused', 'answeredElsewhere'], array_map(static fn(ConditionOutcome $c): string => $c->id, $unreachable->conflicting()));
        // The better-supported explanation is offered first.
        self::assertSame('database.credentialsRejected', $analysis->causes[0]->ruleId);
    }

    // --- Confidence: how firmly a cause is held, from what was found.

    /**
     * The published ladder, one step at a time.
     *
     * @return array<string, array{list<ConditionOutcome>, Confidence, Confidence}>
     */
    public static function ladder(): array
    {
        $req = self::outcome(ConditionRole::REQUIRED, true);
        $for = self::outcome(ConditionRole::SUPPORTING, true);
        $missing = self::outcome(ConditionRole::SUPPORTING, false);
        $against = self::outcome(ConditionRole::CONTRADICTING, true);
        $notAgainst = self::outcome(ConditionRole::CONTRADICTING, false);
        $proof = self::outcome(ConditionRole::CONFIRMING, true, confirmed: true);
        $hearsay = self::outcome(ConditionRole::CONFIRMING, true, confirmed: false);

        return [
            'what it requires, and nothing more' => [[$req, $missing, $missing], Confidence::HIGH, Confidence::POSSIBLE],
            'and one signal for it' => [[$req, $for, $missing], Confidence::HIGH, Confidence::LIKELY],
            'and every signal for it' => [[$req, $for, $for], Confidence::HIGH, Confidence::HIGH],
            'one signal of one is not high' => [[$req, $for], Confidence::HIGH, Confidence::LIKELY],
            'never above its ceiling' => [[$req, $for, $for], Confidence::LIKELY, Confidence::LIKELY],
            'a ceiling of possible holds' => [[$req, $for, $for], Confidence::POSSIBLE, Confidence::POSSIBLE],
            'one step down for what counts against it' => [[$req, $for, $for, $against], Confidence::HIGH, Confidence::LIKELY],
            'two things against it, two steps down' => [[$req, $for, $for, $against, $against], Confidence::HIGH, Confidence::POSSIBLE],
            'never below possible' => [[$req, $against, $against], Confidence::HIGH, Confidence::POSSIBLE],
            'what it requires is not a signal for it' => [[$req, $req, $req], Confidence::HIGH, Confidence::POSSIBLE],
            'something not found against it costs nothing' => [[$req, $for, $for, $notAgainst], Confidence::HIGH, Confidence::HIGH],
            'proof from a check that established it' => [[$req, $proof, $missing], Confidence::HIGH, Confidence::CONFIRMED],
            'proof only from checks that suspected it is support' => [[$req, $hearsay, $missing], Confidence::HIGH, Confidence::LIKELY],
            'proof with something against it is not proof' => [[$req, $proof, $for, $against], Confidence::HIGH, Confidence::LIKELY],
            // The ceiling holds whatever the rest arrives at — proof included.
            'proof under a ceiling of likely is likely' => [[$req, $proof, $missing], Confidence::LIKELY, Confidence::LIKELY],
            'proof under a ceiling of possible is possible' => [[$req, $proof], Confidence::POSSIBLE, Confidence::POSSIBLE],
            'nothing found for it stays possible under any ceiling' => [[$req, $missing], Confidence::HIGH, Confidence::POSSIBLE],
            'the ceiling first, then a step down for what is against it' => [[$req, $for, $for, $against], Confidence::LIKELY, Confidence::POSSIBLE],
        ];
    }

    /**
     * The invariant behind every case above, over every combination and every ceiling: a cause is
     * never held more firmly than its rule allows, and only a ceiling of high lets proof through.
     */
    public function testNoCombinationOfFindingsCarriesACausePastItsCeiling(): void
    {
        $kinds = [
            self::outcome(ConditionRole::SUPPORTING, true),
            self::outcome(ConditionRole::SUPPORTING, false),
            self::outcome(ConditionRole::CONFIRMING, true, confirmed: true),
            self::outcome(ConditionRole::CONFIRMING, true, confirmed: false),
            self::outcome(ConditionRole::CONTRADICTING, true),
        ];
        $req = self::outcome(ConditionRole::REQUIRED, true);

        foreach ([Confidence::POSSIBLE, Confidence::LIKELY, Confidence::HIGH] as $ceiling) {
            $limit = $ceiling === Confidence::HIGH ? Confidence::CONFIRMED : $ceiling;

            // Every multiset of up to three of the kinds beside the requirement.
            foreach (range(0, 5 ** 3 - 1) as $n) {
                $conditions = [$req];

                for ($i = 0, $m = $n; $i < 3; $i++, $m = intdiv($m, 5)) {
                    $conditions[] = $kinds[$m % 5];
                }

                $confidence = RootCause::confidenceFor($conditions, $ceiling);

                self::assertLessThanOrEqual($limit->rank(), $confidence->rank(), "{$confidence->value} exceeded a ceiling of {$ceiling->value}.");
                self::assertContains($confidence, RootCause::LADDER);
            }
        }
    }

    /**
     * @param list<ConditionOutcome> $conditions
     */
    #[DataProvider('ladder')]
    public function testConfidenceFollowsThePublishedLadder(array $conditions, Confidence $ceiling, Confidence $expected): void
    {
        self::assertSame($expected, RootCause::confidenceFor($conditions, $ceiling));
    }

    public function testACauseIsNeverConfirmedOnTheWordOfACheckThatOnlySuspectedIt(): void
    {
        // The login refusal is recorded by a connection check that, for whatever reason, only
        // holds its finding as likely. Quoting it cannot make it proof.
        $unsure = self::connectionFailed(self::LOGIN_REFUSED, user: Redaction::PRESENT, password: Redaction::PRESENT, confidence: Confidence::LIKELY);
        $cause = self::cause((new RootCauses())->analyse(self::case(self::issue('database.connection', Category::DATABASE), [$unsure]))->causes, 'database.credentialsRejected');

        self::assertSame(Confidence::HIGH, $cause->confidence);
        self::assertTrue($cause->supporting()[1]->met());
        self::assertFalse($cause->supporting()[1]->confirms());
        self::assertStringContainsString('did not establish it', implode(' ', $cause->reasoning));
    }

    public function testNoCauseIsEverHeldAsMereContext(): void
    {
        // Informational is for things offered as background; a cause is offered as an explanation.
        self::assertNotContains(Confidence::INFORMATIONAL, RootCause::LADDER);
        self::assertSame(
            [Confidence::POSSIBLE, Confidence::LIKELY, Confidence::HIGH, Confidence::CONFIRMED],
            RootCause::LADDER,
        );
    }

    // --- Deterministic ordering: the same findings, the same answer.

    public function testTheSameFindingsAlwaysProduceTheSameCausesInTheSameOrder(): void
    {
        $issue = self::issue('database.connection', Category::DATABASE, plugin: 'seo');
        $results = [
            self::connectionFailed(self::UNREACHABLE, user: Redaction::MISSING, password: Redaction::PRESENT),
            self::broke('database.migrations', Category::DATABASE, new PDOException(self::UNREACHABLE)),
            self::broke('queue.backlog', Category::QUEUE, new PDOException(self::UNREACHABLE)),
            self::charset(sampledAcceptsMb4: false),
            self::pluginHealth(failedToLoad: ['seo']),
        ];
        $issues = [
            self::issue('seo.headers', Category::SECURITY, id: 7, plugin: 'seo', firstDetected: '-10 minutes'),
            self::issue('seo.sitemap', Category::PLUGINS, id: 3, plugin: 'seo', firstDetected: '-2 days'),
        ];

        $first = (new RootCauses())->analyse(self::case($issue, $results, $issues));
        $again = (new RootCauses())->analyse(self::case($issue, array_reverse($results), array_reverse($issues)));

        self::assertGreaterThan(2, count($first->causes));
        self::assertSame(Evidence::encode($first->causes), Evidence::encode($again->causes));
        self::assertSame(range(0, count($first->causes) - 1), array_map(static fn(RootCause $c): int => $c->position, $first->causes));

        // Most firmly held first, all the way down.
        $ranks = array_map(static fn(RootCause $c): int => $c->confidence->rank(), $first->causes);
        $sorted = $ranks;
        rsort($sorted);
        self::assertSame($sorted, $ranks);
    }

    /**
     * Each known cause, weighed from the same facts handed over in another order — the results,
     * the evidence within each, the other issues and the rules themselves all reversed — comes out
     * byte for byte the same.
     *
     * @param Closure(): CorrelationCase $case
     */
    #[DataProvider('everyKnownCause')]
    public function testEveryKnownCauseComesOutTheSameWhateverOrderItsFactsArriveIn(string $ruleId, Closure $case): void
    {
        $original = $case();
        $reversed = new CorrelationCase(
            issue: $original->issue,
            results: array_map(static fn(DiagnosticResult $r): DiagnosticResult => new DiagnosticResult(
                diagnosticId: $r->diagnosticId,
                name: $r->name,
                category: $r->category,
                status: $r->status,
                summary: $r->summary,
                evidence: array_reverse($r->evidence()),
                confidence: $r->confidence,
                affectedPlugin: $r->affectedPlugin,
            ), array_reverse($original->results())),
            issues: array_reverse($original->issues()),
            environment: $original->environment,
        );

        $first = (new RootCauses())->analyse($original);
        $again = (new RootCauses())->analyse($reversed, array_reverse(RootCauseRules::all()));

        self::assertContains($ruleId, array_map(static fn(RootCause $c): string => $c->ruleId, $first->causes));
        self::assertSame(Evidence::encode($first->causes), Evidence::encode($again->causes));
    }

    public function testCausesThatTieAreOrderedByRuleWhicheverOrderTheRulesAreHandedOverIn(): void
    {
        // A rule that always applies, with as many supporting signals as asked for, all found.
        $always = static fn(string $id, int $supports): RootCauseRule => new RootCauseRule(
            id: $id,
            title: $id,
            statement: '',
            explains: null,
            conditions: [
                Condition::requires('always', 'Always', static fn(CorrelationCase $c): array => [$c->observeIssue($c->issue)]),
                ...array_map(static fn(int $n): Condition => Condition::supports("s{$n}", 'Support', static fn(CorrelationCase $c): array => [$c->observeIssue($c->issue)]), range(1, $supports)),
            ],
            ceiling: Confidence::HIGH,
            recommendation: 'Do something.',
            nextSteps: ['Look.'],
        );

        $case = self::case(self::issue('tests.origin', Category::DATABASE));

        // Equally held: by rule ID, not by the order the rules arrived in.
        self::assertSame(['tests.a', 'tests.b'], array_map(static fn(RootCause $c): string => $c->ruleId, (new RootCauses())->analyse($case, [$always('tests.b', 1), $always('tests.a', 1)])->causes));
        self::assertSame(['tests.a', 'tests.b'], array_map(static fn(RootCause $c): string => $c->ruleId, (new RootCauses())->analyse($case, [$always('tests.a', 1), $always('tests.b', 1)])->causes));
        // Held more firmly: first, wherever it is written.
        self::assertSame(['tests.firm', 'tests.a'], array_map(static fn(RootCause $c): string => $c->ruleId, (new RootCauses())->analyse($case, [$always('tests.a', 1), $always('tests.firm', 2)])->causes));
    }

    public function testARuleThatBreaksCostsOnlyItselfAndIsCounted(): void
    {
        $broken = new RootCauseRule(
            id: 'tests.broken',
            title: 'Broken',
            statement: '',
            explains: null,
            conditions: [Condition::requires('boom', 'Boom', static fn(): array => throw new RuntimeException('Rule broke.'))],
            ceiling: Confidence::HIGH,
            recommendation: '',
            nextSteps: [],
        );

        $case = self::case(self::issue('environment.configuration', Category::ENVIRONMENT), [self::environment(securityKey: Redaction::MISSING)]);
        $analysis = (new RootCauses())->analyse($case, [$broken, ...RootCauseRules::all()]);

        self::assertSame(['tests.broken'], $analysis->failed);
        // Which rules broke is listed in one order, whatever order they were weighed in.
        $twice = (new RootCauses())->analyse($case, [$broken, ...RootCauseRules::all(), new RootCauseRule('tests.alsoBroken', 'x', '', null, $broken->conditions, Confidence::HIGH, '', [])]);
        self::assertSame(['tests.alsoBroken', 'tests.broken'], $twice->failed);
        self::assertSame(count(RootCauseRules::all()) + 1, $analysis->weighed);
        self::assertSame('environment.settingMissing', $analysis->leading()?->ruleId);
    }

    // --- The registry: the one written-out list of known causes.

    public function testEveryKnownCauseIsWrittenOutCompletelyAndCannotOverclaim(): void
    {
        $ids = [];

        foreach (RootCauseRules::all() as $rule) {
            $ids[] = $rule->id;
            self::assertMatchesRegularExpression('/^[a-z][a-zA-Z0-9]*\.[a-z][a-zA-Z0-9]*$/', $rule->id);
            self::assertNotSame('', $rule->title, $rule->id);
            self::assertNotSame('', $rule->statement, $rule->id);
            self::assertNotSame('', $rule->recommendation, $rule->id);
            self::assertNotSame([], $rule->nextSteps, $rule->id);

            $roles = array_map(static fn(Condition $c): ConditionRole => $c->role, $rule->conditions);
            $conditionIds = array_map(static fn(Condition $c): string => $c->id, $rule->conditions);

            self::assertContains(ConditionRole::REQUIRED, $roles, "{$rule->id} would be offered on nothing at all.");
            self::assertSame($conditionIds, array_unique($conditionIds), $rule->id);

            foreach ($rule->conditions as $condition) {
                self::assertNotSame('', $condition->description, "{$rule->id}.{$condition->id}");
            }

            // Confirmed is reached only through confirming evidence, and a rule able to reach it
            // must not be capped below high on the way. A rule that can never be confirmed, or is
            // held lower, says why.
            self::assertContains($rule->ceiling, [Confidence::POSSIBLE, Confidence::LIKELY, Confidence::HIGH], $rule->id);

            if ($rule->canConfirm()) {
                self::assertSame(Confidence::HIGH, $rule->ceiling, $rule->id);
            } else {
                self::assertNotNull($rule->limitation, "{$rule->id} can never be confirmed and does not say why.");
            }
        }

        self::assertSame($ids, array_unique($ids));
        $covered = array_unique(array_map(static fn(array $case): string => $case[0], self::everyKnownCause()));
        sort($covered);
        sort($ids);
        self::assertSame($ids, $covered, 'A known cause has no case in everyKnownCause().');
    }

    /**
     * A cause offered for a kind of problem has to be reachable from it: the investigation of an
     * issue raised by a shipped check must run a check whose findings the cause requires, or the
     * cause is promised for problems it can never be weighed against. Which check each cause
     * requires is written here rather than read from the closures, which cannot be inspected.
     */
    public function testEveryCauseOfferedForAShippedChecksProblemIsOneItsInvestigationCanReach(): void
    {
        $requires = [
            'database.credentialsRejected' => ['database.connection'],
            'database.serverUnreachable' => ['database.connection'],
            'database.characterSet' => ['database.charset'],
            'queue.notProcessing' => ['queue.backlog'],
            'queue.outOfMemory' => ['queue.failedJobs'],
            'deployment.incomplete' => ['database.migrations', 'projectConfig.pendingChanges'],
        ];
        $shipped = CoreDiagnostics::all();
        $rules = [];

        foreach (RootCauseRules::all() as $rule) {
            $rules[$rule->id] = $rule;
        }

        self::assertSame([], array_diff(array_keys($requires), array_keys($rules)));

        foreach ($shipped as $origin) {
            $plan = InvestigationPlan::build($origin->id(), $origin->category(), null, $shipped);

            foreach ($requires as $ruleId => $needs) {
                if (!$rules[$ruleId]->explains($origin->category())) {
                    continue;
                }

                self::assertNotSame(
                    [],
                    array_intersect($needs, $plan->diagnosticIds()),
                    "{$ruleId} is offered for a {$origin->id()} issue, whose investigation runs none of " . implode(', ', $needs) . '.',
                );
            }
        }
    }

    // --- What a cause is made of.

    public function testAConditionListsEachFactOnceInAFixedOrderAndBoundsHowMany(): void
    {
        $issue = self::issue('tests.origin', Category::DATABASE);
        $many = array_map(static fn(int $n): IssueSnapshot => self::issue("tests.other{$n}", Category::DATABASE, id: 100 + $n), range(1, Condition::MAX_OBSERVATIONS + 5));
        $condition = Condition::supports('many', 'Many', static fn(CorrelationCase $c): array => [
            ...array_map(static fn(IssueSnapshot $i): Observation => $c->observeIssue($i), array_reverse($c->issues())),
            $c->observeIssue($c->issues()[0]),
        ]);

        $outcome = $condition->assess(self::case($issue, [], $many));

        // Ordered by what each fact is and where it came from — here, the check that raised it —
        // however the condition happened to find them.
        $expected = array_map(static fn(IssueSnapshot $i): string => $i->diagnosticId, $many);
        sort($expected, SORT_STRING);

        self::assertCount(Condition::MAX_OBSERVATIONS, $outcome->observations);
        self::assertSame(5, $outcome->omitted);
        self::assertSame(array_slice($expected, 0, Condition::MAX_OBSERVATIONS), array_map(static fn(Observation $o): ?string => $o->diagnosticId, $outcome->observations));
    }

    public function testWhatACauseQuotesIsRedactedBoundedAndReadsBackAsItWas(): void
    {
        $observation = new Observation(
            kind: Observation::ERROR,
            label: 'Login failed password=hunter2',
            detail: "SMTP refused: password=hunter2 " . str_repeat('x', 900),
            diagnosticId: 'database.connection',
            errorFingerprint: str_repeat('a', 64),
            confirmed: true,
            at: new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
        );

        self::assertStringNotContainsString('hunter2', $observation->label . $observation->detail);
        self::assertLessThanOrEqual(Observation::MAX_DETAIL_LENGTH, mb_strlen((string)$observation->detail));

        $outcome = new ConditionOutcome('loginRefused', ConditionRole::CONFIRMING, 'Refused', [$observation], omitted: 2);
        $back = ConditionOutcome::fromArray(json_decode(Evidence::encode($outcome), true));

        self::assertEquals($outcome, $back);
        self::assertTrue($back->confirms());
        // Something this version does not recognise never reads as more than it was.
        self::assertSame(Observation::ISSUE, Observation::fromArray(['kind' => 'rumour', 'label' => 'x'])->kind);
        self::assertSame(ConditionRole::SUPPORTING, ConditionOutcome::fromArray(['role' => 'decisive'])->role);
    }

    // Helpers ----------------------------------------------------------------

    private static function sharedErrorCase(): CorrelationCase
    {
        $exception = new RuntimeException('Redis connection refused at cache:6379');

        return self::case(self::issue('tests.cache', Category::CRAFT), [
            self::reported('tests.cache', Category::CRAFT, Status::FAIL, [Evidence::fromThrowable($exception, 'tests.cache')]),
            self::reported('tests.sessions', Category::CRAFT, Status::ERROR, [Evidence::fromThrowable($exception, 'tests.sessions')]),
        ]);
    }

    /**
     * @param list<DiagnosticResult> $results
     * @param list<IssueSnapshot> $issues
     */
    private static function case(IssueSnapshot $issue, array $results = [], array $issues = []): CorrelationCase
    {
        return new CorrelationCase(issue: $issue, results: $results, issues: $issues, environment: 'production');
    }

    private static function issue(string $diagnosticId, Category $category, int $id = 1, ?string $plugin = null, string $firstDetected = '-1 hour'): IssueSnapshot
    {
        return new IssueSnapshot(
            id: $id,
            diagnosticId: $diagnosticId,
            category: $category,
            title: "{$diagnosticId} reports a problem.",
            severity: Severity::HIGH,
            status: IssueStatus::NEW,
            firstDetected: new DateTimeImmutable('2026-05-01 12:00:00 ' . $firstDetected),
            affectedPlugin: $plugin,
        );
    }

    /**
     * @param list<Evidence> $evidence
     */
    private static function reported(string $id, Category $category, Status $status, array $evidence = [], Confidence $confidence = Confidence::CONFIRMED): DiagnosticResult
    {
        return new DiagnosticResult(diagnosticId: $id, name: "Check {$id}", category: $category, status: $status, summary: "{$id} says so.", evidence: $evidence, confidence: $confidence);
    }

    /** A check that broke on an exception, recorded the way the engine records it. */
    private static function broke(string $id, Category $category, \Throwable $exception): DiagnosticResult
    {
        return self::reported($id, $category, Status::ERROR, [Evidence::fromThrowable($exception, $id), Evidence::stackTrace($exception, $id)], Confidence::INFORMATIONAL);
    }

    private static function connectionFailed(string $message, string $user = Redaction::PRESENT, string $password = Redaction::PRESENT, Confidence $confidence = Confidence::CONFIRMED): DiagnosticResult
    {
        return self::reported('database.connection', Category::DATABASE, Status::FAIL, [
            new Evidence(EvidenceType::CONFIGURATION, 'Database settings', 'database.connection', ['driver' => 'mysql', 'server' => 'db', 'user' => $user, 'password' => $password]),
            new Evidence(EvidenceType::DATABASE_ERROR, 'Connection attempt', 'database.connection', ['succeeded' => false]),
            Evidence::fromThrowable(new PDOException($message), 'database.connection'),
        ], $confidence);
    }

    private static function charset(string $database = 'utf8mb4', string $configured = 'utf8mb4', bool $sampledAcceptsMb4 = true): DiagnosticResult
    {
        return self::reported('database.charset', Category::DATABASE, Status::WARNING, [
            new Evidence(EvidenceType::DATABASE, 'Character set', 'database.charset', [
                'driver' => 'MySQL',
                'configuredCharset' => $configured,
                'databaseCharset' => $database,
                'sampledTable' => 'elements_sites',
                'sampledTableAcceptsMb4' => $sampledAcceptsMb4,
                'columnsInspected' => false,
            ], reference: 'elements_sites'),
        ]);
    }

    /**
     * @param list<array{string, string, int}> $jobs Description, recorded error, occurrences.
     */
    private static function failedJobs(array $jobs): DiagnosticResult
    {
        $evidence = [new Evidence(EvidenceType::QUEUE, 'Failed jobs', 'queue.failedJobs', ['failed' => array_sum(array_column($jobs, 2))])];

        foreach ($jobs as [$description, $error, $occurrences]) {
            $evidence[] = new Evidence(EvidenceType::QUEUE_JOB, $description, 'queue.failedJobs', [
                'description' => $description,
                'occurrences' => $occurrences,
                'error' => $error,
            ]);
        }

        return self::reported('queue.failedJobs', Category::QUEUE, Status::FAIL, $evidence);
    }

    private static function backlog(int $waiting, ?int $oldest, int $running, bool $automatic): DiagnosticResult
    {
        return self::reported('queue.backlog', Category::QUEUE, $oldest !== null && $oldest >= 1800 ? Status::FAIL : Status::PASS, [
            new Evidence(EvidenceType::QUEUE, 'Queue depth', 'queue.backlog', [
                'waiting' => $waiting,
                'running' => $running,
                'delayed' => 0,
                'oldestWaitingSeconds' => $oldest,
                'longestRunningSeconds' => null,
                'longestRunningTimeLimit' => null,
                'runQueueAutomatically' => $automatic,
            ]),
        ]);
    }

    private static function php(string $memoryLimit): DiagnosticResult
    {
        return self::reported('php.configuration', Category::PHP, Status::PASS, [
            new Evidence(EvidenceType::CONFIGURATION, 'PHP configuration', 'php.configuration', ['sapi' => 'fpm-fcgi', 'memory_limit' => $memoryLimit, 'max_execution_time' => '120']),
        ]);
    }

    /**
     * @param list<string> $pending
     */
    private static function migrations(array $pending, bool $compatible = true): DiagnosticResult
    {
        return self::reported('database.migrations', Category::DATABASE, $pending === [] && $compatible ? Status::PASS : Status::FAIL, [
            new Evidence(EvidenceType::DATABASE, 'Migration state', 'database.migrations', [
                'schemaVersionCompatible' => $compatible,
                'pendingMigrations' => $pending,
                'codeSchemaVersion' => '5.8.0.3',
                'includesContentMigrations' => true,
            ]),
        ]);
    }

    private static function projectConfig(bool $pending): DiagnosticResult
    {
        return self::reported('projectConfig.pendingChanges', Category::PROJECT_CONFIG, $pending ? Status::WARNING : Status::PASS, [
            new Evidence(EvidenceType::PROJECT_CONFIG, 'Project config state', 'projectConfig.pendingChanges', [
                'externalConfigExists' => true,
                'changesPending' => $pending,
                'allowAdminChanges' => false,
            ]),
        ]);
    }

    /**
     * @param list<string> $failedToLoad
     */
    private static function pluginHealth(array $failedToLoad = []): DiagnosticResult
    {
        return self::reported('plugins.health', Category::PLUGINS, $failedToLoad === [] ? Status::PASS : Status::FAIL, [
            new Evidence(EvidenceType::PLUGIN, 'Plugin state', 'plugins.health', [
                'recorded' => 4,
                'failedToLoad' => $failedToLoad,
                'missingFromProject' => [],
                'licensing' => [],
            ]),
        ]);
    }

    private static function environment(string $securityKey): DiagnosticResult
    {
        return self::reported('environment.configuration', Category::ENVIRONMENT, $securityKey === Redaction::MISSING ? Status::FAIL : Status::INFO, [
            new Evidence(EvidenceType::CONFIGURATION, 'Environment settings', 'environment.configuration', ['environment' => 'production', 'devMode' => false]),
            new Evidence(EvidenceType::ENVIRONMENT_VARIABLE, 'Environment variables', 'environment.configuration', ['CRAFT_ENVIRONMENT' => Redaction::PRESENT, 'CRAFT_SECURITY_KEY' => $securityKey]),
        ]);
    }

    private static function outcome(ConditionRole $role, bool $met, bool $confirmed = false): ConditionOutcome
    {
        return new ConditionOutcome(
            id: $role->value,
            role: $role,
            description: $role->label(),
            observations: $met ? [new Observation(kind: Observation::RESULT, label: 'Seen', diagnosticId: 'tests.x', confirmed: $confirmed)] : [],
        );
    }

    /**
     * @param list<RootCause> $causes
     */
    private static function cause(array $causes, string $ruleId): RootCause
    {
        foreach ($causes as $cause) {
            if ($cause->ruleId === $ruleId) {
                return $cause;
            }
        }

        self::fail(sprintf('%s was not offered. Offered: %s', $ruleId, implode(', ', array_map(static fn(RootCause $c): string => $c->ruleId, $causes)) ?: 'nothing'));
    }
}
