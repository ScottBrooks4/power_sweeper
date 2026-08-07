<?php

declare(strict_types=1);

/**
 * Combined pass for Studio App checker errors (locale switches, screen duplication,
 * control/SharePoint refs, a11y, delegation). Works across VCR / THCEE / ASC / TDR apps.
 */
return [
    'description' => 'Full Studio App checker repair: locale separators, control/SharePoint refs, booleans, accessibility, maintainability, delegation, and live SARIF regeneration.',
    'hops' => include __DIR__ . '/includes/studio_repair_hops.php',
];
