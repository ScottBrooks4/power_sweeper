#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$msapp = $argv[1] ?? 'samples/import_debug/CDLS (L) VCR App repair2.msapp';
$archive = new PowerSweeper\MsappArchive($msapp);
$archive->unpack();
$catalog = PowerSweeper\AppControlCatalog::build($archive->documents());

$screens = [];
foreach ($archive->documents() as $doc) {
    $s = $catalog->screenForDocument($doc);
    if ($s) {
        $screens[$s] = true;
    }
}

$all = '';
foreach ($archive->documents() as $doc) {
    $doc->transformFormulas(static function (string $f) use (&$all): string {
        $all .= $f . "\n";
        return $f;
    });
}

echo "Screens: " . implode(', ', array_keys($screens)) . "\n\n";

// Repeated screen qualification patterns
foreach (array_keys($screens) as $screen) {
    $q = "'" . str_replace("'", "''", $screen) . "'";
    if (str_contains($all, $q . '.' . $q)) {
        echo "DOUBLE: $screen\n";
    }
    if (preg_match('/\'{2,}' . preg_quote($screen, '/') . '/i', $all)) {
        echo "EXTRA-QUOTES: $screen\n";
    }
}

// Mangled admin pattern
if (preg_match_all("/'VCR '[^']+'\.Admin Screen'/i", $all, $m)) {
    echo "\nMangled admin patterns:\n";
    foreach (array_unique($m[0]) as $p) {
        echo "  $p\n";
    }
}

// Count triple-quote occurrences
$n = substr_count($all, "'''");
echo "\nTriple-single-quote count: $n\n";

$archive->cleanup();
