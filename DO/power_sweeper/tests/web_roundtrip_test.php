<?php

declare(strict_types=1);

/**
 * Full conversion-file roundtrip tests: properties, package, CSS classes, storage.
 *
 * Run via tests/run_tests.php (required at end) or:
 *   php tests/web_roundtrip_test.php
 */

use PowerSweeper\ControlDocument;
use PowerSweeper\MsappArchive;
use PowerSweeper\OutputNaming;
use PowerSweeper\Report;
use PowerSweeper\SweepRegistry;
use PowerSweeper\Sweeps\ImportWebPackageSweep;
use PowerSweeper\WebApp\PowerAppMaterializer;
use PowerSweeper\WebApp\SweeperStyles;
use PowerSweeper\WebApp\WebAppIrApplier;
use PowerSweeper\WebApp\WebAppIrBuilder;
use PowerSweeper\WebApp\WebAppPackageBuilder;
use PowerSweeper\WebApp\WebAppPackageReader;
use PowerSweeper\WebApp\WebAppPixelRenderer;

require_once dirname(__DIR__) . '/bootstrap.php';

if (!function_exists('assert_true')) {
    function assert_true(bool $cond, string $msg): void
    {
        if (!$cond) {
            fwrite(STDERR, "FAIL: {$msg}\n");
            exit(1);
        }
        echo "ok - {$msg}\n";
    }
}

$screen1 = ControlDocument::fromFile(__DIR__ . '/fixtures/web_roundtrip.pa.yaml', 'Src/Screen1.pa.yaml');
$screen2 = ControlDocument::fromFile(__DIR__ . '/fixtures/web_roundtrip_screen2.pa.yaml', 'Src/Screen2.pa.yaml');
assert_true($screen1 !== null && $screen2 !== null, 'web roundtrip fixtures load');

$extract = sys_get_temp_dir() . '/ps_web_rt_' . bin2hex(random_bytes(3));
mkdir($extract, 0775, true);
file_put_contents($extract . '/Properties.json', json_encode([
    'DocumentAppType' => 'Phone',
    'DocumentLayoutWidth' => 640,
    'DocumentLayoutHeight' => 1136,
    'DocumentLayoutScaleToFit' => true,
    'Name' => 'Roundtrip Demo',
], JSON_PRETTY_PRINT));

$builder = new WebAppIrBuilder();
assert_true(
    OutputNaming::msappFilename('App16.msapp', [
        ['id' => 'fix_control_names_and_refs'],
        ['id' => 'fix_formula_errors'],
        ['id' => 'enable_dark_theme'],
    ]) === 'App16.names+formulas+dark.msapp',
    'download name uses short sweep tags',
);
assert_true(
    OutputNaming::msappFilename('Thing.zip', [['id' => 'export_to_web_ir']]) === 'Thing.toweb.msapp',
    'download name for web export is toweb',
);
assert_true(WebAppIrBuilder::normalizeRuntime('C Sharp') === 'csharp', 'C Sharp normalizes to csharp');
assert_true(WebAppIrBuilder::normalizeRuntime('py') === 'python', 'py normalizes to python');
assert_true(WebAppIrBuilder::normalizeStorage('lom') === 'xml', 'lom normalizes to xml');
assert_true(WebAppIrBuilder::normalizeStorage('SQL') === 'sql', 'SQL normalizes to sql');
assert_true(WebAppIrBuilder::normalizeLayout('pixel') === 'canvas', 'pixel normalizes to canvas');
assert_true(WebAppIrBuilder::normalizeLayout('responsive web') === 'responsive', 'responsive web normalizes');
assert_true(WebAppIrBuilder::normalizeLayout('MVC') === 'mvc', 'MVC normalizes');
assert_true(WebAppIrBuilder::normalizeLayout('modern') === 'responsive', 'modern normalizes to responsive');
assert_true(WebAppIrBuilder::normalizeLayout('WET') === 'wet', 'WET normalizes');
assert_true(WebAppIrBuilder::normalizeLayout('Web Experience Toolkit') === 'wet', 'Web Experience Toolkit normalizes to wet');

$ir = $builder->build([$screen1, $screen2], $extract, [
    'runtime' => 'php',
    'storage' => 'sql',
    'layout' => 'canvas',
]);

