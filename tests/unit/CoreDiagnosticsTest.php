<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use yii\base\Event;

/**
 * The checks Web Doctor ships with, as a set.
 *
 * These are the properties that have to hold for every one of them and that are easiest to
 * break by adding the nineteenth: an ID that is well formed, an ID nobody else has taken, and
 * an ID that never changes — because a site's recorded history is keyed to it.
 *
 * None of this runs a check. What each one concludes is a question for a real installation, and
 * belongs to the integration suite.
 */
class CoreDiagnosticsTest extends TestCase
{
    /**
     * @return DiagnosticInterface[]
     */
    private static function diagnostics(): array
    {
        return CoreDiagnostics::all();
    }

    public function testEveryShippedCheckSatisfiesTheContract(): void
    {
        self::assertNotEmpty(self::diagnostics());

        foreach (self::diagnostics() as $diagnostic) {
            self::assertInstanceOf(DiagnosticInterface::class, $diagnostic);
        }
    }

    public function testEveryIdIsWellFormed(): void
    {
        foreach (self::diagnostics() as $diagnostic) {
            self::assertTrue(
                Diagnostics::isValidId($diagnostic->id()),
                sprintf('%s declares the ID "%s", which the registry would refuse.', $diagnostic::class, $diagnostic->id()),
            );
        }
    }

    public function testNoClassIsListedTwice(): void
    {
        $classes = CoreDiagnostics::classes();

        self::assertSame(array_unique($classes), $classes);
    }

    public function testEveryCheckIsNamedAndCategorised(): void
    {
        foreach (self::diagnostics() as $diagnostic) {
            self::assertNotSame('', $diagnostic->name(), $diagnostic::class . ' has no name.');
            self::assertNotSame('', $diagnostic->description(), $diagnostic::class . ' has no description.');
            self::assertInstanceOf(DiagnosticCategory::class, $diagnostic->category());
        }
    }

