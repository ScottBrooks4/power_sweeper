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
$activeMangled = 0;
$commentMangled = 0;

foreach ($archive->documents() as $doc) {
    $doc->transformFormulas(static function (string $f, string $path) use (&$all, &$activeMangled, &$commentMangled): string {
        $all .= $f . "\n";
        $parts = PowerSweeper\PowerFxFormulaSegments::splitForStructure($f);
        foreach ($parts as [$type, $text]) {
            $mangled = str_contains($text, "'''")
                || preg_match("/'[^']+'\.Admin Screen/i", str_replace("''", "'", $text));
            if (!$mangled) {
                continue;
            }
            if ($type === 'code') {
                $activeMangled++;
                echo "ACTIVE-MANGLED: $path\n";
            } else {
                $commentMangled++;
            }
        }

        return $f;
    });
}

echo "Screens: " . implode(', ', array_keys($screens)) . "\n\n";

foreach (array_keys($screens) as $screen) {
    $q = "'" . str_replace("'", "''", $screen) . "'";
    if (str_contains($all, $q . '.' . $q)) {
        echo "DOUBLE: $screen\n";
    }
}

echo "\nActive mangled code segments: $activeMangled\n";
echo "Mangled in comments/strings only: $commentMangled\n";
echo "Triple-single-quote count (all formulas): " . substr_count($all, "'''") . "\n";

$archive->cleanup();
