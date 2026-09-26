<?php

namespace Tahadudhiya\WebDoctor\investigations;

use Craft;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory as Category;

/**
 * The single list of what is related to what.
 *
 * Every relationship is written out here with its reason, rather than inferred from anything —
 * so the same problem is always investigated the same way, and every check an investigation ran
 * can be traced to a sentence explaining why it was worth running. A relationship that is not
 * on this list does not exist.
 *
 * A related area is named whether or not anything covers it on a given installation. The plan
 * built from it says which areas had no check, rather than quietly investigating less.
 */
final class InvestigationRules
{
    /** @var string The rule used for a problem no other rule describes. */
    public const FALLBACK = 'general';

    /**
     * @return list<InvestigationRule>
     */
    public static function all(): array
    {
        $logs = Craft::t('web-doctor', 'Craft’s logs (by default in storage/logs) for errors recorded around the time the problem was seen. Web Doctor does not read logs.');

        return [
            new InvestigationRule(
                id: 'database',
                label: Craft::t('web-doctor', 'A database problem'),
                categories: [Category::DATABASE],
                related: [
                    RelatedArea::category(Category::QUEUE, Craft::t('web-doctor', 'Craft’s default queue keeps its jobs in the database, so a database fault usually shows up as jobs failing or piling up.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Plugins bring tables and migrations of their own, and a plugin whose schema is out of step is a common source of database errors.')),
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Database connection settings usually come from environment variables.')),
                    RelatedArea::check('projectConfig.pendingChanges', Craft::t('web-doctor', 'Pending migrations and pending project config changes are normally applied together.')),
                ],
                leads: [$logs, Craft::t('web-doctor', 'The database server’s own error log.')],
            ),
            new InvestigationRule(
                id: 'email',
                label: Craft::t('web-doctor', 'An email problem'),
                categories: [Category::EMAIL],
                related: [
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Mailer settings usually come from environment variables.')),
                    RelatedArea::check('queue.failedJobs', Craft::t('web-doctor', 'Mail sent from a queue job records the transport’s error when it fails.')),
                    RelatedArea::check('queue.backlog', Craft::t('web-doctor', 'Mail a plugin queued waits behind a stalled queue until it runs again.')),
                ],
                leads: [$logs, Craft::t('web-doctor', 'The mail provider’s own delivery log.')],
            ),
            new InvestigationRule(
                id: 'http',
                label: Craft::t('web-doctor', 'A failing request, such as a 500 error'),
                categories: [Category::HTTP],
                related: [
                    RelatedArea::category(Category::PHP, Craft::t('web-doctor', 'A request that fails outright is often PHP running out of memory or time, or missing an extension.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'An exception thrown while serving a request frequently comes from a plugin.')),
                    RelatedArea::category(Category::DATABASE, Craft::t('web-doctor', 'Almost every request reads the database.')),
                    RelatedArea::category(Category::QUEUE, Craft::t('web-doctor', 'Work a request hands to the queue fails there rather than in the request.')),
                    RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'Craft’s own state — maintenance mode, a pending update — changes what every request does.')),
                    RelatedArea::category(Category::CONFIGURATION, Craft::t('web-doctor', 'Configuration decides how a request is handled.')),
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Settings missing from the environment fail at the moment something needs them.')),
                ],
                leads: [
                    Craft::t('web-doctor', 'Craft’s logs (by default in storage/logs) for the exception and stack trace behind the failing request. Web Doctor does not read logs.'),
                    Craft::t('web-doctor', 'The web server’s error log, which records failures that happen before Craft is reached.'),
                ],
            ),
            new InvestigationRule(
                id: 'craft',
                label: Craft::t('web-doctor', 'A problem with Craft itself'),
                categories: [Category::CRAFT],
                related: [
                    RelatedArea::category(Category::PHP, Craft::t('web-doctor', 'Craft’s requirements are requirements of PHP.')),
                    RelatedArea::category(Category::DATABASE, Craft::t('web-doctor', 'Craft’s installed state and schema live in the database.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Plugins have to be compatible with the version of Craft installed.')),
                    RelatedArea::category(Category::PROJECT_CONFIG, Craft::t('web-doctor', 'An update can leave project config changes still to be applied.')),
                ],
                leads: [$logs],
            ),
            new InvestigationRule(
                id: 'php',
                label: Craft::t('web-doctor', 'A PHP problem'),
                categories: [Category::PHP],
                related: [
                    RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'Craft states what it requires of PHP.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Plugins can require PHP versions and extensions of their own.')),
                ],
                leads: [Craft::t('web-doctor', 'PHP’s own error log.')],
            ),
            new InvestigationRule(
                id: 'plugins',
                label: Craft::t('web-doctor', 'A plugin problem'),
                categories: [Category::PLUGINS],
                related: [
                    RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'Plugins have to be compatible with the version of Craft installed.')),
                    RelatedArea::category(Category::PHP, Craft::t('web-doctor', 'Plugins can require PHP versions and extensions of their own.')),
                    RelatedArea::check('database.migrations', Craft::t('web-doctor', 'A plugin update usually brings migrations of its own.')),
                    RelatedArea::check('database.connection', Craft::t('web-doctor', 'Which plugins are installed, and in what state, is read from the database.')),
                    RelatedArea::category(Category::PROJECT_CONFIG, Craft::t('web-doctor', 'Which plugins are installed, and with what settings, is recorded in project config.')),
                ],
                leads: [$logs],
            ),
            new InvestigationRule(
                id: 'queue',
                label: Craft::t('web-doctor', 'A queue problem'),
                categories: [Category::QUEUE],
                related: [
                    RelatedArea::category(Category::DATABASE, Craft::t('web-doctor', 'Craft’s default queue keeps its jobs in the database.')),
                    RelatedArea::check('php.configuration', Craft::t('web-doctor', 'A job runs under PHP’s memory and time limits.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Most queued jobs belong to plugins.')),
                ],
                leads: [
                    Craft::t('web-doctor', 'Craft’s queue log (by default storage/logs/queue.log) for what each failing job reported.'),
                    Craft::t('web-doctor', 'Whether anything is actually running the queue — a worker process, a cron entry, or the control panel’s own runner.'),
                ],
            ),
            new InvestigationRule(
                id: 'filesystem',
                label: Craft::t('web-doctor', 'A filesystem problem'),
                categories: [Category::FILESYSTEM, Category::ASSETS],
                related: [
                    RelatedArea::category(Category::FILESYSTEM, Craft::t('web-doctor', 'Assets are stored on filesystems.')),
                    RelatedArea::category(Category::STORAGE, Craft::t('web-doctor', 'A local filesystem shares the server, and its permissions, with Craft’s own storage.')),
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Filesystem paths and credentials usually come from environment variables.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Remote filesystem types are provided by plugins.')),
                ],
                leads: [Craft::t('web-doctor', 'The storage provider’s own status and access logs, for a remote filesystem.')],
            ),
            new InvestigationRule(
                id: 'storage',
                label: Craft::t('web-doctor', 'A storage problem'),
                categories: [Category::STORAGE],
                related: [
                    RelatedArea::category(Category::FILESYSTEM, Craft::t('web-doctor', 'A local filesystem shares the server, and its permissions, with Craft’s own storage.')),
                ],
                leads: [Craft::t('web-doctor', 'Who owns the storage directory on the server, and which user PHP runs as.')],
            ),
            new InvestigationRule(
                id: 'environment',
                label: Craft::t('web-doctor', 'An environment problem'),
                categories: [Category::ENVIRONMENT, Category::CONFIGURATION],
                related: [
                    RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Configuration is usually drawn from the environment.')),
                    RelatedArea::check('database.connection', Craft::t('web-doctor', 'Database credentials usually come from the environment.')),
                    RelatedArea::check('email.configuration', Craft::t('web-doctor', 'Mailer settings usually come from the environment.')),
                    RelatedArea::category(Category::FILESYSTEM, Craft::t('web-doctor', 'Filesystem paths and credentials usually come from the environment.')),
                    RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'Craft reads its own environment on every request.')),
                ],
                leads: [Craft::t('web-doctor', 'Whether the .env file, or the host’s environment settings, match what this environment is meant to have.')],
            ),
            new InvestigationRule(
                id: 'projectConfig',
                label: Craft::t('web-doctor', 'A project config problem'),
                categories: [Category::PROJECT_CONFIG],
                related: [
                    RelatedArea::check('database.migrations', Craft::t('web-doctor', 'Pending migrations and pending project config changes are normally applied together.')),
                    RelatedArea::check('database.connection', Craft::t('web-doctor', 'Project config is applied to, and compared against, what the database holds.')),
                    RelatedArea::category(Category::PLUGINS, Craft::t('web-doctor', 'Which plugins are installed, and with what settings, is recorded in project config.')),
                    RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'Project config records the Craft schema version it was written by.')),
                ],
                leads: [Craft::t('web-doctor', 'Whether the config/project directory deployed here matches the one in version control.')],
            ),
        ];
    }

    /**
     * The rule for a problem in this category. The first rule that names the category wins;
     * a category no rule names gets {@see self::fallback()}.
     */
    public static function for(Category $category): InvestigationRule
    {
        foreach (self::all() as $rule) {
            if ($rule->appliesTo($category)) {
                return $rule;
            }
        }

        return self::fallback($category);
    }

    /**
     * Looks at the problem's own area, then at the two things nearly every problem touches.
     */
    public static function fallback(Category $category): InvestigationRule
    {
        return new InvestigationRule(
            id: self::FALLBACK,
            label: Craft::t('web-doctor', 'A problem in {category}', ['category' => $category->label()]),
            categories: [],
            related: [
                RelatedArea::category(Category::CRAFT, Craft::t('web-doctor', 'Craft’s own state affects every part of the installation.')),
                RelatedArea::category(Category::ENVIRONMENT, Craft::t('web-doctor', 'Most settings come from the environment.')),
            ],
            leads: [Craft::t('web-doctor', 'Craft’s logs (by default in storage/logs) for errors recorded around the time the problem was seen. Web Doctor does not read logs.')],
        );
    }
}
