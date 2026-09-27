<?php

namespace Tahadudhiya\WebDoctor\helpers;

/**
 * Arranges a piece of evidence for a reader, marking everything redaction withheld or cut short.
 *
 * Redaction leaves marks behind — `[redacted]`, `… [truncated]`, a count of entries left out —
 * and a page that printed them as they stand would leave the reader to guess whether a bracket
 * was part of the value. So the evidence is turned into a tree of typed nodes first, and the
 * page renders a withheld value as a withheld value. Nothing here can reveal anything: it only
 * ever sees evidence that was redacted when it was built.
 */
final class EvidenceDisplay
{
    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed> A `map` or `list` node.
     */
    public static function tree(array $data): array
    {
        return self::structure($data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function node(mixed $value): array
    {
        if (is_array($value)) {
            return self::structure($value);
        }

        if ($value === null) {
            return ['kind' => 'scalar', 'value' => 'null'];
        }

        if (is_bool($value)) {
            return ['kind' => 'scalar', 'value' => $value ? 'true' : 'false'];
        }

        if (is_int($value) || is_float($value)) {
            return ['kind' => 'scalar', 'value' => (string)$value];
        }

        $value = (string)$value;

        if ($value === Redaction::REDACTED) {
            return ['kind' => 'redacted'];
        }

        if (Redaction::isPresence($value)) {
            return ['kind' => 'presence', 'value' => $value];
        }

        if ($value === Redaction::DEPTH_LIMIT) {
            return ['kind' => 'deep'];
        }

        return self::text($value);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function structure(array $value): array
    {
        $omitted = null;

        if (array_key_exists(Redaction::OMITTED_KEY, $value)) {
            $omitted = (string)$value[Redaction::OMITTED_KEY];
            unset($value[Redaction::OMITTED_KEY]);
        }

        if (array_is_list($value)) {
            return [
                'kind' => 'list',
                'items' => array_map(self::node(...), $value),
                'omitted' => $omitted,
            ];
        }

        $entries = [];

        foreach ($value as $key => $item) {
            $entries[] = ['key' => (string)$key, 'node' => self::node($item)];
        }

        return ['kind' => 'map', 'entries' => $entries, 'omitted' => $omitted];
    }

    /**
     * Free text, split around anything withheld inside it so each withheld part can be shown as
     * such while the words around it — the part that explains the failure — stay readable.
     *
     * @return array<string, mixed>
     */
    private static function text(string $value): array
    {
        $truncated = str_ends_with($value, Redaction::TRUNCATED);

        if ($truncated) {
            $value = substr($value, 0, -strlen(Redaction::TRUNCATED));
        }

        $segments = [];
        $parts = explode(Redaction::REDACTED, $value);

        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $segments[] = ['text' => $part, 'redacted' => false];
            }

            if ($i < count($parts) - 1) {
                $segments[] = ['text' => '', 'redacted' => true];
            }
        }

        return ['kind' => 'text', 'segments' => $segments, 'truncated' => $truncated];
    }
}
