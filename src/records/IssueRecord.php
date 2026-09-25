<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_issues` row.
 *
 * Records are the database's shape and nothing else. Everything outside
 * {@see \Tahadudhiya\WebDoctor\services\Issues} works with the Issue model instead, so no
 * controller or template ever holds something it could save.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $diagnosticId
 * @property string $diagnosticName
 * @property string $category
 * @property string $title
 * @property string|null $description
 * @property string|null $recommendation
 * @property string $severity
 * @property string $status
 * @property string $resolution
 * @property string $resultStatus
 * @property string $environment
 * @property int|null $siteId
 * @property string|null $siteName
 * @property string|null $affectedComponent
 * @property string|null $affectedPlugin
 * @property string|null $latestResult
 * @property string|null $firstRunId
 * @property string|null $latestRunId
 * @property string|null $resolvedByRunId
 * @property int $occurrences
 * @property string $firstDetected
 * @property string $lastDetected
 * @property string|null $resolvedAt
 * @property string|null $statusNote
 * @property string|null $statusChangedAt
 * @property int|null $statusChangedBy
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class IssueRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_issues}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
