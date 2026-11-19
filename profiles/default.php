<?php

return [
    'description' => 'Balanced cleanup: normalize containers, snap near-misses, then fill accessibility labels.',
    'hops' => [
        ['id' => 'normalize_containers', 'options' => []],
        ['id' => 'align_near_miss', 'options' => ['tolerance' => 3]],
        ['id' => 'accessibility_labels', 'options' => []],
    ],
];
