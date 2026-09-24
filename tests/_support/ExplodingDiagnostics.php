<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use RuntimeException;
use Tahadudhiya\WebDoctor\services\Diagnostics;

/**
 * A registry that cannot be read at all.
 *
 * The engine contains a diagnostic that throws; nothing contains the registry that hands it the
 * checks. This is what the dashboard's own boundary is tested against — including that the
 * message, which carries something that looks like a credential, never reaches the page.
 */
class ExplodingDiagnostics extends Diagnostics
{
    /** @var string Deliberately shaped like the worst thing an exception could be carrying. */
    public const SECRET = 'password=hunter2-SHOULD-NOT-APPEAR';

    public function all(): array
    {
        throw new RuntimeException('The registry could not be read: ' . self::SECRET);
    }

    public function ids(): array
    {
        throw new RuntimeException('The registry could not be read: ' . self::SECRET);
    }
}
