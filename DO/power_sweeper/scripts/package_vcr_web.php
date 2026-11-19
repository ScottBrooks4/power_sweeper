<?php

declare(strict_types=1);

/**
 * Build a standalone CDLS (L) VCR web zip for Azure Zip Deploy or email.
 *
 *   php scripts/package_vcr_web.php [output.zip]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\WebApp\WebAppPackageBuilder;

$root = dirname(__DIR__);
$irPath = $root . '/web/VCR/ir/power_sweeper_ir.json';
if (!is_file($irPath)) {
    fwrite(STDERR, "Missing IR at {$irPath}\n");
    exit(1);
}

$out = $argv[1] ?? ($root . '/storage/out/vcr-web-azure.zip');
if ($out === '' || $out[0] !== '/') {
    $out = getcwd() . '/' . ltrim((string) $out, '/');
}
$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}

$ir = json_decode((string) file_get_contents($irPath), true);
if (!is_array($ir)) {
    fwrite(STDERR, "IR did not parse\n");
    exit(1);
}
$ir['export'] = array_merge(is_array($ir['export'] ?? null) ? $ir['export'] : [], [
    'runtime' => 'php',
    'storage' => 'sql',
    'layout' => 'pixel',
]);

$tmpZip = sys_get_temp_dir() . '/vcr_pkg_' . bin2hex(random_bytes(3)) . '.zip';
$result = (new WebAppPackageBuilder())->build($ir, $tmpZip, null);
if (empty($result['ok']) || !is_file($tmpZip)) {
    fwrite(STDERR, 'Package build failed: ' . (string) ($result['message'] ?? 'unknown') . "\n");
    exit(1);
}

$stage = sys_get_temp_dir() . '/vcr_stage_' . bin2hex(random_bytes(3));
mkdir($stage, 0775, true);
$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) {
    fwrite(STDERR, "Cannot reopen {$tmpZip}\n");
    exit(1);
}
$zip->extractTo($stage);
$zip->close();

$mediaSrc = $root . '/web/VCR/assets/media';
$mediaDst = $stage . '/public/assets/media';
if (is_dir($mediaSrc)) {
    if (!is_dir($mediaDst)) {
        mkdir($mediaDst, 0775, true);
    }
    foreach (scandir($mediaSrc) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $from = $mediaSrc . '/' . $name;
        if (is_file($from)) {
            copy($from, $mediaDst . '/' . $name);
        }
    }
}

// Fresh SQL seed — do not ship local test tracking rows / outbox mail.
foreach (['data/app.sqlite', 'data/app.xml'] as $rel) {
    $p = $stage . '/' . $rel;
    if (is_file($p)) {
        unlink($p);
    }
}
$outbox = $stage . '/data/outbox';
if (is_dir($outbox)) {
    foreach (glob($outbox . '/*') ?: [] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
}

$configPath = $stage . '/server/config.json';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : [];
if (!is_array($config)) {
    $config = [];
}
$config['runtime'] = 'php';
$config['storage'] = 'sql';
$config['mail_delivery'] = 'outbox';
$config['mail_unlisted'] = 'rewrite';
$config['mail_allowlist'] = [
    'morath99@gmail.com',
    'Scott.Brooks4@ecn.forces.gc.ca',
    'Scott.Brooks4@forces.gc.ca',
];
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

file_put_contents($stage . '/AZURE.txt', <<<'TXT'
CDLS (L) VCR / VCN — Azure / email package
==========================================

This zip is a self-contained PHP 8.2+ web app (SQL in data/app.sqlite).

Put it on Azure
---------------
1. Azure Portal → Create a Linux Web App, PHP 8.2 or 8.3.
2. App Service → Deployment Center / Advanced Tools (Kudu) → Zip Deploy
   this file (or unzip into /home/site/wwwroot).
3. Configuration → General settings → Startup Command:

   bash /home/site/wwwroot/azure/startup.sh

4. Browse https://<app>.azurewebsites.net/

Local check
-----------
  unzip vcr-web-azure.zip -d vcr && cd vcr
  php -S localhost:8080 router.php

Mail
----
server/config.json ships as mail_delivery=outbox with the owner allowlist
(morath99@gmail.com and Scott.Brooks4@…). No mail goes to anyone else.
To send real mail later, set mail_delivery and SMTP on the App Service —
keep the allowlist until you intend production recipients.

Data
----
First request creates data/app.sqlite (Settings + Admin users). Submitted
VCR rows persist there. Do not copy a local test database into this zip.
TXT);

$outZip = new ZipArchive();
if ($outZip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot write {$out}\n");
    exit(1);
}
$real = realpath($stage) ?: $stage;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $local = ltrim(str_replace($real, '', $file->getPathname()), '/\\');
    $outZip->addFile($file->getPathname(), str_replace('\\', '/', $local));
}
$outZip->close();
@unlink($tmpZip);

$rm;
$rm = static function (string $path) use (&$rm): void {
    if (is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $rm($path . '/' . $n);
    }
    rmdir($path);
};
$rm($stage);

echo 'Wrote ' . $out . "\n";
echo (string) ($result['message'] ?? '') . "\n";
exit(0);
