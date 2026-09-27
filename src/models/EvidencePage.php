<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * One page of an issue's evidence. An issue open for months has seen a great deal, and the page
 * showing it loads a page of it rather than the lot.
 */
final class EvidencePage
{
    /**
     * @param list<StoredEvidence> $items The evidence on this page, newest first.
     * @param int $total How much there is altogether.
     * @param int $page Which page this is, counting from one.
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
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
}
