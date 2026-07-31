<?php

declare(strict_types=1);

/**
 * Formula reference repair for duplicated / locale-switched canvas apps
 * (CDLS VCR class) — control qualification, SharePoint fields, package shape.
 */
return [
    'description' => 'Repair cross-screen control refs, double-qualified refs, SharePoint field typos, varCurrentPackage shape, and ghost Patch fields. Does not include locale, a11y, or delegation.',
    'hops' => [
        ['id' => 'repair_control_refs', 'options' => []],
        ['id' => 'repair_double_qualified_refs', 'options' => []],
        ['id' => 'repair_sharepoint_fields', 'options' => []],
        ['id' => 'repair_var_current_package', 'options' => []],
        ['id' => 'repair_ghost_patch_fields', 'options' => []],
        ['id' => 'regenerate_sarif', 'options' => []],
    ],
];
