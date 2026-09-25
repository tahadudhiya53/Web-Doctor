<?php

namespace Tahadudhiya\WebDoctor\diagnostics\craft;

use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Whether the application itself came up, and in what state it is serving.
 *
 * This is the check that gives every other result its footing. A Craft that is not installed,
 * or is still in maintenance mode after an update that did not finish, will produce confusing
 * findings everywhere else — so the state of the application is stated plainly rather than left
 * to be inferred from a scattering of unrelated failures.
 *
 * Being offline is reported, not judged. Taking a site offline is a deliberate act, and Web
 * Doctor has no way to tell a planned maintenance window from an accident.
 */
class ApplicationDiagnostic extends Diagnostic
{
    public const ID = 'craft.application';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Craft application');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::CRAFT;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Reports whether Craft is installed and serving, and whether it is still in maintenance mode.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $installed = $this->isInstalled();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'Whether Craft is installed could not be determined.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        if (!$installed) {
            return $this->fail(
                Craft::t('web-doctor', 'Craft is not installed.'),
                [$this->state(['installed' => false])],
                recommendation: Craft::t('web-doctor', 'Run `php craft install`, or restore the database this installation belongs to.'),
                severity: Severity::CRITICAL,
                description: Craft::t('web-doctor', 'Craft’s own tables are not present, so nothing that depends on them can be diagnosed.'),
            );
        }

        $maintenance = $this->isInMaintenanceMode();
        $live = $this->isLive();

        $evidence = [
            $this->state([
                'installed' => true,
                'live' => $live ?? Redaction::UNKNOWN,
                'maintenanceMode' => $maintenance ?? Redaction::UNKNOWN,
                'sites' => $this->siteCount(),
            ]),
        ];

        if ($maintenance === true) {
            return $this->warning(
                Craft::t('web-doctor', 'Craft is in maintenance mode.'),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Finish or roll back the interrupted update. Craft clears maintenance mode once an update completes.'),
                severity: Severity::MEDIUM,
                description: Craft::t('web-doctor', 'Craft sets maintenance mode while it updates and clears it when the update finishes. Still being in it usually means an update stopped part way.'),
            );
        }

        // Asked before the good news is given. Craft being installed says nothing about
        // whether it is serving, so a state that could not be read has to be reported as not
        // known — a pass here would be this check saying "all well" about something it failed
        // to look at.
        $undetermined = array_keys(array_filter([
            'maintenanceMode' => $maintenance === null,
            'live' => $live === null,
        ]));

        if ($undetermined !== []) {
            return $this->unknown(
                Craft::t('web-doctor', 'Craft is installed, but its serving state could not be read: {states}.', [
                    'states' => implode(', ', $undetermined),
                ]),
                $evidence,
            );
        }

        if ($live === false) {
            return $this->info(
                Craft::t('web-doctor', 'Craft is installed and running, with the system offline.'),
                $evidence,
                description: Craft::t('web-doctor', 'Taking a system offline is deliberate, so this is reported rather than treated as a problem.'),
            );
        }

        return $this->pass(Craft::t('web-doctor', 'Craft is installed and serving.'), $evidence);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function state(array $state): Evidence
    {
        return $this->evidence(EvidenceType::SYSTEM, Craft::t('web-doctor', 'Application state'), $state);
    }

    /**
     * What this check reads from outside itself. Protected so a test can state an
     * application's condition rather than needing one in it.
     *
     * @throws Throwable where the database holding the answer is out of reach.
     */
    protected function isInstalled(): bool
    {
        return Craft::$app->getIsInstalled();
    }

    protected function isInMaintenanceMode(): ?bool
    {
        try {
            return Craft::$app->getIsInMaintenanceMode();
        } catch (Throwable) {
            return null;
        }
    }

    protected function isLive(): ?bool
    {
        try {
            return Craft::$app->getIsLive();
        } catch (Throwable) {
            return null;
        }
    }

    protected function siteCount(): int|string
    {
        try {
            return count(Craft::$app->getSites()->getAllSites(true));
        } catch (Throwable) {
            // Context rather than a verdict: how many sites there are does not decide whether
            // Craft is serving, so this one not being readable is recorded and no more.
            return Redaction::UNKNOWN;
        }
    }
}
