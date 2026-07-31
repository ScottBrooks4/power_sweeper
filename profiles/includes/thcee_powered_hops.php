<?php

declare(strict_types=1);

/**
 * THCEE powered hop chain: locale + dark mode only.
 *
 * Skips VCR-class control-ref / syntax repair that breaks global component hosts
 * (comTranslations, comExternalFunctions_* on THCEE Control Screen).
 *
 * @return list<array{id:string,options?:array<string,mixed>}>
 */
return array_merge(
    (include dirname(__DIR__) . '/unwhack_locale.php')['hops'],
    (include dirname(__DIR__) . '/dark_mode.php')['hops'],
);
