<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;
use PowerSweeper\WebApp\WebAppHtmlPreview;
use PowerSweeper\WebApp\WebAppIrBuilder;

/**
 * Export a structural web IR (+ HTML preview scaffold) from the canvas package.
 *
 * Heuristic conversion surface — not a full Power Fx → JS compiler.
 */
final class ExportWebAppHop implements HopInterface
{
    public const IR_REL = 'WebApp/power_sweeper_ir.json';
    public const HTML_REL = 'WebApp/index.html';

    public static function id(): string
    {
        return 'export_web_ir';
    }

    public static function label(): string
    {
        return 'Export web IR';
    }

    public static function description(): string
    {
        return 'Build a structural web intermediate representation (screens, labels, navigation, document layout) and a static HTML preview scaffold inside WebApp/. Formulas stay authoritative in the .msapp.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = is_string($options['_extract_dir'] ?? null) ? (string) $options['_extract_dir'] : '';
        if ($extractDir === '' || !is_dir($extractDir)) {
            $report->add(self::id(), '(package)', 'export', '(missing extract dir)', 'skipped');
            return;
        }

        $builder = new WebAppIrBuilder();
        $ir = $builder->build($documents, $extractDir);

        // Browser-oriented document defaults when exporting toward web
        if (!isset($ir['document']) || !is_array($ir['document'])) {
            $ir['document'] = [];
        }
        $forceWebDoc = !array_key_exists('configure_document', $options) || (bool) $options['configure_document'];
        if ($forceWebDoc) {
            $ir['document']['app_type'] = 'DesktopOrTablet';
            $layout = is_array($ir['document']['layout'] ?? null) ? $ir['document']['layout'] : [];
            $layout['scale_to_fit'] = false;
            $layout['maintain_aspect_ratio'] = false;
            $ir['document']['layout'] = $layout;
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

        $html = (new WebAppHtmlPreview())->render($ir);
        $htmlPath = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::HTML_REL);
        file_put_contents($htmlPath, $html);

        // Also align Properties.json toward browser layout when requested
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
                'screens=%d controls=%d nav=%d',
                (int) ($stats['screens'] ?? 0),
                (int) ($stats['controls'] ?? 0),
                (int) ($stats['navigation_edges'] ?? 0),
            ),
        );
        $report->add(self::id(), self::HTML_REL, 'preview', '(none)', 'static HTML scaffold written');
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
