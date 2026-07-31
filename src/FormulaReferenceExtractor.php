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
        $parts = self::splitProtected($formula);
        $seen = [];
        $out = [];
        foreach ($parts as [$type, $text]) {
            if ($type !== 'code') {
                continue;
            }
            if (preg_match_all('/(?<![\w])([A-Za-z_][\w]*)/', $text, $m)) {
                foreach ($m[1] as $id) {
                    if (!isset($seen[$id])) {
                        $seen[$id] = true;
                        $out[] = $id;
                    }
                }
            }
            // Quoted control/datasource names: 'PertinenceSpecification-'
            if (preg_match_all("/'((?:[^']|'')+)'/", $text, $qm)) {
                foreach ($qm[1] as $q) {
                    $name = str_replace("''", "'", $q);
                    if (!isset($seen[$name])) {
                        $seen[$name] = true;
                        $out[] = $name;
                    }
                }
            }
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
