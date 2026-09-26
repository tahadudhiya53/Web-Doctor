<?php

namespace Tahadudhiya\WebDoctor\diagnostics\queue;

use Craft;
use craft\db\Query;
use craft\queue\Queue;
use craft\queue\QueueInterface;
use RuntimeException;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Throwable;

/**
 * What the queue checks share: which queue they look at, and how they read its table.
 *
 * Both read the table directly rather than through `Queue`'s own counters, because every one of
 * those calls `_moveExpired()` first, which *writes* — it releases jobs whose worker died so they
 * will be retried. That is housekeeping for Craft to do, not something a health check should
 * cause. Reading directly means the scoping Craft applies has to be reproduced exactly, and it is
 * reproduced here once so the two checks cannot drift into reading different sets of rows.
 */
abstract class QueueDiagnostic extends Diagnostic
{
    /**
     * @var QueueInterface|null The queue to inspect. Craft's own unless a caller supplies
     * another, which is how a site running a different queue driver is exercised without one
     * having to be configured.
     */
    public ?QueueInterface $queue = null;

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::QUEUE;
    }

    /**
     * Only Craft's own database-backed queue keeps what these checks read. A site pointed at
     * Redis, at SQS or at anything else implementing the interface is making a supported choice,
     * so the run records that this did not apply.
     *
     * A queue Craft cannot even build is a different matter: that is a question these checks
     * exist to answer, so they run and report that they could not — rather than being recorded
     * as inapplicable, which would read as "nothing to see here".
     */
    public function isApplicable(DiagnosticContext $context): bool
    {
        try {
            return $this->queue() instanceof Queue;
        } catch (Throwable) {
            return true;
        }
    }

    protected function queue(): QueueInterface
    {
        return $this->queue ?? Craft::$app->getQueue();
    }

    /**
     * The queue as one this check can read, or an exception saying why not.
     *
     * @throws Throwable
     */
    protected function databaseQueue(): Queue
    {
        $queue = $this->queue();

        if (!$queue instanceof Queue) {
            throw new RuntimeException('Craft’s queue is not the one this check can read.');
        }

        return $queue;
    }

    /**
     * A query over this queue's own jobs, scoped the way Craft scopes them in
     * `Queue::_createJobQuery()`.
     */
    protected function jobs(Queue $queue): Query
    {
        $query = (new Query())->from([$queue->tableName]);
        $channel = $this->channel($queue);

        // With no channel to scope by, the whole table is read rather than none of it: a count
        // that is a superset in an exotic multi-channel setup is far better than a zero that
        // would read as "nothing queued".
        return $channel === null ? $query : $query->where(['channel' => $channel]);
    }

    /**
     * Runs a read on the queue's own connection, on its primary, as Craft's `Queue` reads.
     *
     * A queue can be given a database of its own (`'db' => 'queueDb'`), and a read of the
     * application's default connection would count some other table — reporting a stalled queue as
     * empty. A replica can lag behind jobs the primary already holds, which is why Craft reads the
     * primary.
     *
     * @template T
     * @param callable(\yii\db\Connection): T $read
     * @return T
     */
    protected function onQueueDb(Queue $queue, callable $read): mixed
    {
        $db = $queue->db;

        return $db->usePrimary(static fn() => $read($db));
    }

    /**
     * Which channel this queue writes to.
     *
     * Craft derives this from the queue's own `channel`, falling back to the application
     * component ID it is registered under. `Queue::channel()` is private, so the same derivation
     * is done here — read-only, and over components Craft has already built.
     */
    private function channel(Queue $queue): ?string
    {
        if ($queue->channel !== null) {
            return $queue->channel;
        }

        try {
            foreach (Craft::$app->getComponents(false) as $id => $component) {
                if ($component === $queue) {
                    return (string)$id;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
