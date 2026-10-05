<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Craft;
use DateTimeImmutable;
use Tahadudhiya\WebDoctor\models\SafeException;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\Query;

/**
 * Removes rows a history no longer keeps: those older than its retention period, oldest first, a
 * bounded batch at a time, from its own table only.
 *
 * The period and the batch are refused before anything is deleted when either is not one, so a
 * configuration that cannot be read never decides what is removed.
 */
final class Retention
{
    /**
     * @param string $table The history's own table.
     * @param string $column The moment each row records.
     * @param int $days How many days a row is kept. A row recorded exactly that long ago is kept.
     * @param int $batch The most rows removed at once.
     * @return int How many were removed.
     * @throws InvalidConfigException if the period or the batch is not one.
     */
    public static function prune(string $table, string $column, int $days, int $batch, ?DateTimeImmutable $now = null): int
    {
        self::validate($days, $batch);

        $cutoff = StoredTime::forDb(($now ?? new DateTimeImmutable())->modify(sprintf('-%d days', $days)));
        $db = Craft::$app->getDb();
        $ids = (new Query())
            ->select(['id'])
            ->from($table)
            ->where(['<', $column, $cutoff])
            ->orderBy([$column => SORT_ASC, 'id' => SORT_ASC])
            ->limit($batch)
            ->column($db);

        if ($ids === []) {
            return 0;
        }

        return $db->createCommand()->delete($table, ['id' => $ids])->execute();
    }

    /**
     * @throws InvalidConfigException
     */
    public static function validate(int $days, int $batch): void
    {
        if ($days < 1) {
            throw new InvalidConfigException(sprintf('A retention period of at least 1 day is needed; %d was configured.', $days));
        }

        if ($batch < 1) {
            throw new InvalidConfigException(sprintf('A retention batch of at least 1 row is needed; %d was configured.', $batch));
        }
    }

    /**
     * Prunes after a write, as a history does: once per instance, never inside a transaction someone
     * else holds — where a failed delete would cost them their change — and never at the cost of the
     * write that came first, so a failure is logged and nothing more.
     *
     * @param bool $pruned Whether the instance has pruned already; set here.
     * @param callable(): mixed $prune
     */
    public static function whenFree(bool &$pruned, callable $prune, string $what): void
    {
        if ($pruned || Craft::$app->getDb()->getTransaction() !== null) {
            return;
        }

        $pruned = true;

        try {
            $prune();
        } catch (Throwable $e) {
            SafeException::log($what, $e);
        }
    }

    /**
     * Keeps the newest rows of one subject — a repair's verifications, an issue's investigations —
     * and removes the rest, newest by when each started, then by ID so rows started in the same
     * second are kept or removed in a fixed order.
     *
     * @param array<string, mixed> $of Which rows belong to the subject.
     * @return int How many were removed.
     */
    public static function keepNewest(string $table, array $of, int $limit, string $column = 'startedAt'): int
    {
        $db = Craft::$app->getDb();
        $stale = (new Query())
            ->select(['id'])
            ->from($table)
            ->where($of)
            ->orderBy([$column => SORT_DESC, 'id' => SORT_DESC])
            ->offset($limit)
            ->column($db);

        return $stale === [] ? 0 : $db->createCommand()->delete($table, ['id' => $stale])->execute();
    }
}
