<?php

namespace Tahadudhiya\WebDoctor\models;

use Tahadudhiya\WebDoctor\helpers\QueryParams;

/**
 * What the diagnostic history was asked to show. Read through {@see QueryParams}, so a value sent
 * is taken exactly or refused. Newest first unless the oldest are asked for, the ID breaking ties.
 */
final class RunFilter
{
    /** @var string What the site filter says to mean "runs with no particular site in view". */
    public const NO_SITE = 'none';

    public const PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    /**
     * @param string|null $from A `Y-m-d` date, or null.
     * @param string|null $to A `Y-m-d` date, or null.
     */
    public function __construct(
        public readonly ?string $environment = null,
        public readonly ?int $siteId = null,
        public readonly bool $withoutSite = false,
        public readonly bool $snapshotsOnly = false,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly bool $oldestFirst = false,
        public readonly int $page = 1,
        public readonly int $perPage = self::PER_PAGE,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @throws \InvalidArgumentException naming the parameter, when a value sent is not one it can be.
     */
    public static function fromParams(array $params): self
    {
        $perPage = QueryParams::positive($params, 'perPage') ?? self::PER_PAGE;

        if ($perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException('perPage');
        }

        $site = QueryParams::present($params, 'siteId') ? $params['siteId'] : null;
        $from = QueryParams::date($params, 'from');
        $to = QueryParams::date($params, 'to');

        if ($from !== null && $to !== null && $to < $from) {
            throw new \InvalidArgumentException('to');
        }

        return new self(
            environment: QueryParams::text($params, 'environment', 255),
            siteId: $site === self::NO_SITE ? null : QueryParams::positive($params, 'siteId'),
            withoutSite: $site === self::NO_SITE,
            snapshotsOnly: QueryParams::choice($params, 'scored', ['1']) === '1',
            from: $from,
            to: $to,
            oldestFirst: QueryParams::choice($params, 'order', ['newest', 'oldest']) === 'oldest',
            page: QueryParams::positive($params, 'page') ?? 1,
            perPage: $perPage,
        );
    }

    public function isFiltering(): bool
    {
        return $this->environment !== null || $this->siteId !== null || $this->withoutSite
            || $this->snapshotsOnly || $this->from !== null || $this->to !== null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function onPage(int $page): self
    {
        return new self(
            environment: $this->environment,
            siteId: $this->siteId,
            withoutSite: $this->withoutSite,
            snapshotsOnly: $this->snapshotsOnly,
            from: $this->from,
            to: $this->to,
            oldestFirst: $this->oldestFirst,
            page: max(1, $page),
            perPage: $this->perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $params = [];

        foreach ([
            'environment' => $this->environment,
            'siteId' => $this->withoutSite ? self::NO_SITE : $this->siteId,
            'scored' => $this->snapshotsOnly ? '1' : null,
            'from' => $this->from,
            'to' => $this->to,
            'order' => $this->oldestFirst ? 'oldest' : null,
            'page' => $this->page > 1 ? $this->page : null,
            'perPage' => $this->perPage !== self::PER_PAGE ? $this->perPage : null,
        ] as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
