<?php

declare(strict_types=1);

/**
 * Combined pass for Studio App checker errors seen after locale/language switches
 * and screen duplication (CDLS VCR App class).
 */
return [
    'description' => 'Repair Studio App checker errors: locale separators, control refs, SharePoint field typos, booleans, accessibility, maintainability.',
    'hops' => [
        ['id' => 'unwhack_locale_formulas', 'options' => []],
        ['id' => 'repair_control_refs', 'options' => []],
        ['id' => 'repair_sharepoint_fields', 'options' => []],
        ['id' => 'repair_checked_booleans', 'options' => []],
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
        ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
        ['id' => 'tooltip_from_label', 'options' => []],
        ['id' => 'repair_maintainability', 'options' => []],
    ],
];
