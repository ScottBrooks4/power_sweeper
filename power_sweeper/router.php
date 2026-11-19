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

// Published web packages — http://localhost:8080/web/{slug}/
if ($uri === '/web' || $uri === '/web/' || str_starts_with($uri, '/web/')) {
    if ($uri === '/web' || $uri === '/web/') {
        $index = __DIR__ . '/web/index.html';
        if (is_file($index)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($index);
            return true;
        }
    }
    if (preg_match('#^/web/([^/]+)(/.*)?$#', $uri, $m) === 1) {
        $slug = $m[1];
        $front = __DIR__ . '/web/' . $slug . '/index.php';
        if (is_file($front)) {
            require $front;
            return true;
        }
    }
}

if ($uri === '/floorscanner' || $uri === '/floorscanner/' || $uri === '/floorscanner/index.php') {
    require __DIR__ . '/public/floorscanner.php';
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
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
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
