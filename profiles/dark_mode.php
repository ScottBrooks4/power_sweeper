<?php

declare(strict_types=1);

return [
    'description' => 'Add Light/Dark on Settings Theme radio (or a toggle) and central gblThemeLight/gblThemeDark/gblTheme named-formula palettes; controls use gblTheme.* tokens (edit colors in App.Formulas or config/theme_defaults.php). Prefer running repair_studio_errors first as a separate step.',
    'hops' => [
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
