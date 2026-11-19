<?php

return [
    'description' => 'Accessibility pass: labels (Self.Text when dynamic), tooltips, focus ring, tab order, then refresh AppCheckerResult.sarif. Set force => true to overwrite existing AccessibleLabel/Tooltip values.',
    'force' => false,
    'hops' => [
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
        ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
        ['id' => 'tooltip_from_label', 'options' => []],
        ['id' => 'regenerate_sarif', 'options' => []],
    ],
];
