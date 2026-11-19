<?php
declare(strict_types=1);

/**
 * Who may use Pivot's admin features.
 *
 * For now, every signed-in VASC member is an admin. A member is someone this
 * browser has already signed in to the VCDS-VCEMD Azure DevOps organization.
 * A list, when one is set, narrows that again: VASC_ADMINS (comma or newline
 * separated), else /srv/http/vasc-private/admins.txt (one address per line,
 * # starts a comment).
 */

function vascAdminEmails(): array
{
    $env = getenv('VASC_ADMINS');
    if (is_string($env) && trim($env) !== '') {
        $lines = preg_split('/[,\r\n]+/', $env) ?: [];
    } else {
        $path = '/srv/http/vasc-private/admins.txt';
        $lines = is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }
    $emails = [];
    foreach ($lines as $line) {
        $line = strtolower(trim((string) preg_replace('/#.*$/', '', (string) $line)));
        if ($line !== '') {
            $emails[$line] = true;
        }
    }
    return array_keys($emails);
}

function vascIsAdmin(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    $list = vascAdminEmails();
    if ($list === []) {
        return true;
    }
    return in_array($email, $list, true);
}
