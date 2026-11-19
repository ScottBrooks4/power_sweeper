<?php
declare(strict_types=1);

/**
 * Dated decision history for the leadership brief.
 *
 * The Project Scores export keeps one snapshot per use case: the scoring
 * recommendation with the day it was scored, and the current project status
 * with the day the row was last modified. Pivot's own status edits carry the
 * moment they were made. Neither keeps earlier decisions, so this endpoint also
 * keeps data/do-decision-log.json: whenever it is read, a use case whose status
 * differs from the last one seen is logged with a date. From the day the log
 * starts, that is what tells one project deferred twice from two projects
 * deferred once.
 *
 * The edit-versus-export rule matches api/status.php: an edit wins unless the
 * export row was modified after it.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const STATUS_FILE = 'do-scores.csv';
const EDIT_FILE = 'do-status-edits.json';
const LOG_FILE = 'do-decision-log.json';

/** Scoring recommendations, worded as the decision they recommend. */
const RECOMMENDED = [
    'defer' => 'Deferred',
    'decline' => 'Declined',
    'approve' => 'Approved'
];

$dataDir = dirname(__DIR__) . '/data';

function dayText(string $value): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }
    $stamp = strtotime($text);
    return $stamp === false ? '' : date('Y-m-d', $stamp);
}

function statusName(string $value): string
{
    $text = trim($value);
    if (preg_match('/^decom{1,2}issioned$/i', $text)) {
        return 'Decommissioned';
    }
    return preg_match('/^dev$/i', $text) ? 'In Dev' : $text;
}

/** @return array<string, array{status: string, recommended: string, scored: string, modified: string}> */
function exportRows(string $dir): array
{
    $handle = @fopen($dir . '/' . STATUS_FILE, 'rb');
    if ($handle === false) {
        return [];
    }
    $header = fgetcsv($handle, 0, ',', '"', '');
    if (!is_array($header)) {
        fclose($handle);
        return [];
    }
    $at = [];
    foreach ($header as $place => $name) {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $name);
        $at[trim(trim((string) $text), '"')] = $place;
    }
    $pick = static function (array $row, string $column) use ($at): string {
        return isset($at[$column]) ? trim((string) ($row[$at[$column]] ?? '')) : '';
    };
    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if (!is_array($row) || count($row) < 2) {
            continue;
        }
        $id = $pick($row, 'UseCaseID');
        if (!preg_match('/^\d{1,7}$/', $id)) {
            continue;
        }
        $rows[$id] = [
            'status' => statusName($pick($row, 'Project Status')),
            'recommended' => RECOMMENDED[strtolower($pick($row, 'Recommendation'))] ?? '',
            'scored' => dayText($pick($row, 'Last Scored On')),
            'modified' => dayText($pick($row, 'UseCaseID: Modified'))
        ];
    }
    fclose($handle);
    return $rows;
}

function readJson(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    $raw = flock($handle, LOCK_SH) ? stream_get_contents($handle) : '';
    flock($handle, LOCK_UN);
    fclose($handle);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * The status each use case has now, the day it took that status, and whether
 * the status came from an edit made in Pivot.
 *
 * The export has no status date. Decisions are taken at the scoring meetings, so
 * a status from the export is dated by "Last Scored On". Its "Modified" column
 * belongs to the use case, not the decision, and is only a fallback for rows
 * that were never scored.
 *
 * @return array<string, array{status: string, day: string, scored: string, recommended: string, source: string}>
 */
function currentStatuses(array $rows, array $edits): array
{
    $now = [];
    foreach ($rows as $id => $row) {
        $now[$id] = [
            'status' => $row['status'],
            'day' => $row['scored'] !== '' ? $row['scored'] : $row['modified'],
            'scored' => $row['scored'],
            'recommended' => $row['recommended'],
            'source' => $row['scored'] !== '' ? 'list' : 'usecase'
        ];
        $edit = $edits[$id] ?? null;
        if (!is_array($edit) || !is_string($edit['projectStatus'] ?? null)) {
            continue;
        }
        $stamp = strtotime((string) ($edit['at'] ?? ''));
        if ($stamp === false) {
            continue;
        }
        if ($row['modified'] !== '' && strtotime($row['modified'] . ' 23:59:59') > $stamp) {
            continue;
        }
        $now[$id]['status'] = statusName($edit['projectStatus']);
        $now[$id]['day'] = date('Y-m-d', $stamp);
        $now[$id]['source'] = 'pivot';
    }
    return $now;
}

/**
 * Log every status change, and every re-scoring, since the last read. A use
 * case seen for the first time is only recorded, because a status already in
 * place has no change date; that first status is kept as its baseline.
 *
 * @param array<string, array{status: string, day: string, scored: string, recommended: string, source: string}> $now
 * @return array{log: array, writable: bool}
 */
function updateLog(string $dir, array $now): array
{
    $path = $dir . '/' . LOG_FILE;
    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return ['log' => readJson($path), 'writable' => false];
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return ['log' => readJson($path), 'writable' => false];
    }
    $log = json_decode((string) stream_get_contents($handle), true);
    if (!is_array($log)) {
        $log = [];
    }
    $today = date('Y-m-d');
    $log['started'] = (string) ($log['started'] ?? $today);
    $seen = is_array($log['seen'] ?? null) ? $log['seen'] : [];
    $changes = is_array($log['changes'] ?? null) ? $log['changes'] : [];
    $dirty = !isset($log['seen']);
    foreach ($now as $id => $row) {
        $key = (string) $id;
        $before = is_array($seen[$key] ?? null) ? $seen[$key] : null;
        if ($before === null) {
            $seen[$key] = [
                'status' => $row['status'],
                'scored' => $row['scored'],
                'base' => [
                    'status' => $row['status'],
                    'date' => $row['day'],
                    'source' => $row['source'],
                    'recommended' => $row['recommended'],
                    'scored' => $row['scored']
                ]
            ];
            $dirty = true;
            continue;
        }
        $moved = (string) ($before['status'] ?? '') !== $row['status'];
        $rescored = $row['scored'] !== '' && $row['scored'] > (string) ($before['scored'] ?? '');
        if (!$moved && !$rescored) {
            continue;
        }
        if ($moved && $row['source'] === 'pivot') {
            $day = $row['day'];
            $source = 'pivot';
        } elseif ($rescored) {
            $day = $row['scored'];
            $source = 'rescored';
        } else {
            $day = $today;
            $source = 'observed';
        }
        $changes[] = [
            'id' => $key,
            'from' => (string) ($before['status'] ?? ''),
            'to' => $row['status'],
            'recommended' => $row['recommended'],
            'date' => $day,
            'source' => $source
        ];
        $seen[$key]['status'] = $row['status'];
        $seen[$key]['scored'] = $row['scored'];
        $dirty = true;
    }
    if ($dirty) {
        ksort($seen, SORT_NUMERIC);
        $log['seen'] = $seen;
        $log['changes'] = $changes;
        $encoded = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded . "\n");
            fflush($handle);
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return ['log' => $log, 'writable' => true];
}

