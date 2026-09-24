<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;

/**
 * The words a result is made of: what happened (status), how much it matters (severity) and how
 * firmly it is held (confidence). The rest of Web Doctor reasons from these distinctions, so
 * they are asserted rather than left to whoever reads the enums next.
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
    }

    public function testEveryWordInTheVocabularyIsLabelled(): void
    {
        foreach ([...DiagnosticStatus::cases(), ...Severity::cases(), ...Confidence::cases()] as $case) {
            self::assertNotSame('', $case->label());
        }
    }
}
