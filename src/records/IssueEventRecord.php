<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_issue_events` row: one thing that happened to one issue.
 *
 * @property int $id
 * @property int $issueId
 * @property string $type
 * @property string|null $fromStatus
 * @property string|null $toStatus
 * @property string|null $severity
 * @property string|null $note
 * @property string|null $runId
 * @property int|null $userId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class IssueEventRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_issue_events}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
