<?php

namespace Tahadudhiya\WebDoctor\diagnostics;

use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\diagnostics\craft\ApplicationDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\craft\CraftVersionDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\database\CharsetDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\database\ConnectionDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\database\MigrationsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\email\MailerConfigurationDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\environment\EnvironmentDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\filesystem\FilesystemsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\php\PhpConfigurationDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\php\PhpExtensionsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\php\PhpVersionDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\plugins\InstalledPluginsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\plugins\PluginHealthDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\projectconfig\PendingChangesDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\projectconfig\ProjectConfigIntegrityDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\FailedJobsDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\queue\QueueBacklogDiagnostic;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;

/**
 * The checks Web Doctor ships with.
 *
 * One list, in one file, so that "what does Web Doctor actually check?" is a question answered
 * by reading twenty lines rather than by searching a directory tree. It is also what the
 * registry reads before it asks other plugins for theirs, which is what reserves these IDs
 * under the registry's first-wins rule: a plugin cannot quietly take over `craft.version` by
 * registering it sooner.
 *
 * Instances are built only when this is called, so a request that never looks at a diagnostic
 * never constructs one.
 */
final class CoreDiagnostics
{
    /**
     * @var class-string<DiagnosticInterface>[] Every check Web Doctor provides. The order here
     * is for a reader; a run is ordered by category and ID regardless.
     */
    private const DIAGNOSTICS = [
        CraftVersionDiagnostic::class,
        ApplicationDiagnostic::class,
        PhpVersionDiagnostic::class,
        PhpExtensionsDiagnostic::class,
        PhpConfigurationDiagnostic::class,
        ConnectionDiagnostic::class,
        MigrationsDiagnostic::class,
        CharsetDiagnostic::class,
        InstalledPluginsDiagnostic::class,
        PluginHealthDiagnostic::class,
        QueueBacklogDiagnostic::class,
        FailedJobsDiagnostic::class,
        FilesystemsDiagnostic::class,
        StoragePathsDiagnostic::class,
        MailerConfigurationDiagnostic::class,
        EnvironmentDiagnostic::class,
        PendingChangesDiagnostic::class,
        ProjectConfigIntegrityDiagnostic::class,
    ];

    /**
     * @return DiagnosticInterface[]
     */
    public static function all(): array
    {
        return array_map(static fn(string $class): DiagnosticInterface => new $class(), self::DIAGNOSTICS);
    }

    /**
     * @return class-string<DiagnosticInterface>[]
     */
    public static function classes(): array
    {
        return self::DIAGNOSTICS;
    }
}
