<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * One page of error groups, most recently seen first.
 */
final class ErrorGroupPage
{
    /**
     * @param list<ErrorGroup> $items The groups on this page, each with the checks that ran into it.
     * @param int $total How many there are altogether.
     * @param int $page Which page this is, counting from one.
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function pageCount(): int
    {
        return max(1, (int)ceil($this->total / max(1, $this->perPage)));
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->pageCount();
    }

    /**
     * The groups' issue IDs across the page, each once, so the issues can be read in one query.
     *
     * @return list<int>
     */
    public function issueIds(): array
    {
        $ids = [];

        foreach ($this->items as $group) {
            foreach ($group->issueIds() as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
