<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Composer\InstalledVersions;
use Craft;
use ReflectionClass;
use Throwable;

/**
 * What Craft says it needs, read from Craft rather than restated here.
 *
 * A diagnostic that compares the server against Craft's requirements has to know what those
 * requirements are. Writing them into Web Doctor would mean every Craft release that raises one
 * silently turns this plugin into a source of wrong answers — the worst failure a diagnostic
 * tool has, because it is confidently wrong rather than unavailable.
 *
 * Craft states its requirements in two places, and both are read:
 *
 * - its package manifest, for the PHP version and the extensions Composer enforces;
 * - the `RequirementsChecker` class in `craftcms/server-check`, for the minimum database server
 *   versions.
 *
 * Only that class's declared properties are read. The requirements *file* beside it opens a
 * database connection the moment it is included, so it is never evaluated: a helper that
 * answers "what does Craft require" must not connect to anything to do it.
 *
 * Packages are located through Composer's own registry rather than by assuming a directory
 * layout, so this holds wherever an installation puts its dependencies. When something cannot
 * be read, nothing is guessed — the caller is told it does not know, and reports what it found
 * without a verdict.
 */
final class Requirements
{
    /**
     * @var string[] Extensions Craft marks mandatory in its requirements checker but does not
     * declare in its package manifest, so Composer never enforces them.
     *
     * They are named here because there is nowhere else to read them from: the file that states
     * them cannot be evaluated without opening a database connection. That makes this the one
     * place in Web Doctor where a Craft requirement is written down rather than read, so it is
     * pinned by a test that reads Craft's own requirements file and fails if any of these stops
     * being mandatory — which turns drift into a failing build rather than a wrong answer on
     * somebody's site.
     *
     * @see craftcms/server-check server/requirements/requirements.php
     */
    private const CHECKER_MANDATORY_EXTENSIONS = ['ctype', 'fileinfo', 'gd', 'iconv', 'reflection', 'spl'];

    /**
     * @var string[] Extensions Craft explicitly marks `'mandatory' => false`. Useful to have and
     * never a failure, which is exactly what Imagick is: Craft recommends it for animated GIF
     * and transparent PNG handling, and works without it.
     */
    private const RECOMMENDED_EXTENSIONS = ['imagick'];

    /** @var array<string, string>|null Craft's declared `require` block, once per request. */
    private static ?array $require = null;

    /** @var array<string, string>|null The minimum database server versions, once per request. */
    private static ?array $databaseVersions = null;

    /**
     * The PHP version constraint Craft declares, as a Composer constraint — `^8.2` — or null
     * where Craft's manifest could not be read.
     */
    public static function phpConstraint(): ?string
    {
        return self::declaredRequire()['php'] ?? null;
    }

    /**
     * Every extension Craft requires, from both places it states them, without the `ext-`
     * prefix Composer uses.
     *
     * @return string[]
     */
    public static function extensions(): array
    {
        $manifest = self::manifestExtensions();

        if ($manifest === []) {
            // Nothing was read, so nothing is claimed. Returning the pinned list on its own
            // would report a partial requirement set as though it were the whole one.
            return [];
        }

        $extensions = array_values(array_unique([...$manifest, ...self::CHECKER_MANDATORY_EXTENSIONS]));

        sort($extensions);

        return $extensions;
    }

    /**
     * The extensions Craft declares in its package manifest, which Composer enforces at install
     * time. A subset of {@see self::extensions()}.
     *
     * @return string[]
     */
    public static function manifestExtensions(): array
    {
        $extensions = [];

        foreach (array_keys(self::declaredRequire()) as $package) {
            if (str_starts_with($package, 'ext-')) {
                $extensions[] = substr($package, 4);
            }
        }

        sort($extensions);

        return $extensions;
    }

    /**
     * Extensions Craft recommends but works without. Never a requirement, so never a failure.
     *
     * @return string[]
     */
    public static function recommendedExtensions(): array
    {
        return self::RECOMMENDED_EXTENSIONS;
    }

