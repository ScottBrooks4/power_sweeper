<?php
declare(strict_types=1);

/**
 * Project status for the Digital Office, from the Project Scores list.
 *
 * Two files are involved:
 *   data/do-scores.csv      the SharePoint export. Replacing it is how the site
 *                           is refreshed. Never written to.
 *   data/do-status-edits.json   decisions and feature ticks made in this site.
 *
 * An edit wins over the export unless the export is newer, so re-exporting the
 * list with a corrected status takes effect instead of being masked by an old
 * change made here.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const STATUS_FILE = 'do-scores.csv';
const EDIT_FILE = 'do-status-edits.json';

/** The statuses the list uses, in the order the standing agenda reads them. */
const STATUSES = [
    'New',
    'Prioritized',
    'In Dev',
    'UAT',
    'Completed',
    'Deferred',
    'Declined',
    'Decommissioned'
];

/** The six features every project Epic carries, from SOP-001 6.1.3. */
const FEATURES = [
    'architecture' => 'Project Architecture',
    'backend' => 'Back-end development',
    'frontend' => 'Front-end development',
    'uat' => 'UAT Testing',
    'production' => 'Production',
    'maintenance' => 'Maintenance'
];

$dataDir = dirname(__DIR__) . '/data';

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
        'samesite' => 'Lax'
    ]);
    session_start();
}

function signedIn(): bool
{
    if (trim((string) ($_SESSION['ado_pat'] ?? '')) !== '') {
        return true;
    }
    $ado = $_SESSION['ado'] ?? null;
    return is_array($ado) && trim((string) ($ado['access'] ?? '')) !== '';
}

function trimText(mixed $value, int $limit = 900): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit);
    }
    return substr($text, 0, $limit);
}

function numberText(mixed $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    return preg_match('/^-?\d+(?:\.\d+)?$/', $text) ? $text : '';
}

function dateText(mixed $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $stamp = strtotime($text);
    return $stamp === false ? '' : gmdate('Y-m-d', $stamp);
}

/**
 * The roadblocks and note columns hold SharePoint rich text. Strip the markup so
 * the pages can print the words without trusting the export's HTML.
 */
