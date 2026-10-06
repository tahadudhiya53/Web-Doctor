<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\controllers\RepairsController;

/**
 * The repairs controller with its flash messages captured rather than stored, for the reason
 * {@see RecordsFlashes} gives. Everything else is the real controller.
 */
class RecordingRepairsController extends RepairsController
{
    use RecordsFlashes;
}
