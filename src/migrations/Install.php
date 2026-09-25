<?php

namespace Tahadudhiya\WebDoctor\migrations;

use craft\db\Migration;
use craft\db\Table;
use Tahadudhiya\WebDoctor\records\EvidenceRecord;
use Tahadudhiya\WebDoctor\records\IssueEventRecord;
use Tahadudhiya\WebDoctor\records\IssueRecord;

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

        return true;
    }

    public function safeDown(): bool
    {
        // Evidence and events first: they point at issues, and dropping the target of a foreign
        // key before the key itself leaves the table that holds it unusable.
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
            // The last result, reduced to what the control panel shows. Evidence is kept as its
            // type and label only: keeping contents no page displays is how a payload ends up
            // somewhere it was never meant to go.
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
}