function plainText(mixed $value, int $limit = 900): string
{
    $text = (string) $value;
    $text = preg_replace('/<br\s*\/?>/i', ' ', $text);
    $text = preg_replace('/<\/(?:p|div|li|h[1-6]|tr)>/i', ' ', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/\.(?:\s*\.)+/', '.', $text);
    $text = trimText($text, $limit);
    // An empty SharePoint rich-text cell reduces to a lone full stop.
    return preg_match('/^[\s.,;:–—-]*$/u', $text) ? '' : $text;
}

/** "Carter, Patrick@VCDS Gp HQ / VCEMD QG Gp@Defence365" reads as "Patrick Carter". */
function personText(mixed $value): string
{
    $text = trimText($value, 160);
    if ($text === '') {
        return '';
    }
    $name = explode('@', $text)[0];
    if (str_contains($name, ',')) {
        [$last, $first] = array_map('trim', explode(',', $name, 2));
        if ($first !== '' && $last !== '') {
            return $first . ' ' . $last;
        }
    }
    return $name;
}

/** The list writes "Decomissioned" with one m; the site uses the real word. */
function statusName(string $value): string
{
    $text = trim($value);
    return preg_match('/^decom{1,2}issioned$/i', $text) ? 'Decommissioned' : $text;
}

function statusFile(string $dir): string
{
    return $dir . '/' . STATUS_FILE;
}

function editFile(string $dir): string
{
    return $dir . '/' . EDIT_FILE;
}

/** @return array<string, array<string, mixed>> */
function readEdits(string $dir): array
{
    $path = editFile($dir);
    if (!is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    // Shared, because two people can tick a box at the same moment.
    if (flock($handle, LOCK_SH)) {
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $rows = json_decode((string) $raw, true);
        return is_array($rows) ? $rows : [];
    }
    fclose($handle);
    return [];
}

/**
 * @param array<string, array<string, mixed>> $rows
 */
function writeEdits(string $dir, array $rows): bool
{
    $path = editFile($dir);
    ksort($rows, SORT_NUMERIC);
    $encoded = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }
    $handle = fopen($path, 'cb');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    ftruncate($handle, 0);
    rewind($handle);
    $written = fwrite($handle, $encoded . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $written !== false;
}

function statusRows(string $dir): array
{
    $file = statusFile($dir);
    if (!is_readable($file)) {
        return ['rows' => [], 'updated' => '', 'count' => 0, 'missing' => true];
    }

    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return ['rows' => [], 'updated' => '', 'count' => 0, 'missing' => true];
    }

    $header = fgetcsv($handle, 0, ',', '"', '');
    if (!is_array($header)) {
        fclose($handle);
        return ['rows' => [], 'updated' => '', 'count' => 0, 'missing' => true];
    }

    // Excel writes a byte-order mark before the first column name. Because the
    // mark sits in front of the opening quote, the first field keeps its quotes,
    // so the mark comes off before the quotes do.
    $header = array_map(static function (mixed $name): string {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $name);
        return trim(trim((string) $text), "\"");
    }, $header);

    $at = [];
    foreach ($header as $place => $name) {
        $at[trim((string) $name)] = $place;
    }

    $pick = static function (array $row, string $column) use ($at): string {
        if (!isset($at[$column])) {
            return '';
        }
        return (string) ($row[$at[$column]] ?? '');
    };

    $rows = [];
    $updated = '';
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (!is_array($row) || $row === [null] || count($row) < 2) {
            continue;
        }
        $id = trim($pick($row, 'UseCaseID'));
        if (!preg_match('/^\d{1,7}$/', $id)) {
            continue;
        }
        $modified = dateText($pick($row, 'UseCaseID: Modified'));
        if ($modified !== '' && $modified > $updated) {
            $updated = $modified;
        }
        $rows[$id] = [
            'id' => $id,
            'title' => trimText($pick($row, 'Title'), 300),
            'projectStatus' => statusName($pick($row, 'Project Status')),
            'scoreStatus' => trimText($pick($row, 'Status'), 60),
            'recommendation' => trimText($pick($row, 'Recommendation'), 60),
            'rag' => trimText($pick($row, 'RAG'), 20),
            'effort' => numberText($pick($row, 'Score – Effort')),
            'confidence' => numberText($pick($row, 'Confidence')),
            'weighted' => numberText($pick($row, 'Weighted Score')),
            'adjusted' => numberText($pick($row, 'Adjusted Score')),
            'lastScored' => dateText($pick($row, 'Last Scored On')),
            'review' => trimText($pick($row, 'Reviewed'), 12) === 'True',
            'securityReview' => trimText($pick($row, 'Security/IM Review Required'), 12) === 'True',
            'userReach' => trimText($pick($row, 'User Reach'), 80),
            'roadblocks' => plainText($pick($row, 'Current Roadblocks'), 900),
            'lead' => personText($pick($row, 'Project Lead / OPI')),
            'dataLead' => personText($pick($row, 'Data Lead')),
            'modified' => $modified
        ];
    }
    fclose($handle);

    return ['rows' => $rows, 'updated' => $updated, 'count' => count($rows), 'missing' => false];
}

/**
 * Fold the decisions made here onto the export. An edit only wins while it is at
 * least as new as the row it came from.
 *
 * @param array<string, array<string, mixed>> $rows
 * @param array<string, array<string, mixed>> $edits
 */
