<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require __DIR__ . '/mailer.php';

const INTAKE_LIVE_TO = 'VCDS-ASC-VCEMD-CSA@ecn.forces.gc.ca';
const INTAKE_LIVE_NAME = 'VCDS ASC-VCEMD CSA';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mailFail(405, 'Use the intake form.');
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 100000) {
    mailFail(413, 'That submission is too long.');
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    mailFail(400, 'The form could not be read.');
}

$relayed = false;
$forward = takeRelay($payload, $relayed);
$fields = intakeFields($payload);
if ($forward) {
    mailForward('intake.php', $payload);
}

$test = $relayed ? testMailbox() : '';
$to = $test !== '' ? $test : formatAddress(INTAKE_LIVE_NAME, INTAKE_LIVE_TO);
$error = mailSend($to, 'Intake: ' . $fields['title'], intakeBody($fields), $fields['contact']);
if ($error !== null) {
    mailFail(502, $error);
}
echo json_encode(['ok' => true]);

function formatAddress(string $name, string $email): string
{
    return '"' . str_replace(['"', "\r", "\n"], '', $name) . '" <' . $email . '>';
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
        mailFail(400, 'Title and a real contact email are required.');
    }
    foreach (['overview', 'problem', 'outcome'] as $key) {
        if ($text($key, 8000) === '') {
            mailFail(400, 'Overview, the business problem, and the desired outcome are required.');
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
