<?php

declare(strict_types=1);

/**
 * Apache/nginx entry for http://localhost/power_sweeper/
 * (PHP built-in server can keep using router.php instead.)
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if (preg_match('#/floorscanner(?:/index\\.php)?/?$#', $uri)) {
    require __DIR__ . '/public/floorscanner.php';
    return;
}
require __DIR__ . '/public/index.php';
