<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_audit_log` row: one act, who did it, to what, where, when and how it ended.
 *
 * @property int $id
 * @property string $action
 * @property string $result
 * @property string $summary
 * @property string $objectType
 * @property string|null $objectId
 * @property string|null $objectLabel
 * @property int|null $issueId
 * @property int|null $userId
 * @property string|null $userName
 * @property string $environment
 * @property int|null $siteId
 * @property string|null $siteName
 * @property string|null $details
 * @property string $occurredAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AuditRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_audit_log}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