assert_true(($ir['version'] ?? 0) === 2, 'conversion file is version 2');
assert_true(($ir['export']['runtime'] ?? '') === 'php', 'export runtime php');
assert_true(($ir['export']['storage'] ?? '') === 'sql', 'export storage sql');
assert_true(($ir['export']['layout'] ?? '') === 'canvas', 'export layout canvas');
assert_true(($ir['stats']['properties'] ?? 0) > 10, 'conversion file stores many properties');

$goNext = null;
foreach ($ir['screens'] as $s) {
    if (($s['name'] ?? '') !== 'Screen1') {
        continue;
    }
    foreach ($s['children'] ?? [] as $c) {
        if (($c['name'] ?? '') === 'GoNext') {
            $goNext = $c;
        }
    }
}
assert_true($goNext !== null, 'GoNext control in conversion file');
assert_true(
    isset($goNext['properties']['OnSelect']) && str_contains((string) $goNext['properties']['OnSelect'], 'Navigate'),
    'OnSelect formula preserved in conversion file',
);
assert_true(isset($goNext['layout']['x']) && (int) $goNext['layout']['x'] === 20, 'pixel layout X captured');

// Mutate a property via conversion file and apply back
$ir['screens'][0]['children'][0]['properties']['Text'] = '="Roundtrip Demo Updated"';
$applyReport = new Report();
$applyResult = (new WebAppIrApplier())->apply([$screen1, $screen2], $ir, $applyReport, $extract);
assert_true($applyResult['changes'] > 0, 'applier reports property restore changes');
$title = null;
foreach ($screen1->controls() as $c) {
    if ($c->name === 'TitleLabel') {
        $title = $c->getProperty('Text');
        break;
    }
}
assert_true(is_string($title) && str_contains($title, 'Updated'), 'property formula restored onto canvas');

// Rebuild and compare property counts (second export)
$ir2 = $builder->build([$screen1, $screen2], $extract, [
    'runtime' => 'php',
    'storage' => 'sql',
    'layout' => 'canvas',
]);
assert_true(
    (int) ($ir2['stats']['controls'] ?? 0) === (int) ($ir['stats']['controls'] ?? -1),
    'second export control count matches',
);
assert_true(
    (int) ($ir2['stats']['screens'] ?? 0) === (int) ($ir['stats']['screens'] ?? -1),
    'second export screen count matches',
);

// CSS: intermediate styles → classes, no inline left
$renderer = new WebAppPixelRenderer();
$intermediate = $renderer->renderIntermediate($ir2);
assert_true(str_contains($intermediate, 'style='), 'pixel renderer emits intermediate geometry styles');
$sweeper = new SweeperStyles();
$converted = $sweeper->fallbackConvert($intermediate);
assert_true(($converted['class_count'] ?? 0) > 0, 'styles folded into classes');
assert_true(
    (preg_match_all('/\sstyle="/i', $converted['html']) ?: 0) === 0,
    'no inline styles after class conversion',
);
if ($sweeper->available()) {
    $viaSweeper = $sweeper->convert($intermediate);
    assert_true(is_string($viaSweeper['html'] ?? null) && $viaSweeper['html'] !== '', 'sweeper convert returns HTML');
}

/**
 * @param array<string, mixed> $ir
 * @return array<string, array<string, mixed>>
 */
function ps_web_fingerprint(array $ir): array
{
    $out = [];
    $walk = static function (array $node, string $prefix) use (&$walk, &$out): void {
        $name = (string) ($node['name'] ?? '');
        $key = $prefix . $name;
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $picked = [];
        foreach (['Text', 'OnSelect', 'X', 'Y', 'Width', 'Height', 'Items', 'Default'] as $prop) {
            if (array_key_exists($prop, $props)) {
                $picked[$prop] = $props[$prop];
            }
        }
        $out[$key] = [
            'type' => (string) ($node['type'] ?? ''),
            'props' => $picked,
        ];
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $walk($child, $key . '/');
            }
        }
    };
    foreach ($ir['screens'] ?? [] as $screen) {
        if (is_array($screen)) {
            $walk($screen, '');
        }
    }
    ksort($out);

    return $out;
}

