<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const TENANT = '325b4494-1587-40d5-bb31-8b660b7f1038';
const GRAPH_CLIENT = '14d82eec-204b-4c2f-b7e8-296a70dab67e';
const GRAPH_SCOPE = 'https://graph.microsoft.com/Presence.Read.All https://graph.microsoft.com/User.ReadBasic.All offline_access';
// Optional scopes are exchanged from the same refresh token, so a missing consent never blocks presence.
const GRAPH_PROFILE_SCOPE = 'https://graph.microsoft.com/User.Read.All offline_access';
const GRAPH_CALENDAR_SCOPE = 'https://graph.microsoft.com/Calendars.ReadBasic offline_access';
const PROFILE_FIELDS = 'id,displayName,mail,userPrincipalName,jobTitle,department,officeLocation,businessPhones,mobilePhone';
const DIRECTORY_SECONDS = 3600;
const SCHEDULE_SECONDS = 120;
const SCHEDULE_ZONE = 'Eastern Standard Time';

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

function releaseSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function fail(int $code, string $message, bool $login = false): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message, 'login' => $login]);
    exit;
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
    if (!is_array($data)) {
        $data = [];
    }
    return ['status' => $status, 'data' => $data, 'error' => (string) ($data['error'] ?? '')];
}

/**
 * The PHP session lasts minutes, so the refresh token is also kept in an
 * httpOnly cookie, the way devops.php keeps the DevOps token in vasc_ado.
 */
function rememberTeams(string $refresh): void
{
    setcookie('vasc_teams', $refresh, [
        'expires' => $refresh === '' ? time() - 3600 : time() + 90 * 24 * 60 * 60,
        'path' => '/DO/',
        'secure' => httpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function savedRefresh(): string
{
    $saved = $_COOKIE['vasc_teams'] ?? '';
    return is_string($saved) && preg_match('/^[A-Za-z0-9._~+\/=-]{40,4096}$/', $saved) ? $saved : '';
}

function storeTeams(array $data): void
{
    $_SESSION['teams'] = [
        'access' => (string) $data['access_token'],
        'refresh' => (string) ($data['refresh_token'] ?? ($_SESSION['teams']['refresh'] ?? '')),
        'expires' => time() + (int) ($data['expires_in'] ?? 3600),
    ];
    if ($_SESSION['teams']['refresh'] !== '' && $_SESSION['teams']['refresh'] !== savedRefresh()) {
        rememberTeams($_SESSION['teams']['refresh']);
    }
    unset($_SESSION['teams_device'], $_SESSION['teams_device_expires'], $_SESSION['teams_next'], $_SESSION['teams_interval'], $_SESSION['teams_scoped']);
}

function teamsBearer(bool $forceRefresh = false): string
{
    $teams = $_SESSION['teams'] ?? null;
    if ((!is_array($teams) || ($teams['access'] ?? '') === '') && savedRefresh() !== '') {
        $teams = ['access' => '', 'refresh' => savedRefresh(), 'expires' => 0];
        $_SESSION['teams'] = $teams;
        $forceRefresh = true;
    }
    if (!is_array($teams) || (($teams['access'] ?? '') === '' && ($teams['refresh'] ?? '') === '')) {
        return '';
    }
    if (!$forceRefresh && time() < ((int) $teams['expires'] - 60)) {
        return (string) $teams['access'];
    }
    if (($teams['refresh'] ?? '') === '') {
        return $forceRefresh ? '' : (string) $teams['access'];
    }
    $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/token', [
        'client_id' => GRAPH_CLIENT,
        'grant_type' => 'refresh_token',
        'refresh_token' => $teams['refresh'],
        'scope' => GRAPH_SCOPE,
    ]);
    if ($res['status'] === 200 && !empty($res['data']['access_token'])) {
        storeTeams($res['data']);
        return (string) $_SESSION['teams']['access'];
    }
    $error = (string) ($res['data']['error'] ?? '');
    if ($error === 'invalid_grant' || $error === 'interaction_required') {
        unset($_SESSION['teams']);
        rememberTeams('');
        return '';
    }
    return $forceRefresh ? '' : (string) $teams['access'];
}

function scopedBearer(string $key, string $scope): array
{
    $cached = $_SESSION['teams_scoped'][$key] ?? null;
    if (is_array($cached) && ($cached['access'] ?? '') !== '' && time() < ((int) $cached['expires'] - 60)) {
        return ['access' => (string) $cached['access'], 'consent' => false];
    }
    if (is_array($cached) && !empty($cached['consent']) && time() < (int) ($cached['retry'] ?? 0)) {
        return ['access' => '', 'consent' => true];
    }
    $refresh = (string) ($_SESSION['teams']['refresh'] ?? '');
    if ($refresh === '') {
        return ['access' => '', 'consent' => false];
    }
    $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/token', [
        'client_id' => GRAPH_CLIENT,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'scope' => $scope,
    ]);
    if ($res['status'] === 200 && !empty($res['data']['access_token'])) {
        $_SESSION['teams_scoped'][$key] = [
            'access' => (string) $res['data']['access_token'],
            'expires' => time() + (int) ($res['data']['expires_in'] ?? 3600),
        ];
        if (!empty($res['data']['refresh_token'])) {
            $_SESSION['teams']['refresh'] = (string) $res['data']['refresh_token'];
        }
        return ['access' => (string) $res['data']['access_token'], 'consent' => false];
    }
    if (in_array($res['error'], ['invalid_grant', 'consent_required', 'interaction_required', 'invalid_scope', 'unauthorized_client'], true)) {
        $_SESSION['teams_scoped'][$key] = ['access' => '', 'consent' => true, 'retry' => time() + 600];
        return ['access' => '', 'consent' => true];
    }
    return ['access' => '', 'consent' => false];
}

