<?php

declare(strict_types=1);

/**
 * Export canvas structure to intermediate web IR (not a full compiler).
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return [
    ['id' => 'meaningful_names', 'options' => ['only_generic' => true]],
    ['id' => 'repair_double_qualified_refs', 'options' => []],
    ['id' => 'export_web_ir', 'options' => ['configure_document' => true]],
    ['id' => 'configure_power_document', 'options' => ['mode' => 'web']],
];
