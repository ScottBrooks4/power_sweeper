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
            'color.azure' => [240, 255, 255, 1.0],
            'color.skyblue' => [135, 206, 235, 1.0],
            'color.aliceblue' => [240, 248, 255, 1.0],
            'color.darkcyan' => [0, 139, 139, 1.0],
            'color.aquamarine' => [127, 255, 212, 1.0],
            'color.dodgerblue' => [30, 144, 255, 1.0],
            'color.cornflowerblue' => [100, 149, 237, 1.0],
            'color.steelblue' => [70, 130, 180, 1.0],
            'color.royalblue' => [65, 105, 225, 1.0],
            'color.mediumblue' => [0, 0, 205, 1.0],
            'color.navy' => [0, 0, 128, 1.0],
            'color.midnightblue' => [25, 25, 112, 1.0],
            'color.teal' => [0, 128, 128, 1.0],
            'color.turquoise' => [64, 224, 208, 1.0],
            'color.cadetblue' => [95, 158, 160, 1.0],
            'color.indigo' => [75, 0, 130, 1.0],
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

        // PAT/PACS formulas often keep prior experiments as // comments after the live value.
        $stripped = preg_replace('/\/\/[^\n]*/', '', $formula) ?? $formula;
        $fixed = FormulaLocaleNormalizer::toInvariant($stripped);
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
        // Modern button/input brand color — background chrome, not text ink.
        if ($p === 'basepalettecolor' || str_contains($p, 'palette')) {
            return 'background';
        }
        if ($p === 'color' || (str_ends_with($p, 'color') && !str_contains($p, 'fill'))) {
            return 'foreground';
        }
        // ChevronFill / IconFill are the glyph, not the well behind it.
        if (preg_match('/^(chevronfill|chevronhoverfill|iconfill|iconcolor)$/i', $p) === 1
            || (str_contains($p, 'chevron') && str_contains($p, 'fill') && !str_contains($p, 'background'))
        ) {
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

        if (str_contains($p, 'disabled')) {
            return $role === 'foreground' ? 'TextMuted' : 'Disabled';
        }
        if (str_contains($p, 'handle') || str_contains($p, 'rail') || str_contains($p, 'track') || str_contains($p, 'falsefill') || str_contains($p, 'inactive') || str_contains($p, 'barcolor')) {
            return str_contains($p, 'handle') ? 'Handle' : 'Rail';
        }
        if (str_contains($p, 'selection') || str_contains($p, 'selected') || str_contains($p, 'hoveritem') || str_contains($p, 'presseditem')) {
            return str_contains($p, 'hover') || str_contains($p, 'pressed') ? 'SurfaceMuted' : 'Selection';
        }
        // Translucent washes invert in place (keep hue/grey + alpha). Collapsing
        // RGBA(240,240,240,0.4) onto opaque Page/SurfaceMuted restyles the sidebar.
        $alpha = $c['a'] ?? 1.0;
        if ($alpha < 0.999 && $alpha > 0.02) {
            return self::uniqueColorToken($c);
        }

        // Tinted caption greys (106,122,127 / #6b6760) are ink, not brand paint.
        // Leave warmer taupe fills (189,178,176) as unique colors — those are chrome.
        if ($role === 'foreground') {
            $chroma = max($c['r'], $c['g'], $c['b']) - min($c['r'], $c['g'], $c['b']);
            $sat = self::saturation($c);
            if ($chroma <= 26 && $sat <= 0.22 && $lum >= 0.08 && $lum <= 0.80) {
                return $lum > 0.55 ? 'TextOnAccent' : 'TextMuted';
            }
        }

        // Hues stay hues: each author color gets its own token so Exit taupe
        // is not Danger and a cream banner is not Surface grey.
        if (!self::isShade($c)) {
            return self::uniqueColorToken($c);
        }

        if ($role === 'foreground') {
            if (str_contains($p, 'disabled')) {
                return 'TextMuted';
            }
            // Near-white (typed inverse / on-accent) vs grey captions (LightGray ≈ 0.66).
            if ($lum > 0.88) {
                return 'TextOnAccent';
            }
            // sRGB-linear: 117,117,117 ≈ 0.18 (secondary captions); 51,51,51 ≈ 0.03 (body).
            if ($lum > 0.08) {
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
        if ($lum > 0.88) {
            return 'Page';
        }
        // Light grey chips (completed steps ~227) stay SurfaceMuted, not white Surface.
        if ($lum > 0.65) {
            return 'SurfaceMuted';
        }
        // Slate selected chrome (79,90,94 ≈ 0.10) stays a dark shade — not inverse white.
        if ($lum > 0.06) {
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

        return self::flipSpectrum($c, $role);
    }

    /**
     * True neutrals (white / grey / black). Warm taupe and cream are colors.
     *
     * @param array{r:int,g:int,b:int,a?:float} $c
     */
    public static function isShade(array $c): bool
    {
        $chroma = max($c['r'], $c['g'], $c['b']) - min($c['r'], $c['g'], $c['b']);
        $lum = self::luminance($c);
        $max = max($c['r'], $c['g'], $c['b']);
        // Near-black body ink (15,23,42) and near-white paper are shades even with a tint.
        if ($lum < 0.08 && $max <= 50) {
            return true;
        }
        if ($lum > 0.90 && $chroma <= 20) {
            return true;
        }

        return $chroma <= 10 && self::saturation($c) <= 0.06;
    }

    /** Author-color token such as C189_178_176. */
    public static function isUniqueColorToken(string $token): bool
    {
        return preg_match('/^C\d+_\d+_\d+(?:_A\d+)?$/', $token) === 1;
    }

    /**
     * @param array{r:int,g:int,b:int,a?:float} $c
     */
    public static function uniqueColorToken(array $c): string
    {
        $token = sprintf('C%d_%d_%d', $c['r'], $c['g'], $c['b']);
        $a = $c['a'] ?? 1.0;
        if ($a < 0.999) {
            $token .= '_A' . (int) round($a * 100);
        }

        return $token;
    }

    /**
     * Same hue in dark mode. Greys invert. Bright brand fills and translucent
     * washes keep a visible tint — they do not flip into neon or disappear into the page.
     *
     * @param array{r:int,g:int,b:int,a:float} $c
     * @return array{r:int,g:int,b:int,a:float}
     */
    public static function flipSpectrum(array $c, string $role = 'background'): array
    {
        if (self::isTransparent($c)) {
            return ['r' => $c['r'], 'g' => $c['g'], 'b' => $c['b'], 'a' => $c['a']];
        }
        if (self::isShade($c)) {
            return [
                'r' => 255 - $c['r'],
                'g' => 255 - $c['g'],
                'b' => 255 - $c['b'],
                'a' => $c['a'],
            ];
        }
        $hsl = self::toHsl($c);
        $alpha = $c['a'];
        $h = $hsl['h'];
        $s = $hsl['s'];
        $l = $hsl['l'];
        // HSL saturation inflates pale greys (a #E2E8F0 hairline reads as s≈0.3).
        // Chroma/max keeps those on the invert path and only tints real hues.
        $chromaSat = self::saturation($c);

        // Peach/amber/status washes (RGBA orange at 0.18) composite to a tint on
        // white and to nothing on a dark page. Paint an opaque dark tint of that hue.
        if ($alpha < 0.85 && $chromaSat >= 0.2 && $role !== 'foreground') {
            return self::withRelativeLuminance($h, max($s, 0.55), 0.16, 1.0);
        }

        if ($role === 'foreground') {
            $target = $l >= 0.62 ? min(0.78, $l) : 0.62;

            return self::withRelativeLuminance($h, min(1.0, $s), $target, $alpha);
        }

        if ($role === 'border' && $chromaSat >= 0.2) {
            // Saturated edge tint: visible on a dark surface, not a second fill.
            return self::withRelativeLuminance($h, min(1.0, $s), 0.42, $alpha);
        }

        // Neon seeds (RGBA(8, 222, 8)) glow if lightness is flipped upward.
        // Park them where white label text still clears ~4.5:1.
        if ($chromaSat >= 0.28 && $l >= 0.38) {
            return self::withRelativeLuminance($h, $s, 0.14, $alpha);
        }

        return self::fromHsl($h, $s, 1.0 - $l, $alpha);
    }

    /**
     * Dark-mode face for a secondary button: the palette hue, softened so it
     * stays a tint instead of a solid CTA.
     *
     * @param array{r:int,g:int,b:int,a?:float} $c
     * @return array{r:int,g:int,b:int,a:float}
     */
    public static function darkTint(array $c): array
    {
        // Mix the pigment into the dark surface. A full-strength shade reads as a
        // second CTA (Quitter turning solid red); a 40% mix stays a tinted chip.
        $base = ['r' => 32, 'g' => 32, 'b' => 36];
        $t = 0.42;

        return [
            'r' => (int) round($base['r'] * (1 - $t) + $c['r'] * $t),
            'g' => (int) round($base['g'] * (1 - $t) + $c['g'] * $t),
            'b' => (int) round($base['b'] * (1 - $t) + $c['b'] * $t),
            'a' => 1.0,
        ];
    }

    /**
     * @return array{r:int,g:int,b:int,a:float}
     */
    private static function withRelativeLuminance(float $h, float $s, float $targetLum, float $a): array
    {
        $targetLum = max(0.02, min(0.85, $targetLum));
        $lo = 0.02;
        $hi = 0.92;
        $best = self::fromHsl($h, $s, 0.3, $a);
        for ($i = 0; $i < 18; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $best = self::fromHsl($h, $s, $mid, $a);
            if (self::luminance($best) > $targetLum) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        return $best;
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
            'TextMuted' => ['r' => 212, 'g' => 212, 'b' => 220, 'a' => 1.0],
            'TextOnAccent' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
            'TextOnLight' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
            'InputFill' => ['r' => 44, 'g' => 44, 'b' => 50, 'a' => 1.0],
            'Border' => ['r' => 56, 'g' => 56, 'b' => 64, 'a' => 1.0],
            'BorderStrong' => ['r' => 82, 'g' => 82, 'b' => 90, 'a' => 1.0],
            'Focus' => ['r' => 96, 'g' => 165, 'b' => 250, 'a' => 1.0],
            // Lifted from InputFill (44) so disabled fields stay visibly greyed-out.
            'Disabled' => ['r' => 72, 'g' => 72, 'b' => 78, 'a' => 1.0],
            // Lifted slate so the current stepper step stays distinct from completed chips.
            'Selected' => ['r' => 110, 'g' => 118, 'b' => 124, 'a' => 1.0],
            'Handle' => ['r' => 240, 'g' => 240, 'b' => 240, 'a' => 1.0],
            'Rail' => ['r' => 70, 'g' => 70, 'b' => 70, 'a' => 1.0],
            'Selection' => ['r' => 30, 'g' => 64, 'b' => 175, 'a' => 1.0],
            'Link' => ['r' => 45, 'g' => 212, 'b' => 191, 'a' => 1.0],
            'LinkHover' => ['r' => 94, 'g' => 234, 'b' => 212, 'a' => 1.0],
            'Accent' => ['r' => 30, 'g' => 64, 'b' => 175, 'a' => 1.0],
            'AccentHover' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
            'AccentPressed' => ['r' => 30, 'g' => 58, 'b' => 138, 'a' => 1.0],
            'Danger' => ['r' => 136, 'g' => 32, 'b' => 48, 'a' => 1.0],
            'DangerHover' => ['r' => 153, 'g' => 38, 'b' => 56, 'a' => 1.0],
            'DangerPressed' => ['r' => 127, 'g' => 29, 'b' => 29, 'a' => 1.0],
            'DangerText' => ['r' => 254, 'g' => 202, 'b' => 202, 'a' => 1.0],
            'Warning' => ['r' => 161, 'g' => 98, 'b' => 7, 'a' => 1.0],
            'WarningHover' => ['r' => 180, 'g' => 120, 'b' => 20, 'a' => 1.0],
            'WarningPressed' => ['r' => 146, 'g' => 84, 'b' => 6, 'a' => 1.0],
            // Dark ink on warning surfaces (not pale yellow-on-yellow).
            'WarningText' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
            'Success' => ['r' => 20, 'g' => 83, 'b' => 45, 'a' => 1.0],
            'SuccessHover' => ['r' => 22, 'g' => 101, 'b' => 52, 'a' => 1.0],
            'SuccessPressed' => ['r' => 16, 'g' => 64, 'b' => 36, 'a' => 1.0],
            'SuccessText' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
            default => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
        };
    }

    /** Whether defaultDarkForToken has a curated value (not the Accent fallback). */
    public static function hasNamedDarkDefault(string $token): bool
    {
        return in_array($token, [
            'Page', 'Surface', 'SurfaceMuted', 'SurfaceAlt', 'SurfaceInverse', 'InputFill',
            'Text', 'TextMuted', 'TextOnAccent', 'TextOnLight', 'Border', 'BorderStrong', 'Focus',
            'Disabled', 'Selected', 'Handle', 'Rail', 'Selection', 'Accent', 'AccentHover', 'AccentPressed',
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
     * CSS hex for HtmlText link colors (#RRGGBB).
     *
     * @param array{r:int,g:int,b:int,a:float} $c
     */
    public static function toHex(array $c): string
    {
        return sprintf('#%02X%02X%02X', $c['r'], $c['g'], $c['b']);
    }

    /**
     * CSS color for web emission (rgba when alpha < 1).
     *
     * @param array{r:int,g:int,b:int,a:float} $c
     */
    public static function toCss(array $c): string
    {
        $a = $c['a'] ?? 1.0;
        if ($a < 0.999) {
            $aStr = rtrim(rtrim(sprintf('%.3F', $a), '0'), '.');

            return sprintf('rgba(%d, %d, %d, %s)', $c['r'], $c['g'], $c['b'], $aStr);
        }

        return self::toHex($c);
    }

    /**
     * Whether a literal color/fill looks like an untouched Studio default (safe to rewrite).
     * Non-literal formulas (theme refs, expressions) return false — treat as user-authored.
     */
    public static function isStudioDefault(string $formula, string $property = 'Fill'): bool
    {
        $trim = trim($formula);
        if ($trim === '') {
            return true;
        }

        $body = strtolower(ltrim($trim, '='));
        $bodyCompact = preg_replace('/\s+/', '', $body) ?? $body;
        if ($bodyCompact === 'defaultgraybackgroud'
            || preg_match('/^app\\.theme\\.colors\\.[a-z0-9]+$/', $bodyCompact) === 1
            || preg_match('/^color\\.(white|transparent|lightgray|lightgrey|darkgray|darkgrey|gray|grey|black)$/', $bodyCompact) === 1) {
            return true;
        }

        $parsed = self::parse($formula);
        if ($parsed === null) {
            return false;
        }
        if (self::isTransparent($parsed)) {
            return true;
        }

        $role = self::roleForProperty($property);
        $lum = self::luminance($parsed);
        $sat = self::saturation($parsed);

        if ($role === 'background' || str_contains(strtolower($property), 'fill')) {
            if ($lum >= 0.92) {
                return true;
            }
            // Near-white Studio / Fluent defaults (incl. slate-100-ish chrome).
            if ($parsed['r'] >= 220 && $parsed['g'] >= 220 && $parsed['b'] >= 220 && $parsed['a'] >= 0.85) {
                return true;
            }
            // Light-to-mid grey field chrome (RGBA(200,200,200) ≈ 0.57) — not just near-white.
            if (self::isNeutralSurface($parsed)) {
                return true;
            }
            // Pale accent chrome on interactive chrome props (SelectedFill, HoverFill, …).
            if (self::isChromeColorProperty($property) && $lum >= 0.80 && $sat < 0.45 && $parsed['a'] >= 0.85) {
                return true;
            }

            return false;
        }

        if ($role === 'foreground') {
            if ($lum <= 0.12) {
                return true;
            }
            if (self::isNeutralInk($parsed)) {
                return true;
            }

            return false;
        }

        if ($role === 'border') {
            if ($lum <= 0.08) {
                return true;
            }
            if ($sat < 0.18 && $lum >= 0.45) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * Grey body/caption ink that must flip with the theme (incl. Color.LightGray ≈ 0.66).
     *
     * @param array{r:int,g:int,b:int,a?:float} $c
     */
    public static function isNeutralInk(array $c): bool
    {
        if (self::isTransparent($c)) {
            return false;
        }
        $sat = self::saturation($c);
        $lum = self::luminance($c);

        return $sat <= 0.28 && $lum >= 0.04 && $lum <= 0.88;
    }

    /**
     * Light/mid grey fills used as input and card chrome (not brand paint).
     *
     * @param array{r:int,g:int,b:int,a?:float} $c
     */
    public static function isNeutralSurface(array $c): bool
    {
        if (self::isTransparent($c)) {
            return false;
        }
        $sat = self::saturation($c);
        $lum = self::luminance($c);

        return self::isShade($c) && $lum >= 0.40 && ($c['a'] ?? 1.0) >= 0.85;
    }

    /**
     * Gallery/slider/input chrome properties that Studio seeds with pale slate/blue
     * literals — treat as themeable defaults when force is off.
     */
    public static function isChromeColorProperty(string $property): bool
    {
        $p = strtolower($property);

        return (bool) preg_match(
            '/^(rail|handle|selected|selection|hover|pressed|item|template|inactive|disabled|chevron|iconbackground|falsefill|truefill|indicator|progress|valuefill|active|barcolor)/',
            $p
        ) || str_contains($p, 'itemfill')
            || str_contains($p, 'itemborder')
            || str_contains($p, 'hoveritem')
            || str_contains($p, 'presseditem');
    }

    /**
     * @param array{r:int,g:int,b:int,a?:float} $c
     * @return array{h:float,s:float,l:float}
     */
    public static function toHsl(array $c): array
    {
        $r = $c['r'] / 255.0;
        $g = $c['g'] / 255.0;
        $b = $c['b'] / 255.0;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2.0;
        $delta = $max - $min;
        if ($delta < 0.0001) {
            return ['h' => 0.0, 's' => 0.0, 'l' => $l];
        }
        $s = $l > 0.5 ? $delta / (2.0 - $max - $min) : $delta / ($max + $min);
        $h = match (true) {
            $max === $r => 60 * fmod((($g - $b) / $delta), 6),
            $max === $g => 60 * ((($b - $r) / $delta) + 2),
            default => 60 * ((($r - $g) / $delta) + 4),
        };
        if ($h < 0) {
            $h += 360;
        }

        return ['h' => $h, 's' => $s, 'l' => $l];
    }

    /**
     * @return array{r:int,g:int,b:int,a:float}
     */
    public static function fromHsl(float $h, float $s, float $l, float $a = 1.0): array
    {
        $h = fmod(($h + 360.0), 360.0) / 360.0;
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));
        $hue2rgb = static function (float $p, float $q, float $t): float {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }

            return $p;
        };
        if ($s < 0.0001) {
            $v = (int) round($l * 255);

            return ['r' => $v, 'g' => $v, 'b' => $v, 'a' => $a];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        return [
            'r' => (int) max(0, min(255, round($hue2rgb($p, $q, $h + 1 / 3) * 255))),
            'g' => (int) max(0, min(255, round($hue2rgb($p, $q, $h) * 255))),
            'b' => (int) max(0, min(255, round($hue2rgb($p, $q, $h - 1 / 3) * 255))),
            'a' => $a,
        ];
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
