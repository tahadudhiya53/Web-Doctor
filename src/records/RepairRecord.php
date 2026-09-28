<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_repairs` row: one repair of an issue, from its preview to its outcome.
 *
 * @property int $id
 * @property int|null $issueId
 * @property string $issueTitle
 * @property string $diagnosticId
 * @property string|null $findingRunId
 * @property string $action
 * @property string $actionName
 * @property string $risk
 * @property string|null $riskReason
 * @property string $status
 * @property string $verificationStatus
 * @property string|null $verifyWith
 * @property string|null $verificationNote
 * @property string $environment
 * @property int|null $siteId
 * @property string|null $preview
 * @property string $fingerprint
 * @property string $definitionFingerprint
 * @property string|null $prerequisites
 * @property string|null $acknowledged
 * @property string|null $outcome
 * @property string|null $failure
 * @property string|null $issueStatusBefore
 * @property string|null $lockKey
 * @property int|null $previewedBy
 * @property int|null $executedBy
 * @property string $previewedAt
 * @property string|null $startedAt
 * @property string|null $finishedAt
 * @property float|null $durationMs
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class RepairRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_repairs}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
