<?php

namespace Tahadudhiya\WebDoctor\services;

use Tahadudhiya\WebDoctor\base\VerificationActionInterface;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\verifications\CoreVerificationActions;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Every verification action Web Doctor knows: what a particular repair should have left true. It
 * holds them; verifying a repair is the verification service's job.
 *
 * Held to the repair registry's rules: an ID has a diagnostic ID's shape, the repair action it
 * verifies is named in the same shape, and the first registration of an ID keeps it. One action
 * per repair action, and the first keeps that too: two that disagreed about what a repair should
 * have left true would make its verification depend on which was asked.
 *
 * An action that fails to register leaves its repair action with none, and a repair with none is
 * never verified — its verification says so and is inconclusive.
 */
class VerificationActions extends Component
{
    /**
     * @var bool Whether the actions Web Doctor ships with are part of this registry. The plugin
     * turns this on for the registry it hands out; one built directly starts empty.
     */
    public bool $includeCoreActions = false;

    /** @var array<string, VerificationActionInterface> Registered actions, keyed by ID. */
    private array $actions = [];

    private bool $loaded = false;

    /**
     * Adds an action. The first registration of an ID keeps it.
     *
     * @throws InvalidArgumentException if the action is malformed or its ID is taken.
     */
    public function register(VerificationActionInterface $action): void
    {
        $id = $action->id();

        if (!Diagnostics::isValidId($id)) {
            throw new InvalidArgumentException(sprintf(
                'The verification action ID "%s" is not a valid ID. Action IDs take the shape of diagnostic IDs — dot-separated, '
                . 'at least two segments, each starting with a lowercase letter — for example "queue.retriedJobsSettled".',
                $id,
            ));
        }

        if (isset($this->actions[$id])) {
            throw new InvalidArgumentException(sprintf('The verification action ID "%s" is already registered.', $id));
        }

        if (trim($action->name()) === '' || !Diagnostics::isValidId($action->repairAction())) {
            throw new InvalidArgumentException(sprintf('The verification action "%s" must say what it checks and name the repair action it verifies.', $id));
        }

        foreach ($this->actions as $registered) {
            if ($registered->repairAction() === $action->repairAction()) {
                throw new InvalidArgumentException(sprintf('The repair action "%s" already has a verification action, "%s".', $action->repairAction(), $registered->id()));
            }
        }

        $this->actions[$id] = $action;
    }

    /**
     * Every registered action, by ID.
     *
     * @return list<VerificationActionInterface>
     */
    public function all(): array
    {
        $this->load();

        $actions = $this->actions;
        ksort($actions, SORT_STRING);

        return array_values($actions);
    }

    /**
     * The actions that verify one repair action's repairs, by ID.
     *
     * @return list<VerificationActionInterface>
     */
    public function forRepairAction(string $repairAction): array
    {
        return array_values(array_filter($this->all(), static fn(VerificationActionInterface $a): bool => $a->repairAction() === $repairAction));
    }

    /**
     * The actions Web Doctor ships with. A seam, so a test can make one fail to register and prove
     * its repairs are then never verified.
     *
     * @return list<VerificationActionInterface>
     */
    protected function core(): array
    {
        return CoreVerificationActions::all();
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!$this->includeCoreActions) {
            return;
        }

        foreach ($this->core() as $action) {
            try {
                $this->register($action);
            } catch (Throwable $e) {
                SafeException::log('A verification action Web Doctor ships with could not be registered', $e);
            }
        }
    }
}
