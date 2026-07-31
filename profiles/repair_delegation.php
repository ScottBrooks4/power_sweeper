<?php

declare(strict_types=1);

/**
 * SharePoint delegation fixes only — run after formula/a11y repair or on its own
 * when delegation hints are the only remaining App checker performance issues.
 */
return [
    'description' => 'Repair SharePoint delegation warnings: delegable email filters, collection CountIf→CountRows, split duplicate-request Filters, admin lookup StartsWith. Regenerates AppCheckerResult.sarif.',
    'hops' => [
        ['id' => 'repair_delegation', 'options' => []],
        ['id' => 'regenerate_sarif', 'options' => []],
    ],
];
