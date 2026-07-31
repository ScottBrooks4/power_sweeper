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
        'dark' => ['r' => 38, 'g' => 38, 'b' => 42, 'a' => 1.0],
    ],
    'SurfaceMuted' => [
        'light' => ['r' => 241, 'g' => 245, 'b' => 249, 'a' => 1.0],
        'dark' => ['r' => 45, 'g' => 45, 'b' => 45, 'a' => 1.0],
    ],
    'Text' => [
        'light' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
        'dark' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
    ],
    'TextMuted' => [
        'light' => ['r' => 100, 'g' => 116, 'b' => 139, 'a' => 1.0],
        'dark' => ['r' => 200, 'g' => 200, 'b' => 208, 'a' => 1.0],
    ],
    'Border' => [
        'light' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
        'dark' => ['r' => 80, 'g' => 80, 'b' => 80, 'a' => 1.0],
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
        'dark' => ['r' => 74, 'g' => 222, 'b' => 128, 'a' => 1.0],
    ],
    'Warning' => [
        'light' => ['r' => 234, 'g' => 179, 'b' => 8, 'a' => 1.0],
        'dark' => ['r' => 250, 'g' => 204, 'b' => 21, 'a' => 1.0],
    ],
];
