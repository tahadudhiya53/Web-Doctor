<?php

namespace Tahadudhiya\WebDoctor\rules;

use Closure;
use Craft;
use craft\helpers\App;
use Tahadudhiya\WebDoctor\diagnostics\database\CharsetDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueBacklogDiagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory as Category;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\ErrorSignature;
use Tahadudhiya\WebDoctor\models\IssueSnapshot;
use Tahadudhiya\WebDoctor\models\Observation;

/**
 * The single list of known causes.
 *
 * Every cause Web Doctor can offer is written out here, with what it requires, what makes it more
 * or less likely and how firmly it may be held — so the same findings always produce the same
 * causes, and every cause can be traced to conditions a reader can check. A cause that is not on
 * this list is never offered.
 *
 * The conditions read the evidence the checks Web Doctor ships with actually record, by the keys
 * they record it under. A check contributed by another plugin is weighed wherever it records the
 * same kinds of fact — an exception, a deployment, a failed job — and not otherwise.
 */
final class RootCauseRules
{
    /** @var int How close together two problems' first sightings have to be to count as appearing together. */
    public const TOGETHER_WITHIN = 3600;

    /** @var int How long before a problem was first seen a deployment counts as shortly before it. */
    public const DEPLOYED_WITHIN = 86400;

    /** @var string A database server that answered and refused the login it was given. */
    private const LOGIN_REFUSED = '/\[(?:1044|1045)\]|SQLSTATE\[(?:28000|28P01)\]|access denied for user|password authentication failed|authentication failed for user/i';

    /** @var string A connection that never reached a server that could answer it. */
    private const UNREACHABLE = '/\[(?:2002|2003|2005|2006)\]|connection refused|no such file or directory|getaddrinfo|name or service not known|could not translate host name|connection timed out|timed out|could not connect to server|server has gone away|no route to host|network is unreachable/i';

    /**
     * @var string A database refusing a value for the characters in it, in the words the
     * databases Craft supports use. Deliberately not MySQL's bare error 1366, which is raised for
     * any value of the wrong type, and not the words "character set" or "collation" on their own,
     * which a mail or HTTP error can use about something else entirely.
     */
    private const CHARACTER_DATA = '/incorrect string value|illegal mix of collations|unknown collation|invalid utf8mb4 character string|invalid byte sequence for encoding|SQLSTATE\[22021\]/i';

    /** @var string An error that came from the database. */
    private const DATABASE_ERROR = '/SQLSTATE\[|PDOException|db\\\\Exception|IntegrityException/i';

    /**
     * @var string PHP stopping because a script needed more memory than it was allowed, in PHP's own
     * two wordings — not any message that mentions memory, which a cache server or an image
     * library can say about itself.
     */
    private const OUT_OF_MEMORY = '/allowed memory size of \d+ bytes exhausted|out of memory \(allocated \d+\)/i';

    /** @var list<string> The shipped checks that read the database, so their answering shows it answered. */
    private const READS_DATABASE = ['database.migrations', 'database.charset', 'queue.backlog', 'queue.failedJobs', 'plugins.health'];

    /**
     * @var array<string, list<string>> The settings each shipped check records the presence of
     * that it cannot do without. A missing reply-to address or a database with no password is a
     * choice; a missing sender address or security key is not.
     */
    private const REQUIRED_SETTINGS = [
        'database.connection' => ['user'],
        'email.configuration' => ['fromEmail', 'host'],
        'environment.configuration' => ['CRAFT_SECURITY_KEY'],
    ];

    /** @var list<string> The shipped checks whose finding is itself a setting that is missing. */
    private const REPORT_MISSING_SETTINGS = ['email.configuration', 'environment.configuration'];

    /** @var int The memory limit Craft asks for, in bytes. */
    private const RECOMMENDED_MEMORY_LIMIT = 256 * 1024 * 1024;

