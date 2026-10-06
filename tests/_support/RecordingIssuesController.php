<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\controllers\IssuesController;

/**
 * The Issue Center controller with its flash messages captured rather than stored, for the reason
 * {@see RecordsFlashes} gives. Everything else is the real controller.
 */
class RecordingIssuesController extends IssuesController
{
    use RecordsFlashes;
}
