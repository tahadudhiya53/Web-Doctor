<?php

namespace Tahadudhiya\WebDoctor\base;

use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * One check Web Doctor can perform.
 *
 * This is the contract the whole platform is built on, and the one other plugins implement to
 * contribute checks of their own, so it is deliberately small: a diagnostic says what it is and
 * runs. Everything richer — repairs, recommendations, verification — hangs off the result it
 * returns rather than widening what every implementation has to provide.
 *
 * A diagnostic's ID is its permanent identity. Issues, evidence, history and reports all refer
 * to it, so it is chosen once and never changed: renaming one severs a site's recorded history
 * from the check that produced it.
 *
 * Implementations report problems. They do not throw to report one — an exception means the
 * check itself broke, which the engine records as an error result and carries on from.
 */
interface DiagnosticInterface
{
    /**
     * This diagnostic's permanent identity, unique across every diagnostic registered.
     *
     * Dot-separated and scoped by what it is about — `craft.version`, `queue.failedJobs` — so
     * IDs from different sources stay distinguishable. The same class always returns the same
     * value, for the life of the plugin.
     */
    public function id(): string;

    /**
     * What this check is called, as a reader sees it.
     */
    public function name(): string;

    /**
     * The part of the installation this check is about.
     */
    public function category(): DiagnosticCategory;

    /**
     * What this check looks at and why it matters, for someone deciding whether to run it.
     */
    public function description(): string;

    /**
     * Whether this check has anything to say in this context.
     *
     * Answered without doing the work: a check that needs Commerce says so by looking for
     * Commerce, not by running and failing. The engine records a skipped result instead.
     */
    public function isApplicable(DiagnosticContext $context): bool;

    /**
     * Performs the check.
     *
     * Returns a result whatever it finds, including when what it finds is a serious problem.
     * The context bounds the work: depth, site and options are instructions, not suggestions.
     */
    public function run(DiagnosticContext $context): DiagnosticResult;
}
