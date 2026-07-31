<?php

declare(strict_types=1);

/**
 * Non-destructive formula pass — only refreshes embedded App checker SARIF
 * using the live Studio-equivalent checker (no formula edits).
 */
return [
    'description' => 'Regenerate AppCheckerResult.sarif from the live App checker without changing formulas (updates Studio error counts without Save).',
    'hops' => [
        ['id' => 'regenerate_sarif', 'options' => []],
    ],
];