    /**
     * @return list<RootCauseRule>
     */
    public static function all(): array
    {
        $databaseDependent = [Category::DATABASE, Category::QUEUE, Category::PLUGINS, Category::CRAFT, Category::PROJECT_CONFIG, Category::HTTP, Category::CONTENT, Category::SEARCH];

        $connectionFailed = static fn(string $id): Condition => Condition::requires($id, Craft::t('web-doctor', 'Craft could not connect to its database'), static fn(CorrelationCase $c): array => self::connectionFailed($c));
        $answeredElsewhere = static fn(): Condition => Condition::contradicts('answeredElsewhere', Craft::t('web-doctor', 'Another check read the database successfully in the same investigation'), static fn(CorrelationCase $c): array => self::databaseAnswered($c));

        return [
            new RootCauseRule(
                id: 'database.credentialsRejected',
                title: Craft::t('web-doctor', 'The database is refusing the credentials Craft connects with'),
                statement: Craft::t('web-doctor', 'The database server was reached and turned down the user name and password this environment gives Craft. Until it accepts them nothing that reads or writes data can work, so failures elsewhere that depend on the database follow from this.'),
                explains: [...$databaseDependent, Category::ENVIRONMENT],
                conditions: [
                    $connectionFailed('connectionFailed'),
                    Condition::confirms('loginRefused', Craft::t('web-doctor', 'The database server refused the login'), static fn(CorrelationCase $c): array => self::connectionErrorsMatching($c, self::LOGIN_REFUSED)),
                    Condition::supports('credentialsPresent', Craft::t('web-doctor', 'A database user name and password are both configured'), static fn(CorrelationCase $c): array => self::databaseCredentials($c, Redaction::PRESENT, all: true)),
                    Condition::contradicts('credentialsMissing', Craft::t('web-doctor', 'The database user name or password is not configured at all'), static fn(CorrelationCase $c): array => self::databaseCredentials($c, Redaction::MISSING, all: false)),
                    $answeredElsewhere(),
                ],
                ceiling: Confidence::HIGH,
                recommendation: Craft::t('web-doctor', 'Check the database user name and password this environment gives Craft against the accounts on the database server, and correct whichever is wrong.'),
                nextSteps: [
                    Craft::t('web-doctor', 'Log in to the database server from the web server with the same user name and password, to see the refusal outside Craft.'),
                    Craft::t('web-doctor', 'Whether the password was changed on the server, or the user removed or limited to other hosts.'),
                    Craft::t('web-doctor', 'The database server’s own error log, which records refused logins.'),
                ],
            ),
            new RootCauseRule(
                id: 'database.serverUnreachable',
                title: Craft::t('web-doctor', 'Craft cannot reach the database server'),
                statement: Craft::t('web-doctor', 'The connection never got as far as a login: the server Craft is configured to use did not answer. Every failure that depends on the database follows from this.'),
                explains: [...$databaseDependent, Category::ENVIRONMENT],
                conditions: [
                    $connectionFailed('connectionFailed'),
                    Condition::supports('unreachable', Craft::t('web-doctor', 'The connection error says the server could not be reached'), static fn(CorrelationCase $c): array => self::connectionErrorsMatching($c, self::UNREACHABLE)),
                    Condition::supports('dependentsBroke', Craft::t('web-doctor', 'Other checks that need the database broke or could not tell'), static fn(CorrelationCase $c): array => self::dependentsBroke($c)),
                    Condition::contradicts('loginRefused', Craft::t('web-doctor', 'The database server answered and refused the login'), static fn(CorrelationCase $c): array => self::connectionErrorsMatching($c, self::LOGIN_REFUSED)),
                    $answeredElsewhere(),
                ],
                ceiling: Confidence::HIGH,
                recommendation: Craft::t('web-doctor', 'Check that the database server is running and that the host and port this environment gives Craft point at it.'),
                nextSteps: [
                    Craft::t('web-doctor', 'Whether the database server is running and listening on the configured host and port.'),
                    Craft::t('web-doctor', 'Whether the web server can reach that host and port at all, with a connection test from the same machine.'),
                    Craft::t('web-doctor', 'Whether the host name resolves to the server intended for this environment.'),
                ],
                limitation: Craft::t('web-doctor', 'A server that is down, a host name that points elsewhere and a network in between all look the same from inside Craft, so which of them it is cannot be established from here.'),
            ),
            new RootCauseRule(
                id: 'database.characterSet',
                title: Craft::t('web-doctor', 'The database’s character set cannot store some of what is written to it'),
                statement: Craft::t('web-doctor', 'The database, or the table Craft samples, is not set up for the characters being saved. Values containing them — emoji, some names and punctuation — are refused or mangled, and the failure names a column or a value rather than a character set.'),
                explains: [Category::DATABASE, Category::QUEUE, Category::CONTENT, Category::HTTP, Category::SEARCH, Category::COMMERCE],
                conditions: [
                    Condition::requires('mismatch', Craft::t('web-doctor', 'The database’s character set does not match what Craft needs'), static fn(CorrelationCase $c): array => self::charsetMismatch($c)),
                    Condition::supports('refusedCharacters', Craft::t('web-doctor', 'An error recorded the database refusing a value because of its characters'), static fn(CorrelationCase $c): array => self::databaseErrorsMatching($c, self::CHARACTER_DATA)),
                    Condition::supports('failedWrite', Craft::t('web-doctor', 'A queue job failed writing a value the database refused because of its characters'), static fn(CorrelationCase $c): array => self::jobsMatching($c, self::CHARACTER_DATA)),
                    Condition::contradicts('otherDatabaseErrors', Craft::t('web-doctor', 'Database errors were recorded that have nothing to do with characters'), static fn(CorrelationCase $c): array => self::databaseErrorsOtherThan($c, self::CHARACTER_DATA)),
                    Condition::contradicts('sampledTableAccepts', Craft::t('web-doctor', 'The table Craft samples accepts four-byte characters'), static fn(CorrelationCase $c): array => self::sampledTableAccepts($c)),
                ],
                ceiling: Confidence::LIKELY,
                recommendation: Craft::t('web-doctor', 'Convert the database and its tables to utf8mb4 with a utf8mb4 collation, and set the same charset in this environment’s database settings.'),
                nextSteps: [
                    Craft::t('web-doctor', 'The character set of the table and column named in the refused write.'),
                    Craft::t('web-doctor', 'Whether the value that failed contains emoji or other four-byte characters.'),
                    Craft::t('web-doctor', 'Craft’s `php craft db/convert-charset` command, which converts every table, once a backup has been taken.'),
                ],
                limitation: Craft::t('web-doctor', 'Web Doctor samples one table, so which column refused the value, and whether the others would, is not known.'),
            ),
            new RootCauseRule(
                id: 'queue.notProcessing',
                title: Craft::t('web-doctor', 'Nothing is taking jobs off the queue'),
                statement: Craft::t('web-doctor', 'Jobs are waiting past the point a working queue would have run them. Whatever the site hands to the queue — email, search indexing, image transforms — is not happening, while the site itself looks normal.'),
                explains: [Category::QUEUE, Category::EMAIL, Category::SEARCH, Category::ASSETS, Category::CONTENT],
                conditions: [
                    Condition::requires('stalled', Craft::t('web-doctor', 'The oldest waiting job has been waiting more than half an hour'), static fn(CorrelationCase $c): array => self::queueDepth($c, static fn(array $d): bool => (int)($d['waiting'] ?? 0) > 0 && (int)($d['oldestWaitingSeconds'] ?? 0) >= QueueBacklogDiagnostic::STALLED_AFTER, ['waiting', 'oldestWaitingSeconds'])),
                    Condition::supports('nothingRunning', Craft::t('web-doctor', 'No job is running'), static fn(CorrelationCase $c): array => self::queueDepth($c, static fn(array $d): bool => ($d['running'] ?? null) === 0, ['running'])),
                    Condition::supports('noAutomaticRunner', Craft::t('web-doctor', 'Craft is not set to run the queue itself, so something else has to'), static fn(CorrelationCase $c): array => self::queueDepth($c, static fn(array $d): bool => ($d['runQueueAutomatically'] ?? null) === false, ['runQueueAutomatically'])),
                    Condition::contradicts('jobInProgress', Craft::t('web-doctor', 'A job is running within the time it allows itself'), static fn(CorrelationCase $c): array => self::queueDepth($c, static fn(array $d): bool => (int)($d['running'] ?? 0) > 0
                        && is_numeric($d['longestRunningSeconds'] ?? null)
                        && is_numeric($d['longestRunningTimeLimit'] ?? null)
                        && (int)$d['longestRunningSeconds'] <= (int)$d['longestRunningTimeLimit'], ['running', 'longestRunningSeconds', 'longestRunningTimeLimit'])),
                ],
                ceiling: Confidence::HIGH,
                recommendation: Craft::t('web-doctor', 'Start whatever runs the queue here — a worker process, a cron entry calling `php craft queue/run`, or Craft’s automatic runner — and make sure it keeps running.'),
                nextSteps: [
                    Craft::t('web-doctor', 'Whether a queue worker is running on the server, and its own log.'),
                    Craft::t('web-doctor', 'The cron entry or process supervisor that is meant to start it.'),
                    Craft::t('web-doctor', 'Craft’s queue log (by default storage/logs/queue.log), for a worker that starts and stops at once.'),
                ],
                limitation: Craft::t('web-doctor', 'Whether a worker process exists, and why it stopped, cannot be seen from inside Craft.'),
            ),
            new RootCauseRule(
                id: 'queue.outOfMemory',
                title: Craft::t('web-doctor', 'Queue jobs are running out of memory'),
                statement: Craft::t('web-doctor', 'PHP stopped jobs part-way because they needed more memory than they were allowed. The work they were doing is left undone, and a job that failed this way usually fails the same way when it is retried.'),
                // Not PHP: a job running out of memory is what a low limit leads to, not what explains it.
                explains: [Category::QUEUE, Category::EMAIL, Category::SEARCH, Category::ASSETS, Category::PERFORMANCE, Category::CONTENT],
                conditions: [
                    // Required and establishing read the same jobs: a failed job running out of
                    // memory makes the cause one to consider; whether the check that read the jobs
                    // established what it read decides whether that is proof.
                    Condition::requires('ranOutOfMemory', Craft::t('web-doctor', 'A failed queue job recorded PHP running out of memory'), static fn(CorrelationCase $c): array => self::jobsMatching($c, self::OUT_OF_MEMORY)),
                    Condition::confirms('jobRecordedIt', Craft::t('web-doctor', 'Craft recorded a failed job’s error as PHP running out of memory'), static fn(CorrelationCase $c): array => self::jobsMatching($c, self::OUT_OF_MEMORY)),
                    Condition::supports('lowLimit', Craft::t('web-doctor', 'PHP’s memory limit is below the 256M Craft asks for'), static fn(CorrelationCase $c): array => self::lowMemoryLimit($c)),
                    Condition::supports('repeated', Craft::t('web-doctor', 'A job that ran out of memory has failed more than once'), static fn(CorrelationCase $c): array => self::jobsMatching($c, self::OUT_OF_MEMORY, repeated: true)),
                    Condition::contradicts('otherFailures', Craft::t('web-doctor', 'Other failed jobs recorded errors that have nothing to do with memory'), static fn(CorrelationCase $c): array => self::jobsMatching($c, self::OUT_OF_MEMORY, matching: false)),
                ],
                ceiling: Confidence::HIGH,
                recommendation: Craft::t('web-doctor', 'Raise memory_limit for the PHP that runs the queue, or find what makes the job need so much, and then retry the failed jobs.'),
                nextSteps: [
                    Craft::t('web-doctor', 'Which PHP runs the queue here: the command line often has a different memory_limit from the web server.'),
                    Craft::t('web-doctor', 'What the failing job was processing — an unusually large asset, import or batch.'),
                    Craft::t('web-doctor', 'Craft’s queue log, for how much memory the job had used when it stopped.'),
                ],
            ),
            new RootCauseRule(
                id: 'deployment.incomplete',
                title: Craft::t('web-doctor', 'A deployment has not been finished here'),
                statement: Craft::t('web-doctor', 'The code in place expects changes the database and configuration do not have yet. That is what a deployment leaves behind when the step that applies them — `php craft up` — did not run or did not finish, and it shows up as missing fields, sections and tables rather than as anything naming a deployment.'),
                explains: [Category::PROJECT_CONFIG, Category::DATABASE, Category::CRAFT, Category::PLUGINS, Category::CONTENT, Category::DEPLOYMENT, Category::HTTP],
                conditions: [
                    Condition::requires('workLeftUndone', Craft::t('web-doctor', 'Migrations or project config changes are waiting to be applied'), static fn(CorrelationCase $c): array => [...self::pendingMigrations($c), ...self::pendingConfig($c)]),
                    Condition::supports('bothLeftUndone', Craft::t('web-doctor', 'Migrations and project config changes are both waiting, as they are when `php craft up` has not run'), static fn(CorrelationCase $c): array => self::pendingMigrations($c) !== [] && self::pendingConfig($c) !== [] ? [...self::pendingMigrations($c), ...self::pendingConfig($c)] : []),
                    Condition::supports('schemaDisagrees', Craft::t('web-doctor', 'The code and the stored schema or project config disagree on a schema version'), static fn(CorrelationCase $c): array => self::schemaDisagrees($c)),
                    Condition::supports('deployedJustBefore', Craft::t('web-doctor', 'A deployment was recorded in the day before the problem was first seen'), static fn(CorrelationCase $c): array => self::deployedJustBefore($c)),
                    Condition::supports('appearedTogether', Craft::t('web-doctor', 'Other problems nearby were first seen within an hour of this one'), static fn(CorrelationCase $c): array => self::appearedTogether($c)),
                ],
                ceiling: Confidence::HIGH,
                recommendation: Craft::t('web-doctor', 'Run `php craft up` in this environment, and make it part of every deployment.'),
                nextSteps: [
                    Craft::t('web-doctor', 'The deployment log for this environment, for a step that failed or was skipped.'),
                    Craft::t('web-doctor', 'Whether the code deployed here is the version the database and project config files were written for.'),
                    Craft::t('web-doctor', 'What else was deployed at the same time.'),
                ],
                limitation: Craft::t('web-doctor', 'Web Doctor sees what is left to apply, not the deployment that left it, unless a check records deployments.'),
            ),
            new RootCauseRule(
                id: 'place.shared',
                title: Craft::t('web-doctor', 'Several of these problems come from the same place'),
                statement: Craft::t('web-doctor', 'This problem and others found alongside it name the same plugin or component. Problems that share a place often share a cause, so that place is the first to look at.'),
                explains: null,
                conditions: [
                    Condition::requires('samePlace', Craft::t('web-doctor', 'Another problem names the same plugin or component as this one'), static fn(CorrelationCase $c): array => self::samePlace($c)),
                    Condition::supports('pluginUnhealthy', Craft::t('web-doctor', 'Craft reports that plugin as not loading, missing or unlicensed'), static fn(CorrelationCase $c): array => self::pluginUnhealthy($c)),
                    Condition::supports('thrownFromPlugin', Craft::t('web-doctor', 'An error was thrown from that plugin’s code'), static fn(CorrelationCase $c): array => self::thrownFromPlugin($c)),
                    Condition::supports('appearedTogether', Craft::t('web-doctor', 'Those problems were first seen within an hour of this one'), static fn(CorrelationCase $c): array => self::appearedTogether($c, static fn(IssueSnapshot $i): bool => self::sharesPlace($c, $i->affectedPlugin, $i->affectedComponent))),
                ],
                ceiling: Confidence::LIKELY,
                recommendation: Craft::t('web-doctor', 'Look at that plugin or component first: its version, its recent changes and its own log.'),
                nextSteps: [
                    Craft::t('web-doctor', 'Whether the plugin was updated, installed or reconfigured around the time the problems were first seen.'),
                    Craft::t('web-doctor', 'The plugin’s changelog and issue tracker, for the errors recorded here.'),
                    Craft::t('web-doctor', 'Whether the problems go away with the plugin disabled, in an environment where that is safe to try.'),
                ],
                limitation: Craft::t('web-doctor', 'Sharing a place is a reason to look there, not proof of a shared cause.'),
            ),
            new RootCauseRule(
                id: 'error.shared',
                title: Craft::t('web-doctor', 'One error is behind several checks'),
                statement: Craft::t('web-doctor', 'The check that raised this issue and others ran into the same error. A single failure underneath several checks is more likely to be the cause than any one of them.'),
                explains: null,
                conditions: [
                    Condition::requires('sameError', Craft::t('web-doctor', 'The check that raised this issue and at least one other ran into the same error'), static fn(CorrelationCase $c): array => array_map(static fn(array $e): Observation => $c->observeError($e), self::sharedErrors($c))),
                    Condition::supports('othersCouldNotAnswer', Craft::t('web-doctor', 'The other checks that ran into it broke or could not tell'), static fn(CorrelationCase $c): array => self::othersCouldNotAnswer($c)),
                ],
                ceiling: Confidence::LIKELY,
                recommendation: Craft::t('web-doctor', 'Look into that error first: where it was thrown, and what it says.'),
                nextSteps: [
                    Craft::t('web-doctor', 'The error’s own page, for how often it has been seen here and since when.'),
                    Craft::t('web-doctor', 'Craft’s logs around its first occurrence, for what changed. Web Doctor does not read logs.'),
                ],
                limitation: Craft::t('web-doctor', 'An error several checks ran into is where they meet, not necessarily where the problem starts.'),
            ),
            new RootCauseRule(
                id: 'environment.settingMissing',
                title: Craft::t('web-doctor', 'A setting this environment depends on is missing'),
                statement: Craft::t('web-doctor', 'Something the check that raised this issue needs is not configured in this environment. A setting that refers to an environment variable the environment does not define reads as missing in the same way.'),
                explains: [Category::ENVIRONMENT, Category::CONFIGURATION, Category::DATABASE, Category::EMAIL, Category::FILESYSTEM, Category::ASSETS, Category::CRAFT, Category::SECURITY],
                conditions: [
                    Condition::requires('missingInOrigin', Craft::t('web-doctor', 'The check that raised this issue recorded a setting it needs as missing'), static fn(CorrelationCase $c): array => self::missingSettings($c)),
                    Condition::confirms('originReportsIt', Craft::t('web-doctor', 'That check established the missing setting as the problem'), static fn(CorrelationCase $c): array => self::originReportsMissing($c)),
                    Condition::supports('loginRefused', Craft::t('web-doctor', 'The database server refused the login made without it'), static fn(CorrelationCase $c): array => $c->issue->diagnosticId === 'database.connection' ? self::connectionErrorsMatching($c, self::LOGIN_REFUSED) : []),
                    Condition::contradicts('serverUnreachable', Craft::t('web-doctor', 'The server the setting is for could not be reached at all'), static fn(CorrelationCase $c): array => $c->issue->diagnosticId === 'database.connection' ? self::connectionErrorsMatching($c, self::UNREACHABLE) : []),
                ],
                ceiling: Confidence::HIGH,
                recommendation: Craft::t('web-doctor', 'Define the missing setting in this environment — usually an environment variable — with the value it is meant to have here.'),
                nextSteps: [
                    Craft::t('web-doctor', 'The .env file, or the host’s environment settings, against what this environment is meant to define.'),
                    Craft::t('web-doctor', 'Whether the setting refers to an environment variable by a name the environment spells differently.'),
                ],
            ),
        ];
    }

