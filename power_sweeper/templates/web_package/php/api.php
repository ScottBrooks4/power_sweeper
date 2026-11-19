<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/server/config.json';
$config = is_file($configPath)
    ? (json_decode((string) file_get_contents($configPath), true) ?: [])
    : [];
$storage = strtolower((string) ($config['storage'] ?? 'xml'));
if ($storage === 'lom') {
    $storage = 'xml';
}

require_once __DIR__ . '/TableMap.php';
require_once __DIR__ . '/LomStore.php';
require_once __DIR__ . '/SqlStore.php';
require_once __DIR__ . '/XmlStore.php';
require_once __DIR__ . '/MailAllowlist.php';
require_once __DIR__ . '/MailGateway.php';
require_once __DIR__ . '/StoreSeed.php';
if (is_file(__DIR__ . '/GraphGateway.php')) {
    require_once __DIR__ . '/GraphGateway.php';
}

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$body = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $body = array_merge($_GET, $body);
}
$action = (string) ($body['action'] ?? $_GET['action'] ?? 'list');
$table = (string) ($body['table'] ?? 'Items');

$root = dirname(__DIR__);
try {
    if ($action === 'invoke') {
        echo json_encode(handleInvoke($root, $config, $body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'status' || $action === 'health') {
        echo json_encode(handleStoreStatus($root, $config, $storage), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'meta' || $action === 'app') {
        echo json_encode(handleGeneratedMeta($root, $config, $storage), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!TableMap::isPlausible($table)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'rejected table name']);
        return;
    }

    $storeInfo = openStore($root, $config, $storage);
    $store = $storeInfo['store'];
    StoreSeed::ensure($store);

    $canonical = TableMap::canonical($table);
    $result = match ($action) {
        'list' => ['ok' => true, 'rows' => $store->list($table), 'table' => $canonical],
        'get' => ['ok' => true, 'row' => $store->get($table, $body['id'] ?? null), 'table' => $canonical],
        'insert' => ['ok' => true, 'row' => $store->insert($table, is_array($body['row'] ?? null) ? $body['row'] : []), 'table' => $canonical],
        'update' => ['ok' => true, 'row' => $store->update($table, is_array($body['row'] ?? null) ? $body['row'] : []), 'table' => $canonical],
        'delete' => ['ok' => true, 'deleted' => $store->delete($table, $body['id'] ?? null), 'table' => $canonical],
        'deleteWhere' => ['ok' => true, 'deleted' => $store->deleteWhere($table, is_array($body['match'] ?? null) ? $body['match'] : []), 'table' => $canonical],
        default => ['ok' => false, 'error' => 'unknown action'],
    };
    $result['storage'] = $storeInfo['engine'];
    if (!empty($storeInfo['fallback'])) {
        $result['storage_fallback'] = $storeInfo['fallback'];
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/**
 * @param array<string, mixed> $config
 * @return array{store: object, engine: string, fallback?: string, detail?: array<string, mixed>}
 */
function openStore(string $root, array $config, string $storage): array
{
    try {
        $store = match ($storage) {
            'sql' => new SqlStore($root, $config),
            'lom' => new LomStore($root, $config),
            default => new XmlStore($root, $config),
        };

        return [
            'store' => $store,
            'engine' => $storage === 'lom' ? 'xml' : $storage,
        ];
    } catch (Throwable $e) {
        // Local Apache without pdo_sqlite: keep reviewing the UI against the XML seed.
        if (
            $storage === 'sql'
            && str_contains($e->getMessage(), 'could not find driver')
            && is_file($root . '/data/app.xml')
        ) {
            return [
                'store' => new XmlStore($root, $config),
                'engine' => 'xml',
                'fallback' => 'pdo_sqlite missing — using data/app.xml',
            ];
        }
        throw $e;
    }
}

/**
 * Maintainable conversion surface: generated PHP modules + data files.
 * Coders edit server/generated + data/ + public/assets — not conversion JSON.
 *
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function handleGeneratedMeta(string $root, array $config, string $storage): array
{
    $appData = $root . '/server/generated/AppData.php';
    $handlers = $root . '/server/generated/Handlers.php';
    $out = [
        'ok' => true,
        'runtime' => 'php',
        'storage' => $storage,
        'generated' => [
            'AppData.php' => is_file($appData),
            'Handlers.php' => is_file($handlers),
        ],
        'data' => [
            'app.xml' => is_file($root . '/data/app.xml'),
            'app.sqlite' => is_file($root . '/data/app.sqlite'),
            'schema.sql' => is_file($root . '/data/schema.sql'),
        ],
        'assets' => [
            'app.css' => is_file($root . '/public/assets/app.css'),
            'generated.css' => is_file($root . '/public/assets/generated.css'),
            'handlers' => is_dir($root . '/public/assets/handlers'),
        ],
        'import_archive' => is_file($root . '/import/power_sweeper_ir.json')
            || is_file($root . '/ir/power_sweeper_ir.json'),
        'note' => 'Edit public/assets + server/generated + data/. import/ is Import→.msapp only.',
    ];
    if (is_file($appData)) {
        require_once $appData;
        if (class_exists('GeneratedAppData', false)) {
            $out['title'] = GeneratedAppData::title();
            $out['screens'] = GeneratedAppData::screens();
            $out['tables'] = GeneratedAppData::tables();
            $cols = GeneratedAppData::collections();
            $out['collections'] = array_map(
                static fn ($rows) => is_array($rows) ? count($rows) : 0,
                is_array($cols) ? $cols : [],
            );
        }
    }
    if (is_file($handlers)) {
        require_once $handlers;
        if (class_exists('GeneratedHandlers', false)) {
            $all = GeneratedHandlers::all();
            $out['handlers'] = count($all);
        }
    }
    $nav = $root . '/server/generated/Navigation.php';
    if (is_file($nav)) {
        require_once $nav;
        if (class_exists('GeneratedNavigation', false)) {
            $out['start_screen'] = GeneratedNavigation::startScreen();
        }
    }
    $handlerDir = $root . '/server/generated/handlers';
    if (is_dir($handlerDir)) {
        $out['handler_screens'] = array_values(array_map(
            static fn ($f) => basename((string) $f, '.php'),
            glob($handlerDir . '/*.php') ?: [],
        ));
    }

    return $out;
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function handleStoreStatus(string $root, array $config, string $storage): array
{
    $pdoSqlite = in_array('sqlite', PDO::getAvailableDrivers(), true);
    $sqlitePath = $root . '/data/app.sqlite';
    $xmlPath = $root . '/data/app.xml';
    $info = [
        'ok' => true,
        'configured' => $storage,
        'pdo_drivers' => PDO::getAvailableDrivers(),
        'pdo_sqlite' => $pdoSqlite,
        'files' => [
            'app.sqlite' => is_file($sqlitePath),
            'app.xml' => is_file($xmlPath),
            'schema.sql' => is_file($root . '/data/schema.sql'),
        ],
    ];
    try {
        $opened = openStore($root, $config, $storage);
        $info['engine'] = $opened['engine'];
        $info['using_sql'] = $opened['engine'] === 'sql';
        if (!empty($opened['fallback'])) {
            $info['fallback'] = $opened['fallback'];
        }
        if ($opened['engine'] === 'sql' && is_file($sqlitePath)) {
            $info['sqlite_bytes'] = filesize($sqlitePath);
        }
    } catch (Throwable $e) {
        $info['ok'] = false;
        $info['engine'] = null;
        $info['using_sql'] = false;
        $info['error'] = $e->getMessage();
    }
    $info['how_to_verify'] = [
        'GET /api/data?action=status — engine/using_sql/fallback',
        'SQL demos need the pdo_sqlite PHP extension (creates data/app.sqlite)',
        'If fallback is set, installs are on XML even when config storage=sql',
    ];

    return $info;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function handleInvoke(string $root, array $config, array $body): array
{
    $connector = (string) ($body['connector'] ?? '');
    $operation = (string) ($body['operation'] ?? '');
    $isMail = preg_match('/Outlook|SendEmail/i', $connector . $operation) === 1
        || preg_match('/SendEmailV2?/i', $operation) === 1;
    if (!$isMail) {
        return ['ok' => false, 'error' => 'unsupported invoke'];
    }

    $args = is_array($body['args'] ?? null) ? $body['args'] : [];
    $message = [
        'connector' => $connector !== '' ? $connector : 'Office365Outlook',
        'operation' => $operation !== '' ? $operation : 'SendEmailV2',
        'to' => (string) ($body['to'] ?? $args[0] ?? ''),
        'subject' => (string) ($body['subject'] ?? $args[1] ?? ''),
        'body' => (string) ($body['body'] ?? $args[2] ?? ''),
        'cc' => (string) ($body['cc'] ?? ''),
        'bcc' => (string) ($body['bcc'] ?? ''),
    ];
    // Prefer Microsoft Graph when the MVC Graph gateway is present.
    if (class_exists('GraphGateway', false)) {
        return (new GraphGateway($root, $config))->sendMail($message);
    }
    $gateway = new MailGateway($root, $config, MailAllowlist::fromConfig($config));

    return $gateway->send($message);
}
