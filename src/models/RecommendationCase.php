<?php

namespace Tahadudhiya\WebDoctor\models;

use Closure;
use Craft;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * One finding a recommendation is chosen for: what a check reported, the evidence it left, the
 * issue it is recorded on, and the causes an investigation weighed for that issue.
 *
 * The same shape whether the finding is a result on the dashboard, an issue, or a check an
 * investigation ran — so a finding gets the same recommendation wherever it is read. Recommendation
 * rules read the finding through the finders here and nothing else.
 */
final class RecommendationCase
{
    public readonly string $name;
    public readonly string $problem;

    /** @var list<Evidence> */
    public readonly array $evidence;

    /** @var list<RootCause> Most firmly held first. */
    public readonly array $causes;

    /**
     * @param string $diagnosticId The check that reported it.
     * @param string $name The check's name, as a reader sees it.
     * @param string $problem The finding, in a line.
     * @param iterable<Evidence> $evidence What the check recorded.
     * @param int|null $issueId The issue the finding is recorded on, where there is one.
     * @param iterable<RootCause> $causes The causes weighed for that issue.
     * @param bool $evidenceComplete Whether `$evidence` is all the check recorded. A finding rule
     * chooses by what the evidence holds, so choosing from part of it can give a lower-precedence
     * rule's advice — or none — for a finding a rule does cover.
     */
    public function __construct(
        public readonly string $diagnosticId,
        string $name,
        public readonly DiagnosticStatus $status,
        public readonly Severity $severity,
        string $problem,
        iterable $evidence = [],
        public readonly ?int $issueId = null,
        iterable $causes = [],
        public readonly bool $evidenceComplete = true,
    ) {
        $this->name = Redaction::redactString($name !== '' ? $name : $diagnosticId);
        $this->problem = Redaction::redactString($problem);
        $this->evidence = array_values([...$evidence]);

        $causes = array_values([...$causes]);
        usort($causes, static fn(RootCause $a, RootCause $b): int => [$a->position, $a->ruleId] <=> [$b->position, $b->ruleId]);
        $this->causes = $causes;
    }

    /**
     * @param iterable<RootCause> $causes
     */
    public static function fromResult(DiagnosticResult $result, ?int $issueId = null, iterable $causes = []): self
    {
        return new self(
            diagnosticId: $result->diagnosticId,
            name: $result->name,
            status: $result->status,
            severity: $result->severity(),
            problem: $result->summary,
            evidence: $result->evidence(),
            issueId: $issueId,
            causes: $causes,
        );
    }

    /**
     * @param iterable<Evidence> $evidence The evidence the issue's latest finding left.
     * @param iterable<RootCause> $causes
     */
    public static function fromIssue(Issue $issue, iterable $evidence, iterable $causes = []): self
    {
        return new self(
            diagnosticId: $issue->diagnosticId,
            name: $issue->diagnosticName,
            status: $issue->resultStatus,
            severity: $issue->severity,
            problem: $issue->title,
            evidence: $evidence,
            issueId: $issue->id,
            causes: $causes,
        );
    }

    /**
     * A check an investigation ran, from the evidence its step kept. Null for a step that is not a
     * check's.
     *
     * @param iterable<RootCause> $causes
     */
    public static function fromStep(InvestigationStep $step, iterable $causes = []): ?self
    {
        if ($step->diagnosticId === null || $step->status === null) {
            return null;
        }

        return new self(
            diagnosticId: $step->diagnosticId,
            name: $step->diagnosticName ?? $step->diagnosticId,
            status: $step->status,
            severity: $step->severity ?? $step->status->defaultSeverity(),
            problem: (string)$step->summary,
            evidence: $step->evidence,
            issueId: $step->relatedIssueId,
            causes: $causes,
            // An investigation keeps a bounded amount of evidence across all its checks, so a
            // step can hold less than its check recorded.
            evidenceComplete: !$step->evidenceTruncated,
        );
    }

    /** Whether there is anything to recommend: only a finding about the site is acted on. */
    public function isFinding(): bool
    {
        return $this->status->isProblem();
    }

    /**
     * The evidence of one type.
     *
     * @return list<Evidence>
     */
    public function evidenceOf(EvidenceType $type): array
    {
        return array_values(array_filter($this->evidence, static fn(Evidence $e): bool => $e->type === $type));
    }

    /**
     * The evidence of one type that meets a test, each observed quoting the values named.
     *
     * @param Closure(Evidence): bool $test
     * @param list<string> $keys
     * @return list<Observation>
     */
    public function where(EvidenceType $type, Closure $test, array $keys = []): array
    {
        $out = [];

        foreach ($this->evidenceOf($type) as $evidence) {
            if ($test($evidence)) {
                $out[] = $this->observe($evidence, $keys);
            }
        }

        return $out;
    }

    /**
     * The finding itself, as a fact a recommendation rests on.
     */
    public function observeFinding(): Observation
    {
        return new Observation(
            kind: Observation::RESULT,
            label: $this->problem !== ''
                ? sprintf('%s: %s', $this->name, $this->problem)
                : sprintf('%s: %s', $this->name, $this->status->label()),
            diagnosticId: $this->diagnosticId,
            issueId: $this->issueId,
        );
    }

    /**
     * A piece of evidence, quoting only the values the rule looked at.
     *
     * @param list<string> $keys
     */
    public function observe(Evidence $evidence, array $keys = []): Observation
    {
        $quoted = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $evidence->data)) {
                $quoted[] = sprintf('%s: %s', $key, Evidence::describe($evidence->data[$key]));
            }
        }

        return new Observation(
            kind: Observation::EVIDENCE,
            label: Craft::t('web-doctor', '{label}, recorded by {check}', [
                'label' => $evidence->label !== '' ? $evidence->label : $evidence->type->label(),
                'check' => $this->name,
            ]),
            detail: $quoted === [] ? null : implode('; ', $quoted),
            diagnosticId: $this->diagnosticId,
            issueId: $this->issueId,
            evidenceDigest: $evidence->digest(),
            at: $evidence->observedAt ?? $evidence->recordedAt,
        );
    }
}
