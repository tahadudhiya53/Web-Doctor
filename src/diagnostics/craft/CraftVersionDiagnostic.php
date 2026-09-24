<?php

namespace Tahadudhiya\WebDoctor\diagnostics\craft;

use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Which Craft is running, and whether the installation agrees with it.
 *
 * Every other answer Web Doctor gives is relative to a Craft version, so this is the first
 * thing a report needs. It is also where one genuinely dangerous state is caught: an update
 * that skipped a version Craft required passing through, which leaves the database in a shape
 * no migration path accounts for.
 *
 * Nothing here reaches the network. What update is available is a question for a system with a
 * licence and a connection; what is installed is a fact the installation already holds.
 */
class CraftVersionDiagnostic extends Diagnostic
{
    public const ID = 'craft.version';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Craft version');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::CRAFT;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Reports the Craft version and edition in use, and whether the installation was upgraded past a version it had to pass through.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $version = $this->version();
        $edition = $this->edition();

        $data = [
            'version' => $version,
            'edition' => $edition,
            'schemaVersion' => $this->schemaVersion(),
        ];

        // The licensed edition is recorded but never compared with the edition in use. Craft
        // lets a development domain run any edition it likes, and whether this is such a domain
        // is a question about the request rather than about the installation — so a console run
        // could not answer it, and a check that warned here would be wrong half the time.
        $licensedEdition = $this->licensedEdition();

        if ($licensedEdition !== null) {
            $data['licensedEdition'] = $licensedEdition;
        }

        $evidence = [$this->evidence(EvidenceType::CRAFT_VERSION, Craft::t('web-doctor', 'Craft'), $data)];

        try {
            // Answering this needs the version recorded in the database, so an installation
            // whose database is out of reach has no answer. Caught here and reported as not
            // knowing, rather than passed off as a clean upgrade path.
            $breakpointSkipped = $this->breakpointSkipped();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'Craft {version} ({edition}) is running, but the installation’s own record of it could not be read.', [
                    'version' => $version,
                    'edition' => $edition,
                ]),
                [...$evidence, Evidence::fromThrowable($e, $this->id())],
            );
        }

        if ($breakpointSkipped) {
            return $this->fail(
                Craft::t('web-doctor', 'Craft was updated past a version it had to pass through first.'),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Restore the database from a backup taken before the update, update to Craft {version} first, then update again.', [
                    'version' => $this->minimumVersion(),
                ]),
                severity: Severity::CRITICAL,
                description: Craft::t('web-doctor', 'Craft required passing through version {version} before reaching this one. Because that step was skipped, the database is in a state no migration path accounts for.', [
                    'version' => $this->minimumVersion(),
                ]),
            );
        }

        return $this->info(
            Craft::t('web-doctor', 'Craft {version} ({edition}).', [
                'version' => $version,
                'edition' => $edition,
            ]),
            $evidence,
        );
    }

    /**
     * What this check reads from outside itself. Protected so a test can state an
     * installation's version and upgrade history rather than needing one that has them.
     */
    protected function version(): string
    {
        return Craft::$app->getVersion();
    }

    protected function edition(): string
    {
        return Craft::$app->edition->name;
    }

    protected function schemaVersion(): string
    {
        return Craft::$app->schemaVersion;
    }

    protected function minimumVersion(): string
    {
        return Craft::$app->minVersionRequired;
    }

    /**
     * Whether the installation was upgraded past a version Craft required passing through.
     *
     * @throws Throwable where the database holding the answer is out of reach.
     */
    protected function breakpointSkipped(): bool
    {
        return Craft::$app->getUpdates()->getWasCraftBreakpointSkipped();
    }

    protected function licensedEdition(): ?string
    {
        try {
            return Craft::$app->getLicensedEdition()?->name;
        } catch (Throwable) {
            return null;
        }
    }
}
