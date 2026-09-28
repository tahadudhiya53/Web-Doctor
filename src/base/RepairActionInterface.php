<?php

namespace Tahadudhiya\WebDoctor\base;

use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\models\Prerequisite;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RepairContext;
use Tahadudhiya\WebDoctor\models\RepairReport;

/**
 * One corrective action Web Doctor can carry out for a finding, once somebody has previewed it and
 * confirmed it.
 *
 * An action does one specific thing, through Craft's own API, to exactly what its preview named.
 * It never decides whether it may run: the permission, the issue's state, the environment, its
 * prerequisites, the confirmation and whether the preview still holds are all the repair service's
 * to establish, before `execute()` is called and in that order, so an action cannot skip one.
 *
 * An action reads the installation live in `prerequisites()` and `preview()`, and the service calls
 * both again just before `execute()`: what is carried out has to be what was confirmed, so a preview
 * whose fingerprint has changed in between is refused rather than acted on.
 */
interface RepairActionInterface
{
    /** A permanent identity, in the diagnostic ID's shape, recorded against every repair. */
    public function id(): string;

    /** What the action is called, as a reader sees it. */
    public function name(): string;

    /** What it does, in a sentence or two. */
    public function description(): string;

    /** The check whose findings it answers. It is offered for nothing else. */
    public function diagnosticId(): string;

    /** The recommendation rule it carries out, where one describes the same action by hand. */
    public function recommendation(): ?string;

    /** What could go wrong carrying it out — never the seriousness of the problem. */
    public function risk(): RepairRisk;

    /** Why the risk is what it is. */
    public function riskReason(): string;

    /** Whether it answers this finding: the check's evidence has to show what it acts on. */
    public function isApplicable(RecommendationCase $finding): bool;

    /**
     * Whether Craft would let the person in front of it do the same thing by Craft's own means. A
     * repair is never a way around a permission Craft keeps: somebody who may not retry a queue job
     * in Craft's queue manager may not retry one through Web Doctor either.
     */
    public function isAuthorized(): bool;

    /** What Craft requires for it, said to somebody {@see self::isAuthorized()} refused. */
    public function authorization(): string;

    /**
     * What has to be true before it runs: the ones it can check itself, read live, and the ones only
     * a person can confirm.
     *
     * @return list<Prerequisite>
     */
    public function prerequisites(RepairContext $context): array;

    /**
     * Exactly what it would do, read live, with the state it would act on and a fingerprint of that
     * state. Changes nothing.
     */
    public function preview(RepairContext $context): RepairReport;

    /**
     * Carries out what the preview named and reports what it did and the state afterwards.
     *
     * Reads what it acts on once more first, and throws a {@see \Tahadudhiya\WebDoctor\errors\Refusal},
     * having changed nothing, unless it still matches the preview's fingerprint. Any other exception
     * means it could not finish, having done whatever it did.
     */
    public function execute(RepairContext $context, RepairReport $preview): RepairReport;

    /**
     * The checks to run again to tell whether it worked, the one that found the problem first.
     *
     * @return list<string>
     */
    public function verifyWith(): array;

    /** What the checks should show once it has worked. */
    public function verification(): string;
}
