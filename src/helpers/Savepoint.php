<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Craft;
use Throwable;
use yii\db\ActiveQuery;
use yii\db\IntegrityException;

/**
 * An insert that expects to lose a race.
 *
 * Where a lookup and an insert cannot be one atomic step, a unique index is what stops two
 * requests writing the same row, and the loser has to rejoin the winner's row rather than fail.
 * The insert runs inside a nested transaction so that losing rolls back only that savepoint:
 * PostgreSQL abandons an entire transaction after a failed statement, so without it a lost race
 * would take the whole write down with it.
 */
final class Savepoint
{
    /**
     * Runs an insert, answering false when a unique index refused it.
     *
     * Only a unique violation is a lost race. Any other integrity failure — a foreign key naming
     * a row that has just been deleted, a missing value — is rethrown as itself, because reading
     * it as a race would send the caller looking for a winner that does not exist and hide the
     * real cause behind "could not be found".
     *
     * @param callable(): void $insert
     */
    public static function insert(callable $insert): bool
    {
        $savepoint = Craft::$app->getDb()->beginTransaction();

        try {
            $insert();
            $savepoint->commit();

            return true;
        } catch (IntegrityException $e) {
            $savepoint->rollBack();

            if (self::isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        } catch (Throwable $e) {
            $savepoint->rollBack();

            throw $e;
        }
    }

    /**
     * The rows a query matches as last committed, for finding the winner after losing a race.
     *
     * An ordinary read will not do. Under REPEATABLE READ — MySQL's default, which Craft keeps —
     * a transaction reads from the snapshot its first read fixed, and the winner committed after
     * that, so its row is invisible to the very transaction whose insert it just refused. A
     * locking read reads the latest committed version instead, and holds it for the update the
     * caller is about to make.
     *
     * @return array<int, mixed> As the query's own `all()` would return them.
     */
    public static function committed(ActiveQuery $query): array
    {
        $db = $query->modelClass::getDb();
        [$sql, $params] = $db->getQueryBuilder()->build($query);

        return $query->populate($db->createCommand($sql . ' FOR UPDATE', $params)->queryAll());
    }

    /**
     * MySQL reports every integrity failure as SQLSTATE 23000 and tells them apart by its own
     * code (1062 is a duplicate key); PostgreSQL gives a unique violation its own state, 23505.
     */
    public static function isUniqueViolation(IntegrityException $e): bool
    {
        $state = (string)($e->errorInfo[0] ?? '');
        $code = (int)($e->errorInfo[1] ?? 0);

        return $state === '23505' || ($state === '23000' && $code === 1062);
    }
}
