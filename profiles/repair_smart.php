<?php

declare(strict_types=1);

/**
 * Smart repair: meaningful control names from visible/a11y text, then full Studio repair.
 *
 * Use on apps with generic Studio names (Button1, Container54_2). Skip for already-named
 * or pre-repaired packages where renaming can disturb formulas.
 */
return [
    'description' => 'Rename generic control names from Text/AccessibleLabel, then run the full Studio repair chain (locale, context-aware refs, delegation, SARIF).',
    'hops' => array_merge(
        [
            ['id' => 'meaningful_names', 'options' => ['only_generic' => true]],
        ],
        include __DIR__ . '/includes/studio_repair_hops.php',
    ),
];
