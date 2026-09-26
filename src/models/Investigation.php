<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\InvestigationStatus;
use Tahadudhiya\WebDoctor\records\InvestigationRecord;
use Throwable;

/**
 * One investigation of one issue, as it stands: what it set out to look at and why, how far it
 * got, and what it found, counted. What happened along the way is its {@see InvestigationStep}s.
 *
 * An investigation records what was observed. The causes it weighs from that are kept apart, as
 * {@see RootCause}s, each with the evidence for and against it: which of the things it found
 * explains the problem is a judgement the evidence has to support, and this record makes none.
 */
final class Investigation implements JsonSerializable
{
    /** @var string The check that raised the issue still reports it. */
    public const ORIGIN_PRESENT = 'present';

    /** @var string It ran, reached a conclusion, and the conclusion was not the problem. */
    public const ORIGIN_CLEAR = 'clear';

    /** @var string It ran and could not tell, broke, or did not apply. */
    public const ORIGIN_INCONCLUSIVE = 'inconclusive';

    /** @var string It is not installed here any more, so it could not be asked. */
    public const ORIGIN_UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly int $id,
        public readonly int $issueId,
        public readonly ?string $runId,
        public readonly InvestigationStatus $status,
        public readonly DiagnosticDepth $depth,
        public readonly InvestigationPlan $plan,
        public readonly string $environment,
        public readonly ?int $siteId,
        public readonly ?int $startedBy,
        public readonly int $checksPlanned,
        public readonly int $checksRun,
        public readonly int $checksWithProblems,
        public readonly int $checksIncomplete,
        public readonly int $checksSkipped,
        public readonly int $evidenceCount,
        public readonly int $relatedIssues,
        public readonly ?DiagnosticStatus $originStatus,
        public readonly ?string $failure,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly ?float $durationMs,
    ) {
    }

    /**
     * Reads a stored row. A status this version does not have reads as partly completed rather
     * than completed: not knowing how an investigation went is not a reason to call it whole.
     */
    public static function fromRecord(InvestigationRecord $record): self
    {
        $plan = null;

        if (is_string($record->plan) && $record->plan !== '') {
            $decoded = json_decode($record->plan, true);
            $plan = is_array($decoded) ? InvestigationPlan::fromArray($decoded) : null;
        }

        $depth = DiagnosticDepth::tryFrom((string)$record->depth) ?? DiagnosticDepth::NORMAL;

        return new self(
            id: (int)$record->id,
            issueId: (int)$record->issueId,
            runId: $record->runId,
            status: InvestigationStatus::tryFrom((string)$record->status) ?? InvestigationStatus::PARTIAL,
            depth: $depth,
            plan: $plan ?? new InvestigationPlan(ruleId: (string)$record->ruleId, ruleLabel: '', depth: $depth, checks: []),
            environment: (string)$record->environment,
            siteId: $record->siteId === null ? null : (int)$record->siteId,
            startedBy: $record->startedBy === null ? null : (int)$record->startedBy,
            checksPlanned: (int)$record->checksPlanned,
            checksRun: (int)$record->checksRun,
            checksWithProblems: (int)$record->checksWithProblems,
            checksIncomplete: (int)$record->checksIncomplete,
            checksSkipped: (int)$record->checksSkipped,
            evidenceCount: (int)$record->evidenceCount,
            relatedIssues: (int)$record->relatedIssues,
            originStatus: $record->originStatus === null ? null : DiagnosticStatus::tryFrom((string)$record->originStatus),
            failure: $record->failure,
            startedAt: self::time($record->startedAt) ?? new DateTimeImmutable(),
            finishedAt: self::time($record->finishedAt),
            durationMs: $record->durationMs === null ? null : (float)$record->durationMs,
        );
    }

    /**
     * What the check that raised the issue said this time, reduced to the four answers that
     * matter to a reader. Null while it is running or when it never got that far.
     */
    public function originOutcome(): ?string
    {
        if ($this->originStatus !== null) {
            return match (true) {
                $this->originStatus->isProblem() => self::ORIGIN_PRESENT,
                $this->originStatus->isConclusive() => self::ORIGIN_CLEAR,
                default => self::ORIGIN_INCONCLUSIVE,
            };
        }

        if ($this->status->isFinished() && $this->status !== InvestigationStatus::FAILED && !$this->plan->includesOrigin()) {
            return self::ORIGIN_UNAVAILABLE;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'issueId' => $this->issueId,
            'runId' => $this->runId,
            'status' => $this->status->value,
            'depth' => $this->depth->value,
            'plan' => $this->plan->jsonSerialize(),
            'environment' => $this->environment,
            'siteId' => $this->siteId,
            'checksPlanned' => $this->checksPlanned,
            'checksRun' => $this->checksRun,
            'checksWithProblems' => $this->checksWithProblems,
            'checksIncomplete' => $this->checksIncomplete,
            'checksSkipped' => $this->checksSkipped,
            'evidenceCount' => $this->evidenceCount,
            'relatedIssues' => $this->relatedIssues,
            'originStatus' => $this->originStatus?->value,
            'originOutcome' => $this->originOutcome(),
            'failure' => $this->failure,
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'finishedAt' => $this->finishedAt?->format(DATE_ATOM),
            'durationMs' => $this->durationMs,
        ];
    }

    private static function time(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Stored the way Craft stores every date: UTC.
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
