<?php

declare(strict_types=1);

/**
 * Combined pass for the class of Studio errors seen after locale/language switches
 * (and common accessibility checker gaps), matching apps like the CDLS VCR form.
 *
 * Does NOT rewrite SharePoint delegation warnings or remove unused media — those are
 * different App checker categories.
 */
return [
    'description' => 'Repair Studio formula errors from locale corruption (Size/Orientation/ParseJSON/Checked), then accessibility labels, focus rings, and tooltips.',
    'hops' => [
        ['id' => 'unwhack_locale_formulas', 'options' => []],
        ['id' => 'repair_checked_booleans', 'options' => []],
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
        ['id' => 'tooltip_from_label', 'options' => []],
    ],
];
