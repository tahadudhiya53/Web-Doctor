<?php

namespace Tahadudhiya\WebDoctor\diagnostics\php;

use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Requirements;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * Whether the PHP extensions Craft depends on are actually loaded.
 *
 * The list is Craft's own. Craft states it in two places and they do not agree: its package
 * manifest holds the extensions Composer enforces, while its requirements checker marks several
 * more as mandatory — `fileinfo`, `gd`, `iconv` and others — that Composer never sees. Reading
 * only the manifest would miss them, and missing a mandatory extension is how a site ends up
 * broken in a way nothing explains.
 *
 * GD in particular is a hard requirement, not one of a pair. Craft marks GD mandatory and
 * Imagick explicitly optional, so a server with Imagick and no GD does not meet Craft's
 * requirements however capable it looks. Imagick is reported as what Craft calls it: recommended.
 *
 * A missing extension under the command line and a missing extension under the web server are
 * different problems with the same name, so the result says which PHP was inspected.
 */
class PhpExtensionsDiagnostic extends Diagnostic
{
    public const ID = 'php.extensions';

    public function name(): string
    {
        return Craft::t('web-doctor', 'PHP extensions');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::PHP;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks that the PHP extensions Craft marks as required are loaded, and reports the ones it merely recommends.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $required = $this->required();

        if ($required === []) {
            return $this->unknown(Craft::t('web-doctor', 'The extensions Craft requires could not be read.'));
        }

        $loaded = [];
        $missing = [];

        foreach ($required as $extension) {
            if ($this->isLoaded($extension)) {
                $loaded[] = $extension;
            } else {
                $missing[] = $extension;
            }
        }

        $recommended = $this->recommended();
        $recommendedMissing = array_values(array_filter($recommended, fn(string $e): bool => !$this->isLoaded($e)));

        $evidence = [
            $this->evidence(EvidenceType::CONFIGURATION, Craft::t('web-doctor', 'PHP extensions'), [
                'sapi' => $this->sapi(),
                'required' => $required,
                'loaded' => $loaded,
                'missing' => $missing,
                'recommended' => $recommended,
                'recommendedMissing' => $recommendedMissing,
            ]),
        ];

        if ($missing !== []) {
            return $this->fail(
                Craft::t('web-doctor', 'PHP extensions Craft requires are not loaded: {extensions}.', [
                    'extensions' => implode(', ', $missing),
                ]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Install and enable {extensions} for the PHP that runs Craft, under both the web server and the command line.', [
                    'extensions' => implode(', ', $missing),
                ]),
                severity: Severity::CRITICAL,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft marks these as required, so parts of it will fail outright without them.'),
            );
        }

        if ($recommendedMissing !== []) {
            return $this->info(
                Craft::t('web-doctor', 'Every extension Craft requires is loaded. Not loaded, and recommended rather than required: {extensions}.', [
                    'extensions' => implode(', ', $recommendedMissing),
                ]),
                $evidence,
                description: Craft::t('web-doctor', 'Craft works without these. Imagick, for instance, is what it recommends for animated GIF and transparent PNG handling.'),
            );
        }

        return $this->pass(
            Craft::t('web-doctor', 'All {count} extensions Craft requires are loaded, along with the ones it recommends.', [
                'count' => count($required),
            ]),
            $evidence,
        );
    }

    /**
     * What Craft says it needs, and what this PHP has. Protected so a test can state a server
     * missing an extension rather than needing one; between them these are the whole of what
     * this check reads from outside itself.
     *
     * @return string[]
     */
    protected function required(): array
    {
        return Requirements::extensions();
    }

    /**
     * @return string[]
     */
    protected function recommended(): array
    {
        return Requirements::recommendedExtensions();
    }

    protected function isLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    protected function sapi(): string
    {
        return PHP_SAPI;
    }
}
