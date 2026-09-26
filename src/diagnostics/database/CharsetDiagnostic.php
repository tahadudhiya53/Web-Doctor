<?php

namespace Tahadudhiya\WebDoctor\diagnostics\database;

use Craft;
use craft\db\Connection;
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
 * Whether the database is set up to store the characters the site actually contains.
 *
 * Character set problems are quiet. An emoji in an entry title, a name with an unusual
 * character, a pasted quotation mark — the save fails or the value is silently mangled, and
 * nothing anywhere says "character set".
 *
 * What this check establishes is deliberately narrower than "every column is fine", and the
 * result says so rather than implying otherwise. Three facts are gathered:
 *
 * - the character set and collation the database itself is set to;
 * - the character set Craft is configured to create new tables with;
 * - whether one table — the one Craft uses as its own indicator — accepts four-byte characters.
 *
 * That last one is Craft's `getSupportsMb4()`, which samples `elements_sites` and infers the
 * rest. Web Doctor reports it as what it is: one table, sampled, named in the evidence. A
 * database converted table by table can have a mixture this will not see, and claiming
 * otherwise would be exactly the kind of confident wrong answer a diagnostic must not give.
 * Column-by-column inspection is a job for the content and asset checks, not for this one.
 */
class CharsetDiagnostic extends Diagnostic
{
    public const ID = 'database.charset';

    /**
     * @var string The table Craft samples to decide whether the database accepts mb4, named as
     * a reader would recognise it. Craft's own constant carries its table-prefix placeholder —
     * `{{%elements_sites}}` — which belongs in a query and not in something a person reads.
     */
    private const SAMPLED_TABLE = 'elements_sites';

    /**
     * @var Connection|null The connection to inspect. Craft's own unless a caller supplies
     * another.
     */
    public ?Connection $db = null;

    public function name(): string
    {
        return Craft::t('web-doctor', 'Database character set');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::DATABASE;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Compares the character set Craft is configured for with the one the database is set to, and samples one Craft table for four-byte character support. It does not inspect every table or column.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $db = $this->connection();
        $configured = Craft::$app->getConfig()->getDb()->getCharset();
        $configuredCollation = Craft::$app->getConfig()->getDb()->collation;

        try {
            $actual = $this->actualCharset($db);
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The database’s character set could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        if ($actual === null) {
            return $this->skipped(Craft::t('web-doctor', 'This database driver does not report a character set Web Doctor knows how to read.'));
        }

        $supportsMb4 = $this->supportsMb4($db);

        $evidence = [
            $this->evidence(EvidenceType::DATABASE, Craft::t('web-doctor', 'Character set'), [
                'driver' => $db->getDriverLabel(),
                'configuredCharset' => $configured,
                'configuredCollation' => $configuredCollation,
                'databaseCharset' => $actual['charset'],
                'databaseCollation' => $actual['collation'],
                // Named so a reader can see how narrow the mb4 answer is, and check it.
                'sampledTable' => self::SAMPLED_TABLE,
                'sampledTableAcceptsMb4' => $supportsMb4,
                'columnsInspected' => false,
            ], reference: self::SAMPLED_TABLE),
        ];

        if ($db->getIsMysql() && $supportsMb4 === false) {
            return $this->warning(
                Craft::t('web-doctor', 'Craft’s element index table does not accept four-byte characters such as emoji.'),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Convert the database and its tables to utf8mb4 with a utf8mb4 collation, then set the same charset in this environment’s database settings.'),
                severity: Severity::MEDIUM,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'This is the table Craft samples to decide the question for itself, so content containing such a character will fail to save or be stored mangled. Other tables were not inspected and may differ.'),
            );
        }

        if (!self::sameCharset($actual['charset'], $configured)) {
            return $this->warning(
                Craft::t('web-doctor', 'The database is set to {actual} but Craft is configured for {configured}.', [
                    'actual' => $actual['charset'],
                    'configured' => $configured,
                ]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Bring the two into line: either convert the database to {configured}, or configure Craft for {actual}.', [
                    'configured' => $configured,
                    'actual' => $actual['charset'],
                ]),
                severity: Severity::LOW,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Tables Craft creates from now on will not match the ones already there, which is how a database ends up with a mixture.'),
            );
        }

        if ($supportsMb4 === null) {
            return $this->info(
                Craft::t('web-doctor', 'The database is set to {charset}, as configured. Four-byte character support could not be sampled.', ['charset' => $actual['charset']]),
                $evidence,
            );
        }

        return $this->pass(
            Craft::t('web-doctor', 'The database is set to {charset}, as configured, and {table} accepts four-byte characters.', [
                'charset' => $actual['charset'],
                'table' => self::SAMPLED_TABLE,
            ]),
            $evidence,
            description: Craft::t('web-doctor', 'One table was sampled, the one Craft uses as its own indicator. Individual tables and columns were not inspected.'),
        );
    }

    protected function connection(): Connection
    {
        return $this->db ?? Craft::$app->getDb();
    }

    /**
     * The character set and collation the database itself reports, or null where the driver is
     * one this check has no query for.
     *
     * Protected so a test can state a database's character set rather than needing one
     * configured that way.
     *
     * @return array{charset: string, collation: string|null}|null
     */
    protected function actualCharset(Connection $db): ?array
    {
        if ($db->getIsMysql()) {
            /** @var array<string, string>|false $row */
            $row = $db->createCommand('SELECT @@character_set_database AS [[charset]], @@collation_database AS [[collation]]')->queryOne();

            return $row === false ? null : ['charset' => (string)$row['charset'], 'collation' => (string)$row['collation']];
        }

        if ($db->getIsPgsql()) {
            $encoding = $db->createCommand('SHOW server_encoding')->queryScalar();

            return $encoding === false || $encoding === null
                ? null
                : ['charset' => strtolower((string)$encoding), 'collation' => null];
        }

        return null;
    }

    /**
     * Whether the sampled table accepts four-byte characters, as Craft itself determines it, or
     * null where the question could not be answered.
     */
    protected function supportsMb4(Connection $db): ?bool
    {
        try {
            return $db->getSupportsMb4();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether two charset names mean the same charset. MySQL 8.0.30 and MariaDB 10.6 report the
     * three-byte `utf8` as `utf8mb3`, which Craft's configuration may still call `utf8`; the
     * difference is a name, not a mismatch.
     */
    public static function sameCharset(string $a, string $b): bool
    {
        $canonical = static fn(string $name): string => strtolower($name) === 'utf8' ? 'utf8mb3' : strtolower($name);

        return $canonical($a) === $canonical($b);
    }
}
