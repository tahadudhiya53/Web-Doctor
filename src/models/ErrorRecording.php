<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * What recording a run's errors amounted to.
 */
final class ErrorRecording
{
    /**
     * @param int $occurrences How many occurrences of an error the run's results recorded.
     * @param int $groups How many distinct errors those were.
     * @param int $created How many of those had never been seen here before.
     * @param int $omitted How many further distinct errors the run met that were not recorded,
     * because the place already holds as many as it may.
     */
    public function __construct(
        public readonly int $occurrences = 0,
        public readonly int $groups = 0,
        public readonly int $created = 0,
        public readonly int $omitted = 0,
    ) {
    }
}
