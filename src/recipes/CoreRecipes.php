<?php

namespace Tahadudhiya\WebDoctor\recipes;

use Craft;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory as Category;
use Tahadudhiya\WebDoctor\investigations\RelatedArea;

/**
 * The recipes Web Doctor ships with, in the order they are offered.
 *
 * Every area a recipe looks at is written out here with its reason, as every relationship an
 * investigation follows is. Where the symptom has a part no check here can inspect — Craft's logs,
 * above all — it is stated as a lead rather than left out, so a recipe that finds nothing never
 * reads as a clean bill of health for the part it could not see.
 */
final class CoreRecipes
{
    /**
     * @return list<Recipe>
     */
    public static function all(): array
    {
        $noLogs = Craft::t('web-doctor', 'Web Doctor does not read logs.');

        return [
            new Recipe(
                id: 'http.serverError',
                title: Craft::t('web-doctor', '500 Error Doctor'),
                symptom: Craft::t('web-doctor', 'I have a 500 error'),
                description: Craft::t('web-doctor', 'Looks at what most often makes Craft fail a request outright: PHP, Craft’s own state, plugins and the database, then storage, the queue, configuration and the environment.'),
                category: Category::HTTP,
                primary: [
                    RelatedArea::category(Category::PHP, Craft::t('web-doctor', 'A request that fails outright is often PHP running out of memory or time, or missing an extension.')),
                    RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'Craft’s own state — whether it is installed and serving, or still in maintenance mode because an update stopped part-way — changes what every request does.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'An exception thrown while serving a request frequently comes from a plugin, and a plugin whose code has gone fails every request that reaches it.')),
                    RelatedArea::category(Category::DATABASE, Craft::t('web-doctor', 'Almost every request reads the database, and a migration still to be run stops Craft serving the site.')),
                ],
                related: [
                    RelatedArea::check('storage.paths', Craft::t('web-doctor', 'Craft writes compiled templates, caches and logs to its storage directory while serving requests, and one it cannot write to fails them.')),
                    RelatedArea::category(Category::QUEUE, Craft::t('web-doctor', 'Work a request hands to the queue fails there rather than in the request, and its error is recorded with the job.')),
                    RelatedArea::category(Category::CONFIGURATION, Craft::t('web-doctor', 'Configuration decides how a request is handled.')),
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Settings missing from the environment fail at the moment something needs them.')),
                ],
                leads: [
                    Craft::t('web-doctor', 'Craft’s web log (by default storage/logs/web.log) for the exception and stack trace behind the failing request. Web Doctor does not capture the exceptions a site throws while serving requests, and does not read logs.'),
                    Craft::t('web-doctor', 'The web server’s and PHP’s error logs, which record failures that happen before Craft is reached — a fatal error, a timeout, a crashed PHP process.'),
                    Craft::t('web-doctor', 'Whether the error happens on every page or only some, and since when. A failure on every page usually lies in what is checked here; one confined to a page usually lies in its template or the content it shows.'),
                ],
            ),
            new Recipe(
                id: 'email.notSending',
                title: Craft::t('web-doctor', 'Email Doctor'),
                symptom: Craft::t('web-doctor', 'Email is not being sent'),
                description: Craft::t('web-doctor', 'Looks at the mailer’s configuration and the environment it draws on, then at the queue, which sends the mail plugins queue for themselves, and the failures it recorded. Credentials are reported only as present or missing; no message is sent.'),
                category: Category::EMAIL,
                primary: [
                    RelatedArea::check('email.configuration', Craft::t('web-doctor', 'The mailer transport Craft is configured with, and whether what it needs is set: a sender address and, for SMTP, a host, and a user name and password when authentication is on. Credentials are reported only as present or missing; the host and port are named, so the service can be recognised.')),
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Mailer settings usually come from environment variables, and a variable missing in this environment leaves the mailer without it.')),
                ],
                related: [
                    RelatedArea::check('queue.failedJobs', Craft::t('web-doctor', 'Craft sends its own mail during the request that triggers it, but plugins often send theirs from queue jobs, and a send that failed there records the transport’s error with the job.')),
                    RelatedArea::check('queue.backlog', Craft::t('web-doctor', 'Mail a plugin queued is not sent while nothing runs the queue.')),
                ],
                leads: [
                    Craft::t('web-doctor', 'Craft’s logs (by default in storage/logs) for errors recorded while sending. {noLogs}', ['noLogs' => $noLogs]),
                    Craft::t('web-doctor', 'The mail provider’s own delivery log, for mail that was handed over but never arrived.'),
                    Craft::t('web-doctor', 'Sending a test message from Settings → Email in the control panel. Web Doctor never sends one itself.'),
                ],
            ),
            new Recipe(
                id: 'queue.jobsFailing',
                title: Craft::t('web-doctor', 'Queue Doctor'),
                symptom: Craft::t('web-doctor', 'Queue jobs are failing or not running'),
                description: Craft::t('web-doctor', 'Looks at how many jobs have failed and, at normal depth and deeper, whether the same job keeps failing and — in dev mode or for an administrator, as Craft shows them — the errors they recorded; at what is waiting and for how long, and any job running longer than it said it would — then at the limits and the database jobs depend on.'),
                category: Category::QUEUE,
                primary: [
                    RelatedArea::check('queue.failedJobs', Craft::t('web-doctor', 'How many jobs have failed. At normal depth and deeper, whether the same job is failing repeatedly and the error each of the most recent recorded, which is shown in dev mode or to an administrator, as Craft shows it; a shallow run counts them only.')),
                    RelatedArea::check('queue.backlog', Craft::t('web-doctor', 'How many jobs are waiting and for how long, and any job that has been running longer than it said it would.')),
                ],
                related: [
                    RelatedArea::check('php.configuration', Craft::t('web-doctor', 'A job runs under PHP’s memory and time limits, and one that exceeds them fails or is abandoned part-way.')),
                    RelatedArea::check('database.connection', Craft::t('web-doctor', 'Craft’s default queue keeps its jobs in the database.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Most queued jobs belong to plugins, and a job whose plugin has gone cannot run.')),
                ],
                leads: [
                    Craft::t('web-doctor', 'Craft’s queue log (by default storage/logs/queue.log) for what each failing job reported. {noLogs}', ['noLogs' => $noLogs]),
                    Craft::t('web-doctor', 'Whether anything is actually running the queue — a worker process, a cron entry, or the control panel’s own runner.'),
                ],
            ),
            new Recipe(
                id: 'database.failing',
                title: Craft::t('web-doctor', 'Database Doctor'),
                symptom: Craft::t('web-doctor', 'The database is failing'),
                description: Craft::t('web-doctor', 'Looks at the connection, the schema and its migrations, and the character set, then at the environment the credentials come from and the errors jobs recorded against the database.'),
                category: Category::DATABASE,
                primary: [
                    RelatedArea::check('database.connection', Craft::t('web-doctor', 'Whether Craft can connect, and to what server and version.')),
                    RelatedArea::check('database.migrations', Craft::t('web-doctor', 'Whether the schema matches the code: migrations still to run, or a schema newer than the code.')),
                    RelatedArea::check('database.charset', Craft::t('web-doctor', 'Whether the database’s character set and collation match what Craft is configured to use, and whether the table Craft itself uses as its indicator accepts four-byte characters. Other tables are not inspected.')),
                    RelatedArea::category(Category::DATABASE, Craft::t('web-doctor', 'Anything else that inspects the database.')),
                ],
                related: [
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Database connection settings usually come from environment variables.')),
                    RelatedArea::check('projectConfig.pendingChanges', Craft::t('web-doctor', 'Pending migrations and pending project config changes are normally applied together.')),
                    RelatedArea::check('queue.failedJobs', Craft::t('web-doctor', 'Craft’s default queue keeps its jobs in the database, and a job that ran into a database error recorded it.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Plugins bring tables and migrations of their own, and a plugin whose schema is out of step is a common source of database errors.')),
                ],
                leads: [
                    Craft::t('web-doctor', 'Craft’s logs (by default in storage/logs) for database errors recorded around the time the problem was seen. {noLogs}', ['noLogs' => $noLogs]),
                    Craft::t('web-doctor', 'The database server’s own error log, and whether it is running out of connections or disk space.'),
                ],
            ),
            new Recipe(
                id: 'deployment.verify',
                title: Craft::t('web-doctor', 'Deployment Doctor'),
                symptom: Craft::t('web-doctor', 'Something broke after a deployment'),
                description: Craft::t('web-doctor', 'Looks at the state now of what a deployment changes — Craft, PHP, plugins, project config, migrations and the environment — then at the queue, filesystems and storage a new release depends on. Web Doctor does not record deployments, so it cannot say what a deployment changed or when.'),
                category: Category::DEPLOYMENT,
                primary: [
                    RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'A deployment can change the version of Craft, and Craft’s installed state.')),
                    RelatedArea::category(Category::PHP, Craft::t('web-doctor', 'A new server or release can run a different PHP, with different extensions and limits.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'A deployment installs, updates or removes plugins, and a plugin whose code did not arrive fails wherever it is used.')),
                    RelatedArea::category(Category::PROJECT_CONFIG, Craft::t('web-doctor', 'Project config changes deployed with the code have to be applied before the site matches it.')),
                    RelatedArea::check('database.migrations', Craft::t('web-doctor', 'Migrations deployed with the code have to be run before the schema matches it.')),
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'A new environment has to be given every setting the old one had.')),
                ],
                related: [
                    RelatedArea::category(Category::QUEUE, Craft::t('web-doctor', 'Jobs queued by the old release can fail under the new one, and a deployment can leave nothing running the queue.')),
                    RelatedArea::category(Category::FILESYSTEM, Craft::t('web-doctor', 'Filesystem paths and credentials are part of what a deployment has to carry over.')),
                    RelatedArea::category(Category::STORAGE, Craft::t('web-doctor', 'A new release directory needs a storage directory Craft can write to.')),
                    RelatedArea::check('database.connection', Craft::t('web-doctor', 'Whether the release is connected to the database it should be.')),
                ],
                leads: [
                    Craft::t('web-doctor', 'What the deployment actually changed, and when. Web Doctor records no deployments, so it compares nothing with how the site was before.'),
                    Craft::t('web-doctor', 'Whether the deployment ran `php craft up`, which applies migrations and project config changes together.'),
                    Craft::t('web-doctor', 'Whether composer.lock and the config/project directory deployed here match version control.'),
                    Craft::t('web-doctor', 'Craft’s logs (by default in storage/logs) from the moment the deployment finished. {noLogs}', ['noLogs' => $noLogs]),
                ],
            ),
        ];
    }
}
