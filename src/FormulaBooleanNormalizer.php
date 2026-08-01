<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Normalize boolean property formulas flagged by App checker.
 */
final class FormulaBooleanNormalizer
{
    /**
     * @return null|string normalized formula, or null if unchanged / not applicable
     */
    public static function tryNormalize(string $value, string $property): ?string
    {
        $propLower = strtolower($property);
        $boolProps = ['checked', 'default', 'value', 'reset', 'visible', 'wrap', 'autoheight', 'autowidth'];
        if (!in_array($propLower, $boolProps, true)) {
            return null;
        }

        $yamlEquals = str_starts_with(ltrim($value), '=');
        $body = trim(ltrim(trim($value), '='));
        $lower = strtolower($body);

        $map = [
            '1' => 'true',
            '0' => 'false',
            '"true"' => 'true',
            '"false"' => 'false',
            "'true'" => 'true',
            "'false'" => 'false',
        ];

        if (array_key_exists($lower, $map)) {
            $bool = $map[$lower];

            return $yamlEquals ? '=' . $bool : $bool;
        }

        if ($lower === 'true' || $lower === 'false') {
            return null;
        }

        $normalized = self::rewriteIfNumericBool($body);
        if ($normalized === null || $normalized === $body) {
            return null;
        }

        return $yamlEquals ? '=' . $normalized : $normalized;
    }

    private static function rewriteIfNumericBool(string $body): ?string
    {
        $body = trim($body);
        if (!preg_match('/^If\s*\(/i', $body) || !str_ends_with($body, ')')) {
            return null;
        }

        $inner = substr($body, (int) strpos($body, '(') + 1, -1);
        $args = self::splitTopLevelArgs($inner);
        if (count($args) !== 3) {
            return null;
        }

        $a = strtolower(trim($args[1]));
        $b = strtolower(trim($args[2]));
        if (!(($a === '1' && $b === '0') || ($a === '0' && $b === '1'))) {
            return null;
        }

        $trueBranch = $a === '1' ? 'true' : 'false';
        $falseBranch = $b === '1' ? 'true' : 'false';

        return 'If(' . trim($args[0]) . ', ' . $trueBranch . ', ' . $falseBranch . ')';
    }

    /**
     * @return list<string>
     */
    private static function splitTopLevelArgs(string $inner): array
    {
        $args = [];
        $buf = '';
        $depth = 0;
        $inString = false;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($ch === '"' && ($i === 0 || $inner[$i - 1] !== '\\')) {
                $inString = !$inString;
                $buf .= $ch;
                continue;
            }
            if (!$inString) {
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $args[] = $buf;
                    $buf = '';
                    continue;
                }
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $args[] = $buf;
        }

        return $args;
    }
}
