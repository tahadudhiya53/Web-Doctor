<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\events\RegisterDiagnosticsEvent;
use Tahadudhiya\WebDoctor\helpers\DiagnosticMeta;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Every diagnostic Web Doctor knows about. It holds diagnostics; running them is the engine's job.
 *
 * Three rules give it its value. An ID belongs to exactly one diagnostic and the first valid
 * registration keeps it, so what a site is told never depends on plugin load order. An ID's shape
 * is checked at registration, while it can still be changed rather than after it is in a
 * database. And the order comes from the diagnostics themselves rather than from registration
 * order, which is what makes two runs comparable.
 *
 * Another plugin contributes diagnostics by handling {@see self::EVENT_REGISTER_DIAGNOSTICS} on
 * this class:
 *
 * ```php
 * Event::on(
 *     Diagnostics::class,
 *     Diagnostics::EVENT_REGISTER_DIAGNOSTICS,
 *     function(RegisterDiagnosticsEvent $event) {
 *         $event->diagnostics[] = new MyDiagnostic();
 *     },
 * );
 * ```
 */
class Diagnostics extends Component
{
    /** @event RegisterDiagnosticsEvent Fired so other plugins may contribute diagnostics. */
    public const EVENT_REGISTER_DIAGNOSTICS = 'registerDiagnostics';

    /**
     * @var string The shape of a diagnostic ID: dot-separated segments, each starting with a
     * lowercase letter and continuing in letters and digits, at least two of them — a scope and
     * a name, as in `craft.version` or `queue.failedJobs`. Two segments are required so that
     * IDs from Web Doctor and from other plugins stay distinguishable at a glance.
     *
     * Anchored with `\z` rather than `$`, which in PCRE also matches before a trailing newline
     * — and an ID with a newline in it would travel into logs, storage and reports.
     */
    private const ID_PATTERN = '/\A[a-z][a-zA-Z0-9]*(?:\.[a-z][a-zA-Z0-9]*)+\z/';

    /** @var int The longest an ID may be, so it stays storable and readable. */
    private const ID_MAX_LENGTH = 100;

    /**
     * @var bool Whether the checks Web Doctor ships with are part of this registry. The plugin
     * turns this on for the registry it hands out; a registry built directly — by a test, or by
     * a caller that wants to run a set of its own — starts empty.
     */
    public bool $includeCoreDiagnostics = false;

    /** @var array<string, DiagnosticInterface> Registered diagnostics, keyed by ID. */
    private array $diagnostics = [];

    /** @var bool Whether other plugins have been asked for theirs yet. */
    private bool $loaded = false;

    /**
     * Adds a diagnostic.
     *
     * The first valid registration of an ID keeps it. Registering the same class again is a
     * no-op rather than a replacement, so a plugin that registers twice costs nothing and the
     * instance already handed out stays the one in use. A different class under a taken ID is
     * refused outright.
     *
     * @throws InvalidArgumentException if the ID is malformed, or if it is taken by another class.
     */
    public function register(DiagnosticInterface $diagnostic): void
    {
        $id = $diagnostic->id();

        $this->validateId($id, $diagnostic);

        $existing = $this->diagnostics[$id] ?? null;

        if ($existing !== null) {
            if ($existing::class === $diagnostic::class) {
                return;
            }

            throw new InvalidArgumentException(sprintf(
                'The diagnostic ID "%s" is already registered by %s, so %s cannot also use it.',
                $id,
                $existing::class,
                $diagnostic::class,
            ));
        }

        $this->diagnostics[$id] = $diagnostic;
    }

