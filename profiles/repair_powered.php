<?php

declare(strict_types=1);

/**
 * CDLS VCR "powered" preset: full Studio repair pass + central theme palettes.
 *
 * Output convention: *.powered.msapp (repairs + gblThemeLight/gblThemeDark in App.OnStart).
 */
return [
    'description' => 'Powered preset: repair_studio_errors (locale, refs, syntax, a11y, delegation) then enable_dark_mode with editable gblThemeLight/gblThemeDark palettes.',
    'hops' => array_merge(
        (include __DIR__ . '/repair_studio_errors.php')['hops'],
        (include __DIR__ . '/dark_mode.php')['hops'],
    ),
];
