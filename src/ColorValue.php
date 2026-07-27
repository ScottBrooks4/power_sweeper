<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Parse / map Power Apps color literals for dark-mode theme tokens.
 */
final class ColorValue
{
    /**
     * @return null|array{r:int,g:int,b:int,a:float,raw:string}
     */
    public static function parse(string $formula): ?array
    {
        $formula = self::normalizeColorLiteral($formula);
        $v = trim($formula);
        $v = ltrim($v, '=');
        $v = trim($v);

        // Locale-broken alpha: RGBA(240, 240, 240, 0,2) → treated as 0.2
        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $v, $m)) {
            $alpha = (float) ($m[4] . '.' . $m[5]);
            return [
                'r' => (int) $m[1],
                'g' => (int) $m[2],
                'b' => (int) $m[3],
                'a' => $alpha,
                'raw' => $v,
            ];
        }

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
            'color.blue' => [0, 120, 212, 1.0],
            'color.red' => [232, 17, 35, 1.0],
            'color.green' => [16, 124, 16, 1.0],
            'color.orange' => [247, 99, 12, 1.0],
            'color.yellow' => [255, 185, 0, 1.0],
            'color.purple' => [136, 23, 152, 1.0],
            'color.gray' => [128, 128, 128, 1.0],
            'color.grey' => [128, 128, 128, 1.0],
            'color.lightgray' => [211, 211, 211, 1.0],
            'color.lightgrey' => [211, 211, 211, 1.0],
            'color.darkgray' => [169, 169, 169, 1.0],
            'color.darkgrey' => [169, 169, 169, 1.0],
            'color.lightgreen' => [144, 238, 144, 1.0],
            'color.lightblue' => [173, 216, 230, 1.0],
            'color.lightyellow' => [255, 255, 224, 1.0],
            'color.gold' => [255, 215, 0, 1.0],
            'color.brown' => [165, 42, 42, 1.0],
            'color.cyan' => [0, 255, 255, 1.0],
            'color.magenta' => [255, 0, 255, 1.0],
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

    /**
     * Repair locale-broken RGBA alpha (e.g. RGBA(240, 240, 240, 0,2) → 0.2) without locale conversion.
     */
    public static function fixLocaleBrokenAlpha(string $formula): string
    {
        if ($formula === '' || trim($formula) === '') {
            return $formula;
        }

        $hadEquals = str_starts_with(trim($formula), '=');
        $body = ltrim(trim($formula), '=');

        if (preg_match('/^RGBA?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $body, $m)) {
            $alpha = (float) ($m[4] . '.' . $m[5]);
            $aStr = abs($alpha - round($alpha)) < 0.0001 ? (string) (int) round($alpha) : rtrim(rtrim(sprintf('%.3F', $alpha), '0'), '.');
            $body = sprintf('RGBA(%d, %d, %d, %s)', (int) $m[1], (int) $m[2], (int) $m[3], $aStr);
            return ($hadEquals ? '=' : '') . $body;
        }

        return $formula;
    }

    /**
     * Repair locale-corrupted color literals before parse/theme (e.g. RGBA comma-decimal alpha).
     */
    public static function normalizeColorLiteral(string $formula): string
    {
        if ($formula === '' || trim($formula) === '') {
            return $formula;
        }

        $fixed = FormulaLocaleNormalizer::toInvariant($formula);
        return self::fixLocaleBrokenAlpha($fixed);
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

    /** Hue in degrees 0..360, or -1 for near-gray. */
    public static function hue(array $c): float
    {
        $r = $c['r'] / 255.0;
        $g = $c['g'] / 255.0;
        $b = $c['b'] / 255.0;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        if ($delta < 0.0001) {
            return -1.0;
        }
        $h = match (true) {
            $max === $r => 60 * fmod((($g - $b) / $delta), 6),
            $max === $g => 60 * ((($b - $r) / $delta) + 2),
            default => 60 * ((($r - $g) / $delta) + 4),
        };
        if ($h < 0) {
            $h += 360;
        }
        return $h;
    }

    public static function saturation(array $c): float
    {
        $max = max($c['r'], $c['g'], $c['b']) / 255.0;
        $min = min($c['r'], $c['g'], $c['b']) / 255.0;
        if ($max < 0.0001) {
            return 0.0;
        }
        return ($max - $min) / $max;
    }

    public static function roleForProperty(string $property): string
    {
        $p = strtolower($property);
        if (str_contains($p, 'border')) {
            return 'border';
        }
        if ($p === 'color' || (str_ends_with($p, 'color') && !str_contains($p, 'fill'))) {
            return 'foreground';
        }
        return 'background';
    }

    /**
     * Map a literal + property into a named theme token (editable in App.OnStart).
     *
     * @param array{r:int,g:int,b:int,a:float,raw:string} $c
     */
    public static function themeToken(array $c, string $property): string
    {
        $role = self::roleForProperty($property);
        $p = strtolower($property);
        $lum = self::luminance($c);
        $sat = self::saturation($c);
        $hue = self::hue($c);

        if ($sat > 0.35 && $hue >= 0) {
            $family = match (true) {
                $hue < 25 || $hue >= 345 => 'Danger',
                $hue >= 25 && $hue < 70 => 'Warning',
                $hue >= 70 && $hue < 165 => 'Success',
                default => 'Accent',
            };
            if ($role === 'foreground') {
                return $family === 'Accent' ? 'TextOnAccent' : ($family . 'Text');
            }
            if (str_contains($p, 'hover')) {
                return $family . 'Hover';
            }
            if (str_contains($p, 'pressed')) {
                return $family . 'Pressed';
            }
            if (str_contains($p, 'disabled')) {
                return 'Disabled';
            }
            if (str_contains($p, 'selection') || str_contains($p, 'selected') || str_contains($p, 'highlight') || str_contains($p, 'checkmark') || str_contains($p, 'truefill') || str_contains($p, 'indicator') || str_contains($p, 'progress') || str_contains($p, 'valuefill') || str_contains($p, 'active')) {
                return $family === 'Accent' ? (str_contains($p, 'selection') || str_contains($p, 'selected') ? 'Selection' : 'Accent') : $family;
            }
            return $family;
        }

        if ($role === 'foreground') {
            if (str_contains($p, 'disabled')) {
                return 'TextMuted';
            }
            if ($lum > 0.8) {
                return 'TextOnAccent';
            }
            if ($lum > 0.4) {
                return 'TextMuted';
            }
            return 'Text';
        }

        if ($role === 'border') {
            if (str_contains($p, 'focus')) {
                return 'Focus';
            }
            return $lum > 0.45 ? 'Border' : 'BorderStrong';
        }

        // Backgrounds / fills
        if (str_contains($p, 'disabled')) {
            return 'Disabled';
        }
        if (str_contains($p, 'handle') || str_contains($p, 'rail') || str_contains($p, 'track') || str_contains($p, 'falsefill') || str_contains($p, 'inactive') || str_contains($p, 'barcolor')) {
            if (str_contains($p, 'handle')) {
                return 'Handle';
            }
            return 'Rail';
        }
        if (str_contains($p, 'chevron') || str_contains($p, 'iconbackground')) {
            return 'SurfaceMuted';
        }
        if (str_contains($p, 'selection') || str_contains($p, 'selected') || str_contains($p, 'hoveritem') || str_contains($p, 'presseditem')) {
            return str_contains($p, 'hover') || str_contains($p, 'pressed') ? 'SurfaceMuted' : 'Selection';
        }
        if ($lum > 0.93) {
            return 'Page';
        }
        if ($lum > 0.85) {
            return 'Surface';
        }
        if ($lum > 0.65) {
            return 'SurfaceMuted';
        }
        if ($lum > 0.35) {
            return 'SurfaceAlt';
        }
        return 'SurfaceInverse';
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
                ? ['r' => 18, 'g' => 18, 'b' => 18, 'a' => $c['a']]
                : ($lum > 0.65
                    ? ['r' => 30, 'g' => 30, 'b' => 30, 'a' => $c['a']]
                    : ($lum > 0.4
                        ? ['r' => 45, 'g' => 45, 'b' => 45, 'a' => $c['a']]
                        : ['r' => $c['r'], 'g' => $c['g'], 'b' => $c['b'], 'a' => $c['a']])),
        };
    }

    /**
     * Sensible dark defaults when a token was only seen in light form.
     *
     * @return array{r:int,g:int,b:int,a:float}
     */
    public static function defaultDarkForToken(string $token): array
    {
        return match ($token) {
            'Page' => ['r' => 18, 'g' => 18, 'b' => 18, 'a' => 1.0],
            'Surface' => ['r' => 30, 'g' => 30, 'b' => 30, 'a' => 1.0],
            'SurfaceMuted' => ['r' => 45, 'g' => 45, 'b' => 45, 'a' => 1.0],
            'SurfaceAlt' => ['r' => 55, 'g' => 55, 'b' => 55, 'a' => 1.0],
            'SurfaceInverse' => ['r' => 241, 'g' => 245, 'b' => 249, 'a' => 1.0],
            'Text' => ['r' => 242, 'g' => 242, 'b' => 242, 'a' => 1.0],
            'TextMuted' => ['r' => 160, 'g' => 160, 'b' => 160, 'a' => 1.0],
            'TextOnAccent' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
            'Border' => ['r' => 80, 'g' => 80, 'b' => 80, 'a' => 1.0],
            'BorderStrong' => ['r' => 120, 'g' => 120, 'b' => 120, 'a' => 1.0],
            'Focus' => ['r' => 96, 'g' => 165, 'b' => 250, 'a' => 1.0],
            'Disabled' => ['r' => 60, 'g' => 60, 'b' => 60, 'a' => 1.0],
            'Handle' => ['r' => 240, 'g' => 240, 'b' => 240, 'a' => 1.0],
            'Rail' => ['r' => 70, 'g' => 70, 'b' => 70, 'a' => 1.0],
            'Selection' => ['r' => 30, 'g' => 64, 'b' => 175, 'a' => 1.0],
            'Accent' => ['r' => 96, 'g' => 165, 'b' => 250, 'a' => 1.0],
            'AccentHover' => ['r' => 59, 'g' => 130, 'b' => 246, 'a' => 1.0],
            'AccentPressed' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
            'Danger' => ['r' => 248, 'g' => 113, 'b' => 113, 'a' => 1.0],
            'DangerHover' => ['r' => 239, 'g' => 68, 'b' => 68, 'a' => 1.0],
            'DangerPressed' => ['r' => 220, 'g' => 38, 'b' => 38, 'a' => 1.0],
            'DangerText' => ['r' => 254, 'g' => 202, 'b' => 202, 'a' => 1.0],
            'Warning' => ['r' => 251, 'g' => 191, 'b' => 36, 'a' => 1.0],
            'WarningHover' => ['r' => 245, 'g' => 158, 'b' => 11, 'a' => 1.0],
            'WarningPressed' => ['r' => 217, 'g' => 119, 'b' => 6, 'a' => 1.0],
            'WarningText' => ['r' => 254, 'g' => 243, 'b' => 199, 'a' => 1.0],
            'Success' => ['r' => 74, 'g' => 222, 'b' => 128, 'a' => 1.0],
            'SuccessHover' => ['r' => 34, 'g' => 197, 'b' => 94, 'a' => 1.0],
            'SuccessPressed' => ['r' => 22, 'g' => 163, 'b' => 74, 'a' => 1.0],
            'SuccessText' => ['r' => 187, 'g' => 247, 'b' => 208, 'a' => 1.0],
            default => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
        };
    }

    /** Whether defaultDarkForToken has a curated value (not the Accent fallback). */
    public static function hasNamedDarkDefault(string $token): bool
    {
        return in_array($token, [
            'Page', 'Surface', 'SurfaceMuted', 'SurfaceAlt', 'SurfaceInverse',
            'Text', 'TextMuted', 'TextOnAccent', 'Border', 'BorderStrong', 'Focus',
            'Disabled', 'Handle', 'Rail', 'Selection', 'Accent', 'AccentHover', 'AccentPressed',
            'Danger', 'DangerHover', 'DangerPressed', 'DangerText',
            'Warning', 'WarningHover', 'WarningPressed', 'WarningText',
            'Success', 'SuccessHover', 'SuccessPressed', 'SuccessText',
        ], true);
    }

    /**
     * @param array{r:int,g:int,b:int,a:float} $c
     */
    public static function formatRgba(array $c, bool $yamlEquals = false): string
    {
        $a = $c['a'];
        $aStr = abs($a - round($a)) < 0.0001 ? (string) (int) round($a) : rtrim(rtrim(sprintf('%.3F', $a), '0'), '.');
        $body = sprintf('RGBA(%d, %d, %d, %s)', $c['r'], $c['g'], $c['b'], $aStr);
        return $yamlEquals ? '=' . $body : $body;
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
