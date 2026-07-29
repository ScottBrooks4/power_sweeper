<?php

declare(strict_types=1);

/**
 * Combined pass for Studio App checker errors seen after locale/language switches
 * (and common accessibility checker gaps), matching apps like the CDLS VCR form.
 *
 * Fixes:
 *   - Expected operator / Invalid # args (Size, Orientation, ParseJSON, If, LookUp)
 *     via locale separator unwhack (YAML + InvariantScript + AutoRuleBindingString)
 *   - Expecting a true or false value (Checked/Default/Visible 1/0, If(cond,1,0))
 *   - Missing accessible labels / tooltips
 *   - Focus isn't showing
 *
 * Does NOT rewrite SharePoint delegation warnings or remove unused media — those are
 * different App checker categories. Optionally append hop `scan_studio_issues` to
 * report anything still flagged by heuristics.
 */
return [
    'description' => 'Repair Studio App checker errors from locale corruption (Size/Orientation/ParseJSON/Checked/Visible), then accessibility labels, focus rings, and tooltips.',
    'hops' => [
        ['id' => 'unwhack_locale_formulas', 'options' => []],
        ['id' => 'repair_checked_booleans', 'options' => []],
        ['id' => 'accessibility_labels', 'options' => []],
        ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
        ['id' => 'tooltip_from_label', 'options' => []],
    ],
];
