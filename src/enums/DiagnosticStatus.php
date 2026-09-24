<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * What happened when a diagnostic ran.
 *
 * Status answers one question only: what happened when this check executed? How much the
 * finding matters is a separate question, answered by {@see Severity}. The two are deliberately
 * kept apart, because conflating them makes both useless: there would be no way to say that a
 * check failed and the failure is minor, or that a check merely warned about something serious.
 *
 * So a result states both. `FAIL` with `Severity::CRITICAL` and `FAIL` with `Severity::LOW` are
 * both ordinary, valid results.
 *
 * `FAIL` means the check ran and the thing it inspects is broken. `ERROR` means the check
 * itself broke and nothing is known about the site. Those are different facts and Web Doctor
 * never reports one as the other.
 */
enum DiagnosticStatus: string
{
    /** The check ran and found nothing wrong. */
    case PASS = 'pass';

    /** The check ran and found something worth knowing that is not a problem. */
    case INFO = 'info';

    /** The check ran and found something that deserves attention but still works. */
    case WARNING = 'warning';

    /** The check ran and the thing it inspects is broken. How badly is the severity's answer. */
    case FAIL = 'fail';

    /** The check itself failed. Nothing is known about what it was looking at. */
    case ERROR = 'error';

    /** The check did not apply here, so it was not run. */
    case SKIPPED = 'skipped';

    /** The check ran but could not reach a conclusion. */
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::PASS => Craft::t('web-doctor', 'Passed'),
            self::INFO => Craft::t('web-doctor', 'Information'),
            self::WARNING => Craft::t('web-doctor', 'Warning'),
            self::FAIL => Craft::t('web-doctor', 'Failed'),
            self::ERROR => Craft::t('web-doctor', 'Error'),
            self::SKIPPED => Craft::t('web-doctor', 'Skipped'),
            self::UNKNOWN => Craft::t('web-doctor', 'Unknown'),
        };
    }

    /**
     * Whether the diagnostic reached a conclusion about what it was inspecting. A diagnostic
     * that errored or was skipped reached none, so its result says nothing about the site.
     */
    public function isConclusive(): bool
    {
        return match ($this) {
            self::PASS, self::INFO, self::WARNING, self::FAIL => true,
            self::ERROR, self::SKIPPED, self::UNKNOWN => false,
        };
    }

    /**
     * Whether this result describes something wrong with the site. Says nothing about how much
     * it matters — that is the severity.
     */
    public function isProblem(): bool
    {
        return $this === self::WARNING || $this === self::FAIL;
    }

    /**
     * Whether this result reduces the health score. A diagnostic that could not run or could
     * not conclude counts, because an unanswered question is not a clean bill of health.
     *
     * How much it reduces it by is decided by the severity alone, never by this.
     */
    public function countsTowardHealth(): bool
    {
        return match ($this) {
            self::WARNING, self::FAIL, self::ERROR, self::UNKNOWN => true,
            self::PASS, self::INFO, self::SKIPPED => false,
        };
    }

    /**
     * The severity a result carries when a diagnostic does not state one.
     *
     * A fallback, not a mapping: a diagnostic that knows how much its finding matters says so,
     * and what it says always wins. Nothing here ever produces `Severity::CRITICAL`, because
     * calling something critical is a judgement a check has to make deliberately.
     */
    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::PASS, self::INFO, self::SKIPPED => Severity::INFO,
            self::UNKNOWN => Severity::LOW,
            self::WARNING => Severity::MEDIUM,
            // A check that failed leaves a question open rather than proving a problem.
            self::ERROR => Severity::MEDIUM,
            self::FAIL => Severity::HIGH,
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
