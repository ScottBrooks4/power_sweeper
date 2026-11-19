<?php

declare(strict_types=1);

return [
    'description' => 'Prepare controls that lack Fill/Color (modern/Fluent) by swapping them to classic templates, then add Light/Dark theming via gblThemeLight/gblThemeDark palettes (controls bind gblTheme.Token). Prefer running repair_studio_errors first as a separate step. Default force=false keeps non-default user colors; force=true re-themes all literals.',
    'force' => false,
    'hops' => [
        [
            'id' => 'prefer_classic_theme_controls',
            // Optional: also convert ModernNumberInput (breaks .Value → .Text callers)
            // 'options' => ['include_modern_number_input' => true],
        ],
        [
            'id' => 'enable_dark_mode',
            // Defaults live in config/theme_defaults.php — override here or via theme_defaults_file.
            'options' => [
                // 'theme_defaults_file' => __DIR__ . '/../config/theme_defaults.php',
                // 'theme_defaults' => ['Accent' => ['light' => ['r'=>37,'g'=>99,'b'=>235,'a'=>1.0], 'dark' => ['r'=>96,'g'=>165,'b'=>250,'a'=>1.0]]],
            ],
        ],
    ],
];
