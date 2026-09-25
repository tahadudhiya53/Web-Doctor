<?php

namespace Tahadudhiya\WebDoctor\base;

use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use yii\base\Component;

/**
 * The starting point for writing a diagnostic.
 *
 * It supplies the parts every check answers the same way — its identity, that it applies unless
 * told otherwise — and a set of builders that produce a well-formed result carrying the
 * diagnostic's own identity and category. That last part matters: a result that named itself
 * could name the wrong check, and everything downstream keys off that name.
 *
 * Implementations declare their identity as a class constant:
 *
 * ```php
 * class CraftVersionDiagnostic extends Diagnostic
 * {
 *     public const ID = 'craft.version';
 * }
 * ```
 *
 * Extending Yii's Component is what lets a diagnostic be built and configured through Craft's
 * container, so a diagnostic that needs a service can be given one rather than reaching for it.
 */
abstract class Diagnostic extends Component implements DiagnosticInterface
{
    /**
     * @var string This diagnostic's permanent identity. Every implementation declares its own;
     * the registry refuses one that has not, rather than letting an unnamed check be recorded
     * against nothing.
     */
    public const ID = '';

    public function id(): string
    {
        return static::ID;
    }

    public function description(): string
    {
        return '';
    }

    public function isApplicable(DiagnosticContext $context): bool
    {
        return true;
    }

    /**
     * @param Evidence[] $evidence
     */
    protected function pass(string $summary, array $evidence = [], string $description = ''): DiagnosticResult
    {
        return $this->result(DiagnosticStatus::PASS, $summary, evidence: $evidence, description: $description);
    }

    /**
     * @param Evidence[] $evidence
     */
    protected function info(string $summary, array $evidence = [], string $description = ''): DiagnosticResult
    {
        return $this->result(DiagnosticStatus::INFO, $summary, evidence: $evidence, description: $description);
    }

    /**
     * @param Evidence[] $evidence
     */
    protected function warning(
        string $summary,
        array $evidence = [],
        ?string $recommendation = null,
        ?Severity $severity = null,
        Confidence $confidence = Confidence::LIKELY,
        string $description = '',
    ): DiagnosticResult {
        return $this->result(
            DiagnosticStatus::WARNING,
            $summary,
            severity: $severity,
            evidence: $evidence,
            recommendation: $recommendation,
            confidence: $confidence,
            description: $description,
        );
    }

    /**
     * The thing this check inspects is broken. How badly is the severity's answer, not this
     * one: a failure can be critical or it can be minor, and the check is what knows which.
     *
     * @param Evidence[] $evidence
     */
    protected function fail(
        string $summary,
        array $evidence = [],
        ?string $recommendation = null,
        ?Severity $severity = null,
        Confidence $confidence = Confidence::LIKELY,
        string $description = '',
    ): DiagnosticResult {
        return $this->result(
            DiagnosticStatus::FAIL,
            $summary,
            severity: $severity,
            evidence: $evidence,
            recommendation: $recommendation,
            confidence: $confidence,
            description: $description,
        );
    }

    /**
     * The check did not apply here. Not a finding about the site.
     */
    protected function skipped(string $summary): DiagnosticResult
    {
        return $this->result(DiagnosticStatus::SKIPPED, $summary);
    }

    /**
     * The check ran but could not tell. Said plainly, rather than reported as a pass.
     *
     * @param Evidence[] $evidence
     */
    protected function unknown(string $summary, array $evidence = []): DiagnosticResult
    {
        return $this->result(DiagnosticStatus::UNKNOWN, $summary, evidence: $evidence);
    }

    /**
     * Builds a result attributed to this diagnostic.
     *
     * @param Evidence[] $evidence
     */
    protected function result(
        DiagnosticStatus $status,
        string $summary,
        ?Severity $severity = null,
        string $description = '',
        array $evidence = [],
        ?string $recommendation = null,
        Confidence $confidence = Confidence::INFORMATIONAL,
        ?string $affectedComponent = null,
        ?string $affectedPlugin = null,
        bool $repairAvailable = false,
        bool $verificationAvailable = false,
    ): DiagnosticResult {
        return new DiagnosticResult(
            diagnosticId: $this->id(),
            name: $this->name(),
            category: $this->category(),
            status: $status,
            summary: $summary,
            severity: $severity,
            description: $description,
            evidence: $evidence,
            recommendation: $recommendation,
            confidence: $confidence,
            affectedComponent: $affectedComponent,
            affectedPlugin: $affectedPlugin,
            repairAvailable: $repairAvailable,
            verificationAvailable: $verificationAvailable,
        );
    }

    /**
     * Records a fact, attributed to this diagnostic.
     *
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $metadata How the fact was gathered, rather than the fact.
     * @param string|null $reference Where the fact can be found again.
     */
    protected function evidence(EvidenceType $type, string $label, array $data = [], array $metadata = [], ?string $reference = null): Evidence
    {
        return new Evidence(type: $type, label: $label, source: $this->id(), data: $data, metadata: $metadata, reference: $reference);
    }
}
