<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Split Power Fx formulas into code vs protected regions (comments, strings).
 *
 * Single-quoted Power Fx names ('VCR Home Page', '8_Pertinence') are treated as
 * opaque string tokens — never scanned for bare identifiers inside them.
 */
final class PowerFxFormulaSegments
{
    /**
     * @param bool $opaqueSingleQuotes When true (default), 'Screen Name' tokens are opaque
     *                                 string segments. When false, only "..." strings and
     *                                 comments are protected — use for member-chain normalization.
     *
     * @return list<array{0:string,1:string}> [type, text] where type is code|comment|string
     */
    public static function split(string $formula, bool $opaqueSingleQuotes = true): array
    {
        $parts = [];
        $len = strlen($formula);
        $i = 0;
        $buf = '';
        $flush = static function (string $type, string &$buf) use (&$parts): void {
            if ($buf !== '') {
                $parts[] = [$type, $buf];
                $buf = '';
            }
        };

        while ($i < $len) {
            if ($formula[$i] === '/' && ($formula[$i + 1] ?? '') === '/') {
                $flush('code', $buf);
                $j = $i;
                while ($j < $len && $formula[$j] !== "\n") {
                    $j++;
                }
                $parts[] = ['comment', substr($formula, $i, $j - $i)];
                $i = $j;
                continue;
            }
            if ($formula[$i] === '/' && ($formula[$i + 1] ?? '') === '*') {
                $flush('code', $buf);
                $j = strpos($formula, '*/', $i + 2);
                if ($j === false) {
                    $parts[] = ['comment', substr($formula, $i)];
                    break;
                }
                $parts[] = ['comment', substr($formula, $i, $j + 2 - $i)];
                $i = $j + 2;
                continue;
            }
            if ($formula[$i] === '"' || ($opaqueSingleQuotes && $formula[$i] === "'")) {
                $quote = $formula[$i];
                $flush('code', $buf);
                $j = $i + 1;
                while ($j < $len) {
                    if ($formula[$j] === $quote) {
                        if (($formula[$j + 1] ?? '') === $quote) {
                            $j += 2;
                            continue;
                        }
                        $j++;
                        break;
                    }
                    $j++;
                }
                $parts[] = ['string', substr($formula, $i, $j - $i)];
                $i = $j;
                continue;
            }
            $buf .= $formula[$i];
            $i++;
        }
        $flush('code', $buf);

        return $parts;
    }

    /**
     * Split for structural rewrites (member chains, Navigate args): only "..." and comments protected.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function splitForStructure(string $formula): array
    {
        return self::split($formula, false);
    }

    /** Reassemble segments unchanged. */
    public static function join(array $parts): string
    {
        $out = '';
        foreach ($parts as [, $text]) {
            $out .= $text;
        }

        return $out;
    }

    /**
     * Transform only code segments; comments and strings (including 'Screen Name') pass through.
     *
     * @param list<array{0:string,1:string}> $parts
     */
    public static function mapCode(array $parts, callable $callback): string
    {
        $out = '';
        foreach ($parts as [$type, $text]) {
            $out .= $type === 'code' ? $callback($text) : $text;
        }

        return $out;
    }
}