    // Database ---------------------------------------------------------------

    /**
     * @return list<Observation>
     */
    private static function connectionFailed(CorrelationCase $case): array
    {
        $result = $case->result('database.connection');

        if ($result === null || $result->status !== DiagnosticStatus::FAIL) {
            return [];
        }

        foreach ($case->evidence(EvidenceType::DATABASE_ERROR, 'database.connection') as [$evidence, $from]) {
            if ($evidence->get('succeeded') === false) {
                return [$case->observeResult($from), $case->observeEvidence($evidence, $from, ['succeeded'])];
            }
        }

        return [];
    }

    /**
     * The checks that read the database and reached a conclusion, which they could not have done
     * without it answering.
     *
     * @return list<Observation>
     */
    private static function databaseAnswered(CorrelationCase $case): array
    {
        $out = [];

        foreach (self::READS_DATABASE as $id) {
            $result = $case->result($id);

            if ($result !== null && $result->status->isConclusive()) {
                $out[] = $case->observeResult($result);
            }
        }

        return $out;
    }

    /**
     * @return list<Observation>
     */
    private static function dependentsBroke(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->results() as $result) {
            if ($result->diagnosticId === 'database.connection') {
                continue;
            }

            if (in_array($result->category, [Category::DATABASE, Category::QUEUE, Category::PROJECT_CONFIG, Category::PLUGINS], true)
                && in_array($result->status, [DiagnosticStatus::ERROR, DiagnosticStatus::UNKNOWN], true)) {
                $out[] = $case->observeResult($result);
            }
        }

