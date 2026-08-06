<?php

declare(strict_types=1);

/**
 * Power App → web-oriented structural export.
 *
 * Builds WebApp/power_sweeper_ir.json + a static HTML scaffold and aligns
 * Properties.json toward browser layout. This is a heuristic structural
 * conversion — not a Power Fx → JavaScript compiler.
 */
return [
    'description' => 'Export structural web IR + HTML preview scaffold; configure document layout for browser (ScaleToFit off). Formulas stay in the .msapp.',
    'hops' => [
        ['id' => 'export_web_ir', 'options' => ['configure_document' => true]],
        ['id' => 'configure_power_document', 'options' => ['mode' => 'web']],
    ],
];
