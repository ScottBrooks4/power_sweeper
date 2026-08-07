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

        // Already a member path or a fully quoted Power Fx name — do not wrap again
        // (wrapping "'PACS Homepage'" would produce '''PACS Homepage''').
        if (str_contains($new, '.') || self::isQuotedName($new)) {
            return $new;
        }

        return "'" . str_replace("'", "''", $new) . "'";
    }

    private static function isQuotedName(string $value): bool
    {
        return str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2;
    }

    private static function replaceIdentifier(string $code, string $old, string $new): string
    {
        if (str_contains($old, ' ') || !preg_match('/^[A-Za-z_][\w]*$/', $old)) {
            return $code;
        }

        // Bare-identifier slot: quote replacements that are not bare ids
        // (e.g. "TDR Trips_ TopMenu_1" must become 'TDR Trips_ TopMenu_1').
        $replacement = $new;
        if (!str_contains($new, '.') && !self::isQuotedName($new) && !preg_match('/^[A-Za-z_][\w]*$/', $new)) {
            $replacement = "'" . str_replace("'", "''", $new) . "'";
        }

        // Unquoted identifier — skip member access (preceded by '.') so
        // 'Screen'.Date is not re-qualified to 'Screen'.'Screen'.Date.
        $pattern = '/(?<![\w.])' . preg_quote($old, '/') . '(?![\w])/';

        return preg_replace($pattern, $replacement, $code) ?? $code;
    }
}
