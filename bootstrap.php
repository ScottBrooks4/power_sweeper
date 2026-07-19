<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

const POWER_SWEEPER_ROOT = __DIR__;
const POWER_SWEEPER_STORAGE = __DIR__ . '/storage';
const POWER_SWEEPER_PROFILES = __DIR__ . '/profiles';

if (!is_dir(POWER_SWEEPER_STORAGE . '/tmp')) {
    mkdir(POWER_SWEEPER_STORAGE . '/tmp', 0777, true);
}
if (!is_dir(POWER_SWEEPER_STORAGE . '/out')) {
    mkdir(POWER_SWEEPER_STORAGE . '/out', 0777, true);
}
