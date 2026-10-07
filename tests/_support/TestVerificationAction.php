<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use RuntimeException;
use Tahadudhiya\WebDoctor\base\VerificationAction;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\VerificationCondition;

/**
 * A verification action whose answer the test states: its one condition's state, or that it throws,
 * or that it returns something other than conditions.
 */
class TestVerificationAction extends VerificationAction
{
    public string $actionId = 'test.verified';
    public string $verifies = 'test.repair';
    public string $state = VerificationCondition::HELD;
    public bool $throws = false;

    /** @var mixed What it returns instead of its condition, where the test says: a contributed action may return anything. */
    public mixed $returns = null;

    public function id(): string
    {
        return $this->actionId;
    }

    public function name(): string
    {
        return 'The target stayed changed';
    }

    public function repairAction(): string
    {
        return $this->verifies;
    }

    public function conditions(Repair $repair): array
    {
        if ($this->throws) {
            throw new RuntimeException('The verifier broke.');
        }

        if ($this->returns !== null) {
            return $this->returns;
        }

        return [ match ($this->state) {
            VerificationCondition::HELD => VerificationCondition::held('targetChanged', 'The target stayed changed.'),
            VerificationCondition::NOT_HELD => VerificationCondition::notHeld('targetChanged', 'The target stayed changed.', 'It changed back.'),
            default => VerificationCondition::undetermined('targetChanged', 'The target stayed changed.'),
        }];
    }
}
