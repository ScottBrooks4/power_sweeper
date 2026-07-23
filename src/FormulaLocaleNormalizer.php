<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Convert Power Fx formulas that were incorrectly persisted with
 * comma-decimal (e.g. de-DE / fr-FR) separators back to invariant form.
 *
 * Invariant (en-US style, what .msapp stores / InvariantScript expects):
 *   decimal ".", list ",", chaining ";"
 *
 * Locale authoring display (comma-decimal languages):
 *   decimal ",", list ";", chaining ";;"
 *
 * Studio bugs / mixed-locale edits can bake the locale form into YAML or
 * InvariantScript, including in places the formula bar cannot reach.
 */
final class FormulaLocaleNormalizer
{
    /**
     * Heuristic: does this formula look like comma-decimal locale syntax?
     */
    public static function looksLocaleCorrupted(string $formula): bool
    {
        $s = self::stripLeadingEquals($formula);
        if ($s === '') {
            return false;
        }

        // Strong signals outside of strings
        $masked = self::maskProtected($s);
        if (str_contains($masked, ';;')) {
            return true;
        }

        // Function / record args separated with ; instead of ,
        // e.g. RGBA(255; 255; 255; 1) or If(a; b; c)
        if (preg_match('/\b[A-Za-z_][\w.]*\s*\([^)"\']*;/', $masked)) {
            return true;
        }

        // Decimal comma numbers: 12,5 or 12.345,67
        if (preg_match('/(?<![A-Za-z_])\d{1,3}(?:\.\d{3})+,\d+/', $masked)) {
            return true;
        }
        if (preg_match('/(?<![A-Za-z_.])\d+,\d+/', $masked)) {
            return true;
        }

        return false;
    }

    /**
     * Convert locale (comma-decimal) formula text to invariant form.
     * Returns the original string when no conversion is warranted (unless $force).
     */
    public static function toInvariant(string $formula, bool $force = false): string
    {
        if ($formula === '') {
            return $formula;
        }

        // Preserve a single leading "=" when present (YAML formulas).
        $prefix = '';
        if (str_starts_with($formula, '=')) {
            $prefix = '=';
            $body = substr($formula, 1);
        } else {
            $body = $formula;
        }

        // Never rewrite unless locale corruption signals are present.
        // ($force is reserved for callers that already pre-filtered; still require a signal.)
        if (!self::looksLocaleCorrupted($formula) && !$force) {
            return $formula;
        }
        if ($force && !self::looksLocaleCorrupted($formula)) {
            // Force only proceeds when there is at least a semicolon list-separator pattern
            // or a decimal comma outside strings — never rewrite clean invariant chaining.
            $masked = self::maskProtected($body);
            $hasSignal = str_contains($masked, ';;')
                || preg_match('/(?<![A-Za-z_.])\d+,\d+/', $masked)
                || preg_match('/\b[A-Za-z_][\w.]*\s*\([^)"\']*;/', $masked);
            if (!$hasSignal) {
                return $formula;
            }
        }

        $parts = self::splitProtected($body);
        $out = '';
        foreach ($parts as [$type, $text]) {
            if ($type === 'code') {
                $out .= self::convertCodeSegment($text);
            } else {
                $out .= $text;
            }
        }

        return $prefix . $out;
    }

    private static function stripLeadingEquals(string $formula): string
    {
        $s = ltrim($formula);
        return str_starts_with($s, '=') ? substr($s, 1) : $s;
    }

    private static function convertCodeSegment(string $code): string
    {
        // 1) Chaining ;; → placeholder
        $code = str_replace(';;', "\x00CHAIN\x00", $code);

        // 2) List separator ; → ,
        $code = str_replace(';', ',', $code);

        // 3) Restore chaining as ;
        $code = str_replace("\x00CHAIN\x00", ';', $code);

        // 4) Normalize decimal / thousands commas in numeric literals
        // German-style thousands + decimal: 12.345,67 → 12345.67
        $code = preg_replace_callback(
            '/(?<![A-Za-z_])\d{1,3}(?:\.\d{3})+,\d+/',
            static function (array $m): string {
                $n = $m[0];
                $n = str_replace('.', '', $n);
                return str_replace(',', '.', $n);
            },
            $code
        ) ?? $code;

        // Plain decimal comma: 12,5 → 12.5 (avoid matching already-fixed or identifiers)
        $code = preg_replace_callback(
            '/(?<![A-Za-z_.])\d+,\d+(?!\d)/',
            static fn(array $m): string => str_replace(',', '.', $m[0]),
            $code
        ) ?? $code;

        // Collapse accidental double commas from known Studio bugs (,, → ,)
        // but keep ,, inside blank-looking patterns like { a: , , b: 1 } rare — only ,,
        $code = preg_replace('/,(?=\s*,)/', '', $code) ?? $code;

        return $code;
    }

    /**
     * Replace string/comment contents with spaces (same length) for detection.
     */
    private static function maskProtected(string $s): string
    {
        $parts = self::splitProtected($s);
        $out = '';
        foreach ($parts as [$type, $text]) {
            $out .= $type === 'code' ? $text : str_repeat(' ', strlen($text));
        }
        return $out;
    }

    /**
     * Split into [type, text] where type is code|string|comment.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function splitProtected(string $s): array
    {
        $parts = [];
        $len = strlen($s);
        $i = 0;
        $buf = '';
        $flush = static function (string $type, string &$buf) use (&$parts): void {
            if ($buf !== '') {
                $parts[] = [$type, $buf];
                $buf = '';
            }
        };

        while ($i < $len) {
            // Line comment
            if ($s[$i] === '/' && ($s[$i + 1] ?? '') === '/') {
                $flush('code', $buf);
                $j = $i;
                while ($j < $len && $s[$j] !== "\n") {
                    $j++;
                }
                $parts[] = ['comment', substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }

            // Block comment
            if ($s[$i] === '/' && ($s[$i + 1] ?? '') === '*') {
                $flush('code', $buf);
                $j = strpos($s, '*/', $i + 2);
                if ($j === false) {
                    $parts[] = ['comment', substr($s, $i)];
                    break;
                }
                $parts[] = ['comment', substr($s, $i, $j + 2 - $i)];
                $i = $j + 2;
                continue;
            }

            // Double-quoted string
            if ($s[$i] === '"') {
                $flush('code', $buf);
                $j = $i + 1;
                while ($j < $len) {
                    if ($s[$j] === '"') {
                        // "" escape in Power Fx
                        if (($s[$j + 1] ?? '') === '"') {
                            $j += 2;
                            continue;
                        }
                        $j++;
                        break;
                    }
                    $j++;
                }
                $parts[] = ['string', substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }

            $buf .= $s[$i];
            $i++;
        }
        $flush('code', $buf);
        return $parts;
    }
}
