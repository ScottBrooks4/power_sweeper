<?php

declare(strict_types=1);

/**
 * Editable default theme tokens for the enable_dark_mode sweep.
 *
 * Makers edit the generated App.Formulas palettes (gblThemeLight / gblThemeDark).
 * Operators can change these defaults (or override via sweep options) without
 * touching EnableDarkModeSweep — colors stay centralized here.
 *
 * Chrome tokens (Page, Surface, Text, Border, …) always win over first-seen literals.
 * Author hues become per-color tokens (C189_178_176) whose dark side is the opposite
 * point on the same spectrum. Shades invert; colors stay colors. We do not restyle
 * buttons to "intuitive" greens/reds.
 *
 * Each token: ['light' => [r,g,b,a], 'dark' => [r,g,b,a]]
 * Omit 'dark' to let ColorValue::defaultDarkForToken() supply a contrast-safe dark.
 *
 * @return array<string, array{light: array{r:int,g:int,b:int,a:float}, dark?: array{r:int,g:int,b:int,a:float}}>
 */
return [
    'Page' => [
        'light' => ['r' => 250, 'g' => 250, 'b' => 252, 'a' => 1.0],
        'dark' => ['r' => 12, 'g' => 12, 'b' => 14, 'a' => 1.0],
    ],
    'Surface' => [
        'light' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
        'dark' => ['r' => 28, 'g' => 28, 'b' => 30, 'a' => 1.0],
    ],
    'InputFill' => [
        'light' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
        // Slightly lifted from Surface so field chrome reads as inputs, not page voids.
        'dark' => ['r' => 44, 'g' => 44, 'b' => 50, 'a' => 1.0],
    ],
    'SurfaceMuted' => [
        'light' => ['r' => 241, 'g' => 245, 'b' => 249, 'a' => 1.0],
        'dark' => ['r' => 52, 'g' => 52, 'b' => 58, 'a' => 1.0],
    ],
    'SurfaceAlt' => [
        'light' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
        'dark' => ['r' => 55, 'g' => 55, 'b' => 55, 'a' => 1.0],
    ],
    'Text' => [
        'light' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
        'dark' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
    ],
    'TextMuted' => [
        'light' => ['r' => 100, 'g' => 116, 'b' => 139, 'a' => 1.0],
        // ≥4.5:1 on Page/InputFill for placeholders and secondary labels.
        'dark' => ['r' => 212, 'g' => 212, 'b' => 220, 'a' => 1.0],
    ],
    // White label on Accent / Success / Warning / Danger fills in both themes.
    // First-seen dark-grey button Color must not become the dark palette (illegible CTAs).
    'TextOnAccent' => [
        'light' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
        'dark' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
    ],
    // Dark ink for text that sits on pastel/light chips (ColorFade status pills, etc.).
    // Same in both themes — those surfaces stay light even when the app is dark.
    'TextOnLight' => [
        'light' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
        'dark' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
    ],
    'Border' => [
        'light' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
        // Near-surface hairline — light borders barely show on white; a mid-grey
        // dark token (110+) reads as a heavy outline on Page/Surface.
        'dark' => ['r' => 56, 'g' => 56, 'b' => 64, 'a' => 1.0],
    ],
    'BorderStrong' => [
        'light' => ['r' => 148, 'g' => 163, 'b' => 184, 'a' => 1.0],
        'dark' => ['r' => 82, 'g' => 82, 'b' => 90, 'a' => 1.0],
    ],
    'Accent' => [
        'light' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
        // Filled CTAs — not sky-blue; white label stays ≥4.5:1.
        'dark' => ['r' => 30, 'g' => 64, 'b' => 175, 'a' => 1.0],
    ],
    // Hyperlinks only — blue in light mode, accessible teal in dark (≥4.5:1 on Page).
    'Link' => [
        'light' => ['r' => 29, 'g' => 78, 'b' => 216, 'a' => 1.0],
        'dark' => ['r' => 45, 'g' => 212, 'b' => 191, 'a' => 1.0],
    ],
    'LinkHover' => [
        'light' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
        'dark' => ['r' => 94, 'g' => 234, 'b' => 212, 'a' => 1.0],
    ],
    'Focus' => [
        'light' => ['r' => 59, 'g' => 130, 'b' => 246, 'a' => 1.0],
        'dark' => ['r' => 96, 'g' => 165, 'b' => 250, 'a' => 1.0],
    ],
    'Rail' => [
        'light' => ['r' => 203, 'g' => 213, 'b' => 225, 'a' => 1.0],
        'dark' => ['r' => 70, 'g' => 70, 'b' => 70, 'a' => 1.0],
    ],
    'Success' => [
        'light' => ['r' => 22, 'g' => 163, 'b' => 74, 'a' => 1.0],
        // Forest — neon lime (8,222,8) is light-only; dark CTAs stay green, not glowing.
        'dark' => ['r' => 20, 'g' => 83, 'b' => 45, 'a' => 1.0],
    ],
    'Danger' => [
        'light' => ['r' => 249, 'g' => 83, 'b' => 109, 'a' => 1.0],
        // Wine — original pink (249,83,109) is light-only.
        'dark' => ['r' => 136, 'g' => 32, 'b' => 48, 'a' => 1.0],
    ],
    // Current step / selected tab — darker than completed chips in light, lifted in dark.
    'Selected' => [
        'light' => ['r' => 79, 'g' => 90, 'b' => 94, 'a' => 1.0],
        'dark' => ['r' => 110, 'g' => 118, 'b' => 124, 'a' => 1.0],
    ],
    // Distinct from InputFill so DisplayMode.Disabled still reads as greyed-out.
    'Disabled' => [
        'light' => ['r' => 244, 'g' => 244, 'b' => 244, 'a' => 1.0],
        'dark' => ['r' => 72, 'g' => 72, 'b' => 78, 'a' => 1.0],
    ],
    'Warning' => [
        // Studio VCR gold banner reads mustard (~202,138,4), not pure amber.
        'light' => ['r' => 202, 'g' => 138, 'b' => 4, 'a' => 1.0],
        // Darker amber so white banner text stays readable (avoids pale-yellow + white).
        'dark' => ['r' => 161, 'g' => 98, 'b' => 7, 'a' => 1.0],
    ],
    'CheckboxLabel' => [
        'light' => ['r' => 30, 'g' => 41, 'b' => 59, 'a' => 1.0],
        'dark' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
    ],
    'CheckboxBorder' => [
        'light' => ['r' => 100, 'g' => 116, 'b' => 139, 'a' => 1.0],
        'dark' => ['r' => 148, 'g' => 163, 'b' => 184, 'a' => 1.0],
    ],
];
