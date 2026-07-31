<?php

declare(strict_types=1);

/**
 * Full CDLS VCR repair pass followed by dark mode — convenience preset.
 * For large apps you can still run repair_studio_errors and dark_mode separately.
 */
return [
    'description' => 'Full Studio checker repair (locale, refs, a11y, delegation, SARIF) then enable_dark_mode with gblTheme palettes. CDLS VCR one-shot preset.',
    'hops' => array_merge(
        (include __DIR__ . '/repair_studio_errors.php')['hops'],
        (include __DIR__ . '/dark_mode.php')['hops'],
    ),
];
