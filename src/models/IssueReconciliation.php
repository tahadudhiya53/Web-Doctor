<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * What a run did to the Issue Center.
 *
 * Reported rather than inferred from the run itself, because the two are different things: a run
 * that found the same three failures as the last one opened nothing, and saying "3 issues" about
 * it would suggest three new problems appeared.
 */
final class IssueReconciliation
{
    public function __construct(
        public readonly int $opened = 0,
        public readonly int $updated = 0,
        public readonly int $recurred = 0,
        public readonly int $resolved = 0,
    ) {
    }

    /**
     * Whether anything changed at all.
     */
    public function isEmpty(): bool
    {
        return $this->opened === 0 && $this->updated === 0 && $this->recurred === 0 && $this->resolved === 0;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'opened' => $this->opened,
            'updated' => $this->updated,
            'recurred' => $this->recurred,
            'resolved' => $this->resolved,
        ];
    }
}
