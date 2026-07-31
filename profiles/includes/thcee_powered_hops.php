<?php

declare(strict_types=1);

/**
 * THCEE powered hop chain: full Studio repair + dark mode.
 *
 * Component-host safety (comTranslations, comExternalFunctions_*) is enforced inside
 * repair_control_refs and the live checker — global component refs stay bare.
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return array_merge(
    include __DIR__ . '/vcr_repair_hops.php',
    (include dirname(__DIR__) . '/dark_mode.php')['hops'],
);
