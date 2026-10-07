<?php

namespace Tahadudhiya\WebDoctor\helpers;

/**
 * Text held to a length, the one way Web Doctor cuts it: by character, never inside one, and marked
 * with an ellipsis so a reader can see something was left off.
 */
final class Text
{
    /**
     * @return ($value is null ? null : string)
     */
    public static function fit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
