<?php

declare(strict_types=1);

/**
 * Alias of repair_powered for THCEE-named inputs (kept for scripts/UI compatibility).
 */
return [
    'description' => 'THCEE powered preset (alias of repair_powered): full Studio repair then enable_dark_mode. force=true for complete powered theming.',
    'app_class' => 'thcee',
    'force' => true,
    'hops' => include __DIR__ . '/includes/thcee_powered_hops.php',
];
