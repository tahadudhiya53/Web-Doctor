<?php

namespace Tahadudhiya\WebDoctor\diagnostics\plugins;

use Craft;
use craft\db\Query;
use craft\db\Table;
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
 * Plugins that are installed but not working.
 *
 * Craft is forgiving about this by design: a plugin whose code has gone missing, or whose class
 * will not load, is quietly left out rather than allowed to take the site down with it. That is
 * the right behaviour for a CMS and the wrong behaviour for the person trying to work out why a
 * section of the site stopped existing — the plugin simply is not there any more, and nothing
 * says so.
 *
 * Two states are worth naming. A plugin the database says is switched on, which never loaded,
 * has failed to initialise. A plugin the database has a record of, with no code behind it at
 * all, was removed from the project without being uninstalled — and its tables, its content and
 * its project config entries are still in place.
 *
 * Licensing problems are reported separately and much more gently. They are Craft's business,
 * they are reported in Craft's own interface, and they are not why a site is broken.
 *
 * Only some of them are reported at all. Craft decides several licensing issues by asking
 * whether this request may test editions, which is false for every command-line run regardless
 * of the site — so a run from the terminal would raise `wrong_edition` and `required` against a
 * developer machine where the control panel shows nothing at all. Those are recorded as context
 * for a reader and kept out of the finding; what is left — invalid, mismatched, astray — comes
 * straight from Craft's licensing service and means the same thing wherever it is asked.
 */
class PluginHealthDiagnostic extends Diagnostic
{
    public const ID = 'plugins.health';

    /**
     * @var string[] The licensing problems that mean the same thing however Craft was reached.
     *
     * Craft's other licensing issues — `wrong_edition`, `required`, `no_trials` — are decided
     * partly by `getCanTestEditions()`, which is false for every console run whatever the site.
     * Reporting those would mean a command-line check contradicting the control panel.
     */
    private const CONTEXT_INDEPENDENT_LICENSE_ISSUES = ['invalid', 'mismatched', 'astray'];

    public function name(): string
    {
        return Craft::t('web-doctor', 'Plugin health');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::PLUGINS;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Looks for plugins that are recorded as installed but never loaded, plugins whose code is no longer present, and plugins with licensing problems.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $info = $this->pluginInfo();
            $recorded = $this->recordedHandles();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The state of the installed plugins could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $notLoaded = [];
        $licensing = [];
        $contextual = [];

        // Sorted by handle so the lists this check reports never depend on the order Craft
        // or Composer happened to return things in.
        ksort($info, SORT_STRING);
        sort($recorded, SORT_STRING);

        foreach ($info as $handle => $plugin) {
            if (!($plugin['isInstalled'] ?? false)) {
                continue;
            }

            // `isEnabled` is Craft's answer to "did the plugin object come into being", while
            // `isPluginEnabled` is its answer to "is it supposed to". The two disagreeing is
            // exactly what a plugin that failed to initialise looks like.
            if (!($plugin['isEnabled'] ?? false) && $this->isSwitchedOn($handle)) {
                $notLoaded[] = $handle;
            }

            $issues = $plugin['licenseIssues'] ?? [];

            if (is_array($issues) && $issues !== []) {
                $definite = array_values(array_intersect($issues, self::CONTEXT_INDEPENDENT_LICENSE_ISSUES));

                if ($definite !== []) {
                    $licensing[$handle] = $definite;
                }

                $contextual[$handle] = array_values(array_diff($issues, self::CONTEXT_INDEPENDENT_LICENSE_ISSUES));
            }
        }

        $missing = array_values(array_diff($recorded, array_keys($info)));
        sort($missing, SORT_STRING);
        sort($notLoaded, SORT_STRING);
        ksort($licensing, SORT_STRING);
        ksort($contextual, SORT_STRING);

        $evidence = [
            $this->evidence(EvidenceType::PLUGIN_VERSION, Craft::t('web-doctor', 'Plugin state'), [
                'recorded' => count($recorded),
                'failedToLoad' => $notLoaded,
                'missingFromProject' => $missing,
                'licensing' => array_keys($licensing),
                // Kept apart because these depend on how Craft was reached rather than on the
                // installation, so they are context for a reader rather than a finding.
                'licensingDependsOnContext' => array_keys(array_filter($contextual)),
            ]),
        ];

        if ($missing !== []) {
            return $this->fail(
                Craft::t('web-doctor', 'Plugins are recorded as installed but their code is not in the project: {handles}.', [
                    'handles' => implode(', ', $missing),
                ]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Either reinstate these packages with Composer, or uninstall them through Craft so their tables and project config entries are removed properly.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Removing a plugin from a project without uninstalling it leaves its tables, its content and its project config entries behind, with nothing left to interpret them.'),
            );
        }

        if ($notLoaded !== []) {
            return $this->fail(
                Craft::t('web-doctor', 'Plugins are switched on but did not load: {handles}.', ['handles' => implode(', ', $notLoaded)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Check Craft’s logs for the failure that stopped each one initialising, and that its package is installed at a compatible version.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft leaves out a plugin it cannot initialise rather than failing the request, so everything the plugin provides is simply absent.'),
            );
        }

        if ($licensing !== []) {
            return $this->warning(
                Craft::t('web-doctor', 'Plugins have licensing problems: {handles}.', ['handles' => implode(', ', array_keys($licensing))]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Resolve these under Settings → Plugins in the control panel.'),
                severity: Severity::LOW,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Licensing does not stop a plugin working, so this is reported for attention rather than as a fault.'),
            );
        }

        return $this->pass(Craft::t('web-doctor', 'Every installed plugin loaded as expected.'), $evidence);
    }

    /**
     * What Craft knows about every plugin in the project. Protected, with the two below, so a
     * test can state a broken project rather than needing one.
     *
     * @return array<string, array<string, mixed>>
     * @throws Throwable where the database holding the answer is out of reach.
     */
    protected function pluginInfo(): array
    {
        return Craft::$app->getPlugins()->getAllPluginInfo();
    }

    /**
     * Whether the installation is supposed to be running this plugin.
     *
     * Distinct from Craft's `isEnabled`, which reports whether the plugin object came into
     * being. The two disagreeing is what a plugin that failed to initialise looks like.
     */
    protected function isSwitchedOn(string $handle): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled($handle);
    }

    /**
     * The plugin handles the database has a record of, which is the only place a plugin whose
     * code has gone missing still exists.
     *
     * Craft's plugins service cannot answer this: it iterates the plugins Composer installed,
     * so a row with no package behind it is invisible to it. The table is small and read once.
     *
     * @return string[]
     */
    protected function recordedHandles(): array
    {
        /** @var string[] $handles */
        $handles = (new Query())
            ->select(['handle'])
            ->from([Table::PLUGINS])
            ->column();

        return $handles;
    }
}
