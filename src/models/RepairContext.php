<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * What a repair action is carried out for: the issue, the finding as its latest evidence records
 * it, the environment it runs in, and who asked.
 */
final class RepairContext
{
    /**
     * @param int|null $userId Who asked. Recorded, never used to authorise — that is the controller's.
     */
    public function __construct(
        public readonly Issue $issue,
        public readonly RecommendationCase $finding,
        public readonly string $environment,
        public readonly ?int $userId = null,
    ) {
    }
}
