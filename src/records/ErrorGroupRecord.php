<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_error_groups` row: one error, however many times it happened.
 *
 * Only {@see \Tahadudhiya\WebDoctor\services\Errors} works with this. Everything else reads
 * {@see \Tahadudhiya\WebDoctor\models\ErrorGroup}.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $exceptionClass
 * @property string $normalizedMessage
 * @property string|null $message
 * @property string $origin
 * @property string|null $previous
 * @property string|null $stackFingerprint
 * @property string|null $frames
 * @property string $environment
 * @property int|null $siteId
 * @property string|null $siteName
 * @property int $occurrences
 * @property string $firstSeen
 * @property string $lastSeen
 * @property string|null $firstRunId
 * @property string|null $lastRunId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ErrorGroupRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_error_groups}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
