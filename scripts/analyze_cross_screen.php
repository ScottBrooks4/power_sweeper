<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\AppControlCatalog;
use PowerSweeper\FormulaReferenceExtractor;
use PowerSweeper\MsappArchive;

$msapp = $argv[1] ?? 'samples/import_debug/CDLS_L_VCR_App_16.repaired.msapp';
$archive = new MsappArchive($msapp);
$archive->unpack();
$catalog = AppControlCatalog::build($archive->documents());

function isBareRef(string $formula, string $id, string $screen, AppControlCatalog $catalog): bool
{
    if (preg_match('/(?<![\w.])' . preg_quote($id, '/') . '(?![\w])/', $formula) !== 1) {
        return false;
    }
    foreach ($catalog->screensWith($id) as $other) {
        if ($other === $screen) {
            continue;
        }
        $q = $catalog->quoteScreen($other);
        $patterns = [
            $q . '.' . $id,
            $q . ".'" . str_replace("'", "''", $id) . "'",
        ];
        foreach ($patterns as $pat) {
            if (str_contains($formula, $pat)) {
                return false;
            }
        }
    }
    return true;
}

$bare = [];
foreach ($archive->documents() as $doc) {
    $screen = $catalog->screenForDocument($doc);
    if ($screen === null || $doc->format !== 'json') {
        continue;
    }
    foreach ($doc->controls() as $control) {
        foreach (['OnSelect', 'OnChange', 'OnVisible', 'OnCheck', 'OnUncheck'] as $prop) {
            $value = $control->getProperty($prop);
            if ($value === null || trim($value) === '') {
                continue;
            }
            foreach (FormulaReferenceExtractor::identifiers($value) as $id) {
                if ($catalog->hasOnScreen($screen, $id)) {
                    continue;
                }
                if ($catalog->isReserved($id)) {
                    continue;
                }
                if (preg_match('/^(var|col|gbl)/', $id)) {
                    continue;
                }
                $others = $catalog->screensWith($id);
                if ($others === [] || in_array($screen, $others, true)) {
                    continue;
                }
                if (!isBareRef($value, $id, $screen, $catalog)) {
                    continue;
                }
                $key = $screen . '|' . $control->name . '|' . $prop . '|' . $id;
                $bare[$key] = ($bare[$key] ?? 0) + 1;
            }
        }
    }
}

ksort($bare);
echo "Bare cross-screen refs: " . count($bare) . "\n";
foreach ($bare as $key => $n) {
    echo "  $key\n";
}

$archive->cleanup();
