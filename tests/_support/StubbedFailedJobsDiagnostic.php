<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use craft\queue\Queue;
use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\queue\FailedJobsDiagnostic;

/**
 * The failed-jobs check reading failures the test states rather than failures a site has.
 */
class StubbedFailedJobsDiagnostic extends FailedJobsDiagnostic
{
    public int $failed = 0;

    /** @var bool Whether reading the queue throws, as it does when the database is gone. */
    public bool $unreadable = false;

    /** @var list<array{description: string, occurrences: int, firstFailed: int|null, lastFailed: int|null, error: string}> */
    public array $failures = [];

    protected function totalFailed(Queue $queue): int
    {
        if ($this->unreadable) {
            throw new RuntimeException('The queue could not be read.');
        }

        return $this->failed;
    }

    protected function groupFailures(Queue $queue, int $limit): array
    {
        return array_slice($this->failures, 0, $limit);
    }
}
