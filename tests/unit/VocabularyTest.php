<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\enums\AuditAction;
use Tahadudhiya\WebDoctor\enums\AuditObjectType;
use Tahadudhiya\WebDoctor\enums\AuditResult;
use Tahadudhiya\WebDoctor\enums\ConditionRole;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\InvestigationStatus;
use Tahadudhiya\WebDoctor\enums\InvestigationStepType;
use Tahadudhiya\WebDoctor\enums\IssueEventType;
use Tahadudhiya\WebDoctor\enums\IssueResolution;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;

/**
 * The words Web Doctor's domain is made of: what happened to a check (status), how much it
 * matters (severity), how firmly a conclusion is held (confidence), what kind of fact backs it
 * (evidence type), where a problem stands
 * once it outlives the run that found it (issue status, resolution, event type), how an
 * investigation of it went (investigation status, step type), and what part a finding plays in a
 * cause it is weighed for (condition role). The rest of the
 * plugin reasons from these distinctions, so they are asserted rather than left to whoever reads
 * the enums next.
 */
class VocabularyTest extends TestCase
{
    /**
     * What each status says, as a truth table over every case. The difference the whole product
     * rests on: a check that broke (`error`) has said nothing about the site and is never read as
     * a clean result, nor as a problem with the site — which only `warning` and `fail` are. An
     * unanswered question is not a clean bill of health, so it counts against it; a check that did
     * not apply is not a question at all.
     */
    public function testWhatEachStatusSaysAboutTheSite(): void
    {
        // [conclusive, a problem, counts against health]
        $expected = [
            'pass' => [true, false, false],
            'info' => [true, false, false],
            'warning' => [true, true, true],
            'fail' => [true, true, true],
            'error' => [false, false, true],
            'skipped' => [false, false, false],
            'unknown' => [false, false, true],
        ];

        $actual = [];
        foreach (DiagnosticStatus::cases() as $status) {
            $actual[$status->value] = [$status->isConclusive(), $status->isProblem(), $status->countsTowardHealth()];
        }

        self::assertSame($expected, $actual);
    }

    public function testEveryStatusImpliesASeverityForResultsThatStateNone(): void
    {
        foreach (DiagnosticStatus::cases() as $status) {
            self::assertInstanceOf(Severity::class, $status->defaultSeverity());

            // Calling something critical is a judgement a diagnostic makes deliberately. A
            // default that produced it would make the word meaningless.
            self::assertNotSame(Severity::CRITICAL, $status->defaultSeverity());
        }

        self::assertSame(Severity::HIGH, DiagnosticStatus::FAIL->defaultSeverity());
        self::assertSame(Severity::MEDIUM, DiagnosticStatus::WARNING->defaultSeverity());
        self::assertSame(Severity::INFO, DiagnosticStatus::PASS->defaultSeverity());
    }

    public function testNoStatusIsASeverity(): void
    {
        // The statuses say what happened, never how much it matters. `critical` is a severity
        // and must not reappear here, which is what this fails on if it is ever added back.
        self::assertSame(
            ['pass', 'info', 'warning', 'fail', 'error', 'skipped', 'unknown'],
            DiagnosticStatus::values(),
        );
        self::assertNotContains('critical', DiagnosticStatus::values());
    }

