<?php

declare(strict_types=1);

/**
 * Web IR → Power App heuristic apply.
 *
 * Reads WebApp/power_sweeper_ir.json (from power_to_web or external edit) and
 * applies document layout, labels, layout/state, renames, and navigation. Then
 * fills a11y/focus chrome that web tooling often omits. Does not invent missing
 * controls or rewrite arbitrary Power Fx into executable web code.
 */
return [
    'description' => 'Apply WebApp IR heuristics (labels, layout/state, renames, Navigate/SetFocus), normalize screen refs, restore classic ScaleToFit, then fill a11y labels / focus ring / tab index / tooltips.',
    'hops' => [
        ['id' => 'import_web_ir', 'options' => []],
        ['id' => 'repair_double_qualified_refs', 'options' => []],
        ['id' => 'configure_power_document', 'options' => ['mode' => 'power']],
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
        ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
        ['id' => 'tooltip_from_label', 'options' => []],
    ],
];
