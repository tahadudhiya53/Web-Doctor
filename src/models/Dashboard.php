<?php

namespace Tahadudhiya\WebDoctor\models;

use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\helpers\DiagnosticMeta;

/**
 * What the health dashboard shows: the checks that exist now, reconciled with the last run there
 * was.
 *
 * The two disagree in ordinary ways — a check added since the run has no result, a check from an
 * uninstalled plugin has a result and no longer exists — and each of those is stated rather than
 * hidden. Two judgements follow, both about not letting the past speak for the present.
 *
 * {@see self::health()} is computed from the results of checks registered *now*. A result from a
 * check that has since been uninstalled stays visible, because losing a finding silently is how a
 * real problem stops being anybody's problem, but it no longer describes this installation:
 * letting a departed plugin's failure hold the score down would mean the only way to answer the
 * dashboard is to reinstall the thing that was removed.
 *
 * {@see self::describesHealth()} is the second. Scoring three checks out of eighteen and calling
 * the answer 100 would put the most trusted number on the page behind the least earned
 * arithmetic, so a partial run is shown as a partial run, with its results and without a score.
 */
final class Dashboard
{
    /** @var HealthSummary|null What the currently registered results add up to, computed once. */
    private ?HealthSummary $health = null;

    /**
     * @param list<array{id: string, name: string, category: DiagnosticCategory, result: DiagnosticResult|null, registered: bool}> $rows
     * The checks registered now, in the registry's order.
     * @param list<array{id: string, name: string, category: DiagnosticCategory, result: DiagnosticResult, registered: bool}> $staleRows
     * Results from checks that are no longer registered, in the order the run reported them.
     * @param int $coveredCount How many registered checks the run reached.
     */
    private function __construct(
        public readonly ?DiagnosticRun $run,
        public readonly array $rows,
        public readonly array $staleRows,
        public readonly int $coveredCount,
    ) {
    }

    /**
     * @param iterable<DiagnosticInterface> $diagnostics Every registered check. The registry sorts
     * by category and then ID, so the dashboard inherits a stable order rather than inventing one.
     */
    public static function build(iterable $diagnostics, ?DiagnosticRun $run): self
    {
        $rows = [];
        $stale = [];
        $seen = [];
        $covered = 0;

        foreach ($diagnostics as $diagnostic) {
            $id = DiagnosticMeta::id($diagnostic);
            $result = $run?->resultFor($id);

            $seen[$id] = true;

            if ($result !== null) {
                $covered++;
            }

            $rows[] = [
                'id' => $id,
                // The run's own name for a check is preferred: it is what the check called
                // itself when it ran, which is what the rest of the row describes.
                'name' => $result->name ?? DiagnosticMeta::name($diagnostic, $id),
                'category' => $result->category ?? DiagnosticMeta::category($diagnostic),
                'result' => $result,
                'registered' => true,
            ];
        }

        // Results from checks that are no longer registered are kept rather than dropped, but
        // held apart so nothing downstream can mistake them for the state of this installation.
        foreach ($run?->results() ?? [] as $result) {
            if (!isset($seen[$result->diagnosticId])) {
                $stale[] = [
                    'id' => $result->diagnosticId,
                    'name' => $result->name,
                    'category' => $result->category,
                    'result' => $result,
                    'registered' => false,
                ];
            }
        }

        return new self(run: $run, rows: $rows, staleRows: $stale, coveredCount: $covered);
    }

    public function hasDiagnostics(): bool
    {
        return $this->rows !== [];
    }

    public function hasRun(): bool
    {
        return $this->run !== null;
    }

    public function registeredCount(): int
    {
        return count($this->rows);
    }

    public function hasStaleResults(): bool
    {
        return $this->staleRows !== [];
    }

    public function staleCount(): int
    {
        return count($this->staleRows);
    }

    /**
     * Whether the run covered every check registered now. Stale results do not count toward
     * coverage: a run that reached two of three current checks is partial however many departed
     * plugins it also has answers for.
     */
    public function isComplete(): bool
    {
        return $this->run !== null && $this->coveredCount === $this->registeredCount();
    }

    /**
     * Whether a health score for this installation can honestly be derived from what is here.
     */
    public function describesHealth(): bool
    {
        return $this->isComplete() && $this->rows !== [];
    }

    /**
     * What this installation's currently registered checks add up to, or nothing where there was
     * no run. Whether it may be presented as a score is {@see self::describesHealth()}'s answer.
     */
    public function health(): ?HealthSummary
    {
        if ($this->run === null) {
            return null;
        }

        return $this->health ??= HealthSummary::fromResults($this->currentResults());
    }

    /**
     * The results of checks that are registered now and were reached by the run.
     *
     * @return list<DiagnosticResult>
     */
    public function currentResults(): array
    {
        $results = [];

        foreach ($this->rows as $row) {
            if ($row['result'] !== null) {
                $results[] = $row['result'];
            }
        }

        return $results;
    }

    /**
     * The registered checks grouped for display, in the registry's order.
     *
     * Stale results are not here. They are their own section, because a reader scanning the
     * Database group has to be able to take what is in it as the state of the database.
     *
     * @return list<array{category: DiagnosticCategory, rows: list<array{id: string, name: string, category: DiagnosticCategory, result: DiagnosticResult|null, registered: bool}>}>
     */
    public function byCategory(): array
    {
        $groups = [];

        foreach ($this->rows as $row) {
            $key = $row['category']->value;
            $groups[$key] ??= ['category' => $row['category'], 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }

        return array_values($groups);
    }
}
