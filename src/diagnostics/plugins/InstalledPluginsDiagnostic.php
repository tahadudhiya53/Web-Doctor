<?php

namespace Tahadudhiya\WebDoctor\diagnostics\plugins;

use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * What is installed alongside Craft, and at which versions.
 *
 * This states a fact rather than passing a judgement — which is the point. Almost every
 * investigation ends up asking what was installed and at what version, and almost every
 * environment comparison is a comparison of exactly this list. Recording it as evidence on
 * every run is what makes those later questions answerable.
 *
 * Licence keys are never part of it. The licence *status* is, because "invalid" is a fact worth
 * having; the key itself is a credential and is not recorded in any form.
 */
class InstalledPluginsDiagnostic extends Diagnostic
{
    public const ID = 'plugins.installed';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Installed plugins');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::PLUGINS;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Records which plugins are installed, at which versions and editions, and which of them are switched on.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $info = $this->pluginInfo();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The list of installed plugins could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $installed = [];
        $evidence = [];

        // Craft sorts this by display name, which leaves two plugins sharing a name in
        // whichever order Composer happened to list them — and it is not a documented
        // guarantee in any case. Sorted by handle here, which is unique by construction, so
        // two runs of the same site are comparable whatever Craft does next.
        ksort($info, SORT_STRING);

        foreach ($info as $handle => $plugin) {
            if (!($plugin['isInstalled'] ?? false)) {
                continue;
            }

            $installed[$handle] = [
                'name' => $plugin['name'] ?? $handle,
                'version' => $plugin['version'] ?? null,
                'edition' => $plugin['edition'] ?? null,
                'enabled' => $plugin['isEnabled'] ?? false,
                'licenseStatus' => $plugin['licenseKeyStatus'] ?? null,
            ];

            // One piece of evidence per plugin, so a later investigation can correlate a
            // failure with the version of the plugin it blames without unpicking a list.
            $evidence[] = $this->evidence(EvidenceType::PLUGIN_VERSION, (string)($plugin['name'] ?? $handle), [
                'handle' => $handle,
            ] + $installed[$handle]);
        }

        if ($installed === []) {
            return $this->info(Craft::t('web-doctor', 'No plugins are installed.'));
        }

        $disabled = array_keys(array_filter($installed, static fn(array $p): bool => !$p['enabled']));

        if ($disabled !== []) {
            return $this->info(
                Craft::t('web-doctor', 'Plugins installed: {count}. Switched off: {handles}.', [
                    'count' => count($installed),
                    'handles' => implode(', ', $disabled),
                ]),
                $evidence,
                description: Craft::t('web-doctor', 'Switching a plugin off is a deliberate act, so this is recorded rather than treated as a problem.'),
            );
        }

        return $this->info(
            Craft::t('web-doctor', 'Plugins installed: {count}. All are switched on.', ['count' => count($installed)]),
            $evidence,
        );
    }

    /**
     * What Craft knows about every plugin in the project, through Craft's own plugins service.
     *
     * Craft sorts these by display name; this check sorts them again by handle rather than
     * relying on that. Protected so a test can state a project's plugins rather than needing
     * them installed.
     *
     * @return array<string, array<string, mixed>>
     * @throws Throwable where the database holding the answer is out of reach.
     */
    protected function pluginInfo(): array
    {
        return Craft::$app->getPlugins()->getAllPluginInfo();
    }
}
