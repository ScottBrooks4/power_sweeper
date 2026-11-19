<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

const INTAKE_RELAY = 'b57fdb411be3825e7bfe3aadd5cb44820d95791eb6e5a2a7';
const INTAKE_LOCAL_TO = 'morath99@gmail.com';
const INTAKE_LIVE_TO = 'VCDS-ASC-VCEMD-CSA@ecn.forces.gc.ca';
const INTAKE_LIVE_NAME = 'VCDS ASC-VCEMD CSA';
const INTAKE_FROM = 'intake@freement.cloud';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    intakeFail(405, 'Use the intake form.');
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 100000) {
    intakeFail(413, 'That submission is too long.');
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    intakeFail(400, 'The form could not be read.');
}

$relay = (string) ($payload['relay'] ?? '');
unset($payload['relay']);
$local = intakeIsLocal();
if ($local && $relay === '') {
    intakeForward($payload);
}

$toGmail = $local || hash_equals(INTAKE_RELAY, $relay);
$to = $toGmail ? INTAKE_LOCAL_TO : formatAddress(INTAKE_LIVE_NAME, INTAKE_LIVE_TO);
$fields = intakeFields($payload);
$subject = 'Intake: ' . $fields['title'];
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
}
$error = intakeSend($to, $subject, intakeBody($fields), $fields['contact']);
if ($error !== null) {
    intakeFail(502, $error);
}
echo json_encode(['ok' => true]);

function intakeIsLocal(): bool
{
    $file = realpath(__FILE__) ?: '';

    return str_starts_with($file, '/srv/http/DO/');
}

function formatAddress(string $name, string $email): string
{
    return '"' . str_replace(['"', "\r", "\n"], '', $name) . '" <' . $email . '>';
}

/** @param array<string, mixed> $payload */
function intakeForward(array $payload): void
{
    $payload['relay'] = INTAKE_RELAY;
    $ch = curl_init('https://freement.cloud/DO/api/intake.php');
    if ($ch === false) {
        intakeFail(502, 'Mail could not be started.');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
        intakeFail(502, $message !== '' ? $message : ($err !== '' ? $err : 'Mail was not accepted.'));
    }
    echo is_string($raw) && $raw !== '' ? $raw : '{"ok":true}';
    exit;
}

/** @param array<string, mixed> $payload */
function intakeFields(array $payload): array
{
    $text = static function (string $key, int $max) use ($payload): string {
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) ($payload[$key] ?? '')));
        if (strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }

        return $value;
    };
    $line = static function (string $key, int $max) use ($text): string {
        return trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $text($key, $max))) ?? '');
    };
    $title = $line('title', 200);
    $contact = $line('contact', 200);
    if ($title === '' || $contact === '' || !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        intakeFail(400, 'Title and a real contact email are required.');
    }
    foreach (['overview', 'problem', 'outcome'] as $key) {
        if ($text($key, 8000) === '') {
            intakeFail(400, 'Overview, the business problem, and the desired outcome are required.');
        }
    }

    return [
        'title' => $title,
        'level' => $line('level', 20),
        'l2' => $line('l2', 80),
        'l3' => $line('l3', 80),
        'contact' => $contact,
        'category' => $line('category', 80),
        'classification' => $line('data', 40),
        'priority' => $line('priority', 40),
        // The questions SOP-001 6.3.3 asks at the intake meeting.
        'audience' => $line('audience', 300),
        'timeline' => $line('timeline', 300),
        'opi' => $line('opi', 300),
        'continuity' => $text('continuity', 2000),
        'dataOwner' => $text('dataOwner', 2000),
        'uatTesters' => $line('uatTesters', 500),
        'roadblocks' => $text('roadblocks', 2000),
        'overview' => $text('overview', 8000),
        'problem' => $text('problem', 8000),
        'outcome' => $text('outcome', 8000),
    ];
}

/** @param array<string, string> $fields */
function intakeBody(array $fields): string
{
    return implode("\n", [
        'Title: ' . $fields['title'],
        'Level: ' . ($fields['level'] !== '' ? $fields['level'] : '—'),
        'L2 organization: ' . ($fields['l2'] !== '' ? $fields['l2'] : '—'),
        'L3 organization: ' . ($fields['l3'] !== '' ? $fields['l3'] : '—'),
        'Contact: ' . $fields['contact'],
        'Category: ' . ($fields['category'] !== '' ? $fields['category'] : '—'),
        'Classification: ' . ($fields['classification'] !== '' ? $fields['classification'] : '—'),
        'Priority: ' . ($fields['priority'] !== '' ? $fields['priority'] : '—'),
        '',
        'Intake questions:',
        'Audience: ' . ($fields['audience'] !== '' ? $fields['audience'] : '—'),
        'Timeline: ' . ($fields['timeline'] !== '' ? $fields['timeline'] : '—'),
        'OPI: ' . ($fields['opi'] !== '' ? $fields['opi'] : '—'),
        'Continuity if the OPI changes roles:',
        $fields['continuity'] !== '' ? $fields['continuity'] : '—',
        'Who handles the data once it is in production:',
        $fields['dataOwner'] !== '' ? $fields['dataOwner'] : '—',
        'UAT testers: ' . ($fields['uatTesters'] !== '' ? $fields['uatTesters'] : '—'),
        'Current roadblocks:',
        $fields['roadblocks'] !== '' ? $fields['roadblocks'] : '—',
        '',
        'Overview:',
        $fields['overview'],
        '',
        'Business problem:',
        $fields['problem'],
        '',
        'Desired outcome:',
        $fields['outcome'],
        '',
    ]);
}

function intakeSend(string $to, string $subject, string $body, string $replyTo): ?string
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: VASC Pivot <' . INTAKE_FROM . '>',
        'Reply-To: ' . $replyTo,
    ];
    $header = implode("\r\n", $headers);
    $ok = @mail($to, $subject, $body, $header, '-f ' . INTAKE_FROM);
    if (!$ok) {
        $ok = @mail($to, $subject, $body, $header);
    }
    if ($ok) {
        return null;
    }
    $err = error_get_last();

    return isset($err['message']) ? (string) $err['message'] : 'Mail was not accepted.';
}

function intakeFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
