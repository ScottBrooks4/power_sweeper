<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

const POWER_SWEEPER_ROOT = __DIR__;
const POWER_SWEEPER_STORAGE = __DIR__ . '/storage';
const POWER_SWEEPER_PROFILES = __DIR__ . '/profiles';

function ps_ini_bytes(string $val): int
{
    $val = trim($val);
    if ($val === '' || $val === '-1') {
        return PHP_INT_MAX;
    }
    if (!preg_match('/^(\d+)([KMGT]?)B?$/i', $val, $m)) {
        return (int) $val;
    }
    $n = (int) $m[1];
    return match (strtoupper($m[2])) {
        'K' => $n * 1024,
        'M' => $n * 1024 * 1024,
        'G' => $n * 1024 * 1024 * 1024,
        'T' => $n * 1024 * 1024 * 1024 * 1024,
        default => $n,
    };
}

if (!is_dir(POWER_SWEEPER_STORAGE . '/tmp')) {
    mkdir(POWER_SWEEPER_STORAGE . '/tmp', 0777, true);
}
if (!is_dir(POWER_SWEEPER_STORAGE . '/out')) {
    mkdir(POWER_SWEEPER_STORAGE . '/out', 0777, true);
}
