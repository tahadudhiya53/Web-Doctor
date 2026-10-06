<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\controllers\InvestigationsController;

/**
 * The investigations controller with its flash messages captured rather than stored, for the reason
 * {@see RecordsFlashes} gives. Everything else is the real controller.
 */
class RecordingInvestigationsController extends InvestigationsController
{
    use RecordsFlashes;
}
