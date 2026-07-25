<?php

declare(strict_types=1);

/**
 * One-pass cleanup for apps like CDLS VCR / VCDS THCEE:
 * repair locale/Studio formula errors, then add central dark-theme palettes.
 *
 * Does NOT fix SharePoint delegation warnings, unused media, or missing Flows.
 */
return [
    'description' => 'Repair Studio locale/formula errors (Size/Orientation/ParseJSON/Checked + a11y/focus/tooltips), then add editable gblTheme dark mode.',
    'hops' => [
        ['id' => 'unwhack_locale_formulas', 'options' => []],
        ['id' => 'repair_checked_booleans', 'options' => []],
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
        ['id' => 'tooltip_from_label', 'options' => []],
        [
            'id' => 'enable_dark_mode',
            'options' => [
                // Brand defaults: edit config/theme_defaults.php
            ],
        ],
    ],
];
