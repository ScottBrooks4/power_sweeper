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

        // Function / record args separated with ; instead of , (check unmasked — color blobs use ; legitimately only after mask)
        // e.g. RGBA(255; 255; 255; 1), If(a; b; c), ParseJSON(x; y), LookUp(t; c; f)
        if (preg_match('/\b[A-Za-z_][\w.]*\s*\([^)"\']*;/', $s)) {
            return true;
        }

        // Record / table field separators: { Name: "x"; Amount: 1 }
        if (preg_match('/\{[^}"\']*;/', $s)) {
            return true;
        }

        // Decimal comma numbers: 12,5 or 12.345,67 or Parent.Width * 0,5
        if (preg_match('/(?<![A-Za-z_])\d{1,3}(?:\.\d{3})+,\d+/', $masked)) {
            return true;
        }
        if (preg_match('/(?<![A-Za-z_.])\d+,\d+/', $masked)) {
            return true;
        }

        // Prior unwhack bug: RGBA(0,0,0,0) → RGBA(0.0,0.0)
        if (preg_match('/\bRGBA?\(\s*\d+\.\d+\s*,\s*\d+\.\d+\s*\)/i', $s)) {
            return true;
        }

        // Locale-broken RGBA alpha: RGBA(240, 240, 240, 0,2)
        if (preg_match('/\bRGBA?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)/i', $s)) {
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
                || preg_match('/(?<![A-Za-z_.])\d+,\d+/', $masked)
                || preg_match('/\b[A-Za-z_][\w.]*\s*\([^)"\']*;/', $masked)
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
        [$code, $colorBlobs] = self::maskColorFunctions($code);

        // 1) Chaining ;; → placeholder
        $code = str_replace(';;', "\x00CHAIN\x00", $code);

        // 2) List separator ; → ,
        $code = str_replace(';', ',', $code);

        // 3) Restore chaining as ;
        $code = str_replace("\x00CHAIN\x00", ';', $code);

        // 4) German-style thousands + decimal: 12.345,67 → 12345.67
        $code = preg_replace_callback(
            '/(?<![A-Za-z_])\d{1,3}(?:\.\d{3})+,\d+/',
            static function (array $m): string {
                $n = $m[0];
                $n = str_replace('.', '', $n);
                return str_replace(',', '.', $n);
            },
            $code
        ) ?? $code;

        // Plain decimal comma: 12,5 / 0,5 → 12.5 / 0.5 (never RGBA/RGB list commas — masked above)
        $code = preg_replace_callback(
            '/(?<![A-Za-z_.])\d+,\d+(?!\d)/',
            static fn(array $m): string => str_replace(',', '.', $m[0]),
            $code
        ) ?? $code;

        // Studio double-comma bug
        $code = preg_replace('/,(?=\s*,)/', '', $code) ?? $code;

        return self::unmaskColorFunctions($code, $colorBlobs);
    }

    /**
     * Mask RGBA/RGB/ColorFade/ColorValue calls so list commas are not treated as decimal commas.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private static function maskColorFunctions(string $code): array
    {
        $store = [];
        $out = '';
        $len = strlen($code);
        $i = 0;

        while ($i < $len) {
            if (!preg_match('/\b(RGBA|RGB|ColorFade|ColorValue)\s*\(/i', $code, $m, PREG_OFFSET_CAPTURE, $i)) {
                $out .= substr($code, $i);
                break;
            }

            $start = $m[0][1];
            $out .= substr($code, $i, $start - $i);
            $open = strpos($code, '(', $start);
            if ($open === false) {
                $out .= substr($code, $start);
                break;
            }

            $depth = 0;
            $j = $open;
            while ($j < $len) {
                $ch = $code[$j];
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $j++;
                        break;
                    }
                }
                $j++;
            }

            $blob = substr($code, $start, $j - $start);
            $key = "\x00CF" . count($store) . "\x00";
            $store[$key] = self::normalizeMaskedColorFunction($blob);
            $out .= $key;
            $i = $j;
        }

        return [$out, $store];
    }

    /** @param array<string,string> $store */
    private static function unmaskColorFunctions(string $code, array $store): string
    {
        foreach ($store as $key => $blob) {
            $code = str_replace($key, $blob, $code);
        }
        return $code;
    }

  /**
     * Fix separators inside a single color function literal (locale ; lists, 0,2 alpha).
     */
    private static function normalizeMaskedColorFunction(string $blob): string
    {
        // Prior buggy unwhack: RGBA(0.0,0.0) → transparent black
        if (preg_match('/^RGBA?\(\s*\d+\.\d+\s*,\s*\d+\.\d+\s*\)$/i', trim($blob))) {
            return 'RGBA(0, 0, 0, 0)';
        }

        $inner = str_replace(';', ',', $blob);
        return ColorValue::fixLocaleBrokenAlpha($inner);
    }

    private static function maskProtected(string $s): string
    {
        [$masked,] = self::maskColorFunctions($s);
        $parts = self::splitProtected($masked);
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
