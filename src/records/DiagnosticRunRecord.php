<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_diagnostic_runs` row: one diagnostic run as it happened, with its health snapshot
 * where the run covered every check.
 *
 * @property int $id
 * @property string $runId
 * @property string $environment
 * @property int|null $siteId
 * @property string|null $siteName
 * @property string $mode
 * @property string $depth
 * @property int|null $userId
 * @property string|null $userName
 * @property int $checksRegistered
 * @property int $checksRun
 * @property bool $complete
 * @property string $statusCounts
 * @property int|null $score
 * @property int|null $maxScore
 * @property string|null $weights
 * @property string|null $severityCounts
 * @property string|null $contributions
 * @property string $results
 * @property int $resultsOmitted
 * @property string $startedAt
 * @property string $finishedAt
 * @property float|null $durationMs
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class DiagnosticRunRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_diagnostic_runs}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
