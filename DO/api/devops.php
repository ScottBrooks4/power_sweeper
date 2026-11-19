<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ORG = 'VCDS-VCEMD';
const PROJECT = 'VASC';
const API = '7.1';
const CLIENT = '499b84ac-1321-427f-aa17-267ca6975798';
const TENANT = '325b4494-1587-40d5-bb31-8b660b7f1038';
const SCOPE_WORK = '499b84ac-1321-427f-aa17-267ca6975798/vso.work 499b84ac-1321-427f-aa17-267ca6975798/vso.machinegroup_manage offline_access';
const SCOPE = SCOPE_WORK . ' 499b84ac-1321-427f-aa17-267ca6975798/vso.graph 499b84ac-1321-427f-aa17-267ca6975798/vso.profile';

sessionStart();

function httpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function sessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/DO/',
        'secure' => httpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function rememberPat(string $pat): void
{
    $_SESSION['ado_pat'] = $pat;
    setcookie('vasc_ado', $pat, [
        'expires' => time() + 180 * 24 * 60 * 60,
        'path' => '/DO/',
        'secure' => httpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function forgetPat(): void
{
    unset($_SESSION['ado_pat'], $_SESSION['ado_write']);
    setcookie('vasc_ado', '', [
        'expires' => time() - 3600,
        'path' => '/DO/',
        'secure' => httpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function fail(int $code, string $message, bool $login = false): void
{
    if (!empty($GLOBALS['vascDailyQuiet'])) {
        throw new RuntimeException($message, $code);
    }
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message, 'login' => $login]);
    exit;
}

function token(): string
{
    $env = getenv('VASC_AZURE_DEVOPS_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $paths = [
        dirname(__DIR__, 4) . '/vasc-private/azure-devops.token',
        '/home/lulu/.config/vasc/azure-devops.token',
    ];
    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $text = trim((string) file_get_contents($path));
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function tokenRequest(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    return ['status' => $status, 'data' => is_array($data) ? $data : []];
}

function storeAccess(array $data): void
{
    $_SESSION['ado'] = [
        'access' => (string) $data['access_token'],
        'refresh' => (string) ($data['refresh_token'] ?? ($_SESSION['ado']['refresh'] ?? '')),
        'expires' => time() + (int) ($data['expires_in'] ?? 3600),
    ];
    unset($_SESSION['ado_device'], $_SESSION['ado_device_expires'], $_SESSION['ado_next']);
}

function bearer(bool $forceRefresh = false): string
{
    $ado = $_SESSION['ado'] ?? null;
    if (!is_array($ado) || ($ado['access'] ?? '') === '') {
        return '';
    }
    if (!$forceRefresh && time() < ((int) $ado['expires'] - 60)) {
        return (string) $ado['access'];
    }
    if (($ado['refresh'] ?? '') === '') {
        return $forceRefresh ? '' : (string) $ado['access'];
    }
    $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/token', [
        'client_id' => CLIENT,
        'grant_type' => 'refresh_token',
        'refresh_token' => $ado['refresh'],
        'scope' => SCOPE,
    ]);
    if ($res['status'] !== 200 || empty($res['data']['access_token'])) {
        $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/token', [
            'client_id' => CLIENT,
            'grant_type' => 'refresh_token',
            'refresh_token' => $ado['refresh'],
            'scope' => SCOPE_WORK,
        ]);
    }
    if ($res['status'] === 200 && !empty($res['data']['access_token'])) {
        storeAccess($res['data']);
        return (string) $_SESSION['ado']['access'];
    }
    $error = (string) ($res['data']['error'] ?? '');
    if ($error === 'invalid_grant' || $error === 'interaction_required') {
        unset($_SESSION['ado']);
        return '';
    }
    return $forceRefresh ? '' : (string) $ado['access'];
}

function tokenSource(): string
{
    $env = getenv('VASC_AZURE_DEVOPS_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return 'environment';
    }
    $paths = [
        dirname(__DIR__, 4) . '/vasc-private/azure-devops.token',
        '/home/lulu/.config/vasc/azure-devops.token',
    ];
    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }
        if (trim((string) file_get_contents($path)) !== '') {
            return 'file';
        }
    }
    return 'none';
}

function reach(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['ok' => $raw !== false && $status > 0, 'status' => $status, 'error' => $error];
}

function sessionPat(): string
{
    $pat = $_SESSION['ado_pat'] ?? '';
    if (is_string($pat) && trim($pat) !== '') {
        $pat = trim($pat);
        $saved = $_COOKIE['vasc_ado'] ?? '';
        if (!is_string($saved) || $saved !== $pat) {
            rememberPat($pat);
        }
        return $pat;
    }
    $saved = $_COOKIE['vasc_ado'] ?? '';
    if (!is_string($saved) || !preg_match('/^[A-Za-z0-9+\/_.=-]{20,256}$/', $saved)) {
        return '';
    }
    $_SESSION['ado_pat'] = $saved;
    return $saved;
}

function adoCall(string $method, string $url, ?array $body = null): array
{
    $pat = sessionPat();
    $access = '';
    if ($pat === '') {
        $access = bearer();
        if ($access === '') {
            $pat = token();
        }
    }
    if ($access === '' && $pat === '') {
        return ['ok' => false, 'status' => 401, 'error' => 'no-credential', 'data' => []];
    }
    $request = static function (string $accessToken, string $patToken) use ($method, $url, $body): array {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 40,
        ];
        if ($patToken !== '') {
            $options[CURLOPT_USERPWD] = ':' . $patToken;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $raw, true);
        return ['ok' => $raw !== false, 'status' => $status, 'error' => '', 'data' => is_array($data) ? $data : []];
    };
    $result = $request($access, $pat);
    if (!$result['ok']) {
        return ['ok' => false, 'status' => 0, 'error' => 'unreachable', 'data' => []];
    }
    if (($result['status'] === 401 || $result['status'] === 403) && $access !== '') {
        $refreshed = bearer(true);
        if ($refreshed !== '') {
            $result = $request($refreshed, '');
        }
    }
    if ($result['status'] !== 200) {
        unset($_SESSION['ado']);
        if (in_array($result['status'], [401, 403], true) && $access === '' && $pat !== '') {
            forgetPat();
        }
        $result['ok'] = false;
        $result['error'] = in_array($result['status'], [301, 302, 303, 401, 403], true)
            ? 'rejected'
            : 'http-' . $result['status'];
        return $result;
    }
    return $result;
}

function ado(string $method, string $url, ?array $body = null): array
{
    $result = adoCall($method, $url, $body);
    if ($result['error'] === 'no-credential') {
        fail(401, 'Sign in to Azure DevOps to load the VASC work items.', true);
    }
    if ($result['error'] === 'unreachable') {
        fail(502, 'Could not reach Azure DevOps.');
    }
    if ($result['error'] === 'rejected') {
        fail(401, 'Azure DevOps did not accept this sign-in. Sign in again.', true);
    }
    if (!$result['ok']) {
        fail(502, 'Azure DevOps returned an error for the VASC project.');
    }
    return $result['data'];
}

function step(string $id, string $state, string $label, string $detail): array
{
    return ['id' => $id, 'state' => $state, 'label' => $label, 'detail' => $detail];
}

function signedIn(): bool
{
    return bearer() !== '' || sessionPat() !== '' || token() !== '';
}

function num(array $fields, string $key): ?float
{
    if (!array_key_exists($key, $fields) || $fields[$key] === '' || $fields[$key] === null) {
        return null;
    }
    return (float) $fields[$key];
}

function workItemUrl(int $id): string
{
    return 'https://dev.azure.com/' . ORG . '/' . PROJECT . '/_workitems/edit/' . $id;
}

function playAddress(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
    $url = rtrim($url, ".,);]>\"'");
    if (!preg_match('~^https://apps\.powerapps\.com/play/e/([^/?]+)/a/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i', $url, $match)) {
        return '';
    }
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $keep = [];
    $tenant = (string) ($query['tenantId'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}$/i', $tenant)) {
        $keep['tenantId'] = strtolower($tenant);
    }
    $hint = (string) ($query['hint'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}$/i', $hint)) {
        $keep['hint'] = strtolower($hint);
    }
    $base = 'https://apps.powerapps.com/play/e/' . $match[1] . '/a/' . strtolower($match[2]);
    return $keep ? $base . '?' . http_build_query($keep) : $base;
}

function addressRank(string $nearby, string $title): int
{
    $text = strtolower($nearby . ' ' . $title);
    if (str_contains($text, 'production') || preg_match('/\bprod\b/', $text)) {
        return 3;
    }
    if (str_contains($text, 'deprec') || str_contains($text, 'do not use')) {
        return 0;
    }
    if (str_contains($text, 'uat')) {
        return 1;
    }
    return 2;
}

function rememberApp(string $url, string $title, int $workId, int $rank = 2): void
{
    $url = playAddress($url);
    if ($url === '' || str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '/apps')) {
        return;
    }
    if (!isset($GLOBALS['vascApps']) || !is_array($GLOBALS['vascApps'])) {
        $GLOBALS['vascApps'] = [];
    }
    $key = strtolower((string) strtok($url, '?'));
    if (!isset($GLOBALS['vascApps'][$key])) {
        $env = '';
        $app = '';
        if (preg_match('#/e/([^/]+)/a/([^/?]+)#', $url, $match)) {
            $env = $match[1];
            $app = $match[2];
        }
        $GLOBALS['vascApps'][$key] = [
            'title' => trim($title),
            'url' => $url,
            'workId' => $workId,
            'environment' => $env,
            'appId' => $app,
            'rank' => $rank,
        ];
        return;
    }
    if ($rank > (int) ($GLOBALS['vascApps'][$key]['rank'] ?? 0)) {
        $GLOBALS['vascApps'][$key]['rank'] = $rank;
    }
    if ($GLOBALS['vascApps'][$key]['title'] === '' && trim($title) !== '') {
        $GLOBALS['vascApps'][$key]['title'] = trim($title);
    }
    if ((int) $GLOBALS['vascApps'][$key]['workId'] === 0 && $workId > 0) {
        $GLOBALS['vascApps'][$key]['workId'] = $workId;
    }
}

function harvestAppText(string $text, string $title, int $workId): void
{
    if ($text === '' || !str_contains(strtolower($text), 'apps.powerapps.com/play/')) {
        return;
    }
    if (preg_match_all('#https://apps\.powerapps\.com/play/e/[^\s"\'<>]+#i', $text, $found, PREG_OFFSET_CAPTURE)) {
        foreach ($found[0] as [$url, $pos]) {
            $nearby = substr($text, max(0, (int) $pos - 48), 48);
            rememberApp($url, $title, $workId, addressRank($nearby, $title));
        }
    }
}

function appRows(): array
{
    $rows = array_values($GLOBALS['vascApps'] ?? []);
    usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title']));
    return $rows;
}

function titlesMatch(string $a, string $b): bool
{
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if (strcasecmp($a, $b) === 0) {
        return true;
    }
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long = $short === $a ? $b : $a;
    return (bool) preg_match('/\b' . preg_quote($short, '/') . '\b/i', $long);
}

