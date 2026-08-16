<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

const POWER_SWEEPER_ROOT = __DIR__;
const POWER_SWEEPER_STORAGE = __DIR__ . '/storage';

// Large canvas apps (e.g. THCEE) need well above PHP's default 128M/256M while
// unpacking control trees and applying enable_dark_mode. Raise early so API
// routes and CLI share the same floor even when Azure .user.ini is slow/missing.
// Keep at 1024M (not higher): App Service plans with ~1.5–1.75GB RAM will hard-kill
// PHP if memory_limit exceeds the container, which skips our NDJSON error handler.
(static function (): void {
    $want = 1024 * 1024 * 1024; // 1024M
    $current = ini_get('memory_limit');
    if (!is_string($current) || $current === '' || $current === '-1') {
        return;
    }
    if (!preg_match('/^(\d+)([KMGT]?)B?$/i', trim($current), $m)) {
        @ini_set('memory_limit', '1024M');
        return;
    }
    $n = (int) $m[1];
    $bytes = match (strtoupper($m[2])) {
        'K' => $n * 1024,
        'M' => $n * 1024 * 1024,
        'G' => $n * 1024 * 1024 * 1024,
        'T' => $n * 1024 * 1024 * 1024 * 1024,
        default => $n,
    };
    if ($bytes > 0 && $bytes < $want) {
        @ini_set('memory_limit', '1024M');
    }
})();

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
