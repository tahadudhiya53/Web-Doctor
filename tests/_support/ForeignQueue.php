<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use craft\queue\QueueInterface;
use yii\queue\Queue as BaseQueue;

/**
 * A queue that is not Craft's own database-backed one.
 *
 * Sites are free to point Craft's queue at Redis, at SQS or at anything else implementing the
 * interface. Web Doctor's queue checks read counts only Craft's own queue keeps, so what they
 * have to do with one of these is decline to run — and this is what lets that be shown without
 * a test having to configure a real alternative.
 */
class ForeignQueue extends BaseQueue implements QueueInterface
{
    public function run(): mixed
    {
        return null;
    }

    public function retry(string $id): void
    {
    }

    public function retryAll(): void
    {
    }

    public function release(string $id): void
    {
    }

    public function releaseAll(): void
    {
    }

    public function setProgress(int $progress, ?string $label = null): void
    {
    }

    public function getHasWaitingJobs(): bool
    {
        return false;
    }

    public function getHasReservedJobs(): bool
    {
        return false;
    }

    public function getTotalJobs(): int
    {
        return 0;
    }

    public function getJobInfo(?int $limit = null): array
    {
        return [];
    }

    public function getJobDetails(string $id): array
    {
        return [];
    }

    public function status($id): int
    {
        return self::STATUS_DONE;
    }

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        return '0';
    }
}
