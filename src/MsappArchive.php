<?php

declare(strict_types=1);

namespace PowerSweeper;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MsappArchive
{
    private string $extractDir;

    /** @var string ZipTool::STYLE_* preserved from source (or overridden by hop) */
    private string $entryStyle = ZipTool::STYLE_WINDOWS;

    /** @var list<string> Source archive entry names in original order */
    private array $entryOrder = [];

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

    /** @return ZipTool::STYLE_* */
    public function entryStyle(): string
    {
        return $this->entryStyle;
    }

    /** @param ZipTool::STYLE_*|string $style */
    public function setEntryStyle(string $style): void
    {
        $this->entryStyle = ZipTool::normalizeStyle($style);
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

        $this->entryOrder = ZipTool::listEntryNames($this->msappPath);
        $this->entryStyle = ZipTool::extract($this->msappPath, $this->extractDir);
        $this->discoverDocuments();
    }

    public function saveDocuments(): void
    {
        foreach ($this->documents as $doc) {
            if (!$doc->isDirty()) {
                continue;
            }
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
        ZipTool::createFromDirectory($this->extractDir, $outputPath, $this->entryStyle, $this->entryOrder);
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
