<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use Tahadudhiya\WebDoctor\controllers\InvestigationsController;

/**
 * The investigations controller with its flash messages captured rather than stored, for the
 * reason {@see RecordingIssuesController} gives. Everything else is the real controller.
 */
class RecordingInvestigationsController extends InvestigationsController
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
