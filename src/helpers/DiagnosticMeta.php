<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Tahadudhiya\WebDoctor\base\DiagnosticInterface;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
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
        } catch (Throwable) {
            return $diagnostic::class;
        }

        return $id !== '' ? $id : $diagnostic::class;
    }

    public static function name(DiagnosticInterface $diagnostic, ?string $fallback = null): string
    {
        $fallback ??= $diagnostic::class;

        try {
            $name = $diagnostic->name();
        } catch (Throwable) {
            return $fallback;
        }

        return $name !== '' ? $name : $fallback;
    }

    public static function category(DiagnosticInterface $diagnostic): DiagnosticCategory
    {
        try {
            return $diagnostic->category();
        } catch (Throwable) {
            return DiagnosticCategory::CONFIGURATION;
        }
    }
}
