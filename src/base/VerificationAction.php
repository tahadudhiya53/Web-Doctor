<?php

namespace Tahadudhiya\WebDoctor\base;

use Tahadudhiya\WebDoctor\models\Repair;
use yii\base\Component;

/**
 * The starting point for writing a verification action. An action states its identity as a class
 * constant, as a repair action does, and extends Yii's Component for the same reason: one that
 * reads a service can be given another, which is how a test states the installation's condition.
 */
abstract class VerificationAction extends Component implements VerificationActionInterface
{
    /** @var string This action's permanent identity. The registry refuses one that has none. */
    public const ID = '';

    public function id(): string
    {
        return static::ID;
    }

    /**
     * One value the repair recorded about its own outcome, from the state it recorded under its own
     * ID. Null where the repair recorded no outcome or not that value.
     */
    protected function recorded(Repair $repair, string $key): mixed
    {
        return $this->from($repair->outcome->state ?? [], $repair->action, $key);
    }

    /**
     * The same, from the state the repair's preview read before it ran.
     */
    protected function recordedBefore(Repair $repair, string $key): mixed
    {
        return $this->from($repair->preview->state, $repair->action, $key);
    }

    /**
     * @param list<\Tahadudhiya\WebDoctor\models\Evidence> $state
     */
    private function from(array $state, string $source, string $key): mixed
    {
        foreach ($state as $evidence) {
            if ($evidence->source === $source) {
                return $evidence->get($key);
            }
        }

        return null;
    }
}
