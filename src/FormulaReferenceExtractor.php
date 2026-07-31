<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Extract bare Power Fx identifiers from formula code segments (not inside strings/comments).
 */
final class FormulaReferenceExtractor
{
    /**
     * @return list<string> unique identifiers in left-to-right order
     */
    public static function identifiers(string $formula): array
    {
        $parts = PowerFxFormulaSegments::split($formula);
        $seen = [];
        $out = [];
        foreach ($parts as [$type, $text]) {
            if ($type === 'string') {
                if (str_starts_with($text, "'")) {
                    $name = self::unquoteSingle($text);
                    if ($name !== null && !isset($seen[$name])) {
                        $seen[$name] = true;
                        $out[] = $name;
                    }
                }
                continue;
            }
            if ($type !== 'code') {
                continue;
            }
            if (preg_match_all('/(?<![\w.])([A-Za-z_][\w]*)/', $text, $m)) {
                foreach ($m[1] as $id) {
                    if (!isset($seen[$id])) {
                        $seen[$id] = true;
                        $out[] = $id;
                    }
                }
            }
        }

        return $out;
    }

    private static function unquoteSingle(string $segment): ?string
    {
        if (!preg_match("/^'((?:[^']|'')+)'$/", $segment, $m)) {
            return null;
        }

        return str_replace("''", "'", $m[1]);
    }
}
