<?php

namespace Tahadudhiya\WebDoctor\models;

use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\Severity;

/**
 * What a set of results adds up to.
 *
 * The score exists only because it is shown alongside the arithmetic that produced it. Every
 * penalty is listed against the result that caused it and the weight that was applied, so a
 * reader can always answer "why is it 72?" without being asked to trust the number. A score
 * nobody can take apart would be worse than no score at all.
 *
 * Results that could not reach a conclusion — a diagnostic that errored, one that could not
 * tell — count against health, because an unanswered question is not a clean bill of health.
 * Skipped checks do not: they did not apply.
 */
final class HealthSummary implements JsonSerializable
{
    /** The score a run with nothing against it earns. */
    public const MAX_SCORE = 100;

    /**
     * @param array<string, int> $counts How many results of each status, keyed by status value.
     * @param array<string, int> $severityCounts How many of the results that count toward health
     * carried each severity, keyed by severity value. Results that do not count toward health are
     * not in here at all: a passing check has a severity, but it is not an issue of that severity,
     * and counting it as one would put items in the "critical" column that nobody needs to act on.
     * @param list<array{diagnosticId: string, status: string, severity: string, penalty: int}> $contributions
     * Every result that moved the score, and by how much.
     */
    private function __construct(
        public readonly int $score,
        public readonly int $total,
        public readonly array $counts,
        public readonly array $severityCounts,
        public readonly ?Severity $worstSeverity,
        public readonly array $contributions,
    ) {
    }

    /**
     * What each severity costs the score. Stated here, in one place, and published through
     * {@see self::weights()} so the control panel and reports can show the same table a reader
     * would need to check the arithmetic.
     *
     * @return array<string, int>
     */
    public static function weights(): array
    {
        return [
            Severity::INFO->value => 0,
            Severity::LOW->value => 2,
            Severity::MEDIUM->value => 6,
            Severity::HIGH->value => 15,
            Severity::CRITICAL->value => 30,
        ];
    }

    /**
     * @param DiagnosticResult[] $results
     */
    public static function fromResults(array $results): self
    {
        $weights = self::weights();
        $counts = array_fill_keys(DiagnosticStatus::values(), 0);
        $severityCounts = array_fill_keys(Severity::values(), 0);
        $contributions = [];
        $severities = [];
        $penalty = 0;

        foreach ($results as $result) {
            $counts[$result->status->value]++;

            if (!$result->status->countsTowardHealth()) {
                continue;
            }

            $severity = $result->severity();
            $severities[] = $severity;
            $severityCounts[$severity->value]++;
            $cost = $weights[$severity->value] ?? 0;
            $penalty += $cost;

            $contributions[] = [
                'diagnosticId' => $result->diagnosticId,
                'status' => $result->status->value,
                'severity' => $severity->value,
                'penalty' => $cost,
            ];
        }

        return new self(
            score: max(0, self::MAX_SCORE - $penalty),
            total: count($results),
            counts: $counts,
            severityCounts: $severityCounts,
            worstSeverity: Severity::highest($severities),
            contributions: $contributions,
        );
    }

    public function countOf(DiagnosticStatus $status): int
    {
        return $this->counts[$status->value] ?? 0;
    }

    /**
     * How many results needing attention carried this severity.
     */
    public function countOfSeverity(Severity $severity): int
    {
        return $this->severityCounts[$severity->value] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'score' => $this->score,
            'maxScore' => self::MAX_SCORE,
            'total' => $this->total,
            'counts' => $this->counts,
            'severityCounts' => $this->severityCounts,
            'worstSeverity' => $this->worstSeverity?->value,
            'weights' => self::weights(),
            'contributions' => $this->contributions,
        ];
    }
}
