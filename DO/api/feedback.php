<?php
declare(strict_types=1);

/**
 * Comments and bug reports. The message is mailed to the positional mailbox
 * named in data/positional-mailbox.txt (one address, no other text).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/mailer.php';

function mailbox(): string
{
    $env = trim((string) getenv('VASC_POSITIONAL_MAILBOX'));
    if ($env !== '' && filter_var($env, FILTER_VALIDATE_EMAIL)) {
        return $env;
    }
    $path = dirname(__DIR__) . '/data/positional-mailbox.txt';
    if (!is_readable($path)) {
        return '';
    }
    foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($path)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        return filter_var($line, FILTER_VALIDATE_EMAIL) ? $line : '';
    }
    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mailFail(400, 'Send the comment in a POST.');
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    mailFail(400, 'The comment could not be read.');
}
if (trim((string) ($body['company'] ?? '')) !== '') {
    echo json_encode(['ok' => true]);
    exit;
}
$relayed = false;
$forward = takeRelay($body, $relayed);

$message = trim((string) ($body['message'] ?? ''));
$message = str_replace("\r", '', $message);
if ($message === '' || strlen($message) > 4000) {
    mailFail(400, 'Write a short comment.');
}
$name = trim((string) ($body['name'] ?? ''));
$name = preg_replace('/\s+/', ' ', $name) ?? '';
if (strlen($name) > 80) {
    $name = substr($name, 0, 80);
}
$page = trim((string) ($body['page'] ?? ''));
if (strlen($page) > 160 || preg_match('/[\r\n]/', $page)) {
    $page = '';
}
$reply = trim((string) ($body['email'] ?? ''));
if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) {
    mailFail(400, 'That reply address is not an email.');
}

if ($forward) {
    mailForward('feedback.php', ['name' => $name, 'email' => $reply, 'message' => $message, 'page' => $page]);
}

$to = mailbox();
if ($to === '') {
    mailFail(500, 'The positional mailbox is not set on this server yet.');
}

$text = "Digital Office comment\n\n";
if ($name !== '') {
    $text .= 'Name: ' . $name . "\n";
}
if ($reply !== '') {
    $text .= 'Reply: ' . $reply . "\n";
}
if ($page !== '') {
    $text .= 'Page: ' . $page . "\n";
}
$text .= "\n" . $message . "\n";

$error = mailSend($to, 'Digital Office comment', $text, $reply);
if ($error !== null) {
    mailFail(502, 'The comment did not reach the positional mailbox. ' . $error);
}
echo json_encode(['ok' => true]);