function graphCall(string $method, string $url, ?array $body, string $access, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json', 'Authorization: Bearer ' . $access], $extraHeaders);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 40,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($data) ? $data : []];
}

function knownEmails(): array
{
    $path = dirname(__DIR__) . '/data/daily.json';
    if (!is_readable($path)) {
        return [];
    }
    $saved = json_decode((string) file_get_contents($path), true);
    $known = [];
    foreach (is_array($saved) ? ($saved['people'] ?? []) : [] as $person) {
        if (!is_array($person)) {
            continue;
        }
        $email = strtolower(trim((string) ($person['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $known[$email] = true;
        }
    }
    return $known;
}

function presenceWord(string $availability, string $activity, bool $oof): string
{
    if ($oof) {
        return 'Out of office';
    }
    if ($availability === 'AvailableIdle') {
        return 'Away (idle)';
    }
    $words = [
        'Available' => 'Available',
        'AvailableIdle' => 'Away',
        'Busy' => 'Busy',
        'BusyIdle' => 'Busy',
        'Away' => 'Away',
        'BeRightBack' => 'Be right back',
        'DoNotDisturb' => 'Do not disturb',
        'Offline' => 'Offline',
        'OffWork' => 'Off work',
        'Inactive' => 'Away',
        'InACall' => 'In a call',
        'InAConferenceCall' => 'In a call',
        'InAMeeting' => 'In a meeting',
        'Presenting' => 'Presenting',
        'Focusing' => 'Focusing',
        'UrgentInterruptionsOnly' => 'Urgent interruptions only',
        'OutOfOffice' => 'Out of office',
        'PresenceUnknown' => 'Status unknown',
    ];
    return $words[$activity] ?? $words[$availability] ?? 'Status unknown';
}

function presenceTone(string $availability, string $activity, bool $oof): string
{
    if ($oof || $availability === 'OutOfOffice' || $activity === 'OutOfOffice') {
        return 'oof';
    }
    if ($availability === 'DoNotDisturb' || in_array($activity, ['DoNotDisturb', 'Presenting', 'UrgentInterruptionsOnly'], true)) {
        return 'dnd';
    }
    if (in_array($availability, ['Busy', 'BusyIdle', 'InACall', 'InAMeeting', 'Presenting', 'Focusing'], true)) {
        return 'busy';
    }
    if (in_array($availability, ['Away', 'BeRightBack', 'AvailableIdle'], true)) {
        return 'away';
    }
    if ($availability === 'Available') {
        return 'available';
    }
    if ($availability === 'Offline') {
        return 'offline';
    }
    return 'unknown';
}

function presenceView(array $row): array
{
    $availability = (string) ($row['availability'] ?? '');
    $activity = (string) ($row['activity'] ?? '');
    $oof = !empty($row['outOfOfficeSettings']['isOutOfOffice']);
    $location = is_array($row['workLocation'] ?? null) ? $row['workLocation'] : [];
    $type = (string) ($location['workLocationType'] ?? '');
    $source = (string) ($location['source'] ?? '');
    $place = '';
    $planned = false;
    $where = '';
    if ($type === 'office') {
        $place = 'office';
        $planned = $source === 'scheduled';
        $where = $planned ? 'planned in office' : 'in office';
    } elseif ($type === 'remote') {
        $place = 'remote';
        $where = 'remote';
    } elseif ($type === 'timeOff') {
        $place = 'off';
        $where = 'time off';
    }
    $word = presenceWord($availability, $activity, $oof);
    $tone = presenceTone($availability, $activity, $oof);
    return [
        'tone' => $tone,
        'place' => $place,
        'planned' => $planned,
        'active' => $tone !== 'offline' && $tone !== 'unknown',
        'label' => $where !== '' ? $word . ' · ' . $where : $word,
    ];
}

/**
 * GETs in Graph $batch requests of 20. A batch answers 200 even when every
 * request in it was refused, so the per-request statuses are checked too.
 *
 * @param array<string, string> $urls key => relative URL
 * @return array{denied: int, responses: array<string, array{status: int, body: array}>}
 */
function batchGet(array $urls, string $access): array
{
    $out = [];
    $refused = 0;
    foreach (array_chunk($urls, 20, true) as $chunk) {
        $keys = array_keys($chunk);
        $requests = [];
        foreach (array_values($chunk) as $index => $url) {
            $requests[] = ['id' => (string) $index, 'method' => 'GET', 'url' => $url];
        }
        $batch = graphCall('POST', 'https://graph.microsoft.com/v1.0/$batch', ['requests' => $requests], $access);
        if ($batch['status'] === 401 || $batch['status'] === 403) {
            return ['denied' => $batch['status'], 'responses' => []];
        }
        foreach ($batch['data']['responses'] ?? [] as $response) {
            $index = (int) ($response['id'] ?? -1);
            if (!is_array($response) || !isset($keys[$index])) {
                continue;
            }
            $status = (int) ($response['status'] ?? 0);
            if ($status === 401) {
                return ['denied' => 401, 'responses' => []];
            }
            if ($status === 403) {
                $refused++;
            }
            $out[(string) $keys[$index]] = ['status' => $status, 'body' => is_array($response['body'] ?? null) ? $response['body'] : []];
        }
    }
    $denied = $out && $refused === count($out) ? 403 : 0;
    return ['denied' => $denied, 'responses' => $out];
}

function odataText(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function lookupUsers(array $emails, string $access, string $select): array
{
    $users = [];
    $byMail = [];
    $answered = 0;
    $passes = [
        fn (string $email) => '/users/' . rawurlencode($email) . '?$select=' . $select,
        fn (string $email) => '/users?$filter=' . rawurlencode("mail eq '" . str_replace("'", "''", $email) . "'") . '&$select=' . $select . '&$top=1',
    ];
    foreach ($passes as $pass => $url) {
        $list = $pass === 0 ? $emails : $byMail;
        foreach (array_chunk($list, 20) as $chunk) {
            $requests = [];
            foreach ($chunk as $index => $email) {
                $requests[] = ['id' => (string) $index, 'method' => 'GET', 'url' => $url($email)];
            }
            $batch = graphCall('POST', 'https://graph.microsoft.com/v1.0/$batch', ['requests' => $requests], $access);
            if ($batch['status'] === 401 || $batch['status'] === 403) {
                return ['denied' => $batch['status'], 'users' => [], 'reached' => true];
            }
            if ($batch['ok']) {
                $answered++;
            }
            foreach ($batch['data']['responses'] ?? [] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $index = (int) ($response['id'] ?? -1);
                if ($index < 0 || !isset($chunk[$index])) {
                    continue;
                }
                $status = (int) ($response['status'] ?? 0);
                $body = is_array($response['body'] ?? null) ? $response['body'] : [];
                $user = $pass === 0 ? $body : (is_array($body['value'][0] ?? null) ? $body['value'][0] : []);
                if ($status === 200 && preg_match('/^[0-9a-fA-F-]{36}$/', (string) ($user['id'] ?? ''))) {
                    $users[$chunk[$index]] = $user;
                } elseif ($pass === 0 && $status === 404) {
                    $byMail[] = $chunk[$index];
                }
            }
        }
    }
    return ['denied' => 0, 'users' => $users, 'reached' => $answered > 0 || !$emails];
}


function lookupIds(array $emails, string $access): array
{
    $found = lookupUsers($emails, $access, 'id');
    $ids = [];
    foreach ($found['users'] as $email => $user) {
        $ids[$email] = (string) $user['id'];
    }
    return ['denied' => $found['denied'], 'ids' => $ids];
}

function requestedEmails(int $limit): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    $raw = is_array($body) ? ($body['emails'] ?? []) : [];
    if (!is_array($raw) || count($raw) > $limit) {
        fail(400, 'Send up to ' . $limit . ' addresses.');
    }
    $known = knownEmails();
    $emails = [];
    foreach ($raw as $item) {
        $email = strtolower(trim((string) $item));
        if (isset($known[$email])) {
            $emails[$email] = true;
        }
    }
    return array_keys($emails);
}

function peopleSource(): string
{
    if (strtolower((string) getenv('PIVOT_PEOPLE_SOURCE')) === 'sample') {
        return 'config';
    }
    $teams = $_SESSION['teams'] ?? null;
    if (!is_array($teams) || (((string) ($teams['access'] ?? '')) === '' && ((string) ($teams['refresh'] ?? '')) === '')) {
        return 'signed-out';
    }
    return '';
}

function profileView(array $user): array
{
    $phones = [];
    foreach (is_array($user['businessPhones'] ?? null) ? $user['businessPhones'] : [] as $phone) {
        if (is_string($phone) && trim($phone) !== '' && count($phones) < 3) {
            $phones[] = trim($phone);
        }
    }
    $mail = strtolower(trim((string) ($user['mail'] ?? '')));
    return [
        'found' => true,
        'displayName' => (string) ($user['displayName'] ?? ''),
        'mail' => filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '',
        'upn' => strtolower((string) ($user['userPrincipalName'] ?? '')),
        'jobTitle' => (string) ($user['jobTitle'] ?? ''),
        'department' => (string) ($user['department'] ?? ''),
        'officeLocation' => (string) ($user['officeLocation'] ?? ''),
        'businessPhones' => $phones,
        'mobilePhone' => (string) ($user['mobilePhone'] ?? ''),
    ];
}

function sampleRows(): array
{
    $rows = json_decode((string) @file_get_contents(dirname(__DIR__) . '/data/directory-sample.json'), true);
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function sampleDirectory(array $emails, string $reason): array
{
    $rows = sampleRows();
    $byMail = [];
    foreach ($rows as $row) {
        $byMail[strtolower((string) ($row['mail'] ?? ''))] = $row;
    }
    $people = [];
    foreach ($emails as $email) {
        $row = $byMail[$email] ?? ($rows ? $rows[crc32($email) % count($rows)] : []);
        $view = profileView(array_merge($row, ['mail' => $email, 'userPrincipalName' => $email]));
        if (!isset($byMail[$email])) {
            $view['displayName'] = '';
        }
        $state = is_array($row['presence'] ?? null) ? $row['presence'] : [];
        $view['presence'] = presenceView([
            'availability' => (string) ($state['availability'] ?? 'PresenceUnknown'),
            'activity' => (string) ($state['activity'] ?? ''),
            'outOfOfficeSettings' => ['isOutOfOffice' => !empty($state['outOfOffice'])],
        ]);
        $view['sample'] = true;
        $people[$email] = $view;
    }
    return ['ok' => true, 'source' => 'sample', 'reason' => $reason, 'profile' => 'sample', 'people' => $people];
}

function scheduleDay(string $raw): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4))) {
        return $raw;
    }
    return (new DateTimeImmutable('now', new DateTimeZone('America/Toronto')))->format('Y-m-d');
}