function appUrlForTitle(string $title, array $apps): string
{
    $best = '';
    $score = 0;
    foreach ($apps as $app) {
        if (!is_array($app) || !titlesMatch($title, (string) ($app['title'] ?? ''))) {
            continue;
        }
        $url = (string) ($app['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $next = (int) ($app['rank'] ?? 2);
        if ($next > $score) {
            $score = $next;
            $best = $url;
        }
    }
    return $best;
}

function appsInSaved(array $saved): array
{
    $GLOBALS['vascApps'] = [];
    foreach ($saved['apps'] ?? [] as $row) {
        if (is_array($row)) {
            rememberApp((string) ($row['url'] ?? ''), (string) ($row['title'] ?? ''), (int) ($row['workId'] ?? 0), (int) ($row['rank'] ?? 2));
        }
    }
    foreach ($saved['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        harvestAppText((string) ($item['description'] ?? ''), (string) ($item['title'] ?? ''), (int) ($item['id'] ?? 0));
    }
    foreach ($saved['links'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        harvestAppText((string) ($row['url'] ?? ''), (string) ($row['title'] ?? ''), (int) (($row['items'][0]['id'] ?? 0)));
    }
    return appRows();
}

function powerPlatformGet(string $url): array
{
    $access = bearer();
    $pat = sessionPat();
    if ($access === '' && $pat === '') {
        $pat = token();
    }
    if ($access === '' && $pat === '') {
        return ['status' => 0, 'data' => null];
    }
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($access !== '') {
        $headers[] = 'Authorization: Bearer ' . $access;
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 6,
    ];
    if ($pat !== '') {
        $options[CURLOPT_USERPWD] = ':' . $pat;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'data' => is_array($data) ? $data : null];
}

function pullPowerApps(): string
{
    $envs = [
        '9ed70000-7006-e3b7-a4b4-c31cb53e5df0',
        '4f7cb393-36fb-efba-94fb-def26e69c3f5',
        '510961b1-a339-ed63-a313-2a1d6437d0f3',
    ];
    $notes = [];
    $found = 0;
    foreach ($envs as $env) {
        $url = 'https://api.powerapps.com/providers/Microsoft.PowerApps/scopes/admin/environments/'
            . rawurlencode($env) . '/apps?api-version=2016-11-01';
        $pages = 0;
        while ($url !== '' && $pages < 6) {
            $pages++;
            $res = powerPlatformGet($url);
            $status = (int) $res['status'];
            if ($status !== 200 || !is_array($res['data'])) {
                if ($status !== 200) {
                    $fallback = 'https://api.powerapps.com/providers/Microsoft.PowerApps/apps?api-version=2017-08-01&$filter='
                        . rawurlencode("environment eq '" . $env . "'");
                    $res = powerPlatformGet($fallback);
                    $status = (int) $res['status'];
                }
            }
            if ($status !== 200 || !is_array($res['data'])) {
                $notes[] = $status > 0 ? (string) $status : 'unreachable';
                break;
            }
            $notes[] = '200';
            foreach ($res['data']['value'] ?? [] as $app) {
                if (!is_array($app)) {
                    continue;
                }
                $props = is_array($app['properties'] ?? null) ? $app['properties'] : [];
                $name = trim((string) ($props['displayName'] ?? ''));
                $open = (string) ($props['appOpenUri'] ?? $props['appPlayUri'] ?? '');
                if ($open === '') {
                    $id = (string) ($app['name'] ?? '');
                    if (preg_match('/^[0-9a-f-]{36}$/i', $id)) {
                        $open = 'https://apps.powerapps.com/play/e/' . $env . '/a/' . strtolower($id) . '?tenantId=' . TENANT;
                    }
                }
                if ($name !== '' && $open !== '') {
                    rememberApp($open, $name, 0, addressRank('', $name));
                    $found++;
                }
            }
            $url = (string) ($res['data']['nextLink'] ?? $res['data']['@odata.nextLink'] ?? '');
            if (!str_starts_with($url, 'https://api.powerapps.com/')) {
                $url = '';
            }
        }
    }
    if ($found > 0) {
        return '';
    }
    $unique = array_values(array_unique($notes));
    if ($unique === ['401'] || $unique === ['403'] || $unique === ['401', '403'] || $unique === ['403', '401']) {
        return 'The Azure DevOps sign-in cannot read the Power Apps list.';
    }
    return 'The Power Apps list did not return addresses.';
}

function harvestPlayRelations(array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    foreach (array_chunk($ids, 200) as $chunk) {
        $url = 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems?ids=' . implode(',', $chunk)
            . '&fields=' . rawurlencode('System.Id,System.Title,System.Description')
            . '&$expand=relations&api-version=' . API;
        $res = adoCall('GET', $url);
        if (!$res['ok']) {
            break;
        }
        foreach ($res['data']['value'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = (string) ($item['fields']['System.Title'] ?? '');
            $id = (int) ($item['id'] ?? 0);
            harvestAppText((string) ($item['fields']['System.Description'] ?? ''), $title, $id);
            foreach ($item['relations'] ?? [] as $rel) {
                if (is_array($rel)) {
                    harvestAppText((string) ($rel['url'] ?? ''), $title, $id);
                }
            }
        }
    }
}

function shapeWorkItem(array $item, bool $fullText = false): array
{
    $fields = $item['fields'] ?? [];
    $id = (int) ($item['id'] ?? 0);
    harvestAppText((string) ($fields['System.Description'] ?? ''), (string) ($fields['System.Title'] ?? ''), $id);
    $needed = num($fields, 'Microsoft.VSTS.Scheduling.OriginalEstimate');
    $left = num($fields, 'Microsoft.VSTS.Scheduling.RemainingWork');
    $used = num($fields, 'Microsoft.VSTS.Scheduling.CompletedWork');
    if ($used === null && $needed !== null && $left !== null) {
        $used = $needed - $left;
    }
    return [
        'id' => $id,
        'title' => (string) ($fields['System.Title'] ?? ''),
        'type' => (string) ($fields['System.WorkItemType'] ?? ''),
        'state' => (string) ($fields['System.State'] ?? ''),
        'parentId' => isset($fields['System.Parent']) ? (int) $fields['System.Parent'] : null,
        'area' => (string) ($fields['System.AreaPath'] ?? ''),
        'hoursNeeded' => $needed,
        'hoursLeft' => $left,
        'hoursUsed' => $used,
        'description' => plainText((string) ($fields['System.Description'] ?? ''), $fullText ? 12000 : 500, $fullText),
        'assigned' => assignedName($fields['System.AssignedTo'] ?? null),
        'changed' => (string) ($fields['System.ChangedDate'] ?? ''),
        'changedBy' => assignedName($fields['System.ChangedBy'] ?? null),
        'url' => workItemUrl($id),
    ];
}

function plainText(string $html, int $limit = 500, bool $breaks = false): string
{
    if ($breaks) {
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n\n", $html) ?? $html;
        $html = preg_replace('/<li[^>]*>/i', '- ', $html) ?? $html;
    }
    $text = preg_replace('/<[^>]+>/', $breaks ? '' : ' ', $html) ?? '';
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($breaks) {
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
    } else {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
    if ($limit > 0 && strlen($text) > $limit) {
        $text = substr($text, 0, $limit);
    }
    return $text;
}

function assignedName(mixed $value): string
{
    if (is_array($value)) {
        return (string) ($value['displayName'] ?? $value['uniqueName'] ?? '');
    }
    return is_string($value) ? $value : '';
}

function fetchWorkItems(array $ids, bool $detail = false, bool $assigned = false, bool $fullText = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $names = [
        'System.Id',
        'System.Title',
        'System.WorkItemType',
        'System.State',
        'System.Parent',
        'System.AreaPath',
        'Microsoft.VSTS.Scheduling.OriginalEstimate',
        'Microsoft.VSTS.Scheduling.RemainingWork',
        'Microsoft.VSTS.Scheduling.CompletedWork',
        'System.ChangedDate',
    ];
    if ($detail) {
        $names[] = 'System.Description';
        $names[] = 'System.AssignedTo';
    } elseif ($assigned) {
        $names[] = 'System.AssignedTo';
    }
    $fields = implode(',', $names);
    $items = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $url = 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems?ids=' . implode(',', $chunk)
            . '&fields=' . rawurlencode($fields) . '&api-version=' . API;
        $data = ado('GET', $url);
        $relations = [];
        if ($detail) {
            $linked = ado('GET', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems?ids=' . implode(',', $chunk) . '&$expand=relations&api-version=' . API);
            foreach ($linked['value'] ?? [] as $row) {
                $relations[(int) ($row['id'] ?? 0)] = $row['relations'] ?? [];
            }
        }
        foreach ($data['value'] ?? [] as $item) {
            if ($detail) {
                $item['relations'] = $relations[(int) ($item['id'] ?? 0)] ?? [];
            }
            rememberAssignee($item['fields']['System.AssignedTo'] ?? null);
            $items[] = shapeWorkItem($item, $fullText);
            if ($detail && isset($GLOBALS['vascLinkBag']) && is_array($GLOBALS['vascLinkBag'])) {
                $fieldsRow = $item['fields'] ?? [];
                $workTitle = (string) ($fieldsRow['System.Title'] ?? '');
                $workType = (string) ($fieldsRow['System.WorkItemType'] ?? '');
                $workId = (int) ($item['id'] ?? 0);
                $project = projectFromArea((string) ($fieldsRow['System.AreaPath'] ?? ''));
                foreach ($item['relations'] ?? [] as $rel) {
                    if (!is_array($rel)) {
                        continue;
                    }
                    harvestAppText((string) ($rel['url'] ?? ''), $workTitle, $workId);
                    if (($rel['rel'] ?? '') !== 'Hyperlink') {
                        continue;
                    }
                    $comment = trim((string) ($rel['attributes']['comment'] ?? ''));
                    rememberLink($GLOBALS['vascLinkBag'], (string) ($rel['url'] ?? ''), $comment !== '' ? $comment : $workTitle, $project, 'devops', $workId, $workTitle, $workType, '');
                }
                $description = (string) ($fieldsRow['System.Description'] ?? '');
                if ($description !== '' && preg_match_all('#https://[^\\s<>"\']+#i', $description, $found)) {
                    foreach ($found[0] as $raw) {
                        rememberLink($GLOBALS['vascLinkBag'], $raw, $workTitle, $project, 'devops', $workId, $workTitle, $workType, '');
                    }
                }
            }
        }
    }
    return $items;
}

function dailyPath(): string
{
    return dirname(__DIR__) . '/data/daily.json';
}

function readDaily(): ?array
{
    $path = dailyPath();
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function dailyFresh(?array $data): bool
{
    return is_array($data) && !empty($data['saved']) && (time() - (int) $data['saved']) < 86400;
}

function emptyDaily(): array
{
    return ['saved' => 0, 'note' => '', 'items' => [], 'people' => [], 'groups' => []];
}

function publicItem(array $item): array
{
    return [
        'id' => (int) ($item['id'] ?? 0),
        'title' => (string) ($item['title'] ?? ''),
        'type' => (string) ($item['type'] ?? ''),
        'state' => (string) ($item['state'] ?? ''),
        'parentId' => $item['parentId'] ?? null,
        'area' => (string) ($item['area'] ?? ''),
        'changed' => (string) ($item['changed'] ?? ''),
        'url' => (string) ($item['url'] ?? ''),
    ];
}

function rememberAssignee(mixed $value): void
{
    if (!is_array($value)) {
        return;
    }
    $name = assignedName($value);
    $url = portraitUrl($value);
    if ($name === '' || $url === '') {
        return;
    }
    $key = personKey($name);
    if (!isset($GLOBALS['vascAssignees']) || !is_array($GLOBALS['vascAssignees'])) {
        $GLOBALS['vascAssignees'] = [];
    }
    if (isset($GLOBALS['vascAssignees'][$key])) {
        return;
    }
    $GLOBALS['vascAssignees'][$key] = [
        'key' => $key,
        'name' => $name,
        'email' => (string) ($value['uniqueName'] ?? ''),
        'team' => '',
        'avatarUrl' => $url,
    ];
}

function beginLinkBag(): void
{
    $GLOBALS['vascLinkBag'] = [];
    foreach (workspaceLinks() as [$title, $url]) {
        rememberLink($GLOBALS['vascLinkBag'], $url, $title, PROJECT, 'workspace', 0, '', '', '');
    }
    foreach (intakeLinks() as [$caseId, $caseTitle, $project, $url, $title]) {
        rememberLink($GLOBALS['vascLinkBag'], $url, $title, $project, 'intake', 0, '', '', (string) $caseId);
        $key = strtolower($project) . '|' . strtolower((string) strtok(cleanWorkUrl($url), '#'));
        if (isset($GLOBALS['vascLinkBag'][$key])) {
            $GLOBALS['vascLinkBag'][$key]['caseTitle'] = $caseTitle;
        }
    }
}

function packLinks(array $items): array
{
    $bag = is_array($GLOBALS['vascLinkBag'] ?? null) ? $GLOBALS['vascLinkBag'] : [];
    $byId = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $byId[(int) ($item['id'] ?? 0)] = $item;
        }
    }
    $regrouped = [];
    foreach ($bag as $row) {
        if (!is_array($row)) {
            continue;
        }
        $project = (string) ($row['project'] ?? PROJECT);
        $workId = (int) (($row['items'][0]['id'] ?? 0));
        $seen = [];
        while ($workId > 0 && empty($seen[$workId]) && isset($byId[$workId])) {
            $seen[$workId] = true;
            $node = $byId[$workId];
            $title = (string) ($node['title'] ?? '');
            if (strcasecmp((string) ($node['type'] ?? ''), 'Projects') === 0 && !preg_match('/^VASC\b/i', $title)) {
                $project = $title;
                break;
            }
            $workId = (int) ($node['parentId'] ?? 0);
        }
        $row['project'] = $project;
        $key = strtolower($project) . '|' . strtolower((string) strtok((string) ($row['url'] ?? ''), '#'));
        if (!isset($regrouped[$key])) {
            $regrouped[$key] = $row;
        } else {
            foreach ($row['items'] ?? [] as $item) {
                if (count($regrouped[$key]['items']) >= 8) {
                    break;
                }
                $regrouped[$key]['items'][] = $item;
            }
            foreach ($row['cases'] ?? [] as $caseId) {
                if (!in_array($caseId, $regrouped[$key]['cases'], true)) {
                    $regrouped[$key]['cases'][] = $caseId;
                }
            }
        }
    }
    $links = array_values($regrouped);
    usort($links, static function (array $a, array $b): int {
        $byProject = strcasecmp((string) $a['project'], (string) $b['project']);
        return $byProject !== 0 ? $byProject : strcasecmp((string) $a['title'], (string) $b['title']);
    });
    return $links;
}

function finishedLinkFile(): array
{
    $path = dirname(__DIR__) . '/data/links.json';
    if (!is_readable($path)) {
        return [];
    }
    $cached = json_decode((string) file_get_contents($path), true);
    if (!is_array($cached) || empty($cached['done']) || !is_array($cached['links'] ?? null)) {
        return [];
    }
    return array_values(array_filter($cached['links'], 'is_array'));
}

function ensureStoredLinks(array $saved): array
{
    if (!empty($saved['links']) && is_array($saved['links'])) {
        return $saved;
    }
    $links = finishedLinkFile();
    if (!$links) {
        $saved['links'] = catalogLinks();
        return $saved;
    }
    $saved['links'] = $links;
    if (!empty($saved['saved'])) {
        writeDaily($saved);
    }
    return $saved;
}

function collectDaily(): array
{
    $GLOBALS['vascAssignees'] = [];
    $GLOBALS['vascApps'] = [];
    beginLinkBag();
    $query = ado('POST', 'https://dev.azure.com/' . ORG . '/' . rawurlencode(PROJECT) . '/_apis/wit/wiql?api-version=' . API, [
        'query' => "SELECT [System.Id] FROM WorkItems WHERE [System.TeamProject] = '" . PROJECT . "' AND [System.WorkItemType] NOT IN ('Test Case', 'Test Suite', 'Test Plan', 'Shared Steps', 'Shared Parameter', 'Feedback Request', 'Code Review Request', 'Code Review Response') AND [System.State] <> 'Removed'",
    ]);
    $ids = [];
    foreach ($query['workItems'] ?? [] as $row) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
    $items = fetchWorkItems($ids, true, true);
    $people = [];
    $note = '';
    $teams = adoCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/projects/' . rawurlencode(PROJECT) . '/teams?api-version=' . API);
    if (!$teams['ok']) {
        $note = 'Azure DevOps did not return the project team list, so this is everyone with work assigned.';
    } else {
        foreach ($teams['data']['value'] ?? [] as $team) {
            $teamId = (string) ($team['id'] ?? '');
            $teamName = (string) ($team['name'] ?? PROJECT);
            if ($teamId === '') {
                continue;
            }
            $members = adoCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/projects/' . rawurlencode(PROJECT) . '/teams/' . rawurlencode($teamId) . '/members?api-version=' . API);
            foreach ($members['data']['value'] ?? [] as $member) {
                $identity = $member['identity'] ?? [];
                $name = (string) ($identity['displayName'] ?? '');
                if ($name === '') {
                    continue;
                }
                $key = personKey($name);
                if (isset($people[$key]) && $people[$key]['name'] !== $name) {
                    $key .= '-' . substr(sha1($name), 0, 4);
                }
                if (!isset($people[$key])) {
                    $people[$key] = [
                        'key' => $key,
                        'name' => $name,
                        'email' => (string) ($identity['uniqueName'] ?? ''),
                        'team' => $teamName,
                        'avatarUrl' => portraitUrl($identity),
                    ];
                }
            }
        }
    }
    $groups = adoCall('GET', 'https://dev.azure.com/' . ORG . '/' . rawurlencode(PROJECT) . '/_apis/distributedtask/deploymentgroups?api-version=' . API);
    $machines = [];
    foreach (($groups['ok'] ? ($groups['data']['value'] ?? []) : []) as $group) {
        $gid = (int) ($group['id'] ?? 0);
        if (!$gid) {
            continue;
        }
        $listed = adoCall('GET', 'https://dev.azure.com/' . ORG . '/' . rawurlencode(PROJECT) . '/_apis/distributedtask/deploymentgroups/' . $gid . '/machines?api-version=' . API);
        $rows = [];
        foreach (($listed['ok'] ? ($listed['data']['value'] ?? []) : []) as $machine) {
            $agent = $machine['agent'] ?? [];
            $tags = $machine['tags'] ?? [];
            $rows[] = [
                'name' => (string) ($agent['name'] ?? $machine['name'] ?? ('Machine ' . ($machine['id'] ?? ''))),
                'status' => (string) ($agent['status'] ?? (!empty($machine['online']) ? 'online' : 'offline')),
                'tags' => array_values(array_map('strval', is_array($tags) ? $tags : [])),
            ];
        }
        $machines[] = [
            'id' => $gid,
            'name' => (string) ($group['name'] ?? 'Machine group'),
            'url' => 'https://dev.azure.com/' . ORG . '/' . PROJECT . '/_machinegroup?view=MachineGroupView&deploymentGroupId=' . $gid,
            'machines' => $rows,
        ];
    }
    foreach ($GLOBALS['vascAssignees'] ?? [] as $key => $person) {
        if (!isset($people[$key])) {
            $people[$key] = $person;
        }
    }
    $list = array_values($people);
    usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    pullPowerApps();
    return [
        'saved' => time(),
        'note' => $note,
        'items' => $items,
        'people' => $list,
        'groups' => $machines,
        'apps' => appRows(),
        'links' => packLinks($items),
    ];
}

function releaseSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function writeDaily(array $built): void
{
    $path = dailyPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $path . '.tmp';
    $json = json_encode($built, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || $json === '') {
        return;
    }
    file_put_contents($tmp, $json);
    rename($tmp, $path);
}

function projectOrgHint(array $item): string
{
    $type = strtolower((string) ($item['type'] ?? ''));
    if (!str_contains($type, 'project') && $type !== 'program') {
        return '';
    }
    require_once __DIR__ . '/org_list.php';
    $title = strtoupper((string) ($item['title'] ?? ''));
    $rules = [
        'VCR' => '9667',
        'VCN' => '9667',
        'HERMES' => '9667',
        'TAXI CHIT' => '9648',
        'TRAINING REQUEST' => '9593',
        'BUSINESS CASE' => '9561',
        'BCA' => '9561',
        'EC REQUEST' => '9536',
        'PROGRAM COORDINATION' => '9536',
        'MP FORM' => '7394',
        'CF MP' => '7394',
        'DPSIM' => '9600',
        'DGDS' => '9593',
        'CRES' => '9577',
        'C RES' => '9577',
    ];
    foreach ($rules as $needle => $id) {
        if ($needle !== '' && str_contains($title, $needle)) {
            return $id;
        }
    }
    $compactTitle = preg_replace('/[^A-Z0-9]+/', '', $title) ?? '';
    $best = '';
    $bestLen = 0;
    foreach (orgRows() as $org) {
        $short = strtoupper((string) ($org['short'] ?? ''));
        if (in_array($short, ['VCDS', 'DND/CAF', 'CAF', 'DND'], true)) {
            continue;
        }
        $compact = preg_replace('/[^A-Z0-9]+/', '', $short) ?? '';
        if (strlen($compact) < 4) {
            continue;
        }
        if (str_contains($compactTitle, $compact) && strlen($compact) > $bestLen) {
            $best = (string) $org['id'];
            $bestLen = strlen($compact);
        }
    }
    return $best;
}

function chartItems(array $saved): array
{
    $items = [];
    foreach ($saved['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $hint = projectOrgHint($item);
        unset($item['description']);
        if ($hint !== '') {
            $item['orgHint'] = $hint;
        }
        $items[] = $item;
    }
    return $items;
}

function chartPeople(array $saved): array
{
    $people = [];
    foreach ($saved['people'] ?? [] as $person) {
        if (!is_array($person)) {
            continue;
        }
        $name = (string) ($person['name'] ?? '');
        $key = (string) ($person['key'] ?? '');
        if ($name === '' || $key === '') {
            continue;
        }
        $email = strtolower(trim((string) ($person['email'] ?? '')));
        $people[] = [
            'key' => $key,
            'name' => $name,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'avatar' => (string) ($person['avatarUrl'] ?? '') !== '',
        ];
    }
    return $people;
}

function dailyNote(array $saved, bool $refreshing): string
{
    if (empty($saved['saved'])) {
        return $refreshing
            ? 'Today’s list is being prepared. Reload in a minute.'
            : 'Nothing is saved for today yet.';
    }
    $when = gmdate('Y-m-d H:i', (int) $saved['saved']) . ' UTC';
    if ($refreshing) {
        return 'Saved from ' . $when . '. A newer one is being saved for the next visit.';
    }
    return 'Saved from ' . $when . '. Sync reads Azure DevOps again.';
}

function pageDaily(): array
{
    $saved = readDaily();
    $refreshing = !dailyFresh($saved) && signedIn();
    if (!is_array($saved)) {
        $saved = emptyDaily();
    }
    return [$saved, $refreshing];
}

function respondDaily(array $payload, bool $refresh): void
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false) {
        fail(500, 'What was saved could not be read.');
    }
    releaseSession();
    if (!$refresh) {
        echo $body;
        exit;
    }
    ignore_user_abort(true);
    @ini_set('zlib.output_compression', '0');
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    refreshDailyInBackground();
    exit;
}

function refreshDailyInBackground(): void
{
    $path = dailyPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return;
    }
    if (dailyFresh(readDaily())) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return;
    }
    set_time_limit(300);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        sessionStart();
    }
    bearer();
    releaseSession();
    $GLOBALS['vascDailyQuiet'] = true;
    try {
        $built = collectDaily();
        writeDaily($built);
        storePortraits($built['people'] ?? []);
    } catch (Throwable $error) {
        // Keep what was already saved. The next visit tries again.
    }
    $GLOBALS['vascDailyQuiet'] = false;
    flock($lock, LOCK_UN);
    fclose($lock);
}

function ensureDaily(bool $allowFetch, bool $force = false): array
{
    $saved = readDaily();
    if (!$force && dailyFresh($saved)) {
        return $saved;
    }
    if (!$allowFetch || !signedIn()) {
        return is_array($saved) ? $saved : emptyDaily();
    }
    $path = dailyPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return is_array($saved) ? $saved : emptyDaily();
    }
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) {
        return is_array($saved) ? $saved : emptyDaily();
    }
    flock($lock, LOCK_EX);
    $again = readDaily();
    if (!$force && dailyFresh($again)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return $again;
    }
    bearer();
    releaseSession();
    $built = collectDaily();
    writeDaily($built);
    storePortraits($built['people'] ?? []);
    flock($lock, LOCK_UN);
    fclose($lock);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        sessionStart();
    }
    return $built;
}

