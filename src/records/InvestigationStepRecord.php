<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_investigation_steps` row: one entry in an investigation's timeline.
 *
 * @property int $id
 * @property int $investigationId
 * @property int $position
 * @property string $type
 * @property string|null $diagnosticId
 * @property string|null $diagnosticName
 * @property string|null $status
 * @property string|null $severity
 * @property string|null $summary
 * @property string|null $note
 * @property int|null $relatedIssueId
 * @property string|null $evidence
 * @property int $evidenceCount
 * @property bool $evidenceTruncated
 * @property float|null $durationMs
 * @property string $occurredAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class InvestigationStepRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_investigation_steps}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
