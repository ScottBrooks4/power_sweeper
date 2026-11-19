<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

use PowerSweeper\ControlNaming;
use PowerSweeper\PowerAppsYaml;
use PowerSweeper\ZipTool;

/**
 * Turn a conversion file back into canvas YAML and a .msapp.
 */
final class PowerAppMaterializer
{
    /**
     * @param array<string, mixed> $ir
     * @return list<string> paths written relative to $destDir
     */
    public function writeSources(array $ir, string $destDir): array
    {
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new \RuntimeException('cannot create ' . $destDir);
        }
        $written = [];
        $screens = is_array($ir['screens'] ?? null) ? $ir['screens'] : [];
        foreach ($screens as $screen) {
            if (!is_array($screen)) {
                continue;
            }
            $name = (string) ($screen['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rel = 'Src/' . ControlNaming::screenFileStem($name) . '.pa.yaml';
            $this->writeYaml($destDir . '/' . $rel, [$name => $this->definition($screen)]);
            $written[] = $rel;
        }

        if (isset($ir['app']) && is_array($ir['app'])) {
            $appName = (string) ($ir['app']['name'] ?? 'App');
            if ($appName === '') {
                $appName = 'App';
            }
            $rel = 'Src/' . ControlNaming::screenFileStem($appName) . '.pa.yaml';
            $this->writeYaml($destDir . '/' . $rel, [$appName => $this->definition($ir['app'])]);
            $written[] = $rel;
        }

        foreach (is_array($ir['components'] ?? null) ? $ir['components'] : [] as $component) {
            if (!is_array($component)) {
                continue;
            }
            $name = (string) ($component['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $rel = 'Src/Components/' . ControlNaming::screenFileStem($name) . '.pa.yaml';
            $this->writeYaml($destDir . '/' . $rel, [$name => $this->definition($component)]);
            $written[] = $rel;
        }

        $this->writeProperties($destDir, $ir);
        $this->writeHeader($destDir);
        $written[] = 'Properties.json';
        $written[] = 'Header.json';

        return $written;
    }

    public function pack(string $sourceDir, string $msappPath): void
    {
        $dir = dirname($msappPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        ZipTool::createFromDirectory($sourceDir, $msappPath, ZipTool::STYLE_WINDOWS);
    }

    /**
     * Drop the web package tree after the canvas sources have been written,
     * so a zip that started life as a web app packs as a canvas app.
     */
    public function stripWebTree(string $dir): void
    {
        foreach (['public', 'server', 'views', 'controllers', 'models', 'data', 'ir', 'import', 'azure', 'Controllers', 'Models'] as $rel) {
            $this->rmTree($dir . '/' . $rel);
        }
        foreach ([
            'index.php',
            'router.php',
            'app.py',
            'Program.cs',
            'Store.cs',
            'App.csproj',
            'requirements.txt',
            'README.md',
            'package_meta.json',
        ] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function definition(array $node): array
    {
        $type = (string) ($node['type'] ?? '');
        if ($type === '') {
            $type = $this->fallbackType((string) ($node['kind'] ?? ''), (string) ($node['role'] ?? ''));
        }
        $def = ['Control' => $type];
        if (isset($node['variant']) && is_string($node['variant']) && $node['variant'] !== '') {
            $def['Variant'] = $node['variant'];
        }
        if (isset($node['component']) && is_string($node['component']) && $node['component'] !== '') {
            $def['ComponentName'] = $node['component'];
        }
        $props = [];
        foreach (is_array($node['properties'] ?? null) ? $node['properties'] : [] as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value)) {
                continue;
            }
            $props[$key] = $value;
        }
        if ($props !== []) {
            $def['Properties'] = $props;
        }
        $children = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            $childName = (string) ($child['name'] ?? '');
            if ($childName === '') {
                continue;
            }
            $children[] = [$childName => $this->definition($child)];
        }
        if ($children !== []) {
            $def['Children'] = $children;
        }

        return $def;
    }

    private function fallbackType(string $kind, string $role): string
    {
        if ($kind === 'screen' || $role === 'screen') {
            return 'Screen@2.0.0';
        }
        if ($kind === 'app' || $role === 'app') {
            return 'App@2.0.0';
        }

        return match ($role) {
            'button' => 'Classic/Button@2.0.0',
            'input' => 'Classic/TextInput@2.0.0',
            'gallery' => 'Gallery@2.0.0',
            'label' => 'Label@2.0.0',
            default => 'Label@2.0.0',
        };
    }

    /** @param array<string, mixed> $tree */
    private function writeYaml(string $absolute, array $tree): void
    {
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($absolute, PowerAppsYaml::dump($tree));
    }

    /** @param array<string, mixed> $ir */
    private function writeProperties(string $destDir, array $ir): void
    {
        $doc = is_array($ir['document'] ?? null) ? $ir['document'] : [];
        $layout = is_array($doc['layout'] ?? null) ? $doc['layout'] : [];
        $props = [
            'Name' => (string) (($doc['name'] ?? null) ?: 'App'),
            'DocumentAppType' => (string) (($doc['app_type'] ?? null) ?: 'DesktopOrTablet'),
            'DocumentLayoutWidth' => (int) (($layout['width'] ?? null) ?: 1366),
            'DocumentLayoutHeight' => (int) (($layout['height'] ?? null) ?: 768),
            'DocumentLayoutScaleToFit' => (bool) ($layout['scale_to_fit'] ?? true),
            'DocumentLayoutMaintainAspectRatio' => (bool) ($layout['maintain_aspect_ratio'] ?? true),
        ];
        $json = json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($destDir . '/Properties.json', is_string($json) ? $json . "\n" : "{}\n");
    }

    private function writeHeader(string $destDir): void
    {
        $header = [
            'DocVersion' => '1.349',
            'MinVersionToLoad' => '1.349',
            'MSAppStructureVersion' => '2.4.0',
            'LastSavedDateTimeUTC' => gmdate('m/d/Y H:i:s'),
            'AnalysisOptions' => [
                'DataflowAnalysisEnabled' => true,
                'DataflowAnalysisFlagStateToggledByUser' => false,
            ],
        ];
        $json = json_encode($header, JSON_UNESCAPED_SLASHES);
        file_put_contents($destDir . '/Header.json', is_string($json) ? $json : '{}');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
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
}
