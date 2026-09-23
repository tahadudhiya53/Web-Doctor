<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use DateTimeImmutable;
use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\helpers\DiagnosticMeta;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Runs diagnostics and reports what happened.
 *
 * The engine exists chiefly to contain failure. A diagnostic is other people's code — Web
 * Doctor's own, a third-party plugin's — inspecting a site that may be broken in exactly the
 * way the check is looking for. It will sometimes throw. When it does, that becomes an error
 * result with the exception recorded as evidence, and the run continues: a site with one broken
 * check is still owed the answers from every other one, and a diagnostic tool that goes down
 * with the thing it is diagnosing is worse than none.
 *
 * This is the only place `Throwable` is caught broadly. Inside a diagnostic, catching
 * everything would hide the bug rather than record it.
 */
class DiagnosticEngine extends Component
{
    /**
     * @var Diagnostics|null Where diagnostics are looked up. Settable so a caller — a test, a
     * recipe working from a narrowed set — can supply its own rather than the plugin's.
     */
    public ?Diagnostics $registry = null;

    /**
     * Runs one diagnostic and returns what it concluded, or why it could not.
     *
     * @param DiagnosticInterface|string $diagnostic The diagnostic, or the ID of a registered one.
     * @throws InvalidArgumentException if an ID is given that nothing is registered under.
     */
    public function run(DiagnosticInterface|string $diagnostic, ?DiagnosticContext $context = null): DiagnosticResult
    {
        $context ??= DiagnosticContext::current();
        $diagnostic = is_string($diagnostic) ? $this->resolve($diagnostic) : $diagnostic;

        $startedAt = new DateTimeImmutable();
        $start = hrtime(true);

        try {
            $result = $diagnostic->isApplicable($context)
                ? $diagnostic->run($context)
                : $this->skippedResult($diagnostic);
        } catch (Throwable $e) {
            $result = $this->errorResult($diagnostic, $e);
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return $result->withExecution($context, $startedAt, new DateTimeImmutable(), $durationMs);
    }

    /**
     * Runs every registered diagnostic.
     */
    public function runAll(?DiagnosticContext $context = null): DiagnosticRun
    {
        return $this->runMany($this->registry()->all(), $context);
    }

    /**
     * Runs the given diagnostics as one run, under one identity.
     *
     * Every one of them is attempted. An ID nothing is registered under is reported as an error
     * result rather than stopping the run, for the same reason a thrown exception is: a caller
     * working from a stale list still deserves the answers it can have.
     *
     * @param iterable<DiagnosticInterface|string> $diagnostics
     */
    public function runMany(iterable $diagnostics, ?DiagnosticContext $context = null): DiagnosticRun
    {
        $context ??= DiagnosticContext::current();

        $startedAt = new DateTimeImmutable();
        $start = hrtime(true);
        $results = [];

        foreach ($diagnostics as $diagnostic) {
            $results[] = $this->runSafely($diagnostic, $context);
        }

        return new DiagnosticRun(
            context: $context,
            results: $results,
            startedAt: $startedAt,
            finishedAt: new DateTimeImmutable(),
            durationMs: (hrtime(true) - $start) / 1_000_000,
        );
    }

    /**
     * Runs one diagnostic within a larger run, turning anything that goes wrong — including an
     * unknown ID — into a result rather than letting it end the run.
     */
    private function runSafely(DiagnosticInterface|string $diagnostic, DiagnosticContext $context): DiagnosticResult
    {
        try {
            return $this->run($diagnostic, $context);
        } catch (Throwable $e) {
            $id = $this->identify($diagnostic);
            $safe = SafeException::from($e);
            $this->logFailure($id, $safe);

            $now = new DateTimeImmutable();

            return (new DiagnosticResult(
                diagnosticId: $id,
                name: $id,
                category: DiagnosticCategory::CONFIGURATION,
                status: DiagnosticStatus::ERROR,
                summary: Craft::t('web-doctor', 'This check could not be run.'),
                description: $safe->summary(),
                evidence: [Evidence::fromThrowable($safe, $id)],
                confidence: Confidence::INFORMATIONAL,
            ))->withExecution($context, $now, $now, 0.0);
        }
    }

    /**
     * What to record a failure against. An ID given by a caller stands as it is; a diagnostic is
     * asked defensively, for the reason {@see DiagnosticMeta} explains.
     */
    private function identify(DiagnosticInterface|string $diagnostic): string
    {
        return is_string($diagnostic) ? $diagnostic : DiagnosticMeta::id($diagnostic);
    }

    /**
     * Where diagnostics are looked up. Resolved on first use rather than at construction, so an
     * engine built before the plugin finished booting still ends up with the plugin's registry
     * rather than one of its own.
     */
    private function registry(): Diagnostics
    {
        return $this->registry ??= WebDoctor::getInstance()?->getDiagnostics() ?? new Diagnostics();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function resolve(string $id): DiagnosticInterface
    {
        $diagnostic = $this->registry()->get($id);

        if ($diagnostic === null) {
            throw new InvalidArgumentException(sprintf('No diagnostic is registered under the ID "%s".', $id));
        }

        return $diagnostic;
    }

    private function skippedResult(DiagnosticInterface $diagnostic): DiagnosticResult
    {
        return new DiagnosticResult(
            diagnosticId: $this->identify($diagnostic),
            name: DiagnosticMeta::name($diagnostic),
            category: DiagnosticMeta::category($diagnostic),
            status: DiagnosticStatus::SKIPPED,
            summary: Craft::t('web-doctor', 'This check does not apply here.'),
        );
    }

    /**
     * Turns a broken diagnostic into a recorded fact. The result says the check failed rather
     * than that the site is fine, and carries the exception so the failure can be investigated
     * like anything else Web Doctor finds.
     */
    private function errorResult(DiagnosticInterface $diagnostic, Throwable $exception): DiagnosticResult
    {
        $id = $this->identify($diagnostic);

        // Reduced once, here, and never handled as a raw exception again: the description, the
        // evidence and the log line all read from the same sanitised representation.
        $safe = SafeException::from($exception);
        $this->logFailure($id, $safe);

        return new DiagnosticResult(
            diagnosticId: $id,
            name: DiagnosticMeta::name($diagnostic),
            category: DiagnosticMeta::category($diagnostic),
            status: DiagnosticStatus::ERROR,
            summary: Craft::t('web-doctor', 'This check failed to run.'),
            description: $safe->summary(),
            evidence: [
                Evidence::fromThrowable($safe, $id),
                Evidence::stackTrace($safe, $id),
            ],
            recommendation: Craft::t('web-doctor', 'The check itself failed, so nothing is known about what it inspects. Investigate the error and run it again.'),
            confidence: Confidence::INFORMATIONAL,
        );
    }

    /**
     * Logs a diagnostic failure. Takes the sanitised exception rather than the raw one, so the
     * log cannot become the boundary that leaks what the other three do not.
     */
    private function logFailure(string $id, SafeException $exception): void
    {
        Craft::error(
            sprintf('The diagnostic "%s" failed. %s at %s', $id, $exception->summary(), $exception->origin),
            WebDoctor::LOG_CATEGORY,
        );
    }
}
