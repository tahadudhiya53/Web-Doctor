<?php

namespace Tahadudhiya\WebDoctor\diagnostics\storage;

use Craft;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Whether Craft can write to its own working directories.
 *
 * Craft writes compiled templates, logs, caches and temporary files under `storage/`, and a
 * deployment that copies files as one user while the web server runs as another is the usual
 * way that stops working. The symptoms are strange: templates that will not render, sessions
 * that do not persist, logs that are not there to explain any of it.
 *
 * Nothing is created to find this out. The directories are asked about as they stand — asking
 * Craft to create a missing one would hide the very problem being looked for, and would have
 * Web Doctor changing a site it was asked to inspect.
 */
class StoragePathsDiagnostic extends Diagnostic
{
    public const ID = 'storage.paths';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Storage directories');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::STORAGE;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks that the directories Craft writes to exist and are writable by the user running it.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $paths = $this->paths();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'Craft’s storage paths could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $states = [];
        $missing = [];
        $readOnly = [];
        $unreadable = [];

        foreach (array_keys($paths) as $label) {
            $state = $this->stateOf($paths[$label]);
            $states[$label] = $state;

            match ($state) {
                'missing' => $missing[] = $label,
                // A file where a directory belongs is as unusable as one Craft cannot write
                // to, and is fixed the same way — by clearing the path.
                'notADirectory', 'notWritable' => $readOnly[] = $label,
                'unknown' => $unreadable[] = $label,
                default => null,
            };
        }

        $evidence = [
            $this->evidence(EvidenceType::FILESYSTEM, Craft::t('web-doctor', 'Storage directories'), [
                'states' => $states,
                'user' => $this->processUser(),
            ]),
        ];

        if ($readOnly !== []) {
            return $this->fail(
                Craft::t('web-doctor', 'Craft cannot write to: {paths}.', ['paths' => implode(', ', $readOnly)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Give the user running PHP write access to these directories, and check that nothing is occupying the path as a file. A deployment that copies files as a different user is the usual cause.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft writes compiled templates, caches, sessions and logs here, so the failures this causes appear everywhere except where the cause is.'),
            );
        }

        if ($missing !== []) {
            return $this->warning(
                Craft::t('web-doctor', 'Directories Craft writes to do not exist: {paths}.', ['paths' => implode(', ', $missing)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Create them, or confirm that the parent directory is writable so Craft can create them itself.'),
                severity: Severity::MEDIUM,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft creates these on demand, so this only becomes a failure when it cannot.'),
            );
        }

        // Asked last, after every definite finding: uncertainty never outranks something
        // already established. But a directory whose state could not be read is not a
        // directory that is fine, so it stops the pass — which is the difference between a
        // diagnostic and a reassurance.
        if ($unreadable !== []) {
            return $this->unknown(
                Craft::t('web-doctor', 'These directories could not be inspected: {paths}.', ['paths' => implode(', ', $unreadable)]),
                $evidence,
            );
        }

        return $this->pass(Craft::t('web-doctor', 'Craft can write to all of its storage directories.'), $evidence);
    }

    /**
     * The directories Craft depends on being able to write to, asked for without being created.
     *
     * @return array<string, string>
     */
    protected function paths(): array
    {
        $path = Craft::$app->getPath();

        return [
            'storage' => $path->getStoragePath(false),
            'runtime' => Craft::$app->getRuntimePath(),
            'compiledTemplates' => $path->getCompiledTemplatesPath(false),
            'logs' => $path->getLogPath(false),
        ];
    }

    /**
     * Whether a directory is there and writable, without touching it.
     *
     * Protected so a test can state a directory's condition rather than having to create an
     * unwritable one, which is not something every platform or CI user can do.
     *
     * A path that exists but is a file is reported as its own state rather than as missing:
     * the two need different fixes, and calling a file "missing" sends somebody to create a
     * directory that cannot be created.
     *
     * A path whose parent cannot be read is reported as unknown rather than missing: `file_exists()`
     * answers false both for "not there" and for "not allowed to look", and reporting the second
     * as the first would send somebody to create a directory that is already there.
     *
     * @return 'missing'|'notADirectory'|'notWritable'|'writable'|'unknown'
     */
    protected function stateOf(string $path): string
    {
        if (!file_exists($path)) {
            $parent = dirname($path);

            return is_dir($parent) && !is_readable($parent) ? 'unknown' : 'missing';
        }

        if (!is_dir($path)) {
            return 'notADirectory';
        }

        return is_writable($path) ? 'writable' : 'notWritable';
    }

    /**
     * Which user is running this, where the platform will say. Names one side of the mismatch
     * that produces most of these failures.
     *
     * Only POSIX is asked. `get_current_user()` looks like the answer and is not: it reports
     * the owner of the running *script file*, which on exactly the misconfigured deployment
     * this check exists to find is the wrong user — and naming the wrong user is worse than
     * naming none, because somebody will act on it.
     */
    protected function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user)) {
                return (string)$user['name'];
            }
        }

        return Craft::t('web-doctor', 'Unknown');
    }
}
