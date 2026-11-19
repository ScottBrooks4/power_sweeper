<?php

declare(strict_types=1);

/**
 * Apply WebApp IR heuristics, then fill a11y / focus / tab / tooltips.
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return [
    ['id' => 'import_from_web_ir', 'options' => []],
    // HTML section-top anchors → mid-pane SetFocus buttons in the correct sections.
    ['id' => 'apply_section_anchors', 'options' => ['relocate' => true, 'force_y' => true]],
];
