<?php
declare(strict_types=1);

/**
 * Admin edits to the site-added abbreviation list, reached as api/abbreviations.php?action=…
 * The DND/CAF manual file is still never written; a correction to a manual entry is saved
 * here and outranks the manual sense.
 *
 * Expects $path from api/abbreviations.php.
 */

require_once __DIR__ . '/devops_identity.php';

header('Content-Type: application/json; charset=utf-8');

function abbrText(DOMElement $entry, string $tag): string
{
    foreach ($entry->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $tag) {
            return trim($child->textContent);
        }
    }
    return '';
}

function abbrSetText(DOMDocument $doc, DOMElement $entry, string $tag, string $value): void
{
    foreach (iterator_to_array($entry->childNodes) as $child) {
        if ($child instanceof DOMElement && $child->tagName === $tag) {
            $entry->removeChild($child);
        }
    }
    if ($value === '') {
        return;
    }
    $node = $doc->createElement($tag);
    $node->appendChild($doc->createTextNode($value));
    $before = null;
    foreach ($entry->childNodes as $child) {
        if ($child instanceof DOMElement && ($tag === 'en' || ($tag === 'fr' && $child->tagName === 'place'))) {
            $before = $child;
            break;
        }
    }
    $entry->insertBefore($node, $before);
}

function abbrEntries(DOMDocument $doc): array
{
    $root = $doc->documentElement;
    if ($root === null || $root->tagName !== 'abbreviations') {
        vascAdminFail(500, 'The added abbreviation list could not be read.');
    }
    $list = [];
    foreach ($root->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === 'entry') {
            $list[] = $child;
        }
    }
    return $list;
}

function abbrRow(DOMElement $entry, int $index): array
{
    return [
        'index' => $index,
        'abbr' => (string) $entry->getAttribute('abbr'),
        'frAbbr' => (string) $entry->getAttribute('frAbbr'),
        'en' => abbrText($entry, 'en'),
        'fr' => abbrText($entry, 'fr'),
        'source' => (string) $entry->getAttribute('source'),
    ];
}

function abbrKey(string $abbr): string
{
    return strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $abbr));
}

function abbrClean(mixed $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
}

/** The entry at $index, provided it still carries the abbreviations the editor saw. */
function abbrTarget(array $entries, array $body): DOMElement
{
    $index = (int) ($body['index'] ?? -1);
    $entry = $entries[$index] ?? null;
    if (!$entry instanceof DOMElement
        || (string) $entry->getAttribute('abbr') !== (string) ($body['was']['abbr'] ?? '')
        || (string) $entry->getAttribute('frAbbr') !== (string) ($body['was']['frAbbr'] ?? '')) {
        vascAdminFail(409, 'The list changed since it was loaded. Reload and try again.');
    }
    return $entry;
}

/** Loads without the file's whitespace so saved entries come out indented like the rest. */
function abbrLoad(string $path): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    if (!is_readable($path)) {
        $doc->appendChild($doc->createElement('abbreviations'));
        return $doc;
    }
    if ($doc->load($path) === false) {
        vascAdminFail(500, 'The added abbreviation list could not be read.');
    }
    return $doc;
}

function abbrSave(DOMDocument $doc, string $path): void
{
    $tmp = $path . '.tmp';
    if ($doc->save($tmp) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        vascAdminFail(500, 'The abbreviation list could not be saved.');
    }
}

$adminAction = (string) $_GET['action'];

if ($adminAction === 'list') {
    vascRequireAdmin();
    session_write_close();
    $doc = abbrLoad($path);
    $rows = [];
    foreach (abbrEntries($doc) as $index => $entry) {
        $rows[] = abbrRow($entry, $index);
    }
    echo json_encode(['ok' => true, 'entries' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($adminAction !== 'save' && $adminAction !== 'delete') {
    vascAdminFail(400, 'Unknown abbreviation action.');
}

$body = vascRequireJsonPost();
vascRequireAdmin();
session_write_close();

$lock = fopen($path . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    vascAdminFail(503, 'The abbreviation list is busy. Try again.');
}
$doc = abbrLoad($path);
$entries = abbrEntries($doc);

if ($adminAction === 'delete') {
    $entry = abbrTarget($entries, $body);
    $entry->parentNode->removeChild($entry);
    abbrSave($doc, $path);
    echo json_encode(['ok' => true, 'deleted' => true]);
    exit;
}

$abbr = abbrClean($body['abbr'] ?? '');
$frAbbr = abbrClean($body['frAbbr'] ?? '');
$en = abbrClean($body['en'] ?? '');
$fr = abbrClean($body['fr'] ?? '');
$short = '/^[\p{L}\p{N}(-][\p{L}\p{N} .\/+&\'’(),;–-]{0,59}$/u';
if ($abbr === '' && $frAbbr === '') {
    vascAdminFail(400, 'Give the English or French abbreviation.');
}
foreach (['English' => $abbr, 'French' => $frAbbr] as $label => $value) {
    if ($value !== '' && !preg_match($short, $value)) {
        vascAdminFail(400, 'The ' . $label . ' abbreviation should be up to 60 letters, numbers, spaces and . / + & ( ) , ; -');
    }
}
if ($en === '' && $fr === '') {
    vascAdminFail(400, 'Give the English or French expansion.');
}
foreach (['English' => $en, 'French' => $fr] as $label => $value) {
    if ($value !== '' && (mb_strlen($value) < 2 || mb_strlen($value) > 200 || preg_match('/[<>]/', $value))) {
        vascAdminFail(400, 'Give the ' . $label . ' expansion in one line, up to 200 characters.');
    }
}

$editing = array_key_exists('index', $body) && (int) $body['index'] >= 0;
$entry = $editing ? abbrTarget($entries, $body) : null;
foreach ($entries as $other) {
    if ($other === $entry) {
        continue;
    }
    if (($abbr !== '' && abbrKey($abbr) === abbrKey((string) $other->getAttribute('abbr')))
        || ($frAbbr !== '' && abbrKey($frAbbr) === abbrKey((string) $other->getAttribute('frAbbr')))) {
        vascAdminFail(409, 'That abbreviation is already on the added list. Edit that row instead.');
    }
}
if ($entry === null) {
    $entry = $doc->createElement('entry');
    $doc->documentElement->appendChild($entry);
    $entry->setAttribute('source', 'Added on this site');
}
$entry->setAttribute('abbr', $abbr);
if ($frAbbr !== '') {
    $entry->setAttribute('frAbbr', $frAbbr);
} else {
    $entry->removeAttribute('frAbbr');
}
abbrSetText($doc, $entry, 'en', $en);
abbrSetText($doc, $entry, 'fr', $fr);
abbrSave($doc, $path);
$entries = abbrEntries($doc);
echo json_encode(['ok' => true, 'entry' => abbrRow($entry, (int) array_search($entry, $entries, true))], JSON_UNESCAPED_UNICODE);
