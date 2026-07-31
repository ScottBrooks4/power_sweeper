<?php

declare(strict_types=1);

/**
 * THCEE "powered" preset: locale + dark mode only.
 *
 * Skips VCR-class control-ref / syntax repair hops that can break THCEE's
 * global component host pattern (comTranslations, comExternalFunctions_* on
 * THCEE Control Screen referenced bare from other screens).
 */
return [
    'description' => 'THCEE powered preset: unwhack locale separators then enable_dark_mode (no VCR-class control ref repair).',
    'app_class' => 'thcee',
    'hops' => include __DIR__ . '/includes/thcee_powered_hops.php',
];