$combos = [];
foreach (['php', 'python', 'csharp'] as $runtime) {
    foreach (['xml', 'sql'] as $storage) {
        foreach (['mvc', 'canvas', 'responsive', 'wet'] as $layout) {
            $combos[] = [$runtime, $storage, $layout];
        }
    }
}
$pkgBuilder = new WebAppPackageBuilder();
$reader = new WebAppPackageReader();
$materializer = new PowerAppMaterializer();
foreach ($combos as [$runtime, $storage, $layout]) {
    $label = "{$runtime}/{$storage}/{$layout}";
    $irCombo = $builder->build([$screen1, $screen2], $extract, [
        'runtime' => $runtime,
        'storage' => $storage,
        'layout' => $layout,
    ]);
    $zip = $extract . "/pkg_{$runtime}_{$storage}_{$layout}.zip";
    $result = $pkgBuilder->build($irCombo, $zip, $extract);
    assert_true($result['ok'] === true, "package builds for {$label}: " . ($result['message'] ?? ''));
    assert_true(is_file($zip) && filesize($zip) > 100, "zip exists for {$label}");
    assert_true(($result['inline_styles'] ?? 1) === 0, "no inline styles in {$label} package");

    $za = new ZipArchive();
    assert_true($za->open($zip) === true, "open zip {$label}");
    assert_true($za->locateName('import/power_sweeper_ir.json') !== false, "import conversion archive in {$label} zip");
    assert_true($za->locateName('ir/power_sweeper_ir.json') === false, "no runtime ir/ tree in {$label} zip");
    assert_true($za->locateName('public/index.html') !== false, "index.html in {$label} zip");
    assert_true($za->locateName('public/assets/runtime.js') !== false, "runtime.js in {$label} zip");
    assert_true($za->locateName('public/assets/generated.css') !== false, "generated.css in {$label} zip");
    assert_true($za->locateName('public/assets/app.css') !== false, "app.css in {$label} zip");
    assert_true($za->locateName('public/assets/app-logic.js') !== false, "app-logic.js in {$label} zip");
    assert_true($za->locateName('public/assets/app-logic.json') !== false, "app-logic.json in {$label} zip");
    assert_true($za->locateName('public/assets/handlers.js') !== false, "handlers.js in {$label} zip");
    assert_true($za->locateName('public/assets/collections.json') !== false, "assets/collections.json in {$label} zip");
    $handlerModules = 0;
    $nativeHandler = false;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $stat = $za->statIndex($i);
        $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
        if (str_starts_with($name, 'public/assets/handlers/') && str_ends_with($name, '.js')) {
            $handlerModules++;
            $body = $za->getFromName($name);
            if (is_string($body) && (str_contains($body, 'function ()') || str_contains($body, 'PS.navigate') || str_contains($body, 'PS_HANDLERS'))) {
                $nativeHandler = true;
            }
        }
    }
    assert_true($handlerModules >= 1, "per-screen handler modules in {$label} zip");
    assert_true($nativeHandler, "native handler module shape in {$label} zip");
    if ($runtime === 'php') {
        assert_true($za->locateName('server/generated/AppData.php') !== false, "Generated AppData in {$label}");
        assert_true($za->locateName('server/generated/Handlers.php') !== false, "Generated Handlers in {$label}");
        assert_true($za->locateName('data/collections.json') !== false, "collections.json in {$label}");
        assert_true($za->locateName('server/generated/Navigation.php') !== false, "Generated Navigation in {$label}");
    } elseif ($runtime === 'python') {
        assert_true($za->locateName('server/generated/app_data.py') !== false, "Generated app_data.py in {$label}");
        assert_true($za->locateName('server/generated/handlers/__init__.py') !== false, "Python handlers package in {$label}");
    } else {
        assert_true($za->locateName('Models/GeneratedAppData.cs') !== false, "GeneratedAppData.cs in {$label}");
    }
    if ($runtime === 'php') {
        assert_true($za->locateName('index.php') !== false, "index.php in {$label} zip");
        assert_true($za->locateName('server/XmlStore.php') !== false, "XmlStore in {$label} zip");
    } elseif ($runtime === 'python') {
        assert_true($za->locateName('app.py') !== false, "app.py in {$label} zip");
    } else {
        assert_true($za->locateName('Program.cs') !== false, "Program.cs in {$label} zip");
        assert_true($za->locateName('Store.cs') !== false, "Store.cs in {$label} zip");
        assert_true($za->locateName('App.csproj') !== false, "App.csproj in {$label} zip");
    }
    if ($storage === 'xml') {
        assert_true($za->locateName('data/app.xml') !== false, "app.xml in {$label} zip");
        assert_true($za->locateName('data/schema.sql') === false, "no schema.sql in {$label} zip");
    } else {
        assert_true($za->locateName('data/schema.sql') !== false, "schema.sql in {$label} zip");
        assert_true($za->locateName('data/app.xml') === false, "no app.xml in {$label} zip");
    }
    if ($layout === 'mvc') {
        assert_true($za->locateName('views/Screen1.html') !== false, "MVC view in {$label} zip");
    } else {
        assert_true($za->locateName('views/Screen1.html') === false, "no MVC view in {$label} zip");
    }
    $indexHtml = $za->getFromName('public/index.html');
    assert_true(is_string($indexHtml) && !preg_match('/\sstyle="/i', $indexHtml), "packaged HTML has no style= for {$label}");
    assert_true(is_string($indexHtml) && str_contains($indexHtml, 'data-layout="' . $layout . '"'), "index marks layout {$label}");
    if ($layout === 'wet') {
        assert_true(is_string($indexHtml) && str_contains($indexHtml, 'id="wb-bnr"'), "WET GCWeb banner in {$label}");
        assert_true(is_string($indexHtml) && str_contains($indexHtml, 'theme.min.css'), "WET theme CSS CDN in {$label}");
        assert_true(is_string($indexHtml) && str_contains($indexHtml, 'wet-boew.min.js'), "WET-BOEW JS CDN in {$label}");
        assert_true(is_string($indexHtml) && str_contains($indexHtml, 'id="wb-cont"'), "WET main heading id in {$label}");
        $genCss = $za->getFromName('public/assets/generated.css');
        assert_true(is_string($genCss) && str_contains($genCss, 'layout-wet'), "WET rules in generated.css for {$label}");
        assert_true(is_string($indexHtml) && str_contains($indexHtml, 'assets/generated.css'), "generated.css linked in {$label}");
        assert_true(is_string($indexHtml) && !preg_match('/<style\b/i', $indexHtml), "no inline <style> in {$label} HTML");
    }
    if ($layout === 'responsive' || $layout === 'wet') {
        assert_true(
            is_string($indexHtml) && !preg_match('/position\s*:\s*absolute/i', $indexHtml),
            "flow layout strips absolute geometry for {$label}",
        );
        assert_true(
            is_string($indexHtml) && !preg_match('/(?:^|[{;])\s*(left|top)\s*:\s*-?\d/i', $indexHtml),
            "flow layout strips left/top offsets for {$label}",
        );
        assert_true(
            is_string($indexHtml) && !preg_match('/(?:^|[{;])\s*(width|height)\s*:\s*(?!0(?:px)?)(?:\d+(?:\.\d+)?)px/i', $indexHtml),
            "flow layout strips fixed px width/height for {$label}",
        );
    }
    $cfgJson = $za->getFromName('server/config.json');
    assert_true(is_string($cfgJson) && str_contains($cfgJson, '"runtime": "' . $runtime . '"'), "config runtime {$label}");
    assert_true(is_string($cfgJson) && str_contains($cfgJson, '"storage": "' . $storage . '"'), "config storage {$label}");
    assert_true(is_string($cfgJson) && str_contains($cfgJson, '"layout": "' . $layout . '"'), "config layout {$label}");
    $za->close();

    $read = $reader->read($zip);
    assert_true($read['runtime'] === $runtime, "reader runtime {$label}");
    assert_true($read['storage'] === $storage, "reader storage {$label}");
    assert_true($read['layout'] === $layout, "reader layout {$label}");
    $backDir = $extract . "/back_{$runtime}_{$storage}_{$layout}";
    $materializer->writeSources($read['ir'], $backDir);
    $docs = [];
    foreach (glob($backDir . '/Src/*.pa.yaml') ?: [] as $yaml) {
        $doc = ControlDocument::fromFile($yaml, 'Src/' . basename($yaml));
        if ($doc !== null) {
            $docs[] = $doc;
        }
    }
    $rebuilt = $builder->build($docs, $backDir);
    $beforeFp = ps_web_fingerprint($read['ir']);
    $afterFp = ps_web_fingerprint($rebuilt);
    assert_true($beforeFp === $afterFp, "roundtrip fingerprint {$label}");
    assert_true(count($rebuilt['screens'] ?? []) === 2, "roundtrip keeps both screens for {$label}");
}