function clockOf(array $stamp): string
{
    $value = (string) ($stamp['dateTime'] ?? '');
    return preg_match('/T(\d{2}:\d{2})/', $value, $m) ? $m[1] : '';
}

function scheduleView(array $row): array
{
    $statuses = ['free', 'tentative', 'busy', 'oof', 'workingElsewhere'];
    $items = [];
    foreach (is_array($row['scheduleItems'] ?? null) ? $row['scheduleItems'] : [] as $item) {
        if (!is_array($item) || count($items) >= 12) {
            continue;
        }
        $status = (string) ($item['status'] ?? 'busy');
        $items[] = [
            'status' => in_array($status, $statuses, true) ? $status : 'busy',
            'start' => clockOf(is_array($item['start'] ?? null) ? $item['start'] : []),
            'end' => clockOf(is_array($item['end'] ?? null) ? $item['end'] : []),
            'subject' => empty($item['isPrivate']) ? (string) ($item['subject'] ?? '') : '',
            'location' => empty($item['isPrivate']) ? (string) ($item['location'] ?? '') : '',
        ];
    }
    $hours = is_array($row['workingHours'] ?? null) ? $row['workingHours'] : [];
    return [
        'view' => preg_replace('/[^0-4]/', '', (string) ($row['availabilityView'] ?? '')),
        'items' => $items,
        'workingHours' => [
            'start' => substr((string) ($hours['startTime'] ?? ''), 0, 5),
            'end' => substr((string) ($hours['endTime'] ?? ''), 0, 5),
            'days' => array_values(array_filter(is_array($hours['daysOfWeek'] ?? null) ? $hours['daysOfWeek'] : [], 'is_string')),
        ],
    ];
}

