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
 * `set_zip_path_style` hop / `posix_zip_paths` profile to force POSIX `/`.
 */
final class ZipTool
{
    /** Bump when deploy-critical zip behavior changes (shown in errors). */
    public const REV = '2026-07-25b';

    public const STYLE_WINDOWS = 'windows';
    public const STYLE_POSIX = 'posix';

    public static function hasZipArchive(): bool
    {
        return class_exists(ZipArchive::class);
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
     * @return self::STYLE_* detected style from the source archive
     */
    public static function extract(string $archivePath, string $destinationDir): string
    {
        if (!is_file($archivePath)) {
            throw new \RuntimeException('Archive not found: ' . $archivePath);
        }
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0777, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Unable to create extract directory');
        }

        $style = self::detectEntryStyle($archivePath);

        if (self::hasZipArchive()) {
            $zip = new ZipArchive();
            $opened = $zip->open($archivePath);
            if ($opened !== true) {
                throw new \RuntimeException('Unable to open archive as ZIP (code ' . $opened . ')');
            }
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name === false) {
                        continue;
                    }
                    self::writeNormalizedEntry($destinationDir, $name, static function () use ($zip, $i) {
                        return $zip->getFromIndex($i);
                    });
                }
            } finally {
                $zip->close();
            }
            self::normalizeBackslashTree($destinationDir);
            return $style;
        }

        // PharData: extractTo then rewrite any literal "foo\bar" filenames to foo/bar.
        self::withPharZip($archivePath, static function (PharData $phar) use ($destinationDir): void {
            $phar->extractTo($destinationDir, null, true);
        });
        self::normalizeBackslashTree($destinationDir);
        return $style;
    }

    /**
     * @param self::STYLE_*|null $entryStyle null = windows for .msapp, posix otherwise
     */
    public static function createFromDirectory(string $sourceDir, string $archivePath, ?string $entryStyle = null): void
    {
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

        // PharData rejects backslashes; for Windows-style .msapp use a pure-PHP ZIP writer.
        if ($useWindows) {
            self::writeZipArchive($entries, $archivePath);
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
     */
    private static function writeZipArchive(array $entries, string $archivePath): void
    {
        $fh = fopen($archivePath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to create archive file');
        }

        $central = '';
        $offset = 0;
        $count = 0;

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
