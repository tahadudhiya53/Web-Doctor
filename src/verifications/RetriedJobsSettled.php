<?php

namespace Tahadudhiya\WebDoctor\verifications;

use Craft;
use craft\queue\Queue;
use craft\queue\QueueInterface;
use Tahadudhiya\WebDoctor\base\VerificationAction;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueDiagnostic;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\models\VerificationCondition;
use Tahadudhiya\WebDoctor\repairs\RetryFailedJobs;
use Throwable;
use yii\queue\ExecEvent;

/**
 * That the jobs a repair put back in the queue have run successfully, and none failed again.
 *
 * Retrying a job only takes it out of the failed count; whether it works is known once whatever runs
 * the queue has run it. And a job that has left the queue table has not necessarily run: Craft's
 * `Queue::release()` deletes the row both after a job succeeds and when somebody releases it by hand,
 * and `executeJob()` also releases a job it gave up on without running it. An absent row is therefore
 * never read as success.
 *
 * What is read as success is Craft's own signal that a job ran: `EVENT_AFTER_EXEC`, which yii's queue
 * triggers only after the job's `execute()` returned without throwing, in the process that ran it.
 * The repair notes each job in Craft's cache just before retrying it ({@see self::watch()}), and
 * {@see self::recordRun()} — attached to that event — marks a noted job as run. A job counts as run
 * only when its note was made during this repair and was then marked run. Anything short of that —
 * released by hand, abandoned, the cache cleared, run by a worker whose cache this one cannot see —
 * cannot be told, which is inconclusive, never success.
 *
 * Only this queue's rows are ever read, through the scoping the queue checks use.
 */
class RetriedJobsSettled extends VerificationAction
{
    public const ID = 'queue.retriedJobsSettled';

    /** @var int How long a retried job is watched for, in seconds. Past it, whether it ran cannot be told. */
    public const WATCH_FOR = 604800;

    private const KEY = 'web-doctor:retried-job:v1';

    /**
     * @var QueueInterface|null The queue to read. Craft's own unless a caller supplies another,
     * which is how a test reads a queue table of its own.
     */
    public ?QueueInterface $queue = null;

    public function name(): string
    {
        return Craft::t('web-doctor', 'The retried jobs have run and none failed again');
    }

    public function repairAction(): string
    {
        return RetryFailedJobs::ID;
    }

    /**
     * Notes that a job is being retried, just before it is, so that its run can be recognised. A note
     * that cannot be written costs only the ability to verify that job, which then reads as not told.
     */
    public static function watch(Queue $queue, int $id): void
    {
        try {
            Craft::$app->getCache()->set(self::key($queue, $id), ['retriedAt' => time(), 'ranAt' => null], self::WATCH_FOR);
        } catch (Throwable $e) {
            SafeException::log('A retried job could not be noted for verification', $e);
        }
    }

    /**
     * Craft's queue finished running a job without an error. Marks it run if a repair noted it. Never
     * throws: a queue worker must not fail a job because Web Doctor could not write down that it ran.
     */
    public static function recordRun(ExecEvent $event): void
    {
        try {
            $queue = $event->sender;

            if (!$queue instanceof Queue || !is_numeric($event->id)) {
                return;
            }

            $cache = Craft::$app->getCache();
            $key = self::key($queue, (int)$event->id);
            $note = $cache->get($key);

            if (is_array($note) && is_int($note['retriedAt'] ?? null)) {
                $cache->set($key, ['retriedAt' => $note['retriedAt'], 'ranAt' => time()], self::WATCH_FOR);
            }
        } catch (Throwable $e) {
            SafeException::log('That a retried job ran could not be noted for verification', $e);
        }
    }

