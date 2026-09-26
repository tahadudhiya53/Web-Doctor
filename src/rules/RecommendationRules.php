<?php

namespace Tahadudhiya\WebDoctor\rules;

use Closure;
use Craft;
use Tahadudhiya\WebDoctor\diagnostics\database\CharsetDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueBacklogDiagnostic;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Observation;
use Tahadudhiya\WebDoctor\models\RecommendationCase;

/**
 * The single list of recommendations.
 *
 * Every piece of advice Web Doctor gives is written out here with what selects it, the risk of
 * following it and why, what has to be in place first, and how to tell whether it worked — so the
 * same finding always gets the same advice, and none is composed on the spot. A finding no rule
 * answers gets none, and the page says so rather than inventing some.
 *
 * Advice for a check's findings is selected by the evidence that check records, by the keys it
 * records it under, and is listed in the order the check itself decides between them: the first
 * rule for a check that finds what it looks for is the one given. Advice for a known cause is given
 * as well, ahead of it, once an investigation holds that cause firmly enough to act on.
 */
final class RecommendationRules
{
    /**
     * @return list<RecommendationRule>
     */
    public static function all(): array
    {
        return [...self::forCauses(), ...self::forFindings()];
    }

    /**
     * @return list<RecommendationRule>
     */
    private static function forFindings(): array
    {
        $backup = self::backup();
        $retrySafety = Craft::t('web-doctor', 'Each job is safe to run again: a job that sends email, calls another service or charges a payment may have done part of its work before it failed.');

        return [
            // Craft ---------------------------------------------------------------

            RecommendationRule::forFinding(
                id: 'craft.settleDatabaseBeforeInstalling',
                check: 'craft.application',
                title: Craft::t('web-doctor', 'Establish which database this is before installing'),
                explanation: Craft::t('web-doctor', 'Craft’s own tables are not in the database this environment connects to. Either Craft was never installed against it, or it is not the database this site belongs to.'),
                action: Craft::t('web-doctor', 'Check which database this environment’s settings name. If it is the wrong one, correct the settings. If it is the right one and it should hold a site, restore it from a backup. Run `php craft install` only for a genuinely new installation.'),
                rationale: Craft::t('web-doctor', 'The application state records Craft as not installed. Installing into a database that ought to hold a site would start the site again, empty, so which database it is has to be settled first.'),
                risk: RepairRisk::HIGH,
                riskReason: Craft::t('web-doctor', 'Installing or restoring writes the whole database; done against the wrong one, it replaces a site.'),
                verification: Craft::t('web-doctor', 'The check reports Craft installed, and the connection and migration checks find the schema the code expects.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::SYSTEM, static fn(Evidence $e): bool => $e->get('installed') === false, ['installed']),
                prerequisites: [
                    Craft::t('web-doctor', 'Know which database this environment is meant to use.'),
                    $backup,
                ],
                alsoVerifyWith: ['database.connection', 'database.migrations'],
            ),
            RecommendationRule::forFinding(
                id: 'craft.finishInterruptedUpdate',
                check: 'craft.application',
                title: Craft::t('web-doctor', 'Finish or roll back the interrupted update'),
                explanation: Craft::t('web-doctor', 'Craft sets maintenance mode while it updates and clears it when the update finishes, so still being in it usually means an update stopped part way, unless somebody switched it on deliberately.'),
                action: Craft::t('web-doctor', 'Find out which update was running from Craft’s logs, then finish it with `php craft up`, or restore the backup taken before it started. Craft clears maintenance mode once an update completes.'),
                rationale: Craft::t('web-doctor', 'The application state records maintenance mode as on. Clearing the flag alone would put the site back in front of visitors with the update still half applied.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Finishing the update runs migrations; restoring a backup discards what was written since it was taken.'),
                verification: Craft::t('web-doctor', 'The check no longer reports maintenance mode, and no migration is left waiting.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::SYSTEM, static fn(Evidence $e): bool => $e->get('maintenanceMode') === true, ['maintenanceMode']),
                prerequisites: [$backup],
                alsoVerifyWith: ['database.migrations'],
            ),
            RecommendationRule::forFinding(
                id: 'craft.redoSkippedBreakpoint',
                check: 'craft.version',
                title: Craft::t('web-doctor', 'Redo the update through the version it skipped'),
                explanation: Craft::t('web-doctor', 'Craft had to pass through an intermediate version on its way here and did not, so the database is in a state no migration path accounts for.'),
                action: Craft::t('web-doctor', 'Restore the database from a backup taken before the update, update to the intermediate version the check names, then update again.'),
                rationale: Craft::t('web-doctor', 'The check found the upgrade breakpoint skipped. Running further migrations on top would build on a schema Craft never expected, so the way forward starts from before the update.'),
                risk: RepairRisk::HIGH,
                riskReason: Craft::t('web-doctor', 'Restoring a backup discards everything written since it was taken.'),
                verification: Craft::t('web-doctor', 'The check no longer reports a skipped breakpoint, and no migration is left waiting.'),
                match: static fn(RecommendationCase $c): array => [$c->observeFinding(), ...$c->where(EvidenceType::CRAFT_VERSION, static fn(Evidence $e): bool => true, ['version', 'schemaVersion'])],
                prerequisites: [
                    Craft::t('web-doctor', 'A database backup taken before the update.'),
                    Craft::t('web-doctor', 'The intermediate Craft version the check names, ready to deploy.'),
                ],
                alsoVerifyWith: ['database.migrations'],
            ),

            // PHP -----------------------------------------------------------------

            RecommendationRule::forFinding(
                id: 'php.moveToSupportedVersion',
                check: 'php.version',
                title: Craft::t('web-doctor', 'Move to a PHP version Craft supports'),
                explanation: Craft::t('web-doctor', 'Craft is not supported on the PHP version running here, so failures anywhere else may be explained by it alone.'),
                action: Craft::t('web-doctor', 'Switch this environment to a PHP version that meets Craft’s requirement, for the web server and the command line alike, and restart PHP.'),
                rationale: Craft::t('web-doctor', 'The PHP version recorded does not satisfy the constraint Craft declares. Nothing else can be judged reliably on an unsupported runtime.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'A new PHP version changes the runtime for every plugin and extension on the server; try it on a copy of the site first.'),
                verification: Craft::t('web-doctor', 'The check reports a supported version, and the extension and configuration checks pass on the new version.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::PHP_VERSION, static fn(Evidence $e): bool => true, ['version', 'sapi', 'required']),
                prerequisites: [
                    Craft::t('web-doctor', 'The new PHP version installed, with the extensions Craft requires.'),
                    Craft::t('web-doctor', 'The plugins in use support that version.'),
                ],
                alsoVerifyWith: ['php.extensions', 'php.configuration'],
            ),
            RecommendationRule::forFinding(
                id: 'php.installExtensions',
                check: 'php.extensions',
                title: Craft::t('web-doctor', 'Install the missing PHP extensions'),
                explanation: Craft::t('web-doctor', 'Craft marks these extensions as required, so parts of it fail outright without them.'),
                action: Craft::t('web-doctor', 'Install and enable the extensions the check names for the PHP that runs Craft — the web server and the command line — and restart PHP.'),
                rationale: Craft::t('web-doctor', 'The check recorded required extensions as not loaded.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Adding an extension changes nothing already loaded; PHP has to be restarted, which briefly interrupts requests.'),
                verification: Craft::t('web-doctor', 'The check finds every required extension loaded.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::CONFIGURATION, static fn(Evidence $e): bool => is_array($e->get('missing')) && $e->get('missing') !== [], ['sapi', 'missing']),
                prerequisites: [self::serverAccess()],
            ),
            RecommendationRule::forFinding(
                id: 'php.keepDocblockComments',
                check: 'php.configuration',
                title: Craft::t('web-doctor', 'Let the opcode cache keep docblock comments'),
                explanation: Craft::t('web-doctor', 'Craft and its plugins read docblocks while running, so an opcode cache that discards them makes them fail in ways that do not look like a PHP setting.'),
                action: Craft::t('web-doctor', 'Set opcache.save_comments to 1 for the PHP that runs Craft and restart PHP.'),
                rationale: Craft::t('web-doctor', 'The check recorded opcache.save_comments as off with the opcode cache enabled.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'One setting; restarting PHP briefly interrupts requests.'),
                verification: Craft::t('web-doctor', 'The check finds docblock comments kept.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::CONFIGURATION, static fn(Evidence $e): bool => $e->get('opcache.save_comments') === false, ['sapi', 'opcache.save_comments']),
                prerequisites: [self::serverAccess()],
            ),
            RecommendationRule::forFinding(
                id: 'php.raiseLimits',
                check: 'php.configuration',
                title: Craft::t('web-doctor', 'Raise the PHP limits the check names'),
                explanation: Craft::t('web-doctor', 'PHP is set to limits that will cut Craft short: too little memory, too little time for an update, or uploads cut off before they finish.'),
                action: Craft::t('web-doctor', 'Adjust the settings the check names in the php.ini of the PHP that runs Craft, and restart it.'),
                rationale: Craft::t('web-doctor', 'The check recorded these settings below what Craft asks for.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Raising a limit only lets PHP do more of what it already does; restarting PHP briefly interrupts requests.'),
                verification: Craft::t('web-doctor', 'The check finds the settings within range.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::CONFIGURATION, static fn(Evidence $e): bool => $e->get('memory_limit') !== null, ['sapi', 'memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size']),
                prerequisites: [Craft::t('web-doctor', 'Know which php.ini the web server and the command line each read: they are often different files.')],
            ),

            // Database ------------------------------------------------------------

            RecommendationRule::forFinding(
                id: 'database.establishConnection',
                check: 'database.connection',
                title: Craft::t('web-doctor', 'Find out why the connection fails before changing anything'),
                explanation: Craft::t('web-doctor', 'Craft could not open its database connection, so nothing that reads or writes data can work, and other findings may follow from it.'),
                action: Craft::t('web-doctor', 'Read the exception the check recorded. Then check that the database server is running, and that the host, port, database name, user name and password this environment gives Craft are right.'),
                rationale: Craft::t('web-doctor', 'The connection attempt was recorded as failing. Its exception says whether the server refused the login or could not be reached, and the two are fixed in different places.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Checking the server and the settings changes nothing; correcting a setting affects only this environment’s connection.'),
                verification: Craft::t('web-doctor', 'The check connects, and the checks that read the database reach a conclusion again.'),
                match: static fn(RecommendationCase $c): array => [
                    ...$c->where(EvidenceType::DATABASE_ERROR, static fn(Evidence $e): bool => $e->get('succeeded') === false, ['succeeded']),
                    ...$c->where(EvidenceType::EXCEPTION, static fn(Evidence $e): bool => true, ['message']),
                ],
                prerequisites: [Craft::t('web-doctor', 'Access to this environment’s database settings, usually its .env file.')],
                alsoVerifyWith: ['database.migrations', 'database.charset'],
            ),
            RecommendationRule::forFinding(
                id: 'database.upgradeServer',
                check: 'database.connection',
                title: Craft::t('web-doctor', 'Upgrade the database server'),
                explanation: Craft::t('web-doctor', 'The database server is older than the version Craft requires.'),
                action: Craft::t('web-doctor', 'Upgrade the database server to the version the check names, or move the database to a server that meets it.'),
                rationale: Craft::t('web-doctor', 'The check connected and recorded the server’s version below Craft’s minimum.'),
                risk: RepairRisk::HIGH,
                riskReason: Craft::t('web-doctor', 'Upgrading a database server is maintenance on everything it holds, usually with downtime.'),
                verification: Craft::t('web-doctor', 'The check reports a server version Craft supports.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::DATABASE, static fn(Evidence $e): bool => $e->get('succeeded') === true && $e->get('required') !== null, ['driver', 'serverVersion', 'required']),
                prerequisites: [$backup, Craft::t('web-doctor', 'A maintenance window agreed with whoever relies on the site.')],
                alsoVerifyWith: ['database.charset'],
            ),
            RecommendationRule::forFinding(
                id: 'database.deployMigratedVersion',
                check: 'database.migrations',
                title: Craft::t('web-doctor', 'Deploy the Craft version the database was migrated to'),
                explanation: Craft::t('web-doctor', 'The database schema is newer than the code running against it — what a rolled-back deployment leaves behind. Older code writing to newer tables can lose data.'),
                action: Craft::t('web-doctor', 'Deploy the Craft version this database was migrated to, or restore a backup taken before the migration ran. Do not run migrations to make the two agree: migrations only move forward.'),
                rationale: Craft::t('web-doctor', 'The check recorded the stored schema version as incompatible with the code’s.'),
                risk: RepairRisk::HIGH,
                riskReason: Craft::t('web-doctor', 'Restoring a backup discards what was written since; leaving it as it is risks older code damaging newer tables.'),
                verification: Craft::t('web-doctor', 'The check finds the schema and the code in agreement.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::DATABASE, static fn(Evidence $e): bool => $e->get('schemaVersionCompatible') === false, ['schemaVersionCompatible', 'codeSchemaVersion']),
                prerequisites: [$backup, Craft::t('web-doctor', 'Know which Craft version last migrated this database — usually the one deployed before the rollback.')],
                alsoVerifyWith: ['craft.version'],
            ),
            RecommendationRule::forFinding(
                id: 'database.runPendingMigrations',
                check: 'database.migrations',
                title: Craft::t('web-doctor', 'Run the pending migrations'),
                explanation: Craft::t('web-doctor', 'The code expects tables and columns the database does not have yet.'),
                action: Craft::t('web-doctor', 'Run `php craft up` in this environment. It applies pending migrations and then pending project config changes, in the order Craft needs.'),
                rationale: Craft::t('web-doctor', 'The check recorded migrations waiting to run. `php craft up` is Craft’s own way of applying them, rather than running each one by hand.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Migrations change the schema and can change content; they are meant to run, but cannot be undone without a backup.'),
                verification: Craft::t('web-doctor', 'The check finds no migration waiting, and no project config change is left pending.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::DATABASE, static fn(Evidence $e): bool => is_array($e->get('pendingMigrations')) && $e->get('pendingMigrations') !== [], ['pendingMigrations']),
                prerequisites: [$backup],
                alsoVerifyWith: ['projectConfig.pendingChanges'],
            ),
            RecommendationRule::forFinding(
                id: 'database.convertToUtf8mb4',
                check: 'database.charset',
                title: Craft::t('web-doctor', 'Convert the database to utf8mb4'),
                explanation: Craft::t('web-doctor', 'The table Craft samples does not accept four-byte characters such as emoji, so content containing them fails to save or is stored mangled.'),
                action: Craft::t('web-doctor', 'Take a backup, convert the tables with Craft’s `php craft db/convert-charset`, and set utf8mb4 as the charset in this environment’s database settings.'),
                rationale: Craft::t('web-doctor', 'The check recorded the sampled table as not accepting four-byte characters. Craft’s own command converts every table the same way, where converting by hand leaves a mixture.'),
                risk: RepairRisk::HIGH,
                riskReason: Craft::t('web-doctor', 'Converting rewrites every table; on a large database it takes time and locks tables while it runs.'),
                verification: Craft::t('web-doctor', 'The check finds the sampled table accepting four-byte characters.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::DATABASE, static fn(Evidence $e): bool => $e->get('sampledTableAcceptsMb4') === false, ['sampledTable', 'sampledTableAcceptsMb4']),
                prerequisites: [$backup, Craft::t('web-doctor', 'A maintenance window agreed with whoever relies on the site.')],
            ),
            RecommendationRule::forFinding(
                id: 'database.alignCharset',
                check: 'database.charset',
                title: Craft::t('web-doctor', 'Bring the database and Craft’s charset setting into line'),
                explanation: Craft::t('web-doctor', 'The database is set to one character set and Craft is configured for another, so tables created from now on will not match the ones already there.'),
                action: Craft::t('web-doctor', 'Decide which character set is intended. Either convert the database to the one Craft is configured for, or configure Craft for the one the database uses.'),
                rationale: Craft::t('web-doctor', 'The check recorded the database’s character set and the configured one as different.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Changing the setting affects only tables created later; converting the database rewrites the tables it already has.'),
                verification: Craft::t('web-doctor', 'The check finds the database set to the configured character set.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::DATABASE, static fn(Evidence $e): bool => is_string($e->get('databaseCharset')) && is_string($e->get('configuredCharset')) && !CharsetDiagnostic::sameCharset($e->get('databaseCharset'), $e->get('configuredCharset')), ['databaseCharset', 'configuredCharset']),
                prerequisites: [$backup],
            ),

            // Plugins -------------------------------------------------------------

            RecommendationRule::forFinding(
                id: 'plugins.reinstateOrUninstall',
                check: 'plugins.health',
                title: Craft::t('web-doctor', 'Reinstate each missing plugin, or uninstall it properly'),
                explanation: Craft::t('web-doctor', 'These plugins are recorded as installed but their code is gone, leaving their tables, content and project config entries with nothing to interpret them.'),
                action: Craft::t('web-doctor', 'For each plugin, decide whether it is still wanted. If it is, reinstate its package with Composer at the version that was installed. If not, reinstate it long enough to uninstall it through Craft. Do not delete its tables or rows by hand.'),
                rationale: Craft::t('web-doctor', 'The check recorded these handles in the database with no code in the project. Uninstalling through Craft is what removes a plugin’s data and configuration together.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Uninstalling removes the plugin’s tables and the content in them; reinstating changes the code deployed.'),
                verification: Craft::t('web-doctor', 'The check finds no plugin recorded without its code.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::PLUGIN, static fn(Evidence $e): bool => is_array($e->get('missingFromProject')) && $e->get('missingFromProject') !== [], ['missingFromProject']),
                prerequisites: [$backup, Craft::t('web-doctor', 'Know which version of each plugin was installed — the project’s composer.lock history records it.')],
            ),
            RecommendationRule::forFinding(
                id: 'plugins.findLoadFailure',
                check: 'plugins.health',
                title: Craft::t('web-doctor', 'Find out why each plugin failed to load'),
                explanation: Craft::t('web-doctor', 'Craft leaves out a plugin it cannot initialise rather than failing the request, so everything the plugin provides is simply absent.'),
                action: Craft::t('web-doctor', 'Read Craft’s logs for the exception thrown while each plugin initialised, and check that its package is installed at a version compatible with this Craft and PHP. Web Doctor does not read logs.'),
                rationale: Craft::t('web-doctor', 'The check recorded these plugins as switched on but not loaded. Why is in the exception Craft logged, which is where the fix starts.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Reading logs and versions changes nothing.'),
                verification: Craft::t('web-doctor', 'The check finds every plugin that is switched on loaded.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::PLUGIN, static fn(Evidence $e): bool => is_array($e->get('failedToLoad')) && $e->get('failedToLoad') !== [], ['failedToLoad']),
                prerequisites: [Craft::t('web-doctor', 'Access to Craft’s logs for this environment.')],
                alsoVerifyWith: ['plugins.installed'],
            ),
            RecommendationRule::forFinding(
                id: 'plugins.resolveLicensing',
                check: 'plugins.health',
                title: Craft::t('web-doctor', 'Resolve the plugins’ licensing'),
                explanation: Craft::t('web-doctor', 'These plugins have licensing problems Craft reports the same way everywhere. Licensing does not stop a plugin working.'),
                action: Craft::t('web-doctor', 'Resolve each one under Settings → Plugins in the control panel.'),
                rationale: Craft::t('web-doctor', 'The check recorded licensing problems against these handles.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Assigning or correcting a licence changes no data.'),
                verification: Craft::t('web-doctor', 'The check finds no licensing problem.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::PLUGIN, static fn(Evidence $e): bool => is_array($e->get('licensing')) && $e->get('licensing') !== [], ['licensing']),
            ),

            // Queue ---------------------------------------------------------------

            RecommendationRule::forFinding(
                id: 'queue.startRunner',
                check: 'queue.backlog',
                title: Craft::t('web-doctor', 'Get something running the queue'),
                explanation: Craft::t('web-doctor', 'Jobs are waiting past the point a working queue would have run them, so search indexing, email and image transforms are quietly not happening.'),
                action: Craft::t('web-doctor', 'Make sure something runs the queue here — a worker process, a cron entry calling `php craft queue/run`, or Craft’s automatic runner — and that it is not failing as it starts.'),
                rationale: Craft::t('web-doctor', 'The check recorded the oldest waiting job past the point the queue counts as stalled.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Starting a runner runs jobs that are already meant to run; nothing is skipped or repeated.'),
                verification: Craft::t('web-doctor', 'The check finds the backlog clearing, and the failed-jobs check shows whether the jobs that now run succeed.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::QUEUE, static fn(Evidence $e): bool => (int)$e->get('waiting', 0) > 0 && (int)$e->get('oldestWaitingSeconds', 0) >= QueueBacklogDiagnostic::STALLED_AFTER, ['waiting', 'oldestWaitingSeconds', 'running', 'runQueueAutomatically']),
                prerequisites: [self::serverAccess()],
                alsoVerifyWith: ['queue.failedJobs'],
            ),
            RecommendationRule::forFinding(
                id: 'queue.checkOverrunningJob',
                check: 'queue.backlog',
                title: Craft::t('web-doctor', 'Find out whether the overrunning job is still working'),
                explanation: Craft::t('web-doctor', 'A running job has gone past the time it allows itself: either it needs longer than it declares, or the worker running it went away.'),
                action: Craft::t('web-doctor', 'Check whether a worker is still running it, from the worker’s own log and Utilities → Queue Manager, before releasing or restarting it. Craft releases an overrun job for retry once something runs the queue again.'),
                rationale: Craft::t('web-doctor', 'The check recorded the longest-running job past its own time limit.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Releasing a job that is still running can make its work happen twice.'),
                verification: Craft::t('web-doctor', 'The check finds no job running past its limit.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::QUEUE, static fn(Evidence $e): bool => is_numeric($e->get('longestRunningSeconds')) && is_numeric($e->get('longestRunningTimeLimit')) && (int)$e->get('longestRunningTimeLimit') > 0 && (int)$e->get('longestRunningSeconds') > (int)$e->get('longestRunningTimeLimit'), ['running', 'longestRunningSeconds', 'longestRunningTimeLimit']),
            ),
            RecommendationRule::forFinding(
                id: 'queue.watchBacklog',
                check: 'queue.backlog',
                title: Craft::t('web-doctor', 'Confirm the backlog is clearing'),
                explanation: Craft::t('web-doctor', 'The queue is moving, but a large number of jobs is waiting.'),
                action: Craft::t('web-doctor', 'Run the check again in a while. If the number waiting is not falling, give the queue more workers, or find what is producing the work.'),
                rationale: Craft::t('web-doctor', 'The check recorded a large backlog with the queue still running jobs.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Watching changes nothing; adding workers runs jobs that are already meant to run.'),
                verification: Craft::t('web-doctor', 'The check finds the number waiting back to normal.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::QUEUE, static fn(Evidence $e): bool => (int)$e->get('waiting', 0) > 0, ['waiting', 'running']),
            ),
            RecommendationRule::forFinding(
                id: 'queue.raiseMemoryThenRetry',
                check: 'queue.failedJobs',
                title: Craft::t('web-doctor', 'Give the queue more memory, then retry'),
                explanation: Craft::t('web-doctor', 'PHP stopped jobs part-way because they needed more memory than they were allowed. A job that failed this way fails the same way when it is retried as things stand.'),
                action: Craft::t('web-doctor', 'Raise memory_limit for the PHP that runs the queue, or reduce what the job processes at once. Then retry the failed jobs from Utilities → Queue Manager.'),
                rationale: Craft::t('web-doctor', 'A failed job’s recorded error is PHP running out of memory, so retrying before the limit changes would only repeat the failure.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Retrying runs the job again, so anything it did before failing may happen twice.'),
                verification: Craft::t('web-doctor', 'The check finds no failed jobs once the retried ones have run, and the PHP configuration check shows the new limit.'),
                match: static fn(RecommendationCase $c): array => self::jobs($c, static fn(Evidence $e): bool => is_string($e->get('error')) && preg_match(RootCauseRules::OUT_OF_MEMORY, $e->get('error')) === 1),
                prerequisites: [Craft::t('web-doctor', 'The memory limit raised for the command-line PHP too, where the queue runs from a worker or cron.'), $retrySafety],
                alsoVerifyWith: ['php.configuration'],
            ),
            RecommendationRule::forFinding(
                id: 'queue.fixRepeatedFailure',
                check: 'queue.failedJobs',
                title: Craft::t('web-doctor', 'Fix what the repeatedly failing job names before retrying it'),
                explanation: Craft::t('web-doctor', 'The same job has failed more than once, which points at a condition that has not gone away rather than at a one-off.'),
                action: Craft::t('web-doctor', 'Read the recorded error for the job that keeps failing and fix what it names. Retry it only then; until the condition is gone, a retry fails the same way.'),
                rationale: Craft::t('web-doctor', 'The check recorded a job failing repeatedly with its error.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Retrying runs the job again, so anything it did before failing may happen twice.'),
                verification: Craft::t('web-doctor', 'The check finds no failed jobs once the retried ones have run.'),
                match: static fn(RecommendationCase $c): array => self::jobs($c, static fn(Evidence $e): bool => (int)$e->get('occurrences', 1) > 1),
                prerequisites: [$retrySafety],
            ),
            RecommendationRule::forFinding(
                id: 'queue.inspectThenRetry',
                check: 'queue.failedJobs',
                title: Craft::t('web-doctor', 'Read each failed job’s error, and retry only when it is safe'),
                explanation: Craft::t('web-doctor', 'Work the site was asked to do has not been done, and Craft does not retry a failed job on its own.'),
                action: Craft::t('web-doctor', 'Open Utilities → Queue Manager and read the error recorded for each failed job. Retry a job once what its error names has been fixed and it is safe to run again; release one that should not run at all.'),
                rationale: Craft::t('web-doctor', 'The check recorded failed jobs. Why each failed is in its recorded error, and retrying without reading it repeats whatever went wrong.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Retrying runs the job again, so anything it did before failing may happen twice; releasing it discards the work.'),
                verification: Craft::t('web-doctor', 'The check finds no failed jobs once the retried ones have run.'),
                match: static fn(RecommendationCase $c): array => [
                    ...$c->where(EvidenceType::QUEUE, static fn(Evidence $e): bool => (int)$e->get('failed', 0) > 0, ['failed', 'examined']),
                    ...self::jobs($c, static fn(Evidence $e): bool => true),
                ],
                prerequisites: [
                    Craft::t('web-doctor', 'Permission to see job errors: Craft shows them in development mode or to an administrator.'),
                    $retrySafety,
                ],
            ),

            // Filesystems and storage ---------------------------------------------

            RecommendationRule::forFinding(
                id: 'filesystem.reconnectVolume',
                check: 'filesystem.volumes',
                title: Craft::t('web-doctor', 'Reconnect each volume to the filesystem its files are on'),
                explanation: Craft::t('web-doctor', 'Craft keeps the volume and its asset records, so the assets appear to exist while no file behind them can be read.'),
                action: Craft::t('web-doctor', 'Verify the filesystem: under Settings → Filesystems, point each volume at the filesystem that holds its files, or restore the one it used. Then verify the asset records by opening a few of the volume’s assets. Do not delete the volume or its assets to clear the finding.'),
                rationale: Craft::t('web-doctor', 'The check recorded these volumes with no filesystem behind them. The asset records are intact and hold the assets’ content and relations; deleting them cannot be undone, and reconnecting the files makes them whole again.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Pointing a volume at the wrong filesystem makes every asset on it read the wrong files.'),
                verification: Craft::t('web-doctor', 'The check finds a filesystem behind every volume and reaches it.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::FILESYSTEM, static fn(Evidence $e): bool => $e->get('state') === 'missing', ['volume', 'filesystem', 'state', 'reason']),
                prerequisites: [Craft::t('web-doctor', 'Know which filesystem — bucket, path or service — holds each volume’s files.')],
            ),
            RecommendationRule::forFinding(
                id: 'filesystem.restoreAccess',
                check: 'filesystem.volumes',
                title: Craft::t('web-doctor', 'Restore access to the filesystem before touching any asset'),
                explanation: Craft::t('web-doctor', 'The filesystem behind these volumes could not be reached, which makes every file on it look missing.'),
                action: Craft::t('web-doctor', 'Verify the filesystem first: the credentials, bucket or path it is configured with, and that this environment can reach it. Then verify the asset records by opening a few of the volume’s assets. Do not delete asset records because their files cannot be read.'),
                rationale: Craft::t('web-doctor', 'The check recorded these volumes as unreachable. The files are more likely out of reach than gone, and an asset deleted now would be lost once access returns.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Checking settings and access changes nothing; correcting credentials affects only this environment.'),
                verification: Craft::t('web-doctor', 'The check reaches every volume.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::FILESYSTEM, static fn(Evidence $e): bool => $e->get('state') === 'unreachable', ['volume', 'filesystem', 'state', 'reason']),
            ),
            RecommendationRule::forFinding(
                id: 'storage.grantWriteAccess',
                check: 'storage.paths',
                title: Craft::t('web-doctor', 'Give PHP write access to Craft’s storage directories'),
                explanation: Craft::t('web-doctor', 'Craft writes compiled templates, caches and logs here, so the failures this causes appear everywhere except where the cause is.'),
                action: Craft::t('web-doctor', 'Give the user PHP runs as write access to the directories the check names, and make sure no file is sitting where a directory belongs.'),
                rationale: Craft::t('web-doctor', 'The check recorded these directories as not writable, with the user PHP runs as. A deployment that copies files as a different user is the usual cause.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Changing ownership or permissions on the wrong path can expose files or break other sites on the server.'),
                verification: Craft::t('web-doctor', 'The check finds every storage directory writable.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::FILESYSTEM, static fn(Evidence $e): bool => is_array($e->get('states')) && array_intersect($e->get('states'), ['notWritable', 'notADirectory']) !== [], ['states', 'user']),
                prerequisites: [self::serverAccess()],
            ),
            RecommendationRule::forFinding(
                id: 'storage.createDirectories',
                check: 'storage.paths',
                title: Craft::t('web-doctor', 'Create the missing storage directories'),
                explanation: Craft::t('web-doctor', 'Craft creates these on demand, so this becomes a failure only when it cannot.'),
                action: Craft::t('web-doctor', 'Create the directories the check names, or make sure their parent is writable so Craft can create them itself.'),
                rationale: Craft::t('web-doctor', 'The check recorded these directories as missing.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Creating an empty directory changes nothing that exists.'),
                verification: Craft::t('web-doctor', 'The check finds every storage directory present and writable.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::FILESYSTEM, static fn(Evidence $e): bool => is_array($e->get('states')) && in_array('missing', $e->get('states'), true), ['states', 'user']),
            ),

            // Email, environment and project config ------------------------------

            RecommendationRule::forFinding(
                id: 'email.chooseTransport',
                check: 'email.configuration',
                title: Craft::t('web-doctor', 'Choose a mail transport Craft can build'),
                explanation: Craft::t('web-doctor', 'Craft could not build the configured mail transport and falls back to PHP’s own mail function, so mail may be leaving by a route nobody chose, or not at all.'),
                action: Craft::t('web-doctor', 'Choose a transport under Settings → Email, or reinstate the plugin that provided this one.'),
                rationale: Craft::t('web-doctor', 'The check recorded the exception thrown while building the transport.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Changing the transport affects only how mail leaves; nothing stored changes.'),
                verification: Craft::t('web-doctor', 'The check builds the transport and finds the settings complete. Delivery itself is not tested.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::EXCEPTION, static fn(Evidence $e): bool => true),
                prerequisites: [Craft::t('web-doctor', 'Credentials for the mail service to be used.')],
            ),
            RecommendationRule::forFinding(
                id: 'email.completeSettings',
                check: 'email.configuration',
                title: Craft::t('web-doctor', 'Complete the mail settings'),
                explanation: Craft::t('web-doctor', 'Mail Craft tries to send will fail, including the messages people need to get back into the site.'),
                action: Craft::t('web-doctor', 'Complete the settings the check names under Settings → Email, and define any environment variables they refer to in this environment.'),
                rationale: Craft::t('web-doctor', 'The check found settings that sending mail cannot do without missing.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Filling in a missing setting changes nothing that works now.'),
                verification: Craft::t('web-doctor', 'The check finds the settings complete. Delivery itself is not tested.'),
                // SMTP credentials are only a problem with authentication on, which the check judges
                // and its summary names; the evidence alone cannot say it, so it is quoted instead.
                match: static fn(RecommendationCase $c): array => [
                    ...self::missing($c, EvidenceType::CONFIGURATION, ['fromEmail']),
                    ...self::missing($c, EvidenceType::ENVIRONMENT_VARIABLE, ['host']),
                ] ?: [$c->observeFinding()],
                prerequisites: [Craft::t('web-doctor', 'Credentials for the mail service to be used.')],
            ),
            RecommendationRule::forFinding(
                id: 'environment.restoreSecurityKey',
                check: 'environment.configuration',
                title: Craft::t('web-doctor', 'Restore the original security key'),
                explanation: Craft::t('web-doctor', 'Craft encrypts stored credentials and signs data with this key, so without it those values cannot be read back.'),
                action: Craft::t('web-doctor', 'Set CRAFT_SECURITY_KEY to the key this installation’s data was encrypted with — from another environment of the same site, or wherever it was kept. Generate a new key only for a new site.'),
                rationale: Craft::t('web-doctor', 'The check recorded the security key as missing. A new key cannot read what the old one encrypted, so the original is the only fix that keeps existing data.'),
                risk: RepairRisk::HIGH,
                riskReason: Craft::t('web-doctor', 'Any other key leaves existing encrypted values unreadable, and data written under it has to be redone once the original is back.'),
                verification: Craft::t('web-doctor', 'The check finds the security key present.'),
                match: static fn(RecommendationCase $c): array => self::missing($c, EvidenceType::ENVIRONMENT_VARIABLE, ['CRAFT_SECURITY_KEY']),
                prerequisites: [Craft::t('web-doctor', 'The key this installation was using.')],
            ),
            RecommendationRule::forFinding(
                id: 'environment.disableDevMode',
                check: 'environment.configuration',
                title: Craft::t('web-doctor', 'Turn off development mode here'),
                explanation: Craft::t('web-doctor', 'Development mode shows errors and stack traces to visitors, and this environment is not named as a development one.'),
                action: Craft::t('web-doctor', 'Set CRAFT_DEV_MODE to false in this environment — or, if this is a development machine, name the environment so.'),
                rationale: Craft::t('web-doctor', 'The check recorded development mode on in an environment whose name does not say development.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Errors stop being shown to visitors; Craft still logs them.'),
                verification: Craft::t('web-doctor', 'The check finds development mode off, or the environment named as a development one.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::CONFIGURATION, static fn(Evidence $e): bool => $e->get('devMode') === true, ['environment', 'devMode']),
            ),
            RecommendationRule::forFinding(
                id: 'projectConfig.applyPending',
                check: 'projectConfig.pendingChanges',
                title: Craft::t('web-doctor', 'Apply the project config through Craft'),
                explanation: Craft::t('web-doctor', 'The database is running the previous configuration while the files describe a newer one, which shows up as missing fields and sections rather than anything naming project config.'),
                action: Craft::t('web-doctor', 'Apply the changes with Craft’s own command: `php craft up`, which runs pending migrations first, or `php craft project-config/apply`. Add `php craft up` to the deployment so it runs every time.'),
                rationale: Craft::t('web-doctor', 'The check recorded changes pending in the project config files. Craft’s command applies them in order and records what it applied; editing the database to match would bypass both.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Applying changes sections, fields and settings to match the files, overwriting anything changed here through the control panel since the files were written.'),
                verification: Craft::t('web-doctor', 'The check finds nothing pending, and the integrity check finds the files and the code on the same schema versions.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::PROJECT_CONFIG, static fn(Evidence $e): bool => $e->get('changesPending') === true, ['changesPending', 'allowAdminChanges']),
                prerequisites: [
                    Craft::t('web-doctor', 'The project config files deployed here are the ones intended for this environment.'),
                    $backup,
                ],
                alsoVerifyWith: ['projectConfig.integrity'],
            ),
            RecommendationRule::forFinding(
                id: 'projectConfig.alignSchemaVersions',
                check: 'projectConfig.integrity',
                title: Craft::t('web-doctor', 'Deploy the versions the project config files were written by'),
                explanation: Craft::t('web-doctor', 'Craft will not apply project config across a schema version difference, so the changes in these files are being skipped rather than failing loudly.'),
                action: Craft::t('web-doctor', 'Deploy the Craft and plugin versions the files were written by. Or, once this installation is the one that is correct, write the files from it with `php craft project-config/write`.'),
                rationale: Craft::t('web-doctor', 'The check recorded the schema versions in the files disagreeing with the code’s.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Writing the files replaces what they describe with this installation’s configuration, which other environments then apply.'),
                verification: Craft::t('web-doctor', 'The check finds the schema versions in agreement, and nothing is left pending.'),
                match: static fn(RecommendationCase $c): array => $c->where(EvidenceType::PROJECT_CONFIG, static fn(Evidence $e): bool => $e->get('compatible') === false, ['compatible', 'mismatches']),
                prerequisites: [Craft::t('web-doctor', 'Know which environment’s configuration is the one to keep.')],
                alsoVerifyWith: ['projectConfig.pendingChanges'],
            ),
        ];
    }

    /**
     * @return list<RecommendationRule>
     */
    private static function forCauses(): array
    {
        $backup = self::backup();

        return [
            RecommendationRule::forCause(
                id: 'database.correctCredentials',
                cause: 'database.credentialsRejected',
                title: Craft::t('web-doctor', 'Correct the database credentials'),
                rationale: Craft::t('web-doctor', 'The investigation weighed a refused login as the likely cause, from the evidence above.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Changing the password on the database server affects every environment and service using that account; changing this environment’s setting affects only this environment.'),
                verification: Craft::t('web-doctor', 'The connection check connects, and the issue’s own check no longer reports the problem.'),
                prerequisites: [Craft::t('web-doctor', 'Access to this environment’s database settings, usually its .env file.')],
                alsoVerifyWith: ['database.connection'],
            ),
            RecommendationRule::forCause(
                id: 'database.reachServer',
                cause: 'database.serverUnreachable',
                title: Craft::t('web-doctor', 'Get the database server answering'),
                rationale: Craft::t('web-doctor', 'The investigation weighed an unreachable server as the likely cause, from the evidence above.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Starting a server or correcting the host it is reached at restores something that is not working; nothing stored changes.'),
                verification: Craft::t('web-doctor', 'The connection check connects, and the issue’s own check no longer reports the problem.'),
                prerequisites: [self::serverAccess()],
                alsoVerifyWith: ['database.connection'],
            ),
            RecommendationRule::forCause(
                id: 'database.convertCharacterSet',
                cause: 'database.characterSet',
                title: Craft::t('web-doctor', 'Convert the database’s character set'),
                rationale: Craft::t('web-doctor', 'The investigation weighed the character set as the likely cause, from the evidence above.'),
                risk: RepairRisk::HIGH,
                riskReason: Craft::t('web-doctor', 'Converting rewrites every table; on a large database it takes time and locks tables while it runs.'),
                verification: Craft::t('web-doctor', 'The charset check finds four-byte characters accepted, and the issue’s own check no longer reports the problem.'),
                prerequisites: [$backup, Craft::t('web-doctor', 'A maintenance window agreed with whoever relies on the site.')],
                alsoVerifyWith: ['database.charset'],
            ),
            RecommendationRule::forCause(
                id: 'queue.startWorker',
                cause: 'queue.notProcessing',
                title: Craft::t('web-doctor', 'Start whatever runs the queue'),
                rationale: Craft::t('web-doctor', 'The investigation weighed a queue nothing is running as the likely cause, from the evidence above.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Starting a runner runs jobs that are already meant to run; nothing is skipped or repeated.'),
                verification: Craft::t('web-doctor', 'The backlog check finds the queue clearing, and the issue’s own check no longer reports the problem.'),
                prerequisites: [self::serverAccess()],
                alsoVerifyWith: ['queue.backlog', 'queue.failedJobs'],
            ),
            RecommendationRule::forCause(
                id: 'queue.raiseQueueMemory',
                cause: 'queue.outOfMemory',
                title: Craft::t('web-doctor', 'Give the queue more memory, then retry its jobs'),
                rationale: Craft::t('web-doctor', 'The investigation weighed jobs running out of memory as the likely cause, from the evidence above.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'Retrying runs the jobs again, so anything they did before failing may happen twice.'),
                verification: Craft::t('web-doctor', 'The failed-jobs check finds no failures once the retried jobs have run, and the issue’s own check no longer reports the problem.'),
                prerequisites: [Craft::t('web-doctor', 'Each job is safe to run again: a job that sends email, calls another service or charges a payment may have done part of its work before it failed.')],
                alsoVerifyWith: ['queue.failedJobs', 'php.configuration'],
            ),
            RecommendationRule::forCause(
                id: 'deployment.finish',
                cause: 'deployment.incomplete',
                title: Craft::t('web-doctor', 'Finish the deployment with `php craft up`'),
                rationale: Craft::t('web-doctor', 'The investigation weighed an unfinished deployment as the likely cause, from the evidence above.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'It runs migrations and applies project config; both change the database and cannot be undone without a backup.'),
                verification: Craft::t('web-doctor', 'The migration and project config checks find nothing waiting, and the issue’s own check no longer reports the problem.'),
                prerequisites: [$backup],
                alsoVerifyWith: ['database.migrations', 'projectConfig.pendingChanges'],
            ),
            RecommendationRule::forCause(
                id: 'place.inspect',
                cause: 'place.shared',
                title: Craft::t('web-doctor', 'Look at the plugin or component these problems share'),
                rationale: Craft::t('web-doctor', 'The investigation found several problems naming the same place, from the evidence above. That is a reason to look there first, not proof of a shared cause.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Looking changes nothing. Disabling or updating the plugin is a separate decision with its own risk.'),
                verification: Craft::t('web-doctor', 'The issue’s own check no longer reports the problem once whatever is found there is fixed.'),
            ),
            RecommendationRule::forCause(
                id: 'error.inspect',
                cause: 'error.shared',
                title: Craft::t('web-doctor', 'Look into the error behind these checks'),
                rationale: Craft::t('web-doctor', 'The investigation found one error behind several checks, from the evidence above.'),
                risk: RepairRisk::LOW,
                riskReason: Craft::t('web-doctor', 'Looking changes nothing.'),
                verification: Craft::t('web-doctor', 'The error stops being seen, and the issue’s own check no longer reports the problem.'),
            ),
            RecommendationRule::forCause(
                id: 'environment.defineSetting',
                cause: 'environment.settingMissing',
                title: Craft::t('web-doctor', 'Define the missing setting in this environment'),
                rationale: Craft::t('web-doctor', 'The investigation weighed a missing setting as the likely cause, from the evidence above.'),
                risk: RepairRisk::MEDIUM,
                riskReason: Craft::t('web-doctor', 'A missing setting is not in use, so defining it changes nothing that works — unless other data depends on its value, as it does for the security key, which has to be the original.'),
                verification: Craft::t('web-doctor', 'The issue’s own check finds the setting present and no longer reports the problem.'),
                prerequisites: [Craft::t('web-doctor', 'The value the setting is meant to have in this environment.')],
            ),
        ];
    }

    /**
     * The failed jobs the check read that meet the test, quoting the error Craft recorded for each.
     *
     * @param Closure(Evidence): bool $test
     * @return list<Observation>
     */
    private static function jobs(RecommendationCase $case, Closure $test): array
    {
        return $case->where(EvidenceType::QUEUE_JOB, $test, ['occurrences', 'error']);
    }

    /**
     * The settings among those named that the check recorded as missing.
     *
     * @param list<string> $keys
     * @return list<Observation>
     */
    private static function missing(RecommendationCase $case, EvidenceType $type, array $keys): array
    {
        $out = [];

        foreach ($case->evidenceOf($type) as $evidence) {
            $missing = array_values(array_filter($keys, static fn(string $key): bool => $evidence->get($key) === Redaction::MISSING));

            if ($missing !== []) {
                $out[] = $case->observe($evidence, $missing);
            }
        }

        return $out;
    }

    private static function backup(): string
    {
        return Craft::t('web-doctor', 'A current backup of the database, taken just before.');
    }

    private static function serverAccess(): string
    {
        return Craft::t('web-doctor', 'Access to the server, to change how PHP or its services run.');
    }
}
