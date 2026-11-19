<?php

declare(strict_types=1);

/**
 * Report-only verification — does not modify formulas; lists remaining
 * locale/boolean/focus issues in the sweep report.
 */
return [
    'description' => 'Scan and report remaining Studio-class issues (locale, booleans, focus) without modifying the app. Append after repair profiles to verify cleanup.',
    'hops' => [
        ['id' => 'scan_studio_issues', 'options' => []],
    ],
];
