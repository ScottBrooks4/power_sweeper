<?php

declare(strict_types=1);

/**
 * Shared Studio repair hop chain for all app classes (VCR, THCEE, ASC, TDR, …).
 *
 * Stage composites: formulas, accessibility (+ SARIF inside each).
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return [
    ['id' => 'fix_formula_errors', 'options' => []],
    ['id' => 'accessibility_polish', 'options' => []],
];
