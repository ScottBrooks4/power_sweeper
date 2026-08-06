<?php

declare(strict_types=1);

/**
 * Web IR → Power App heuristic apply.
 *
 * Reads WebApp/power_sweeper_ir.json (from power_to_web or external edit) and
 * applies document layout, label sync, and Navigate renames. Does not invent
 * missing controls or rewrite arbitrary Power Fx into executable web code.
 */
return [
    'description' => 'Apply WebApp IR heuristics onto the .msapp (labels, navigation renames, document layout), then restore classic Power document ScaleToFit defaults.',
    'hops' => [
        ['id' => 'import_web_ir', 'options' => []],
        ['id' => 'configure_power_document', 'options' => ['mode' => 'power']],
    ],
];
