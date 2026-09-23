<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use craft\queue\Queue;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueBacklogDiagnostic;

/**
 * The backlog check reading a queue the test states rather than one it has to fill.
 */
class StubbedQueueBacklogDiagnostic extends QueueBacklogDiagnostic
{
    public int $waiting = 0;
    public int $delayed = 0;
    public int $reserved = 0;

    /** @var bool Whether reading the queue throws, as it does when the database is gone. */
    public bool $unreadable = false;

    /** @var int|null How long ago the oldest waiting job became ready to run, in seconds. */
    public ?int $waitingForSeconds = null;

    /** @var array{seconds: int, ttr: int, overrunning: bool}|null The longest-running job, if any. */
    public ?array $running = null;

    protected function counts(Queue $queue): array
    {
        if ($this->unreadable) {
            throw new RuntimeException('The queue could not be read.');
        }

        return ['waiting' => $this->waiting, 'delayed' => $this->delayed, 'reserved' => $this->reserved];
    }

    protected function oldestWaitingSince(Queue $queue): ?int
    {
        return $this->waitingForSeconds === null ? null : time() - $this->waitingForSeconds;
    }

    protected function longestRunning(Queue $queue): ?array
    {
        return $this->running;
    }

    protected function runsQueueAutomatically(): bool
    {
        return true;
    }
}