    /**
     * The extensions this helper names itself, for the test that pins them against Craft's own
     * requirements file.
     *
     * @return string[]
     * @internal
     */
    public static function pinnedMandatoryExtensions(): array
    {
        return self::CHECKER_MANDATORY_EXTENSIONS;
    }

    /**
     * The minimum server version Craft requires for a database, keyed by the driver names Web
     * Doctor uses: `mysql`, `mariadb` and `pgsql`.
     *
     * @return array<string, string>
     */
    public static function databaseVersions(): array
    {
        if (self::$databaseVersions !== null) {
            return self::$databaseVersions;
        }

        self::$databaseVersions = [];

        $checker = self::packagePath('craftcms/server-check');

        if ($checker === null) {
            return self::$databaseVersions;
        }

        $file = $checker . '/server/requirements/RequirementsChecker.php';

        try {
            if (!class_exists('RequirementsChecker', false)) {
                if (!is_file($file)) {
                    return self::$databaseVersions;
                }

                // Craft ships this as a deliberately old-fashioned global class, so it is
                // loaded by path rather than by the autoloader. Only its declared minimums are
                // read; the checker is never run.
                require_once $file;
            }

            $declared = get_object_vars(new \RequirementsChecker());

            self::$databaseVersions = array_filter([
                'mysql' => self::version($declared, 'requiredMySqlVersion'),
                'mariadb' => self::version($declared, 'requiredMariaDbVersion'),
                'pgsql' => self::version($declared, 'requiredPgSqlVersion'),
            ]);
        } catch (Throwable) {
            // Unreadable requirements are reported as unknown by the diagnostics that asked,
            // never filled in from memory.
            self::$databaseVersions = [];
        }

        return self::$databaseVersions;
    }

    /**
     * @param array<string, mixed> $declared
     */
    private static function version(array $declared, string $property): ?string
    {
        $value = $declared[$property] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Craft's declared dependencies.
     *
     * @return array<string, string>
     */
    private static function declaredRequire(): array
    {
        if (self::$require !== null) {
            return self::$require;
        }

        self::$require = [];

        $path = self::craftPackagePath();

        if ($path === null || !is_file("$path/composer.json")) {
            return self::$require;
        }

        try {
            $manifest = json_decode((string)file_get_contents("$path/composer.json"), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return self::$require;
        }

        if (!is_array($manifest) || !is_array($manifest['require'] ?? null)) {
            return self::$require;
        }

        foreach ($manifest['require'] as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                self::$require[$package] = $constraint;
            }
        }

        return self::$require;
    }

    /**
     * The root of the `craftcms/cms` package.
     *
     * Found through the Craft class itself first, because that names the package Craft was
     * actually loaded from rather than whichever copy happens to be installed — a distinction
     * that matters when a plugin is developed inside a project and carries a Craft of its own
     * in `vendor/`. Composer's registry is the fallback.
     */
    private static function craftPackagePath(): ?string
    {
        try {
            $file = (new ReflectionClass(Craft::class))->getFileName();
        } catch (Throwable) {
            $file = false;
        }

        // .../craftcms/cms/src/Craft.php → .../craftcms/cms
        return $file === false ? self::packagePath('craftcms/cms') : dirname($file, 2);
    }

    /**
     * Where Composer put a package, asked of Composer rather than assumed from a directory
     * layout — which a project is free to change, and some do.
     */
    private static function packagePath(string $package): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            if (!InstalledVersions::isInstalled($package)) {
                return null;
            }

            $path = InstalledVersions::getInstallPath($package);
        } catch (Throwable) {
            return null;
        }

        return $path !== null && is_dir($path) ? $path : null;
    }

    /**
     * Forgets what was read, so a test can prove the reading works rather than the caching.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$require = null;
        self::$databaseVersions = null;
    }
}