function isDecision(string $outcome): bool
{
    return $outcome !== '' && strcasecmp($outcome, 'New') !== 0;
}

/**
 * One dated list of decisions per use case: the scoring recommendation, the
 * baseline status, then each logged change.
 *
 * Two decisions on one day are one meeting, and the status is its final word.
 * A later decision that only repeats the one before records it again rather
 * than making it again, unless the project was scored again in between.
 */
function decisionEvents(array $rows, array $now, array $seen, array $changes): array
{
    $logged = [];
    foreach ($changes as $change) {
        if (is_array($change) && isset($change['id'], $change['to'], $change['date'])) {
            $logged[(string) $change['id']][] = $change;
        }
    }
    $events = [];
    foreach ($rows as $id => $row) {
        $key = (string) $id;
        $base = is_array($seen[$key]['base'] ?? null) ? $seen[$key]['base'] : $now[$key];
        $recommended = (string) ($base['recommended'] ?? $row['recommended']);
        $scored = (string) ($base['scored'] ?? $row['scored']);
        $raw = [];
        if ($recommended !== '' && $scored !== '') {
            $raw[] = ['date' => $scored, 'outcome' => $recommended, 'source' => 'scoring', 'rank' => 0];
        }
        $raw[] = ['date' => (string) $base['date'], 'outcome' => statusName((string) $base['status']), 'source' => (string) $base['source'], 'rank' => 1];
        foreach ($logged[$key] ?? [] as $change) {
            $to = statusName((string) $change['to']);
            if (!isDecision($to) && $change['source'] === 'rescored') {
                $to = (string) ($change['recommended'] ?? '');
            }
            $raw[] = ['date' => (string) $change['date'], 'outcome' => $to, 'source' => (string) $change['source'], 'rank' => 2];
        }
        usort($raw, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']) ?: ($a['rank'] <=> $b['rank']));
        $list = [];
        foreach ($raw as $event) {
            if ($event['date'] === '') {
                continue;
            }
            $last = count($list) - 1;
            if ($last >= 0 && $list[$last]['date'] === $event['date'] && !isDecision($event['outcome'])) {
                continue;
            } elseif ($last >= 0 && $list[$last]['date'] === $event['date']) {
                $list[$last] = $event;
            } elseif ($last >= 0 && $list[$last]['outcome'] === $event['outcome'] && $event['source'] !== 'rescored') {
                continue;
            } else {
                $list[] = $event;
            }
        }
        foreach ($list as $event) {
            if (isDecision($event['outcome'])) {
                unset($event['rank']);
                $events[] = ['id' => $key] + $event;
            }
        }
    }
    usort($events, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']) ?: ((int) $a['id'] <=> (int) $b['id']));
    return $events;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use GET.']);
    exit;
}

$rows = exportRows($dataDir);
if (!$rows) {
    echo json_encode(['ok' => true, 'missing' => true, 'events' => [], 'note' => 'The Project Scores list has not been published to this site yet.']);
    exit;
}
$edits = readJson($dataDir . '/' . EDIT_FILE);
$now = currentStatuses($rows, $edits);
$kept = updateLog($dataDir, $now);
$changes = is_array($kept['log']['changes'] ?? null) ? $kept['log']['changes'] : [];
$seen = is_array($kept['log']['seen'] ?? null) ? $kept['log']['seen'] : [];

$exported = '';
foreach ($rows as $row) {
    if ($row['modified'] > $exported) {
        $exported = $row['modified'];
    }
}
$edited = '';
foreach ($edits as $edit) {
    $at = is_array($edit) ? (string) ($edit['at'] ?? '') : '';
    if ($at !== '' && $at > $edited) {
        $edited = substr($at, 0, 19);
    }
}

echo json_encode([
    'ok' => true,
    'missing' => false,
    'exported' => $exported,
    'edited' => $edited,
    'logStarted' => (string) ($kept['log']['started'] ?? ''),
    'logChanges' => count($changes),
    'logWritable' => $kept['writable'],
    'events' => decisionEvents($rows, $now, $seen, $changes)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
