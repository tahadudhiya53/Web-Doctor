<?php

namespace Tahadudhiya\WebDoctor\diagnostics\projectconfig;

use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Whether the project config on disk has been applied to this installation.
 *
 * Pending changes are the ordinary state of a deployment that has not finished. The files
 * describe sections, fields and settings the database has not been told about yet, so the site
 * is running the previous configuration while the code expects the new one — and the symptoms
 * are missing fields and missing sections rather than anything mentioning project config.
 *
 * How much it matters depends on whether anybody can still apply them. Where administrative
 * changes are allowed, this is a step someone has yet to take. Where they are not — the usual
 * arrangement on a served environment — nothing will apply them except a deployment that
 * already went past this point.
 */
class PendingChangesDiagnostic extends Diagnostic
{
    public const ID = 'projectConfig.pendingChanges';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Pending project config changes');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::PROJECT_CONFIG;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks whether the project config files hold changes this installation has not applied.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $state = $this->state();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The project config could not be compared with the database.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $evidence = [
            $this->evidence(EvidenceType::PROJECT_CONFIG, Craft::t('web-doctor', 'Project config state'), $state),
        ];

        if (!$state['externalConfigExists']) {
            return $this->info(
                Craft::t('web-doctor', 'This installation keeps no project config files, so there is nothing to apply.'),
                $evidence,
                description: Craft::t('web-doctor', 'Configuration lives only in the database here. That is a supported arrangement, not a fault.'),
            );
        }

        if (!$state['changesPending']) {
            return $this->pass(Craft::t('web-doctor', 'The project config files are applied.'), $evidence);
        }

        // Where administrative changes are switched off, no one can apply these from the
        // control panel, so the installation stays out of step until a deployment applies them.
        $unattended = !$state['allowAdminChanges'];

        return $this->result(
            $unattended ? DiagnosticStatus::FAIL : DiagnosticStatus::WARNING,
            Craft::t('web-doctor', 'The project config files hold changes this installation has not applied.'),
            severity: $unattended ? Severity::HIGH : Severity::MEDIUM,
            description: $unattended
                ? Craft::t('web-doctor', 'Administrative changes are switched off here, so nothing will apply these except a deployment step that has evidently not run.')
                : Craft::t('web-doctor', 'The database is running the previous configuration while the files describe a newer one, which shows up as missing fields and sections rather than as anything naming project config.'),
            evidence: $evidence,
            recommendation: Craft::t('web-doctor', 'Run `php craft up` in this environment, and add it to the deployment so it runs every time.'),
            confidence: Confidence::CONFIRMED,
        );
    }

    /**
     * The project config's state, through Craft's own service.
     *
     * `areChangesPending()` is only asked once there are files to compare against, because with
     * no external config there is nothing that could be pending.
     *
     * Protected so a test can state a half-deployed installation rather than needing one.
     *
     * @return array{externalConfigExists: bool, changesPending: bool, writeYamlAutomatically: bool, readOnly: bool, allowAdminChanges: bool}
     * @throws Throwable where the project config cannot be read.
     */
    protected function state(): array
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $externalConfigExists = $projectConfig->getDoesExternalConfigExist();

        return [
            'externalConfigExists' => $externalConfigExists,
            'changesPending' => $externalConfigExists && $projectConfig->areChangesPending(),
            'writeYamlAutomatically' => $projectConfig->writeYamlAutomatically,
            'readOnly' => $projectConfig->readOnly,
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ];
    }
}
