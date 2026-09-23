<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use RuntimeException;
use yii\caching\ArrayCache;

/**
 * A cache that is there but does not work — the Redis that is down, the cache directory that is
 * no longer writable. Web Doctor has to survive both without taking the control panel with it.
 */
class FailingCache extends ArrayCache
{
    public bool $failReads = true;
    public bool $failWrites = true;

    protected function getValue($key): mixed
    {
        if ($this->failReads) {
            throw new RuntimeException('The cache could not be read.');
        }

        return parent::getValue($key);
    }

    protected function setValue($key, $value, $duration): bool
    {
        if ($this->failWrites) {
            throw new RuntimeException('The cache could not be written to.');
        }

        return parent::setValue($key, $value, $duration);
    }
}
