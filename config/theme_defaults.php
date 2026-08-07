<?php

declare(strict_types=1);

/**
 * Editable default theme tokens for the dark_mode profile.
 *
 * Makers edit the generated App.Formulas palettes (gblThemeLight / gblThemeDark).
 * Operators can change these defaults (or override via hop options) without
 * touching EnableDarkModeHop — colors stay centralized here.
 * Core tokens here always win over first-seen control literals.
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
    'Text' => [
        'light' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
        'dark' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
    ],
    'TextMuted' => [
        'light' => ['r' => 100, 'g' => 116, 'b' => 139, 'a' => 1.0],
        // ≥4.5:1 on Page/InputFill for placeholders and secondary labels.
        'dark' => ['r' => 212, 'g' => 212, 'b' => 220, 'a' => 1.0],
    ],
    // Dark ink for text that sits on pastel/light chips (ColorFade status pills, etc.).
    // Same in both themes — those surfaces stay light even when the app is dark.
    'TextOnLight' => [
        'light' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
        'dark' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
    ],
    'Border' => [
        'light' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
        'dark' => ['r' => 110, 'g' => 110, 'b' => 120, 'a' => 1.0],
    ],
    'Accent' => [
        'light' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
        'dark' => ['r' => 96, 'g' => 165, 'b' => 250, 'a' => 1.0],
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
        // Darker green so white Text/TextOnAccent stays ≥4.5:1 on banners.
        'dark' => ['r' => 21, 'g' => 128, 'b' => 61, 'a' => 1.0],
    ],
    'Warning' => [
        'light' => ['r' => 234, 'g' => 179, 'b' => 8, 'a' => 1.0],
        // Darker amber so white banner text stays readable (avoids pale-yellow + white).
        'dark' => ['r' => 161, 'g' => 98, 'b' => 7, 'a' => 1.0],
    ],
];