function pulseFromDaily(array $saved): array
{
    $people = [];
    foreach ($saved['people'] ?? [] as $person) {
        if (!is_array($person)) {
            continue;
        }
        $name = (string) ($person['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $people[strtolower($name)] = [
            'name' => $name,
            'email' => (string) ($person['email'] ?? ''),
            'team' => (string) ($person['team'] ?? ''),
            'items' => [],
            'supportHours' => 0,
            'supportCount' => 0,
        ];
    }
    $closed = ['Removed', 'Closed', 'Done', 'Completed', 'Resolved'];
    foreach ($saved['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = (string) ($item['assigned'] ?? '');
        $state = (string) ($item['state'] ?? '');
        if ($name === '' || str_starts_with($name, '[')) {
            continue;
        }
        $key = strtolower($name);
        if (!isset($people[$key])) {
            $people[$key] = ['name' => $name, 'email' => '', 'team' => PROJECT, 'items' => [], 'supportHours' => 0, 'supportCount' => 0];
        }
        if (strcasecmp((string) ($item['type'] ?? ''), 'Client Support') === 0) {
            $people[$key]['supportHours'] = round(((float) $people[$key]['supportHours']) + (float) ($item['hoursUsed'] ?? 0), 2);
            $people[$key]['supportCount'] = (int) $people[$key]['supportCount'] + 1;
        }
        if (in_array($state, $closed, true)) {
            continue;
        }
        $people[$key]['items'][] = [
            'id' => $item['id'] ?? 0,
            'title' => (string) ($item['title'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'state' => $state,
            'hoursLeft' => $item['hoursLeft'] ?? null,
            'url' => (string) ($item['url'] ?? ''),
        ];
    }
    $list = array_values($people);
    usort($list, static function (array $a, array $b): int {
        $byCount = count($b['items']) <=> count($a['items']);
        return $byCount !== 0 ? $byCount : strcasecmp($a['name'], $b['name']);
    });
    return $list;
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'workitem') {
    if (!signedIn()) {
        fail(401, 'Sign in to read a work item.', true);
    }
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^\d{1,8}$/', $id)) {
        fail(400, 'Work item id is not valid.');
    }
    $fresh = (string) ($_GET['fresh'] ?? '') === '1';
    if (!$fresh) {
        $saved = ensureDaily(false);
        foreach ($saved['items'] ?? [] as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === (int) $id) {
                echo json_encode(['ok' => true, 'cached' => true, 'item' => $item]);
                exit;
            }
        }
    }
    $items = fetchWorkItems([(int) $id], true, true, true);
    if (!$items) {
        fail(404, 'That work item was not returned.');
    }
    echo json_encode(['ok' => true, 'item' => $items[0]]);
    exit;
}

if ($action === 'workitems') {
    if (!signedIn()) {
        fail(401, 'Sign in to read work items.', true);
    }
    $raw = (string) ($_GET['ids'] ?? '');
    $parts = $raw === '' ? [] : explode(',', $raw);
    if (count($parts) > 50) {
        fail(400, 'Too many work item ids.');
    }
    foreach ($parts as $part) {
        if (!preg_match('/^\d{1,8}$/', $part)) {
            fail(400, 'Work item id is not valid.');
        }
    }
    $wanted = array_map('intval', $parts);
    $saved = ensureDaily(false);
    $found = [];
    foreach ($saved['items'] ?? [] as $item) {
        if (is_array($item) && in_array((int) ($item['id'] ?? 0), $wanted, true)) {
            $found[] = $item;
        }
    }
    echo json_encode(['ok' => true, 'items' => $found]);
    exit;
}

if ($action === 'tasks') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the VASC work items.', true);
    }
    [$saved, $refreshing] = pageDaily();
    $types = ['Task', 'User Story', 'Product Backlog Item', 'Epic'];
    $items = array_values(array_filter($saved['items'] ?? [], static function ($item) use ($types): bool {
        return is_array($item) && in_array((string) ($item['type'] ?? ''), $types, true);
    }));
    usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['changed'] ?? ''), (string) ($a['changed'] ?? '')));
    respondDaily(['ok' => true, 'cached' => true, 'items' => array_slice($items, 0, 100)], $refreshing);
}

if ($action === 'machines') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the machine group.', true);
    }
    [$saved, $refreshing] = pageDaily();
    respondDaily(['ok' => true, 'cached' => true, 'groups' => $saved['groups'] ?? []], $refreshing);
}

if ($action === 'login') {
    $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/devicecode', [
        'client_id' => CLIENT,
        'scope' => SCOPE,
    ]);
    $userCode = (string) ($res['data']['user_code'] ?? '');
    $deviceCode = (string) ($res['data']['device_code'] ?? '');
    if ($res['status'] !== 200 || !preg_match('/^[A-Z0-9]{4,16}$/', $userCode) || $deviceCode === '') {
        fail(502, 'Azure sign-in did not start. Try again.');
    }
    $interval = max(5, (int) ($res['data']['interval'] ?? 5));
    $_SESSION['ado_device'] = $deviceCode;
    $_SESSION['ado_device_expires'] = time() + (int) ($res['data']['expires_in'] ?? 900);
    $_SESSION['ado_next'] = time() + $interval;
    $_SESSION['ado_interval'] = $interval;
    echo json_encode([
        'ok' => true,
        'user_code' => $userCode,
        'url' => 'https://login.microsoftonline.com/common/oauth2/deviceauth',
        'interval' => $interval,
    ]);
    exit;
}

if ($action === 'loginpoll') {
    $device = (string) ($_SESSION['ado_device'] ?? '');
    if ($device === '') {
        if (signedIn()) {
            echo json_encode(['ok' => true, 'signedIn' => true]);
            exit;
        }
        fail(400, 'Sign-in has not started.', true);
    }
    if (time() > (int) ($_SESSION['ado_device_expires'] ?? 0)) {
        unset($_SESSION['ado_device'], $_SESSION['ado_device_expires'], $_SESSION['ado_next']);
        fail(401, 'That sign-in expired. Start it again.', true);
    }
    if (time() < (int) ($_SESSION['ado_next'] ?? 0)) {
        echo json_encode(['ok' => true, 'pending' => true]);
        exit;
    }
    $interval = max(5, (int) ($_SESSION['ado_interval'] ?? 5));
    $_SESSION['ado_next'] = time() + $interval;
    $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/token', [
        'client_id' => CLIENT,
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device,
    ]);
    if ($res['status'] === 200 && !empty($res['data']['access_token'])) {
        storeAccess($res['data']);
        echo json_encode(['ok' => true, 'signedIn' => true]);
        exit;
    }
    $error = (string) ($res['data']['error'] ?? '');
    if ($error === 'authorization_pending') {
        echo json_encode(['ok' => true, 'pending' => true]);
        exit;
    }
    if ($error === 'slow_down') {
        $_SESSION['ado_interval'] = $interval + 5;
        $_SESSION['ado_next'] = time() + $interval + 5;
        echo json_encode(['ok' => true, 'pending' => true]);
        exit;
    }
    unset($_SESSION['ado_device'], $_SESSION['ado_device_expires'], $_SESSION['ado_next']);
    fail(401, 'Azure DevOps did not accept this sign-in. Sign in again.', true);
}

if ($action === 'usepat') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the Azure DevOps token in a POST.');
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $pat = trim((string) (is_array($body) ? ($body['pat'] ?? '') : ''));
    if (!preg_match('/^[A-Za-z0-9+\/_.=-]{20,256}$/', $pat)) {
        fail(400, 'That does not look like an Azure DevOps token.');
    }
    $_SESSION['ado_pat'] = $pat;
    unset($_SESSION['ado'], $_SESSION['ado_device'], $_SESSION['ado_device_expires'], $_SESSION['ado_next'], $_SESSION['ado_write']);
    $check = adoCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/projects/' . rawurlencode(PROJECT) . '?api-version=' . API);
    $name = (string) ($check['data']['name'] ?? '');
    if (!$check['ok'] || strcasecmp($name, PROJECT) !== 0) {
        forgetPat();
        fail(401, 'Azure DevOps did not accept that token for the VASC project.', true);
    }
    rememberPat($pat);
    echo json_encode(['ok' => true, 'signedIn' => true, 'saved' => true]);
    exit;
}

if ($action === 'session') {
    $ado = $_SESSION['ado'] ?? null;
    $oauth = is_array($ado) && (((string) ($ado['access'] ?? '')) !== '' || ((string) ($ado['refresh'] ?? '')) !== '');
    echo json_encode(['ok' => true, 'signedIn' => sessionPat() !== '' || $oauth]);
    exit;
}

if ($action === 'forget') {
    unset($_SESSION['ado'], $_SESSION['ado_device'], $_SESSION['ado_device_expires'], $_SESSION['ado_next']);
    forgetPat();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'status') {
    $steps = [];
    $steps[] = step('api', 'ok', 'This page', 'The browser reached the VASC connection check.');
    $sessionOn = session_status() === PHP_SESSION_ACTIVE && session_id() !== '';
    $steps[] = step(
        'session',
        $sessionOn ? 'ok' : 'stop',
        'Server session',
        $sessionOn ? 'A session is open for this browser. Sign-in is stored here, not in the page.' : 'PHP did not open a session, so a sign-in cannot be kept.'
    );

    $loginReach = reach('https://login.microsoftonline.com/' . TENANT . '/v2.0/.well-known/openid-configuration');
    $steps[] = step(
        'microsoft',
        $loginReach['ok'] && $loginReach['status'] < 400 ? 'ok' : 'stop',
        'Microsoft login',
        $loginReach['ok'] && $loginReach['status'] < 400
            ? 'This server can reach the DND sign-in endpoint.'
            : 'This server could not reach Microsoft login' . ($loginReach['status'] ? ' (HTTP ' . $loginReach['status'] . ').' : '.')
    );

    $ado = $_SESSION['ado'] ?? null;
    $hasAccess = is_array($ado) && ($ado['access'] ?? '') !== '';
    $hasRefresh = is_array($ado) && ($ado['refresh'] ?? '') !== '';
    $expiresIn = $hasAccess ? max(0, (int) $ado['expires'] - time()) : null;
    $pending = (string) ($_SESSION['ado_device'] ?? '') !== '';
    $pendingFor = $pending ? max(0, (int) ($_SESSION['ado_device_expires'] ?? 0) - time()) : 0;
    $source = tokenSource();

    if ($hasAccess) {
        $credState = 'ok';
        $credDetail = 'This browser’s sign-in is on the server'
            . ($expiresIn !== null ? ', for about ' . (int) ceil($expiresIn / 60) . ' min' : '')
            . ($hasRefresh ? ', and can refresh.' : '.');
    } elseif (sessionPat() !== '') {
        $credState = 'ok';
        $credDetail = 'The Azure DevOps token from this browser is saved. It stays until you sign out.';
    } elseif ($source === 'environment') {
        $credState = 'ok';
        $credDetail = 'A server environment token is set. The chart can read DevOps without a browser sign-in.';
    } elseif ($source === 'file') {
        $credState = 'ok';
        $credDetail = 'A server token file is set. The chart can read DevOps without a browser sign-in.';
    } else {
        $credState = 'stop';
        $credDetail = 'No Azure DevOps token yet. The code window is Microsoft Visual Studio Online on another device, and DND blocks it.';
    }
    $steps[] = step('credential', $credState, 'Credential', $credDetail);

    $host = reach('https://dev.azure.com/' . ORG . '/_apis/connectionData?api-version=7.1-preview');
    $steps[] = step(
        'host',
        $host['ok'] ? 'ok' : 'stop',
        'Azure DevOps',
        $host['ok']
            ? 'This server can reach dev.azure.com for ' . ORG . '.'
            : 'This server could not reach dev.azure.com.'
    );

    $account = null;
    $project = null;
    $sample = [];
    $where = 'needs-sign-in';
    if (!$sessionOn) {
        $where = 'session';
    } elseif (!$loginReach['ok'] || !$host['ok']) {
        $where = 'offline';
    } elseif ($credState === 'stop') {
        $where = 'needs-sign-in';
    } else {
        $conn = adoCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/connectionData?api-version=7.1-preview');
        if (!$conn['ok']) {
            $where = $conn['error'] === 'rejected' ? 'rejected' : 'devops-error';
            $steps[] = step('account', 'stop', 'Signed-in account', 'Azure DevOps did not accept the credential' . ($conn['status'] ? ' (HTTP ' . $conn['status'] . ').' : '.'));
        } else {
            $user = $conn['data']['authenticatedUser'] ?? [];
            $name = (string) ($user['providerDisplayName'] ?? $user['customDisplayName'] ?? '');
            $account = $name !== '' ? ['name' => $name] : null;
            $steps[] = step('account', 'ok', 'Signed-in account', $name !== '' ? $name : 'The token was accepted. No display name was returned.');
            $proj = adoCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/projects/' . rawurlencode(PROJECT) . '?api-version=' . API);
            if (!$proj['ok']) {
                $where = 'project-denied';
                $steps[] = step('project', 'stop', 'VASC project', 'The account can reach Azure DevOps, but not the ' . PROJECT . ' project' . ($proj['status'] ? ' (HTTP ' . $proj['status'] . ').' : '.'));
            } else {
                $project = [
                    'name' => (string) ($proj['data']['name'] ?? PROJECT),
                    'state' => (string) ($proj['data']['state'] ?? ''),
                ];
                $steps[] = step('project', 'ok', 'VASC project', $project['name'] . ' on ' . ORG . ($project['state'] !== '' ? ' (' . $project['state'] . ').' : '.'));
                $wiql = adoCall('POST', 'https://dev.azure.com/' . ORG . '/' . rawurlencode(PROJECT) . '/_apis/wit/wiql?$top=3&api-version=' . API, [
                    'query' => "SELECT [System.Id] FROM WorkItems WHERE [System.TeamProject] = '" . PROJECT . "' ORDER BY [System.ChangedDate] DESC",
                ]);
                if (!$wiql['ok']) {
                    $where = 'work-denied';
                    $steps[] = step('work', 'stop', 'Work items', 'The project opened, but the work-item query did not' . ($wiql['status'] ? ' (HTTP ' . $wiql['status'] . ').' : '.'));
                } else {
                    $ids = [];
                    foreach (array_slice($wiql['data']['workItems'] ?? [], 0, 3) as $row) {
                        $id = (int) ($row['id'] ?? 0);
                        if ($id > 0) {
                            $ids[] = $id;
                        }
                    }
                    if (!$ids) {
                        $where = 'connected';
                        $steps[] = step('work', 'ok', 'Work items', 'The query ran and returned no work items.');
                    } else {
                        $fields = 'System.Id,System.Title,System.WorkItemType,System.State';
                        $batch = adoCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems?ids=' . implode(',', $ids) . '&fields=' . rawurlencode($fields) . '&api-version=' . API);
                        if (!$batch['ok']) {
                            $where = 'work-denied';
                            $steps[] = step('work', 'stop', 'Work items', 'The query found ids, but the item read did not' . ($batch['status'] ? ' (HTTP ' . $batch['status'] . ').' : '.'));
                        } else {
                            foreach ($batch['data']['value'] ?? [] as $item) {
                                $fields = $item['fields'] ?? [];
                                $sample[] = [
                                    'id' => (int) ($item['id'] ?? 0),
                                    'title' => (string) ($fields['System.Title'] ?? ''),
                                    'type' => (string) ($fields['System.WorkItemType'] ?? ''),
                                    'state' => (string) ($fields['System.State'] ?? ''),
                                ];
                            }
                            $where = 'connected';
                            $steps[] = step('work', 'ok', 'Work items', $sample ? 'Recent items came back from the VASC project.' : 'The query ran and returned no work items.');
                        }
                    }
                }
            }
        }
    }

    $summary = [
        'connected' => 'Connected. The chart can load VASC epics, screens, and tasks.',
        'waiting' => 'Waiting on the Microsoft window. Finish that sign-in and check again.',
        'needs-sign-in' => 'Not connected. Paste an Azure DevOps token. The code sign-in is blocked.',
        'rejected' => 'A credential is present, and Azure DevOps rejected it.',
        'project-denied' => 'Signed in, but this account cannot open the VASC project.',
        'work-denied' => 'The VASC project opened, but work items did not.',
        'devops-error' => 'Azure DevOps did not answer the signed-in request.',
        'offline' => 'This server cannot reach Microsoft or Azure DevOps right now.',
        'session' => 'The server session did not start, so sign-in cannot be stored.',
    ][$where] ?? 'The connection check did not finish.';

    echo json_encode([
        'ok' => true,
        'where' => $where,
        'summary' => $summary,
        'org' => ORG,
        'project' => PROJECT,
        'steps' => $steps,
        'account' => $account,
        'projectInfo' => $project,
        'sample' => $sample,
    ]);
    exit;
}

