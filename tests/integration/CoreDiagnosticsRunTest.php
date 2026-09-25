<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\diagnostics\CoreDiagnostics;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\services\DiagnosticEngine;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use Tahadudhiya\WebDoctor\Tests\_support\ExplodingDiagnostic;

/**
 * Every check Web Doctor ships with, against a real Craft installation.
 *
 * This is the only place the checks actually run, because running is all they do: each one
 * reads something about the installation it is inside and says what it found. A test that
 * mocked Craft away would be testing the mock.
 *
 * So what is asserted here is what has to hold whatever the installation happens to look like.
 * Every check produces a result. None of them fails to run. Each one produces the same result
 * on its own as it does in a full run, because a check that only works alongside the others is
 * not independently executable. And nothing any of them records is a credential.
 */
class CoreDiagnosticsRunTest extends TestCase
{
    private static ?DiagnosticRun $run = null;

    private function registry(): Diagnostics
    {
        return new Diagnostics(['includeCoreDiagnostics' => true]);
    }

    private function engine(): DiagnosticEngine
    {
        return new DiagnosticEngine(['registry' => $this->registry()]);
    }

    /**
     * One full run, shared by the tests that only read it. Running eighteen checks against a
     * real database for each assertion would be slow for no gain.
     */
    private function fullRun(): DiagnosticRun
    {
        return self::$run ??= $this->engine()->runAll(DiagnosticContext::current());
    }

    /**
     * The results from Web Doctor's own checks, and nothing contributed.
     *
     * A registry fires its registration event for anyone listening, so a run made here can also
     * carry checks contributed by another plugin. These invariants are about the eighteen Web
     * Doctor ships with; asserting them over whatever else happens to be installed would make
     * somebody else's check able to fail Web Doctor's own suite.
     *
     * @return list<DiagnosticResult>
     */
    private function shippedResults(DiagnosticRun $run): array
    {
        $shipped = array_flip($this->shippedIds());

        return array_values(array_filter(
            $run->results(),
            static fn(DiagnosticResult $r): bool => isset($shipped[$r->diagnosticId]),
        ));
    }

    /**
     * @return list<string>
     */
    private function shippedIds(): array
    {
        return array_map(static fn(DiagnosticInterface $d): string => $d->id(), CoreDiagnostics::all());
    }

    /**
     * Every result with this status, including ones from checks that are not Web Doctor's — a
     * test that deliberately adds a broken check needs to see it.
     *
     * @return DiagnosticResult[]
     */
    private function resultsWithStatus(DiagnosticRun $run, DiagnosticStatus $status): array
    {
        return array_values(array_filter(
            $run->results(),
            static fn(DiagnosticResult $r): bool => $r->status === $status,
        ));
    }

    /**
     * The same, narrowed to the checks Web Doctor ships with.
     *
     * @return DiagnosticResult[]
     */
    private function shippedWithStatus(DiagnosticRun $run, DiagnosticStatus $status): array
    {
        return array_values(array_filter(
            $this->shippedResults($run),
            static fn(DiagnosticResult $r): bool => $r->status === $status,
        ));
    }

    public function testEveryShippedCheckProducesExactlyOneResult(): void
    {
        $run = $this->fullRun();
        $expected = $this->shippedIds();

        self::assertCount(count($expected), $this->shippedResults($run));

        foreach ($expected as $id) {
            self::assertNotNull($run->resultFor($id), "No result was produced for $id.");
        }
    }

    public function testNoShippedCheckFailedToRun(): void
    {
        // An ERROR is the check breaking rather than finding something, so on a working
        // installation every one of these should have reached a conclusion of its own — even
        // the ones whose answer is that they could not tell.
        $failures = array_map(
            static fn(DiagnosticResult $r): string => $r->diagnosticId . ': ' . $r->description,
            $this->shippedWithStatus($this->fullRun(), DiagnosticStatus::ERROR),
        );

        self::assertSame([], $failures, 'These checks threw instead of reporting: ' . implode('; ', $failures));
    }

    public function testEveryResultIsAttributedToTheRunAndTheCheckThatMadeIt(): void
    {
        $run = $this->fullRun();

        foreach ($run->results() as $result) {
            self::assertSame($run->id(), $result->runId);
            self::assertNotSame('', $result->name);
            self::assertSame(Craft::$app->env, $result->environment);
            self::assertNotNull($result->durationMs);
        }
    }

    public function testEveryConclusiveResultCarriesEvidence(): void
    {
        foreach ($this->fullRun()->results() as $result) {
            if ($result->status === DiagnosticStatus::SKIPPED) {
                continue;
            }

            self::assertTrue(
                $result->hasEvidence(),
                sprintf('%s reported "%s" with nothing behind it.', $result->diagnosticId, $result->summary),
            );
        }
    }

