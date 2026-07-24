<?php

declare(strict_types=1);

return [
    'description' => 'Add a dark-mode toggle and central gblThemeLight/gblThemeDark/gblTheme palettes; controls use gblTheme.* tokens (edit colors in App.OnStart or config/theme_defaults.php).',
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
