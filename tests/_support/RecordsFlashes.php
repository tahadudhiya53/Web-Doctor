<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

/**
 * A controller's flash messages captured rather than stored.
 *
 * Craft's console application refuses to hand out a session, so an action that tells the reader
 * what happened cannot be exercised in an integration run without this. Only the two methods that
 * reach for the session are replaced: the control panel check, CSRF validation, the permission
 * checks and the services underneath are all the real ones.
 */
trait RecordsFlashes
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
     * @return array{level: string, message: string}|null
     */
    public function lastFlash(): ?array
    {
        return $this->flashes === [] ? null : $this->flashes[array_key_last($this->flashes)];
    }
}