    public function testSeveritiesAreOrderedLowToHighAndTheWorstIsTaken(): void
    {
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn(Severity $s): int => $s->rank(), Severity::cases()));
        self::assertSame(['info', 'low', 'medium', 'high', 'critical'], Severity::values());

        self::assertSame(Severity::CRITICAL, Severity::LOW->max(Severity::CRITICAL));
        self::assertSame(Severity::CRITICAL, Severity::CRITICAL->max(Severity::LOW));
        self::assertSame(Severity::HIGH, Severity::highest([Severity::LOW, Severity::HIGH, Severity::MEDIUM]));

        // A run with nothing against it reports no worst severity rather than the mildest one,
        // which would read as a finding that was never made.
        self::assertNull(Severity::highest([]));
    }

    public function testConfidenceRunsFromContextToCertainty(): void
    {
        // Nothing may claim more than confirmed, and confirmed is only ever stated deliberately
        // by a diagnostic that has the evidence for it.
        self::assertSame(
            [Confidence::CONFIRMED, Confidence::HIGH, Confidence::LIKELY, Confidence::POSSIBLE, Confidence::INFORMATIONAL],
            Confidence::cases(),
        );

        // Ranked in the same order, so causes are sorted by what the words mean.
        self::assertSame([4, 3, 2, 1, 0], array_map(static fn(Confidence $c): int => $c->rank(), Confidence::cases()));
    }

    public function testRepairRiskIsTheVocabularysThreeLevelsEachExplained(): void
    {
        self::assertSame(['low', 'medium', 'high'], array_column(RepairRisk::cases(), 'value'));

        $explanations = array_map(static fn(RepairRisk $r): string => $r->explanation(), RepairRisk::cases());
        self::assertNotContains('', $explanations);
        self::assertSame($explanations, array_unique($explanations));
    }

    public function testOnlyAHighRiskRepairTakesATypedConfirmation(): void
    {
        // Every repair is confirmed; only the one that rewrites data asks for the environment's
        // name to be typed, so the extra step keeps its meaning rather than becoming a reflex.
        self::assertSame(
            [RepairRisk::HIGH],
            array_values(array_filter(RepairRisk::cases(), static fn(RepairRisk $r): bool => $r->requiresTypedConfirmation())),
        );
    }

    public function testARepairSaysWhetherItRanAndNeverWhetherItWorked(): void
    {
        // Whether a repair worked is verification's answer, so nothing in the repair's own status
        // says "fixed". A verification answers with the vocabulary's two results, or says it could
        // not tell — and only those three are a verification's to give.
        self::assertSame(['previewed', 'running', 'succeeded', 'failed', 'superseded'], RepairStatus::values());
        self::assertSame(
            [RepairStatus::SUCCEEDED, RepairStatus::FAILED],
            array_values(array_filter(RepairStatus::cases(), static fn(RepairStatus $s): bool => $s->wasExecuted())),
        );
        self::assertSame(['none', 'pending', 'verified', 'verification_failed', 'inconclusive'], VerificationStatus::values());
        self::assertSame(
            [VerificationStatus::VERIFIED, VerificationStatus::FAILED, VerificationStatus::INCONCLUSIVE],
            array_values(array_filter(VerificationStatus::cases(), static fn(VerificationStatus $s): bool => $s->isResult())),
        );

        // A failed verification says in as many words that the repair was completed and the problem
        // is still there, so it is never read as the repair not having run.
        self::assertStringContainsString('completed', VerificationStatus::FAILED->explanation());
        self::assertStringContainsString('still reports it', VerificationStatus::FAILED->explanation());
        self::assertStringContainsString('stays open', VerificationStatus::FAILED->explanation());
    }

    public function testEveryWordInTheVocabularyIsLabelledAndSpeltOnce(): void
    {
        $vocabularies = [
            DiagnosticStatus::cases(),
            Severity::cases(),
            Confidence::cases(),
            IssueStatus::cases(),
            IssueResolution::cases(),
            IssueEventType::cases(),
            EvidenceType::cases(),
            InvestigationStatus::cases(),
            InvestigationStepType::cases(),
            ConditionRole::cases(),
            RepairRisk::cases(),
            RepairStatus::cases(),
            VerificationStatus::cases(),
            AuditAction::cases(),
            AuditResult::cases(),
            AuditObjectType::cases(),
        ];

        foreach ($vocabularies as $cases) {
            $labels = [];

            foreach ($cases as $case) {
                self::assertNotSame('', $case->label(), $case->value);
                $labels[] = $case->label();
            }

            // Two words in one vocabulary reading the same on screen would make a filter or a
            // status column impossible to act on.
            self::assertSame($labels, array_unique($labels));
        }
    }

    public function testTheAuditTrailNamesActsByWhatTheyWereDoneToAndHowTheyEndedNotWhatTheyFound(): void
    {
        // Each act is its object and its verb, so the log groups and filters by either; the values
        // are stored, so they are fixed.
        foreach (AuditAction::cases() as $action) {
            self::assertMatchesRegularExpression('/\A[a-z]+\.[a-z][a-zA-Z]*\z/', $action->value);
        }

        self::assertSame(
            ['diagnostics.started', 'diagnostics.completed', 'investigation.started', 'investigation.completed', 'recommendations.generated', 'repair.previewed', 'repair.executed', 'verification.executed', 'issue.resolved', 'issue.statusChanged'],
            AuditAction::values(),
        );

        // How it ended, never what it found: a run that found ten problems succeeded.
        self::assertSame(['succeeded', 'partial', 'failed', 'inconclusive', 'none'], AuditResult::values());
    }

    public function testEvidenceCanBeTypedByEveryPartOfTheInstallationItDescribes(): void
    {
        // Evidence takes the most particular type that is true of it, so both the state of a part
        // of the installation and the particular facts within it have a word.
        $missing = array_diff(
            ['configuration', 'database', 'logEntry', 'queue', 'queueJob', 'filesystem', 'plugin', 'element', 'httpResponse', 'environmentVariable', 'deployment', 'system'],
            array_column(EvidenceType::cases(), 'value'),
        );

        self::assertSame([], array_values($missing));
    }

    // --- Where a problem stands once it outlives the run that found it.

    public function testEveryIssueStatusIsEitherOutstandingOrClosed(): void
    {
        $open = IssueStatus::open();

        foreach (IssueStatus::cases() as $status) {
            self::assertSame(in_array($status, $open, true), $status->isOpen(), $status->value);
        }

        self::assertNotEmpty($open);
        self::assertNotCount(count(IssueStatus::cases()), $open);
    }

    public function testOnlyWebDoctorMaySetAnIssueResolvedOrRepairing(): void
    {
        // The distinction the Issue Center rests on. Resolution is established by what a later
        // run observes and repair state belongs to whatever repairs, so neither is a person's to
        // type in — otherwise "resolved" would come to mean "somebody clicked resolved".
        $refused = array_values(array_filter(
            IssueStatus::cases(),
            static fn(IssueStatus $s): bool => !$s->isSettableByHand(),
        ));

        self::assertEqualsCanonicalizing([IssueStatus::RESOLVED, IssueStatus::REPAIRING], $refused);

        $offered = IssueStatus::settableByHand();

        self::assertNotContains(IssueStatus::RESOLVED, $offered);
        self::assertNotContains(IssueStatus::REPAIRING, $offered);
        self::assertCount(count(IssueStatus::cases()) - 2, $offered);
    }

    public function testADismissalIsAClosedJudgementThatNeedsAReasonAndResolvedIsNeither(): void
    {
        foreach ([IssueStatus::IGNORED, IssueStatus::WONT_FIX] as $status) {
            self::assertTrue($status->isDismissal(), $status->value);
            self::assertTrue($status->requiresReason(), $status->value);
            self::assertFalse($status->isOpen(), $status->value);
            // The point of a dismissal is that it is somebody's decision, so it has to be one
            // they can actually make.
            self::assertTrue($status->isSettableByHand(), $status->value);
        }

        // Nobody decided it, so there is nobody to ask for a reason. What stands in for one is
        // the run that established it.
        self::assertFalse(IssueStatus::RESOLVED->isDismissal());
        self::assertFalse(IssueStatus::RESOLVED->requiresReason());
    }

    public function testIssueStatusesAreOrderedByLifecycleRatherThanAlphabet(): void
    {
        $positions = array_map(static fn(IssueStatus $s): int => $s->position(), IssueStatus::cases());

        self::assertSame($positions, array_unique($positions));
        self::assertLessThan(IssueStatus::RESOLVED->position(), IssueStatus::NEW->position());
        self::assertLessThan(IssueStatus::RESOLVED->position(), IssueStatus::INVESTIGATING->position());
    }

    public function testAResolutionSaysHowFirmlyAnIssueIsResolved(): void
    {
        self::assertSame(IssueResolution::NONE, IssueResolution::tryFrom('none'));
        self::assertStringContainsString('not been resolved', IssueResolution::NONE->explanation());

        // The only claim Web Doctor can make today says outright that it is not a verification.
        // If that sentence ever quietly becomes a stronger one, this fails.
        $observed = IssueResolution::OBSERVED_CLEAR->explanation();

        self::assertStringContainsString('no longer reports', $observed);
        self::assertStringContainsString('not a verification', $observed);

        // The stronger claim says what earned it: a repair verified, not merely a check gone quiet.
        self::assertSame(IssueResolution::VERIFIED, IssueResolution::tryFrom('verified'));
        self::assertStringContainsString('repair of this issue was verified', IssueResolution::VERIFIED->explanation());
    }

    // --- How an investigation went.

    public function testAnInvestigationSaysWhetherItGotItsAnswersNotWhatTheyWere(): void
    {
        // Only a running investigation is unfinished. The other three are all endings, and they
        // differ in how much of the plan was answered — never in whether the site looked healthy.
        self::assertSame([InvestigationStatus::RUNNING], array_values(array_filter(
            InvestigationStatus::cases(),
            static fn(InvestigationStatus $s): bool => !$s->isFinished(),
        )));
        self::assertSame(['running', 'completed', 'partial', 'failed'], InvestigationStatus::values());
    }

    // --- What a finding does to a cause it is weighed for.

    public function testOnlyEvidenceAgainstACauseCountsAgainstIt(): void
    {
        // Required, supporting and confirming evidence are all evidence for a cause; a reader shown
        // "the evidence" has to be shown those three, and what counts against it apart from them.
        self::assertSame([ConditionRole::CONTRADICTING], array_values(array_filter(
            ConditionRole::cases(),
            static fn(ConditionRole $r): bool => !$r->isFor(),
        )));
        self::assertSame(['required', 'supporting', 'confirming', 'contradicting'], ConditionRole::values());
    }
}
