<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * How much a finding matters.
 *
 * Severity is independent of {@see DiagnosticStatus}: the status says whether the check ran and
 * what it concluded, the severity says how much the conclusion matters.
 */
enum Severity: string
{
    case INFO = 'info';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::INFO => Craft::t('web-doctor', 'Info'),
            self::LOW => Craft::t('web-doctor', 'Low'),
            self::MEDIUM => Craft::t('web-doctor', 'Medium'),
            self::HIGH => Craft::t('web-doctor', 'High'),
            self::CRITICAL => Craft::t('web-doctor', 'Critical'),
        };
    }

    /**
     * Where this sits in the ordering, low to high. Sorting and comparison go through this so
     * the order is stated once rather than re-derived wherever severities are ranked.
     */
    public function rank(): int
    {
        return match ($this) {
            self::INFO => 0,
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }

    /**
     * The more severe of two severities.
     */
    public function max(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    /**
     * The most severe of the given severities, or null when there are none.
     *
     * @param iterable<self> $severities
     */
    public static function highest(iterable $severities): ?self
    {
        $highest = null;

        foreach ($severities as $severity) {
            $highest = $highest === null ? $severity : $highest->max($severity);
        }

        return $highest;
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