        return $out;
    }

    /**
     * The database user and password as the connection check recorded them: present or missing,
     * never what they are.
     *
     * @param bool $all Whether both have to be in this state, or either.
     * @return list<Observation>
     */
    private static function databaseCredentials(CorrelationCase $case, string $state, bool $all): array
    {
        foreach ($case->evidence(EvidenceType::CONFIGURATION, 'database.connection') as [$evidence, $result]) {
            $matching = array_values(array_filter(['user', 'password'], static fn(string $key): bool => $evidence->get($key) === $state));

            if ($all ? count($matching) === 2 : $matching !== []) {
                return [$case->observeEvidence($evidence, $result, $matching)];
            }
        }

        return [];
    }

    /**
     * @return list<Observation>
     */
    private static function charsetMismatch(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->evidence(EvidenceType::DATABASE, 'database.charset') as [$evidence, $result]) {
            $database = $evidence->get('databaseCharset');
            $configured = $evidence->get('configuredCharset');

            if ($evidence->get('sampledTableAcceptsMb4') === false) {
                $out[] = $case->observeEvidence($evidence, $result, ['sampledTable', 'sampledTableAcceptsMb4']);
            }

            if (is_string($database) && is_string($configured) && !CharsetDiagnostic::sameCharset($database, $configured)) {
                $out[] = $case->observeEvidence($evidence, $result, ['databaseCharset', 'configuredCharset']);
            }
        }

        return $out;
    }

    /**
     * @return list<Observation>
     */
    private static function sampledTableAccepts(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->evidence(EvidenceType::DATABASE, 'database.charset') as [$evidence, $result]) {
            if ($evidence->get('sampledTableAcceptsMb4') === true) {
                $out[] = $case->observeEvidence($evidence, $result, ['sampledTable', 'sampledTableAcceptsMb4']);
            }
        }

        return $out;
    }

    /**
     * Database errors — recorded exceptions and failed jobs' errors — that are not the kind named.
     *
     * @return list<Observation>
     */
    private static function databaseErrorsOtherThan(CorrelationCase $case, string $pattern): array
    {
        $out = [];

        foreach ($case->errors() as $error) {
            $text = self::errorText($error['signature']);

            if (preg_match(self::DATABASE_ERROR, $error['signature']->class . ' ' . $text) === 1 && preg_match($pattern, $text) !== 1) {
                $out[] = $case->observeError($error);
            }
        }

        foreach ($case->failedJobs() as $job) {
            if ($job[2] !== null && preg_match(self::DATABASE_ERROR, $job[2]) === 1 && preg_match($pattern, $job[2]) !== 1) {
                $out[] = $case->observeJob($job);
            }
        }

        return $out;
    }

    // Errors and jobs --------------------------------------------------------

    /**
     * The errors the connection check itself ran into whose message says what the pattern
     * describes. Why the connection failed is the connection's error to say: another check's
     * "no such file" or "timed out" is about a file or a request, not the database server.
     *
     * @return list<Observation>
     */
    private static function connectionErrorsMatching(CorrelationCase $case, string $pattern): array
    {
        $out = [];

        foreach ($case->errors() as $error) {
            $ids = array_map(static fn(DiagnosticResult $r): string => $r->diagnosticId, $error['results']);

            if (in_array('database.connection', $ids, true) && preg_match($pattern, self::errorText($error['signature'])) === 1) {
                $out[] = $case->observeError($error);
            }
        }

        return $out;
    }

    /**
     * The errors that came from the database — by their class or their SQLSTATE — whose message
     * says what the pattern describes.
     *
     * @return list<Observation>
     */
    private static function databaseErrorsMatching(CorrelationCase $case, string $pattern): array
    {
        $out = [];

        foreach ($case->errors() as $error) {
            $text = self::errorText($error['signature']);

            if (preg_match(self::DATABASE_ERROR, $error['signature']->class . ' ' . $text) === 1 && preg_match($pattern, $text) === 1) {
                $out[] = $case->observeError($error);
            }
        }

        return $out;
    }

    /**
     * The failed jobs whose recorded error says, or does not say, what the pattern describes. A
     * job whose error the reader may not see is neither: nothing is known about why it failed.
     *
     * @return list<Observation>
     */
    private static function jobsMatching(CorrelationCase $case, string $pattern, bool $matching = true, bool $repeated = false): array
    {
        $out = [];

        foreach ($case->failedJobs() as $job) {
            if ($job[2] === null || (preg_match($pattern, $job[2]) === 1) !== $matching) {
                continue;
            }

            if ($repeated && (int)$job[0]->get('occurrences', 1) < 2) {
                continue;
            }

            $out[] = $case->observeJob($job);
        }

        return $out;
    }

    /**
     * The errors both the check that raised the issue and at least one other ran into.
     *
     * @return list<array{signature: ErrorSignature, results: list<DiagnosticResult>}>
     */
    private static function sharedErrors(CorrelationCase $case): array
    {
        return array_values(array_filter($case->errors(), static function(array $error) use ($case): bool {
            $ids = array_map(static fn(DiagnosticResult $r): string => $r->diagnosticId, $error['results']);

            return count($ids) >= 2 && in_array($case->issue->diagnosticId, $ids, true);
        }));
    }

    /**
     * @return list<Observation>
     */
    private static function othersCouldNotAnswer(CorrelationCase $case): array
    {
        $out = [];

        foreach (self::sharedErrors($case) as $error) {
            foreach ($error['results'] as $result) {
                if ($result->diagnosticId !== $case->issue->diagnosticId
                    && in_array($result->status, [DiagnosticStatus::ERROR, DiagnosticStatus::UNKNOWN], true)) {
                    $out[] = $case->observeResult($result);
                }
            }
        }

        return $out;
    }

    /**
     * What an error said, as it was said and as it was grouped. Error codes survive both, so
     * either can be matched on.
     */
    private static function errorText(ErrorSignature $signature): string
    {
        $previous = array_map(static fn(array $link): string => $link['message'], $signature->previous);

        return implode("\n", [$signature->sample, $signature->message, ...$previous]);
    }

    // Queue and PHP ----------------------------------------------------------

    /**
     * The queue's depth as the backlog check recorded it, where it meets the test.
     *
     * @param Closure(array<array-key, mixed>): bool $test
     * @param list<string> $keys
     * @return list<Observation>
     */
    private static function queueDepth(CorrelationCase $case, Closure $test, array $keys): array
    {
        $out = [];

        foreach ($case->evidence(EvidenceType::QUEUE, 'queue.backlog') as [$evidence, $result]) {
            if (array_key_exists('waiting', $evidence->data) && $test($evidence->data)) {
                $out[] = $case->observeEvidence($evidence, $result, $keys);
            }
        }

        return $out;
    }

    /**
     * @return list<Observation>
     */
    private static function lowMemoryLimit(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->evidence(EvidenceType::CONFIGURATION, 'php.configuration') as [$evidence, $result]) {
            $limit = $evidence->get('memory_limit');

            // -1 is no limit at all, and an unreadable limit is not a low one.
            if (is_string($limit) && $limit !== Redaction::UNKNOWN) {
                $bytes = App::phpSizeToBytes($limit);

                if ($bytes > 0 && $bytes < self::RECOMMENDED_MEMORY_LIMIT) {
                    $out[] = $case->observeEvidence($evidence, $result, ['sapi', 'memory_limit']);
                }
            }
        }

        return $out;
    }

    // Deployment and configuration -------------------------------------------

    /**
     * @return list<Observation>
     */
    private static function pendingMigrations(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->evidence(EvidenceType::DATABASE, 'database.migrations') as [$evidence, $result]) {
            $pending = $evidence->get('pendingMigrations');

            if (is_array($pending) && $pending !== []) {
                $out[] = $case->observeEvidence($evidence, $result, ['pendingMigrations']);
            }
        }

        return $out;
    }

    /**
     * @return list<Observation>
     */
    private static function pendingConfig(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->evidence(EvidenceType::PROJECT_CONFIG, 'projectConfig.pendingChanges') as [$evidence, $result]) {
            if ($evidence->get('changesPending') === true) {
                $out[] = $case->observeEvidence($evidence, $result, ['changesPending', 'allowAdminChanges']);
            }
        }

        return $out;
    }

    /**
     * @return list<Observation>
     */
    private static function schemaDisagrees(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->evidence(EvidenceType::DATABASE, 'database.migrations') as [$evidence, $result]) {
            if ($evidence->get('schemaVersionCompatible') === false) {
                $out[] = $case->observeEvidence($evidence, $result, ['schemaVersionCompatible', 'codeSchemaVersion']);
            }
        }

        foreach ($case->evidence(EvidenceType::PROJECT_CONFIG, 'projectConfig.integrity') as [$evidence, $result]) {
            if ($evidence->get('compatible') === false) {
                $out[] = $case->observeEvidence($evidence, $result, ['compatible', 'mismatches']);
            }
        }

        return $out;
    }

    /**
     * Deployments any check recorded in the day before the problem was first seen. Nothing Web
     * Doctor ships records deployments yet; a check that does is weighed here.
     *
     * @return list<Observation>
     */
    private static function deployedJustBefore(CorrelationCase $case): array
    {
        $first = $case->issue->firstDetected->getTimestamp();
        $out = [];

        foreach ($case->results() as $result) {
            foreach ($result->evidence() as $evidence) {
                if ($evidence->type !== EvidenceType::DEPLOYMENT) {
                    continue;
                }

                $at = ($evidence->observedAt ?? $evidence->recordedAt)->getTimestamp();

                if ($at <= $first && $first - $at <= self::DEPLOYED_WITHIN) {
                    $out[] = $case->observeEvidence($evidence, $result);
                }
            }
        }

        return $out;
    }

    /**
     * Other problems first seen close to when this one was — issue history, read as time. A
     * problem this run found for the first time is left out: when it started is not known, only
     * when it was first looked for.
     *
     * @param Closure(IssueSnapshot): bool|null $among Which problems count.
     * @return list<Observation>
     */
    private static function appearedTogether(CorrelationCase $case, ?Closure $among = null): array
    {
        if ($case->issue->newThisRun) {
            return [];
        }

        $first = $case->issue->firstDetected->getTimestamp();
        $out = [];

        foreach ($case->issues() as $issue) {
            if ($issue->newThisRun || ($among !== null && !$among($issue))) {
                continue;
            }

            if (abs($issue->firstDetected->getTimestamp() - $first) <= self::TOGETHER_WITHIN) {
                $out[] = $case->observeIssue($issue);
            }
        }

        return $out;
    }

    /**
     * The settings the check that raised the issue cannot do without, recorded as missing.
     *
     * @return list<Observation>
     */
    private static function missingSettings(CorrelationCase $case): array
    {
        $origin = $case->origin();
        $required = self::REQUIRED_SETTINGS[$case->issue->diagnosticId] ?? [];

        if ($origin === null || $required === []) {
            return [];
        }

        $out = [];

        foreach ($origin->evidence() as $evidence) {
            $missing = array_values(array_filter($required, static fn(string $key): bool => $evidence->get($key) === Redaction::MISSING));

            if ($missing !== []) {
                $out[] = $case->observeEvidence($evidence, $origin, $missing);
            }
        }

        return $out;
    }

    /**
     * @return list<Observation>
     */
    private static function originReportsMissing(CorrelationCase $case): array
    {
        $origin = $case->origin();

        return $origin !== null && $origin->status->isProblem() && in_array($origin->diagnosticId, self::REPORT_MISSING_SETTINGS, true)
            ? [$case->observeResult($origin)]
            : [];
    }

    // Plugins and components -------------------------------------------------

    /**
     * Other findings and problems naming the plugin or component this one names.
     *
     * @return list<Observation>
     */
    private static function samePlace(CorrelationCase $case): array
    {
        $out = [];

        foreach ($case->results() as $result) {
            if ($result->diagnosticId !== $case->issue->diagnosticId
                && $result->status->isProblem()
                && self::sharesPlace($case, $result->affectedPlugin, $result->affectedComponent)) {
                $out[] = $case->observeResult($result);
            }
        }

        foreach ($case->issues() as $issue) {
            if (self::sharesPlace($case, $issue->affectedPlugin, $issue->affectedComponent)) {
                $out[] = $case->observeIssue($issue);
            }
        }

        return $out;
    }

    private static function sharesPlace(CorrelationCase $case, ?string $plugin, ?string $component): bool
    {
        $own = $case->issue;

        return ($own->affectedPlugin !== null && $own->affectedPlugin !== '' && $plugin === $own->affectedPlugin)
            || ($own->affectedComponent !== null && $own->affectedComponent !== '' && $component === $own->affectedComponent);
    }

    /**
     * @return list<Observation>
     */
    private static function pluginUnhealthy(CorrelationCase $case): array
    {
        $plugin = $case->issue->affectedPlugin;

        if ($plugin === null || $plugin === '') {
            return [];
        }

        $out = [];

        foreach ($case->evidence(EvidenceType::PLUGIN, 'plugins.health') as [$evidence, $result]) {
            $lists = array_values(array_filter(['failedToLoad', 'missingFromProject', 'licensing'], static fn(string $key): bool => is_array($evidence->get($key)) && in_array($plugin, $evidence->get($key), true)));

            if ($lists !== []) {
                $out[] = $case->observeEvidence($evidence, $result, $lists);
            }
        }

        return $out;
    }

    /**
     * Errors thrown from, or passing through, the plugin's own package. Read from the path, so a
     * plugin whose package directory is named after its handle is recognised and one named
     * otherwise is not — the safe direction to miss in.
     *
     * @return list<Observation>
     */
    private static function thrownFromPlugin(CorrelationCase $case): array
    {
        $plugin = $case->issue->affectedPlugin;

        if ($plugin === null || $plugin === '') {
            return [];
        }

        $pattern = '#vendor/[^/]+/(?:craft-)?' . preg_quote($plugin, '#') . '(?:/|$)#i';
        $out = [];

        foreach ($case->errors() as $error) {
            foreach ([$error['signature']->origin, ...$error['signature']->frames] as $place) {
                if (preg_match($pattern, $place) === 1) {
                    $out[] = $case->observeError($error);

                    continue 2;
                }
            }
        }

        return $out;
    }
}
