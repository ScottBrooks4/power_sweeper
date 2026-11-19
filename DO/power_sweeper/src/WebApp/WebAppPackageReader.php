<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Read a web package produced by the power-app export (zip or unpacked tree).
 */
final class WebAppPackageReader
{
    /**
     * @return array{ir:array<string,mixed>,runtime:string,storage:string,layout:string,notes:list<string>}
     */
    public function read(string $path): array
    {
        if (is_dir($path)) {
            return $this->fromDirectory($path);
        }
        if (!is_file($path)) {
            throw new \RuntimeException('web package not found: ' . $path);
        }
        if (str_ends_with(strtolower($path), '.json')) {
            $raw = file_get_contents($path);
            $ir = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($ir)) {
                throw new \RuntimeException('conversion file is not JSON');
            }
            $export = is_array($ir['export'] ?? null) ? $ir['export'] : [];

            return [
                'ir' => $ir,
                'runtime' => WebAppIrBuilder::normalizeRuntime((string) ($export['runtime'] ?? $ir['server_language'] ?? 'php')),
                'storage' => WebAppIrBuilder::normalizeStorage((string) ($export['storage'] ?? 'xml')),
                'layout' => WebAppIrBuilder::normalizeLayout((string) ($export['layout'] ?? 'canvas')),
                'notes' => ['read conversion file directly'],
            ];
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('cannot open web package');
        }
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name)) {
                    $names[] = str_replace('\\', '/', $name);
                }
            }
            $irRaw = $zip->getFromName('import/power_sweeper_ir.json');
            if (!is_string($irRaw)) {
                // Legacy packages kept the conversion archive under ir/.
                $irRaw = $zip->getFromName('ir/power_sweeper_ir.json');
            }
            if (!is_string($irRaw)) {
                throw new \RuntimeException('package has no import/power_sweeper_ir.json (or legacy ir/)');
            }
            $ir = json_decode($irRaw, true);
            if (!is_array($ir)) {
                throw new \RuntimeException('package conversion file is not JSON');
            }
            $configRaw = $zip->getFromName('server/config.json');
            $config = is_string($configRaw) ? json_decode($configRaw, true) : [];
            $index = $zip->getFromName('public/index.html');

            return $this->assemble(
                $ir,
                $names,
                is_string($index) ? $index : '',
                is_array($config) ? $config : [],
            );
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{ir:array<string,mixed>,runtime:string,storage:string,layout:string,notes:list<string>}
     */
    private function fromDirectory(string $dir): array
    {
        $irPath = $dir . '/import/power_sweeper_ir.json';
        if (!is_file($irPath)) {
            $irPath = $dir . '/ir/power_sweeper_ir.json';
        }
        if (!is_file($irPath)) {
            throw new \RuntimeException('unpacked package has no import/power_sweeper_ir.json (or legacy ir/)');
        }
        $ir = json_decode((string) file_get_contents($irPath), true);
        if (!is_array($ir)) {
            throw new \RuntimeException('package conversion file is not JSON');
        }
        $names = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($dir) + 1);
            $names[] = str_replace('\\', '/', $rel);
        }
        $config = [];
        $configPath = $dir . '/server/config.json';
        if (is_file($configPath)) {
            $decoded = json_decode((string) file_get_contents($configPath), true);
            $config = is_array($decoded) ? $decoded : [];
        }
        $index = is_file($dir . '/public/index.html') ? (string) file_get_contents($dir . '/public/index.html') : '';

        return $this->assemble($ir, $names, $index, $config);
    }

    /**
     * @param array<string, mixed> $ir
     * @param list<string> $names
     * @param array<string, mixed> $config
     * @return array{ir:array<string,mixed>,runtime:string,storage:string,layout:string,notes:list<string>}
     */
    private function assemble(array $ir, array $names, string $indexHtml, array $config): array
    {
        $notes = [];
        $export = is_array($ir['export'] ?? null) ? $ir['export'] : [];

        $runtime = $this->pick(
            'runtime',
            $this->runtimeFromFiles($names),
            isset($config['runtime']) ? WebAppIrBuilder::normalizeRuntime((string) $config['runtime']) : null,
            WebAppIrBuilder::normalizeRuntime((string) ($export['runtime'] ?? 'php')),
            $notes,
        );
        $storage = $this->pick(
            'storage',
            $this->storageFromFiles($names),
            isset($config['storage']) ? WebAppIrBuilder::normalizeStorage((string) $config['storage']) : null,
            WebAppIrBuilder::normalizeStorage((string) ($export['storage'] ?? 'xml')),
            $notes,
        );
        $layout = $this->pick(
            'layout',
            $this->layoutFromFiles($names, $indexHtml),
            isset($config['layout']) ? WebAppIrBuilder::normalizeLayout((string) $config['layout']) : null,
            WebAppIrBuilder::normalizeLayout((string) ($export['layout'] ?? 'canvas')),
            $notes,
        );

        return [
            'ir' => $ir,
            'runtime' => $runtime,
            'storage' => $storage,
            'layout' => $layout,
            'notes' => $notes,
        ];
    }

    /**
     * @param list<string> $notes
     */
    private function pick(string $axis, ?string $fromFiles, ?string $fromConfig, string $fromIr, array &$notes): string
    {
        if ($fromFiles !== null && $fromConfig !== null && $fromFiles !== $fromConfig) {
            $notes[] = $axis . ' files say ' . $fromFiles . '; config says ' . $fromConfig;
        }
        if ($fromFiles !== null) {
            return $fromFiles;
        }
        if ($fromConfig !== null) {
            return $fromConfig;
        }

        return $fromIr;
    }

    /** @param list<string> $names */
    private function runtimeFromFiles(array $names): ?string
    {
        if ($this->has($names, 'Program.cs') || $this->has($names, 'App.csproj')) {
            return 'csharp';
        }
        if ($this->has($names, 'app.py')) {
            return 'python';
        }
        if ($this->has($names, 'index.php')) {
            return 'php';
        }

        return null;
    }

    /** @param list<string> $names */
    private function storageFromFiles(array $names): ?string
    {
        if ($this->has($names, 'data/schema.sql')) {
            return 'sql';
        }
        if ($this->has($names, 'data/app.xml')) {
            return 'xml';
        }

        return null;
    }

    /** @param list<string> $names */
    private function layoutFromFiles(array $names, string $indexHtml): ?string
    {
        foreach ($names as $name) {
            if (str_starts_with($name, 'views/') && str_ends_with($name, '.html')) {
                return 'mvc';
            }
        }
        if (preg_match('/data-layout="(mvc|canvas|responsive|wet)"/', $indexHtml, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** @param list<string> $names */
    private function has(array $names, string $rel): bool
    {
        return in_array($rel, $names, true);
    }
}
