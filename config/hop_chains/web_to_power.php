<?php

declare(strict_types=1);

/**
 * Apply WebApp IR heuristics, then fill a11y / focus / tab / tooltips.
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return [
    ['id' => 'import_web_ir', 'options' => []],
    ['id' => 'repair_double_qualified_refs', 'options' => []],
    ['id' => 'configure_power_document', 'options' => ['mode' => 'power']],
    ['id' => 'accessibility_labels', 'options' => []],
    ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
    ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
    ['id' => 'tooltip_from_label', 'options' => []],
];
