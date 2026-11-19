<?php

declare(strict_types=1);

namespace PowerSweeper;

use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * ZIP helpers that prefer ext-zip (ZipArchive) and fall back to a pure-PHP
 * writer / PharData when the zip extension is missing.
 *
 * On disk we always use forward slashes. Inside .msapp packages, entry path
 * separators are preserved from the source archive by default (almost always
 * Windows `\`, since Power Apps is a Windows product). Use the
 * `set_zip_path_style` sweep to force POSIX `/`.
 */
final class ZipTool
{
    /** Bump when deploy-critical zip behavior changes (shown in errors). */
    public const REV = '2026-10-11a';

    public const STYLE_WINDOWS = 'windows';
    public const STYLE_POSIX = 'posix';

    public static function hasZipArchive(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public static function hasUnzip(): bool
    {
        return self::findUnzipBinary() !== null;
    }

    /**
     * @return non-empty-string|null
     */
    public static function findUnzipBinary(): ?string
    {
        static $cached = false;
        static $path = null;
        if ($cached) {
            return $path;
        }
        $cached = true;
        foreach (['/usr/bin/unzip', '/bin/unzip'] as $candidate) {
            if (is_executable($candidate)) {
                $path = $candidate;
                return $path;
            }
        }
        $which = trim((string) shell_exec('command -v unzip 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            $path = $which;
            return $path;
        }
        return null;
    }

    /**
     * Detect zip entry path style from an existing archive.
     * Defaults to windows when no nested paths (or mixed with any `\`).
     */
    public static function detectEntryStyle(string $archivePath): string
    {
        if (!is_file($archivePath)) {
            return self::STYLE_WINDOWS;
        }

        $fromUnzip = self::listEntryNamesViaUnzip($archivePath);
        if ($fromUnzip !== null) {
            return self::styleFromNames($fromUnzip);
        }

        $sawBackslash = false;
        $sawForward = false;

        if (self::hasZipArchive()) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                return self::STYLE_WINDOWS;
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name === false) {
                        continue;
                    }
                    if (str_contains($name, '\\')) {
                        $sawBackslash = true;
                    } elseif (str_contains($name, '/')) {
                        $sawForward = true;
                    }
                }
            } finally {
                $zip->close();
            }
        } else {
            // Best-effort via PharData staging for non-.zip names.
            try {
                self::withPharZip($archivePath, static function (PharData $phar, string $pharZipPath) use (&$sawBackslash, &$sawForward): void {
                    $prefix = 'phar://' . $pharZipPath . '/';
                    foreach (new RecursiveIteratorIterator($phar) as $file) {
                        /** @var \SplFileInfo $file */
                        $pathname = $file->getPathname();
                        if (!str_starts_with($pathname, $prefix)) {
                            continue;
                        }
                        $name = substr($pathname, strlen($prefix));
                        if (str_contains($name, '\\')) {
                            $sawBackslash = true;
                        } elseif (str_contains($name, '/')) {
                            $sawForward = true;
                        }
                    }
                });
            } catch (\Throwable) {
                return self::STYLE_WINDOWS;
            }
        }

        if ($sawBackslash) {
            return self::STYLE_WINDOWS;
        }
        if ($sawForward) {
            return self::STYLE_POSIX;
        }
        // Root-only files (Header.json, …): Power Apps convention is Windows.
        return self::STYLE_WINDOWS;
    }

    /**
     * @param list<string> $names
     * @return self::STYLE_*
     */
    private static function styleFromNames(array $names): string
    {
        $sawBackslash = false;
        $sawForward = false;
        foreach ($names as $name) {
            if (str_contains($name, '\\')) {
                $sawBackslash = true;
            } elseif (str_contains($name, '/')) {
                $sawForward = true;
            }
        }
        if ($sawBackslash) {
            return self::STYLE_WINDOWS;
        }
        if ($sawForward) {
            return self::STYLE_POSIX;
        }
        return self::STYLE_WINDOWS;
    }

    /**
     * @return self::STYLE_* detected style from the source archive
     * @param null|callable(array<string,mixed>):void $onProgress
     */
    public static function extract(string $archivePath, string $destinationDir, ?callable $onProgress = null): string
    {
        if (!is_file($archivePath)) {
            throw new \RuntimeException('Archive not found: ' . $archivePath);
        }
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0777, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Unable to create extract directory');
        }

        self::tick($onProgress, 'Detecting archive layout…');
        $style = self::detectEntryStyle($archivePath);

        if (self::hasZipArchive()) {
            self::tick($onProgress, 'Extracting with ZipArchive…');
            $zip = new ZipArchive();
            $opened = $zip->open($archivePath);
            if ($opened !== true) {
                throw new \RuntimeException('Unable to open archive as ZIP (code ' . $opened . ')');
            }
            try {
                $n = $zip->numFiles;
                for ($i = 0; $i < $n; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name === false) {
                        continue;
                    }
                    self::writeNormalizedEntry($destinationDir, $name, static function () use ($zip, $i) {
                        return $zip->getFromIndex($i);
                    });
                    if ($onProgress !== null && ($i % 25 === 0 || $i + 1 === $n)) {
                        self::tick($onProgress, sprintf('Extracting… %d / %d', $i + 1, $n));
                    }
                }
            } finally {
                $zip->close();
            }
            self::normalizeBackslashTree($destinationDir);
            return $style;
        }

        // Prefer system unzip — PharData stages a full copy then extractTo, which
        // can take tens of seconds on shared hosts and starves NDJSON heartbeats.
        if (self::extractWithUnzip($archivePath, $destinationDir, $onProgress)) {
            self::normalizeBackslashTree($destinationDir);
            return $style;
        }

        self::tick($onProgress, 'Extracting with PharData (slow path)…');
        // PharData: extractTo then rewrite any literal "foo\bar" filenames to foo/bar.
        self::withPharZip($archivePath, static function (PharData $phar) use ($destinationDir): void {
            $phar->extractTo($destinationDir, null, true);
        });
        self::normalizeBackslashTree($destinationDir);
        return $style;
    }

    /**
     * List entry names in archive order (as stored). Skips directory markers.
     *
     * @return list<string>
     * @param null|callable(array<string,mixed>):void $onProgress
     */
    public static function listEntryNames(string $archivePath, ?callable $onProgress = null): array
    {
        if (!is_file($archivePath)) {
            return [];
        }

        self::tick($onProgress, 'Listing archive entries…');
        $viaUnzip = self::listEntryNamesViaUnzip($archivePath);
        if ($viaUnzip !== null) {
            return $viaUnzip;
        }

        $names = [];
        if (self::hasZipArchive()) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                return [];
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name === false || str_ends_with($name, '/') || str_ends_with($name, '\\')) {
                        continue;
                    }
                    $names[] = $name;
                }
            } finally {
                $zip->close();
            }
            return $names;
        }

        try {
            self::withPharZip($archivePath, static function (PharData $phar, string $pharZipPath) use (&$names): void {
                $prefix = 'phar://' . $pharZipPath . '/';
                foreach (new RecursiveIteratorIterator($phar) as $file) {
                    /** @var \SplFileInfo $file */
                    if (!$file->isFile()) {
                        continue;
                    }
                    $pathname = $file->getPathname();
                    if (!str_starts_with($pathname, $prefix)) {
                        continue;
                    }
                    $names[] = substr($pathname, strlen($prefix));
                }
            });
        } catch (\Throwable) {
            return [];
        }

        return $names;
    }

    /**
     * @param self::STYLE_*|null $entryStyle null = windows for .msapp, posix otherwise
     * @param list<string>|null $entryOrder preferred archive entry order (source names or / paths)
     * @param null|callable(array<string,mixed>):void $onProgress
     */
    public static function createFromDirectory(
        string $sourceDir,
        string $archivePath,
        ?string $entryStyle = null,
        ?array $entryOrder = null,
        ?callable $onProgress = null
    ): void {
        if (!is_dir($sourceDir)) {
            throw new \RuntimeException('Source directory not found: ' . $sourceDir);
        }

        if (is_file($archivePath)) {
            unlink($archivePath);
        }

        $sourceDir = rtrim($sourceDir, '/\\');
        self::normalizeBackslashTree($sourceDir);
        $files = self::listFiles($sourceDir);

        if ($entryStyle === null) {
            $entryStyle = self::wantsMsappEntryNames($archivePath) ? self::STYLE_WINDOWS : self::STYLE_POSIX;
        }
        $entryStyle = self::normalizeStyle($entryStyle);
        $useWindows = $entryStyle === self::STYLE_WINDOWS;

        /** @var array<string, string> $entries abs => archive entry name */
        $entries = [];
        foreach ($files as $abs => $rel) {
            $entries[$abs] = $useWindows ? self::packEntryName($rel) : self::fsEntryName($rel);
        }
        $entries = self::orderEntries($entries, $entryOrder);

        // Studio-exported .msapp files use DOS/Windows ZIP metadata (create_system=0,
        // version 20, flags 0). PHP ZipArchive on Linux stamps Unix (create_system=3),
        // which has been observed to fail Studio open with ErrOpeningDocument_UnknownError.
        // Always use the DOS-compatible writer for Windows-style packages.
        if ($useWindows) {
            self::tick($onProgress, 'Packing Windows-style .msapp…');
            self::writeZipArchive($entries, $archivePath, $onProgress);
            return;
        }

        if (self::hasZipArchive()) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create archive');
            }
            foreach ($entries as $abs => $entry) {
                $zip->addFile($abs, $entry);
            }
            $zip->close();
            return;
        }

        $tmpZip = $archivePath . '.ziptool.tmp.zip';
        if (is_file($tmpZip)) {
            unlink($tmpZip);
        }

        try {
            $phar = new PharData($tmpZip);
            foreach ($entries as $abs => $entry) {
                $data = file_get_contents($abs);
                if ($data === false) {
                    throw new \RuntimeException('Unable to read file for archive: ' . $entry);
                }
                $phar[$entry] = $data;
            }
            unset($phar);
            if (!rename($tmpZip, $archivePath)) {
                if (!copy($tmpZip, $archivePath)) {
                    throw new \RuntimeException('Unable to finalize archive');
                }
                unlink($tmpZip);
            }
        } catch (\Throwable $e) {
            if (is_file($tmpZip)) {
                @unlink($tmpZip);
            }
            throw new \RuntimeException(
                'Unable to create archive via PharData (ZipTool ' . self::REV . '): ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @param array<string, string> $entries abs => archive entry name
     * @param list<string>|null $entryOrder
     * @return array<string, string>
     */
    private static function orderEntries(array $entries, ?array $entryOrder): array
    {
        /** @var array<string, string> $byNorm normalized forward path => abs */
        $byNorm = [];
        foreach ($entries as $abs => $name) {
            $byNorm[str_replace('\\', '/', $name)] = $abs;
        }

        // Studio packages put these first; some broken cleans listed Assets first.
        $preferred = ['Header.json', 'Properties.json'];
        if (is_array($entryOrder)) {
            foreach ($entryOrder as $raw) {
                $norm = ltrim(str_replace('\\', '/', (string) $raw), '/');
                if ($norm !== '' && !in_array($norm, $preferred, true)) {
                    $preferred[] = $norm;
                }
            }
        }

        $ordered = [];
        $used = [];
        foreach ($preferred as $norm) {
            if (!isset($byNorm[$norm])) {
                continue;
            }
            $abs = $byNorm[$norm];
            if (isset($used[$abs])) {
                continue;
            }
            $ordered[$abs] = $entries[$abs];
            $used[$abs] = true;
        }
        foreach ($entries as $abs => $name) {
            if (!isset($used[$abs])) {
                $ordered[$abs] = $name;
            }
        }
        return $ordered;
    }

    /** @return self::STYLE_* */
    public static function normalizeStyle(string $style): string
    {
        $style = strtolower(trim($style));
        return match ($style) {
            self::STYLE_POSIX, 'linux', 'forward', 'slash', '/' => self::STYLE_POSIX,
            self::STYLE_WINDOWS, 'win', 'backslash', '\\' => self::STYLE_WINDOWS,
            default => throw new \InvalidArgumentException(
                'Unknown zip path style "' . $style . '" (use windows or posix)'
            ),
        };
    }

    public static function readEntry(string $archivePath, string $entryName): ?string
    {
        $forward = self::fsEntryName($entryName);
        $backslash = self::packEntryName($entryName);

        if (self::hasZipArchive()) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                return null;
            }
            try {
                $data = $zip->getFromName($forward);
                if ($data === false) {
                    $data = $zip->getFromName($backslash);
                }
                return $data === false ? null : $data;
            } finally {
                $zip->close();
            }
        }

        $tmp = sys_get_temp_dir() . '/ps_zip_read_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        try {
            self::extract($archivePath, $tmp);
            $path = $tmp . '/' . $forward;
            if (!is_file($path)) {
                return null;
            }
            $data = file_get_contents($path);
            return $data === false ? null : $data;
        } finally {
            self::removeTree($tmp);
        }
    }

    private static function wantsMsappEntryNames(string $archivePath): bool
    {
        $base = strtolower(basename($archivePath));
        return str_ends_with($base, '.msapp') || str_ends_with($base, '.msapp.zip');
    }

    /** Forward-slash path for on-disk / generic ZIP use. */
    private static function fsEntryName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = str_replace("\0", '', $name);
        $name = ltrim($name, '/');
        if ($name === '' || str_contains($name, '..')) {
            throw new \RuntimeException('Invalid archive entry name (ZipTool ' . self::REV . '): ' . $name);
        }
        return $name;
    }

    /**
     * Windows-style entry name for Studio-compatible .msapp packages.
     * Root files (Header.json, Properties.json) stay without separators.
     */
    private static function packEntryName(string $name): string
    {
        $name = self::fsEntryName($name);
        return str_replace('/', '\\', $name);
    }

    /**
     * Minimal ZIP writer that allows backslash entry names (PharData cannot).
     *
     * @param array<string, string> $entries absolute path => archive entry name
     * @param null|callable(array<string,mixed>):void $onProgress
     */
    private static function writeZipArchive(array $entries, string $archivePath, ?callable $onProgress = null): void
    {
        $fh = fopen($archivePath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to create archive file');
        }

        $central = '';
        $offset = 0;
        $count = 0;
        $total = count($entries);

        try {
            foreach ($entries as $abs => $name) {
                $data = file_get_contents($abs);
                if ($data === false) {
                    throw new \RuntimeException('Unable to read file for archive: ' . $name);
                }
                $nameBytes = $name;
                $nameLen = strlen($nameBytes);
                if ($nameLen > 0xFFFF) {
                    throw new \RuntimeException('Archive entry name too long: ' . $name);
                }

                $crc = crc32($data);
                $uncomp = strlen($data);
                $deflated = gzdeflate($data, 6);
                if ($deflated !== false && strlen($deflated) < $uncomp) {
                    $payload = $deflated;
                    $method = 8;
                    $comp = strlen($deflated);
                } else {
                    $payload = $data;
                    $method = 0;
                    $comp = $uncomp;
                }

                $local = pack(
                    'VvvvvvVVVvv',
                    0x04034b50, // local file header signature
                    20,         // version needed
                    0,          // flags
                    $method,
                    0,          // time
                    0,          // date
                    $crc,
                    $comp,
                    $uncomp,
                    $nameLen,
                    0           // extra len
                );
                $local .= $nameBytes;

                fwrite($fh, $local);
                fwrite($fh, $payload);

                $central .= pack(
                    'VvvvvvvVVVvvvvvVV',
                    0x02014b50, // central directory header
                    20,         // version made by
                    20,         // version needed
                    0,          // flags
                    $method,
                    0,          // time
                    0,          // date
                    $crc,
                    $comp,
                    $uncomp,
                    $nameLen,
                    0,          // extra
                    0,          // comment
                    0,          // disk start
                    0,          // int attr
                    0,          // ext attr
                    $offset
                );
                $central .= $nameBytes;

                $offset += strlen($local) + $comp;
                $count++;
                if ($onProgress !== null && ($count % 10 === 0 || $count === $total)) {
                    self::tick($onProgress, sprintf('Packing… %d / %d', $count, $total));
                }
            }

            $centralOffset = $offset;
            $centralSize = strlen($central);
            fwrite($fh, $central);
            fwrite($fh, pack(
                'VvvvvVVv',
                0x06054b50, // end of central directory
                0,          // disk number
                0,          // start disk
                $count,
                $count,
                $centralSize,
                $centralOffset,
                0           // comment length
            ));
        } finally {
            fclose($fh);
        }
    }

    /**
     * Move any filesystem paths that literally contain "\" into nested directories.
     * Needed after PharData::extractTo(), which preserves Windows zip entry names.
     */
    private static function normalizeBackslashTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $root = rtrim($root, '/\\');
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $abs = $file->getPathname();
            $rel = substr($abs, strlen($root) + 1);
            if ($rel === false || $rel === '' || !str_contains($rel, '\\')) {
                continue;
            }
            $paths[] = $abs;
        }

        // Longest paths first so nested "a\b\c" files move before parents.
        usort($paths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($paths as $abs) {
            if (!file_exists($abs)) {
                continue;
            }
            $rel = substr($abs, strlen($root) + 1);
            $normalizedRel = str_replace('\\', '/', $rel);
            if ($normalizedRel === $rel) {
                continue;
            }
            $target = $root . '/' . $normalizedRel;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException('Unable to normalize path to: ' . $target);
            }
            if (is_dir($abs)) {
                // Directory whose name contains "\"; children already moved.
                if (!is_dir($target)) {
                    if (!@rename($abs, $target) && !@mkdir($target, 0777, true)) {
                        throw new \RuntimeException('Unable to normalize directory: ' . $rel);
                    }
                }
                if (is_dir($abs)) {
                    @rmdir($abs);
                }
                continue;
            }
            if (is_file($target)) {
                // Prefer already-normalized file; drop the backslash duplicate.
                @unlink($abs);
                continue;
            }
            if (!@rename($abs, $target)) {
                if (!@copy($abs, $target)) {
                    throw new \RuntimeException('Unable to normalize file: ' . $rel);
                }
                @unlink($abs);
            }
        }
    }

    /**
     * @param callable():(string|false|null) $reader
     */
    private static function writeNormalizedEntry(string $destinationDir, string $entryName, callable $reader): void
    {
        $name = str_replace('\\', '/', $entryName);
        $name = ltrim($name, '/');
        if ($name === '' || str_contains($name, '..')) {
            return;
        }

        // Directory markers
        if (str_ends_with($name, '/')) {
            $dir = $destinationDir . '/' . rtrim($name, '/');
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create directory: ' . $dir);
            }
            return;
        }

        $target = $destinationDir . '/' . $name;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException('Unable to create directory: ' . $parent);
        }

        $data = $reader();
        if ($data === false || $data === null) {
            // Skip directory entries ZipArchive sometimes surfaces as false content
            if (!str_ends_with($entryName, '/') && !str_ends_with($entryName, '\\')) {
                throw new \RuntimeException('Unable to read archive entry: ' . $entryName);
            }
            return;
        }

        if (file_put_contents($target, $data) === false) {
            throw new \RuntimeException('Unable to write extracted file: ' . $name);
        }
    }

    /**
     * @return array<string, string> absolute path => forward-slash relative path
     */
    private static function listFiles(string $sourceDir): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $rel = substr($abs, strlen($sourceDir) + 1);
            $rel = str_replace('\\', '/', (string) $rel);
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $out[$abs] = $rel;
        }

        return $out;
    }

    /**
     * @param null|callable(array<string,mixed>):void $onProgress
     */
    private static function tick(?callable $onProgress, string $message): void
    {
        if ($onProgress === null) {
            return;
        }
        $onProgress([
            'type' => 'heartbeat',
            'message' => $message,
        ]);
    }

    /**
     * @return list<string>|null null when unzip is unavailable or listing failed
     */
    private static function listEntryNamesViaUnzip(string $archivePath): ?array
    {
        $bin = self::findUnzipBinary();
        if ($bin === null) {
            return null;
        }

        $cmd = escapeshellarg($bin) . ' -Z1 ' . escapeshellarg($archivePath) . ' 2>/dev/null';
        $lines = [];
        $code = 1;
        exec($cmd, $lines, $code);
        // 0 = ok, 1 = warning (backslash separators, etc.)
        if ($code > 1) {
            return null;
        }

        $names = [];
        foreach ($lines as $line) {
            $name = trim((string) $line);
            if ($name === '' || str_ends_with($name, '/') || str_ends_with($name, '\\')) {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    }

    /**
     * Extract via system unzip. Returns false when unzip is missing or fails hard.
     *
     * @param null|callable(array<string,mixed>):void $onProgress
     */
    private static function extractWithUnzip(string $archivePath, string $destinationDir, ?callable $onProgress = null): bool
    {
        $bin = self::findUnzipBinary();
        if ($bin === null) {
            return false;
        }

        self::tick($onProgress, 'Extracting with unzip…');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            [$bin, '-q', '-o', $archivePath, '-d', $destinationDir],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            // Fallback to blocking exec when proc_open is disabled.
            $cmd = escapeshellarg($bin) . ' -q -o ' . escapeshellarg($archivePath)
                . ' -d ' . escapeshellarg($destinationDir) . ' 2>&1';
            $out = [];
            $code = 1;
            exec($cmd, $out, $code);
            return $code <= 1;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $lastTick = microtime(true);
        while (true) {
            $status = proc_get_status($proc);
            $chunk = stream_get_contents($pipes[1]);
            if (is_string($chunk) && $chunk !== '') {
                // discard stdout (quiet mode)
            }
            $err = stream_get_contents($pipes[2]);
            if (is_string($err) && $err !== '') {
                $stderr .= $err;
            }
            if (!$status['running']) {
                break;
            }
            $now = microtime(true);
            if ($now - $lastTick >= 1.5) {
                self::tick($onProgress, 'Extracting with unzip…');
                $lastTick = $now;
            }
            usleep(200000);
        }

        $stdoutTail = stream_get_contents($pipes[1]);
        $stderrTail = stream_get_contents($pipes[2]);
        if (is_string($stderrTail) && $stderrTail !== '') {
            $stderr .= $stderrTail;
        }
        unset($stdoutTail);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        // unzip returns 1 for warnings (backslash path separators in .msapp).
        if ($code > 1) {
            throw new \RuntimeException(
                'unzip failed (code ' . $code . ', ZipTool ' . self::REV . ')'
                . ($stderr !== '' ? ': ' . trim($stderr) : '')
            );
        }
        return true;
    }

    /** @param callable(PharData, string):void $callback */
    private static function withPharZip(string $archivePath, callable $callback): void
    {
        $tmpZip = null;
        $path = $archivePath;
        if (!str_ends_with(strtolower($archivePath), '.zip')) {
            $tmpZip = sys_get_temp_dir() . '/ps_phar_' . bin2hex(random_bytes(4)) . '.zip';
            if (!copy($archivePath, $tmpZip)) {
                throw new \RuntimeException('Unable to stage archive for PharData');
            }
            $path = $tmpZip;
        }

        try {
            $phar = new PharData($path);
            $callback($phar, $path);
            unset($phar);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to open archive via PharData: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($tmpZip !== null && is_file($tmpZip)) {
                @unlink($tmpZip);
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
