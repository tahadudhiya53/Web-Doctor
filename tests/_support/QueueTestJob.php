<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use craft\queue\BaseJob;
use RuntimeException;

/**
 * A queue job that does nothing, or fails, as the test says — so a retried job can be really run by
 * Craft's queue, and its ending be the queue's own.
 */
class QueueTestJob extends BaseJob
{
    public bool $fails = false;

    public function execute($queue): void
    {
        if ($this->fails) {
            throw new RuntimeException('The test job failed as it was told to.');
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'A test job';
    }
}
