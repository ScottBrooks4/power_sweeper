<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\Report;
use PowerSweeper\WebApp\WebAppHtmlPreview;
use PowerSweeper\WebApp\WebAppIrBuilder;
use PowerSweeper\WebApp\WebAppPackageBuilder;

/**
 * Export a full conversion file + self-contained web package from the canvas app.
 */
final class ExportWebAppSweep implements SweepInterface
{
    public const IR_REL = 'WebApp/power_sweeper_ir.json';
    public const HTML_REL = 'WebApp/index.html';
    public const PACKAGE_REL = 'WebApp/package.zip';

    public static function id(): string
    {
        return 'export_web_ir';
    }

    public static function label(): string
    {
        return 'Export web package';
    }

    public static function description(): string
    {
        return 'Build a full conversion file (every control property + formulas) and a self-contained web package. Language is PHP, Python, or C Sharp; data is XML or SQL; pages are MVC, canvas, responsive web, or WET (Web Experience Toolkit).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = is_string($options['_extract_dir'] ?? null) ? (string) $options['_extract_dir'] : '';
        if ($extractDir === '' || !is_dir($extractDir)) {
            $report->add(self::id(), '(package)', 'export', '(missing extract dir)', 'skipped');
            return;
        }

        $runtime = WebAppIrBuilder::normalizeRuntime((string) ($options['runtime'] ?? $options['server_language'] ?? 'php'));
        $storage = WebAppIrBuilder::normalizeStorage((string) ($options['storage'] ?? 'xml'));
        $layout = WebAppIrBuilder::normalizeLayout((string) ($options['layout'] ?? 'canvas'));

        $builder = new WebAppIrBuilder();
        $ir = $builder->build($documents, $extractDir, [
            'runtime' => $runtime,
            'storage' => $storage,
            'layout' => $layout,
        ]);

        if (!isset($ir['document']) || !is_array($ir['document'])) {
            $ir['document'] = [];
        }
        $forceWebDoc = !array_key_exists('configure_document', $options) || (bool) $options['configure_document'];
        if ($forceWebDoc) {
            $ir['document']['app_type'] = 'DesktopOrTablet';
            $docLayout = is_array($ir['document']['layout'] ?? null) ? $ir['document']['layout'] : [];
            $docLayout['scale_to_fit'] = false;
            $docLayout['maintain_aspect_ratio'] = false;
            $ir['document']['layout'] = $docLayout;
        }

        $webDir = $extractDir . DIRECTORY_SEPARATOR . 'WebApp';
        if (!is_dir($webDir) && !mkdir($webDir, 0775, true) && !is_dir($webDir)) {
            $report->add(self::id(), 'WebApp', 'mkdir', '(failed)', 'skipped');
            return;
        }

        $irPath = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::IR_REL);
        $json = json_encode($ir, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $report->add(self::id(), self::IR_REL, 'encode', '(failed)', 'skipped');
            return;
        }
        file_put_contents($irPath, $json . "\n");

        // Legacy scaffold (still useful when package build is skipped for modern layout).
        $html = (new WebAppHtmlPreview())->render($ir);
        $htmlPath = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::HTML_REL);
        file_put_contents($htmlPath, $html);

        if ($forceWebDoc) {
            $this->configurePropertiesJson($extractDir, $report);
        }

        $stats = is_array($ir['stats'] ?? null) ? $ir['stats'] : [];
        $report->add(
            self::id(),
            self::IR_REL,
            'export',
            '(none)',
            sprintf(
                'v%d screens=%d controls=%d props=%d nav=%d runtime=%s storage=%s layout=%s',
                (int) ($ir['version'] ?? 0),
                (int) ($stats['screens'] ?? 0),
                (int) ($stats['controls'] ?? 0),
                (int) ($stats['properties'] ?? 0),
                (int) ($stats['navigation_edges'] ?? 0),
                $runtime,
                $storage,
                $layout,
            ),
        );

        $pkgPath = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::PACKAGE_REL);
        $pkg = (new WebAppPackageBuilder())->build($ir, $pkgPath, $extractDir);
        $report->add(
            self::id(),
            self::PACKAGE_REL,
            'package',
            $pkg['ok'] ? 'ok' : 'failed',
            $pkg['message'] . (isset($pkg['inline_styles']) ? '; inline_styles=' . $pkg['inline_styles'] : ''),
        );
        $report->add(self::id(), self::HTML_REL, 'preview', '(none)', 'scaffold written');
    }

    private function configurePropertiesJson(string $extractDir, Report $report): void
    {
        $path = $extractDir . DIRECTORY_SEPARATOR . 'Properties.json';
        if (!is_file($path)) {
            return;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return;
        }
        $json = json_decode($raw, false);
        if (!is_object($json)) {
            return;
        }
        $pairs = [
            'DocumentAppType' => 'DesktopOrTablet',
            'DocumentLayoutScaleToFit' => false,
            'DocumentLayoutMaintainAspectRatio' => false,
        ];
        $changed = false;
        foreach ($pairs as $key => $value) {
            $before = $json->{$key} ?? null;
            if ($before !== $value) {
                $json->{$key} = $value;
                $report->add(
                    self::id(),
                    'Properties.json',
                    $key,
                    $before === null ? '(unset)' : (is_bool($before) ? ($before ? 'true' : 'false') : (string) $before),
                    is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                );
                $changed = true;
            }
        }
        if ($changed) {
            $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                file_put_contents($path, str_replace("\n", "\r\n", $encoded));
            }
        }
    }
}
