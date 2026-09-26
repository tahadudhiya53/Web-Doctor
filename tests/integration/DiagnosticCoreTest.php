<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\diagnostics\craft\CraftVersionDiagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\ExecutionMode;
use Tahadudhiya\WebDoctor\events\RegisterDiagnosticsEvent;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\Tests\_support\ConstantIdDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\TestDiagnostic;
use Tahadudhiya\WebDoctor\Tests\_support\ThirdPartyPlugin;
use Tahadudhiya\WebDoctor\WebDoctor;

/**
 * The diagnostic core inside a real Craft application: the components Craft builds, the
 * environment and site a run describes itself by, and a run that survives a broken check while
 * a real database is in reach.
 *
 * It also covers the extension point another Craft plugin uses, end to end —
 *
 *     third-party plugin → registration event → registry → engine → result
 *
 * — tested the way a real plugin would use it rather than by poking the registry directly,
 * because that is the contract Web Doctor asks other plugins to build against.
 */
class DiagnosticCoreTest extends TestCase
{
    private WebDoctor $plugin;
    private ThirdPartyPlugin $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '5.0.0',
        ]);
        $this->other = new ThirdPartyPlugin();
    }

    protected function tearDown(): void
    {
        // Uninstalled again, so one test's plugin is not still contributing during the next.
        $this->other->shutDown();

        parent::tearDown();
    }

    private function diagnostic(string $id, ?callable $handler = null, DiagnosticCategory $category = DiagnosticCategory::CONFIGURATION): TestDiagnostic
    {
        $diagnostic = new TestDiagnostic(['diagnosticId' => $id, 'diagnosticCategory' => $category]);

        if ($handler !== null) {
            $diagnostic->handler = \Closure::fromCallable($handler);
        }

        return $diagnostic;
    }

    public function testCraftBuildsBothDiagnosticComponents(): void
    {
        self::assertInstanceOf(Diagnostics::class, $this->plugin->getDiagnostics());
        self::assertInstanceOf(DiagnosticEngine::class, $this->plugin->getDiagnosticEngine());
    }

    public function testTheEngineRunsWhatThePluginsRegistryHolds(): void
    {
        // The registry and the engine are separate components, so this is what proves they are
        // talking to each other rather than each holding a list of its own.
        $this->plugin->getDiagnostics()->register($this->diagnostic('tests.wired'));

        $run = $this->plugin->getDiagnosticEngine()->runAll(DiagnosticContext::current());

        self::assertNotNull($run->resultFor('tests.wired'));
    }

    public function testThePluginsRegistryHoldsTheChecksWebDoctorShipsWith(): void
    {
        // The registry the plugin hands out is the product's one, so it comes with Web Doctor's
        // own checks already in it.
        $plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + ['name' => 'Web Doctor']);

        self::assertCount(count(CoreDiagnostics::classes()), $plugin->getDiagnostics()->all());
        self::assertNotNull($plugin->getDiagnostics()->get(CraftVersionDiagnostic::ID));
    }

    public function testAContextDescribesItselfFromWhatCraftKnows(): void
    {
        $context = DiagnosticContext::current();

        self::assertSame(Craft::$app->env, $context->environment);
        self::assertSame(Craft::$app->getSites()->getCurrentSite()->id, $context->siteId);
        self::assertNotSame('', $context->runId);

        // Craft names no environment when nothing sets one. That is still somewhere, and a run
        // there must not fail for want of a name.
        $env = Craft::$app->env;
        Craft::$app->env = null;

        try {
            self::assertSame('unknown', DiagnosticContext::current()->environment);
        } finally {
            Craft::$app->env = $env;
        }
    }

    public function testARunStartedFromTheCommandLineSaysSo(): void
    {
        // Which matters later: a run a person asked for and a run a schedule asked for are
        // told apart by this.
        self::assertSame(ExecutionMode::CONSOLE, DiagnosticContext::current()->mode);
    }

    public function testResultsCarryTheEnvironmentTheyWereFoundIn(): void
    {
        $result = $this->plugin->getDiagnosticEngine()->run(
            $this->diagnostic('tests.environment'),
            DiagnosticContext::current(),
        );

        self::assertSame(Craft::$app->env, $result->environment);
    }

    public function testOneBrokenDiagnosticDoesNotStopARunInsideCraft(): void
    {
        $run = $this->plugin->getDiagnosticEngine()->runMany([
            $this->diagnostic('tests.a', static fn(TestDiagnostic $d) => $d->build('pass', ['Fine.'])),
            $this->diagnostic('tests.b', static fn() => throw new \RuntimeException('Boom')),
            $this->diagnostic('tests.c', static fn(TestDiagnostic $d) => $d->build('fail', ['Bad.'])),
        ], DiagnosticContext::current());

        self::assertSame(3, $run->count());
        self::assertSame(DiagnosticStatus::PASS, $run->resultFor('tests.a')->status);
        self::assertSame(DiagnosticStatus::ERROR, $run->resultFor('tests.b')->status);
        self::assertSame(DiagnosticStatus::FAIL, $run->resultFor('tests.c')->status);
    }

    public function testADiagnosticThatQueriesTheDatabaseIsRunAgainstTheRealOne(): void
    {
        // Diagnostics inspect Craft. This is the smallest proof that one actually can, without
        // being a check Web Doctor ships.
        $run = $this->plugin->getDiagnosticEngine()->runMany([
            $this->diagnostic('tests.database', static function(TestDiagnostic $d) {
                $tables = Craft::$app->getDb()->getSchema()->getTableNames();

                return $d->build('pass', [sprintf('%d tables.', count($tables))]);
            }, DiagnosticCategory::DATABASE),
        ], DiagnosticContext::current());

        self::assertSame(DiagnosticStatus::PASS, $run->resultFor('tests.database')->status);
        self::assertStringEndsWith('tables.', $run->resultFor('tests.database')->summary);
    }

    // --- The extension point another plugin contributes through.

    public function testAContributedDiagnosticIsDiscoveredAndRun(): void
    {
        $this->other->boot(function(RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = $this->diagnostic(
                'otherPlugin.health',
                static fn(TestDiagnostic $d) => $d->build('fail', ['The other plugin is unhappy.']),
            );
        });

        $run = $this->plugin->getDiagnosticEngine()->runAll(DiagnosticContext::current());
        $result = $run->resultFor('otherPlugin.health');

        self::assertNotNull($result);
        self::assertSame(DiagnosticStatus::FAIL, $result->status);
        self::assertSame('The other plugin is unhappy.', $result->summary);
        self::assertSame($run->id(), $result->runId);
    }

    public function testAPluginMayContributeSeveralDiagnostics(): void
    {
        $this->other->boot(function(RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = $this->diagnostic('otherPlugin.one', category: DiagnosticCategory::QUEUE);
            $event->diagnostics[] = $this->diagnostic('otherPlugin.two', category: DiagnosticCategory::CRAFT);
        });

        $registry = $this->plugin->getDiagnostics();

        self::assertNotNull($registry->get('otherPlugin.one'));
        self::assertNotNull($registry->get('otherPlugin.two'));

        // And they take their place in the same ordering as everything else: a Craft check
        // before a Queue one, among Web Doctor's own rather than appended after them.
        $contributed = array_values(array_filter(
            $registry->ids(),
            static fn(string $id): bool => str_starts_with($id, 'otherPlugin.'),
        ));

        self::assertSame(['otherPlugin.two', 'otherPlugin.one'], $contributed);
        self::assertGreaterThan(
            array_search('otherPlugin.two', $registry->ids(), true),
            array_search('queue.backlog', $registry->ids(), true),
        );
    }

    public function testAContributorCannotTakeAnIdThatIsAlreadyRegistered(): void
    {
        $registry = $this->plugin->getDiagnostics();
        $registry->register($mine = $this->diagnostic(ConstantIdDiagnostic::ID));

        $this->other->boot(function(RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = new ConstantIdDiagnostic();
            $event->diagnostics[] = $this->diagnostic('otherPlugin.valid');
        });

        self::assertSame($mine, $registry->get(ConstantIdDiagnostic::ID));
        self::assertNotNull($registry->get('otherPlugin.valid'));
    }

    public function testOneBadContributorDoesNotCostTheOthersTheirDiagnostics(): void
    {
        $this->other->boot(function(RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = $this->diagnostic('');
            $event->diagnostics[] = $this->diagnostic('malformed id here');
            $event->diagnostics[] = $this->diagnostic('otherPlugin.good');
        });

        $registry = $this->plugin->getDiagnostics();

        self::assertNotNull($registry->get('otherPlugin.good'));
        self::assertNull($registry->get('malformed id here'));

        // Exactly one of the three contributions landed, and Web Doctor's own checks are all
        // still there: a broken contributor costs nobody else anything.
        $contributed = array_filter($registry->ids(), static fn(string $id): bool => str_starts_with($id, 'otherPlugin.'));

        self::assertCount(1, $contributed);
        self::assertCount(count(CoreDiagnostics::classes()) + 1, $registry->all());
    }

    public function testAContributedDiagnosticThatThrowsIsIsolatedLikeAnyOther(): void
    {
        // A third-party diagnostic gets no more trust than Web Doctor's own, and no less
        // protection either.
        $this->other->boot(function(RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = $this->diagnostic('otherPlugin.broken', static fn() => throw new \RuntimeException('Boom'));
            $event->diagnostics[] = $this->diagnostic('otherPlugin.fine');
        });

        $run = $this->plugin->getDiagnosticEngine()->runAll(DiagnosticContext::current());

        self::assertSame(DiagnosticStatus::ERROR, $run->resultFor('otherPlugin.broken')->status);
        self::assertSame(DiagnosticStatus::PASS, $run->resultFor('otherPlugin.fine')->status);
    }

    public function testAContributedDiagnosticCannotLeakACredentialThroughItsException(): void
    {
        $this->other->boot(function(RegisterDiagnosticsEvent $event): void {
            $event->diagnostics[] = $this->diagnostic(
                'otherPlugin.leaky',
                static fn() => throw new \RuntimeException('Failed password=hunter2'),
            );
        });

        $run = $this->plugin->getDiagnosticEngine()->runAll(DiagnosticContext::current());

        self::assertStringNotContainsString('hunter2', (string)json_encode($run));
    }
}
