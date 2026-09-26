<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use craft\console\Application as ConsoleApplication;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\ExecutionMode;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * Everything a diagnostic is told about the run it is part of, and the run's identity.
 *
 * A context carries no secrets. Options scope work — a time window, a component, a limit — and
 * nothing else; where a diagnostic needs a credential it reads it from Craft at the moment it
 * needs it and reports only whether it was there. Options are redacted as the context is built,
 * but that is a backstop against a mistake rather than permission to make one.
 */
final class DiagnosticContext implements JsonSerializable
{
    /** @var string The identity every result, and everything derived from one, refers back to. */
    public readonly string $runId;

    /** @var DateTimeImmutable When the run began. */
    public readonly DateTimeImmutable $startedAt;

    /** @var array<string, mixed> Scoping instructions for the diagnostics in this run. */
    public readonly array $options;

    /**
     * @param int|null $siteId The site being diagnosed, where the question is site-specific.
     * @param string $environment Which environment this is, as Craft reports it.
     * @param ExecutionMode $mode What set the run going.
     * @param DiagnosticDepth $depth How far diagnostics should go.
     * @param array<string, mixed> $options Scoping instructions, never credentials.
     */
    public function __construct(
        public readonly ?int $siteId = null,
        public readonly string $environment = 'unknown',
        public readonly ExecutionMode $mode = ExecutionMode::MANUAL,
        public readonly DiagnosticDepth $depth = DiagnosticDepth::NORMAL,
        array $options = [],
        ?string $runId = null,
        ?DateTimeImmutable $startedAt = null,
    ) {
        $this->runId = $runId ?? StringHelper::UUID();
        $this->startedAt = $startedAt ?? new DateTimeImmutable();
        // Redacted here as well as when serialized to JSON. A run is cached as a serialized PHP
        // object, which never calls jsonSerialize(), so the redaction there alone would miss the
        // one serialization every run goes through.
        $this->options = Redaction::redact($options);
    }

    /**
     * Builds the context for a run happening right now, taking what Craft already knows rather
     * than asking a caller to repeat it.
     *
     * Craft has to be running for this: the environment and the current site are its answers to
     * give. Somewhere without an application — a unit test — states its own context instead.
     *
     * @param array<string, mixed> $options Scoping instructions, never credentials.
     */
    public static function current(
        DiagnosticDepth $depth = DiagnosticDepth::NORMAL,
        ?ExecutionMode $mode = null,
        ?int $siteId = null,
        array $options = [],
    ): self {
        $app = Craft::$app;

        return new self(
            siteId: $siteId ?? self::currentSiteId(),
            environment: self::currentEnvironment(),
            mode: $mode ?? ($app instanceof ConsoleApplication ? ExecutionMode::CONSOLE : ExecutionMode::MANUAL),
            depth: $depth,
            options: $options,
        );
    }

    /**
     * The site being diagnosed, where Craft can say which one that is.
     *
     * A console command, a queue job and an application still booting can all reach this with
     * no current site, and Craft throws rather than guessing. A run with no site says so with a
     * null, which is a fact a diagnostic can act on; inventing a site ID would produce findings
     * attributed to a site nobody was looking at.
     */
    public static function currentSiteId(): ?int
    {
        try {
            return Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The environment being diagnosed, by Craft's name for it. Craft names none when nothing sets
     * CRAFT_ENVIRONMENT, ENVIRONMENT or a server name — a bare command line — and that is still
     * one place, so it is recorded under a name that says the environment was not known.
     */
    public static function currentEnvironment(): string
    {
        return Craft::$app->env ?? 'unknown';
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'runId' => $this->runId,
            'siteId' => $this->siteId,
            'environment' => $this->environment,
            'mode' => $this->mode->value,
            'depth' => $this->depth->value,
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'options' => Redaction::redact($this->options),
        ];
    }
}