    public function conditions(Repair $repair): array
    {
        $retried = $this->recorded($repair, 'retried');

        if (!is_array($retried) || !array_is_list($retried) || count(array_filter($retried, 'is_int')) !== count($retried)) {
            return [
                VerificationCondition::undetermined('jobsRan', Craft::t('web-doctor', 'Every job the repair retried has run successfully.'), Craft::t('web-doctor', 'Which jobs the repair retried cannot be read from its record.')),
            ];
        }

        $retried = array_values(array_unique($retried));
        sort($retried);

        // Nothing it did means nothing to verify — which is not a failure of what it did.
        if ($retried === []) {
            return [
                VerificationCondition::undetermined('jobsRan', Craft::t('web-doctor', 'Every job the repair retried has run successfully.'), Craft::t('web-doctor', 'It put no job back in the queue, so there is nothing it did to verify.')),
            ];
        }

        $queue = $this->databaseQueue();

        if ($queue === null || $repair->startedAt === null || $repair->finishedAt === null) {
            return [
                VerificationCondition::undetermined('jobsRan', Craft::t('web-doctor', 'Every job the repair retried has run successfully.'), Craft::t('web-doctor', 'Craft’s queue is not one Web Doctor can read.')),
            ];
        }

        try {
            $rows = QueueDiagnostic::onQueueDb($queue, static fn($db) => QueueDiagnostic::jobs($queue)
                ->select(['id', 'fail'])
                ->andWhere(['id' => $retried])
                ->all($db));
            $cache = Craft::$app->getCache();
        } catch (Throwable $e) {
            SafeException::log('The retried jobs could not be read to verify a repair', $e);

            return [
                VerificationCondition::undetermined('jobsRan', Craft::t('web-doctor', 'Every job the repair retried has run successfully.'), Craft::t('web-doctor', 'The queue could not be read. The details are in Craft’s logs.')),
            ];
        }

        $present = [];

        foreach ($rows as $row) {
            $present[(int)$row['id']] = (bool)$row['fail'];
        }

        $failedAgain = $waiting = $ran = $untold = [];

        foreach ($retried as $id) {
            if (isset($present[$id])) {
                $present[$id] ? $failedAgain[] = $id : $waiting[] = $id;
            } elseif ($this->ranDuring($cache->get(self::key($queue, $id)), $repair)) {
                $ran[] = $id;
            } else {
                $untold[] = $id;
            }
        }

        $ids = static fn(array $list): string => implode(', ', array_map(static fn(int $id): string => '#' . $id, $list));
        $notes = array_filter([
            $waiting === [] ? null : Craft::t('web-doctor', 'Still waiting to run, or running now: {ids}. Verify again once whatever runs the queue has run them.', ['ids' => $ids($waiting)]),
            $untold === [] ? null : Craft::t('web-doctor', 'No longer in the queue, but nothing shows they ran successfully: {ids}. A job released by hand, or given up on, leaves the queue without running.', ['ids' => $ids($untold)]),
        ]);

        $conditions = [
            $failedAgain === []
                ? VerificationCondition::held('noneFailedAgain', Craft::t('web-doctor', 'None of the jobs the repair retried has failed again.'))
                : VerificationCondition::notHeld('noneFailedAgain', Craft::t('web-doctor', 'None of the jobs the repair retried has failed again.'), Craft::t('web-doctor', 'Failed again: {ids}', ['ids' => $ids($failedAgain)])),
        ];

        // Every one of them failed again: that is already said, and there is nothing else to say.
        if ($notes === [] && $ran === []) {
            return $conditions;
        }

        $conditions[] = $notes !== []
            ? VerificationCondition::undetermined('jobsRan', Craft::t('web-doctor', 'Every job the repair retried has run successfully.'), implode(' ', $notes))
            : VerificationCondition::held('jobsRan', Craft::t('web-doctor', 'Every job the repair retried has run successfully.'), Craft::t('web-doctor', 'Craft ran {count, plural, =1{one job} other{# jobs}} without an error: {ids}', ['count' => count($ran), 'ids' => $ids($ran)]));

        return $conditions;
    }

    /**
     * Whether a note says the job was retried by this repair — while it was being carried out — and
     * then ran. A note from a later retry of the same job, by anybody, is not this repair's.
     */
    private function ranDuring(mixed $note, Repair $repair): bool
    {
        if (!is_array($note) || !is_int($note['retriedAt'] ?? null) || !is_int($note['ranAt'] ?? null)) {
            return false;
        }

        return $repair->startedAt !== null && $repair->finishedAt !== null
            && $note['retriedAt'] >= $repair->startedAt->getTimestamp()
            && $note['retriedAt'] <= $repair->finishedAt->getTimestamp()
            && $note['ranAt'] >= $note['retriedAt'];
    }

    /**
     * Which job in which queue: its table, channel and database, so a note from one queue is never
     * read as another's.
     */
    private static function key(Queue $queue, int $id): string
    {
        return self::KEY . ':' . hash('sha256', implode('|', [$queue->tableName, QueueDiagnostic::channelOf($queue), $queue->db->dsn])) . ':' . $id;
    }

    /**
     * Craft's queue, where it is the database-backed one the repair retried jobs in.
     */
    private function databaseQueue(): ?Queue
    {
        try {
            $queue = $this->queue ?? Craft::$app->getQueue();
        } catch (Throwable) {
            return null;
        }

        return $queue instanceof Queue ? $queue : null;
    }
}
