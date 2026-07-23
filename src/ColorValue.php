<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Parse / map Power Apps color literals for dark-mode rewrites.
 */
final class ColorValue
{
    /**
     * @return null|array{r:int,g:int,b:int,a:float,raw:string}
     */
    public static function parse(string $formula): ?array
    {
        $v = trim($formula);
        $v = ltrim($v, '=');
        $v = trim($v);

        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', $v, $m)) {
            return [
                'r' => (int) $m[1],
                'g' => (int) $m[2],
                'b' => (int) $m[3],
                'a' => isset($m[4]) && $m[4] !== '' ? (float) $m[4] : 1.0,
                'raw' => $v,
            ];
        }

        $lower = strtolower($v);
        $named = [
            'color.white' => [255, 255, 255, 1.0],
            'white' => [255, 255, 255, 1.0],
            'color.black' => [0, 0, 0, 1.0],
            'black' => [0, 0, 0, 1.0],
            'color.transparent' => [0, 0, 0, 0.0],
            'transparent' => [0, 0, 0, 0.0],
        ];
        if (isset($named[$lower])) {
            [$r, $g, $b, $a] = $named[$lower];
            return ['r' => $r, 'g' => $g, 'b' => $b, 'a' => $a, 'raw' => $v];
        }

        if (preg_match('/^colorvalue\(\s*"#([0-9a-f]{6})"\s*\)$/i', $v, $m)) {
            $hex = $m[1];
            return [
                'r' => hexdec(substr($hex, 0, 2)),
                'g' => hexdec(substr($hex, 2, 2)),
                'b' => hexdec(substr($hex, 4, 2)),
                'a' => 1.0,
                'raw' => $v,
            ];
        }

        return null;
    }

    public static function isLiteral(string $formula): bool
    {
        return self::parse($formula) !== null;
    }

    public static function isTransparent(array $c): bool
    {
        return $c['a'] <= 0.001;
    }

    /** Relative luminance 0..1 (sRGB). */
    public static function luminance(array $c): float
    {
        $lin = static function (int $channel): float {
            $s = $channel / 255.0;
            return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $lin($c['r']) + 0.7152 * $lin($c['g']) + 0.0722 * $lin($c['b']);
    }

    /**
     * Map a literal color to a dark-mode counterpart for the given property role.
     *
     * @param array{r:int,g:int,b:int,a:float,raw:string} $c
     * @return array{r:int,g:int,b:int,a:float}
     */
    public static function toDark(array $c, string $role): array
    {
        if (self::isTransparent($c)) {
            return ['r' => $c['r'], 'g' => $c['g'], 'b' => $c['b'], 'a' => $c['a']];
        }

        $lum = self::luminance($c);
        $sat = self::saturation($c);

        // Keep vivid accents; nudge for contrast on dark surfaces
        if ($sat > 0.35 && $lum > 0.15 && $lum < 0.9) {
            if ($role === 'foreground' && $lum < 0.45) {
                return self::mix($c, ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0], 0.35);
            }
            if ($role === 'background' && $lum > 0.75) {
                return self::mix($c, ['r' => 30, 'g' => 30, 'b' => 30, 'a' => 1.0], 0.55);
            }
            return ['r' => $c['r'], 'g' => $c['g'], 'b' => $c['b'], 'a' => $c['a']];
        }

        return match ($role) {
            'foreground' => $lum < 0.55
                ? ['r' => 242, 'g' => 242, 'b' => 242, 'a' => $c['a']]
                : ['r' => max(20, (int) round($c['r'] * 0.15)), 'g' => max(20, (int) round($c['g'] * 0.15)), 'b' => max(20, (int) round($c['b'] * 0.15)), 'a' => $c['a']],
            'border' => $lum > 0.5
                ? ['r' => 80, 'g' => 80, 'b' => 80, 'a' => max($c['a'], 0.7)]
                : ['r' => 160, 'g' => 160, 'b' => 160, 'a' => max($c['a'], 0.7)],
            default => $lum > 0.85
                ? ['r' => 18, 'g' => 18, 'b' => 18, 'a' => $c['a']]          // page / white
                : ($lum > 0.65
                    ? ['r' => 30, 'g' => 30, 'b' => 30, 'a' => $c['a']]       // light gray surface
                    : ($lum > 0.4
                        ? ['r' => 45, 'g' => 45, 'b' => 45, 'a' => $c['a']]   // mid surface
                        : ['r' => $c['r'], 'g' => $c['g'], 'b' => $c['b'], 'a' => $c['a']])),
        };
    }

    /**
     * @param array{r:int,g:int,b:int,a:float} $c
     */
    public static function formatRgba(array $c, bool $yamlEquals): string
    {
        $a = $c['a'];
        $aStr = abs($a - round($a)) < 0.0001 ? (string) (int) round($a) : rtrim(rtrim(sprintf('%.3F', $a), '0'), '.');
        $body = sprintf('RGBA(%d, %d, %d, %s)', $c['r'], $c['g'], $c['b'], $aStr);
        return $yamlEquals ? '=' . $body : $body;
    }

    public static function roleForProperty(string $property): string
    {
        $p = strtolower($property);
        if (str_contains($p, 'border')) {
            return 'border';
        }
        // Color, HoverColor, PressedColor, DisabledColor, IconColor, …
        if ($p === 'color' || (str_ends_with($p, 'color') && !str_contains($p, 'fill'))) {
            return 'foreground';
        }
        return 'background';
    }

    private static function saturation(array $c): float
    {
        $max = max($c['r'], $c['g'], $c['b']) / 255.0;
        $min = min($c['r'], $c['g'], $c['b']) / 255.0;
        if ($max < 0.0001) {
            return 0.0;
        }
        return ($max - $min) / $max;
    }

    /**
     * @param array{r:int,g:int,b:int,a:float} $a
     * @param array{r:int,g:int,b:int,a:float} $b
     * @return array{r:int,g:int,b:int,a:float}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        return [
            'r' => (int) round($a['r'] * (1 - $t) + $b['r'] * $t),
            'g' => (int) round($a['g'] * (1 - $t) + $b['g'] * $t),
            'b' => (int) round($a['b'] * (1 - $t) + $b['b'] * $t),
            'a' => $a['a'] * (1 - $t) + $b['a'] * $t,
        ];
    }
}
