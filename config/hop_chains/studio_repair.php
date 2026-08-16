<?php

declare(strict_types=1);

/**
 * Shared Studio repair hop chain for all app classes (VCR, THCEE, ASC, TDR, …).
 *
 * Formula repairs are bundled in fix_formula_errors; a11y and SARIF stay explicit.
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return [
    ['id' => 'fix_formula_errors', 'options' => []],
    ['id' => 'accessibility_labels', 'options' => []],
    ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
    ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
    ['id' => 'tooltip_from_label', 'options' => []],
    ['id' => 'regenerate_sarif', 'options' => []],
];
