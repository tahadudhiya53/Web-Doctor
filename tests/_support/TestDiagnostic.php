<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Closure;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * A diagnostic whose identity and behaviour are stated by the test rather than fixed in a
 * class, so the engine and the registry can be exercised without any real check existing.
 */
class TestDiagnostic extends Diagnostic
{
    public string $diagnosticId = 'tests.example';
    public string $diagnosticName = 'Example check';
    public DiagnosticCategory $diagnosticCategory = DiagnosticCategory::CONFIGURATION;
    public bool $applicable = true;

    /** @var Closure(self, DiagnosticContext): DiagnosticResult What running it does. */
    public Closure $handler;

    /** @var int How many times it has been run, so isolation can be shown to keep going. */
    public int $runs = 0;

    public function init(): void
    {
        parent::init();

        if (!isset($this->handler)) {
            $this->handler = static fn(self $d): DiagnosticResult => $d->pass('Nothing wrong.');
        }
    }

    public function id(): string
    {
        return $this->diagnosticId;
    }

    public function name(): string
    {
        return $this->diagnosticName;
    }

    public function category(): DiagnosticCategory
    {
        return $this->diagnosticCategory;
    }

    public function isApplicable(DiagnosticContext $context): bool
    {
        return $this->applicable;
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $this->runs++;

        return ($this->handler)($this, $context);
    }

    /**
     * Reaches the protected builders from a test, which is how the base class's contribution —
     * a result that names the diagnostic that made it — is checked.
     *
     * @param mixed[] $arguments
     */
    public function build(string $method, array $arguments = []): DiagnosticResult
    {
        return $this->$method(...$arguments);
    }
}