    public function testEachCheckReachesTheSameConclusionOnItsOwn(): void
    {
        // Independently executable: a recipe, a scheduled run or the command line will run one
        // of these without the others, and it has to mean the same thing when it does.
        $engine = $this->engine();
        $context = DiagnosticContext::current();

        foreach (CoreDiagnostics::all() as $diagnostic) {
            $alone = $engine->run($diagnostic, $context);
            $together = $this->fullRun()->resultFor($diagnostic->id());

            self::assertNotNull($together);
            self::assertSame(
                $together->status,
                $alone->status,
                sprintf('%s concluded differently on its own than in a full run.', $diagnostic->id()),
            );
        }
    }

    public function testTwoRunsOfTheSameInstallationAgree(): void
    {
        // Determinism is what makes two runs comparable, which is what health history and
        // environment comparison are built on.
        $statuses = static fn(DiagnosticRun $run): array => array_combine(
            array_map(static fn(DiagnosticResult $r): string => $r->diagnosticId, $run->results()),
            array_map(static fn(DiagnosticResult $r): string => $r->status->value, $run->results()),
        );

        $first = $this->engine()->runAll(DiagnosticContext::current());
        $second = $this->engine()->runAll(DiagnosticContext::current());

        self::assertSame($statuses($first), $statuses($second));
    }

    public function testResultsComeBackInTheSameOrderEveryTime(): void
    {
        $ids = static fn(DiagnosticRun $run): array => array_map(
            static fn(DiagnosticResult $r): string => $r->diagnosticId,
            $run->results(),
        );

        self::assertSame(
            $ids($this->engine()->runAll(DiagnosticContext::current())),
            $ids($this->engine()->runAll(DiagnosticContext::current())),
        );
    }