function sampleSchedule(string $email, string $day): array
{
    $seed = crc32($email . '|' . $day);
    $view = '';
    for ($slot = 0; $slot < 20; $slot++) {
        $roll = ($seed >> ($slot % 24)) ^ ($slot * 2654435761);
        $view .= ($slot === 8 || $slot === 9) ? '0' : (($roll & 7) === 0 ? '2' : (($roll & 15) === 3 ? '1' : '0'));
    }
    return ['view' => $view, 'items' => [], 'workingHours' => ['start' => '08:00', 'end' => '16:00', 'days' => []]];
}

function lookupPresence(array $ids, string $access): array
{
    $byId = [];
    foreach (array_chunk(array_values(array_unique($ids)), 600) as $chunk) {
        $res = graphCall('POST', 'https://graph.microsoft.com/v1.0/communications/getPresencesByUserId', ['ids' => $chunk], $access);
        if ($res['status'] === 401 || $res['status'] === 403) {
            return ['denied' => $res['status'], 'rows' => []];
        }
        if (!$res['ok']) {
            $urls = [];
            foreach ($chunk as $id) {
                $urls[$id] = '/users/' . rawurlencode($id) . '/presence';
            }
            $batch = batchGet($urls, $access);
            if ($batch['denied']) {
                return ['denied' => $batch['denied'], 'rows' => []];
            }
            foreach ($batch['responses'] as $id => $response) {
                if ($response['status'] === 200) {
                    $byId[$id] = $response['body'];
                }
            }
            continue;
        }
        foreach ($res['data']['value'] ?? [] as $row) {
            if (is_array($row) && isset($row['id']) && in_array((string) $row['id'], $chunk, true)) {
                $byId[(string) $row['id']] = $row;
            }
        }
    }
    return ['denied' => 0, 'rows' => $byId];
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'shape') {
    echo json_encode(presenceView([
        'availability' => 'Available',
        'activity' => 'Available',
        'outOfOfficeSettings' => ['isOutOfOffice' => false],
        'workLocation' => ['workLocationType' => 'office', 'source' => 'scheduled'],
    ]));
    exit;
}

