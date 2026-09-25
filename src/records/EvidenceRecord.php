<?php

namespace Tahadudhiya\WebDoctor\records;

use craft\db\ActiveRecord;

/**
 * The `webdoctor_evidence` row: one fact kept against one issue, however many runs saw it.
 *
 * Only {@see \Tahadudhiya\WebDoctor\services\EvidenceStore} works with this. Everything else
 * reads {@see \Tahadudhiya\WebDoctor\models\StoredEvidence}.
 *
 * @property int $id
 * @property int $issueId
 * @property string $digest
 * @property string $diagnosticId
 * @property string $type
 * @property string $label
 * @property string $source
 * @property string|null $reference
 * @property string|null $data
 * @property string|null $metadata
 * @property string|null $confidence
 * @property bool $truncated
 * @property string $environment
 * @property int|null $siteId
 * @property string|null $firstRunId
 * @property string|null $lastRunId
 * @property int $occurrences
 * @property string|null $observedAt
 * @property string $firstSeen
 * @property string $lastSeen
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class EvidenceRecord extends ActiveRecord
{
    public const TABLE = '{{%webdoctor_evidence}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
