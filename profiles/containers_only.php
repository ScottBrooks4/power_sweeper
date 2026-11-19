<?php

return [
    'description' => 'Only strip default container chrome (shadow, border, radius, padding).',
    'hops' => [
        ['id' => 'normalize_containers', 'options' => []],
        ['id' => 'strip_default_fill', 'options' => []],
    ],
];