$action = (string) ($_GET['action'] ?? '');

if ($action === 'session') {
    $teams = $_SESSION['teams'] ?? null;
    $signed = is_array($teams) && (((string) ($teams['access'] ?? '')) !== '' || ((string) ($teams['refresh'] ?? '')) !== '');
    echo json_encode(['ok' => true, 'signedIn' => $signed || savedRefresh() !== '']);
    exit;
}

if ($action === 'forget') {
    unset($_SESSION['teams'], $_SESSION['teams_device'], $_SESSION['teams_device_expires'], $_SESSION['teams_next'], $_SESSION['teams_interval'], $_SESSION['teams_presence'], $_SESSION['teams_scoped'], $_SESSION['teams_directory'], $_SESSION['teams_schedule']);
    rememberTeams('');
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'login') {
    $scope = GRAPH_SCOPE;
    if (($_GET['calendar'] ?? '') === '1') {
        $scope .= ' https://graph.microsoft.com/Calendars.ReadBasic';
    }
    $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/devicecode', [
        'client_id' => GRAPH_CLIENT,
        'scope' => $scope,
    ]);
    $userCode = (string) ($res['data']['user_code'] ?? '');
    $deviceCode = (string) ($res['data']['device_code'] ?? '');
    if ($res['status'] !== 200 || !preg_match('/^[A-Z0-9]{4,16}$/', $userCode) || $deviceCode === '') {
        $reason = (string) ($res['data']['error_description'] ?? $res['data']['error'] ?? '');
        fail(502, $reason !== '' ? 'Teams sign-in did not start. ' . strtok($reason, "\r\n") : 'Teams sign-in did not start. Try again.');
    }
    $interval = max(5, (int) ($res['data']['interval'] ?? 5));
    $_SESSION['teams_device'] = $deviceCode;
    $_SESSION['teams_device_expires'] = time() + (int) ($res['data']['expires_in'] ?? 900);
    $_SESSION['teams_next'] = time() + $interval;
    $_SESSION['teams_interval'] = $interval;
    $url = (string) ($res['data']['verification_uri'] ?? 'https://login.microsoftonline.com/common/oauth2/deviceauth');
    $complete = (string) ($res['data']['verification_uri_complete'] ?? '');
    if ($complete === '' || !str_starts_with($complete, 'https://login.microsoftonline.com/') && !str_starts_with($complete, 'https://login.microsoft.com/')) {
        $complete = $url . (str_contains($url, '?') ? '&' : '?') . 'user_code=' . rawurlencode($userCode);
    }
    echo json_encode([
        'ok' => true,
        'user_code' => $userCode,
        'url' => $url,
        'complete' => $complete,
        'interval' => $interval,
    ]);
    exit;
}

