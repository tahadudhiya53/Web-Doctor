<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_root_causes` row: one cause an investigation weighed, with its evidence for and
 * against and the reasoning between them.
 *
 * @property int $id
 * @property int $investigationId
 * @property int $position
 * @property string $ruleId
 * @property string $confidence
 * @property string $title
 * @property string|null $statement
 * @property string|null $problem
 * @property string|null $supporting
 * @property string|null $conflicting
 * @property string|null $unmet
 * @property string|null $reasoning
 * @property string|null $relatedIssues
 * @property string|null $recommendation
 * @property string|null $nextSteps
 * @property string|null $limitation
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class RootCauseRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_root_causes}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
