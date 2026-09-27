<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * What weighing one problem against the known causes came to: the causes that fit, most firmly
 * held first, and how many were weighed — including any that could not be, so a short list is
 * never mistaken for a complete one.
 */
final class RootCauseAnalysis
{
    /**
     * @param list<RootCause> $causes The causes that fit, in order.
     * @param int $weighed How many known causes were weighed.
     * @param list<string> $failed The rules that broke while being weighed, by ID.
     */
    public function __construct(
        public readonly array $causes,
        public readonly int $weighed,
        public readonly array $failed = [],
    ) {
    }

    public function leading(): ?RootCause
    {
        return $this->causes[0] ?? null;
    }
}
