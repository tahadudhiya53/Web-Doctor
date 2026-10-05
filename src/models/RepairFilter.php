<?php

namespace Tahadudhiya\WebDoctor\models;

use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\helpers\QueryParams;
use Tahadudhiya\WebDoctor\services\Diagnostics;

/**
 * What the repair history was asked to show. Read through {@see QueryParams}, so a value sent is
 * taken exactly or refused, as the Issue Center's filter is.
 *
 * Dates are when a repair was previewed, which every repair has; whether and when it was carried
 * out is its status. Newest first unless the oldest are asked for, the ID breaking ties.
 */
final class RepairFilter
{
    /** @var string What the site filter says to mean "repairs of issues that belong to no particular site". */
    public const NO_SITE = 'none';

    public const PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    /**
     * @param list<RepairStatus> $statuses Empty means any.
     * @param list<VerificationStatus> $verifications Empty means any.
     * @param int|null $userId Somebody who previewed it or carried it out.
     * @param string|null $from A `Y-m-d` date, or null.
     * @param string|null $to A `Y-m-d` date, or null.
     */
    public function __construct(
        public readonly ?string $action = null,
        public readonly array $statuses = [],
        public readonly array $verifications = [],
        public readonly ?int $userId = null,
        public readonly ?string $environment = null,
        public readonly ?int $siteId = null,
        public readonly bool $withoutSite = false,
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

        // A repair action's ID has a diagnostic ID's shape, so nothing else is looked for. Not
        // `action`: Craft reads a request's `action` parameter as the controller route.
        $action = QueryParams::text($params, 'repairAction', 100);

        if ($action !== null && !Diagnostics::isValidId($action)) {
            throw new \InvalidArgumentException('repairAction');
        }

        $site = QueryParams::present($params, 'siteId') ? $params['siteId'] : null;
        $from = QueryParams::date($params, 'from');
        $to = QueryParams::date($params, 'to');

        if ($from !== null && $to !== null && $to < $from) {
            throw new \InvalidArgumentException('to');
        }

        return new self(
            action: $action,
            statuses: QueryParams::enums($params, 'status', RepairStatus::class),
            verifications: QueryParams::enums($params, 'verification', VerificationStatus::class),
            userId: QueryParams::positive($params, 'user'),
            environment: QueryParams::text($params, 'environment', 255),
            siteId: $site === self::NO_SITE ? null : QueryParams::positive($params, 'siteId'),
            withoutSite: $site === self::NO_SITE,
            from: $from,
            to: $to,
            oldestFirst: QueryParams::choice($params, 'order', ['newest', 'oldest']) === 'oldest',
            page: QueryParams::positive($params, 'page') ?? 1,
            perPage: $perPage,
        );
    }

    public function isFiltering(): bool
    {
        return $this->action !== null
            || $this->statuses !== []
            || $this->verifications !== []
            || $this->userId !== null
            || $this->environment !== null
            || $this->siteId !== null
            || $this->withoutSite
            || $this->from !== null
            || $this->to !== null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function onPage(int $page): self
    {
        return new self(
            action: $this->action,
            statuses: $this->statuses,
            verifications: $this->verifications,
            userId: $this->userId,
            environment: $this->environment,
            siteId: $this->siteId,
            withoutSite: $this->withoutSite,
            from: $this->from,
            to: $this->to,
            oldestFirst: $this->oldestFirst,
            page: max(1, $page),
            perPage: $this->perPage,
        );
    }

    public function hasStatus(RepairStatus $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    public function hasVerification(VerificationStatus $verification): bool
    {
        return in_array($verification, $this->verifications, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $params = [];

        if ($this->statuses !== []) {
            $params['status'] = array_map(static fn(RepairStatus $s): string => $s->value, $this->statuses);
        }

        if ($this->verifications !== []) {
            $params['verification'] = array_map(static fn(VerificationStatus $v): string => $v->value, $this->verifications);
        }

        foreach ([
            'repairAction' => $this->action,
            'user' => $this->userId,
            'environment' => $this->environment,
            'siteId' => $this->withoutSite ? self::NO_SITE : $this->siteId,
            'from' => $this->from,
            'to' => $this->to,
        ] as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        if ($this->oldestFirst) {
            $params['order'] = 'oldest';
        }

        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        if ($this->perPage !== self::PER_PAGE) {
            $params['perPage'] = $this->perPage;
        }

        return $params;
    }
}
