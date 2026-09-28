<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Closure;
use Tahadudhiya\WebDoctor\base\RepairAction;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Prerequisite;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RepairContext;
use Tahadudhiya\WebDoctor\models\RepairReport;

/**
 * A repair action whose every answer is stated by the test: what it acts on, whether its
 * prerequisite holds, how risky it is, and what happens when it runs. Written the way a real action
 * is — it reads its target again as it acts and refuses unless it matches the preview — so what is
 * exercised is the service's safety model, not a shortcut around it.
 */
class TestRepairAction extends RepairAction
{
    public string $actionId = 'test.repair';
    public string $check = 'test.check';
    public RepairRisk $riskLevel = RepairRisk::LOW;
    public bool $authorized = true;
    public bool $applicable = true;
    public bool $prerequisiteMet = true;
    public bool $needsAcknowledgement = false;
    public bool $fingerprinted = true;

    /** What it says about itself: each can be changed to show a changed definition is caught. */
    public string $label = 'Test repair';
    public string $reason = 'Because the test says so.';
    public string $verificationText = 'The check no longer reports the problem.';
    public ?string $recommendationId = null;
    public string $checkedId = 'targetReadable';
    public string $checkedDescription = 'The target can be read.';
    public string $ackId = 'backupTaken';
    public string $ackDescription = 'A backup of the target exists.';

    /** @var bool Whether the acknowledged prerequisite is instead one the action checks itself. */
    public bool $ackAsChecked = false;

    /** @var list<string>|null The checks it says to verify with; its own check alone unless stated. */
    public ?array $verifying = null;

    /** @var mixed What the action would act on; changing it changes the preview's fingerprint. */
    public mixed $target = ['item' => 1];

    /** @var (Closure(RepairContext): void)|null Runs as the action carries itself out; may throw. */
    public ?Closure $onExecute = null;

    /** @var (Closure(): void)|null Runs as the action reads what it would do; may throw. */
    public ?Closure $onPreview = null;

    /** @var int How many times it got as far as carrying itself out. */
    public int $executions = 0;

    public function id(): string
    {
        return $this->actionId;
    }

    public function name(): string
    {
        return $this->label;
    }

    public function recommendation(): ?string
    {
        return $this->recommendationId;
    }

    public function description(): string
    {
        return 'Changes the target the test states.';
    }

    public function diagnosticId(): string
    {
        return $this->check;
    }

    public function risk(): RepairRisk
    {
        return $this->riskLevel;
    }

    public function riskReason(): string
    {
        return $this->reason;
    }

    public function isApplicable(RecommendationCase $finding): bool
    {
        return $this->applicable && $finding->diagnosticId === $this->check;
    }

    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    public function authorization(): string
    {
        return 'Craft would not let you do this yourself.';
    }

    public function prerequisites(RepairContext $context): array
    {
        $prerequisites = [Prerequisite::checked($this->checkedId, $this->checkedDescription, $this->prerequisiteMet, $this->prerequisiteMet ? null : 'It could not.')];

        if ($this->needsAcknowledgement) {
            $prerequisites[] = $this->ackAsChecked
                ? Prerequisite::checked($this->ackId, $this->ackDescription, true)
                : Prerequisite::acknowledged($this->ackId, $this->ackDescription);
        }

        return $prerequisites;
    }

    public function preview(RepairContext $context): RepairReport
    {
        if ($this->onPreview !== null) {
            ($this->onPreview)();
        }

        return new RepairReport(
            'Would change the target.',
            ['Change the target'],
            [new Evidence(EvidenceType::CONFIGURATION, 'Target before', $this->actionId, ['target' => $this->target])],
            $this->fingerprinted ? RepairReport::fingerprintOf($this->target) : null,
        );
    }

    public function execute(RepairContext $context, RepairReport $preview): RepairReport
    {
        if (!hash_equals((string)$preview->fingerprint, RepairReport::fingerprintOf($this->target))) {
            throw new Refusal('The target changed just before it was acted on.');
        }

        $this->executions++;

        if ($this->onExecute !== null) {
            ($this->onExecute)($context);
        }

        return new RepairReport(
            'Changed the target.',
            ['Changed the target'],
            [new Evidence(EvidenceType::CONFIGURATION, 'Target after', $this->actionId, ['target' => 'changed'])],
        );
    }

    public function verifyWith(): array
    {
        return $this->verifying ?? [$this->check];
    }

    public function verification(): string
    {
        return $this->verificationText;
    }
}
