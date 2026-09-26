<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
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
use Tahadudhiya\WebDoctor\enums\Severity;

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
    public function testACheckThatCouldNotRunIsNotACheckThatFoundNothing(): void
    {
        // The difference the whole product rests on: a diagnostic that broke has said nothing
        // about the site, and must never be read as a clean result.
        self::assertTrue(DiagnosticStatus::PASS->isConclusive());
        self::assertFalse(DiagnosticStatus::ERROR->isConclusive());
        self::assertFalse(DiagnosticStatus::UNKNOWN->isConclusive());
        self::assertFalse(DiagnosticStatus::SKIPPED->isConclusive());
    }

    public function testACheckThatFailedIsNotACheckThatBroke(): void
    {
        // FAIL: the thing inspected is broken. ERROR: the check is broken and knows nothing.
        self::assertTrue(DiagnosticStatus::FAIL->isConclusive());
        self::assertTrue(DiagnosticStatus::FAIL->isProblem());

        self::assertFalse(DiagnosticStatus::ERROR->isConclusive());
        self::assertFalse(DiagnosticStatus::ERROR->isProblem());
    }

    public function testOnlyFindingsAboutTheSiteCountAsProblems(): void
    {
        self::assertTrue(DiagnosticStatus::WARNING->isProblem());
        self::assertTrue(DiagnosticStatus::FAIL->isProblem());

        // An error is Web Doctor's problem, not the site's.
        self::assertFalse(DiagnosticStatus::ERROR->isProblem());
        self::assertFalse(DiagnosticStatus::INFO->isProblem());
        self::assertFalse(DiagnosticStatus::PASS->isProblem());
    }

    public function testWhatCountsAgainstHealthIsEveryUnansweredQuestionAndNothingElse(): void
    {
        $counting = array_values(array_filter(
            DiagnosticStatus::cases(),
            static fn(DiagnosticStatus $s): bool => $s->countsTowardHealth(),
        ));

        // An unanswered question is not a clean bill of health; a check that did not apply is
        // not a question at all.
        self::assertSame(
            [DiagnosticStatus::WARNING, DiagnosticStatus::FAIL, DiagnosticStatus::ERROR, DiagnosticStatus::UNKNOWN],
            $counting,
        );
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

    public function testSeveritiesAreOrderedLowToHigh(): void
    {
        $ranks = array_map(static fn(Severity $s): int => $s->rank(), Severity::cases());

        self::assertSame([0, 1, 2, 3, 4], $ranks);
        self::assertSame(['info', 'low', 'medium', 'high', 'critical'], Severity::values());
    }

    public function testTheMoreSevereOfTwoIsTaken(): void
    {
        self::assertSame(Severity::CRITICAL, Severity::LOW->max(Severity::CRITICAL));
        self::assertSame(Severity::CRITICAL, Severity::CRITICAL->max(Severity::LOW));
    }

    public function testTheWorstOfManyIsFound(): void
    {
        self::assertSame(
            Severity::HIGH,
            Severity::highest([Severity::LOW, Severity::HIGH, Severity::MEDIUM]),
        );
    }

    public function testNothingHasNoWorstSeverity(): void
    {
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
