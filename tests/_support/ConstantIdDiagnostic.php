<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * A diagnostic written the way a real one is: identity declared as a constant and nothing else
 * overridden. What the base class supplies on its own is checked against this.
 */
class ConstantIdDiagnostic extends Diagnostic
{
    public const ID = 'tests.constantId';

    public function name(): string
    {
        return 'Constant ID check';
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::CRAFT;
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        return $this->pass('Nothing wrong.');
    }
}
