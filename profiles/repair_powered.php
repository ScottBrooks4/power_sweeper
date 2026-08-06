<?php

declare(strict_types=1);

/**
 * CDLS VCR "powered" preset: full Studio repair pass + central theme palettes.
 *
 * Output convention: *.powered.msapp (repairs + gblThemeLight/gblThemeDark in App.OnStart).
 */
return [
    'description' => 'Powered preset: repair_studio_errors (locale, refs, syntax, a11y, delegation) then enable_dark_mode with editable gblThemeLight/gblThemeDark palettes. force=true re-themes Studio chrome and remaining literals for a complete powered deliverable.',
    'app_class' => 'vcr',
    'force' => true,
    'hops' => array_merge(
        include __DIR__ . '/includes/vcr_repair_hops.php',
        array_map(
            static function (array $hop): array {
                if (($hop['id'] ?? '') === 'enable_dark_mode') {
                    $hop['options'] = array_merge($hop['options'] ?? [], ['force' => true]);
                }

                return $hop;
            },
            (include __DIR__ . '/dark_mode.php')['hops'],
        ),
    ),
];
