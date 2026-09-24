<?php

namespace Tahadudhiya\WebDoctor\diagnostics\database;

use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Whether the database schema matches the code that is running against it.
 *
 * This is the state a half-finished deployment leaves behind: new code on disk, old tables in
 * the database. Craft keeps serving, and the failures that follow point at everything except
 * the cause — so the mismatch is named directly, and the code that owns each pending migration
 * is named with it.
 *
 * The reverse case is treated as more serious than pending migrations. A schema newer than the
 * code means the code was rolled back and the database was not, and running the old code
 * against the new tables can lose data rather than merely fail.
 */
class MigrationsDiagnostic extends Diagnostic
{
    public const ID = 'database.migrations';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Database migrations');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::DATABASE;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks whether Craft or any plugin has migrations waiting to run, and whether the schema is newer than the code running against it.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            ['schemaCompatible' => $schemaCompatible, 'pending' => $pending] = $this->state();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The migration state could not be read from the database.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $evidence = [
            $this->evidence(EvidenceType::CONFIGURATION, Craft::t('web-doctor', 'Migration state'), [
                'schemaVersionCompatible' => $schemaCompatible,
                'pendingMigrations' => $pending,
                'codeSchemaVersion' => Craft::$app->schemaVersion,
                // Content migrations are included: a deployment that half-ran leaves those
                // behind too, and leaving them out would report the site as up to date.
                'includesContentMigrations' => true,
            ]),
        ];

        if (!$schemaCompatible) {
            return $this->fail(
                Craft::t('web-doctor', 'The database schema is newer than the Craft code running against it.'),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Deploy the Craft version this database was migrated to, or restore a backup taken before the migration ran.'),
                severity: Severity::CRITICAL,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'This is what a rolled-back deployment leaves behind. Older code writing to newer tables can lose data rather than simply fail.'),
            );
        }

        if ($pending !== []) {
            return $this->fail(
                Craft::t('web-doctor', 'Migrations are waiting to run: {handles}.', ['handles' => implode(', ', $pending)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Run `php craft up` to apply the pending migrations and project config changes.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'The code expects tables and columns the database does not have yet, so failures elsewhere in this run may be consequences of it.'),
            );
        }

        return $this->pass(Craft::t('web-doctor', 'The database schema matches the code running against it.'), $evidence);
    }

    /**
     * The migration state, through Craft's own update service rather than by reading migration
     * tables — Craft owns this question and reimplementing it would produce a second answer.
     *
     * Protected so a test can state a half-deployed installation rather than needing one.
     *
     * @return array{schemaCompatible: bool, pending: string[]}
     * @throws Throwable where the database holding the answer is out of reach.
     */
    protected function state(): array
    {
        $updates = Craft::$app->getUpdates();

        return [
            'schemaCompatible' => $updates->getIsCraftSchemaVersionCompatible(),
            'pending' => $updates->getPendingMigrationHandles(true),
        ];
    }
}
