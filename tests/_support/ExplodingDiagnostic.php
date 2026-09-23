<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * A diagnostic that fails before it can even be asked what it is called. The worst a contributor
 * can do to the registry, short of not being a diagnostic at all.
 */
class ExplodingDiagnostic extends Diagnostic
{
    public function id(): string
    {
        throw new \RuntimeException('Cannot even name myself, password=hunter2');
    }

    public function name(): string
    {
        return 'Exploding check';
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::CONFIGURATION;
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        return $this->pass('Nothing wrong.');
    }
}
