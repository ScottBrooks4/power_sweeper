<?php

declare(strict_types=1);

/**
 * Publish VCR web demos under /web/{runtime}-{storage}-{layout}/.
 *
 * Uses the full CDLS (L) VCR conversion file so each language × data × pages
 * shape can be reviewed with the same complex app.
 *
 *   php scripts/publish_web_demos.php
 *
 * Links:
 *   http://localhost/power_sweeper/web/
 *   http://localhost/power_sweeper/web/php-sql-canvas/
 *   http://localhost/power_sweeper/web/VCR/   (alias of php-sql-canvas)
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\WebApp\WebAppIrBuilder;
use PowerSweeper\WebApp\WebAppPackageBuilder;

@ini_set('memory_limit', '2048M');
@set_time_limit(0);

$root = dirname(__DIR__);
$webRoot = $root . '/web';
$irPath = $webRoot . '/VCR/ir/power_sweeper_ir.json';
if (!is_file($irPath)) {
    $fallback = '/home/lulu/Projects/power_sweeper-web-roundtrip/web/VCR/ir/power_sweeper_ir.json';
    if (is_file($fallback)) {
        if (!is_dir(dirname($irPath))) {
            mkdir(dirname($irPath), 0775, true);
        }
        copy($fallback, $irPath);
    }
}
if (!is_file($irPath)) {
    fwrite(STDERR, "Missing VCR IR at {$irPath}\n");
    exit(1);
}

$baseIr = json_decode((string) file_get_contents($irPath), true);
if (!is_array($baseIr)) {
    fwrite(STDERR, "VCR IR did not parse\n");
    exit(1);
}
// Keep a durable copy of the conversion file — /web/VCR is rebuilt as an alias later.
$irKeep = $webRoot . '/_vcr_ir/power_sweeper_ir.json';
if (!is_dir(dirname($irKeep))) {
    mkdir(dirname($irKeep), 0775, true);
}
copy($irPath, $irKeep);
$irPath = $irKeep;

$mediaSrcCandidates = [
    $webRoot . '/VCR/public/assets/media',
    '/home/lulu/Projects/power_sweeper-web-roundtrip/web/VCR/public/assets/media',
    $webRoot . '/VCR/assets/media',
];
$mediaSrc = '';
foreach ($mediaSrcCandidates as $cand) {
    if (is_dir($cand)) {
        $mediaSrc = $cand;
        break;
    }
}

if (!is_dir($webRoot) && !mkdir($webRoot, 0775, true) && !is_dir($webRoot)) {
    fwrite(STDERR, "Cannot create {$webRoot}\n");
    exit(1);
}

$pkgBuilder = new WebAppPackageBuilder();
$templates = $root . '/templates/web_package';
$work = sys_get_temp_dir() . '/ps_vcr_publish_' . bin2hex(random_bytes(3));
mkdir($work, 0775, true);
$links = [];

$screens = (int) ($baseIr['stats']['screens'] ?? count($baseIr['screens'] ?? []));
$controls = (int) ($baseIr['stats']['controls'] ?? 0);
echo "VCR IR: screens={$screens} controls={$controls}\n";

foreach (['php', 'python', 'csharp'] as $runtime) {
    foreach (['xml', 'sql'] as $storage) {
        foreach (['mvc', 'canvas', 'responsive', 'wet'] as $layout) {
            $slug = "{$runtime}-{$storage}-{$layout}";
            $dest = $webRoot . '/' . $slug;
            $ir = $baseIr;
            $ir['export'] = [
                'runtime' => WebAppIrBuilder::normalizeRuntime($runtime),
                'storage' => WebAppIrBuilder::normalizeStorage($storage),
                'layout' => WebAppIrBuilder::normalizeLayout($layout),
            ];
            $ir['server_language'] = $ir['export']['runtime'];
            if (isset($ir['fidelity']) && is_array($ir['fidelity'])) {
                $ir['fidelity']['level'] = $ir['export']['layout'];
            }

            $zip = $work . "/{$slug}.zip";
            echo "building {$slug}…\n";
            $result = $pkgBuilder->build($ir, $zip, null);
            if (empty($result['ok']) || !is_file($zip)) {
                fwrite(STDERR, "Failed {$slug}: " . ($result['message'] ?? '') . "\n");
                exit(1);
            }

            rmTree($dest);
            mkdir($dest, 0775, true);
            $za = new ZipArchive();
            if ($za->open($zip) !== true) {
                fwrite(STDERR, "Cannot open {$zip}\n");
                exit(1);
            }
            $za->extractTo($dest);
            $za->close();
            @unlink($zip);

            ensurePhpBrowseLayer($dest, $templates);
            copyMedia($mediaSrc, $dest . '/public/assets/media');
            clearLocalData($dest);
            // Hosts without pdo_sqlite still need a document for /api/data while reviewing UI.
            if ($storage === 'sql') {
                seedXmlBesideSql($dest, $ir);
            }
            stampBase($dest, '/power_sweeper/web/' . $slug);

            $links[] = [
                'slug' => $slug,
                'runtime' => $runtime,
                'storage' => $storage,
                'layout' => $layout,
                'url' => '/power_sweeper/web/' . $slug . '/',
                'note' => (string) ($result['message'] ?? ''),
            ];
            echo "published {$slug} (" . ($result['message'] ?? '') . ")\n";
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }
}

// /web/VCR is the familiar path — keep it as php + sql + canvas.
$aliasSrc = $webRoot . '/php-sql-canvas';
$aliasDest = $webRoot . '/VCR';
if (is_dir($aliasSrc)) {
    rmTree($aliasDest);
    passthroughCopy($aliasSrc, $aliasDest);
    // Preserve the full conversion file path people already use.
    if (!is_dir($aliasDest . '/ir')) {
        mkdir($aliasDest . '/ir', 0775, true);
    }
    copy($irPath, $aliasDest . '/ir/power_sweeper_ir.json');
    stampBase($aliasDest, '/power_sweeper/web/VCR');
    array_unshift($links, [
        'slug' => 'VCR',
        'runtime' => 'php',
        'storage' => 'sql',
        'layout' => 'canvas',
        'url' => '/power_sweeper/web/VCR/',
        'note' => 'alias of php-sql-canvas',
    ]);
    echo "published VCR (alias of php-sql-canvas)\n";
}

writeIndex($webRoot, $links, $screens, $controls);
rmTree($work);
echo "index → {$webRoot}/index.html\n";
echo "Open http://localhost/power_sweeper/web/\n";
foreach ($links as $link) {
    echo '  ' . $link['url'] . "\n";
}

/**
 * @param list<array{slug:string,runtime:string,storage:string,layout:string,url:string,note?:string}> $links
 */