if ($action === 'tree') {
    if (chartRequestKey() === 'new') {
        serveNewChart((string) ($_GET['fresh'] ?? '') === '1');
    }
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the VASC work items.', true);
    }
    $fresh = (string) ($_GET['fresh'] ?? '') === '1';
    if ($fresh) {
        set_time_limit(300);
        $saved = ensureDaily(true, true);
        respondDaily([
            'ok' => true,
            'cached' => false,
            'age' => !empty($saved['saved']) ? time() - (int) $saved['saved'] : null,
            'note' => 'Read from Azure DevOps just now.',
            'items' => chartItems($saved),
            'people' => chartPeople($saved),
        ], false);
    }
    [$saved, $refreshing] = pageDaily();
    respondDaily([
        'ok' => true,
        'cached' => true,
        'age' => !empty($saved['saved']) ? time() - (int) $saved['saved'] : null,
        'note' => dailyNote($saved, $refreshing),
        'items' => chartItems($saved),
        'people' => chartPeople($saved),
    ], $refreshing);
}

if ($action === 'board') {
    if (!signedIn()) {
        fail(401, 'Sign in to see the project list.', true);
    }
    $saved = ensureDaily(false);
    $publicTypes = ['Epic', 'Feature', 'Projects'];
    $items = [];
    foreach ($saved['items'] ?? [] as $item) {
        if (is_array($item) && in_array((string) ($item['type'] ?? ''), $publicTypes, true)) {
            $items[] = publicItem($item);
        }
    }
    echo json_encode([
        'ok' => true,
        'saved' => (int) ($saved['saved'] ?? 0),
        'items' => $items,
    ]);
    exit;
}

function peopleUnderSaved(int $id, array $byId, array $children): int
{
    $names = [];
    $stack = [$id];
    $seen = [];
    while ($stack) {
        $current = array_pop($stack);
        if (isset($seen[$current])) {
            continue;
        }
        $seen[$current] = true;
        $name = (string) ($byId[$current]['assigned'] ?? '');
        if ($name !== '' && !str_starts_with($name, '[')) {
            $names[strtolower($name)] = true;
        }
        foreach ($children[$current] ?? [] as $child) {
            $stack[] = $child;
        }
    }
    return count($names);
}

if ($action === 'timeline') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the timeline.', true);
    }
    [$saved, $refreshing] = pageDaily();
    $rows = [];
    foreach ($saved['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $changed = (string) ($item['changed'] ?? '');
        $rows[] = [
            'id' => (int) ($item['id'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'state' => (string) ($item['state'] ?? ''),
            'changed' => strlen($changed) >= 10 ? substr($changed, 0, 10) : '',
            'hours' => (float) ($item['hoursUsed'] ?? 0),
            'used' => (float) ($item['hoursUsed'] ?? 0),
            'needed' => (float) ($item['hoursNeeded'] ?? 0),
            'left' => isset($item['hoursLeft']) && $item['hoursLeft'] !== null ? (float) $item['hoursLeft'] : null,
            'parent' => (int) ($item['parentId'] ?? 0),
            'text' => mb_substr((string) ($item['description'] ?? ''), 0, 180),
        ];
    }
    respondDaily(['ok' => true, 'cached' => true, 'note' => dailyNote($saved, $refreshing), 'items' => $rows], $refreshing);
}

if ($action === 'portfolio') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the VASC work items.', true);
    }
    [$saved, $refreshing] = pageDaily();
    $byId = [];
    $children = [];
    foreach ($saved['items'] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int) ($item['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $byId[$id] = $item;
        $parent = (int) ($item['parentId'] ?? 0);
        if ($parent > 0) {
            $children[$parent][] = $id;
        }
    }
    $items = [];
    foreach ($byId as $id => $item) {
        if (!in_array((string) ($item['type'] ?? ''), ['Epic', 'Feature'], true)) {
            continue;
        }
        $item['people'] = peopleUnderSaved($id, $byId, $children);
        $items[] = $item;
    }
    usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['changed'] ?? ''), (string) ($a['changed'] ?? '')));
    respondDaily(['ok' => true, 'cached' => true, 'items' => $items], $refreshing);
}

if ($action === 'pulse') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the VASC work items.', true);
    }
    [$saved, $refreshing] = pageDaily();
    respondDaily([
        'ok' => true,
        'cached' => true,
        'note' => dailyNote($saved, $refreshing),
        'people' => pulseFromDaily($saved),
    ], $refreshing);
}

function metricsProjectType(string $type): bool
{
    $name = strtolower($type);
    return str_contains($name, 'project') || $name === 'program';
}

function metricsDescendantHas(int $id, string $field, array $items, array $children, array &$memo, array &$seen): bool
{
    if (isset($memo[$field][$id])) {
        return $memo[$field][$id];
    }
    if (isset($seen[$id])) {
        return false;
    }
    $seen[$id] = true;
    foreach ($children[$id] ?? [] as $kid) {
        if ((float) ($items[$kid][$field] ?? 0) > 0 || metricsDescendantHas($kid, $field, $items, $children, $memo, $seen)) {
            unset($seen[$id]);
            return $memo[$field][$id] = true;
        }
    }
    unset($seen[$id]);
    return $memo[$field][$id] = false;
}

function metricsGroup(array $item, array $items): array
{
    $seen = [];
    $current = $item;
    while (is_array($current) && !isset($seen[(int) $current['id']])) {
        $seen[(int) $current['id']] = true;
        if (metricsProjectType((string) ($current['type'] ?? ''))) {
            return ['id' => (int) $current['id'], 'title' => (string) ($current['title'] ?? 'Project')];
        }
        $parent = (int) ($current['parentId'] ?? 0);
        $current = $items[$parent] ?? null;
    }
    $area = (string) ($item['area'] ?? '');
    if (preg_match('/\\\\Projects\\\\([^\\\\]+)/', $area, $match)) {
        return ['id' => 0, 'title' => $match[1]];
    }
    $parts = array_values(array_filter(explode('\\', $area), static fn (string $part): bool => $part !== ''));
    $last = $parts[count($parts) - 1] ?? '';
    if ($last !== '' && strcasecmp($last, 'VASC') !== 0) {
        return ['id' => 0, 'title' => $last];
    }
    return ['id' => 0, 'title' => 'Other'];
}

function metricsReport(array $saved): array
{
    $items = [];
    foreach ($saved['items'] ?? [] as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }
        $items[(int) $item['id']] = $item;
    }
    $children = [];
    foreach ($items as $id => $item) {
        $parent = (int) ($item['parentId'] ?? 0);
        if ($parent && isset($items[$parent])) {
            $children[$parent][] = $id;
        }
    }
    $done = ['Closed' => true, 'Completed' => true, 'Resolved' => true, 'Removed' => true, 'Done' => true];
    $totals = ['needed' => 0.0, 'left' => 0.0, 'used' => 0.0, 'open' => 0];
    $byType = [];
    $byState = [];
    $byProject = [];
    $memo = [];
    foreach ($items as $id => $item) {
        $seen = [];
        $need = metricsDescendantHas($id, 'hoursNeeded', $items, $children, $memo, $seen) ? 0.0 : (float) ($item['hoursNeeded'] ?? 0);
        $seen = [];
        $left = metricsDescendantHas($id, 'hoursLeft', $items, $children, $memo, $seen) ? 0.0 : (float) ($item['hoursLeft'] ?? 0);
        $seen = [];
        $used = metricsDescendantHas($id, 'hoursUsed', $items, $children, $memo, $seen) ? 0.0 : (float) ($item['hoursUsed'] ?? 0);
        $totals['needed'] += $need;
        $totals['left'] += $left;
        $totals['used'] += $used;
        $state = (string) ($item['state'] ?? '');
        if ($state === '' || !isset($done[$state])) {
            $totals['open'] += 1;
        }
        $type = (string) ($item['type'] ?? 'Work item');
        if (!isset($byType[$type])) {
            $byType[$type] = ['title' => $type, 'count' => 0, 'left' => 0.0];
        }
        $byType[$type]['count'] += 1;
        $byType[$type]['left'] += $left;
        if (!isset($byState[$state])) {
            $byState[$state] = ['title' => $state !== '' ? $state : 'Blank', 'count' => 0, 'left' => 0.0];
        }
        $byState[$state]['count'] += 1;
        $byState[$state]['left'] += $left;
        if ($need == 0.0 && $left == 0.0 && $used == 0.0) {
            continue;
        }
        $group = metricsGroup($item, $items);
        $key = strtolower($group['title']);
        if (!isset($byProject[$key])) {
            $byProject[$key] = ['id' => $group['id'], 'title' => $group['title'], 'needed' => 0.0, 'left' => 0.0, 'used' => 0.0];
        } elseif ($byProject[$key]['id'] === 0 && $group['id'] > 0) {
            $byProject[$key]['id'] = $group['id'];
            $byProject[$key]['title'] = $group['title'];
        }
        $byProject[$key]['needed'] += $need;
        $byProject[$key]['left'] += $left;
        $byProject[$key]['used'] += $used;
    }
    $allProjects = array_values(array_filter($byProject, static fn (array $row): bool => $row['left'] > 0 || $row['needed'] > 0 || $row['used'] > 0));
    usort($allProjects, static fn (array $a, array $b): int => ($b['left'] <=> $a['left']) ?: ($b['used'] <=> $a['used']));
    $projects = array_values(array_filter($byProject, static fn (array $row): bool => $row['left'] > 0));
    usort($projects, static fn (array $a, array $b): int => $b['left'] <=> $a['left']);
    $types = array_values(array_filter($byType, static fn (array $row): bool => $row['left'] > 0));
    usort($types, static fn (array $a, array $b): int => $b['left'] <=> $a['left']);
    $states = array_values(array_filter($byState, static fn (array $row): bool => $row['left'] > 0));
    usort($states, static fn (array $a, array $b): int => $b['left'] <=> $a['left']);
    $apps = appsInSaved($saved);
    foreach ($projects as &$row) {
        $row['url'] = appUrlForTitle((string) $row['title'], $apps);
    }
    unset($row);
    $round = static function (array $row): array {
        foreach ($row as $key => $value) {
            if (is_float($value)) {
                $row[$key] = round($value, 2);
            }
        }
        return $row;
    };
    // SOP-001 6.1.2 asks for time to be logged daily. Compare against the day the
    // file was saved, not today, so a stale file reports its own day.
    $savedAt = (int) ($saved['saved'] ?? 0);
    $day = $savedAt > 0 ? gmdate('Y-m-d', $savedAt) : '';
    $openWork = 0;
    $noTime = 0;
    foreach ($items as $item) {
        $state = (string) ($item['state'] ?? '');
        if ($state !== '' && isset($done[$state])) {
            continue;
        }
        $openWork += 1;
        if ($day !== '' && substr((string) ($item['changed'] ?? ''), 0, 10) !== $day) {
            $noTime += 1;
        }
    }
    return [
        'totals' => $round($totals),
        'projects' => array_map($round, $projects),
        'allProjects' => array_map($round, $allProjects),
        'types' => array_map($round, $types),
        'states' => array_map($round, $states),
        'sop' => ['day' => $day, 'openWork' => $openWork, 'noTimeToday' => $noTime]
    ];
}

if ($action === 'metrics') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the VASC hours.', true);
    }
    $fresh = (string) ($_GET['fresh'] ?? '') === '1';
    if ($fresh) {
        set_time_limit(300);
        $saved = ensureDaily(true, true);
        respondDaily([
            'ok' => true,
            'cached' => false,
            'note' => 'Read from Azure DevOps just now.',
            'report' => metricsReport($saved),
        ], false);
    }
    [$saved, $refreshing] = pageDaily();
    respondDaily([
        'ok' => true,
        'cached' => true,
        'note' => dailyNote($saved, $refreshing),
        'report' => metricsReport($saved),
    ], $refreshing);
}

if ($action === 'apps') {
    $fresh = (string) ($_GET['fresh'] ?? '') === '1';
    if ($fresh && !signedIn()) {
        fail(401, 'Sign in to Azure DevOps to sync the app list.', true);
    }
    $saved = readDaily();
    if (!is_array($saved)) {
        fail(404, 'DevOps has not been saved yet.');
    }
    $saved = ensureStoredLinks($saved);
    releaseSession();
    $note = (string) ($saved['appsNote'] ?? '');
    if ($fresh) {
        set_time_limit(60);
        appsInSaved($saved);
        $powerNote = pullPowerApps();
        $saved['apps'] = appRows();
        $count = count($saved['apps']);
        $note = $count . ' app ' . ($count === 1 ? 'address' : 'addresses');
        if ($powerNote !== '') {
            $note .= '. ' . $powerNote;
        }
        $saved['appsNote'] = $note;
        writeDaily($saved);
    }
    respondDaily([
        'ok' => true,
        'cached' => !$fresh,
        'note' => $note,
        'apps' => $fresh ? ($saved['apps'] ?? []) : appsInSaved($saved),
    ], false);
}

function personKey(string $name): string
{
    $key = strtolower(trim($name));
    $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?? '';
    $key = trim((string) $key, '-');
    return $key !== '' ? $key : 'person';
}

function portraitUrl(array $identity): string
{
    $image = (string) ($identity['imageUrl'] ?? '');
    $host = strtolower((string) parse_url($image, PHP_URL_HOST));
    $allowed = $host === 'dev.azure.com' || str_ends_with($host, '.dev.azure.com') || str_ends_with($host, '.visualstudio.com');
    if (str_starts_with($image, 'https://') && $allowed) {
        return $image;
    }
    $descriptor = (string) ($identity['descriptor'] ?? '');
    if (preg_match('/^[A-Za-z0-9+\\/=._-]{8,300}$/', $descriptor)) {
        return 'https://dev.azure.com/' . ORG . '/_apis/GraphProfile/MemberAvatars/' . rawurlencode($descriptor) . '?api-version=7.1-preview.1';
    }
    $id = (string) ($identity['id'] ?? '');
    if (preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
        return 'https://dev.azure.com/' . ORG . '/_api/_common/identityImage?id=' . rawurlencode($id);
    }
    return '';
}

function publishPeople(array $people): array
{
    $public = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $public[] = [
            'key' => (string) ($person['key'] ?? ''),
            'name' => (string) ($person['name'] ?? ''),
            'email' => (string) ($person['email'] ?? ''),
            'team' => (string) ($person['team'] ?? ''),
            'avatar' => (string) ($person['avatarUrl'] ?? '') !== '',
        ];
    }
    return $public;
}

function portraitFile(string $key): string
{
    return dirname(__DIR__) . '/data/portraits/' . $key;
}

function storePortraits(array $people): void
{
    $dir = dirname(__DIR__) . '/data/portraits';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $key = (string) ($person['key'] ?? '');
        $url = (string) ($person['avatarUrl'] ?? '');
        if (!preg_match('/^[a-z0-9-]{1,80}$/', $key) || !str_starts_with($url, 'https://')) {
            continue;
        }
        $file = portraitFile($key);
        if (is_readable($file) && (int) filesize($file) > 32 && time() - (int) filemtime($file) < 86400) {
            continue;
        }
        $image = fetchPortrait($url);
        if ($image['type'] === '' || strlen($image['body']) < 32) {
            continue;
        }
        file_put_contents($file, $image['body']);
        file_put_contents($file . '.type', $image['type']);
    }
}

function portraitHostAllowed(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }
    if (in_array($host, ['dev.azure.com', 'vssps.dev.azure.com', 'vsrm.dev.azure.com'], true)) {
        return true;
    }
    return str_ends_with($host, '.dev.azure.com')
        || str_ends_with($host, '.visualstudio.com')
        || str_ends_with($host, '.vsassets.io')
        || str_ends_with($host, '.blob.core.windows.net');
}

