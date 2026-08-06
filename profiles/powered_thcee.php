<?php

declare(strict_types=1);

/**
 * THCEE "powered" preset: full Studio repair + dark mode.
 *
 * Uses the same repair chain as VCR-class apps; component-host safety for
 * comTranslations / comExternalFunctions_* is enforced inside repair_control_refs.
 */
return [
    'description' => 'THCEE powered preset: full Studio repair (locale, refs, syntax, a11y, delegation) then enable_dark_mode. force=true for complete powered theming.',
    'app_class' => 'thcee',
    'force' => true,
    'hops' => include __DIR__ . '/includes/thcee_powered_hops.php',
];