function writeIndex(string $webRoot, array $links, int $screens, int $controls): void
{
    $rows = '';
    foreach ($links as $link) {
        $slug = htmlspecialchars($link['slug'], ENT_QUOTES);
        $url = htmlspecialchars($link['url'], ENT_QUOTES);
        $runtime = htmlspecialchars($link['runtime'], ENT_QUOTES);
        $storage = htmlspecialchars($link['storage'], ENT_QUOTES);
        $layout = htmlspecialchars($link['layout'], ENT_QUOTES);
        $rows .= "<tr><td><a href=\"{$url}\">{$slug}</a></td><td>{$runtime}</td><td>{$storage}</td><td>{$layout}</td></tr>\n";
    }
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>VCR web demos — Power Sweeper</title>
  <style>
    body { font: 1rem/1.45 system-ui, sans-serif; margin: 2rem; max-width: 52rem; color: #111; }
    table { border-collapse: collapse; width: 100%; }
    th, td { text-align: left; padding: 0.45rem 0.6rem; border-bottom: 1px solid #ddd; }
    a { color: #0b5fff; }
    .meta { color: #555; }
  </style>
</head>
<body>
  <h1>CDLS (L) VCR — web demos</h1>
  <p class="meta">Same conversion file ({$screens} screens, {$controls} controls) exported in every language × data × pages shape. Open a link and tell me what behavior is wrong.</p>
  <p class="meta"><code>/web/VCR/</code> is the familiar path (php + sql + canvas).</p>
  <table>
    <thead><tr><th>Package</th><th>Language</th><th>Data</th><th>Pages</th></tr></thead>
    <tbody>
{$rows}    </tbody>
  </table>
</body>
</html>
HTML;
    file_put_contents($webRoot . '/index.html', $html);
    if (is_file($webRoot . '/index.php')) {
        @unlink($webRoot . '/index.php');
    }
}

function ensurePhpBrowseLayer(string $dest, string $templates): void
{
    $copies = [
        'php/index.php' => 'index.php',
        'php/router.php' => 'router.php',
        'php/api.php' => 'server/api.php',
        'php/TableMap.php' => 'server/TableMap.php',
        'php/XmlStore.php' => 'server/XmlStore.php',
        'php/SqlStore.php' => 'server/SqlStore.php',
        'php/LomStore.php' => 'server/LomStore.php',
        'php/MailAllowlist.php' => 'server/MailAllowlist.php',
        'php/MailGateway.php' => 'server/MailGateway.php',
        'php/StoreSeed.php' => 'server/StoreSeed.php',
        'php/GraphGateway.php' => 'server/GraphGateway.php',
        'assets/runtime.js' => 'public/assets/runtime.js',
    ];
    foreach ($copies as $from => $to) {
        $src = $templates . '/' . $from;
        $dst = $dest . '/' . $to;
        if (!is_file($src)) {
            continue;
        }
        $dir = dirname($dst);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        copy($src, $dst);
    }
    // MVC demos (any runtime): Apache serves via the PHP controllers + Graph/AAD models.
    if (is_dir($dest . '/views')) {
        if (!is_dir($dest . '/controllers')) {
            mkdir($dest . '/controllers', 0775, true);
        }
        if (!is_dir($dest . '/models')) {
            mkdir($dest . '/models', 0775, true);
        }
        $mvc = [
            'php/screen.php' => 'controllers/screen.php',
            'php/AuthController.php' => 'controllers/AuthController.php',
            'php/GraphController.php' => 'controllers/GraphController.php',
            'php/AppModel.php' => 'models/AppModel.php',
            'php/GraphModel.php' => 'models/GraphModel.php',
        ];
        foreach ($mvc as $from => $to) {
            $src = $templates . '/' . $from;
            if (is_file($src)) {
                copy($src, $dest . '/' . $to);
            }
        }
    }
}

function copyMedia(string $src, string $dst): void
{
    if ($src === '' || !is_dir($src)) {
        return;
    }
    if (!is_dir($dst)) {
        mkdir($dst, 0775, true);
    }
    foreach (scandir($src) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $from = $src . '/' . $name;
        if (is_file($from)) {
            copy($from, $dst . '/' . $name);
        }
    }
}

function clearLocalData(string $dest): void
{
    foreach (['data/app.sqlite', 'data/outbox/last.json', 'data/session.json'] as $rel) {
        $p = $dest . '/' . $rel;
        if (is_file($p)) {
            unlink($p);
        }
    }
    // Apache (http) must be able to write outbox + AAD demo session.
    $data = $dest . '/data';
    if (!is_dir($data)) {
        mkdir($data, 0777, true);
    }
    @chmod($data, 0777);
    $outbox = $data . '/outbox';
    if (!is_dir($outbox)) {
        mkdir($outbox, 0777, true);
    }
    @chmod($outbox, 0777);
}

/** @param array<string, mixed> $ir */
function seedXmlBesideSql(string $dest, array $ir): void
{
    $dir = $dest . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $tables = is_array($ir['tables'] ?? null) ? $ir['tables'] : [];
    $xml = "<app>\n";
    foreach ($tables as $table) {
        if (!is_array($table)) {
            continue;
        }
        $tName = htmlspecialchars((string) ($table['safe_name'] ?? $table['name'] ?? 'Items'), ENT_QUOTES);
        $xml .= "  <{$tName}>\n";
        foreach ($table['rows'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $xml .= "    <row";
            foreach ($row as $k => $v) {
                $xml .= ' ' . preg_replace('/\W+/', '_', (string) $k)
                    . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"';
            }
            $xml .= ">";
            $title = (string) ($row['Title'] ?? $row['title'] ?? '');
            $xml .= htmlspecialchars($title, ENT_QUOTES) . "</row>\n";
        }
        $xml .= "  </{$tName}>\n";
    }
    $xml .= "</app>\n";
    file_put_contents($dir . '/app.xml', $xml);
}

function stampBase(string $dest, string $base): void
{
    $index = $dest . '/public/index.html';
    if (!is_file($index)) {
        return;
    }
    $html = (string) file_get_contents($index);
    $script = '<script>window.PS_BASE=' . json_encode($base) . ';</script>';
    if (str_contains($html, 'window.PS_BASE')) {
        $html = preg_replace('/<script>window\.PS_BASE=.*?<\/script>/', $script, $html) ?? $html;
    } elseif (str_contains($html, 'assets/runtime.js')) {
        $html = str_replace(
            '<script src="assets/runtime.js"></script>',
            $script . "\n  <script src=\"assets/runtime.js\"></script>",
            $html,
        );
    } else {
        $html = str_replace('</body>', $script . "\n</body>", $html);
    }
    file_put_contents($index, $html);
}

function passthroughCopy(string $src, string $dst): void
{
    mkdir($dst, 0775, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($src) + 1);
        $target = $dst . '/' . $rel;
        if ($file->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }
        } else {
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            copy($file->getPathname(), $target);
        }
    }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
