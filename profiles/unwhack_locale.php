<?php

declare(strict_types=1);

return [
    'description' => 'Repair comma-decimal / locale separator corruption (e.g. German) in formulas and internal InvariantScript.',
    'hops' => [
        ['id' => 'unwhack_locale_formulas', 'options' => []],
    ],
];