// Older option names still produce the XML canvas package.
$legacyIr = $builder->build([$screen1, $screen2], $extract, [
    'runtime' => 'php',
    'storage' => 'lom',
    'layout' => 'pixel',
]);
assert_true(($legacyIr['export']['storage'] ?? '') === 'xml', 'legacy lom option stores xml');
assert_true(($legacyIr['export']['layout'] ?? '') === 'canvas', 'legacy pixel option stores canvas');
$legacyZip = $extract . '/pkg_legacy_lom_pixel.zip';
$legacyResult = $pkgBuilder->build($legacyIr, $legacyZip, $extract);
assert_true($legacyResult['ok'] === true, 'legacy lom/pixel options still build a package');
$legacyRead = $reader->read($legacyZip);
assert_true($legacyRead['storage'] === 'xml' && $legacyRead['layout'] === 'canvas', 'reader maps legacy package to xml/canvas');

// responsive used to be rejected under the name modern; it now ships
$modernRead = $reader->read($extract . '/pkg_php_sql_responsive.zip');
assert_true($modernRead['layout'] === 'responsive', 'responsive package is readable');

$registry = new SweepRegistry();
assert_true($registry->has('import_web_package'), 'import_web_package sweep is registered');

// XML store smoke
$xmlZip = $extract . '/pkg_php_xml_canvas.zip';
$unpackXml = $extract . '/unpack_php_xml';
mkdir($unpackXml, 0775, true);
$za = new ZipArchive();
$za->open($xmlZip);
$za->extractTo($unpackXml);
$za->close();
require_once $unpackXml . '/server/TableMap.php';
require_once $unpackXml . '/server/XmlStore.php';
$xmlStore = new XmlStore($unpackXml, []);
$xmlRows = $xmlStore->list('Items');
assert_true(count($xmlRows) >= 2, 'XML store lists seeded rows');
$xmlInserted = $xmlStore->insert('Items', ['Title' => 'Roundtrip', 'Value' => 'C']);
assert_true(isset($xmlInserted['Id']), 'XML insert returns Id');
assert_true(count($xmlStore->list('Items')) === count($xmlRows) + 1, 'XML insert increases row count');

