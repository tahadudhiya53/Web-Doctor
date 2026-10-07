<?php

namespace Tahadudhiya\WebDoctor\services;

use Tahadudhiya\WebDoctor\base\RepairActionInterface;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\repairs\CoreRepairActions;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Every repair action Web Doctor can carry out. It holds actions; carrying one out is the repair
 * service's job, which is where every safety check lives.
 *
 * Held to the diagnostic registry's rules: an ID has a diagnostic ID's shape and is checked at
 * registration, and the first registration keeps it. An action must say what it answers, how risky
 * it is and why, and how to tell whether it worked — one that cannot is refused, because a repair
 * nobody can assess or verify is exactly the kind Web Doctor does not offer.
 */
class RepairActions extends Component
{
    /**
     * @var bool Whether the actions Web Doctor ships with are part of this registry. The plugin
     * turns this on for the registry it hands out; one built directly starts empty.
     */
    public bool $includeCoreActions = false;

    /** @var array<string, RepairActionInterface> Registered actions, keyed by ID. */
    private array $actions = [];

    private bool $loaded = false;

    /**
     * Adds an action. The first registration of an ID keeps it.
     *
     * @throws InvalidArgumentException if the action is malformed or its ID is taken.
     */
    public function register(RepairActionInterface $action): void
    {
        $id = $action->id();

        if (!Diagnostics::isValidId($id)) {
            throw new InvalidArgumentException(sprintf(
                'The repair action ID "%s" is not a valid ID. Action IDs take the shape of diagnostic IDs — dot-separated, '
                . 'at least two segments, each starting with a lowercase letter — for example "queue.retryFailedJobs".',
                $id,
            ));
        }

        if (isset($this->actions[$id])) {
            throw new InvalidArgumentException(sprintf('The repair action ID "%s" is already registered.', $id));
        }

        if (trim($action->name()) === '' || trim($action->riskReason()) === '' || trim($action->verification()) === '') {
            throw new InvalidArgumentException(sprintf('The repair action "%s" must say what it is called, why its risk is what it is, and how to tell whether it worked.', $id));
        }

        // The check that found the problem is the one whose answer resolves the issue, so it has to
        // be among the checks run again.
        if (!Diagnostics::isValidId($action->diagnosticId()) || ($action->verifyWith()[0] ?? null) !== $action->diagnosticId()) {
            throw new InvalidArgumentException(sprintf('The repair action "%s" must name the check it answers and verify with that check first.', $id));
        }

        $this->actions[$id] = $action;
    }

    /**
     * Every registered action, by ID.
     *
     * @return list<RepairActionInterface>
     */
    public function all(): array
    {
        $this->load();

        $actions = $this->actions;
        ksort($actions, SORT_STRING);

        return array_values($actions);
    }

    public function get(string $id): ?RepairActionInterface
    {
        $this->load();

        return $this->actions[$id] ?? null;
    }

    /**
     * The actions that answer one check's findings, by ID.
     *
     * @return list<RepairActionInterface>
     */
    public function forCheck(string $diagnosticId): array
    {
        return array_values(array_filter($this->all(), static fn(RepairActionInterface $a): bool => $a->diagnosticId() === $diagnosticId));
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

        foreach (CoreRepairActions::all() as $action) {
            try {
                $this->register($action);
            } catch (Throwable $e) {
                SafeException::log('A repair action Web Doctor ships with could not be registered', $e);
            }
        }
    }
}