function imageTypeOf(string $body): string
{
    if (str_starts_with($body, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($body, "\x89PNG\x0D\x0A\x1A\x0A")) {
        return 'image/png';
    }
    if (str_starts_with($body, 'GIF8')) {
        return 'image/gif';
    }
    if (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return '';
}

function adoFetch(string $url, string $accept): array
{
    $pat = sessionPat();
    $access = '';
    if ($pat === '') {
        $access = bearer();
        if ($access === '') {
            $pat = token();
        }
    }
    $current = $url;
    for ($hop = 0; $hop < 4; $hop++) {
        if (!portraitHostAllowed($current)) {
            return ['status' => 0, 'body' => ''];
        }
        $host = strtolower((string) parse_url($current, PHP_URL_HOST));
        $sendAuth = $host === 'dev.azure.com' || $host === 'vssps.dev.azure.com' || str_ends_with($host, '.dev.azure.com') || str_ends_with($host, '.visualstudio.com');
        $ch = curl_init($current);
        $headers = ['Accept: ' . $accept];
        if ($sendAuth) {
            $headers[] = 'X-TFS-FedAuthRedirect: Suppress';
        }
        if ($sendAuth && $access !== '') {
            $headers[] = 'Authorization: Bearer ' . $access;
        } elseif ($sendAuth && $pat !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode(':' . $pat);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if (in_array($status, [301, 302, 303, 307, 308], true) && $redirect !== '') {
            $current = $redirect;
            continue;
        }
        $body = is_string($raw) ? $raw : '';
        if (strlen($body) > 2000000) {
            $body = '';
        }
        return ['status' => $status, 'body' => $body];
    }
    return ['status' => 0, 'body' => ''];
}

function avatarBytes(string $body, int $depth = 0): array
{
    if ($depth > 3) {
        return ['body' => '', 'type' => ''];
    }
    $type = imageTypeOf($body);
    if ($type !== '') {
        return ['body' => $body, 'type' => $type];
    }
    $trim = ltrim($body);
    if ($trim !== '' && strlen($trim) < 500000 && (str_starts_with($trim, '<svg') || str_starts_with($trim, '<?xml')) && str_contains($trim, '<svg')) {
        return ['body' => $trim, 'type' => 'image/svg+xml'];
    }
    $json = json_decode($trim, true);
    if (!is_array($json)) {
        return ['body' => '', 'type' => ''];
    }
    $value = $json['value'] ?? null;
    if (is_array($value)) {
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $nested = avatarBytes((string) json_encode(['value' => $row['value'] ?? '']), $depth + 1);
            if ($nested['type'] !== '') {
                return $nested;
            }
        }
        return ['body' => '', 'type' => ''];
    }
    if (!is_string($value) || $value === '') {
        return ['body' => '', 'type' => ''];
    }
    if (str_starts_with($value, 'https://') && portraitHostAllowed($value)) {
        $fetched = adoFetch($value, 'image/jpeg, image/png, image/webp, image/gif, image/svg+xml, */*');
        return avatarBytes($fetched['body'], $depth + 1);
    }
    if (str_starts_with($value, 'data:image')) {
        $comma = strpos($value, ',');
        $bin = $comma !== false ? base64_decode(substr($value, $comma + 1), true) : false;
        return is_string($bin) ? avatarBytes($bin, $depth + 1) : ['body' => '', 'type' => ''];
    }
    $packed = (string) preg_replace('/\s+/', '', $value);
    $packed = strtr($packed, '-_', '+/');
    $pad = strlen($packed) % 4;
    if ($pad !== 0) {
        $packed .= str_repeat('=', 4 - $pad);
    }
    $bin = base64_decode($packed, true);
    return is_string($bin) && $bin !== '' ? avatarBytes($bin, $depth + 1) : ['body' => '', 'type' => ''];
}

function descriptorFromPortraitUrl(string $url): string
{
    if (preg_match('#/MemberAvatars/([^/?]+)#', $url, $match)) {
        $descriptor = rawurldecode($match[1]);
        if (preg_match('/^[A-Za-z0-9+\\/=._-]{8,300}$/', $descriptor)) {
            return $descriptor;
        }
    }
    return '';
}

function identityFromPortraitUrl(string $url): string
{
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $id = (string) ($query['id'] ?? '');
    return preg_match('/^[0-9a-fA-F-]{36}$/', $id) ? $id : '';
}

function descriptorForIdentity(string $id): string
{
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
        return '';
    }
    $url = 'https://vssps.dev.azure.com/' . ORG . '/_apis/graph/descriptors/' . rawurlencode($id) . '?api-version=7.1-preview.1';
    $fetched = adoFetch($url, 'application/json');
    $data = json_decode($fetched['body'], true);
    $value = is_array($data) ? (string) ($data['value'] ?? '') : '';
    return preg_match('/^[A-Za-z0-9+\\/=._-]{8,300}$/', $value) ? $value : '';
}

function graphAvatar(string $descriptor): array
{
    if (!preg_match('/^[A-Za-z0-9+\\/=._-]{8,300}$/', $descriptor)) {
        return ['status' => 0, 'body' => '', 'type' => ''];
    }
    $url = 'https://vssps.dev.azure.com/' . ORG . '/_apis/graph/avatars/' . rawurlencode($descriptor) . '?api-version=7.1-preview.1';
    $fetched = adoFetch($url, 'application/json');
    $image = avatarBytes($fetched['body']);
    return ['status' => $fetched['status'], 'body' => $image['body'], 'type' => $image['type']];
}

function fetchPortrait(string $url): array
{
    $empty = ['status' => 0, 'body' => '', 'type' => ''];
    if ($url === '' || !portraitHostAllowed($url)) {
        return $empty;
    }
    $fetched = adoFetch($url, 'image/jpeg, image/png, image/webp, image/gif, image/svg+xml, application/json, */*');
    $image = avatarBytes($fetched['body']);
    if ($image['type'] !== '' && strlen($image['body']) > 32) {
        return ['status' => $fetched['status'], 'body' => $image['body'], 'type' => $image['type']];
    }
    $descriptor = descriptorFromPortraitUrl($url);
    if ($descriptor === '') {
        $id = identityFromPortraitUrl($url);
        if ($id !== '') {
            $descriptor = descriptorForIdentity($id);
        }
    }
    if ($descriptor !== '') {
        $graph = graphAvatar($descriptor);
        if ($graph['type'] !== '' && strlen($graph['body']) > 32) {
            return $graph;
        }
    }
    return ['status' => $fetched['status'], 'body' => '', 'type' => ''];
}

function portraitUrlForKey(string $key): string
{
    $saved = readDaily();
    foreach ($saved['people'] ?? [] as $person) {
        if (!is_array($person) || (string) ($person['key'] ?? '') !== $key) {
            continue;
        }
        $url = (string) ($person['avatarUrl'] ?? '');
        return portraitHostAllowed($url) ? $url : '';
    }
    return '';
}

function identityPortraitUrl(string $name): string
{
    $short = trim((string) preg_split('/\s+VCDS\b|\s+Defence365\b|@/i', $name)[0]);
    if ($short === '' || strlen($short) > 120) {
        return '';
    }
    $url = 'https://vssps.dev.azure.com/' . ORG . '/_apis/identities?searchFilter=General&filterValue='
        . rawurlencode($short) . '&queryMembership=None&api-version=7.1';
    $access = bearer();
    $pat = sessionPat();
    if ($access === '' && $pat === '') {
        $pat = token();
    }
    if ($access === '' && $pat === '') {
        return '';
    }
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($access !== '') {
        $headers[] = 'Authorization: Bearer ' . $access;
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 4,
    ];
    if ($pat !== '') {
        $options[CURLOPT_USERPWD] = ':' . $pat;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        return '';
    }
    $data = json_decode((string) $raw, true);
    $want = strtolower($name);
    $shortWant = strtolower($short);
    foreach ($data['value'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $display = strtolower((string) ($row['providerDisplayName'] ?? $row['displayName'] ?? ''));
        if ($display === '' || (!str_contains($want, $display) && !str_contains($display, $shortWant))) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return 'https://dev.azure.com/' . ORG . '/_api/_common/identityImage?id=' . rawurlencode($id);
        }
    }
    return '';
}

function savePortrait(string $key, string $body, string $type): bool
{
    $dir = dirname(__DIR__) . '/data/portraits';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $file = portraitFile($key);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $body) === false) {
        return false;
    }
    rename($tmp, $file);
    file_put_contents($file . '.type', $type);
    $miss = $file . '.miss';
    if (is_file($miss)) {
        unlink($miss);
    }
    return true;
}

if ($action === 'people') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load people and projects.', true);
    }
    $fresh = (string) ($_GET['fresh'] ?? '') === '1';
    if ($fresh) {
        set_time_limit(300);
        $saved = ensureDaily(true, true);
        $detail = (string) ($saved['note'] ?? '');
        respondDaily([
            'ok' => true,
            'cached' => false,
            'age' => !empty($saved['saved']) ? time() - (int) $saved['saved'] : null,
            'note' => $detail !== '' ? 'Read from Azure DevOps just now. ' . $detail : 'Read from Azure DevOps just now.',
            'people' => publishPeople($saved['people'] ?? []),
            'items' => chartItems($saved),
        ], false);
    }
    [$saved, $refreshing] = pageDaily();
    $detail = (string) ($saved['note'] ?? '');
    $note = dailyNote($saved, $refreshing);
    respondDaily([
        'ok' => true,
        'cached' => true,
        'age' => !empty($saved['saved']) ? time() - (int) $saved['saved'] : null,
        'note' => $detail !== '' ? $note . ' ' . $detail : $note,
        'people' => publishPeople($saved['people'] ?? []),
        'items' => chartItems($saved),
    ], $refreshing);
}

if ($action === 'portrait') {
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load portraits.', true);
    }
    $key = (string) ($_GET['key'] ?? '');
    $name = trim((string) ($_GET['name'] ?? ''));
    if (!preg_match('/^[a-z0-9-]{1,80}$/', $key)) {
        fail(404, 'That portrait is not on file.');
    }
    if ($name !== '' && personKey($name) !== $key) {
        $name = '';
    }
    releaseSession();
    $file = portraitFile($key);
    $typeFile = $file . '.type';
    $miss = $file . '.miss';
    if (is_readable($file) && is_readable($typeFile) && (int) filesize($file) > 32) {
        header('Content-Type: ' . trim((string) file_get_contents($typeFile)));
        header('Cache-Control: private, max-age=86400');
        readfile($file);
        exit;
    }
    $missTtl = is_file($miss) && trim((string) file_get_contents($miss)) === '401' ? 60 : 3600;
    if (is_file($miss) && filemtime($miss) >= filemtime(__FILE__) && time() - (int) filemtime($miss) < $missTtl) {
        fail(404, 'That portrait is not on file.');
    }
    $url = portraitUrlForKey($key);
    if ($url === '' && $name !== '') {
        $url = identityPortraitUrl($name);
    }
    $image = $url !== '' ? fetchPortrait($url) : ['status' => 0, 'body' => '', 'type' => ''];
    if ($image['type'] === '' || strlen($image['body']) < 32 || !savePortrait($key, $image['body'], $image['type'])) {
        $dir = dirname($file);
        if (is_dir($dir)) {
            file_put_contents($miss, (string) ($image['status'] ?? 0));
        }
        fail(404, 'That portrait is not on file.');
    }
    header('Content-Type: ' . $image['type']);
    header('Cache-Control: private, max-age=86400');
    echo $image['body'];
    exit;
}

function linkKind(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (str_contains($host, 'sharepoint.com')) {
        return 'sharepoint';
    }
    if (str_contains($host, 'powerapps.com') || str_contains($host, 'powerautomate.com') || str_contains($host, 'flow.microsoft.com')) {
        return 'powerapps';
    }
    if (str_contains($host, 'powerbi.com')) {
        return 'powerbi';
    }
    if ($host === 'dev.azure.com' || str_ends_with($host, '.visualstudio.com')) {
        return 'devops';
    }
    if (str_contains($host, 'azure.com') || str_contains($host, 'azurewebsites.net')) {
        return 'azure';
    }
    if (str_contains($host, 'teams.microsoft.com')) {
        return 'teams';
    }
    if (str_contains($host, 'mail-dnd.forces.mil.ca') || str_contains($host, 'outlook.com') || str_contains($host, 'outlook.office')) {
        return 'outlook';
    }
    return 'other';
}

