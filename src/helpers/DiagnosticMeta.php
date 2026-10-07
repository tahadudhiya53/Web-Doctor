<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\models\SafeException;
use Throwable;

/**
 * Asks a diagnostic what it is, defensively.
 *
 * A diagnostic may be another plugin's, and one that throws when asked its own name must cost a
 * reader the name rather than the run or the page. That matters most where it is asked while a
 * failure is already being handled: a second throw from inside the catch block containing the
 * first would escape it and take the whole run down — the one failure the engine exists to
 * prevent. A check that cannot say what it is called is described by its class instead.
 */
final class DiagnosticMeta
{
    public static function id(DiagnosticInterface $diagnostic): string
    {
        try {
            $id = $diagnostic->id();
        } catch (Throwable $e) {
            self::failed($diagnostic, 'ID', $e);

            return $diagnostic::class;
        }

        return $id !== '' ? $id : $diagnostic::class;
    }

    /**
     * A check's name, redacted. Redacted here because this is where every name that is not already
     * part of a result is read — a check shown before it has run, on the dashboard or in an
     * investigation's plan — and a name is whatever the contributing plugin wrote.
     */
    public static function name(DiagnosticInterface $diagnostic, ?string $fallback = null): string
    {
        $fallback ??= $diagnostic::class;

        try {
            $name = $diagnostic->name();
        } catch (Throwable $e) {
            self::failed($diagnostic, 'name', $e);

            return $fallback;
        }

        return $name !== '' ? Redaction::redactString($name) : $fallback;
    }

    public static function category(DiagnosticInterface $diagnostic): DiagnosticCategory
    {
        try {
            return $diagnostic->category();
        } catch (Throwable $e) {
            self::failed($diagnostic, 'category', $e);

            return DiagnosticCategory::CONFIGURATION;
        }
    }

    /**
     * Records that a check could not say what it is. Logging never throws from here, since this can
     * be reached while a failure is already being contained.
     */
    private static function failed(DiagnosticInterface $diagnostic, string $what, Throwable $e): void
    {
        try {
            SafeException::log(sprintf('The diagnostic %s could not say its %s', $diagnostic::class, $what), $e);
        } catch (Throwable) {
        }
    }
}
