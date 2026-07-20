<?php

declare(strict_types=1);

namespace PowerSweeper;

use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * ZIP helpers that prefer ext-zip (ZipArchive) and fall back to PharData
 * when the zip extension is missing (common on some shared hosts).
 *
 * .msapp archives often contain Windows-style entry names (backslashes).
 * Those are normalized to forward-slash paths on extract/pack.
 */
final class ZipTool
{
    /** Bump when deploy-critical zip behavior changes (shown in errors). */
    public const REV = '2026-07-19c';

    public static function hasZipArchive(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public static function extract(string $archivePath, string $destinationDir): void
    {
        if (!is_file($archivePath)) {
            throw new \RuntimeException('Archive not found: ' . $archivePath);
        }
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0777, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException('Unable to create extract directory');
        }

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
            return;
        }

        // PharData: extractTo then rewrite any literal "foo\bar" filenames to foo/bar.
        self::withPharZip($archivePath, static function (PharData $phar) use ($destinationDir): void {
            $phar->extractTo($destinationDir, null, true);
        });
        self::normalizeBackslashTree($destinationDir);
    }

    public static function createFromDirectory(string $sourceDir, string $archivePath): void
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

        if (self::hasZipArchive()) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create archive');
            }
            foreach ($files as $abs => $rel) {
                $zip->addFile($abs, self::zipEntryName($rel));
            }
            $zip->close();
            return;
        }

        // PharData infers format from extension; build via .zip then rename.
        $tmpZip = $archivePath . '.ziptool.tmp.zip';
        if (is_file($tmpZip)) {
            unlink($tmpZip);
        }

        try {
            $phar = new PharData($tmpZip);
            foreach ($files as $abs => $rel) {
                $entry = self::zipEntryName($rel);
                $data = file_get_contents($abs);
                if ($data === false) {
                    throw new \RuntimeException('Unable to read file for archive: ' . $rel);
                }
                // Prefer offset assignment — avoids addFile using a backslash source path as the entry name.
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

    public static function readEntry(string $archivePath, string $entryName): ?string
    {
        $want = self::zipEntryName($entryName);

        if (self::hasZipArchive()) {
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                return null;
            }
            try {
                $data = $zip->getFromName($want);
                if ($data === false) {
                    $data = $zip->getFromName(str_replace('/', '\\', $want));
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
            $path = $tmp . '/' . $want;
            if (!is_file($path)) {
                return null;
            }
            $data = file_get_contents($path);
            return $data === false ? null : $data;
        } finally {
            self::removeTree($tmp);
        }
    }

    private static function zipEntryName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = str_replace("\0", '', $name);
        $name = ltrim($name, '/');
        if ($name === '' || str_contains($name, '..') || str_contains($name, '\\')) {
            throw new \RuntimeException('Invalid archive entry name (ZipTool ' . self::REV . '): ' . $name);
        }
        return $name;
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
