<?php

namespace Tahadudhiya\WebDoctor\models;

use Tahadudhiya\WebDoctor\enums\AuditAction;
use Tahadudhiya\WebDoctor\enums\AuditResult;
use Tahadudhiya\WebDoctor\helpers\QueryParams;

/**
 * What the audit log was asked to show. Read from the query string through {@see QueryParams}, so
 * a value sent is taken exactly or refused, as the Issue Center's filter is.
 *
 * The log is chronological: newest first unless the oldest are asked for, with the ID breaking ties
 * between acts recorded in the same second, so two entries never swap places between pages.
 */
final class AuditFilter
{
    /** @var string What the user filter says to mean "acts nobody signed in did" — the console, the queue. */
    public const NO_USER = 'none';

    /** @var string What the site filter says to mean "acts done with no particular site in view". */
    public const NO_SITE = 'none';

    public const PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    /**
     * @param list<AuditAction> $actions Empty means any.
     * @param list<AuditResult> $results Empty means any.
     * @param string|null $from A `Y-m-d` date, or null.
     * @param string|null $to A `Y-m-d` date, or null.
     */
    public function __construct(
        public readonly array $actions = [],
        public readonly array $results = [],
        public readonly ?int $userId = null,
        public readonly bool $withoutUser = false,
        public readonly ?int $issueId = null,
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
        $user = QueryParams::present($params, 'user') ? $params['user'] : null;
        $site = QueryParams::present($params, 'siteId') ? $params['siteId'] : null;
        $perPage = QueryParams::positive($params, 'perPage') ?? self::PER_PAGE;

        if ($perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException('perPage');
        }

        $from = QueryParams::date($params, 'from');
        $to = QueryParams::date($params, 'to');

        // A range that ends before it starts holds nothing, and showing nothing would read as a
        // quiet log rather than a mistyped range.
        if ($from !== null && $to !== null && $to < $from) {
            throw new \InvalidArgumentException('to');
        }

        return new self(
            // Not `action`: Craft reads a request's `action` parameter as the controller route.
            actions: QueryParams::enums($params, 'auditAction', AuditAction::class),
            results: QueryParams::enums($params, 'result', AuditResult::class),
            userId: $user === self::NO_USER ? null : QueryParams::positive($params, 'user'),
            withoutUser: $user === self::NO_USER,
            issueId: QueryParams::positive($params, 'issue'),
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
        return $this->actions !== []
            || $this->results !== []
            || $this->userId !== null
            || $this->withoutUser
            || $this->issueId !== null
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
            actions: $this->actions,
            results: $this->results,
            userId: $this->userId,
            withoutUser: $this->withoutUser,
            issueId: $this->issueId,
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

    public function hasAction(AuditAction $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    public function hasResult(AuditResult $result): bool
    {
        return in_array($result, $this->results, true);
    }

    /**
     * The filter as query parameters, only what is set, so a pager link carries what was asked for.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $params = [];

        if ($this->actions !== []) {
            $params['auditAction'] = array_map(static fn(AuditAction $a): string => $a->value, $this->actions);
        }

        if ($this->results !== []) {
            $params['result'] = array_map(static fn(AuditResult $r): string => $r->value, $this->results);
        }

        foreach ([
            'user' => $this->withoutUser ? self::NO_USER : $this->userId,
            'issue' => $this->issueId,
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
