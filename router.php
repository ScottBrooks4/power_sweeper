<?php

declare(strict_types=1);

/**
 * Router for PHP built-in server:
 *   php -S localhost:8080 router.php
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if ($uri === '/' || $uri === '') {
    require __DIR__ . '/public/index.php';
    return true;
}

if (str_starts_with($uri, '/assets/')) {
    $path = __DIR__ . '/public' . $uri;
    if (is_file($path)) {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
        ];
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
        }
        readfile($path);
        return true;
    }
}

if (str_starts_with($uri, '/api/')) {
    $path = __DIR__ . $uri;
    if (is_file($path)) {
        require $path;
        return true;
    }
}

$file = __DIR__ . '/public' . $uri;
if (is_file($file)) {
    return false; // let built-in server serve it
}

http_response_code(404);
echo 'Not found';
return true;