function cleanWorkUrl(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
    $url = rtrim($url, ".,);]>\"'");
    if ($url === 'power_sweeper' || $url === 'power_sweeper/') {
        return 'power_sweeper/';
    }
    if (!preg_match('#^https://#i', $url)) {
        return '';
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (str_contains($host, 'safelink')) {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $inner = is_string($query['url'] ?? null) ? $query['url'] : '';
        return str_starts_with($inner, 'https://') ? cleanWorkUrl($inner) : '';
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $noise = ['images.ecomm.microsoft.com', 'mucp.api.account.microsoft.com'];
    if ($host === '' || in_array($host, $noise, true) || str_contains($host, 'login.microsoftonline.com') || str_contains($path, '/_apis/')) {
        return '';
    }
    return strlen($url) > 800 ? substr($url, 0, 800) : $url;
}

function projectFromArea(string $area): string
{
    $parts = array_values(array_filter(explode('\\', $area), static fn (string $part): bool => $part !== ''));
    if (count($parts) >= 3 && strcasecmp($parts[1], 'Projects') === 0) {
        return $parts[2];
    }
    if (count($parts) >= 2) {
        return $parts[count($parts) - 1];
    }
    return $parts[0] ?? PROJECT;
}

function rememberLink(array &$bag, string $url, string $title, string $project, string $source, int $workId, string $workTitle, string $workType, string $caseId): void
{
    $url = cleanWorkUrl($url);
    if ($url === '') {
        return;
    }
    $key = strtolower($project) . '|' . strtolower((string) strtok($url, '#'));
    if (!isset($bag[$key])) {
        $bag[$key] = [
            'kind' => linkKind($url),
            'url' => $url,
            'title' => $title !== '' ? $title : $url,
            'project' => $project,
            'source' => $source,
            'items' => [],
            'cases' => [],
        ];
    } elseif ($bag[$key]['title'] === $bag[$key]['url'] && $title !== '') {
        $bag[$key]['title'] = $title;
    }
    if ($workId > 0 && count($bag[$key]['items']) < 8) {
        foreach ($bag[$key]['items'] as $item) {
            if ((int) $item['id'] === $workId) {
                $workId = 0;
                break;
            }
        }
        if ($workId > 0) {
            $bag[$key]['items'][] = ['id' => $workId, 'title' => $workTitle, 'type' => $workType];
        }
    }
    if ($caseId !== '' && !in_array($caseId, $bag[$key]['cases'], true) && count($bag[$key]['cases']) < 6) {
        $bag[$key]['cases'][] = $caseId;
    }
}

function intakeLinks(): array
{
    return [
        ['213', 'Exporting DRMIS Information to Use in Power BI', 'DRMIS', 'https://dev.azure.com/VCDS-VCEMD/VASC/_workitems/edit/6569', 'Work item 6569'],
        ['176', 'Need PowerApps for automation and CRM', 'CRM', 'https://dev.azure.com/VCDS-VCEMD/VASC/_workitems/edit/5351', 'Work item 5351'],
        ['165', 'Payroll Tracking Automation', 'Payroll', 'https://dev.azure.com/VCDS-VCEMD/VASC/_workitems/edit/5537', 'Work item 5537'],
        ['119', 'OHS SharePoint Site swag', 'CFSG(O-G)', 'https://018gc.sharepoint.com/sites/ORG-0002-001-0002/SitePages/CFSGOG%20External%20Home.aspx', 'CFSG(O-G) SharePoint'],
        ['111', 'Movement Leave Report', 'Movement Leave', 'https://018gc.sharepoint.com/:x:/s/ORG-0150-0003/EUtS2K7LdoNLkru11BVk_pUBmsFVJOcdgR7GpPiCElwbQQ?e=YChegx', 'Movement leave tracker'],
        ['80', 'Power App - User Entry MP Form', 'CF MP Gp', 'https://018gc.sharepoint.com/sites/ORG-9507-000-000/Lists/MPAPCCSO%20Tracker%20Refferals/AllItems.aspx', 'MP tracker list'],
        ['129', 'CDLS(W) banking files', 'CDLS(W)', 'https://018gc-my.sharepoint.com/:f:/g/personal/keith_richards_ecn_forces_gc_ca/IgCovPL-0-T1To2Nkgo5hVM1AcnXifSdZvLwDHu-3I4F3mU?e=bLbc5j', 'Banking files'],

        ['211', 'Outcan URS Workflow', 'Outcan URS', 'https://teams.microsoft.com/l/meetingrecap?driveId=b%21McLckLtQdEWJLMvaYIrW2_TATEppZAZNqGdubFkjzZZwPV5m01OURrnzVmvhHzrU&driveItemId=0145EMFGUXGLKOBNY5C5C2BZD7WANLPRFT&sitePath=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fikma_omar_ecn_forces_gc_ca%2FIQCXMtTgtx0XRaDkf7Aat8SzAYNjfjZtIsjRGMPzPMBfun4&fileUrl=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fikma_omar_ecn_forces_gc_ca%2FIQCXMtTgtx0XRaDkf7Aat8SzAYNjfjZtIsjRGMPzPMBfun4&iCalUid=040000008200E00074C5B7101A82E008000000006D01708CF02ADD01000000000000000010000000F3DF9BAE0DE39849818755F184B7B14C&threadId=19%3Ameeting_ZjFhM2QwMWItOTcyYi00MzAxLWEzYjEtZmE0Y2Q0ZWE4YjM1%40thread.v2&organizerId=a342975a-62c1-402a-b0b4-dce63141c5dd&tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&callId=81e73877-8f70-41d9-bc30-512a79049b26&threadType=Meeting&meetingType=Scheduled&subType=RecapSharingLink_RecapChiclet', 'Meeting recap'],
        ['207', 'DCOS Pers Workflow Management', 'DCOS Pers', 'https://teams.microsoft.com/l/meetingrecap?driveId=b%21wyn3AyrBjEmxeaxQAcqNBdgZ6s_4T3VAria0w5wpTe4egPU4GR8oQqHv29It7ElT&driveItemId=01UDN7TR2KA5ESA3D45BHKRCRV5YBPXVW4&sitePath=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fmariella_azar_ecn_forces_gc_ca%2FIQBKB0kgbHzoTqiKNe4C-9bcAXBE_5uU4WFwZ5RT42oYkws&fileUrl=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fmariella_azar_ecn_forces_gc_ca%2FIQBKB0kgbHzoTqiKNe4C-9bcAXBE_5uU4WFwZ5RT42oYkws&iCalUid=040000008200E00074C5B7101A82E0080000000071FDCE13841FDD0100000000000000001000000040DB83502BED0446BD6F46464BF08B7C&threadId=19%3Ameeting_ODJjN2FlYjItMmNmNC00MWE3LTg5YTktZTNlNzNkNDU0MzNk%40thread.v2&organizerId=a9be8dc4-820d-4d19-ae8b-6f10392d8f44&tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&callId=e9790912-00b2-4b9f-a482-fe34649a00b3&threadType=Meeting&meetingType=Scheduled&subType=RecapSharingLink_RecapChiclet', 'Meeting recap'],
        ['185', 'Promotional items form', 'Promotional items', 'https://teams.microsoft.com/l/meetingrecap?driveId=b%21McLckLtQdEWJLMvaYIrW2_TATEppZAZNqGdubFkjzZZwPV5m01OURrnzVmvhHzrU&driveItemId=0145EMFGUBT4ZDN23ITFFJMETLI333VXZH&sitePath=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fikma_omar_ecn_forces_gc_ca%2FIQCBnzI262iZSpYSa0b3ut8nAQH0IHMmCqEmzxQ8AX8C-yU&fileUrl=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fikma_omar_ecn_forces_gc_ca%2FIQCBnzI262iZSpYSa0b3ut8nAQH0IHMmCqEmzxQ8AX8C-yU&iCalUid=040000008200E00074C5B7101A82E00800000000F41CA9EFD20FDD01000000000000000010000000BB366EE0A02CD54598F80923E7DFDBC0&threadId=19%3Ameeting_NDZkYTMwMzYtY2RmOS00Y2NlLWEwNTAtNjhiOGI5M2RiYjZj%40thread.v2&organizerId=a342975a-62c1-402a-b0b4-dce63141c5dd&tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&callId=10870044-58f7-4b28-9c49-01fec93c52c7&threadType=Meeting&meetingType=Scheduled&subType=RecapSharingLink_RecapChiclet', 'Meeting recap'],
        ['165', 'Payroll Tracking Automation', 'Payroll', 'https://teams.microsoft.com/l/meetingrecap?driveId=b%21u7oYv_oUuUSJphOHscHAc9DxUwFQDo1Pl9CEwL8njlJ2MB_Zh8EJR5JfcBID5WEH&driveItemId=0162GVTM7CIVSWDFJABNA27CBEDOYVXBXM&sitePath=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fmohammad_saad_ecn_forces_gc_ca%2FIQDiRWVhlSALQa-IJBuxW4bsAWO-5ePzM3-icxUUUmTwZVU&fileUrl=https%3A%2F%2F018gc-my.sharepoint.com%2F%3Av%3A%2Fg%2Fpersonal%2Fmohammad_saad_ecn_forces_gc_ca%2FIQDiRWVhlSALQa-IJBuxW4bsAWO-5ePzM3-icxUUUmTwZVU&iCalUid=040000008200E00074C5B7101A82E008000000008400DB47090EDD01000000000000000010000000073EABBE4FA77A4AA0C962E6D5532D94&threadId=19%3Ameeting_ODM5OWZiNzgtYzEzMi00M2IxLTlmYTEtMzI3ZjdjNWQ3NTQ4%40thread.v2&organizerId=00a1aa17-cc34-4e37-8680-2231aff73aed&tenantId=325b4494-1587-40d5-bb31-8b660b7f1038&callId=fce7ec47-313e-447b-866b-099e60d94cfa&threadType=Meeting&meetingType=Scheduled&subType=RecapSharingLink_RecapChiclet', 'Payroll meeting recap'],
    ];
}

function workspaceLinks(): array
{
    $base = 'https://dev.azure.com/' . ORG . '/' . PROJECT;
    return [
        ['VASC in Azure DevOps', $base, 'devops'],
        ['Work items', $base . '/_workitems', 'devops'],
        ['Boards', $base . '/_boards', 'devops'],
        ['Backlogs', $base . '/_backlogs', 'devops'],
        ['Queries', $base . '/_queries', 'devops'],
        ['Machine group', $base . '/_machinegroup', 'devops'],
        ['Personal access tokens', 'https://dev.azure.com/' . ORG . '/_usersSettings/tokens', 'devops'],
        ['VASC homepage', 'https://018gc.sharepoint.com.mcas.ms/sites/ORG-9872-0002/SitePages/EventPlanHome.aspx', 'sharepoint'],
        ['Power Apps', 'https://make.powerapps.com', 'powerapps'],
        ['Power BI', 'https://app.powerbi.com', 'powerbi'],
        ['Power Sweeper', 'power_sweeper/', 'other'],
    ];
}

function labelLinks(array $links): array
{
    foreach ($links as &$row) {
        if (is_array($row)) {
            $row['kind'] = linkKind((string) ($row['url'] ?? ''));
        }
    }
    unset($row);
    return $links;
}

function catalogLinks(): array
{
    $bag = [];
    foreach (workspaceLinks() as [$title, $url]) {
        rememberLink($bag, $url, $title, PROJECT, 'workspace', 0, '', '', '');
    }
    foreach (intakeLinks() as [$caseId, $caseTitle, $project, $url, $title]) {
        rememberLink($bag, $url, $title, $project, 'intake', 0, '', '', (string) $caseId);
        $key = strtolower($project) . '|' . strtolower((string) strtok(cleanWorkUrl($url), '#'));
        if (isset($bag[$key])) {
            $bag[$key]['caseTitle'] = $caseTitle;
        }
    }
    $links = array_values($bag);
    usort($links, static function (array $a, array $b): int {
        $byProject = strcasecmp((string) $a['project'], (string) $b['project']);
        return $byProject !== 0 ? $byProject : strcasecmp((string) $a['title'], (string) $b['title']);
    });
    return $links;
}

function linkIsPublic(array $row): bool
{
    $url = strtolower((string) ($row['url'] ?? ''));
    $title = strtolower((string) ($row['title'] ?? ''));
    $kind = linkKind((string) ($row['url'] ?? ''));
    if ($kind === 'powerapps' || $kind === 'powerbi') {
        return true;
    }
    if (str_contains($url, 'power_sweeper') || str_contains($title, 'power sweeper')) {
        return true;
    }
    return str_contains($title, 'vasc homepage');
}

function keepPublicLinks(array $links): array
{
    $out = [];
    foreach ($links as $row) {
        if (is_array($row) && linkIsPublic($row)) {
            unset($row['items']);
            $out[] = $row;
        }
    }
    return $out;
}

if ($action === 'links') {
    $fresh = (string) ($_GET['fresh'] ?? '') === '1';
    $refreshing = false;
    if ($fresh) {
        if (!signedIn()) {
            fail(401, 'Sign in to Azure DevOps to sync links.', true);
        }
        set_time_limit(300);
        $saved = ensureDaily(true, true);
    } else {
        [$saved, $refreshing] = pageDaily();
        $saved = ensureStoredLinks($saved);
    }
    $links = (isset($saved['links']) && is_array($saved['links']) && $saved['links'] !== [])
        ? $saved['links']
        : catalogLinks();
    $public = !signedIn();
    respondDaily([
        'ok' => true,
        'public' => $public,
        'cached' => !$fresh,
        'note' => $public ? '' : ($fresh ? 'Read from Azure DevOps just now.' : dailyNote($saved, $refreshing)),
        'links' => labelLinks($public ? keepPublicLinks($links) : $links),
    ], $refreshing);
}

function witCall(string $method, string $url, ?array $body = null): array
{
    $pat = sessionPat();
    $access = '';
    if ($pat === '') {
        $access = bearer();
        if ($access === '') {
            $pat = token();
        }
    }
    if ($access === '' && $pat === '') {
        return ['ok' => false, 'status' => 401, 'error' => 'no-credential', 'data' => []];
    }
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($access !== '') {
        $headers[] = 'Authorization: Bearer ' . $access;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json-patch+json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 40,
    ];
    if ($pat !== '') {
        $options[CURLOPT_USERPWD] = ':' . $pat;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $raw === false ? 0 : $status,
        'data' => is_array($data) ? $data : [],
    ];
}

function canWrite(): bool
{
    if (!signedIn()) {
        return false;
    }
    if (!empty($_SESSION['ado_write'])) {
        return true;
    }
    releaseSession();
    $probe = witCall('PATCH', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/0?api-version=' . API, [
        ['op' => 'test', 'path' => '/rev', 'value' => 1],
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        sessionStart();
    }
    if ($probe['status'] === 0) {
        return false;
    }
    $write = !in_array($probe['status'], [401, 403], true);
    $_SESSION['ado_write'] = $write;
    return $write;
}

function requireWrite(): void
{
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to change the chart.', true);
    }
    if (!canWrite()) {
        fail(401, 'This token can read Azure DevOps. Adding and moving circles needs Work Items, Read & write.', true);
    }
}

function chartPath(): string
{
    return dirname(__DIR__) . '/data/chart.xml';
}

function loadChart(): array
{
    $units = [];
    $places = [];
    $path = chartPath();
    if (!is_readable($path)) {
        return ['units' => $units, 'places' => $places];
    }
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        return ['units' => $units, 'places' => $places];
    }
    foreach ($xml->unit as $unit) {
        $id = (string) $unit['id'];
        if ($id === '') {
            continue;
        }
        $units[] = [
            'id' => $id,
            'parent' => (string) $unit['parent'],
            'level' => (string) $unit['level'],
            'name' => (string) $unit['name'],
            'short' => (string) $unit['short'],
            'fr' => (string) $unit['fr'],
        ];
    }
    foreach ($xml->place as $place) {
        $work = (string) $place['work'];
        $under = (string) $place['under'];
        if ($work !== '' && $under !== '') {
            $places[] = ['work' => $work, 'under' => $under];
        }
    }
    return ['units' => $units, 'places' => $places];
}

function saveChart(array $chart): void
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $root = $doc->createElement('organization');
    $doc->appendChild($root);
    foreach ($chart['units'] as $unit) {
        $el = $doc->createElement('unit');
        $el->setAttribute('id', (string) $unit['id']);
        $el->setAttribute('parent', (string) $unit['parent']);
        $el->setAttribute('level', (string) $unit['level']);
        foreach (['name', 'short', 'fr'] as $attr) {
            $value = (string) ($unit[$attr] ?? '');
            if ($value !== '') {
                $el->setAttribute($attr, $value);
            }
        }
        $root->appendChild($el);
    }
    foreach ($chart['places'] as $place) {
        $el = $doc->createElement('place');
        $el->setAttribute('work', (string) $place['work']);
        $el->setAttribute('under', (string) $place['under']);
        $root->appendChild($el);
    }
    $dir = dirname(chartPath());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail(500, 'The organization file could not be saved.');
    }
    if ($doc->save(chartPath()) === false) {
        fail(500, 'The organization file could not be saved.');
    }
}

require_once __DIR__ . '/org_list.php';

