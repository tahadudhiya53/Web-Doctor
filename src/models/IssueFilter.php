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
 * A value left out, or sent empty as a form's "any" is, keeps its default. A value sent is taken
 * exactly or refused: dropped, a status nobody has would have widened the list to every status,
 * closed ones included, and a page of `1.5` would have been read as page 1.
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
     * @throws \InvalidArgumentException naming the parameter, when a value sent is not one it can be.
     */
    public static function fromParams(array $params): self
    {
        $sort = self::choice($params, 'sort', array_keys(self::SORTABLE)) ?? 'lastDetected';
        $site = self::present($params, 'siteId') ? $params['siteId'] : null;
        $perPage = self::positive($params, 'perPage') ?? self::PER_PAGE;

        if ($perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException('perPage');
        }

        return new self(
            statuses: self::enums($params, 'status', IssueStatus::class),
            severities: self::enums($params, 'severity', Severity::class),
            diagnosticId: self::text($params, 'diagnostic', 100),
            // A run with no particular site produces issues with no site, and they have to be
            // reachable. An absent filter means "any site", so "no site" needs to be sayable.
            siteId: $site === self::NO_SITE ? null : self::positive($params, 'siteId'),
            withoutSite: $site === self::NO_SITE,
            environment: self::text($params, 'environment', 255),
            detectedFrom: self::date($params, 'from'),
            detectedTo: self::date($params, 'to'),
            sort: $sort,
            ascending: self::choice($params, 'dir', ['asc', 'desc']) === 'asc',
            page: self::positive($params, 'page') ?? 1,
            perPage: $perPage,
        );
    }

    /**
     * A whole number above zero, written as one: what a page or record ID can be.
     */
    public static function isPositiveNumber(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/\A[1-9]\d{0,17}\z/', $value) === 1);
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
     * What is outstanding, in the order and on the page another filter asks for: a link that sorts
     * or pages the default view without naming a status.
     */
    public static function outstandingAs(self $order): self
    {
        return new self(
            statuses: IssueStatus::open(),
            sort: $order->sort,
            ascending: $order->ascending,
            page: $order->page,
            perPage: $order->perPage,
        );
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
     * Whether a parameter was sent with something in it. Empty is what a form sends for "any".
     *
     * @param array<string, mixed> $params
     */
    private static function present(array $params, string $name): bool
    {
        return isset($params[$name]) && $params[$name] !== '' && $params[$name] !== [];
    }

    /**
     * The cases of a backed enum a parameter names, one or a list of them, each exactly.
     *
     * @template T of \BackedEnum
     * @param array<string, mixed> $params
     * @param class-string<T> $enum
     * @return list<T>
     */
    private static function enums(array $params, string $name, string $enum): array
    {
        if (!self::present($params, $name)) {
            return [];
        }

        $values = is_array($params[$name]) && array_is_list($params[$name]) ? $params[$name] : [$params[$name]];
        $cases = [];

        foreach ($values as $candidate) {
            $case = is_string($candidate) ? $enum::tryFrom($candidate) : null;

            if ($case === null) {
                throw new \InvalidArgumentException($name);
            }

            if (!in_array($case, $cases, true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * One of a fixed set of words, exactly, or null when none was sent.
     *
     * @param array<string, mixed> $params
     * @param list<string> $allowed
     */
    private static function choice(array $params, string $name, array $allowed): ?string
    {
        if (!self::present($params, $name)) {
            return null;
        }

        if (!is_string($params[$name]) || !in_array($params[$name], $allowed, true)) {
            throw new \InvalidArgumentException($name);
        }

        return $params[$name];
    }

    /**
     * Text, trimmed, no longer than a column holds, or null when none was sent. Too long is
     * refused rather than cut: cut, it would be asking for something else.
     *
     * @param array<string, mixed> $params
     */
    private static function text(array $params, string $name, int $maxLength): ?string
    {
        if (!self::present($params, $name)) {
            return null;
        }

        $value = $params[$name];

        if (!is_string($value) || mb_strlen(trim($value)) > $maxLength) {
            throw new \InvalidArgumentException($name);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function positive(array $params, string $name): ?int
    {
        if (!self::present($params, $name)) {
            return null;
        }

        if (!self::isPositiveNumber($params[$name])) {
            throw new \InvalidArgumentException($name);
        }

        return (int)$params[$name];
    }

    /**
     * A calendar date, exactly `Y-m-d`, or null when none was sent. Parsed rather than trusted:
     * this ends up in a comparison against a datetime column.
     *
     * @param array<string, mixed> $params
     */
    private static function date(array $params, string $name): ?string
    {
        if (!self::present($params, $name)) {
            return null;
        }

        $value = $params[$name];
        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($name);
        }

        return $value;
    }
}
