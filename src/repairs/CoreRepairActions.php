<?php

namespace Tahadudhiya\WebDoctor\repairs;

use Tahadudhiya\WebDoctor\base\RepairActionInterface;

/**
 * The single list of the repair actions Web Doctor ships with.
 *
 * Only actions that are safe to carry out from a web request through Craft's own API, that change
 * exactly what their preview names, and whose result the check that found the problem can verify.
 * Applying project config, running migrations, changing file permissions and deleting anything are
 * deliberately not here: each can remove data, replace configuration made elsewhere or reach beyond
 * the installation, and Craft's own tools for them already show what they will do.
 */
final class CoreRepairActions
{
    /**
     * @return list<RepairActionInterface>
     */
    public static function all(): array
    {
        return [
            new CreateStorageDirectories(),
            new RetryFailedJobs(),
        ];
    }
}
