<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Craft;
use craft\db\Query;
use RuntimeException;
use Throwable;

/**
 * Who is acting, as Craft says: the signed-in user's ID and username, or nobody.
 *
 * Nobody — a console command, a queue job, a request nobody is signed in to — is a real answer and is
 * recorded as one. Not being able to ask is not that answer: an identity lookup that fails, or an
 * identity with no usable ID or username, is an error, so a record of who did something is never
 * written as "nobody" when it was somebody Web Doctor could not name.
 */
final class Actor
{
    /**
     * @return array{0: int|null, 1: string|null} The user's ID and username, or both null for nobody.
     * @throws RuntimeException if who is acting cannot be established.
     */
    public static function current(): array
    {
        try {
            $user = Craft::$app->getUser()->getIdentity();
        } catch (Throwable $e) {
            throw new RuntimeException('Who is acting could not be established.', 0, $e);
        }

        if ($user === null) {
            return [null, null];
        }

        $id = $user->id;
        $name = $user->username;

        if (!is_int($id) || $id < 1 || !is_string($name) || trim($name) === '') {
            throw new RuntimeException('The signed-in identity has no usable ID or username.');
        }

        return [$id, $name];
    }

    /**
     * Who a record says acted, by the username kept when it was made: nobody signed in, the person,
     * or the person whose account has since been deleted.
     */
    public static function label(?int $userId, ?string $keptName, bool $unreadable = false): string
    {
        return match (true) {
            $unreadable => Craft::t('web-doctor', 'Could not be read'),
            $keptName === null => Craft::t('web-doctor', 'Nobody signed in (console or queue)'),
            $userId === null => Craft::t('web-doctor', '{name} (deleted)', ['name' => $keptName]),
            default => $keptName,
        };
    }

    /**
     * The people a list can be filtered by, from one table's user columns: each by the name kept on
     * their most recent record there — a renamed account reads as it does now, the same every time —
     * or by ID where no name was ever kept. Deleted accounts are left out: they have no ID to filter by.
     *
     * @param list<array{0: string, 1: string}> $columns Pairs of user-ID and kept-name columns.
     * @return array<int, string> User ID to label, by label.
     */
    public static function choicesIn(string $table, array $columns): array
    {
        $latest = [];

        foreach ($columns as [$idColumn, $nameColumn]) {
            $rows = (new Query())
                ->select(['userId' => $idColumn, 'userName' => $nameColumn, 'latest' => 'MAX([[id]])'])
                ->from($table)
                ->where(['not', [$idColumn => null]])
                ->groupBy([$idColumn, $nameColumn])
                ->all();

            foreach ($rows as $row) {
                $id = (int)$row['userId'];
                $name = is_string($row['userName']) && $row['userName'] !== '' ? $row['userName'] : null;
                $at = (int)$row['latest'];
                $held = $latest[$id] ?? null;

                // A kept name always beats none; between names, the most recent record's wins.
                if ($held === null || ($name !== null && ($held['name'] === null || $at > $held['at']))) {
                    $latest[$id] = ['name' => $name, 'at' => $at];
                }
            }
        }

        $out = [];
        ksort($latest);

        foreach ($latest as $id => $held) {
            $out[$id] = $held['name'] !== null
                ? Redaction::redactString($held['name'])
                : Craft::t('web-doctor', 'User #{id}', ['id' => $id]);
        }

        asort($out);

        return $out;
    }
}
