<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\events\RegisterDiagnosticsEvent;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use yii\base\Event;

/**
 * Stands in for another Craft plugin that contributes diagnostics to Web Doctor.
 *
 * Written the way a real plugin would write it: the handler is attached to the Diagnostics
 * class from the plugin's own bootstrap, long before Web Doctor's registry is built or read.
 * If the extension point only worked on an already-constructed registry instance, no real
 * plugin could use it, and this is what would notice.
 */
final class ThirdPartyPlugin
{
    /** @var callable|null */
    private $handler = null;

    /**
     * @param callable(RegisterDiagnosticsEvent): void $contribute
     */
    public function boot(callable $contribute): void
    {
        $this->handler = static function(RegisterDiagnosticsEvent $event) use ($contribute): void {
            $contribute($event);
        };

        Event::on(Diagnostics::class, Diagnostics::EVENT_REGISTER_DIAGNOSTICS, $this->handler);
    }

    /**
     * Uninstalled again, so one test's plugin is not still contributing during the next.
     */
    public function shutDown(): void
    {
        if ($this->handler !== null) {
            Event::off(Diagnostics::class, Diagnostics::EVENT_REGISTER_DIAGNOSTICS, $this->handler);
            $this->handler = null;
        }
    }
}
