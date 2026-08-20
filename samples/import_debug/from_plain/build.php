<?php

declare(strict_types=1);

/**
 * Rebuild from_plain bisect packs from the plain Studio export.
 *
 * Usage: php samples/import_debug/from_plain/build.php
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

use PowerSweeper\MsappArchive;
use PowerSweeper\Pipeline;

$outDir = __DIR__;
$plain = dirname(__DIR__) . '/CDLS (L) VCR App (4).msapp';
if (!is_file($plain)) {
    fwrite(STDERR, "Missing plain source: {$plain}\n");
    exit(1);
}

/**
 * @param callable(MsappArchive):void $mutator
 */
function withUnpacked(string $msapp, callable $mutator, string $outMsapp): void
{
    $archive = new MsappArchive($msapp);
    $archive->unpack();
    try {
        $mutator($archive);
        $archive->pack($outMsapp);
    } finally {
        $archive->cleanup();
    }
}

function docAbs(MsappArchive $archive, string $relativePath): ?string
{
    $rel = str_replace('\\', '/', $relativePath);
    $candidates = [
        $archive->extractDir() . '/' . $rel,
        $archive->extractDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel),
    ];
    foreach ($candidates as $abs) {
        if (is_file($abs)) {
            return $abs;
        }
    }
    return null;
}

echo 'Building from_plain bisect from ' . basename($plain) . "\n";

// A — rezip only
withUnpacked($plain, static function (MsappArchive $archive): void {
}, $outDir . '/A_rezip_only.msapp');
echo "A_rezip_only.msapp\n";

// B — re-encode one Controls/*.json via ControlDocument (object-preserving StudioJson)
withUnpacked($plain, static function (MsappArchive $archive): void {
    $pick = null;
    foreach ($archive->documents() as $doc) {
        if ($doc->format !== 'json') {
            continue;
        }
        $rel = str_replace('\\', '/', $doc->relativePath);
        if (!str_starts_with($rel, 'Controls/')) {
            continue;
        }
        $abs = docAbs($archive, $doc->relativePath);
        if ($abs === null) {
            continue;
        }
        $size = filesize($abs) ?: 0;
        if ($pick === null || $size > $pick['size']) {
            $pick = ['doc' => $doc, 'size' => $size];
        }
    }
    if ($pick === null) {
        throw new RuntimeException('No Controls JSON found for B');
    }
    $pick['doc']->markDirty();
    echo '  B dirty ' . $pick['doc']->relativePath . ' (' . $pick['size'] . " bytes original)\n";
}, $outDir . '/B_json_roundtrip_one_control.msapp');
echo "B_json_roundtrip_one_control.msapp\n";

// C — re-encode one small YAML screen
withUnpacked($plain, static function (MsappArchive $archive): void {
    $pick = null;
    foreach ($archive->documents() as $doc) {
        if ($doc->format !== 'yaml') {
            continue;
        }
        $rel = str_replace('\\', '/', $doc->relativePath);
        if (!str_starts_with($rel, 'Src/') || str_ends_with(strtolower($rel), 'app.pa.yaml')) {
            continue;
        }
        $abs = docAbs($archive, $doc->relativePath);
        if ($abs === null) {
            continue;
        }
        $size = filesize($abs) ?: 0;
        if ($pick === null || $size < $pick['size']) {
            $pick = ['doc' => $doc, 'size' => $size];
        }
    }
    if ($pick === null) {
        throw new RuntimeException('No Src YAML found for C');
    }
    $pick['doc']->markDirty();
    echo '  C dirty ' . $pick['doc']->relativePath . "\n";
}, $outDir . '/C_yaml_roundtrip_one_screen.msapp');
echo "C_yaml_roundtrip_one_screen.msapp\n";

// D — re-encode all Controls/Components JSON
withUnpacked($plain, static function (MsappArchive $archive): void {
    $n = 0;
    foreach ($archive->documents() as $doc) {
        if ($doc->format !== 'json') {
            continue;
        }
        $rel = str_replace('\\', '/', $doc->relativePath);
        if (!str_starts_with($rel, 'Controls/') && !str_starts_with($rel, 'Components/')) {
            continue;
        }
        $doc->markDirty();
        $n++;
    }
    echo "  D dirty {$n} JSON control docs\n";
}, $outDir . '/D_json_roundtrip_all_controls.msapp');
echo "D_json_roundtrip_all_controls.msapp\n";

// E — re-encode all Src YAML
withUnpacked($plain, static function (MsappArchive $archive): void {
    $n = 0;
    foreach ($archive->documents() as $doc) {
        if ($doc->format !== 'yaml') {
            continue;
        }
        $rel = str_replace('\\', '/', $doc->relativePath);
        if (!str_starts_with($rel, 'Src/')) {
            continue;
        }
        $doc->markDirty();
        $n++;
    }
    echo "  E dirty {$n} YAML docs\n";
}, $outDir . '/E_yaml_roundtrip_all_src.msapp');
echo "E_yaml_roundtrip_all_src.msapp\n";

// F — raw text splice (no YAML/JSON dumpers)
withUnpacked($plain, static function (MsappArchive $archive): void {
    $app = null;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archive->extractDir()));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $p = str_replace('\\', '/', $f->getPathname());
        if (str_ends_with(strtolower($p), '/src/app.pa.yaml')) {
            $app = $f->getPathname();
            break;
        }
    }
    if ($app === null || !is_file($app)) {
        throw new RuntimeException('App.pa.yaml not found for F');
    }
    $raw = file_get_contents($app);
    if ($raw === false) {
        throw new RuntimeException('Unable to read App.pa.yaml');
    }
    if (!str_contains($raw, '/* ps-bisect-raw */')) {
        if (preg_match('/(OnStart:\s*=)/', $raw)) {
            $raw = preg_replace('/(OnStart:\s*=)/', '$1/* ps-bisect-raw */', $raw, 1) ?? $raw;
        } else {
            $raw .= "\n# ps-bisect-raw\n";
        }
        file_put_contents($app, $raw);
    }
}, $outDir . '/F_raw_splice_onstart_comment.msapp');
echo "F_raw_splice_onstart_comment.msapp\n";

$pipeline = new Pipeline();

// G — unwhack only
$pipeline->run($plain, [['id' => 'unwhack_locale_formulas']], $outDir . '/G_unwhack_only.msapp');
echo "G_unwhack_only.msapp\n";

// H — dark only
$dark = \PowerSweeper\HopChains::darkMode();
$pipeline->run($plain, $dark, $outDir . '/H_dark_only.msapp');
echo "H_dark_only.msapp\n";

// I — studio repair
$repair = \PowerSweeper\HopChains::studioRepair();
$pipeline->run($plain, $repair, $outDir . '/I_repair_studio_errors.msapp');
echo "I_repair_studio_errors.msapp\n";

// J — repair then dark
$pipeline->run($plain, array_merge($repair, $dark), $outDir . '/J_repair_then_dark.msapp');
echo "J_repair_then_dark.msapp\n";

echo "Done.\n";