function orgRecord(string $id): ?array
{
    foreach (orgRows() as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    foreach (loadChart()['units'] as $unit) {
        if ((string) $unit['id'] === $id && (string) ($unit['name'] ?? '') !== '') {
            return $unit;
        }
    }
    return null;
}

function levelDown(string $level): string
{
    $number = (int) preg_replace('/\D/', '', $level);
    return 'L' . ($number + 1);
}

function moveOrg(string $id, string $parentId): array
{
    $row = orgRecord($id);
    $parent = orgRecord($parentId);
    if ($row === null || $parent === null) {
        fail(400, 'That organization is not on the chart.');
    }
    if ($id === $parentId) {
        fail(400, 'An organization cannot be placed under itself.');
    }
    $chart = loadChart();
    $parentName = [];
    $levelOf = [];
    foreach (orgRows() as $item) {
        $parentName[(string) $item['id']] = (string) $item['parent'];
        $levelOf[(string) $item['id']] = (string) $item['level'];
    }
    foreach ($chart['units'] as $unit) {
        $parentName[(string) $unit['id']] = (string) $unit['parent'];
        if ((string) $unit['level'] !== '') {
            $levelOf[(string) $unit['id']] = (string) $unit['level'];
        }
    }
    $byName = [];
    foreach (orgRows() as $item) {
        $byName[(string) $item['name']] = (string) $item['id'];
    }
    foreach ($chart['units'] as $unit) {
        $copiedName = (string) ($unit['name'] ?? '');
        if ($copiedName !== '') {
            $byName[$copiedName] = (string) $unit['id'];
        }
    }
    $cursor = $parentId;
    $guard = 0;
    while ($cursor !== '' && $guard < 24) {
        if ($cursor === $id) {
            fail(400, 'That would put an organization under one of its own parts.');
        }
        $name = $parentName[$cursor] ?? '';
        $cursor = $name !== '' && isset($byName[$name]) ? $byName[$name] : '';
        $guard += 1;
    }
    $level = levelDown($levelOf[$parentId] ?? (string) $parent['level']);
    $kept = [];
    $previous = null;
    foreach ($chart['units'] as $unit) {
        if ((string) $unit['id'] === $id) {
            $previous = $unit;
            continue;
        }
        $kept[] = $unit;
    }
    $kept[] = [
        'id' => $id,
        'parent' => (string) $parent['name'],
        'level' => $level,
        'name' => (string) ($previous['name'] ?? $row['name'] ?? ''),
        'short' => (string) ($previous['short'] ?? $row['short'] ?? ''),
        'fr' => (string) ($previous['fr'] ?? $row['fr'] ?? ''),
    ];
    $chart['units'] = $kept;
    saveChart($chart);
    return ['id' => $id, 'parent' => (string) $parent['name'], 'level' => $level];
}

function placeWork(string $workId, string $orgId): void
{
    if (orgRecord($orgId) === null) {
        fail(400, 'That organization is not on the chart.');
    }
    $chart = loadChart();
    $places = [];
    foreach ($chart['places'] as $place) {
        if ((string) $place['work'] !== $workId) {
            $places[] = $place;
        }
    }
    $places[] = ['work' => $workId, 'under' => $orgId];
    $chart['places'] = $places;
    saveChart($chart);
}

function clearPlace(string $workId): void
{
    $chart = loadChart();
    $chart['places'] = array_values(array_filter($chart['places'], static function (array $place) use ($workId): bool {
        return (string) $place['work'] !== $workId;
    }));
    saveChart($chart);
}

function workParentOps(int $id, int $parentId): array
{
    $current = witCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/' . $id . '?$expand=relations&api-version=' . API);
    if (in_array($current['status'], [401, 403], true)) {
        $_SESSION['ado_write'] = false;
        fail(401, 'This token can read Azure DevOps. Adding and moving circles needs Work Items, Read & write.', true);
    }
    if (!$current['ok']) {
        fail(502, 'Azure DevOps did not return that work item.');
    }
    $removes = [];
    foreach ($current['data']['relations'] ?? [] as $index => $rel) {
        if (($rel['rel'] ?? '') === 'System.LinkTypes.Hierarchy-Reverse') {
            $removes[] = (int) $index;
        }
    }
    rsort($removes);
    $ops = [];
    foreach ($removes as $index) {
        $ops[] = ['op' => 'remove', 'path' => '/relations/' . $index];
    }
    if ($parentId > 0) {
        $ops[] = [
            'op' => 'add',
            'path' => '/relations/-',
            'value' => [
                'rel' => 'System.LinkTypes.Hierarchy-Reverse',
                'url' => 'https://dev.azure.com/' . ORG . '/_apis/wit/workItems/' . $parentId,
            ],
        ];
    }
    return $ops;
}

function readJsonBody(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function caseLinkPath(): string
{
    return dirname(__DIR__) . '/data/case-links.json';
}

function readCaseLinks(): array
{
    $path = caseLinkPath();
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveCaseLink(string $caseId, int $workId, string $url): void
{
    $links = readCaseLinks();
    $links[$caseId] = ['id' => $workId, 'url' => $url];
    $encoded = json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents(caseLinkPath(), $encoded . "\n", LOCK_EX) === false) {
        fail(500, 'The work item was created, but the link on this use case could not be saved.');
    }
}

function htmlParagraphs(string $text): string
{
    $parts = preg_split("/\n{2,}/", str_replace("\r", '', $text)) ?: [];
    $html = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $lines = preg_split("/\n/", $part) ?: [];
        $html .= '<p>' . implode('<br>', array_map(static function (string $line): string {
            return htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $lines)) . '</p>';
    }
    return $html;
}

if ($action === 'caselinks') {
    echo json_encode(['ok' => true, 'links' => readCaseLinks()]);
    exit;
}

if ($action === 'access') {
    echo json_encode(['ok' => true, 'signedIn' => signedIn(), 'canWrite' => signedIn() && canWrite()]);
    exit;
}

if ($action === 'chart') {
    $chart = loadChart();
    echo json_encode(['ok' => true, 'units' => $chart['units'], 'places' => $chart['places']]);
    exit;
}

if ($action === 'create') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the new work item in a POST.');
    }
    requireWrite();
    $body = readJsonBody();
    $title = trim((string) ($body['title'] ?? ''));
    $type = (string) ($body['type'] ?? 'Task');
    $allowed = ['Task', 'User Story', 'Bug', 'Feature', 'Epic', 'Issue'];
    if (!in_array($type, $allowed, true)) {
        $type = 'Task';
    }
    if ($title === '' || strlen($title) > 200) {
        fail(400, 'Give the work item a title.');
    }
    $caseId = (string) ($body['case'] ?? '');
    if ($caseId !== '' && !preg_match('/^\d{1,6}$/', $caseId)) {
        fail(400, 'Unknown use case.');
    }
    if ($caseId !== '') {
        $known = readCaseLinks();
        $prior = $known[$caseId] ?? null;
        if (is_array($prior) && (int) ($prior['id'] ?? 0) > 0) {
            echo json_encode(['ok' => true, 'item' => [
                'id' => (int) $prior['id'],
                'url' => (string) ($prior['url'] ?? ''),
                'title' => $title,
            ], 'existing' => true]);
            exit;
        }
    }
    $parent = (string) ($body['parent'] ?? '');
    $ops = [['op' => 'add', 'path' => '/fields/System.Title', 'value' => $title]];
    $description = trim((string) ($body['description'] ?? ''));
    if (strlen($description) > 8000) {
        $description = substr($description, 0, 8000);
    }
    if ($description !== '') {
        $ops[] = ['op' => 'add', 'path' => '/fields/System.Description', 'value' => htmlParagraphs($description)];
    }
    $ops[] = ['op' => 'add', 'path' => '/fields/Custom.WorkStream', 'value' => 'Other'];
    if ($type === 'Epic' || $type === 'Feature') {
        $ops[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Common.Priority', 'value' => 2];
        $ops[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Common.ValueArea', 'value' => 'Business'];
    } elseif ($type === 'User Story' || $type === 'Bug') {
        $ops[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Common.ValueArea', 'value' => 'Business'];
    }
    $state = trim((string) ($body['state'] ?? ''));
    if ($state !== '' && strlen($state) <= 60 && !preg_match('/[<>]/', $state)) {
        $ops[] = ['op' => 'add', 'path' => '/fields/System.State', 'value' => $state];
    }
    foreach ([
        'hoursNeeded' => 'Microsoft.VSTS.Scheduling.OriginalEstimate',
        'hoursLeft' => 'Microsoft.VSTS.Scheduling.RemainingWork',
        'hoursUsed' => 'Microsoft.VSTS.Scheduling.CompletedWork',
    ] as $key => $field) {
        if (!array_key_exists($key, $body) || $body[$key] === '' || $body[$key] === null) {
            continue;
        }
        if (!is_numeric($body[$key])) {
            fail(400, 'Hours must be a number.');
        }
        $hours = round((float) $body[$key], 2);
        if ($hours < 0 || $hours > 100000) {
            fail(400, 'Hours must be a number.');
        }
        $ops[] = ['op' => 'add', 'path' => '/fields/' . $field, 'value' => $hours];
    }
    if ($type === 'Task') {
        $hasNeeded = false;
        $hasUsed = false;
        foreach ($ops as $op) {
            if (($op['path'] ?? '') === '/fields/Microsoft.VSTS.Scheduling.OriginalEstimate') {
                $hasNeeded = true;
            }
            if (($op['path'] ?? '') === '/fields/Microsoft.VSTS.Scheduling.CompletedWork') {
                $hasUsed = true;
            }
        }
        if (!$hasNeeded) {
            $ops[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Scheduling.OriginalEstimate', 'value' => 0];
        }
        if (!$hasUsed) {
            $ops[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Scheduling.CompletedWork', 'value' => 0];
        }
    }
    $orgId = '';
    if (preg_match('/^wit:(\d+)$/', $parent, $match)) {
        $ops[] = [
            'op' => 'add',
            'path' => '/relations/-',
            'value' => [
                'rel' => 'System.LinkTypes.Hierarchy-Reverse',
                'url' => 'https://dev.azure.com/' . ORG . '/_apis/wit/workItems/' . $match[1],
            ],
        ];
    } elseif (preg_match('/^org:(\d+)$/', $parent, $match)) {
        $orgId = $match[1];
        if (orgRecord($orgId) === null) {
            if ($caseId !== '') {
                $orgId = '';
            } else {
                fail(400, 'That organization is not on the chart.');
            }
        }
    }
    $createProject = projectNameFromKey((string) ($body['project'] ?? 'old'));
    $created = witCall(
        'POST',
        'https://dev.azure.com/' . ORG . '/' . rawurlencode($createProject) . '/_apis/wit/workitems/%24' . rawurlencode($type) . '?api-version=' . API,
        $ops
    );
    if (in_array($created['status'], [401, 403], true)) {
        $_SESSION['ado_write'] = false;
        fail(401, 'This token can read Azure DevOps. Adding and moving circles needs Work Items, Read & write.', true);
    }
    if (!$created['ok']) {
        $message = trim((string) ($created['data']['message'] ?? ''));
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if (strlen($message) > 220) {
            $message = substr($message, 0, 220);
        }
        fail(502, $message !== '' ? $message : 'Azure DevOps did not create that work item.');
    }
    $item = shapeWorkItem($created['data']);
    $item['url'] = 'https://dev.azure.com/' . ORG . '/' . rawurlencode($createProject) . '/_workitems/edit/' . $item['id'];
    if ($caseId !== '' && $item['id'] > 0) {
        saveCaseLink($caseId, (int) $item['id'], (string) $item['url']);
    }
    if ($orgId !== '' && $item['id'] > 0) {
        placeWork((string) $item['id'], $orgId);
    }
    $parentId = null;
    if (preg_match('/^wit:(\d+)$/', $parent, $parentMatch)) {
        $parentId = (int) $parentMatch[1];
        $item['parentId'] = $parentId;
    }
    $cacheKey = strtolower(trim((string) ($body['project'] ?? 'old'))) === 'new' ? 'new' : 'old';
    $cachePath = $cacheKey === 'new' ? newChartPath() : dailyPath();
    $cached = json_decode((string) @file_get_contents($cachePath), true);
    if (is_array($cached) && is_array($cached['items'] ?? null) && (int) ($item['id'] ?? 0) > 0) {
        $cached['items'][] = $item;
        if ($cacheKey === 'new') {
            writeNewChart($cached);
        } else {
            writeDaily($cached);
        }
    }
    echo json_encode(['ok' => true, 'item' => $item, 'parent' => $parent]);
    exit;
}

if ($action === 'update') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the work item in a POST.');
    }
    requireWrite();
    $body = readJsonBody();
    $id = (string) ($body['id'] ?? '');
    if (!preg_match('/^\d{1,8}$/', $id)) {
        fail(400, 'Work item id is not valid.');
    }
    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '' || strlen($title) > 200) {
        fail(400, 'Give the work item a title.');
    }
    $state = trim((string) ($body['state'] ?? ''));
    if ($state === '' || strlen($state) > 60 || preg_match('/[<>]/', $state)) {
        fail(400, 'Give the work item a state.');
    }
    $description = str_replace("\r", '', (string) ($body['description'] ?? ''));
    if (strlen($description) > 8000) {
        fail(400, 'The description is too long.');
    }
    $ops = [
        ['op' => 'add', 'path' => '/fields/System.Title', 'value' => $title],
        ['op' => 'add', 'path' => '/fields/System.State', 'value' => $state],
        ['op' => 'add', 'path' => '/fields/System.Description', 'value' => htmlParagraphs($description)],
    ];
    foreach ([
        'hoursNeeded' => 'Microsoft.VSTS.Scheduling.OriginalEstimate',
        'hoursLeft' => 'Microsoft.VSTS.Scheduling.RemainingWork',
        'hoursUsed' => 'Microsoft.VSTS.Scheduling.CompletedWork',
    ] as $key => $field) {
        if (!array_key_exists($key, $body) || $body[$key] === '' || $body[$key] === null) {
            continue;
        }
        if (!is_numeric($body[$key])) {
            fail(400, 'Hours must be a number.');
        }
        $hours = round((float) $body[$key], 2);
        if ($hours < 0 || $hours > 100000) {
            fail(400, 'Hours must be a number.');
        }
        $ops[] = ['op' => 'add', 'path' => '/fields/' . $field, 'value' => $hours];
    }
    $saved = witCall(
        'PATCH',
        'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/' . $id . '?api-version=' . API,
        $ops
    );
    if (in_array($saved['status'], [401, 403], true)) {
        $_SESSION['ado_write'] = false;
        fail(401, 'This token can read Azure DevOps. Changing a work item needs Work Items, Read & write.', true);
    }
    if (!$saved['ok']) {
        $message = trim((string) ($saved['data']['message'] ?? ''));
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if (strlen($message) > 180) {
            $message = substr($message, 0, 180);
        }
        fail(502, $message !== '' ? $message : 'Azure DevOps did not update that work item.');
    }
    echo json_encode(['ok' => true, 'item' => shapeWorkItem($saved['data'], true)]);
    exit;
}

if ($action === 'move') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the move in a POST.');
    }
    requireWrite();
    $body = readJsonBody();
    $id = (string) ($body['id'] ?? '');
    $parent = (string) ($body['parent'] ?? '');
    if (preg_match('/^org:(\d+)$/', $id, $from) && preg_match('/^org:(\d+)$/', $parent, $to)) {
        $unit = moveOrg($from[1], $to[1]);
        echo json_encode(['ok' => true, 'unit' => $unit]);
        exit;
    }
    if (!preg_match('/^wit:(\d+)$/', $id, $from)) {
        fail(400, 'That circle cannot be moved there.');
    }
    $workId = (int) $from[1];
    if (preg_match('/^org:(\d+)$/', $parent, $to)) {
        $ops = workParentOps($workId, 0);
        if ($ops) {
            $saved = witCall('PATCH', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/' . $workId . '?api-version=' . API, $ops);
            if (in_array($saved['status'], [401, 403], true)) {
                $_SESSION['ado_write'] = false;
                fail(401, 'This token can read Azure DevOps. Adding and moving circles needs Work Items, Read & write.', true);
            }
            if (!$saved['ok']) {
                fail(502, 'Azure DevOps did not update that work item.');
            }
        }
        placeWork((string) $workId, $to[1]);
        echo json_encode(['ok' => true, 'parent' => $parent]);
        exit;
    }
    if (!preg_match('/^wit:(\d+)$/', $parent, $to)) {
        fail(400, 'Drop a work item on a work item or an organization.');
    }
    if ((int) $to[1] === $workId) {
        fail(400, 'A work item cannot be placed under itself.');
    }
    $ops = workParentOps($workId, (int) $to[1]);
    $saved = witCall('PATCH', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/' . $workId . '?api-version=' . API, $ops);
    if (in_array($saved['status'], [401, 403], true)) {
        $_SESSION['ado_write'] = false;
        fail(401, 'This token can read Azure DevOps. Adding and moving circles needs Work Items, Read & write.', true);
    }
    if (!$saved['ok']) {
        fail(502, 'Azure DevOps did not update that work item.');
    }
    clearPlace((string) $workId);
    echo json_encode(['ok' => true, 'parent' => $parent]);
    exit;
}

if ($action === 'copy') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the request as a POST.');
    }
    requireWrite();
    set_time_limit(180);
    $body = readJsonBody();
    $paste = (string) ($body['parent'] ?? '');
    $nodes = $body['nodes'] ?? [];
    if (!is_array($nodes) || !$nodes) {
        fail(400, 'Choose a circle to copy first.');
    }
    if (count($nodes) > 40) {
        fail(400, 'That tree is too large to copy at once. Copy a smaller branch.');
    }
    $rootKind = (string) ($nodes[0]['kind'] ?? '');
    if ($rootKind === 'org' && !preg_match('/^org:\d+$/', $paste)) {
        fail(400, 'An organization copies under an organization.');
    }
    $chart = loadChart();
    $nextOrg = 1;
    foreach (orgRows() as $row) {
        $nextOrg = max($nextOrg, (int) ($row['id'] ?? 0) + 1);
    }
    foreach ($chart['units'] as $unit) {
        $nextOrg = max($nextOrg, (int) ($unit['id'] ?? 0) + 1);
    }
    $map = [];
    $created = [];
    $warning = '';
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $key = (string) ($node['key'] ?? '');
        $parentKey = (string) ($node['parentKey'] ?? '');
        $dest = $parentKey === '' ? $paste : (string) ($map[$parentKey]['id'] ?? '');
        $kind = (string) ($node['kind'] ?? 'work');
        $title = trim((string) ($node['title'] ?? ''));
        if ($title === '') {
            $title = 'Copy';
        }
        if (strlen($title) > 200) {
            $title = substr($title, 0, 200);
        }
        if ($kind === 'org') {
            if (!preg_match('/^org:(\d+)$/', $dest, $match)) {
                $warning = 'An organization copies under an organization.';
                break;
            }
            $parentRow = orgRecord($match[1]);
            if ($parentRow === null && isset($map[$parentKey])) {
                $parentRow = $map[$parentKey]['record'];
            }
            if ($parentRow === null) {
                $warning = 'The organization it copies under is not on the chart.';
                break;
            }
            $level = levelDown((string) ($parentRow['level'] ?? 'L0'));
            $id = (string) $nextOrg;
            $nextOrg += 1;
            $short = $title;
            $name = $level . ' - ' . $short . ' ' . $id;
            $unit = [
                'id' => $id,
                'parent' => (string) ($parentRow['name'] ?? ''),
                'level' => $level,
                'name' => $name,
                'short' => $short,
                'fr' => trim((string) ($node['fr'] ?? '')),
            ];
            $chart['units'][] = $unit;
            $record = $unit;
            $map[$key] = ['id' => 'org:' . $id, 'record' => $record];
            $created[] = [
                'id' => 'org:' . $id,
                'orgId' => $id,
                'under' => $dest,
                'kind' => 'org',
                'title' => $short,
                'name' => $name,
                'level' => $level,
                'fr' => $unit['fr'],
                'parentName' => $unit['parent'],
            ];
            continue;
        }
        $type = trim((string) ($node['type'] ?? 'Task'));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9 _\\/-]{0,40}$/', $type)) {
            $type = 'Task';
        }
        $ops = [['op' => 'add', 'path' => '/fields/System.Title', 'value' => $title]];
        $orgId = '';
        if (preg_match('/^wit:(\d+)$/', $dest, $match)) {
            $ops[] = [
                'op' => 'add',
                'path' => '/relations/-',
                'value' => [
                    'rel' => 'System.LinkTypes.Hierarchy-Reverse',
                    'url' => 'https://dev.azure.com/' . ORG . '/_apis/wit/workItems/' . $match[1],
                ],
            ];
        } elseif (preg_match('/^org:(\d+)$/', $dest, $match)) {
            $orgId = $match[1];
        } else {
            $warning = 'It had nowhere to land.';
            break;
        }
        $copyProject = projectNameFromKey((string) ($body['project'] ?? 'old'));
        $made = witCall(
            'POST',
            'https://dev.azure.com/' . ORG . '/' . rawurlencode($copyProject) . '/_apis/wit/workitems/%24' . rawurlencode($type) . '?api-version=' . API,
            $ops
        );
        if (in_array($made['status'], [401, 403], true)) {
            $_SESSION['ado_write'] = false;
            $warning = 'This token can read Azure DevOps. Adding and moving circles needs Work Items, Read & write.';
            break;
        }
        if (!$made['ok']) {
            $warning = 'Azure DevOps did not create part of it.';
            break;
        }
        $item = shapeWorkItem($made['data']);
        if ($orgId !== '' && $item['id'] > 0) {
            $chart['places'][] = ['work' => (string) $item['id'], 'under' => $orgId];
        }
        $newId = 'wit:' . $item['id'];
        $map[$key] = ['id' => $newId, 'record' => null];
        $created[] = [
            'id' => $newId,
            'under' => $dest,
            'kind' => $kind,
            'title' => (string) ($item['title'] ?? $title),
            'type' => (string) ($item['type'] ?? $type),
            'state' => (string) ($item['state'] ?? 'New'),
            'url' => (string) ($item['url'] ?? ''),
            'workId' => $item['id'],
        ];
    }
    saveChart($chart);
    if (!$created && $warning !== '') {
        $login = str_contains($warning, 'Read & write');
        fail($login ? 401 : 502, $warning, $login);
    }
    echo json_encode(['ok' => true, 'created' => $created, 'warning' => $warning]);
    exit;
}

function chartRequestKey(): string
{
    $key = strtolower(trim((string) ($_GET['project'] ?? 'old')));
    return $key === 'new' ? 'new' : 'old';
}

function projectNameFromKey(string $key): string
{
    $key = strtolower(trim($key));
    if ($key === 'new') {
        return 'VCDS ASC — Projects and Team Management';
    }
    if ($key === '' || $key === 'old') {
        return PROJECT;
    }
    fail(400, 'Unknown DevOps project.');
}