if ($action === 'loginpoll') {
    $device = (string) ($_SESSION['teams_device'] ?? '');
    if ($device === '') {
        $teams = $_SESSION['teams'] ?? null;
        if (is_array($teams) && ($teams['access'] ?? '') !== '') {
            echo json_encode(['ok' => true, 'signedIn' => true]);
            exit;
        }
        fail(400, 'Teams sign-in has not started.', true);
    }
    if (time() > (int) ($_SESSION['teams_device_expires'] ?? 0)) {
        unset($_SESSION['teams_device'], $_SESSION['teams_device_expires'], $_SESSION['teams_next']);
        fail(401, 'That Teams sign-in expired. Start it again.', true);
    }
    if (time() < (int) ($_SESSION['teams_next'] ?? 0)) {
        echo json_encode(['ok' => true, 'pending' => true]);
        exit;
    }
    $interval = max(5, (int) ($_SESSION['teams_interval'] ?? 5));
    $_SESSION['teams_next'] = time() + $interval;
    $res = tokenRequest('https://login.microsoftonline.com/' . TENANT . '/oauth2/v2.0/token', [
        'client_id' => GRAPH_CLIENT,
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device,
    ]);
    if ($res['status'] === 200 && !empty($res['data']['access_token'])) {
        storeTeams($res['data']);
        echo json_encode(['ok' => true, 'signedIn' => true]);
        exit;
    }
    $error = (string) ($res['data']['error'] ?? '');
    if ($error === 'authorization_pending') {
        echo json_encode(['ok' => true, 'pending' => true]);
        exit;
    }
    if ($error === 'slow_down') {
        $_SESSION['teams_interval'] = $interval + 5;
        $_SESSION['teams_next'] = time() + $interval + 5;
        echo json_encode(['ok' => true, 'pending' => true]);
        exit;
    }
    unset($_SESSION['teams_device'], $_SESSION['teams_device_expires'], $_SESSION['teams_next']);
    if ($error === 'access_denied' || $error === 'authorization_declined') {
        fail(401, 'Teams presence needs an administrator to allow it for this sign-in. Ask, then connect again.', true);
    }
    fail(401, 'Teams did not accept this sign-in. Connect again.', true);
}

if ($action === 'presence') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the addresses in a POST.');
    }
    $access = teamsBearer();
    if ($access === '') {
        fail(401, 'Connect Teams to read presence.', true);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $raw = is_array($body) ? ($body['emails'] ?? []) : [];
    if (!is_array($raw) || count($raw) > 40) {
        fail(400, 'Send up to 40 addresses.');
    }
    $known = knownEmails();
    $emails = [];
    $unmatched = [];
    foreach ($raw as $item) {
        $email = strtolower(trim((string) $item));
        if (isset($known[$email])) {
            $emails[$email] = true;
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $unmatched[$email] = ['tone' => 'unknown', 'place' => '', 'planned' => false, 'active' => false, 'label' => 'Sync the chart so this address can be matched to Teams'];
        }
    }
    $emails = array_keys($emails);
    $cached = $_SESSION['teams_presence'] ?? null;
    $fresh = is_array($cached) && (time() - (int) ($cached['at'] ?? 0) < 45) && is_array($cached['rows'] ?? null);
    $missing = [];
    foreach ($emails as $email) {
        if (!$fresh || !array_key_exists($email, $cached['rows'])) {
            $missing[] = $email;
        }
    }
    if ($missing) {
        releaseSession();
        $found = lookupIds($missing, $access);
        if ($found['denied'] === 401) {
            sessionStart();
            unset($_SESSION['teams'], $_SESSION['teams_presence']);
            rememberTeams('');
            fail(401, 'Connect Teams again to read presence.', true);
        }
        if ($found['denied'] === 403) {
            fail(403, 'Teams could not look up people. An administrator has to allow User.ReadBasic.All for this sign-in.');
        }
        $presences = lookupPresence($found['ids'], $access);
        if ($presences['denied'] === 401) {
            sessionStart();
            unset($_SESSION['teams'], $_SESSION['teams_presence']);
            rememberTeams('');
            fail(401, 'Connect Teams again to read presence.', true);
        }
        if ($presences['denied'] === 403) {
            fail(403, 'Teams presence needs an administrator to allow Presence.Read.All for this sign-in.');
        }
        sessionStart();
        $rows = is_array($_SESSION['teams_presence']['rows'] ?? null) ? $_SESSION['teams_presence']['rows'] : [];
        foreach ($missing as $email) {
            $id = $found['ids'][$email] ?? '';
            if ($id === '') {
                $rows[$email] = ['tone' => 'unknown', 'place' => '', 'planned' => false, 'active' => false, 'label' => 'No Teams account for this address'];
            } elseif (is_array($presences['rows'][$id] ?? null)) {
                $rows[$email] = presenceView($presences['rows'][$id]);
            } else {
                $rows[$email] = ['tone' => 'unknown', 'place' => '', 'planned' => false, 'active' => false, 'label' => 'Teams did not return a status'];
            }
        }
        $_SESSION['teams_presence'] = ['at' => time(), 'rows' => $rows];
        $cached = $_SESSION['teams_presence'];
    }
    $people = $unmatched;
    foreach ($emails as $email) {
        if (isset($cached['rows'][$email]) && is_array($cached['rows'][$email])) {
            $people[$email] = $cached['rows'][$email];
        }
    }
    echo json_encode(['ok' => true, 'signedIn' => true, 'people' => $people]);
    exit;
}

