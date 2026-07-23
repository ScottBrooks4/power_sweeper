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

        $parts = self::splitProtected($formula);
        $out = '';
        foreach ($parts as [$type, $text]) {
            if ($type !== 'code') {
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

    private static function replaceIdentifier(string $code, string $old, string $new): string
    {
        if (str_contains($old, ' ') || !preg_match('/^[A-Za-z_][\w]*$/', $old)) {
            // Quoted Power Fx name: 'My List'
            $quotedOld = "'" . str_replace("'", "''", $old) . "'";
            $quotedNew = "'" . str_replace("'", "''", $new) . "'";
            $code = str_replace($quotedOld, $quotedNew, $code);
            return $code;
        }

        // Unquoted identifier / property access
        $pattern = '/(?<![\w])' . preg_quote($old, '/') . '(?![\w])/';
        return preg_replace($pattern, $new, $code) ?? $code;
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
