<?php

namespace Tahadudhiya\WebDoctor\events;

use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use yii\base\Event;

/**
 * Fired once, the first time the registry is asked for anything, so other plugins can
 * contribute checks of their own.
 *
 * A contributed diagnostic is held to the same terms as one of Web Doctor's own: it declares a
 * unique ID, it runs behind the same permission model, and the engine isolates it the same way.
 * Nothing registered here gets to widen what Web Doctor is allowed to do.
 */
class RegisterDiagnosticsEvent extends Event
{
    /**
     * @var mixed[] Diagnostics to add to the registry. Handlers add {@see DiagnosticInterface}
     * instances; the type is not narrowed here because this array is filled by other plugins
     * and a docblock cannot make that a guarantee. The registry checks what it is given rather
     * than trusting it, and reports anything else without letting it stop the round.
     */
    public array $diagnostics = [];
}
