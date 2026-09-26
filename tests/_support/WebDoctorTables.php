<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Craft;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use Tahadudhiya\WebDoctor\records\InvestigationRecord;
use Tahadudhiya\WebDoctor\records\InvestigationStepRecord;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\records\RootCauseRecord;

/**
 * Everything Web Doctor keeps, reduced to one comparable value per table: how many rows, and a
 * digest of every row's content. Two snapshots that are equal mean nothing was written — no row
 * added, removed or changed, however quickly — which is what a refused request has to leave.
 */
final class WebDoctorTables
{
    /**
     * @return array<string, array{rows: int, digest: string}>
     */
    public static function snapshot(): array
    {
        $out = [];

        foreach ([IssueRecord::TABLE, IssueEventRecord::TABLE, EvidenceRecord::TABLE, InvestigationRecord::TABLE, InvestigationStepRecord::TABLE, RootCauseRecord::TABLE, ErrorGroupRecord::TABLE, ErrorSourceRecord::TABLE] as $table) {
            $rows = (new \craft\db\Query())->from($table)->orderBy(['id' => SORT_ASC])->all(Craft::$app->getDb());
            $out[$table] = ['rows' => count($rows), 'digest' => md5((string)json_encode($rows))];
        }

        return $out;
    }
}
