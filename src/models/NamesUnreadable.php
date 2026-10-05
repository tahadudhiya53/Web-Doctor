<?php

namespace Tahadudhiya\WebDoctor\models;

/**
 * A model read back from a row, which names each field it could not read in `$unreadable` rather than
 * reading it as some other value, so a page can say which.
 */
trait NamesUnreadable
{
    public function isUnreadable(string $field): bool
    {
        return in_array($field, $this->unreadable, true);
    }
}
