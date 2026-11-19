<?php

declare(strict_types=1);

// Front controller: static files from public/, API under /api/data,
// MVC auth/graph routes, screen controller for ?screen=.
// When published under /web/{slug}/ (or /power_sweeper/web/{slug}/), strip that prefix.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$basePrefix = '';
if (preg_match('#^(.*?/web/[^/]+)/index\.php$#', $script, $m) === 1) {
    $basePrefix = $m[1];
    if ($uri === $basePrefix || str_starts_with($uri, $basePrefix . '/')) {
        $uri = substr($uri, strlen($basePrefix)) ?: '/';
    }
} elseif (preg_match('#^(.*?/web/[^/]+)(?:/|$)#', $uri, $m) === 1) {
    $basePrefix = $m[1];
    if ($uri === $basePrefix || str_starts_with($uri, $basePrefix . '/')) {
        $uri = substr($uri, strlen($basePrefix)) ?: '/';
    }
}

$root = __DIR__;
$isMvc = is_dir($root . '/controllers') && is_dir($root . '/models');

// Azure AD / OpenID Connect
if ($isMvc && (str_starts_with($uri, '/auth/') || $uri === '/auth')) {
    $_GET['action'] = trim(substr($uri, strlen('/auth')), '/') ?: 'login';
    require $root . '/controllers/AuthController.php';
    exit;
}

// Microsoft Graph / M365
if ($isMvc && str_starts_with($uri, '/api/graph')) {
    require $root . '/controllers/GraphController.php';
    exit;
}

if (str_starts_with($uri, '/api/')) {
    require $root . '/server/api.php';
    exit;
}

// MVC: default document and ?screen= go through ScreenController (canvas-identical HTML).
if ($isMvc && ($uri === '/' || $uri === '' || isset($_GET['screen']))) {
    require $root . '/controllers/screen.php';
    exit;
}

// Runnable app is under public/. Conversion archive (import/) is not web-served.
$file = $root . '/public' . ($uri === '/' ? '/index.html' : $uri);
if (is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found\n";
