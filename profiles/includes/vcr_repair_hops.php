<?php

declare(strict_types=1);

/**
 * VCR-class Studio repair hop chain (shared by repair_studio_errors and repair_powered).
 *
 * repair_double_qualified_refs bookends repair_control_refs: pre-clean existing
 * corruption, then a final pass for App-level formulas and any edge cases.
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return [
    ['id' => 'unwhack_locale_formulas', 'options' => []],
    ['id' => 'repair_double_qualified_refs', 'options' => []],
    ['id' => 'repair_control_refs', 'options' => []],
    ['id' => 'repair_context_aware_refs', 'options' => []],
    ['id' => 'repair_double_qualified_refs', 'options' => []],
    ['id' => 'repair_var_current_package', 'options' => []],
    ['id' => 'repair_sharepoint_fields', 'options' => []],
    ['id' => 'repair_ghost_patch_fields', 'options' => []],
    ['id' => 'repair_studio_syntax', 'options' => []],
    ['id' => 'repair_checked_booleans', 'options' => []],
    ['id' => 'accessibility_labels', 'options' => []],
    ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
    ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
    ['id' => 'tooltip_from_label', 'options' => []],
    ['id' => 'repair_maintainability', 'options' => []],
    ['id' => 'repair_delegation', 'options' => []],
    ['id' => 'repair_converge_formulas', 'options' => []],
    ['id' => 'regenerate_sarif', 'options' => []],
];
