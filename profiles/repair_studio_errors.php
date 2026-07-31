<?php

declare(strict_types=1);

/**
 * Combined pass for Studio App checker errors seen after locale/language switches
 * and screen duplication (CDLS VCR App class).
 */
return [
    'description' => 'Full Studio App checker repair for VCR-class apps: locale separators, control/SharePoint refs, booleans, accessibility, maintainability, delegation, and live SARIF regeneration.',
    'app_class' => 'vcr',
    'hops' => include __DIR__ . '/includes/vcr_repair_hops.php',
];
