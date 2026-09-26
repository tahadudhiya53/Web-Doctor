<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_error_sources` row: one check that ran into one error, how often, and the issue
 * that check's findings are recorded on.
 *
 * Only {@see \Tahadudhiya\WebDoctor\services\Errors} works with this. Everything else reads
 * {@see \Tahadudhiya\WebDoctor\models\ErrorSource}.
 *
 * @property int $id
 * @property int $errorGroupId
 * @property string $diagnosticId
 * @property string $diagnosticName
 * @property int|null $issueId
 * @property string $lastStatus
 * @property int $occurrences
 * @property string $firstSeen
 * @property string $lastSeen
 * @property string|null $lastRunId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ErrorSourceRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_error_sources}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
