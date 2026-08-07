<?php

declare(strict_types=1);

/**
 * Full Studio repair pass followed by dark mode — convenience preset.
 * Prefer repair_powered for the *.powered.msapp deliverable name.
 */
return [
    'description' => 'Full Studio checker repair (locale, refs, a11y, delegation, SARIF) then enable_dark_mode with gblTheme palettes. Prefer repair_powered for the *.powered.msapp deliverable name.',
    'force' => true,
    'hops' => (include __DIR__ . '/repair_powered.php')['hops'],
];
