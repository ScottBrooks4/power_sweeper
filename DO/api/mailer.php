<?php
declare(strict_types=1);

/**
 * Mail for the comment and intake forms. A server without a mail transfer
 * agent forwards the submission to MAIL_RELAY_BASE, which sends it. The relay
 * key and any test mailbox live outside the repository (see privateText).
 */

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const MAIL_FROM = 'intake@freement.cloud';
const MAIL_RELAY_BASE = 'https://freement.cloud/DO/api/';

/** An environment variable, else the first non-empty private file with that name. */
function privateText(string $name, string $env): string
{
    $value = trim((string) getenv($env));
    if ($value !== '') {
        return $value;
    }
    $paths = [
        dirname(__DIR__, 4) . '/vasc-private/' . $name,
        // Beside the site, outside this repository; vasc-private/.htaccess must deny all requests.
        dirname(__DIR__, 2) . '/vasc-private/' . $name,
        '/home/lulu/.config/vasc/' . $name,
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

function relayKey(): string
{
    return privateText('mail-relay.key', 'VASC_MAIL_RELAY_KEY');
}

function relayAccepted(string $given): bool
{
    $key = relayKey();
    return $key !== '' && $given !== '' && hash_equals($key, $given);
}

/** Where relayed intake goes for testing, if this server names one. Empty means the live mailbox. */
function testMailbox(): string
{
    $address = privateText('test-mailbox.txt', 'VASC_TEST_MAILBOX');
    return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
}

/**
 * Takes the relay fields off a submission. Returns whether it should be
 * forwarded: never twice, so two servers without mail cannot loop.
 *
 * @param array<string, mixed> $payload
 */
function takeRelay(array &$payload, bool &$relayed): bool
{
    $relayed = relayAccepted((string) ($payload['relay'] ?? ''));
    $forwarded = !empty($payload['forwarded']);
    unset($payload['relay'], $payload['forwarded']);
    return !$forwarded && !canMailHere();
}

function canMailHere(): bool
{
    if (getenv('VASC_MAIL_FORWARD') === '1') {
        return false;
    }
    $binary = strtok(trim((string) ini_get('sendmail_path')), ' ');
    return is_string($binary) && $binary !== '' && is_executable($binary);
}

function mailFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/** @param array<string, mixed> $payload */
function mailForward(string $script, array $payload): void
{
    $payload['relay'] = relayKey();
    $payload['forwarded'] = 1;
    $base = trim((string) getenv('VASC_MAIL_RELAY_BASE')) ?: MAIL_RELAY_BASE;
    $ch = curl_init(rtrim($base, '/') . '/' . $script);
    if ($ch === false) {
        mailFail(502, 'Mail could not be started.');
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
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if ($raw === false || $code < 200 || $code >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
        mailFail(502, $message !== '' ? $message : ($err !== '' ? $err : 'Mail was not accepted.'));
    }
    echo json_encode(['ok' => true]);
    exit;
}

function mailSend(string $to, string $subject, string $body, string $replyTo = ''): ?string
{
    if (function_exists('mb_encode_mimeheader')) {
        $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: VASC Pivot <' . MAIL_FROM . '>',
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $header = implode("\r\n", $headers);
    $ok = @mail($to, $subject, $body, $header, '-f ' . MAIL_FROM);
    if (!$ok) {
        $ok = @mail($to, $subject, $body, $header);
    }
    if ($ok) {
        return null;
    }
    $err = error_get_last();
    return isset($err['message']) ? (string) $err['message'] : 'Mail was not accepted.';
}
