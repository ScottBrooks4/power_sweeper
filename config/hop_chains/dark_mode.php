<?php

declare(strict_types=1);

/**
 * Classic theme prep then dark-mode theming.
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return [
    ['id' => 'prefer_classic_theme_controls', 'options' => []],
    [
        'id' => 'enable_dark_mode',
        'options' => [],
    ],
];