// SQL store smoke when PDO sqlite is available
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $phpSqlZip = $extract . '/pkg_php_sql_canvas.zip';
    $unpack = $extract . '/unpack_php_sql';
    mkdir($unpack, 0775, true);
    $za = new ZipArchive();
    $za->open($phpSqlZip);
    $za->extractTo($unpack);
    $za->close();
    require_once $unpack . '/server/SqlStore.php';
    $sqlStore = new SqlStore($unpack, ['sql_dsn' => '']);
    $rows = $sqlStore->list('Items');
    assert_true(count($rows) >= 2, 'SQL store lists seeded rows');
    $inserted = $sqlStore->insert('Items', ['Title' => 'Roundtrip', 'Value' => 'C']);
    assert_true(isset($inserted['Id']), 'SQL insert returns Id');
    $rows2 = $sqlStore->list('Items');
    assert_true(count($rows2) === count($rows) + 1, 'SQL insert increases row count');
} else {
    assert_true(true, 'PDO sqlite driver is not loaded; SQL store smoke skipped');
}

// Python XML and SQL stores
foreach (['xml', 'sql'] as $pyStorage) {
    $pyZip = $extract . "/pkg_python_{$pyStorage}_canvas.zip";
    $unpackPy = $extract . "/unpack_python_{$pyStorage}";
    mkdir($unpackPy, 0775, true);
    $za = new ZipArchive();
    $za->open($pyZip);
    $za->extractTo($unpackPy);
    $za->close();
    $py = shell_exec(
        'python3 -c ' . escapeshellarg(
            "import sys; sys.path.insert(0, '{$unpackPy}/server'); from store import open_store; "
            . "from pathlib import Path; s=open_store(Path('{$unpackPy}')); "
            . "rows=s.list('Items'); print(len(rows)); s.insert('Items', {'Title':'Py','Value':'P'}); print(len(s.list('Items')))"
        ) . ' 2>&1'
    );
    assert_true(is_string($py) && preg_match('/\d+\n\d+/', trim($py)), "Python {$pyStorage} store list/insert works: " . trim((string) $py));
}

$dotnet = trim((string) shell_exec('command -v dotnet 2>/dev/null'));
if ($dotnet !== '') {
    $csZip = $extract . '/pkg_csharp_xml_canvas.zip';
    $unpackCs = $extract . '/unpack_csharp_xml';
    mkdir($unpackCs, 0775, true);
    $za = new ZipArchive();
    $za->open($csZip);
    $za->extractTo($unpackCs);
    $za->close();
    $cs = shell_exec('cd ' . escapeshellarg($unpackCs) . ' && dotnet run -- store list Items 2>&1');
    assert_true(is_string($cs) && str_contains($cs, '"ok":true'), 'C Sharp XML store lists rows: ' . trim((string) $cs));
} else {
    assert_true(is_file(dirname(__DIR__) . '/templates/web_package/csharp/Program.cs'), 'C Sharp package template is present');
}

