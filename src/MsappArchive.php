<?php

declare(strict_types=1);

namespace PowerSweeper;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class MsappArchive
{
    private string $extractDir;

    /** @var list<ControlDocument> */
    private array $documents = [];

    /** @var array<string, string> relative path => absolute path for control docs */
    private array $documentPaths = [];

    public function __construct(private readonly string $msappPath)
    {
        $this->extractDir = sys_get_temp_dir() . '/power_sweeper_' . bin2hex(random_bytes(8));
    }

    public function extractDir(): string
    {
        return $this->extractDir;
    }

    /** @return list<ControlDocument> */
    public function documents(): array
    {
        return $this->documents;
    }

    public function unpack(): void
    {
        if (!is_file($this->msappPath)) {
            throw new \RuntimeException('msapp file not found: ' . $this->msappPath);
        }

        mkdir($this->extractDir, 0777, true);
        $zip = new ZipArchive();
        $opened = $zip->open($this->msappPath);
        if ($opened !== true) {
            throw new \RuntimeException('Unable to open .msapp as ZIP (code ' . $opened . ')');
        }
        if (!$zip->extractTo($this->extractDir)) {
            $zip->close();
            throw new \RuntimeException('Failed to extract .msapp');
        }
        $zip->close();

        $this->discoverDocuments();
    }

    public function saveDocuments(): void
    {
        foreach ($this->documents as $doc) {
            $abs = $this->documentPaths[$doc->relativePath] ?? null;
            if ($abs === null) {
                continue;
            }
            $doc->save($abs);
        }
    }

    public function pack(string $outputPath): void
    {
        $this->saveDocuments();

        if (is_file($outputPath)) {
            unlink($outputPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create output .msapp');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $abs = $file->getPathname();
            $rel = substr($abs, strlen($this->extractDir) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($file->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($abs, $rel);
            }
        }

        $zip->close();
    }

    public function cleanup(): void
    {
        if (!is_dir($this->extractDir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
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
        @rmdir($this->extractDir);
    }

    private function discoverDocuments(): void
    {
        $this->documents = [];
        $this->documentPaths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->extractDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $rel = substr($abs, strlen($this->extractDir) + 1);
            $rel = str_replace('\\', '/', $rel);

            $doc = ControlDocument::fromFile($abs, $rel);
            if ($doc === null) {
                continue;
            }
            // Prefer Src YAML over editorstate noise
            if (str_contains(strtolower($rel), 'editorstate')) {
                continue;
            }
            $this->documents[] = $doc;
            $this->documentPaths[$doc->relativePath] = $abs;
        }
    }
}
