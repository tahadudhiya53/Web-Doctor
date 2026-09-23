<?php

namespace Tahadudhiya\WebDoctor\diagnostics\projectconfig;

use Craft;
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
 * Whether the project config files can safely be applied at all.
 *
 * Craft refuses to apply project config written by a different schema version, because applying
 * it would run migrations and configuration changes against each other. The refusal is correct
 * and the consequence is confusing: `php craft up` does nothing in particular, the site keeps
 * running the old configuration, and the reason is a version number in a file.
 *
 * So the versions are compared and the mismatch is named, with the component whose schema
 * disagrees and both of the versions involved — which together say whether the code or the
 * files are the thing that is behind.
 */
class ProjectConfigIntegrityDiagnostic extends Diagnostic
{
    public const ID = 'projectConfig.integrity';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Project config integrity');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::PROJECT_CONFIG;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks that the project config files were written by the same schema versions as the code that would apply them.');
    }

    /**
     * Whether this check has anything to say here.
     *
     * With no project config files there is nothing whose schema versions could disagree. A
     * project config that cannot be read at all is a different matter, so that is left to run
     * and report what it found.
     */
    public function isApplicable(DiagnosticContext $context): bool
    {
        try {
            return $this->externalConfigExists();
        } catch (Throwable) {
            return true;
        }
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            ['compatible' => $compatible, 'issues' => $issues] = $this->schemaVersionCheck();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The project config schema versions could not be compared.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $evidence = [
            $this->evidence(EvidenceType::PROJECT_CONFIG, Craft::t('web-doctor', 'Project config schema versions'), [
                'compatible' => $compatible,
                // Craft's own shape: each entry names the component and both versions. Passed
                // through redaction like everything else, since it is another package's data.
                'mismatches' => $issues,
            ]),
        ];

        if ($compatible) {
            return $this->pass(
                Craft::t('web-doctor', 'The project config files match the schema versions of the code that would apply them.'),
                $evidence,
            );
        }

        // Craft populates each issue with a `cause`, but this is another package's data
        // structure and a malformed entry must not turn a finding into a broken check.
        $causes = [];

        foreach ($issues as $issue) {
            if (is_array($issue) && isset($issue['cause']) && is_scalar($issue['cause'])) {
                $causes[] = (string)$issue['cause'];
            }
        }

        return $this->fail(
            $causes === []
                ? Craft::t('web-doctor', 'The project config files were written by different schema versions than the code running here.')
                : Craft::t('web-doctor', 'The project config files disagree with the code on schema version: {causes}.', ['causes' => implode(', ', $causes)]),
            $evidence,
            recommendation: Craft::t('web-doctor', 'Deploy the versions the files were written by, or regenerate the files from this installation once it is the one that is correct.'),
            severity: Severity::HIGH,
            confidence: Confidence::CONFIRMED,
            description: Craft::t('web-doctor', 'Craft will not apply project config across a schema version difference, so the configuration changes in these files are being skipped rather than failing loudly.'),
        );
    }

    /**
     * Whether project config files exist to be applied. Protected, with the one below, so a
     * test can state a mismatched installation rather than needing one.
     *
     * @throws Throwable where the project config cannot be read.
     */
    protected function externalConfigExists(): bool
    {
        return Craft::$app->getProjectConfig()->getDoesExternalConfigExist();
    }

    /**
     * Craft's own comparison of the schema versions in the files with the ones in the code.
     *
     * Craft reports what disagrees through a by-reference argument; that is turned into a
     * return value here so the seam a test implements is an ordinary one.
     *
     * @return array{compatible: bool, issues: array<int, mixed>}
     * @throws Throwable where the project config cannot be read.
     */
    protected function schemaVersionCheck(): array
    {
        $issues = [];
        $compatible = Craft::$app->getProjectConfig()->getAreConfigSchemaVersionsCompatible($issues);

        return ['compatible' => $compatible, 'issues' => $issues];
    }
}
