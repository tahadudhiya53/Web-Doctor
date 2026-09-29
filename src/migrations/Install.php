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
use Tahadudhiya\WebDoctor\records\RepairRecord;
use Tahadudhiya\WebDoctor\records\RootCauseRecord;
use Tahadudhiya\WebDoctor\records\VerificationRecord;

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
        $this->createRepairsTable();
        $this->createVerificationsTable();

        return true;
    }

    public function safeDown(): bool
    {
        // Whatever points at an issue first: dropping the target of a foreign key before the key
        // itself leaves the table that holds it unusable. Steps and root causes point at
        // investigations as well, an error's sources at both the error and an issue, and a
        // verification at its repair.
        $this->dropTableIfExists(VerificationRecord::TABLE);
        $this->dropTableIfExists(RepairRecord::TABLE);
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
     * One investigation — of an issue, or of a symptom through a recipe: what it set out to look
     * at, how it went, and what it found, counted. What happened along the way is its steps.
     */
    private function createInvestigationsTable(): void
    {
        $this->createTable(InvestigationRecord::TABLE, [
            'id' => $this->primaryKey(),
            // Exactly one of the two: the issue investigated, or the recipe a symptom was
            // investigated with. A recipe's investigation belongs to no issue until its checks
            // find something, and what they find is linked from its steps.
            'issueId' => $this->integer(),
            'recipeId' => $this->string(100),
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
        // A recipe's investigations in one place, newest first, and the pruning that bounds them.
        $this->createIndex(null, InvestigationRecord::TABLE, ['recipeId', 'environment', 'siteId', 'startedAt']);
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

    /**
     * One repair of an issue, from the preview somebody was shown to what it did. A preview is kept
     * so that what is confirmed is exactly what was shown, and confirmed at most once.
     */
    private function createRepairsTable(): void
    {
        $this->createTable(RepairRecord::TABLE, [
            'id' => $this->primaryKey(),
            'issueId' => $this->integer(),
            // The issue and its check as they read when it was previewed, so a repair whose issue
            // has since been deleted still says what it was done for.
            'issueTitle' => $this->string(255)->notNull(),
            'diagnosticId' => $this->string(100)->notNull(),
            // The run whose finding the preview was made from. A later finding is a new reading the
            // person has not seen, so the preview no longer stands for it.
            'findingRunId' => $this->string(36),
            // The action, and what it said about itself then: its name, its risk and why, and how
            // to tell whether it worked. The action can change or go afterwards.
            'action' => $this->string(100)->notNull(),
            'actionName' => $this->string(255)->notNull(),
            'risk' => $this->string(16)->notNull(),
            'riskReason' => $this->text(),
            'status' => $this->string(16)->notNull(),
            // The latest verification's answer; `verification_failed` is longer than a status.
            'verificationStatus' => $this->string(32)->notNull(),
            'verifyWith' => $this->text(),
            'verificationNote' => $this->text(),
            'environment' => $this->string(255)->notNull(),
            'siteId' => $this->integer(),
            // What it would do and the state before, redacted and bounded by the report and its
            // evidence — a medium text column holds it with room to spare where a text column
            // might not. The fingerprint is of the whole state, not the bounded list.
            'preview' => $this->mediumText(),
            'fingerprint' => $this->char(64)->notNull(),
            // What the action said about itself — its name, risk, prerequisites and verification —
            // hashed, so a changed definition is caught though the installation's state is not.
            'definitionFingerprint' => $this->char(64)->notNull(),
            'prerequisites' => $this->text(),
            'acknowledged' => $this->text(),
            // What it did and the state after, bounded the same way.
            'outcome' => $this->mediumText(),
            'failure' => $this->text(),
            // Where the issue stood before it was set to repairing, so it can be put back.
            'issueStatusBefore' => $this->string(32),
            // Held only while it runs: two repairs of the same kind in the same place cannot hold
            // it at once. MySQL admits any number of NULLs under a unique index, so finished and
            // waiting repairs never collide.
            'lockKey' => $this->char(64),
            'previewedBy' => $this->integer(),
            'executedBy' => $this->integer(),
            'previewedAt' => $this->dateTime()->notNull(),
            'startedAt' => $this->dateTime(),
            'finishedAt' => $this->dateTime(),
            'durationMs' => $this->float(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, RepairRecord::TABLE, ['lockKey'], true);
        // An issue's repairs, newest first, and the pruning of its unconfirmed previews.
        $this->createIndex(null, RepairRecord::TABLE, ['issueId', 'previewedAt']);
        $this->createIndex(null, RepairRecord::TABLE, ['action']);
        $this->createIndex(null, RepairRecord::TABLE, ['siteId']);

        // A repair is a record of something done to the installation, so it outlives the issue it
        // was done for: deleting the issue drops the link, and `issueTitle` keeps saying what it was.
        $this->addForeignKey(null, RepairRecord::TABLE, ['issueId'], IssueRecord::TABLE, ['id'], 'SET NULL', null);
        // A deleted site drops the reference and keeps the record, as the issue itself does.
        $this->addForeignKey(null, RepairRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, RepairRecord::TABLE, ['previewedBy'], Table::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, RepairRecord::TABLE, ['executedBy'], Table::USERS, ['id'], 'SET NULL', null);
    }

    /**
     * One row per verification of a repair: which checks ran and what each said, what the repair
     * should have left true, the evidence before and after, the errors met, and the answer.
     */
    private function createVerificationsTable(): void
    {
        $this->createTable(VerificationRecord::TABLE, [
            'id' => $this->primaryKey(),
            'repairId' => $this->integer()->notNull(),
            'issueId' => $this->integer(),
            // The check and the repair action as they were verified, so the row still reads after
            // either has changed.
            'diagnosticId' => $this->string(100)->notNull(),
            'action' => $this->string(100)->notNull(),
            'environment' => $this->string(255)->notNull(),
            'siteId' => $this->integer(),
            // The run the checks made, which is the run the Issue Center records their findings under.
            'runId' => $this->string(36)->notNull(),
            'result' => $this->string(32)->notNull(),
            'failures' => $this->text(),
            // Each check as it answered, the conditions as they were read, the evidence before and
            // after, and the errors: redacted and bounded as evidence is, so medium text holds them.
            'checks' => $this->mediumText(),
            'conditions' => $this->text(),
            'originalState' => $this->mediumText(),
            'currentState' => $this->mediumText(),
            'comparison' => $this->text(),
            'errors' => $this->text(),
            'verifiedBy' => $this->integer(),
            'startedAt' => $this->dateTime()->notNull(),
            'finishedAt' => $this->dateTime()->notNull(),
            'durationMs' => $this->float(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // A repair's verifications, newest first, and their pruning.
        $this->createIndex(null, VerificationRecord::TABLE, ['repairId', 'startedAt']);
        $this->createIndex(null, VerificationRecord::TABLE, ['issueId']);
        $this->createIndex(null, VerificationRecord::TABLE, ['siteId']);

        // A verification says something only about its repair, so it goes with it; repairs are
        // never deleted by Web Doctor, so in practice it stays. It outlives its issue as the repair
        // does.
        $this->addForeignKey(null, VerificationRecord::TABLE, ['repairId'], RepairRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, VerificationRecord::TABLE, ['issueId'], IssueRecord::TABLE, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, VerificationRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, VerificationRecord::TABLE, ['verifiedBy'], Table::USERS, ['id'], 'SET NULL', null);
    }
}
