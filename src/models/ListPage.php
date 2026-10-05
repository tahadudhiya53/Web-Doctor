<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * One page of a filtered list — issues, the audit log's entries, the repairs carried out or the
 * diagnostic runs — and enough about the whole to page through it. Each can hold thousands, so none
 * is ever read whole.
 *
 * @template T
 */
final class ListPage
{
    /**
     * @param list<T> $items On this page, in the filter's order.
     * @param int $total How many match the filter altogether.
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly IssueFilter|AuditFilter|RepairFilter|RunFilter $filter,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function pageCount(): int
    {
        return max(1, (int)ceil($this->total / max(1, $this->filter->perPage)));
    }

    public function currentPage(): int
    {
        return min($this->filter->page, $this->pageCount());
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage() > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage() < $this->pageCount();
    }

    /**
     * The position of the first item on this page, counting from one, for "showing 51–100 of 240".
     * Zero when the page holds nothing — including a page number past the end, which would otherwise
     * report a range no row occupies.
     */
    public function firstPosition(): int
    {
        return $this->items === [] ? 0 : $this->filter->offset() + 1;
    }

    public function lastPosition(): int
    {
        return $this->items === [] ? 0 : $this->filter->offset() + count($this->items);
    }
}