if ($action === 'directory') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the addresses in a POST.');
    }
    $emails = requestedEmails(40);
    $reason = peopleSource();
    $access = $reason === '' ? teamsBearer() : '';
    if ($access === '') {
        echo json_encode(sampleDirectory($emails, $reason !== '' ? $reason : 'signed-out'));
        exit;
    }
    $rows = is_array($_SESSION['teams_directory']['rows'] ?? null) ? $_SESSION['teams_directory']['rows'] : [];
    $missing = array_values(array_filter($emails, fn (string $email) => !isset($rows[$email]) || time() - (int) ($rows[$email]['at'] ?? 0) >= DIRECTORY_SECONDS));
    $profile = scopedBearer('profile', GRAPH_PROFILE_SCOPE);
    if ($missing) {
        releaseSession();
        $full = $profile['access'] !== '';
        $found = lookupUsers($missing, $full ? $profile['access'] : $access, PROFILE_FIELDS);
        if ($found['denied'] === 401) {
            sessionStart();
            unset($_SESSION['teams'], $_SESSION['teams_presence'], $_SESSION['teams_scoped'], $_SESSION['teams_directory']);
            echo json_encode(sampleDirectory($emails, 'signed-out'));
            exit;
        }
        if ($found['denied'] === 403) {
            fail(403, 'Reading the directory needs an administrator to allow User.ReadBasic.All for this sign-in.');
        }
        if (!$found['reached']) {
            echo json_encode(sampleDirectory($emails, 'graph-unreachable'));
            exit;
        }
        sessionStart();
        $rows = is_array($_SESSION['teams_directory']['rows'] ?? null) ? $_SESSION['teams_directory']['rows'] : [];
        foreach ($missing as $email) {
            $user = $found['users'][$email] ?? null;
            $rows[$email] = is_array($user)
                ? profileView($user) + ['id' => (string) $user['id'], 'full' => $full, 'at' => time()]
                : ['found' => false, 'at' => time()];
        }
        $_SESSION['teams_directory'] = ['rows' => array_slice($rows, -400, null, true)];
    }
    $cachedPresence = $_SESSION['teams_presence'] ?? null;
    $freshPresence = is_array($cachedPresence) && (time() - (int) ($cachedPresence['at'] ?? 0) < 45) && is_array($cachedPresence['rows'] ?? null);
    $presenceRows = $freshPresence ? $cachedPresence['rows'] : [];
    $stale = [];
    foreach ($emails as $email) {
        if (!empty($rows[$email]['id']) && !isset($presenceRows[$email])) {
            $stale[$email] = (string) $rows[$email]['id'];
        }
    }
    if ($stale) {
        releaseSession();
        $presences = lookupPresence($stale, $access);
        sessionStart();
        if ($presences['denied'] === 0) {
            $merged = is_array($_SESSION['teams_presence']['rows'] ?? null) ? $_SESSION['teams_presence']['rows'] : [];
            foreach ($stale as $email => $id) {
                if (is_array($presences['rows'][$id] ?? null)) {
                    $merged[$email] = presenceView($presences['rows'][$id]);
                    $presenceRows[$email] = $merged[$email];
                }
            }
            $_SESSION['teams_presence'] = ['at' => time(), 'rows' => $merged];
        }
    }
    $people = [];
    $fullProfile = $profile['access'] !== '';
    foreach ($emails as $email) {
        $row = $rows[$email] ?? ['found' => false];
        if (isset($row['full'])) {
            $fullProfile = $fullProfile && !empty($row['full']);
        }
        unset($row['id'], $row['at'], $row['full']);
        $row['presence'] = $presenceRows[$email] ?? null;
        $row['sample'] = false;
        $people[$email] = $row;
    }
    echo json_encode(['ok' => true, 'source' => 'graph', 'profile' => $fullProfile ? 'full' : 'basic', 'people' => $people]);
    exit;
}

