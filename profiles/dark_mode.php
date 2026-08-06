<?php

declare(strict_types=1);

return [
    'description' => 'Add Light/Dark on Settings Theme radio (or a toggle) and central gblThemeLight/gblThemeDark named-formula palettes; controls use If(gblDarkMode, gblThemeDark.Token, gblThemeLight.Token) (edit colors in App.Formulas or config/theme_defaults.php). Prefer running repair_studio_errors first as a separate step. Default force=false keeps non-default user colors; force=true re-themes all literals.',
    'force' => false,
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
