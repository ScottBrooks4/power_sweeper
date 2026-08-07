<?php

declare(strict_types=1);

/**
 * Powered hop chain: full Studio repair + dark mode (all app classes).
 *
 * Component-host safety (comTranslations, comExternalFunctions_*) is enforced inside
 * repair_control_refs and the live checker — global component refs stay bare.
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return array_merge(
    include __DIR__ . '/studio_repair_hops.php',
    array_map(
        static function (array $hop): array {
            if (($hop['id'] ?? '') === 'enable_dark_mode') {
                $hop['options'] = array_merge($hop['options'] ?? [], ['force' => true]);
            }

            return $hop;
        },
        (include dirname(__DIR__) . '/dark_mode.php')['hops'],
    ),
);