function projectKeyFromName(string $name): string
{
    if (strcasecmp(trim($name), PROJECT) === 0) {
        return 'old';
    }
    if (trim($name) === 'VCDS ASC — Projects and Team Management') {
        return 'new';
    }
    return '';
}

function wiqlQuote(string $value): string
{
    return str_replace("'", "''", $value);
}

function newChartPath(): string
{
    return dirname(__DIR__) . '/data/daily-new.json';
}

function readNewChart(): ?array
{
    $path = newChartPath();
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function writeNewChart(array $saved): void
{
    $json = json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || $json === '') {
        return;
    }
    $path = newChartPath();
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $json);
    rename($tmp, $path);
}

function serveNewChart(bool $fresh): void
{
    if (!signedIn()) {
        fail(401, 'Sign in to Azure DevOps to load the new project.', true);
    }
    $saved = readNewChart();
    $usedCache = !$fresh && dailyFresh($saved);
    if (!$usedCache) {
        set_time_limit(180);
        $name = projectNameFromKey('new');
        $query = ado('POST', 'https://dev.azure.com/' . ORG . '/' . rawurlencode($name) . '/_apis/wit/wiql?api-version=' . API, [
            'query' => "SELECT [System.Id] FROM WorkItems WHERE [System.TeamProject] = '" . wiqlQuote($name) . "' AND [System.WorkItemType] NOT IN ('Test Case', 'Test Suite', 'Test Plan', 'Shared Steps', 'Shared Parameter', 'Feedback Request', 'Code Review Request', 'Code Review Response') AND [System.State] <> 'Removed'",
        ]);
        $ids = [];
        foreach ($query['workItems'] ?? [] as $row) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
        $items = fetchWorkItems($ids, false, true);
        $base = 'https://dev.azure.com/' . ORG . '/' . rawurlencode($name) . '/_workitems/edit/';
        foreach ($items as &$item) {
            if (!empty($item['id'])) {
                $item['url'] = $base . $item['id'];
            }
        }
        unset($item);
        $saved = [
            'saved' => time(),
            'note' => 'Read from Projects and Team Management.',
            'items' => $items,
            'people' => [],
        ];
        writeNewChart($saved);
    }
    if (!is_array($saved)) {
        $saved = emptyDaily();
    }
    echo json_encode([
        'ok' => true,
        'project' => 'new',
        'cached' => $usedCache,
        'note' => (string) ($saved['note'] ?? 'Projects and Team Management.'),
        'items' => chartItems($saved),
        'people' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function directChildIds(int $id, string $project): array
{
    $query = ado('POST', 'https://dev.azure.com/' . ORG . '/' . rawurlencode($project) . '/_apis/wit/wiql?api-version=' . API, [
        'query' => 'SELECT [System.Id] FROM WorkItems WHERE [System.Parent] = ' . $id,
    ]);
    $ids = [];
    foreach ($query['workItems'] ?? [] as $row) {
        $child = (int) ($row['id'] ?? 0);
        if ($child > 0 && $child !== $id) {
            $ids[] = $child;
        }
    }
    return $ids;
}

function descendantIds(int $root, string $project): array
{
    $order = [];
    $seen = [$root => true];
    $queue = directChildIds($root, $project);
    while ($queue) {
        $current = array_shift($queue);
        if (isset($seen[$current])) {
            continue;
        }
        $seen[$current] = true;
        $order[] = $current;
        if (count($order) > 40) {
            fail(400, 'That tree is too large to move at once. Move a smaller branch. Nothing was changed.');
        }
        foreach (directChildIds($current, $project) as $child) {
            $queue[] = $child;
        }
    }
    return $order;
}

function moveWorkItemProject(int $id, string $from, string $to): array
{
    $url = 'https://dev.azure.com/' . ORG . '/' . rawurlencode($from) . '/_apis/wit/workitems/' . $id . '?api-version=' . API;
    $saved = witCall('PATCH', $url, [
        ['op' => 'add', 'path' => '/fields/System.TeamProject', 'value' => $to],
        ['op' => 'add', 'path' => '/fields/System.AreaPath', 'value' => $to],
        ['op' => 'add', 'path' => '/fields/System.IterationPath', 'value' => $to],
    ]);
    if ($saved['status'] === 401) {
        $_SESSION['ado_write'] = false;
        fail(401, 'This token can read Azure DevOps. Moving a work item needs Work Items, Read & write.', true);
    }
    if (!$saved['ok']) {
        $message = trim((string) ($saved['data']['message'] ?? ''));
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if (stripos($message, 'VS402836') !== false || stripos($message, 'out of the source project') !== false) {
            $message = 'Azure DevOps refused to move it out of ' . $from . '. This account needs permission to move work items out of that project.';
        }
        if (strlen($message) > 220) {
            $message = substr($message, 0, 220);
        }
        return ['ok' => false, 'error' => $message !== '' ? $message : 'Azure DevOps did not move that work item.'];
    }
    $landed = (string) ($saved['data']['fields']['System.TeamProject'] ?? '');
    if ($landed !== '' && strcasecmp($landed, $to) !== 0) {
        return ['ok' => false, 'error' => 'Azure DevOps left work item ' . $id . ' in ' . $landed . '.'];
    }
    return ['ok' => true, 'item' => shapeWorkItem($saved['data'])];
}

const DUMMY_EPIC = 'TEST MOVE — dummy epic (safe to remove)';

if ($action === 'dummy') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the request as a POST.');
    }
    requireWrite();
    $name = projectNameFromKey('old');
    $existing = ado('POST', 'https://dev.azure.com/' . ORG . '/' . rawurlencode($name) . '/_apis/wit/wiql?api-version=' . API, [
        'query' => "SELECT [System.Id] FROM WorkItems WHERE [System.TeamProject] = '" . wiqlQuote($name) . "' AND [System.Title] = '" . wiqlQuote(DUMMY_EPIC) . "' AND [System.State] <> 'Removed'",
    ]);
    foreach ($existing['workItems'] ?? [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            echo json_encode([
                'ok' => true,
                'created' => false,
                'id' => $id,
                'note' => 'The test epic is already in VASC as work item ' . $id . '. Nothing else was changed.',
            ]);
            exit;
        }
    }
    $make = static function (string $type, string $title, int $parent) use ($name): array {
        $ops = [['op' => 'add', 'path' => '/fields/System.Title', 'value' => $title]];
        $ops[] = ['op' => 'add', 'path' => '/fields/System.Description', 'value' => '<p>Test item for moving work from VASC into Projects and Team Management. Safe to remove after the check. Not the scoring refactor.</p>'];
        $ops[] = ['op' => 'add', 'path' => '/fields/Custom.WorkStream', 'value' => 'Other'];
        if ($type === 'Task') {
            $ops[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Scheduling.OriginalEstimate', 'value' => 0];
            $ops[] = ['op' => 'add', 'path' => '/fields/Microsoft.VSTS.Scheduling.CompletedWork', 'value' => 0];
        }
        if ($parent > 0) {
            $ops[] = [
                'op' => 'add',
                'path' => '/relations/-',
                'value' => [
                    'rel' => 'System.LinkTypes.Hierarchy-Reverse',
                    'url' => 'https://dev.azure.com/' . ORG . '/_apis/wit/workItems/' . $parent,
                ],
            ];
        }
        $created = witCall(
            'POST',
            'https://dev.azure.com/' . ORG . '/' . rawurlencode($name) . '/_apis/wit/workitems/%24' . rawurlencode($type) . '?api-version=' . API,
            $ops
        );
        if (in_array($created['status'], [401, 403], true)) {
            $_SESSION['ado_write'] = false;
            fail(401, 'This token can read Azure DevOps. Adding a work item needs Work Items, Read & write.', true);
        }
        if (!$created['ok']) {
            $message = trim((string) ($created['data']['message'] ?? ''));
            fail(502, $message !== '' ? $message : 'Azure DevOps did not create the test item. Nothing else was changed.');
        }
        return shapeWorkItem($created['data']);
    };
    $epic = $make('Epic', DUMMY_EPIC, 0);
    $feature = $make('Feature', 'TEST MOVE — dummy feature', (int) $epic['id']);
    $task = $make('Task', 'TEST MOVE — dummy task', (int) $feature['id']);
    echo json_encode([
        'ok' => true,
        'created' => true,
        'id' => $epic['id'],
        'note' => 'Test epic ' . $epic['id'] . ' is in VASC, with a feature and a task under it. Drag that epic to the new project when you are ready. The scoring refactor was not touched.',
        'feature' => $feature['id'],
        'task' => $task['id'],
    ]);
    exit;
}

function witFailure(array $saved): string
{
    $message = trim((string) ($saved['data']['message'] ?? ''));
    if ($message === '') {
        $message = 'Azure DevOps refused the delete.';
    }
    $status = (int) ($saved['status'] ?? 0);
    return $status > 0 ? $message . ' (' . $status . ')' : $message;
}

function deleteWorkItem(int $workId): array
{
    $base = 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/' . $workId;
    $recycle = witCall('DELETE', $base . '?api-version=' . API, null);
    if ($recycle['status'] === 401) {
        return ['ok' => false, 'status' => 401, 'destroyed' => false, 'error' => witFailure($recycle)];
    }
    $destroy = witCall('DELETE', $base . '?destroy=true&api-version=' . API, null);
    if ($destroy['status'] === 401) {
        return ['ok' => false, 'status' => 401, 'destroyed' => false, 'error' => witFailure($destroy)];
    }
    if ($destroy['ok']) {
        return ['ok' => true, 'status' => 200, 'destroyed' => true, 'error' => ''];
    }
    if ($recycle['ok']) {
        return ['ok' => true, 'status' => 200, 'destroyed' => false, 'error' => witFailure($destroy)];
    }
    $failed = $recycle['status'] > 0 ? $recycle : $destroy;
    return ['ok' => false, 'status' => (int) $failed['status'], 'destroyed' => false, 'error' => witFailure($failed)];
}

if ($action === 'remove') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the removal in a POST.');
    }
    requireWrite();
    set_time_limit(180);
    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        fail(400, 'Say which work item to remove.');
    }
    if ((string) ($body['confirm'] ?? '') !== (string) $id) {
        fail(400, 'Confirm the work item number before it is removed. Nothing was changed.');
    }
    $current = witCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/' . $id . '?api-version=' . API);
    if (!$current['ok']) {
        fail(502, 'Azure DevOps did not return that work item. Nothing was changed.');
    }
    $fields = $current['data']['fields'] ?? [];
    $title = (string) ($fields['System.Title'] ?? '');
    $fromName = (string) ($fields['System.TeamProject'] ?? '');
    $fromKey = projectKeyFromName($fromName);
    if ($fromKey === '') {
        fail(400, 'That work item is not in VASC or Projects and Team Management, so it was left alone.');
    }
    if (preg_match('/scoring refactor/i', $title) && (string) ($body['acknowledge'] ?? '') !== 'remove scoring refactor') {
        fail(400, 'That epic is the scoring refactor. Confirm it by name before it can be removed. Nothing was changed.');
    }
    $ids = [$id];
    if (!empty($body['includeChildren'])) {
        $children = descendantIds($id, $fromName);
        $ids = array_merge($children, [$id]);
    }
    $removed = [];
    $recycled = [];
    foreach ($ids as $workId) {
        $saved = deleteWorkItem((int) $workId);
        if ($saved['status'] === 401) {
            $_SESSION['ado_write'] = false;
            fail(401, 'This token can read Azure DevOps. Deleting a work item needs Work Items, Read & write.', true);
        }
        if (!$saved['ok']) {
            $done = $removed ? ' Deleted ' . implode(', ', $removed) . ' before it stopped.' : ' Nothing was changed.';
            fail($saved['status'] >= 400 ? $saved['status'] : 502, $saved['error'] . $done);
        }
        $removed[] = (string) $workId;
        if (!$saved['destroyed']) {
            $recycled[] = (string) $workId;
        }
    }
    $sourcePath = $fromKey === 'new' ? newChartPath() : dailyPath();
    $cached = json_decode((string) @file_get_contents($sourcePath), true);
    if (is_array($cached) && is_array($cached['items'] ?? null)) {
        $gone = array_fill_keys($removed, true);
        $cached['items'] = array_values(array_filter($cached['items'], static function ($item) use ($gone): bool {
            return !is_array($item) || !isset($gone[(string) ($item['id'] ?? '')]);
        }));
        if ($fromKey === 'new') {
            writeNewChart($cached);
        } else {
            writeDaily($cached);
        }
    }
    $note = 'Destroyed ' . implode(', ', $removed) . '. They are gone from Azure DevOps and cannot be restored.';
    if ($recycled) {
        $note = 'Deleted ' . implode(', ', $removed) . '. ' . implode(', ', $recycled) . ' could not be permanently destroyed, so those remain in the Azure DevOps recycle bin.';
    }
    echo json_encode([
        'ok' => true,
        'removed' => $removed,
        'recycled' => $recycled,
        'note' => $note,
    ]);
    exit;
}

if ($action === 'transfer') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the move in a POST.');
    }
    requireWrite();
    set_time_limit(180);
    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    $toKey = strtolower(trim((string) ($body['to'] ?? '')));
    if ($id < 1 || ($toKey !== 'old' && $toKey !== 'new')) {
        fail(400, 'Say which work item moves, and which project it moves to.');
    }
    if ((string) ($body['confirm'] ?? '') !== (string) $id) {
        fail(400, 'Confirm the work item number before it moves. Nothing was changed.');
    }
    $current = witCall('GET', 'https://dev.azure.com/' . ORG . '/_apis/wit/workitems/' . $id . '?api-version=' . API);
    if (!$current['ok']) {
        fail(502, 'Azure DevOps did not return that work item. Nothing was changed.');
    }
    $fields = $current['data']['fields'] ?? [];
    $title = (string) ($fields['System.Title'] ?? '');
    $fromName = (string) ($fields['System.TeamProject'] ?? '');
    $fromKey = projectKeyFromName($fromName);
    if ($fromKey === '') {
        fail(400, 'That work item is not in VASC or Projects and Team Management, so it was left alone.');
    }
    if ($fromKey === $toKey) {
        fail(400, 'That work item is already in that project. Nothing was changed.');
    }
    if (preg_match('/scoring refactor/i', $title) && (string) ($body['acknowledge'] ?? '') !== 'move scoring refactor') {
        fail(400, 'That epic is the scoring refactor. Confirm it by name before it can move. Nothing was changed.');
    }
    $toName = projectNameFromKey($toKey);
    $ids = [$id];
    if (!empty($body['includeChildren'])) {
        $children = descendantIds($id, $fromName);
        $ids = array_merge($children, [$id]);
    }
    $moved = [];
    $records = [];
    foreach ($ids as $workId) {
        $result = moveWorkItemProject($workId, $fromName, $toName);
        if (!$result['ok']) {
            if ($records) {
                transplantChartItems($fromKey, $toKey, $records);
            }
            $done = $moved ? ' Moved ' . implode(', ', $moved) . ' before it stopped.' : ' Nothing moved, and the circles stayed on this chart.';
            fail(502, 'Work item ' . $workId . ' stayed put. ' . $result['error'] . $done);
        }
        $moved[] = (string) $workId;
        $item = $result['item'];
        $item['area'] = $toName;
        $item['url'] = 'https://dev.azure.com/' . ORG . '/' . rawurlencode($toName) . '/_workitems/edit/' . $item['id'];
        $records[] = $item;
    }
    transplantChartItems($fromKey, $toKey, $records);
    echo json_encode([
        'ok' => true,
        'moved' => $moved,
        'project' => $toKey,
        'note' => 'Moved ' . implode(', ', $moved) . ' into ' . $toName . '. Nothing was deleted.',
    ]);
    exit;
}

function readChartFile(string $key): array
{
    $path = $key === 'new' ? newChartPath() : dailyPath();
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data) || !is_array($data['items'] ?? null)) {
        return ['saved' => time(), 'items' => [], 'people' => []];
    }
    return $data;
}

function storeChartFile(string $key, array $saved): void
{
    if ($key === 'new') {
        writeNewChart($saved);
        return;
    }
    writeDaily($saved);
}

function transplantChartItems(string $fromKey, string $toKey, array $records): void
{
    $gone = [];
    foreach ($records as $item) {
        if (is_array($item) && (int) ($item['id'] ?? 0) > 0) {
            $gone[(string) $item['id']] = true;
        }
    }
    if (!$gone) {
        return;
    }
    $source = readChartFile($fromKey);
    $dest = readChartFile($toKey);
    $source['items'] = array_values(array_filter($source['items'], static function ($item) use ($gone): bool {
        return !is_array($item) || !isset($gone[(string) ($item['id'] ?? '')]);
    }));
    $index = [];
    foreach ($dest['items'] as $i => $item) {
        if (is_array($item)) {
            $index[(string) ($item['id'] ?? '')] = $i;
        }
    }
    foreach ($records as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        if (!isset($gone[$id])) {
            continue;
        }
        if (isset($index[$id])) {
            $dest['items'][$index[$id]] = $item;
            continue;
        }
        $dest['items'][] = $item;
        $index[$id] = count($dest['items']) - 1;
    }
    $source['saved'] = time();
    $dest['saved'] = time();
    storeChartFile($fromKey, $source);
    storeChartFile($toKey, $dest);
}

fail(400, 'Unknown sync action.');
