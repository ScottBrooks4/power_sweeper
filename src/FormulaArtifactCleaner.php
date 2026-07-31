<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Remove non-semantic artifacts from formulas (YAML/JSON merge duplicates).
 * Idempotent.
 */
final class FormulaArtifactCleaner
{
    public static function clean(string $formula): string
    {
        $formula = self::dedupeLeadingEqualsLine($formula);
        $formula = self::dedupeRepeatedBody($formula);

        return $formula;
    }

    private static function dedupeLeadingEqualsLine(string $formula): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $formula) ?: [$formula];
        if (count($lines) < 2) {
            return $formula;
        }
        $first = ltrim(trim($lines[0]), '=');
        foreach (array_slice($lines, 1) as $line) {
            if (ltrim(trim($line), '=') === $first) {
                return $lines[0];
            }
        }

        return $formula;
    }

    private static function dedupeRepeatedBody(string $formula): string
    {
        $trim = trim($formula);
        $len = strlen($trim);
        if ($len < 40 || $len % 2 !== 0) {
            return $formula;
        }
        $half = (int) ($len / 2);
        if (substr($trim, 0, $half) === substr($trim, $half)) {
            return substr($trim, 0, $half);
        }

        return $formula;
    }
}
