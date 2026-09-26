<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Craft;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use yii\web\BadRequestHttpException;

/**
 * The one reading of what a request asks Web Doctor to act on.
 *
 * A value left out gets the default its form states; a value sent is taken exactly or refused.
 * Nothing is coerced into something else that happens to be valid — `Deep` is not `deep`, and
 * `12abc` is not issue 12 — because a request that does something other than what it said is
 * worse than one that is turned away.
 */
final class RequestInput
{
    /** @var string A whole number above zero, written as one, in digits PHP can hold. */
    private const POSITIVE = '/\A[1-9]\d{0,17}\z/';

    /**
     * The depth asked for: normal when none was sent, as every run form states.
     *
     * @throws BadRequestHttpException for anything that is not one of the depths, exactly.
     */
    public static function depth(mixed $requested): DiagnosticDepth
    {
        if ($requested === null) {
            return DiagnosticDepth::NORMAL;
        }

        $depth = is_string($requested) ? DiagnosticDepth::tryFrom($requested) : null;

        if ($depth === null) {
            throw new BadRequestHttpException(Craft::t('web-doctor', 'The depth must be one of: {depths}.', [
                'depths' => implode(', ', array_map(static fn(DiagnosticDepth $d): string => $d->value, DiagnosticDepth::cases())),
            ]));
        }

        return $depth;
    }

    /**
     * A record's ID: digits only, above zero.
     *
     * @throws BadRequestHttpException for anything else.
     */
    public static function id(mixed $requested): int
    {
        // `\A…\z`, not `^…$`: `$` also matches before a final newline, which read `12\n` as issue 12.
        if ((is_string($requested) && preg_match(self::POSITIVE, $requested) === 1) || (is_int($requested) && $requested > 0)) {
            return (int)$requested;
        }

        throw new BadRequestHttpException(Craft::t('web-doctor', 'That is not a valid ID.'));
    }

    /**
     * A switch a form sends as `1` when it is on: off when nothing was sent.
     *
     * @throws BadRequestHttpException for anything else, so `all=yes` does not run everything.
     */
    public static function flag(mixed $requested): bool
    {
        if ($requested === null) {
            return false;
        }

        if ($requested === '1') {
            return true;
        }

        throw new BadRequestHttpException(Craft::t('web-doctor', 'That is not a valid choice.'));
    }

    /**
     * A list of names a form sends as `name[]`: empty when nothing was sent.
     *
     * @return list<string>
     * @throws BadRequestHttpException for anything that is not a list of strings.
     */
    public static function names(mixed $requested): array
    {
        if ($requested === null) {
            return [];
        }

        if (!is_array($requested) || !array_is_list($requested)) {
            throw new BadRequestHttpException(Craft::t('web-doctor', 'That is not a valid choice.'));
        }

        foreach ($requested as $name) {
            if (!is_string($name)) {
                throw new BadRequestHttpException(Craft::t('web-doctor', 'That is not a valid choice.'));
            }
        }

        return $requested;
    }

    /**
     * A page of a list: the first when none was sent, or sent empty.
     *
     * @throws BadRequestHttpException for anything that is not a page number, so `1.5` is not page 1.
     */
    public static function page(mixed $requested): int
    {
        if ($requested === null || $requested === '') {
            return 1;
        }

        if (is_string($requested) && preg_match(self::POSITIVE, $requested) === 1) {
            return (int)$requested;
        }

        throw new BadRequestHttpException(Craft::t('web-doctor', 'That is not a page number.'));
    }
}
