<?php

namespace Tahadudhiya\WebDoctor\models;

use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\Severity;

/**
 * What the Issue Center was asked to show.
 *
 * Every field is read from the request one at a time and checked against something that already
 * knows the answers — an enum's cases, a list of sortable columns, a numeric range. Nothing a
 * request sends reaches a query as itself. That is not only the no-mass-assignment rule: a
 * filter is the one place in the Issue Center where a URL decides what the database is asked,
 * and a sort column taken on trust is an injection point.
 *
 * Anything unrecognised is dropped rather than refused. A stale bookmark naming a status that no
 * longer exists should show the issue list, not an error page.
 */
final class IssueFilter
{
    /** @var string What the site filter says to mean "issues that belong to no particular site". */
    public const NO_SITE = 'none';

    /** @var int How many issues a page holds. */
    public const PER_PAGE = 50;

    /** @var int The most a page may be asked to hold, so a URL cannot ask for the whole table. */
    public const MAX_PER_PAGE = 200;

    /**
     * @var array<string, string> What may be sorted on, and the column it means. Stated as a map
     * rather than validated as a string, so only these ever reach an `ORDER BY`.
     */
    public const SORTABLE = [
        'lastDetected' => 'lastDetected',
        'firstDetected' => 'firstDetected',
        'severity' => 'severity',
        'status' => 'status',
        'occurrences' => 'occurrences',
        'diagnostic' => 'diagnosticId',
        'title' => 'title',
    ];

    /**
     * @param list<IssueStatus> $statuses Empty means any.
     * @param list<Severity> $severities Empty means any.
     * @param string|null $detectedFrom A `Y-m-d` date, or null.
     * @param string|null $detectedTo A `Y-m-d` date, or null.
     */
    public function __construct(
        public readonly array $statuses = [],
        public readonly array $severities = [],
        public readonly ?string $diagnosticId = null,
        public readonly ?int $siteId = null,
        public readonly bool $withoutSite = false,
        public readonly ?string $environment = null,
        public readonly ?string $detectedFrom = null,
        public readonly ?string $detectedTo = null,
        public readonly string $sort = 'lastDetected',
        public readonly bool $ascending = false,
        public readonly int $page = 1,
        public readonly int $perPage = self::PER_PAGE,
    ) {
    }

    /**
     * Reads a filter from request parameters.
     *
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        $sort = is_string($params['sort'] ?? null) && isset(self::SORTABLE[$params['sort']])
            ? $params['sort']
            : 'lastDetected';

        return new self(
            statuses: self::enums($params['status'] ?? null, IssueStatus::class),
            severities: self::enums($params['severity'] ?? null, Severity::class),
            diagnosticId: self::text($params['diagnostic'] ?? null, 100),
            siteId: self::id($params['siteId'] ?? null),
            // A run with no particular site produces issues with no site, and they have to be
            // reachable. An absent filter means "any site", so "no site" needs to be sayable.
            withoutSite: ($params['siteId'] ?? null) === self::NO_SITE,
            environment: self::text($params['environment'] ?? null, 255),
            detectedFrom: self::date($params['from'] ?? null),
            detectedTo: self::date($params['to'] ?? null),
            sort: $sort,
            ascending: ($params['dir'] ?? null) === 'asc',
            page: max(1, self::id($params['page'] ?? null) ?? 1),
            perPage: min(self::MAX_PER_PAGE, max(1, self::id($params['perPage'] ?? null) ?? self::PER_PAGE)),
        );
    }

    /**
     * The filter the Issue Center opens on: what is still outstanding, worst and most recent
     * first. Stated rather than left to an empty filter, so the list never silently starts by
     * hiding closed issues without saying that is what it is doing.
     */
    public static function outstanding(): self
    {
        return new self(statuses: IssueStatus::open());
    }

    /**
     * Whether anything is being filtered out, so the page can say so.
     */
    public function isFiltering(): bool
    {
        return $this->statuses !== []
            || $this->severities !== []
            || $this->diagnosticId !== null
            || $this->siteId !== null
            || $this->withoutSite
            || $this->environment !== null
            || $this->detectedFrom !== null
            || $this->detectedTo !== null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The same filter on another page, for the pager's links.
     */
    public function onPage(int $page): self
    {
        return new self(
            statuses: $this->statuses,
            severities: $this->severities,
            diagnosticId: $this->diagnosticId,
            siteId: $this->siteId,
            withoutSite: $this->withoutSite,
            environment: $this->environment,
            detectedFrom: $this->detectedFrom,
            detectedTo: $this->detectedTo,
            sort: $this->sort,
            ascending: $this->ascending,
            page: max(1, $page),
            perPage: $this->perPage,
        );
    }

    /**
     * The same filter sorted by another column, back on the first page.
     *
     * Asking for the column that is already in use turns the direction around, which is what a
     * reader clicking a column heading twice means. Paging is reset because page four of one
     * ordering has nothing to do with page four of another.
     */
    public function sortedBy(string $sort): self
    {
        if (!isset(self::SORTABLE[$sort])) {
            return $this;
        }

        return new self(
            statuses: $this->statuses,
            severities: $this->severities,
            diagnosticId: $this->diagnosticId,
            siteId: $this->siteId,
            withoutSite: $this->withoutSite,
            environment: $this->environment,
            detectedFrom: $this->detectedFrom,
            detectedTo: $this->detectedTo,
            sort: $sort,
            ascending: $this->sort === $sort ? !$this->ascending : false,
            page: 1,
            perPage: $this->perPage,
        );
    }

    public function isSortedBy(string $sort): bool
    {
        return $this->sort === $sort;
    }

    /**
     * The filter as query parameters, so a link can carry it. Only what is set is included, so a
     * URL says what was asked for rather than restating every default.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $params = [];

        if ($this->statuses !== []) {
            $params['status'] = array_map(static fn(IssueStatus $s): string => $s->value, $this->statuses);
        }

        if ($this->severities !== []) {
            $params['severity'] = array_map(static fn(Severity $s): string => $s->value, $this->severities);
        }

        foreach ([
            'diagnostic' => $this->diagnosticId,
            'siteId' => $this->withoutSite ? self::NO_SITE : $this->siteId,
            'environment' => $this->environment,
            'from' => $this->detectedFrom,
            'to' => $this->detectedTo,
        ] as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        if ($this->sort !== 'lastDetected') {
            $params['sort'] = $this->sort;
        }

        if ($this->ascending) {
            $params['dir'] = 'asc';
        }

        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        if ($this->perPage !== self::PER_PAGE) {
            $params['perPage'] = $this->perPage;
        }

        return $params;
    }

    /**
     * Whether a status is among the ones being asked for. Used by the form to tick its boxes.
     */
    public function hasStatus(IssueStatus $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    public function hasSeverity(Severity $severity): bool
    {
        return in_array($severity, $this->severities, true);
    }

    /**
     * Turns whatever arrived into the cases of a backed enum, dropping anything that is not one.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return list<T>
     */
    private static function enums(mixed $value, string $enum): array
    {
        $values = is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : []);
        $cases = [];

        foreach ($values as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $case = $enum::tryFrom($candidate);

            if ($case !== null && !in_array($case, $cases, true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    private static function text(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $id = (int)$value;

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * A calendar date, or nothing. Parsed rather than trusted: this ends up in a comparison
     * against a datetime column.
     */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
