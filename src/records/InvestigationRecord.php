<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_investigations` row: one investigation of one issue.
 *
 * @property int $id
 * @property int $issueId
 * @property string|null $runId
 * @property string $status
 * @property string $depth
 * @property string $ruleId
 * @property string|null $plan
 * @property string $environment
 * @property int|null $siteId
 * @property int|null $startedBy
 * @property int $checksPlanned
 * @property int $checksRun
 * @property int $checksWithProblems
 * @property int $checksIncomplete
 * @property int $checksSkipped
 * @property int $evidenceCount
 * @property int $relatedIssues
 * @property string|null $originStatus
 * @property string|null $failure
 * @property string $startedAt
 * @property string|null $finishedAt
 * @property float|null $durationMs
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class InvestigationRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_investigations}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
