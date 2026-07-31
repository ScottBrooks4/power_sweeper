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
 * Studio symptoms this targets (seen in App checker after language/region switches):
 *   - Expected operator
 *   - Invalid number of arguments (e.g. Size / Orientation received 2, expected 1)
 *   - ParseJSON / If / LookUp argument errors
 *   - '.' operator cannot be used in this context (cascade from bad separators)
 *   - Expecting a true or false value (broken If/Checked formulas)
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

        $masked = self::maskProtected($s);
        if (str_contains($masked, ';;')) {
            return true;
        }

        // Function / record args separated with ; instead of ,
        // e.g. RGBA(255; 255; 255; 1), If(a; b; c), ParseJSON(x; y), LookUp(t; c; f)
        if (preg_match('/\b[A-Za-z_][\w.]*\s*\([^)"\']*;/', $masked)) {
            return true;
        }

        // LookUp('Table'; ID = 1) — locale list sep after a quoted datasource arg
        if (preg_match("/'(?:[^']|'')+'\\s*;/", $masked)) {
            return true;
        }

        // Record / table field separators: { Name: "x"; Amount: 1 }
        if (preg_match('/\{[^}"\']*;/', $masked)) {
            return true;
        }

        // German-style thousands + decimal: 12.345,67
        if (preg_match('/(?<![A-Za-z_])\d{1,3}(?:\.\d{3})+,\d+/', $masked)) {
            return true;
        }

        // Standalone decimal comma (0,5 / 12,5), but NOT compact invariant lists
        // like RGBA(0,0,0,0) which are comma-separated numeric args (3+ numbers).
        if (self::hasStandaloneDecimalComma($masked)) {
            return true;
        }

        // Half-converted color alphas: RGBA(240, 240, 240, 0,2) or RGBA(119, 119, 119, ,4)
        // Studio reports these as Invalid number of arguments / Expected operator.
        if (self::hasBrokenColorAlpha($masked)) {
            return true;
        }

        return false;
    }

    /**
     * Convert locale (comma-decimal) formula text to invariant form.
     */
    public static function toInvariant(string $formula, bool $force = false): string
    {
        if ($formula === '') {
            return $formula;
        }

        $prefix = '';
        if (str_starts_with($formula, '=')) {
            $prefix = '=';
            $body = substr($formula, 1);
        } else {
            $body = $formula;
        }

        if (!self::looksLocaleCorrupted($formula) && !$force) {
            return $formula;
        }
        if ($force && !self::looksLocaleCorrupted($formula)) {
            $masked = self::maskProtected($body);
            $hasSignal = str_contains($masked, ';;')
                || self::hasStandaloneDecimalComma($masked)
                || self::hasBrokenColorAlpha($masked)
                || preg_match('/(?<![A-Za-z_])\d{1,3}(?:\.\d{3})+,\d+/', $masked)
                || preg_match('/\b[A-Za-z_][\w.]*\s*\([^)"\']*;/', $masked)
                || preg_match("/'(?:[^']|'')+'\\s*;/", $masked)
                || preg_match('/\{[^}"\']*;/', $masked);
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
        // 1) Thousands + decimal FIRST (while ';' still marks locale lists)
        $code = preg_replace_callback(
            '/(?<![A-Za-z_])\d{1,3}(?:\.\d{3})+,\d+/',
            static function (array $m): string {
                $n = $m[0];
                $n = str_replace('.', '', $n);
                return str_replace(',', '.', $n);
            },
            $code
        ) ?? $code;

        // 2) Standalone decimal commas BEFORE ';' → ',' (critical ordering).
        //    Otherwise RGBA(0;0;0;1) becomes RGBA(0,0,0,1) then wrongly RGBA(0.0,0.0).
        $code = self::convertStandaloneDecimalCommas($code);

        // 3) Chaining ;; → placeholder
        $code = str_replace(';;', "\x00CHAIN\x00", $code);

        // 4) List separator ; → ,
        $code = str_replace(';', ',', $code);

        // 5) Restore chaining as ;
        $code = str_replace("\x00CHAIN\x00", ';', $code);

        // 6) Half-converted color alphas BEFORE collapsing empty ,, 
        //    (RGBA(119, 119, 119, ,4) must become 0.4, not lose the empty slot).
        $code = self::repairBrokenColorAlpha($code);

        // 7) Studio double-comma bug (empty args)
        $code = preg_replace('/,(?=\s*,)/', '', $code) ?? $code;

        return $code;
    }

    /**
     * Detect RGBA/ColorFade-style calls where locale alpha survived as an extra
     * list arg: RGBA(r, g, b, 0,2) or RGBA(r, g, b, ,4).
     */
    private static function hasBrokenColorAlpha(string $masked): bool
    {
        return preg_match(
            '/\bRGBA?\s*\(\s*\d+(?:\.\d+)?\s*,\s*\d+(?:\.\d+)?\s*,\s*\d+(?:\.\d+)?\s*,\s*\d+\s*,\s*\d+\s*\)/i',
            $masked
        ) === 1
            || preg_match(
                '/\bRGBA?\s*\(\s*\d+(?:\.\d+)?\s*,\s*\d+(?:\.\d+)?\s*,\s*\d+(?:\.\d+)?\s*,\s*,\s*\d+\s*\)/i',
                $masked
            ) === 1;
    }

    /**
     * RGBA(240, 240, 240, 0,2) → RGBA(240, 240, 240, 0.2)
     * RGBA(119, 119, 119, ,4) → RGBA(119, 119, 119, 0.4)
     */
    private static function repairBrokenColorAlpha(string $code): string
    {
        $code = preg_replace_callback(
            '/\b(RGBA?)\s*\(\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i',
            static function (array $m): string {
                return sprintf('%s(%s, %s, %s, %s.%s)', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
            },
            $code
        ) ?? $code;

        $code = preg_replace_callback(
            '/\b(RGBA?)\s*\(\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*,\s*,\s*(\d+)\s*\)/i',
            static function (array $m): string {
                return sprintf('%s(%s, %s, %s, 0.%s)', $m[1], $m[2], $m[3], $m[4], $m[5]);
            },
            $code
        ) ?? $code;

        return $code;
    }

    /**
     * True when masked code has a decimal comma that is not just a multi-arg
     * numeric list (RGBA(0,0,0,1), etc.).
     */
    private static function hasStandaloneDecimalComma(string $masked): bool
    {
        $withoutLists = preg_replace(
            '/(?<![A-Za-z_.])\d+(?:\s*,\s*\d+){2,}/',
            ' ',
            $masked
        ) ?? $masked;

        return preg_match('/(?<![A-Za-z_.])\d+,\d+/', $withoutLists) === 1;
    }

    /**
     * Convert 12,5 → 12.5 while leaving comma-separated numeric lists intact.
     */
    private static function convertStandaloneDecimalCommas(string $code): string
    {
        $protected = [];
        $code = preg_replace_callback(
            '/(?<![A-Za-z_.])\d+(?:\s*,\s*\d+){2,}/',
            static function (array $m) use (&$protected): string {
                $key = "\x00NUMLIST" . count($protected) . "\x00";
                $protected[$key] = $m[0];
                return $key;
            },
            $code
        ) ?? $code;

        $code = preg_replace_callback(
            '/(?<![A-Za-z_.])\d+,\d+(?!\d)/',
            static fn(array $m): string => str_replace(',', '.', $m[0]),
            $code
        ) ?? $code;

        if ($protected !== []) {
            $code = str_replace(array_keys($protected), array_values($protected), $code);
        }

        return $code;
    }

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

            if ($s[$i] === '"') {
                $flush('code', $buf);
                $j = $i + 1;
                while ($j < $len) {
                    if ($s[$j] === '"') {
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
