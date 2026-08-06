<?php

return [
    'description' => 'Accessibility-focused pass: labels and tooltips. Set force => true to overwrite existing AccessibleLabel/Tooltip values.',
    'force' => false,
    'hops' => [
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'tooltip_from_label', 'options' => []],
    ],
];
