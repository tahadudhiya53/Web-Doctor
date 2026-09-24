<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use Composer\InstalledVersions;
use Craft;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tahadudhiya\WebDoctor\helpers\Requirements;

/**
 * Reading Craft's requirements from Craft.
 *
 * The point of the helper is that nothing here is written down twice, so the tests check the
 * shape of what comes back and that it came from Craft, rather than asserting the particular
 * versions a Craft release happens to require today. A test that pinned those numbers would
 * have to be edited every time Craft moved, which is exactly the maintenance the helper exists
 * to avoid.
 */
class RequirementsTest extends TestCase
{
    /**
     * Craft's own manifest, found independently of the helper so that the two can be compared.
     *
     * @return array<string, string>
     */
    private function craftManifestRequire(): array
    {
        $file = (new ReflectionClass(Craft::class))->getFileName();

        self::assertIsString($file);

        $manifest = dirname($file, 2) . '/composer.json';

        self::assertFileExists($manifest, 'The installed Craft package should have a manifest to read.');

        $decoded = json_decode((string)file_get_contents($manifest), true);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['require'] ?? null);

        return $decoded['require'];
    }

    public function testThePhpConstraintIsTheOneTheInstalledCraftDeclares(): void
    {
        // Read from Craft's manifest here, independently of the helper, so this proves the
        // helper found the actual installation rather than proving it failed gracefully.
        $declared = $this->craftManifestRequire();

        self::assertArrayHasKey('php', $declared);
        self::assertSame($declared['php'], Requirements::phpConstraint());
    }

    public function testTheRuntimePhpSatisfiesWhatWasRead(): void
    {
        // The suite is running on a PHP this Craft supports, so a constraint that the running
        // PHP fails would mean the helper read the wrong thing.
        $constraint = Requirements::phpConstraint();

        self::assertNotNull($constraint);
        self::assertTrue(
            \Composer\Semver\Semver::satisfies(
                sprintf('%d.%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION),
                $constraint,
            ),
        );
    }

    public function testTheManifestExtensionsAreExactlyWhatTheInstalledCraftDeclares(): void
    {
        $expected = [];

        foreach (array_keys($this->craftManifestRequire()) as $package) {
            if (str_starts_with($package, 'ext-')) {
                $expected[] = substr($package, 4);
            }
        }

        sort($expected);

        self::assertNotSame([], $expected, 'Craft declares extensions, so the comparison is a real one.');
        self::assertSame($expected, Requirements::manifestExtensions());
    }

    public function testTheFullExtensionListAddsTheOnesComposerNeverSees(): void
    {
        // Craft's manifest is not the whole requirement: its requirements checker marks several
        // more mandatory that Composer never enforces. Reading only the manifest would miss
        // them, and a missing mandatory extension is how a site breaks with nothing explaining it.
        $all = Requirements::extensions();

        self::assertNotSame(Requirements::manifestExtensions(), $all);

        foreach (Requirements::manifestExtensions() as $extension) {
            self::assertContains($extension, $all);
        }

        foreach (Requirements::pinnedMandatoryExtensions() as $extension) {
            self::assertContains($extension, $all);
        }

        $sorted = $all;
        sort($sorted);

        self::assertSame($sorted, $all);
        self::assertSame(array_values(array_unique($all)), $all);
    }

    public function testTheExtensionsThisHelperNamesItselfAreStillMandatoryInCraft(): void
    {
        // The one place a Craft requirement is written down rather than read, because the file
        // that states it opens a database connection the moment it is included. Pinned against
        // Craft's own source so drift fails the build instead of producing a wrong answer on
        // somebody's site.
        $source = $this->requirementsCheckerSource('requirements.php');

        foreach (Requirements::pinnedMandatoryExtensions() as $extension) {
            self::assertMatchesRegularExpression(
                sprintf("/'mandatory'\s*=>\s*true,\s*'condition'\s*=>\s*(?:extension_loaded|function_exists)\('%s'\)/i", preg_quote($extension, '/')),
                $source,
                "Craft no longer marks $extension mandatory, or states it differently.",
            );
        }
    }

    public function testTheExtensionsThisHelperCallsRecommendedAreStillOptionalInCraft(): void
    {
        $source = $this->requirementsCheckerSource('requirements.php');

        foreach (Requirements::recommendedExtensions() as $extension) {
            self::assertMatchesRegularExpression(
                sprintf("/'mandatory'\s*=>\s*false,\s*'condition'\s*=>\s*extension_loaded\('%s'\)/i", preg_quote($extension, '/')),
                $source,
                "Craft no longer treats $extension as optional.",
            );
        }
    }

    private function requirementsCheckerSource(string $file): string
    {
        $path = $this->serverCheckPath();

        if ($path === null || !is_file("$path/server/requirements/$file")) {
            self::markTestSkipped('This installation does not have Craft’s requirements checker.');
        }

        return (string)file_get_contents("$path/server/requirements/$file");
    }

    private function serverCheckPath(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('craftcms/server-check')) {
            return null;
        }

        return InstalledVersions::getInstallPath('craftcms/server-check');
    }

    public function testEveryExtensionCraftRequiresIsActuallyLoadedHere(): void
    {
        // The suite is running on a server Craft works on, so anything reported as required and
        // missing would mean the helper read something that is not an extension list.
        foreach (Requirements::extensions() as $extension) {
            self::assertTrue(extension_loaded($extension), "Craft requires $extension, which this PHP should have.");
        }
    }

    public function testTheDatabaseMinimumsAreTheOnesCraftShips(): void
    {
        $source = $this->requirementsCheckerSource('RequirementsChecker.php');
        $versions = Requirements::databaseVersions();

        foreach (['mysql' => 'requiredMySqlVersion', 'mariadb' => 'requiredMariaDbVersion', 'pgsql' => 'requiredPgSqlVersion'] as $key => $property) {
            self::assertArrayHasKey($key, $versions, 'Every driver Craft supports has a minimum.');
            self::assertMatchesRegularExpression('/^\d+\.\d+/', $versions[$key]);
            self::assertMatchesRegularExpression(
                sprintf('/\$%s\s*=\s*[\'"]%s[\'"]/', $property, preg_quote($versions[$key], '/')),
                $source,
                "The $key minimum should be the one Craft's own requirements checker declares.",
            );
        }
    }

    public function testTheRequiredExtensionsAreNamedWithoutTheirPrefix(): void
    {
        $extensions = Requirements::extensions();

        self::assertNotEmpty($extensions);

        foreach ($extensions as $extension) {
            self::assertStringStartsNotWith('ext-', $extension, 'The `ext-` prefix is Composer’s, not PHP’s.');
        }
    }

    public function testTheRequiredExtensionsIncludeOnesCraftCannotRunWithout(): void
    {
        // Named individually rather than as a count: the assertion is that the right list was
        // found, not that it is a particular length.
        self::assertContains('pdo', Requirements::extensions());
        self::assertContains('mbstring', Requirements::extensions());
    }

    public function testTheRequiredExtensionsAreInAStableOrder(): void
    {
        self::assertSame(Requirements::extensions(), Requirements::extensions());

        $extensions = Requirements::extensions();
        $sorted = $extensions;
        sort($sorted);

        self::assertSame($sorted, $extensions);
    }

    public function testRereadingAfterForgettingGivesTheSameAnswer(): void
    {
        // The values are read once and kept. Forgetting them and reading again is what shows
        // the reading works rather than the cache holding a lucky first result.
        $constraint = Requirements::phpConstraint();
        $extensions = Requirements::extensions();
        $versions = Requirements::databaseVersions();

        Requirements::reset();

        self::assertSame($constraint, Requirements::phpConstraint());
        self::assertSame($extensions, Requirements::extensions());
        self::assertSame($versions, Requirements::databaseVersions());
    }
}
