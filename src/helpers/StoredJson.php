<?php

namespace Tahadudhiya\WebDoctor\helpers;

/**
 * JSON read back from a column Web Doctor wrote, strictly: nothing stored is nothing, a structure is
 * read as itself, and anything else is unreadable — never an empty structure, which would read as
 * "there was nothing" where there was something that could not be read.
 */
final class StoredJson
{
    /**
     * @return array{0: array<array-key, mixed>, 1: bool} What is stored, and whether it could be read.
     */
    public static function decode(?string $json): array
    {
        if ($json === null) {
            return [[], true];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? [$decoded, true] : [[], false];
    }
}