if ($action === 'schedule') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail(400, 'Send the address in a POST.');
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    $email = strtolower(trim((string) (is_array($body) ? ($body['email'] ?? '') : '')));
    if (!isset(knownEmails()[$email])) {
        fail(404, 'That address is not on the chart.');
    }
    $day = scheduleDay((string) (is_array($body) ? ($body['date'] ?? '') : ''));
    $window = ['day' => $day, 'start' => '08:00', 'end' => '18:00', 'interval' => 30];
    $reason = peopleSource();
    $access = $reason === '' ? teamsBearer() : '';
    if ($access === '') {
        echo json_encode(['ok' => true, 'source' => 'sample', 'reason' => $reason !== '' ? $reason : 'signed-out'] + $window + sampleSchedule($email, $day));
        exit;
    }
    $calendar = scopedBearer('calendar', GRAPH_CALENDAR_SCOPE);
    if ($calendar['access'] === '') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'calendar' => true, 'error' => $calendar['consent']
            ? 'Free and busy times need calendar access (Calendars.ReadBasic) for this sign-in.'
            : 'The calendar sign-in did not answer. Try again.']);
        exit;
    }
    $key = $email . '|' . $day;
    $hit = $_SESSION['teams_schedule'][$key] ?? null;
    if (is_array($hit) && time() - (int) ($hit['at'] ?? 0) < SCHEDULE_SECONDS && is_array($hit['view'] ?? null)) {
        echo json_encode(['ok' => true, 'source' => 'graph'] + $window + $hit['view']);
        exit;
    }
    $target = (string) ($_SESSION['teams_directory']['rows'][$email]['mail'] ?? '');
    releaseSession();
    $res = graphCall('POST', 'https://graph.microsoft.com/v1.0/me/calendar/getSchedule', [
        'schedules' => [$target !== '' ? $target : $email],
        'startTime' => ['dateTime' => $day . 'T' . $window['start'] . ':00', 'timeZone' => SCHEDULE_ZONE],
        'endTime' => ['dateTime' => $day . 'T' . $window['end'] . ':00', 'timeZone' => SCHEDULE_ZONE],
        'availabilityViewInterval' => $window['interval'],
    ], $calendar['access'], ['Prefer: outlook.timezone="' . SCHEDULE_ZONE . '"']);
    if ($res['status'] === 401 || $res['status'] === 403) {
        sessionStart();
        $_SESSION['teams_scoped']['calendar'] = ['access' => '', 'consent' => $res['status'] === 403, 'retry' => time() + 600];
        http_response_code($res['status']);
        echo json_encode(['ok' => false, 'calendar' => true, 'error' => 'Free and busy times need calendar access (Calendars.ReadBasic) for this sign-in.']);
        exit;
    }
    $row = $res['ok'] && is_array($res['data']['value'][0] ?? null) ? $res['data']['value'][0] : null;
    if ($row === null) {
        fail(502, 'The calendar did not answer. Try again.');
    }
    if (is_array($row['error'] ?? null) && (string) ($row['availabilityView'] ?? '') === '') {
        fail(404, 'Outlook does not share this person’s free and busy times.');
    }
    $view = scheduleView($row);
    sessionStart();
    $saved = is_array($_SESSION['teams_schedule'] ?? null) ? $_SESSION['teams_schedule'] : [];
    $saved[$key] = ['at' => time(), 'view' => $view];
    $_SESSION['teams_schedule'] = array_slice($saved, -60, null, true);
    echo json_encode(['ok' => true, 'source' => 'graph'] + $window + $view);
    exit;
}

fail(404, 'Unknown Teams action.');
