<?php

namespace Tahadudhiya\WebDoctor\diagnostics\queue;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\queue\Queue;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;
use yii\db\Expression;

/**
 * Whether the queue is moving.
 *
 * The number of jobs waiting says very little on its own — a healthy busy site and a site whose
 * queue runner died look identical for the first few seconds. What separates them is time: how
 * long the oldest job has been waiting to start, and how long the running job has been running.
 *
 * A queue that has stopped moving is one of the most consequential silent failures in Craft.
 * Search indexes stop updating, emails stop going out, transforms stop being generated, and the
 * site carries on looking entirely normal.
 *
 * The conditions below are Craft's own, from `Queue::_createWaitingJobQuery()` and the queries
 * beside it. Why they are reproduced rather than called is {@see QueueDiagnostic}.
 */
class QueueBacklogDiagnostic extends QueueDiagnostic
{
    public const ID = 'queue.backlog';

    /** @var int How long a job may sit at the front of the queue before the queue looks stalled, in seconds. */
    public const STALLED_AFTER = 1800;

    /** @var int A backlog above this is worth mentioning even while it is moving. */
    private const LARGE_BACKLOG = 100;

    public function name(): string
    {
        return Craft::t('web-doctor', 'Queue backlog');
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Reports how much work is queued, how long the oldest job has been waiting and whether a running job has overrun its own time limit. It reads the queue without changing it.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $queue = $this->databaseQueue();
            $counts = $this->counts($queue);
            $oldestStartedWaitingAt = $counts['waiting'] > 0 ? $this->oldestWaitingSince($queue) : null;
            $running = $counts['reserved'] > 0 ? $this->longestRunning($queue) : null;
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The queue could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $waiting = $counts['waiting'];
        $waitedFor = $oldestStartedWaitingAt === null ? null : max(0, time() - $oldestStartedWaitingAt);

        $evidence = [
            $this->evidence(EvidenceType::QUEUE, Craft::t('web-doctor', 'Queue depth'), [
                'waiting' => $waiting,
                'running' => $counts['reserved'],
                'delayed' => $counts['delayed'],
                'oldestWaitingSeconds' => $waitedFor,
                'longestRunningSeconds' => $running['seconds'] ?? null,
                'longestRunningTimeLimit' => $running['ttr'] ?? null,
                'runQueueAutomatically' => $this->runsQueueAutomatically(),
            ]),
        ];

        if ($waiting === 0 && $counts['reserved'] === 0) {
            return $this->pass(Craft::t('web-doctor', 'Nothing is waiting in the queue.'), $evidence);
        }

        if ($waitedFor !== null && $waitedFor >= self::STALLED_AFTER) {
            return $this->fail(
                Craft::t('web-doctor', 'The oldest queued job has been waiting {minutes} minutes.', ['minutes' => intdiv($waitedFor, 60)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Check that something is running the queue — a worker process, a cron entry calling `php craft queue/run`, or Craft’s automatic runner — and that it is not failing to start.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Work is being queued and nothing is taking it off, so search indexes, emails and image transforms are quietly not happening while the site looks normal.'),
            );
        }

        // A reserved job records when its worker last reported in, and each job declares how
        // long it expects to need. One that has gone past its own declaration is either doing
        // more than it was written for or was abandoned by a worker that died.
        if ($running !== null && $running['overrunning']) {
            return $this->warning(
                Craft::t('web-doctor', 'A running job has been going for {minutes} minutes, longer than the {limit} seconds it allows itself.', [
                    'minutes' => intdiv($running['seconds'], 60),
                    'limit' => $running['ttr'],
                ]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Find out whether the job is genuinely still working or its worker stopped. Craft releases a job that has overrun so it can be retried, but only once something runs the queue again.'),
                severity: Severity::MEDIUM,
                confidence: Confidence::LIKELY,
                description: Craft::t('web-doctor', 'Either the job needs longer than it declares, or the process running it went away without saying so.'),
            );
        }

        if ($waiting >= self::LARGE_BACKLOG) {
            return $this->warning(
                Craft::t('web-doctor', 'The queue has {waiting} jobs waiting.', ['waiting' => $waiting]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Confirm the backlog is clearing. If it is not, give the queue more workers or find the job that is producing the work.'),
                severity: Severity::MEDIUM,
                confidence: Confidence::LIKELY,
                description: Craft::t('web-doctor', 'The queue is moving, so this is a size to keep an eye on rather than a failure.'),
            );
        }

        return $this->pass(
            Craft::t('web-doctor', 'The queue is keeping up: {waiting} waiting, {running} running.', [
                'waiting' => $waiting,
                'running' => $counts['reserved'],
            ]),
            $evidence,
        );
    }

    /**
     * How many jobs are waiting, delayed and running.
     *
     * The conditions are Craft's, from `Queue::_createWaitingJobQuery()`, `_createDelayedJobQuery()`
     * and `_createReservedJobQuery()` — including the `[[timePushed]] + [[delay]]` comparison,
     * which Craft writes this way precisely so it works on every database it supports.
     *
     * Protected so a test can state a queue's depth rather than having to fill one.
     *
     * @return array{waiting: int, delayed: int, reserved: int}
     */
    protected function counts(Queue $queue): array
    {
        $now = DateTimeHelper::currentTimeStamp();

        $pending = fn(): Query => $this->jobs($queue)->andWhere(['fail' => false, 'timeUpdated' => null]);

        return [
            'waiting' => (int)$pending()
                ->andWhere(new Expression('[[timePushed]] + [[delay]] <= :time', [':time' => $now]))
                ->count('*'),
            'delayed' => (int)$pending()
                ->andWhere(new Expression('[[timePushed]] + [[delay]] > :time', [':time' => $now]))
                ->count('*'),
            'reserved' => (int)$this->jobs($queue)
                ->andWhere(['and', ['fail' => false], ['not', ['timeUpdated' => null]]])
                ->count('*'),
        ];
    }

    /**
     * When the job at the front of the queue became ready to run, as a Unix timestamp.
     *
     * A delayed job is not late until its delay has elapsed, so jobs still waiting out a delay
     * are excluded rather than counted as having waited a long time.
     */
    protected function oldestWaitingSince(Queue $queue): ?int
    {
        $now = DateTimeHelper::currentTimeStamp();

        $oldest = $this->jobs($queue)
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->andWhere(new Expression('[[timePushed]] + [[delay]] <= :time', [':time' => $now]))
            ->min('[[timePushed]] + [[delay]]');

        return $oldest === null || $oldest === false ? null : (int)$oldest;
    }

    /**
     * The running job that has gone longest without reporting in, and whether it has passed the
     * time limit it declared for itself.
     *
     * `timeUpdated` is when the worker last touched the job and `ttr` is the time-to-reserve it
     * asked for, both of which Craft keeps on the row. Craft treats a job past its `ttr` as
     * expired and releases it — but only when something next runs the queue, which is exactly
     * the circumstance in which nothing is.
     *
     * @return array{seconds: int, ttr: int, overrunning: bool}|null
     */
    protected function longestRunning(Queue $queue): ?array
    {
        /** @var array{timeUpdated: int|string|null, ttr: int|string}|null $row */
        $row = $this->jobs($queue)
            ->select(['timeUpdated', 'ttr'])
            ->andWhere(['and', ['fail' => false], ['not', ['timeUpdated' => null]]])
            ->orderBy(['timeUpdated' => SORT_ASC])
            ->limit(1)
            ->one() ?: null;

        if ($row === null || $row['timeUpdated'] === null) {
            return null;
        }

        $seconds = max(0, time() - (int)$row['timeUpdated']);
        $ttr = (int)$row['ttr'];

        return [
            'seconds' => $seconds,
            'ttr' => $ttr,
            'overrunning' => $ttr > 0 && $seconds > $ttr,
        ];
    }

    protected function runsQueueAutomatically(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->runQueueAutomatically;
    }
}