    public function testTheShippedIdsAreExactlyTheseOnes(): void
    {
        // An ID is permanent: issues, evidence and history are recorded against it for the life
        // of an installation, so renaming one severs a site's history from the check that made
        // it. This list is here to make that a decision rather than an accident — a change to it
        // should be as deliberate as failing this test.
        $expected = [
            'craft.application',
            'craft.version',
            'database.charset',
            'database.connection',
            'database.migrations',
            'email.configuration',
            'environment.configuration',
            'filesystem.volumes',
            'php.configuration',
            'php.extensions',
            'php.version',
            'plugins.health',
            'plugins.installed',
            'projectConfig.integrity',
            'projectConfig.pendingChanges',
            'queue.backlog',
            'queue.failedJobs',
            'storage.paths',
        ];

        $actual = array_map(static fn(DiagnosticInterface $d): string => $d->id(), self::diagnostics());
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testARegistryOnlyHoldsTheShippedChecksWhenItIsAskedTo(): void
    {
        // A registry built directly is a container, not Web Doctor (DiagnosticCoreTest holds that
        // it starts empty); asked to, it holds the shipped checks.
        $registry = new Diagnostics(['includeCoreDiagnostics' => true]);

        self::assertCount(count(CoreDiagnostics::classes()), $registry->all());
    }

    public function testTheShippedChecksClaimTheirIdsBeforeOtherPluginsAreAsked(): void
    {
        // Registration is first-wins, so whichever side goes first keeps the ID. If contributors
        // went first, which check answered to `craft.version` would depend on the order Craft
        // happened to boot plugins in.
        $impostor = new TestDiagnostic(['diagnosticId' => 'craft.version']);

        Event::on(Diagnostics::class, Diagnostics::EVENT_REGISTER_DIAGNOSTICS, static function($event) use ($impostor) {
            $event->diagnostics[] = $impostor;
        });

        try {
            $registry = new Diagnostics(['includeCoreDiagnostics' => true]);

            self::assertNotSame($impostor, $registry->get('craft.version'));
        } finally {
            Event::off(Diagnostics::class, Diagnostics::EVENT_REGISTER_DIAGNOSTICS);
        }
    }

    public function testAContributorThatClashesDoesNotStopTheOthersRegistering(): void
    {
        $clashing = new TestDiagnostic(['diagnosticId' => 'php.version']);
        $fine = new TestDiagnostic(['diagnosticId' => 'otherPlugin.check']);

        Event::on(Diagnostics::class, Diagnostics::EVENT_REGISTER_DIAGNOSTICS, static function($event) use ($clashing, $fine) {
            $event->diagnostics[] = $clashing;
            $event->diagnostics[] = $fine;
        });

        try {
            $registry = new Diagnostics(['includeCoreDiagnostics' => true]);

            self::assertNotSame($clashing, $registry->get('php.version'));
            self::assertSame($fine, $registry->get('otherPlugin.check'));
        } finally {
            Event::off(Diagnostics::class, Diagnostics::EVENT_REGISTER_DIAGNOSTICS);
        }
    }

    public function testEveryPhraseTheChecksUseCanBeTranslated(): void
    {
        // A phrase with no entry still renders, so a missing one is invisible until somebody
        // translates the plugin and finds half of it in English. Reading the source is the only
        // way to catch that while it is cheap to fix.
        $translations = require dirname(__DIR__, 2) . '/src/translations/en/web-doctor.php';

        self::assertIsArray($translations);

        $missing = [];
        $found = 0;

        foreach (self::sourceFiles() as $file) {
            $source = (string)file_get_contents($file);

            preg_match_all("/Craft::t\\('web-doctor', '((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

            foreach ($matches[1] as $phrase) {
                $found++;
                $phrase = str_replace(["\\'", '\\\\'], ["'", '\\'], $phrase);

                if (!array_key_exists($phrase, $translations)) {
                    $missing[] = basename($file) . ': ' . $phrase;
                }
            }
        }

        // Without this the test would pass just as happily on a regex that matched nothing.
        self::assertGreaterThan(150, $found, 'The source scan found almost no phrases, so it is not checking anything.');
        self::assertSame([], $missing, 'Phrases with no translation entry: ' . implode(' | ', $missing));
    }

    /**
     * @return string[]
     */
    private static function sourceFiles(): array
    {
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src');
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/translations/')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testNoCheckReportsHealthFromInsideAFailureHandler(): void
    {
        // The rule this encodes: a check that could not do its work must never answer as though
        // it did. `pass()` or `info()` reached from a `catch` block is exactly that — the real
        // check failed and a reassuring result went out anyway — and it is the one defect in
        // this plugin that would be invisible to everybody, because a site full of problems
        // would look clean.
        //
        // Tested against the source because it has to hold for checks nobody has written yet.
        $offenders = [];

        foreach (self::sourceFiles() as $file) {
            if (!str_contains($file, '/diagnostics/')) {
                continue;
            }

            $source = (string)file_get_contents($file);

            foreach (self::catchBlocks($source) as $block) {
                foreach (['pass', 'info'] as $reassurance) {
                    if (str_contains($block, '$this->' . $reassurance . '(')) {
                        $offenders[] = sprintf('%s: %s() inside a catch block', basename($file), $reassurance);
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Silent healthy fallbacks: ' . implode(' | ', $offenders));
    }

    public function testTheBypassGuardWouldActuallyCatchOne(): void
    {
        // A guard that never fires is a guard nobody can trust, so it is shown failing on the
        // shape it exists to find.
        $bad = '<?php try { $this->check(); } catch (Throwable $e) { return $this->pass("All fine."); }';
        $blocks = self::catchBlocks($bad);

        self::assertCount(1, $blocks);
        self::assertStringContainsString('$this->pass(', $blocks[0]);

        // And that it does not fire on the correct shape.
        $good = '<?php try { $this->check(); } catch (Throwable $e) { return $this->unknown("Could not tell."); }';

        self::assertStringNotContainsString('$this->pass(', self::catchBlocks($good)[0]);
    }

    /**
     * The body of every `catch` block in a piece of source, found by matching braces.
     *
     * @return string[]
     */
    private static function catchBlocks(string $source): array
    {
        $blocks = [];
        $offset = 0;

        while (preg_match('/catch\s*\([^)]*\)\s*\{/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $i = $start;

            while ($i < strlen($source) && $depth > 0) {
                $depth += match ($source[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $i++;
            }

            $blocks[] = substr($source, $start, $i - $start - 1);
            $offset = $i;
        }

        return $blocks;
    }
}