    /**
     * Whether a string is a well-formed diagnostic ID.
     */
    public static function isValidId(string $id): bool
    {
        return $id !== ''
            && strlen($id) <= self::ID_MAX_LENGTH
            && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validateId(string $id, DiagnosticInterface $diagnostic): void
    {
        if ($id === '') {
            throw new InvalidArgumentException(sprintf(
                '%s must declare a diagnostic ID. Results are recorded against it, so an unnamed diagnostic has nowhere to belong.',
                $diagnostic::class,
            ));
        }

        if (!self::isValidId($id)) {
            throw new InvalidArgumentException(sprintf(
                '%s declares the diagnostic ID "%s", which is not a valid ID. IDs are dot-separated, '
                . 'at least two segments, each starting with a lowercase letter and continuing in letters '
                . 'and digits — for example "craft.version" or "queue.failedJobs".',
                $diagnostic::class,
                $id,
            ));
        }
    }

    /**
     * Adds the checks Web Doctor ships with.
     *
     * Held to the same terms as anyone else's: one that cannot be registered is logged and
     * skipped rather than allowed to stop the rest. A mistake in one of Web Doctor's own checks
     * is no better a reason to have no diagnostics at all than a mistake in somebody else's.
     */
    private function registerCore(): void
    {
        foreach (CoreDiagnostics::all() as $diagnostic) {
            try {
                $this->register($diagnostic);
            } catch (Throwable $e) {
                Craft::error(Redaction::redactString($e->getMessage()), WebDoctor::LOG_CATEGORY);
            }
        }
    }

    /**
     * Every registered diagnostic, in a stable order: by category, then by ID.
     *
     * @return DiagnosticInterface[]
     */
    public function all(): array
    {
        $this->load();

        $diagnostics = array_values($this->diagnostics);

        // Asked defensively: a contributed diagnostic that throws when asked what it is must
        // cost a reader its place in the ordering, not the whole list.
        usort($diagnostics, static function(DiagnosticInterface $a, DiagnosticInterface $b): int {
            return [DiagnosticMeta::category($a)->position(), DiagnosticMeta::id($a)]
                <=> [DiagnosticMeta::category($b)->position(), DiagnosticMeta::id($b)];
        });

        return $diagnostics;
    }

    /**
     * @return string[]
     */
    public function ids(): array
    {
        return array_map(static fn(DiagnosticInterface $d): string => DiagnosticMeta::id($d), $this->all());
    }

    public function get(string $id): ?DiagnosticInterface
    {
        $this->load();

        return $this->diagnostics[$id] ?? null;
    }

    /**
     * Asks other plugins for their diagnostics, once. Deferred until the registry is actually
     * read so that merely having Web Doctor installed costs a request nothing.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        // Set before either step: a handler that reads the registry while contributing to it
        // must not start the round again.
        $this->loaded = true;

        // Web Doctor's own checks first, so the first-wins rule reserves their IDs before any
        // other plugin is asked. Otherwise which check answers to `craft.version` would depend
        // on the order Craft happened to boot plugins in.
        if ($this->includeCoreDiagnostics) {
            $this->registerCore();
        }

        if (!$this->hasEventHandlers(self::EVENT_REGISTER_DIAGNOSTICS)) {
            return;
        }

        $event = new RegisterDiagnosticsEvent();
        $this->trigger(self::EVENT_REGISTER_DIAGNOSTICS, $event);

        foreach ($event->diagnostics as $diagnostic) {
            // One plugin's mistake must not take the registry down with it. `Throwable` rather
            // than the validation exception alone, because a contributor can fail in ways this
            // class never raises: an `id()` that throws, or something that is not a diagnostic
            // at all pushed onto the array.
            try {
                if (!$diagnostic instanceof DiagnosticInterface) {
                    throw new InvalidArgumentException(sprintf(
                        'Only diagnostics may be contributed to Web Doctor; %s is not one.',
                        get_debug_type($diagnostic),
                    ));
                }

                $this->register($diagnostic);
            } catch (Throwable $e) {
                // Through the same redaction as everything else Web Doctor writes down: the
                // message quotes an ID that came from another plugin.
                Craft::error(Redaction::redactString($e->getMessage()), WebDoctor::LOG_CATEGORY);
            }
        }
    }
}
