<?php
declare(strict_types=1);

/**
 * Abbreviations added on this site. The DND/CAF manual file is never written.
 */

header('Cache-Control: no-store');

$path = dirname(__DIR__) . '/data/abbreviations-added.xml';

function fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function loadDoc(string $path): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    if (is_readable($path)) {
        if ($doc->load($path) === false) {
            fail(500, 'The added abbreviation list could not be read.');
        }
        return $doc;
    }
    $root = $doc->createElement('abbreviations');
    $doc->appendChild($root);
    return $doc;
}

if (isset($_GET['action'])) {
    require __DIR__ . '/lib/abbreviations_admin.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: application/xml; charset=utf-8');
    if (!is_readable($path)) {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<abbreviations/>\n";
        exit;
    }
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(400, 'Send the abbreviation in a POST.');
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}
$abbr = trim((string) ($body['abbr'] ?? ''));
$expansion = trim((string) ($body['expansion'] ?? ''));
$abbr = preg_replace('/\s+/u', ' ', $abbr) ?? '';
$expansion = preg_replace('/\s+/u', ' ', $expansion) ?? '';
$lang = ($body['lang'] ?? 'en') === 'fr' ? 'fr' : 'en';
// French abbreviations sit in frAbbr with a <fr> expansion, the way the manual stores them.
$attr = $lang === 'fr' ? 'frAbbr' : 'abbr';
if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\\/+&()\'’-]{0,15}$/u', $abbr)) {
    fail(400, 'Use a short abbreviation, letters and numbers.');
}
if (mb_strlen($expansion) < 2 || mb_strlen($expansion) > 200 || preg_match('/[<>]/', $expansion)) {
    fail(400, 'Give the expansion in one line.');
}

function abbrKey(string $value): string
{
    return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $value));
}

$doc = loadDoc($path);
$root = $doc->documentElement;
if ($root === null || $root->tagName !== 'abbreviations') {
    fail(500, 'The added abbreviation list could not be read.');
}
$key = abbrKey($abbr);
foreach ($root->getElementsByTagName('entry') as $entry) {
    if (abbrKey((string) $entry->getAttribute($attr)) !== $key) {
        continue;
    }
    $text = null;
    foreach ($entry->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $lang) {
            $text = $child;
        }
    }
    if ($text === null) {
        $text = $doc->createElement($lang);
        $entry->appendChild($text);
    }
    while ($text->firstChild) {
        $text->removeChild($text->firstChild);
    }
    $text->appendChild($doc->createTextNode($expansion));
    $entry->setAttribute($attr, $abbr);
    $entry->setAttribute('source', 'Added on this site');
    if ($doc->save($path) === false) {
        fail(500, 'The abbreviation could not be saved.');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'updated' => true, 'abbr' => $abbr]);
    exit;
}

$entry = $doc->createElement('entry');
$entry->setAttribute($attr, $abbr);
$entry->setAttribute('source', 'Added on this site');
$text = $doc->createElement($lang);
$text->appendChild($doc->createTextNode($expansion));
$entry->appendChild($text);
$root->appendChild($entry);
if ($doc->save($path) === false) {
    fail(500, 'The abbreviation could not be saved.');
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'updated' => false, 'abbr' => $abbr]);
