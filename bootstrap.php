<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

const POWER_SWEEPER_ROOT = __DIR__;
const POWER_SWEEPER_STORAGE = __DIR__ . '/storage';

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

foreach (['tmp', 'out'] as $sub) {
    $dir = POWER_SWEEPER_STORAGE . '/' . $sub;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    // Best-effort: Apache (http) must write here. Prefer scripts/fix_permissions.sh (1777).
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }
}
