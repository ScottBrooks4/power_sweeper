<?php

declare(strict_types=1);

/**
 * MVC GraphController: Microsoft Graph / M365 JSON API.
 * Routes under /api/graph/* (status, mail, calendar, files, serviceAccount).
 */
$root = dirname(__DIR__);
require_once $root . '/models/GraphModel.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$body = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $body = array_merge($_GET, $body);
}

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$resource = (string) ($body['resource'] ?? '');
if ($resource === '' && preg_match('#/api/graph/([^/?]+)#', $path, $m) === 1) {
    $resource = $m[1];
}
if ($resource === '') {
    $resource = (string) ($_GET['resource'] ?? 'status');
}

$model = new GraphModel($root);
try {
    $result = match ($resource) {
        'status', 'me' => $model->status(),
        'mail', 'sendMail' => $model->sendMail([
            'to' => (string) ($body['to'] ?? ''),
            'subject' => (string) ($body['subject'] ?? ''),
            'body' => (string) ($body['body'] ?? ''),
            'cc' => (string) ($body['cc'] ?? ''),
            'bcc' => (string) ($body['bcc'] ?? ''),
            'connector' => (string) ($body['connector'] ?? 'Microsoft.Graph'),
            'operation' => (string) ($body['operation'] ?? 'sendMail'),
        ]),
        'calendar' => $model->calendar(is_array($body) ? $body : []),
        'files', 'drive' => $model->files(is_array($body) ? $body : []),
        'serviceAccount', 'service_account' => $model->serviceAccount(is_array($body) ? $body : []),
        default => ['ok' => false, 'error' => 'unknown graph resource'],
    };
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
