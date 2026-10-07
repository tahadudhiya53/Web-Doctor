<?php

namespace Tahadudhiya\WebDoctor\base;

use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\VerificationCondition;

/**
 * What one particular repair should have left true, checked when it is verified.
 *
 * The check that found the problem answers whether the problem is gone. It cannot say whether what
 * the repair itself did holds — that the jobs it retried ran rather than merely stopped being
 * counted as failed, that the directories it created are still there — so an action written for
 * that repair says so here, reading the installation live and the repair's own record of what it
 * did.
 *
 * It reads and never writes. It never decides the verification's result either: that is the
 * verification service's, from these conditions and everything else it looked at.
 */
interface VerificationActionInterface
{
    /** A permanent identity, in the diagnostic ID's shape. */
    public function id(): string;

    /** What it checks, as a reader sees it. */
    public function name(): string;

    /** The repair action whose repairs it verifies. */
    public function repairAction(): string;

    /**
     * Whether what the repair did holds now, read live. Changes nothing.
     *
     * @return list<VerificationCondition>
     */
    public function conditions(Repair $repair): array;
}
