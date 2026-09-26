<?php

namespace Tahadudhiya\WebDoctor\migrations;

use craft\db\Migration;
use craft\db\Table;
use Tahadudhiya\WebDoctor\records\ErrorGroupRecord;
use Tahadudhiya\WebDoctor\records\ErrorSourceRecord;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use Tahadudhiya\WebDoctor\records\InvestigationRecord;
use Tahadudhiya\WebDoctor\records\InvestigationStepRecord;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;
use Tahadudhiya\WebDoctor\records\RootCauseRecord;

/**
 * Creates the tables Web Doctor owns.
 *
 * Only what needs to outlive a request is stored. A run is the latest answer rather than a
 * record, so it stays in Craft's cache; an issue is the same problem across many runs and carries
 * decisions people made about it, so it does not.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createIssuesTable();
        $this->createIssueEventsTable();
        $this->createEvidenceTable();
        $this->createInvestigationsTable();
        $this->createInvestigationStepsTable();
        $this->createRootCausesTable();
        $this->createErrorGroupsTable();
        $this->createErrorSourcesTable();

        return true;
    }

    public function safeDown(): bool
    {
        // Whatever points at an issue first: dropping the target of a foreign key before the key
        // itself leaves the table that holds it unusable. Steps and root causes point at
        // investigations as well, and an error's sources at both the error and an issue.
        $this->dropTableIfExists(ErrorSourceRecord::TABLE);
        $this->dropTableIfExists(ErrorGroupRecord::TABLE);
        $this->dropTableIfExists(RootCauseRecord::TABLE);
        $this->dropTableIfExists(InvestigationStepRecord::TABLE);
        $this->dropTableIfExists(InvestigationRecord::TABLE);
        $this->dropTableIfExists(EvidenceRecord::TABLE);
        $this->dropTableIfExists(IssueEventRecord::TABLE);
        $this->dropTableIfExists(IssueRecord::TABLE);

        return true;
    }

    /** One row per underlying problem, not one per time it was seen. */
    private function createIssuesTable(): void
    {
        $this->createTable(IssueRecord::TABLE, [
            'id' => $this->primaryKey(),
            // Unique: the same problem found again must land on the row that already describes
            // it. The environment and site are inside the hash as well as beside it, because
            // MySQL treats NULLs in a unique index as distinct — a composite index over a
            // nullable siteId would admit unlimited duplicates for the commonest case of all.
            'fingerprint' => $this->char(64)->notNull(),
            'diagnosticId' => $this->string(100)->notNull(),
            // The check's name and category as it last reported them, so an issue raised by a
            // plugin that has since been removed still reads as something.
            'diagnosticName' => $this->string(255)->notNull(),
            'category' => $this->string(32)->notNull(),
            'title' => $this->string(255)->notNull(),
            'description' => $this->text(),
            'recommendation' => $this->text(),
            'severity' => $this->string(16)->notNull(),
            'status' => $this->string(32)->notNull(),
            'resolution' => $this->string(32)->notNull(),
            // What the check last reported, as against the issue's own status: one says what was
            // found, the other what has been done about it.
            'resultStatus' => $this->string(16)->notNull(),
            'environment' => $this->string(255)->notNull(),
            'siteId' => $this->integer(),
            // The site's name as it was when the issue was found. Without it, a site deletion
            // nulls siteId and the finding would read as one made about the whole installation.
            'siteName' => $this->string(255),
            'affectedComponent' => $this->string(255),
            'affectedPlugin' => $this->string(255),
            // The last result, reduced to what the control panel shows. Its evidence is not part
            // of it: that is kept once per distinct fact in the evidence table.
            'latestResult' => $this->text(),
            'firstRunId' => $this->string(36),
            'latestRunId' => $this->string(36),
            'resolvedByRunId' => $this->string(36),
            'occurrences' => $this->integer()->notNull()->defaultValue(1),
            'firstDetected' => $this->dateTime()->notNull(),
            'lastDetected' => $this->dateTime()->notNull(),
            'resolvedAt' => $this->dateTime(),
            'statusNote' => $this->text(),
            'statusChangedAt' => $this->dateTime(),
            'statusChangedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, IssueRecord::TABLE, ['fingerprint'], true);
        // The Issue Center's default view: outstanding issues, worst first, newest first.
        $this->createIndex(null, IssueRecord::TABLE, ['status', 'severity', 'lastDetected']);
        $this->createIndex(null, IssueRecord::TABLE, ['diagnosticId']);
        $this->createIndex(null, IssueRecord::TABLE, ['environment', 'siteId']);
        $this->createIndex(null, IssueRecord::TABLE, ['lastDetected']);

        // Deleting a site must not delete the record that something was wrong. Most findings are
        // about the installation and merely stamped with whichever site was in view, so cascading
        // would erase a PHP or database problem because an unrelated site was removed. The link
        // is dropped and `siteName` keeps saying which site it was.
        $this->addForeignKey(null, IssueRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
        // The person who last moved it is a reference, not the issue's owner.
        $this->addForeignKey(null, IssueRecord::TABLE, ['statusChangedBy'], Table::USERS, ['id'], 'SET NULL', null);
    }

    /** What has happened to an issue, in order. */
    private function createIssueEventsTable(): void
    {
        $this->createTable(IssueEventRecord::TABLE, [
            'id' => $this->primaryKey(),
            'issueId' => $this->integer()->notNull(),
            'type' => $this->string(32)->notNull(),
            'fromStatus' => $this->string(32),
            'toStatus' => $this->string(32),
            'severity' => $this->string(16),
            'note' => $this->text(),
            'runId' => $this->string(36),
            'userId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, IssueEventRecord::TABLE, ['issueId', 'dateCreated']);

        // An event has no meaning without its issue, so it goes when the issue does.
        $this->addForeignKey(null, IssueEventRecord::TABLE, ['issueId'], IssueRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, IssueEventRecord::TABLE, ['userId'], Table::USERS, ['id'], 'SET NULL', null);
    }

    /**
     * The facts behind an issue: one row per distinct fact, not one per run that saw it. The same
     * evidence found again moves `lastSeen` and the count; only evidence that says something new
     * adds a row, and the store bounds how many an issue may hold.
     */
    private function createEvidenceTable(): void
    {
        $this->createTable(EvidenceRecord::TABLE, [
            'id' => $this->primaryKey(),
            'issueId' => $this->integer()->notNull(),
            // What makes this the same fact as another, so a second sighting finds the first row.
            'digest' => $this->char(64)->notNull(),
            'diagnosticId' => $this->string(100)->notNull(),
            'type' => $this->string(32)->notNull(),
            'label' => $this->string(255)->notNull(),
            'source' => $this->string(255)->notNull(),
            // Where the fact can be found again, kept in preference to the thing itself.
            'reference' => $this->string(500),
            // Redacted and bounded before it arrives. The model caps the encoded fact at 16 KiB
            // and its notes at 4 KiB, so a text column holds either with room to spare.
            'data' => $this->text(),
            'metadata' => $this->text(),
            'confidence' => $this->string(16),
            'truncated' => $this->boolean()->notNull()->defaultValue(false),
            // The evidence's own attribution, as the engine stamped it — not borrowed from the
            // issue, so the row still says where it was gathered when read on its own.
            'environment' => $this->string(255)->notNull(),
            'siteId' => $this->integer(),
            'firstRunId' => $this->string(36),
            'lastRunId' => $this->string(36),
            'occurrences' => $this->integer()->notNull()->defaultValue(1),
            'observedAt' => $this->dateTime(),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Unique: two requests recording the same fact at once must land on one row.
        $this->createIndex(null, EvidenceRecord::TABLE, ['issueId', 'digest'], true);
        // The detail page's two reads — what the latest run saw, and everything else newest first
        // — and the pruning that keeps an issue's evidence bounded.
        $this->createIndex(null, EvidenceRecord::TABLE, ['issueId', 'lastRunId']);
        $this->createIndex(null, EvidenceRecord::TABLE, ['issueId', 'lastSeen']);
        $this->createIndex(null, EvidenceRecord::TABLE, ['siteId']);

        // Evidence exists to support an issue, so it goes when the issue does.
        $this->addForeignKey(null, EvidenceRecord::TABLE, ['issueId'], IssueRecord::TABLE, ['id'], 'CASCADE', null);
        // A deleted site drops the reference and keeps the fact, as the issue itself does.
        $this->addForeignKey(null, EvidenceRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
    }

    /**
     * One investigation of one issue: what it set out to look at, how it went, and what it found,
     * counted. What happened along the way is its steps.
     */
    private function createInvestigationsTable(): void
    {
        $this->createTable(InvestigationRecord::TABLE, [
            'id' => $this->primaryKey(),
            'issueId' => $this->integer()->notNull(),
            // The diagnostic run the investigation's checks ran as, so its findings and the
            // evidence they left behind issues can be followed back to it.
            'runId' => $this->string(36),
            'status' => $this->string(16)->notNull(),
            'depth' => $this->string(16)->notNull(),
            'ruleId' => $this->string(64)->notNull(),
            // The plan as it was decided, reasons and all. Kept rather than rebuilt on reading,
            // because the rules and the installed checks can both change afterwards and the
            // record has to say what this investigation actually set out to do.
            'plan' => $this->text(),
            'environment' => $this->string(255)->notNull(),
            'siteId' => $this->integer(),
            'startedBy' => $this->integer(),
            'checksPlanned' => $this->integer()->notNull()->defaultValue(0),
            'checksRun' => $this->integer()->notNull()->defaultValue(0),
            'checksWithProblems' => $this->integer()->notNull()->defaultValue(0),
            'checksIncomplete' => $this->integer()->notNull()->defaultValue(0),
            'checksSkipped' => $this->integer()->notNull()->defaultValue(0),
            'evidenceCount' => $this->integer()->notNull()->defaultValue(0),
            'relatedIssues' => $this->integer()->notNull()->defaultValue(0),
            // What the check that raised the issue reported this time, as a result status.
            'originStatus' => $this->string(16),
            // Why it could not finish, redacted, where it could not.
            'failure' => $this->text(),
            'startedAt' => $this->dateTime()->notNull(),
            'finishedAt' => $this->dateTime(),
            'durationMs' => $this->float(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // An issue's investigations, newest first, and the pruning that keeps them bounded.
        $this->createIndex(null, InvestigationRecord::TABLE, ['issueId', 'startedAt']);
        $this->createIndex(null, InvestigationRecord::TABLE, ['siteId']);

        // An investigation exists to explain its issue, so it goes when the issue does.
        $this->addForeignKey(null, InvestigationRecord::TABLE, ['issueId'], IssueRecord::TABLE, ['id'], 'CASCADE', null);
        // A deleted site drops the reference and keeps the record, as the issue itself does.
        $this->addForeignKey(null, InvestigationRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, InvestigationRecord::TABLE, ['startedBy'], Table::USERS, ['id'], 'SET NULL', null);
    }

    /** An investigation's timeline, in the order it happened. */
    private function createInvestigationStepsTable(): void
    {
        $this->createTable(InvestigationStepRecord::TABLE, [
            'id' => $this->primaryKey(),
            'investigationId' => $this->integer()->notNull(),
            'position' => $this->integer()->notNull(),
            'type' => $this->string(32)->notNull(),
            'diagnosticId' => $this->string(100),
            'diagnosticName' => $this->string(255),
            'status' => $this->string(16),
            'severity' => $this->string(16),
            'summary' => $this->text(),
            'note' => $this->text(),
            // The issue a step concerns — the one a check's finding landed on, or one already open
            // nearby. What the step says about it is kept on the step, so a deleted issue leaves
            // a step that still reads as something.
            'relatedIssueId' => $this->integer(),
            // What the check recorded. Each piece is bounded by the evidence model, and the
            // investigation bounds how many it keeps, so a medium text column holds a step's
            // share with room to spare where a text column would not.
            'evidence' => $this->mediumText(),
            'evidenceCount' => $this->integer()->notNull()->defaultValue(0),
            'evidenceTruncated' => $this->boolean()->notNull()->defaultValue(false),
            'durationMs' => $this->float(),
            'occurredAt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, InvestigationStepRecord::TABLE, ['investigationId', 'position'], true);
        $this->createIndex(null, InvestigationStepRecord::TABLE, ['relatedIssueId']);

        $this->addForeignKey(null, InvestigationStepRecord::TABLE, ['investigationId'], InvestigationRecord::TABLE, ['id'], 'CASCADE', null);
        // Another issue being deleted must not take this investigation's account of it along.
        $this->addForeignKey(null, InvestigationStepRecord::TABLE, ['relatedIssueId'], IssueRecord::TABLE, ['id'], 'SET NULL', null);
    }

    /**
     * The causes an investigation weighed, most firmly held first, each with the evidence for and
     * against it and the reasoning between them. Kept rather than weighed again on reading, for the
     * reason the plan is: the rules can change afterwards, and the record has to say what was
     * concluded then and on what.
     */
    private function createRootCausesTable(): void
    {
        $this->createTable(RootCauseRecord::TABLE, [
            'id' => $this->primaryKey(),
            'investigationId' => $this->integer()->notNull(),
            // Most firmly held first, as the causes were ordered when they were weighed.
            'position' => $this->integer()->notNull(),
            'ruleId' => $this->string(64)->notNull(),
            'confidence' => $this->string(16)->notNull(),
            'title' => $this->string(255)->notNull(),
            'statement' => $this->text(),
            // The problem weighed, as its issue read then.
            'problem' => $this->text(),
            // The conditions as found, each with the observations that met it. Bounded by the rule
            // — ten observations a condition, each of them bounded — which a medium text column
            // holds with room to spare where a text column might not.
            'supporting' => $this->mediumText(),
            'conflicting' => $this->mediumText(),
            'unmet' => $this->text(),
            'reasoning' => $this->text(),
            // The issues the evidence for it came from, as they stood then, so a deleted or renamed
            // issue leaves a cause that still reads as it did.
            'relatedIssues' => $this->text(),
            'recommendation' => $this->text(),
            'nextSteps' => $this->text(),
            'limitation' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, RootCauseRecord::TABLE, ['investigationId', 'position'], true);
        $this->createIndex(null, RootCauseRecord::TABLE, ['ruleId']);

        // A cause is what an investigation concluded, so it goes when the investigation does.
        $this->addForeignKey(null, RootCauseRecord::TABLE, ['investigationId'], InvestigationRecord::TABLE, ['id'], 'CASCADE', null);
    }

    /**
     * One row per error, not one per time it happened: the same error seen again moves the count
     * and `lastSeen`. What it was is kept normalised, so the row describes the error rather than
     * any one occurrence of it, beside the latest occurrence's own wording.
     */
    private function createErrorGroupsTable(): void
    {
        $this->createTable(ErrorGroupRecord::TABLE, [
            'id' => $this->primaryKey(),
            // Unique, and with the environment and site inside the hash, for the reason the
            // issues table gives.
            'fingerprint' => $this->char(64)->notNull(),
            'exceptionClass' => $this->string(255)->notNull(),
            // Redacted before normalising and again on the way in; bounded to 1,000 characters.
            'normalizedMessage' => $this->text()->notNull(),
            // The latest occurrence's message as it was written, redacted.
            'message' => $this->text(),
            'origin' => $this->string(500)->notNull(),
            'previous' => $this->text(),
            'stackFingerprint' => $this->char(64),
            // The latest recorded trace, bounded by the exception model to 50 frames.
            'frames' => $this->text(),
            'environment' => $this->string(255)->notNull(),
            'siteId' => $this->integer(),
            // Kept for the reason the issue keeps it: a deleted site nulls the reference.
            'siteName' => $this->string(255),
            'occurrences' => $this->integer()->notNull()->defaultValue(1),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'firstRunId' => $this->string(36),
            'lastRunId' => $this->string(36),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, ErrorGroupRecord::TABLE, ['fingerprint'], true);
        // The list, most recently seen first, and the pruning that keeps the table bounded.
        $this->createIndex(null, ErrorGroupRecord::TABLE, ['lastSeen']);
        $this->createIndex(null, ErrorGroupRecord::TABLE, ['environment', 'siteId']);
        $this->createIndex(null, ErrorGroupRecord::TABLE, ['exceptionClass']);

        // A deleted site drops the reference and keeps the error, as the issue itself does.
        $this->addForeignKey(null, ErrorGroupRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
    }

    /** Which checks ran into each error, and the issue each check's findings are recorded on. */
    private function createErrorSourcesTable(): void
    {
        $this->createTable(ErrorSourceRecord::TABLE, [
            'id' => $this->primaryKey(),
            'errorGroupId' => $this->integer()->notNull(),
            'diagnosticId' => $this->string(100)->notNull(),
            // As the check last reported it, so a removed plugin's check still reads as something.
            'diagnosticName' => $this->string(255)->notNull(),
            'issueId' => $this->integer(),
            // What the check's result was the last time it ran into the error: it broke, it could
            // not tell, or it reported a problem.
            'lastStatus' => $this->string(16)->notNull(),
            'occurrences' => $this->integer()->notNull()->defaultValue(1),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'lastRunId' => $this->string(36),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Unique: two requests recording the same check against the same error land on one row.
        $this->createIndex(null, ErrorSourceRecord::TABLE, ['errorGroupId', 'diagnosticId'], true);
        // An issue's errors, read from its page.
        $this->createIndex(null, ErrorSourceRecord::TABLE, ['issueId']);
        $this->createIndex(null, ErrorSourceRecord::TABLE, ['diagnosticId']);

        // A source says something only about its error, so it goes when the error does.
        $this->addForeignKey(null, ErrorSourceRecord::TABLE, ['errorGroupId'], ErrorGroupRecord::TABLE, ['id'], 'CASCADE', null);
        // An error outlives the issue it was related to; deleting the issue drops the link.
        $this->addForeignKey(null, ErrorSourceRecord::TABLE, ['issueId'], IssueRecord::TABLE, ['id'], 'SET NULL', null);
    }
}
