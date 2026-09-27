<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * The stable identity of an underlying problem, so the same problem found again updates the issue
 * that already describes it instead of raising a second one.
 *
 * Only what identifies the problem goes in: which check found it, where, and what it is about.
 * Everything that describes the current reading of it — the status, the severity, the wording —
 * stays out, because a problem that gets worse is the same problem. Folding any of those in would
 * raise a fresh issue every time a number moved and split one problem's history in two.
 */
final class Fingerprint
{
    /**
     * @var string The scheme these fingerprints were produced under. Named inside the hash, so
     * changing what identity means re-identifies issues openly rather than merging two schemes'
     * answers into one row.
     */
    public const SCHEME = 'v2';

    /** @var string Separates the parts, so two different splits cannot produce one string. */
    private const SEPARATOR = "\x1f";

    /** @var string Stands in for a part that is absent, so absent and empty stay distinct. */
    private const ABSENT = "\x00";

    /**
     * The fingerprint of the problem this result reports.
     *
     * The environment and the site are part of it because a problem in production is not the same
     * problem as the same reading in staging, and findings are never mixed across sites. The
     * affected component and plugin are how a check that can find several distinct problems says
     * which one this is; a check that does not distinguish them gets one issue covering all of
     * them, which is the safe direction to be wrong in.
     */
    public static function forResult(DiagnosticResult $result, string $environment, ?int $siteId): string
    {
        return self::of([
            self::SCHEME,
            $result->diagnosticId,
            $environment,
            $siteId === null ? null : (string)$siteId,
            $result->affectedComponent,
            $result->affectedPlugin,
        ]);
    }

    /**
     * Hashes the parts into one fingerprint. SHA-256 because a collision would merge two
     * unrelated issues into one.
     *
     * @param list<string|null> $parts
     */
    public static function of(array $parts): string
    {
        $canonical = implode(self::SEPARATOR, array_map(
            static fn(?string $part): string => $part === null ? self::ABSENT : trim($part),
            $parts,
        ));

        return hash('sha256', $canonical);
    }
}