function applyEdits(array $rows, array $edits, array &$changed): array
{
    foreach ($edits as $id => $edit) {
        $key = (string) $id;
        if (!is_array($edit) || !isset($rows[$key])) {
            continue;
        }
        $at = (string) ($edit['at'] ?? '');
        $stamp = $at !== '' ? strtotime($at) : false;
        $listed = (string) ($rows[$key]['modified'] ?? '');
        if ($stamp !== false && $listed !== '' && strtotime($listed . ' 23:59:59') > $stamp) {
            // The export moved on after this change, so the export wins.
            continue;
        }
        if (isset($edit['projectStatus']) && is_string($edit['projectStatus'])) {
            $name = statusName($edit['projectStatus']);
            if (in_array($name, STATUSES, true)) {
                $rows[$key]['projectStatus'] = $name;
                $rows[$key]['statusFromSite'] = true;
            }
        }
        if (isset($edit['features']) && is_array($edit['features'])) {
            $ticks = [];
            foreach (array_keys(FEATURES) as $feature) {
                $ticks[$feature] = !empty($edit['features'][$feature]);
            }
            $rows[$key]['features'] = $ticks;
            $rows[$key]['featuresFromSite'] = true;
        }
        if ($at !== '') {
            $rows[$key]['editedAt'] = substr($at, 0, 19);
            $changed[] = $key;
        }
    }
    // Features the list has never carried still need a shape for the page.
    foreach ($rows as $id => $row) {
        if (!isset($row['features'])) {
            $blank = [];
            foreach (array_keys(FEATURES) as $feature) {
                $blank[$feature] = false;
            }
            $rows[$id]['features'] = $blank;
            $rows[$id]['featuresFromSite'] = false;
        }
    }
    return $rows;
}

function fail(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error, 'login' => $status === 401]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $data = statusRows($dataDir);
    $changed = [];
    $data['rows'] = applyEdits($data['rows'], readEdits($dataDir), $changed);
    echo json_encode([
        'ok' => true,
        'count' => count($data['rows']),
        'updated' => $data['updated'],
        'missing' => $data['missing'],
        'statuses' => STATUSES,
        'features' => FEATURES,
        'edited' => count($changed),
        'note' => $data['missing']
            ? 'The Project Scores list has not been published to this site yet.'
            : '',
        'rows' => $data['rows']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    fail(405, 'Use GET or POST.');
}

sessionStart();
if (!signedIn()) {
    fail(401, 'Sign in to change a status.');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    fail(400, 'Send the change as JSON.');
}

$id = (string) ($payload['id'] ?? '');
if (!preg_match('/^\d{1,7}$/', $id)) {
    fail(400, 'Unknown use case.');
}

$current = statusRows($dataDir);
if (!isset($current['rows'][$id])) {
    fail(404, 'That use case is not on the Project Scores list.');
}

$edits = readEdits($dataDir);
$row = is_array($edits[$id] ?? null) ? $edits[$id] : [];

if (array_key_exists('projectStatus', $payload)) {
    $want = statusName((string) $payload['projectStatus']);
    if ($want === '') {
        unset($row['projectStatus']);
    } elseif (!in_array($want, STATUSES, true)) {
        fail(400, 'Status must be one of: ' . implode(', ', STATUSES) . '.');
    } else {
        $row['projectStatus'] = $want;
    }
}

if (array_key_exists('features', $payload)) {
    $given = $payload['features'];
    if (!is_array($given)) {
        fail(400, 'Features must be a list.');
    }
    $ticks = is_array($row['features'] ?? null) ? $row['features'] : [];
    foreach (array_keys(FEATURES) as $feature) {
        if (array_key_exists($feature, $given)) {
            $ticks[$feature] = (bool) $given[$feature];
        }
    }
    $row['features'] = $ticks;
}

if (!isset($row['projectStatus']) && !isset($row['features'])) {
    fail(400, 'Nothing to change.');
}

$row['at'] = gmdate('c');
$row['by'] = trimText($_SESSION['ado_name'] ?? '', 80);
$edits[$id] = $row;

if (!writeEdits($dataDir, $edits)) {
    fail(500, 'The change could not be saved to the site.');
}

$changed = [];
$fresh = applyEdits($current['rows'], [$id => $row], $changed);

echo json_encode([
    'ok' => true,
    'id' => $id,
    'status' => $fresh[$id] ?? null
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