// Sweep: unpacked web package → canvas app, then pack and read it back
$sweepDir = $extract . '/sweep_pkg';
mkdir($sweepDir, 0775, true);
$za = new ZipArchive();
$za->open($xmlZip);
$za->extractTo($sweepDir);
$za->close();
$expectedFp = ps_web_fingerprint($reader->read($sweepDir)['ir']);
$sweepReport = new Report();
(new ImportWebPackageSweep())->apply([], $sweepReport, ['_extract_dir' => $sweepDir]);
assert_true(is_file($sweepDir . '/Src/Screen1.pa.yaml'), 'import sweep writes Screen1 YAML');
assert_true(is_file($sweepDir . '/Src/Screen2.pa.yaml'), 'import sweep writes Screen2 YAML');
assert_true(!is_file($sweepDir . '/import/power_sweeper_ir.json'), 'import sweep removes the import archive from the canvas app');
assert_true(!is_file($sweepDir . '/ir/power_sweeper_ir.json'), 'import sweep removes legacy ir/ from the canvas app');
assert_true(!is_dir($sweepDir . '/public'), 'import sweep removes the web public folder');
$sweepBlob = json_encode($sweepReport->toArray());
assert_true(
    is_string($sweepBlob) && str_contains($sweepBlob, 'php') && str_contains($sweepBlob, 'xml') && str_contains($sweepBlob, 'canvas'),
    'import sweep reports the package shape',
);
$sweepDocs = [];
foreach (glob($sweepDir . '/Src/*.pa.yaml') ?: [] as $yaml) {
    $doc = ControlDocument::fromFile($yaml, 'Src/' . basename($yaml));
    if ($doc !== null) {
        $sweepDocs[] = $doc;
    }
}
$sweepIr = $builder->build($sweepDocs, $sweepDir);
assert_true(ps_web_fingerprint($sweepIr) === $expectedFp, 'import sweep roundtrip matches the web package');
$msapp = $extract . '/roundtrip.msapp';
$materializer->pack($sweepDir, $msapp);
assert_true(is_file($msapp) && filesize($msapp) > 100, 'roundtrip packs a .msapp');
$packed = new MsappArchive($msapp);
$packed->unpack();
$packedScreens = 0;
foreach ($packed->documents() as $doc) {
    foreach ($doc->controls() as $control) {
        if ($control->isScreen()) {
            $packedScreens++;
        }
    }
}
assert_true($packedScreens === 2, 'packed .msapp opens with both screens');
$packed->cleanup();

// Formula runtime smoke via node-less PHP-embedded check of runtime.js presence of Navigate/Set
$runtimeJs = (string) file_get_contents(dirname(__DIR__) . '/templates/web_package/assets/runtime.js');
assert_true(str_contains($runtimeJs, 'Navigate'), 'runtime evaluates Navigate');
assert_true(str_contains($runtimeJs, 'function evalExpr'), 'runtime has evalExpr');
assert_true(str_contains($runtimeJs, '/api/data'), 'runtime calls data address');
assert_true(str_contains($runtimeJs, 'parseNavigateTarget') || str_contains($runtimeJs, "'([^']+)'"), 'runtime accepts quoted Navigate screen names');
assert_true(str_contains($runtimeJs, 'data-navigate'), 'runtime wires sidebar data-navigate clicks');
assert_true(str_contains($runtimeJs, 'OnVisible') || str_contains($runtimeJs, 'runScreenOnVisible'), 'runtime runs screen OnVisible on Navigate');
assert_true(str_contains($runtimeJs, 'varCurrentUser'), 'runtime seeds varCurrentUser for Admin card visibility');
assert_true(is_file(dirname(__DIR__) . '/scripts/package_vcr_web.php'), 'VCR Azure package script exists');
assert_true(str_contains((string) file_get_contents(dirname(__DIR__) . '/scripts/package_azure_zip.sh'), "exclude 'web/'"), 'Azure sweeper zip excludes local web/ test data');

echo "web_roundtrip_test: all checks passed\n";
