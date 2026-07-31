<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Rename Power Fx identifiers / quoted names while leaving string literals alone.
 *
 * @param array<string, string> $map old => new
 */
final class FormulaIdentifierRewriter
{
    /**
     * @param array<string, string> $map
     */
    public static function rename(string $formula, array $map): string
    {
        if ($formula === '' || $map === []) {
            return $formula;
        }

        // Longest keys first so "Request Number" wins over "Request"
        $keys = array_keys($map);
        usort($keys, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $parts = PowerFxFormulaSegments::split($formula);
        $out = '';
        foreach ($parts as [$type, $text]) {
            if ($type === 'comment') {
                $out .= $text;
                continue;
            }
            if ($type === 'string') {
                if (str_starts_with($text, "'")) {
                    foreach ($keys as $old) {
                        $new = $map[$old];
                        if ($old === $new) {
                            continue;
                        }
                        $text = self::replaceQuotedSegment($text, $old, $new);
                    }
                }
                $out .= $text;
                continue;
            }
            foreach ($keys as $old) {
                $new = $map[$old];
                if ($old === $new) {
                    continue;
                }
                $text = self::replaceIdentifier($text, $old, $new);
            }
            $out .= $text;
        }

        return $out;
    }

    private static function replaceQuotedSegment(string $segment, string $old, string $new): string
    {
        $quotedOld = "'" . str_replace("'", "''", $old) . "'";
        if ($segment !== $quotedOld) {
            return $segment;
        }

        if (str_contains($new, '.')) {
            return $new;
        }

        return "'" . str_replace("'", "''", $new) . "'";
    }

    private static function replaceIdentifier(string $code, string $old, string $new): string
    {
        if (str_contains($old, ' ') || !preg_match('/^[A-Za-z_][\w]*$/', $old)) {
            return $code;
        }

        // Unquoted identifier — skip member access (preceded by '.') so
        // 'Screen'.Date is not re-qualified to 'Screen'.'Screen'.Date.
        $pattern = '/(?<![\w.])' . preg_quote($old, '/') . '(?![\w])/';

        return preg_replace($pattern, $new, $code) ?? $code;
    }
}
