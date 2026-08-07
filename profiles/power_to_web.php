<?php

declare(strict_types=1);

/**
 * Power App → web-oriented structural export.
 *
 * Optionally renames generic Studio controls first so IR names are stable, then
 * builds WebApp/power_sweeper_ir.json + a static HTML scaffold and aligns
 * Properties.json toward browser layout. Heuristic structural conversion —
 * not a Power Fx → JavaScript compiler.
 */
return [
    'description' => 'Rename generic screen controls (not component templates), clean any over-quoted screen refs, export structural web IR + HTML preview, configure document layout for browser (ScaleToFit off). Formulas stay in the .msapp.',
    'hops' => [
        ['id' => 'meaningful_names', 'options' => ['only_generic' => true]],
        // meaningful_names can surface pre-existing over-quotes; normalize before export.
        ['id' => 'repair_double_qualified_refs', 'options' => []],
        ['id' => 'export_web_ir', 'options' => ['configure_document' => true]],
        ['id' => 'configure_power_document', 'options' => ['mode' => 'web']],
    ],
];
