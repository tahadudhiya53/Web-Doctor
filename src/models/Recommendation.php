<?php

namespace Tahadudhiya\WebDoctor\models;

use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * What to do about one finding, and every reason for it: the problem, the evidence the advice rests
 * on, the likely cause where one was weighed, the action, the risk of taking it and why, what has
 * to be in place first, and how to tell afterwards whether it worked.
 *
 * Chosen by a written-out rule, never composed: the same finding always gets the same advice, and
 * the advice can be traced to the rule and the evidence that selected it. Built fresh each time it is
 * shown rather than stored, because it is advice about the finding as it stands now.
 *
 * Redacted as it is built: the problem is a check's own words, including other plugins' checks, and
 * the action of a cause is the cause's.
 */
final class Recommendation implements JsonSerializable
{
    public readonly string $problem;
    public readonly string $title;
    public readonly string $explanation;
    public readonly string $action;
    public readonly string $rationale;
    public readonly string $riskReason;
    public readonly string $verification;

    /** @var list<string> */
    public readonly array $prerequisites;

    /**
     * @param string $ruleId The rule that chose it.
     * @param string $diagnosticId The check whose finding it answers.
     * @param int|null $issueId The issue that finding is recorded on, where there is one.
     * @param string $problem The finding, in a line.
     * @param string $title The action, in a line.
     * @param string $explanation What the finding means — or, for a cause, what the cause would mean.
     * @param string $action What to do.
     * @param string $rationale Why this action, rather than another, answers the finding.
     * @param list<Observation> $basis The evidence that selected the rule.
     * @param RepairRisk $risk What could go wrong in carrying out the action.
     * @param string $riskReason Why the risk is what it is.
     * @param string $verification What shows it worked.
     * @param list<string> $verifyWith The checks to run again, the one that found the problem first.
     * @param list<string> $prerequisites What has to be in place before acting.
     * @param RootCause|null $cause The weighed cause the action answers, where it answers one.
     * @param bool $automaticRepair Whether Web Doctor can carry the action out itself. Nothing does
     * yet: every recommendation is carried out by hand.
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $diagnosticId,
        public readonly ?int $issueId,
        string $problem,
        string $title,
        string $explanation,
        string $action,
        string $rationale,
        public readonly array $basis,
        public readonly RepairRisk $risk,
        string $riskReason,
        string $verification,
        public readonly array $verifyWith,
        array $prerequisites,
        public readonly ?RootCause $cause = null,
        public readonly bool $automaticRepair = false,
    ) {
        $this->problem = Redaction::redactString($problem);
        $this->title = Redaction::redactString($title);
        $this->explanation = Redaction::redactString($explanation);
        $this->action = Redaction::redactString($action);
        $this->rationale = Redaction::redactString($rationale);
        $this->riskReason = Redaction::redactString($riskReason);
        $this->verification = Redaction::redactString($verification);
        $this->prerequisites = array_map(static fn(string $line): string => Redaction::redactString($line), $prerequisites);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'diagnosticId' => $this->diagnosticId,
            'issueId' => $this->issueId,
            'problem' => $this->problem,
            'title' => $this->title,
            'explanation' => $this->explanation,
            'action' => $this->action,
            'rationale' => $this->rationale,
            'basis' => array_map(static fn(Observation $o): array => $o->jsonSerialize(), $this->basis),
            'risk' => $this->risk->value,
            'riskReason' => $this->riskReason,
            'verification' => $this->verification,
            'verifyWith' => $this->verifyWith,
            'prerequisites' => $this->prerequisites,
            'cause' => $this->cause === null ? null : [
                'ruleId' => $this->cause->ruleId,
                'title' => $this->cause->title,
                'confidence' => $this->cause->confidence->value,
                'investigationId' => $this->cause->investigationId,
            ],
            'automaticRepair' => $this->automaticRepair,
        ];
    }
}
