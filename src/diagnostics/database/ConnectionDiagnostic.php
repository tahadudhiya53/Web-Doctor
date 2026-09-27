<?php

namespace Tahadudhiya\WebDoctor\diagnostics\database;

use Craft;
use craft\db\Connection;
use craft\helpers\App;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Requirements;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Whether Craft can reach its database, and whether that database is one Craft supports.
 *
 * A database Craft cannot reach is the single failure that explains the most other failures, so
 * this check says so plainly and with the connection error kept as evidence — the error being
 * the one thing that distinguishes a wrong password from a server that is not running.
 *
 * Connection settings are reported, credentials are not. The user name, the password and the
 * DSN never leave this check as anything but a statement that they are present or missing: a
 * DSN in particular routinely carries the password inside it.
 */
class ConnectionDiagnostic extends Diagnostic
{
    public const ID = 'database.connection';

    /**
     * @var Connection|null The connection to inspect. Craft's own unless a caller supplies
     * another, which is how the unreachable-database path is exercised without a site having
     * to actually lose its database.
     */
    public ?Connection $db = null;

    public function name(): string
    {
        return Craft::t('web-doctor', 'Database connection');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::DATABASE;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks that Craft can connect to its database and that the server version is one Craft supports.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $db = $this->db ?? Craft::$app->getDb();
        $config = Craft::$app->getConfig()->getDb();

        $settings = $this->evidence(EvidenceType::CONFIGURATION, Craft::t('web-doctor', 'Database settings'), [
            'driver' => $config->driver,
            'server' => $config->server,
            'port' => $config->port,
            'database' => $config->database,
            'tablePrefix' => $config->tablePrefix,
            'charset' => $config->getCharset(),
            'user' => Redaction::presence($config->user),
            'password' => Redaction::presence($config->password),
        ]);

        try {
            // Craft opens its connection lazily, so this is the point where a wrong password or
            // an unreachable server actually becomes an error. Caught here so that the fact
            // becomes a finding about the site rather than a broken check.
            $db->open();
            $serverVersion = $db->getServerVersion();
            $driverLabel = $db->getDriverLabel();
        } catch (Throwable $e) {
            return $this->fail(
                Craft::t('web-doctor', 'Craft cannot connect to its database.'),
                [
                    $settings,
                    $this->evidence(EvidenceType::DATABASE_ERROR, Craft::t('web-doctor', 'Connection attempt'), ['succeeded' => false]),
                    Evidence::fromThrowable($e, $this->id()),
                ],
                recommendation: Craft::t('web-doctor', 'Check that the database server is running and that this environment’s database settings point at it.'),
                severity: Severity::CRITICAL,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Nothing that reads or writes data can work until this is resolved, so other findings in this run may be consequences of it.'),
            );
        }

        $required = $this->requiredVersion($db);
        $connection = $this->evidence(EvidenceType::DATABASE, Craft::t('web-doctor', 'Database server'), [
            'succeeded' => true,
            'driver' => $driverLabel,
            'serverVersion' => $serverVersion,
            'required' => $required,
        ]);

        $evidence = [$settings, $connection];

        // Not a non-finding: without the minimum, a server Craft does not support would raise
        // nothing. As the PHP version check answers the same gap.
        if ($required === null) {
            return $this->unknown(
                Craft::t('web-doctor', 'Connected to {driver} {version}. The version Craft requires could not be read.', [
                    'driver' => $driverLabel,
                    'version' => $serverVersion,
                ]),
                $evidence,
            );
        }

        if (version_compare($this->comparableVersion($serverVersion), $required, '<')) {
            return $this->fail(
                Craft::t('web-doctor', '{driver} {version} is below the {required} Craft requires.', [
                    'driver' => $driverLabel,
                    'version' => $serverVersion,
                    'required' => $required,
                ]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Upgrade the database server to {required} or later.', ['required' => $required]),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
            );
        }

        return $this->pass(
            Craft::t('web-doctor', 'Connected to {driver} {version}.', [
                'driver' => $driverLabel,
                'version' => $serverVersion,
            ]),
            $evidence,
        );
    }

    /**
     * The minimum server version Craft requires for the database actually in use.
     */
    protected function requiredVersion(Connection $db): ?string
    {
        $versions = Requirements::databaseVersions();

        $key = match (true) {
            $db->getIsMaria() => 'mariadb',
            $db->getIsMysql() => 'mysql',
            $db->getIsPgsql() => 'pgsql',
            default => null,
        };

        return $key === null ? null : ($versions[$key] ?? null);
    }

    /**
     * The part of a reported server version that can be compared.
     *
     * Servers decorate their version strings — `10.11.6-MariaDB-log`, `8.0.35-0ubuntu0.22.04.1`,
     * and MariaDB before 11 as `5.5.5-10.6.12-MariaDB` — and `version_compare` would read those as
     * pre-release markers or as the wrong version. Craft reads them with this same helper.
     */
    private function comparableVersion(string $version): string
    {
        return App::normalizeVersion($version) ?: $version;
    }
}
