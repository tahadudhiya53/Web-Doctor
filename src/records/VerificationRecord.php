<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_verifications` row: one verification of a carried-out repair.
 *
 * @property int $id
 * @property int $repairId
 * @property int|null $issueId
 * @property string $diagnosticId
 * @property string $action
 * @property string $environment
 * @property int|null $siteId
 * @property string $runId
 * @property string $result
 * @property string|null $failures
 * @property string|null $checks
 * @property string|null $conditions
 * @property string|null $originalState
 * @property string|null $currentState
 * @property string|null $comparison
 * @property string|null $errors
 * @property int|null $verifiedBy
 * @property string $startedAt
 * @property string $finishedAt
 * @property float|null $durationMs
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class VerificationRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_verifications}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