    public function testNothingARunRecordsIsACredential(): void
    {
        // The whole run serialized, the way a report, an API response or a webhook payload
        // would serialize it, searched for the values this installation is actually configured
        // with. A leak anywhere in eighteen checks is caught here.
        $json = json_encode($this->fullRun());

        self::assertIsString($json);

        $secrets = array_filter([
            Craft::$app->getConfig()->getDb()->password,
            Craft::$app->getConfig()->getGeneral()->securityKey,
        ], static fn(string $secret): bool => strlen(trim($secret)) >= 8);

        self::assertNotSame([], $secrets, 'This installation has no credentials to check against.');

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $json, 'A credential this installation is configured with appeared in a diagnostic run.');
        }
    }

    public function testAFullRunChangesNothingAboutTheInstallation(): void
    {
        // The read-only guarantee, proved rather than asserted. Every table's row count, the
        // rows of the ones that carry state a diagnostic touches, and the contents of Craft's
        // working directories are compared either side of the most thorough run there is.
        $db = Craft::$app->getDb();
        $tables = $db->getSchema()->getTableNames();

        self::assertNotEmpty($tables, 'There should be tables to watch.');

        $counts = static function() use ($db, $tables): array {
            $counts = [];

            foreach ($tables as $table) {
                $counts[$table] = (new \craft\db\Query())->from([$table])->count('*', $db);
            }

            return $counts;
        };

        $stateful = static function() use ($db): array {
            $rows = [];

            foreach ([\craft\db\Table::QUEUE, \craft\db\Table::PLUGINS, \craft\db\Table::INFO] as $table) {
                $rows[$table] = (new \craft\db\Query())->from([$table])->orderBy(['id' => SORT_ASC])->all($db);
            }

            return $rows;
        };

        $directories = static function(): array {
            $listing = [];

            foreach ([Craft::$app->getPath()->getStoragePath(false), Craft::$app->getRuntimePath()] as $directory) {
                $entries = is_dir($directory) ? scandir($directory) : [];
                $listing[$directory] = $entries === false ? [] : $entries;
            }

            return $listing;
        };

        $countsBefore = $counts();
        $statefulBefore = $stateful();
        $directoriesBefore = $directories();

        $this->engine()->runAll(DiagnosticContext::current(DiagnosticDepth::DEEP));

        self::assertSame($countsBefore, $counts(), 'A diagnostic run changed the number of rows in a table.');
        self::assertSame($statefulBefore, $stateful(), 'A diagnostic run changed a row.');
        self::assertSame($directoriesBefore, $directories(), 'A diagnostic run created or removed a file.');
    }

    public function testAFullRunSendsNoMail(): void
    {
        $sends = 0;
        $mailer = Craft::$app->getMailer();

        $mailer->on(\yii\mail\BaseMailer::EVENT_BEFORE_SEND, static function() use (&$sends): void {
            $sends++;
        });

        try {
            $this->engine()->runAll(DiagnosticContext::current(DiagnosticDepth::DEEP));

            self::assertSame(0, $sends, 'A diagnostic run sent mail.');
        } finally {
            $mailer->off(\yii\mail\BaseMailer::EVENT_BEFORE_SEND);
        }
    }

    public function testAFullRunLeavesProjectConfigUntouched(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $before = $projectConfig->get();

        $this->engine()->runAll(DiagnosticContext::current(DiagnosticDepth::DEEP));

        self::assertSame($before, $projectConfig->get(), 'A diagnostic run changed project config.');
        self::assertSame([], $projectConfig->getAppliedChanges(), 'A diagnostic run applied project config changes.');
    }

    public function testEveryCheckReachesARealConclusionOnAWorkingInstallation(): void
    {
        // "No errors" is too weak a bar on its own: a check that quietly returns UNKNOWN every
        // time looks just as clean and tells nobody anything. On an installation that is
        // working, every check should have something to say about it.
        $inconclusive = [];

        foreach ($this->shippedResults($this->fullRun()) as $result) {
            if (in_array($result->status, [DiagnosticStatus::ERROR, DiagnosticStatus::UNKNOWN], true)) {
                $inconclusive[] = sprintf('%s (%s): %s', $result->diagnosticId, $result->status->value, $result->summary);
            }
        }

        self::assertSame([], $inconclusive, 'These reached no conclusion: ' . implode('; ', $inconclusive));
    }

    public function testSkippedResultsAreOnlyEverForChecksThatGenuinelyDoNotApply(): void
    {
        $context = DiagnosticContext::current();
        $wronglySkipped = [];

        foreach ($this->shippedWithStatus($this->fullRun(), DiagnosticStatus::SKIPPED) as $result) {
            $diagnostic = $this->registry()->get($result->diagnosticId);

            if ($diagnostic === null || $diagnostic->isApplicable($context)) {
                $wronglySkipped[] = $result->diagnosticId;
            }
        }

        self::assertSame([], $wronglySkipped, 'Skipped although they apply here: ' . implode(', ', $wronglySkipped));
    }

    public function testOneCheckThrowingCostsNoOtherCheckItsResult(): void
    {
        // The whole shipped set, plus something that throws before it can even name itself.
        $diagnostics = [...CoreDiagnostics::all(), new ExplodingDiagnostic()];

        $run = (new DiagnosticEngine(['registry' => $this->registry()]))
            ->runMany($diagnostics, DiagnosticContext::current());

        self::assertCount(count($diagnostics), $run->results());
        self::assertCount(1, $this->resultsWithStatus($run, DiagnosticStatus::ERROR), 'Exactly the broken one should be recorded as a failure to run.');

        foreach (CoreDiagnostics::all() as $diagnostic) {
            $result = $run->resultFor($diagnostic->id());

            self::assertNotNull($result, sprintf('%s lost its result to another check throwing.', $diagnostic->id()));
            self::assertNotSame(DiagnosticStatus::ERROR, $result->status);
        }
    }

    public function testNothingRecordedUnderACredentialKeyIsAnythingButPresenceOrRedaction(): void
    {
        // A structural audit rather than a search for particular strings: wherever a run records
        // something under a key the redactor treats as a credential, the value must be a
        // presence word or the redaction mark. This holds across all eighteen checks at once,
        // including any key a future one introduces.
        $offenders = [];

        $walk = static function(mixed $value, string $path) use (&$walk, &$offenders): void {
            if (!is_array($value)) {
                return;
            }

            foreach ($value as $key => $child) {
                $here = $path . '.' . $key;

                if (is_string($key) && Redaction::isSensitiveKey($key) && !is_array($child)) {
                    $allowed = $child === Redaction::REDACTED || Redaction::isPresence($child) || $child === null;

                    if (!$allowed) {
                        $offenders[] = sprintf('%s = %s', $here, var_export($child, true));
                    }
                }

                $walk($child, $here);
            }
        };

        $decoded = json_decode((string)json_encode($this->fullRun()), true);

        self::assertIsArray($decoded);

        $walk($decoded, 'run');

        self::assertSame([], $offenders, 'Credential-named keys carrying a value: ' . implode('; ', $offenders));
    }

    public function testTheSameEvidenceStructureComesBackEveryTime(): void
    {
        // Timestamps and durations vary by nature; the shape of what was recorded must not.
        $shape = static fn(DiagnosticRun $run): array => array_map(
            static fn(DiagnosticResult $r): array => [
                'id' => $r->diagnosticId,
                'category' => $r->category->value,
                'evidence' => array_map(
                    static fn($e): array => ['type' => $e->type->value, 'keys' => array_keys($e->data)],
                    $r->evidence(),
                ),
            ],
            $run->results(),
        );

        self::assertSame(
            $shape($this->engine()->runAll(DiagnosticContext::current())),
            $shape($this->engine()->runAll(DiagnosticContext::current())),
        );
    }

    public function testEveryDepthProducesAResultForEveryCheck(): void
    {
        foreach (DiagnosticDepth::cases() as $depth) {
            $run = $this->engine()->runAll(DiagnosticContext::current($depth));

            self::assertCount(count(CoreDiagnostics::classes()), $this->shippedResults($run), "at {$depth->value} depth");
            self::assertSame([], $this->shippedWithStatus($run, DiagnosticStatus::ERROR), "a check failed to run at {$depth->value} depth");
        }
    }

    public function testTheHealthScoreIsShownWithTheArithmeticBehindIt(): void
    {
        $health = $this->fullRun()->health();

        self::assertSame(count($this->fullRun()->results()), $health->total);
        self::assertNotSame([], $health->weights());

        foreach ($health->contributions as $contribution) {
            self::assertNotNull($this->fullRun()->resultFor($contribution['diagnosticId']));
            self::assertSame($health->weights()[$contribution['severity']], $contribution['penalty']);
        }
    }
}
