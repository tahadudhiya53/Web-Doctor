<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\controllers\OverviewController;

/**
 * The dashboard controller with its flash messages captured rather than stored.
 *
 * Craft's console application refuses to hand out a session at all, so a controller action that
 * tells the user what happened cannot be exercised in an integration run without this. Only the
 * two methods that reach for the session are replaced: authorization, the selection of what to
 * run, the engine, and where the run is kept are all the real ones.
 */
class RecordingOverviewController extends OverviewController
{
    /** @var list<array{level: string, message: string}> Everything the action tried to say. */
    public array $flashes = [];

    /**
     * @param array<string, mixed> $settings
     */
    public function setSuccessFlash(?string $default = null, array $settings = []): void
    {
        $this->flashes[] = ['level' => 'success', 'message' => (string)$default];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setFailFlash(?string $default = null, array $settings = []): void
    {
        $this->flashes[] = ['level' => 'fail', 'message' => (string)$default];
    }

    /**
     * Which actions, if any, Craft is told may be reached without signing in. Protected on
     * Craft's controller, so a test that wants to assert on it has to be handed it.
     */
    public function anonymousAccess(): array|bool|int
    {
        return $this->allowAnonymous;
    }

    /**
     * @return array{level: string, message: string}|null
     */
    public function lastFlash(): ?array
    {
        return $this->flashes === [] ? null : $this->flashes[array_key_last($this->flashes)];
    }
}
