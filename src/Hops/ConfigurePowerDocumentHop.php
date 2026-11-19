<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/**
 * Heuristic document-mode switch for classic Power Apps phone/tablet packaging
 * vs browser-oriented desktop layout (companion to export_web_ir).
 */
final class ConfigurePowerDocumentHop implements HopInterface
{
    public static function id(): string
    {
        return 'configure_power_document';
    }

    public static function label(): string
    {
        return 'Configure Power document';
    }

    public static function description(): string
    {
        return 'Set Properties.json document layout toward classic Power Apps (ScaleToFit + aspect ratio) or browser/web (DesktopOrTablet, ScaleToFit off). Mode via options.mode = power|web.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = is_string($options['_extract_dir'] ?? null) ? (string) $options['_extract_dir'] : '';
        if ($extractDir === '') {
            return;
        }
        $mode = strtolower((string) ($options['mode'] ?? 'power'));
        $path = $extractDir . DIRECTORY_SEPARATOR . 'Properties.json';
        if (!is_file($path)) {
            $report->add(self::id(), 'Properties.json', 'configure', '(missing)', 'skipped');
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

        $pairs = $mode === 'web'
            ? [
                'DocumentAppType' => 'DesktopOrTablet',
                'DocumentLayoutScaleToFit' => false,
                'DocumentLayoutMaintainAspectRatio' => false,
            ]
            : [
                'DocumentAppType' => 'DesktopOrTablet',
                'DocumentLayoutScaleToFit' => true,
                'DocumentLayoutMaintainAspectRatio' => true,
            ];

        $changed = false;
        foreach ($pairs as $key => $value) {
            $before = $json->{$key} ?? null;
            if ($before === $value) {
                continue;
            }
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

        if ($changed) {
            $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                file_put_contents($path, str_replace("\n", "\r\n", $encoded));
            }
        }
    }
}
