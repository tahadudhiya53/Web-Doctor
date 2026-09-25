<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * One page of issues, and enough about the whole set to page through it. A site diagnosed for a
 * year holds thousands, and loading all of them to show fifty is the kind of thing Web Doctor
 * exists to find.
 */
final class IssueList
{
    /**
     * @param list<Issue> $issues The issues on this page, in the filter's order.
     * @param int $total How many match the filter altogether.
     */
    public function __construct(
        public readonly array $issues,
        public readonly int $total,
        public readonly IssueFilter $filter,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->issues === [];
    }

    public function pageCount(): int
    {
        return max(1, (int)ceil($this->total / max(1, $this->filter->perPage)));
    }

    public function hasPages(): bool
    {
        return $this->pageCount() > 1;
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
     * The position of the first issue on this page, counting from one, for "showing 51–100 of
     * 240". Zero when the page holds nothing — including a page number past the end, which would
     * otherwise report a range no row occupies.
     */
    public function firstPosition(): int
    {
        return $this->issues === [] ? 0 : $this->filter->offset() + 1;
    }

    public function lastPosition(): int
    {
        return $this->issues === [] ? 0 : $this->filter->offset() + count($this->issues);
    }
}
