<?php

declare(strict_types=1);

/**
 * Powered preset: full Studio repair + central theme palettes (all app classes).
 *
 * Output convention: *.powered.msapp (repairs + gblThemeLight/gblThemeDark in App.OnStart).
 */
return [
    'description' => 'Powered preset for all app classes (VCR / THCEE / ASC / TDR / …): full Studio repair, classic theme prep, then enable_dark_mode with editable gblThemeLight/gblThemeDark palettes. force=true re-themes Studio chrome and remaining literals for a complete powered deliverable.',
    'app_class' => 'shared',
    'force' => true,
    'hops' => include __DIR__ . '/includes/powered_hops.php',
];
