<?php

namespace Tahadudhiya\WebDoctor\diagnostics\php;

use Composer\Semver\Semver;
use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Requirements;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Throwable;

/**
 * Whether the PHP running Craft is the PHP Craft asked for.
 *
 * The version Craft requires is read from Craft's own package manifest rather than written
 * down here, so a Craft release that raises its requirement raises this check with it. Where
 * that requirement cannot be read, the version is reported without a verdict — an unavailable
 * answer is a far smaller failure than a confident wrong one.
 *
 * The command line and the web server frequently run different PHP builds, so the result says
 * which one it looked at.
 */
class PhpVersionDiagnostic extends Diagnostic
{
    public const ID = 'php.version';

    public function name(): string
    {
        return Craft::t('web-doctor', 'PHP version');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::PHP;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Compares the PHP version running Craft against the version Craft declares it requires.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $version = $this->runtimeVersion();
        $constraint = $this->requirement();

        $evidence = [
            $this->evidence(EvidenceType::PHP_VERSION, Craft::t('web-doctor', 'PHP'), [
                'version' => $version,
                'fullVersion' => $this->fullRuntimeVersion(),
                'sapi' => $this->sapi(),
                'required' => $constraint,
            ]),
        ];

        if ($constraint === null) {
            return $this->unknown(
                Craft::t('web-doctor', 'PHP {version} is running. The version Craft requires could not be read.', ['version' => $version]),
                $evidence,
            );
        }

        $satisfied = $this->satisfies($version, $constraint);

        if ($satisfied === null) {
            return $this->unknown(
                Craft::t('web-doctor', 'PHP {version} is running. Craft requires “{constraint}”, which could not be interpreted.', [
                    'version' => $version,
                    'constraint' => $constraint,
                ]),
                $evidence,
            );
        }

        if (!$satisfied) {
            return $this->fail(
                Craft::t('web-doctor', 'PHP {version} does not meet Craft’s requirement of {constraint}.', [
                    'version' => $version,
                    'constraint' => $constraint,
                ]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Move this environment to a PHP version matching {constraint}, under both the web server and the command line.', [
                    'constraint' => $constraint,
                ]),
                severity: Severity::CRITICAL,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft is not supported on this PHP version, so failures anywhere else may be explained by it alone.'),
            );
        }

        return $this->pass(
            Craft::t('web-doctor', 'PHP {version} meets Craft’s requirement of {constraint}.', [
                'version' => $version,
                'constraint' => $constraint,
            ]),
            $evidence,
        );
    }

    /**
     * Whether the version satisfies the constraint, or null where the constraint could not be
     * interpreted.
     *
     * Composer's own comparer, because Craft states its requirement as a Composer constraint
     * and reimplementing how one is read is how two answers to the same question appear.
     */
    private function satisfies(string $version, string $constraint): ?bool
    {
        // Composer's semver library arrives with Craft. Its absence would mean a broken
        // install rather than a finding about PHP, so it is reported as not knowing.
        if (!class_exists(Semver::class)) {
            return null;
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The comparable runtime version, without the build suffix distributions add.
     *
     * Protected, along with the three below, so a test can state a version and a requirement
     * rather than needing an installation that has them. They are the whole of what this check
     * reads from outside itself.
     */
    protected function runtimeVersion(): string
    {
        return sprintf('%d.%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION);
    }

    protected function fullRuntimeVersion(): string
    {
        return PHP_VERSION;
    }

    protected function sapi(): string
    {
        return PHP_SAPI;
    }

    protected function requirement(): ?string
    {
        return Requirements::phpConstraint();
    }
}
