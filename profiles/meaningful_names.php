<?php

declare(strict_types=1);

/**
 * Meaningful control names from Text / AccessibleLabel / child labels, then fill a11y gaps.
 */
return [
    'description' => 'Rename generic Studio control names (Button1, Container54, Label7_2) to meaningful PascalCase names derived from visible text and accessibility labels, then run accessibility_labels and tooltip_from_label.',
    'hops' => [
        ['id' => 'meaningful_names', 'options' => ['only_generic' => true]],
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'tooltip_from_label', 'options' => []],
    ],
];
