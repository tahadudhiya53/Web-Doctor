<?php

namespace Tahadudhiya\WebDoctor\base;

use Tahadudhiya\WebDoctor\models\RepairContext;
use yii\base\Component;

/**
 * The starting point for writing a repair action. Supplies the parts most actions answer the same
 * way; an action states its identity as a class constant, as a diagnostic does.
 *
 * Extending Yii's Component lets an action be configured through Craft's container, so one that
 * reads a service can be given another — which is how a test states the installation's condition
 * rather than putting a site into it.
 */
abstract class RepairAction extends Component implements RepairActionInterface
{
    /** @var string This action's permanent identity. The registry refuses one that has none. */
    public const ID = '';

    public function id(): string
    {
        return static::ID;
    }

    public function recommendation(): ?string
    {
        return null;
    }

    /** Craft keeps no permission of its own for most actions; one that does says so. */
    public function isAuthorized(): bool
    {
        return true;
    }

    public function authorization(): string
    {
        return '';
    }

    public function prerequisites(RepairContext $context): array
    {
        return [];
    }
}
