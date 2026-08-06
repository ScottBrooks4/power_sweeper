<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;
use PowerSweeper\WebApp\WebAppIrApplier;

/**
 * Import structural web IR back into the canvas package (heuristic apply).
 */
final class ImportWebAppHop implements HopInterface
{
    public static function id(): string
    {
        return 'import_web_ir';
    }

    public static function label(): string
    {
        return 'Import web IR';
    }

    public static function description(): string
    {
        return 'Apply WebApp/power_sweeper_ir.json heuristics onto the .msapp: document layout, labels, literal layout/state, control renames via previous_name, Navigate renames. Does not invent missing controls or rewrite arbitrary Power Fx.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = is_string($options['_extract_dir'] ?? null) ? (string) $options['_extract_dir'] : '';
        $irPath = is_string($options['ir_path'] ?? null) && $options['ir_path'] !== ''
            ? (string) $options['ir_path']
            : ($extractDir !== '' ? $extractDir . DIRECTORY_SEPARATOR . 'WebApp' . DIRECTORY_SEPARATOR . 'power_sweeper_ir.json' : '');

        if ($irPath === '' || !is_file($irPath)) {
            $report->add(self::id(), 'WebApp/power_sweeper_ir.json', 'import', '(missing IR)', 'skipped — run export_web_ir first or pass ir_path');
            return;
        }

        $raw = file_get_contents($irPath);
        if ($raw === false) {
            $report->add(self::id(), $irPath, 'import', '(unreadable)', 'skipped');
            return;
        }
        $ir = json_decode($raw, true);
        if (!is_array($ir)) {
            $report->add(self::id(), $irPath, 'import', '(invalid JSON)', 'skipped');
            return;
        }

        $result = (new WebAppIrApplier())->apply($documents, $ir, $report, $extractDir !== '' ? $extractDir : null);
        $notes = $result['notes'] !== [] ? implode('; ', $result['notes']) : 'no structural deltas';
        $report->add(
            self::id(),
            'WebApp/power_sweeper_ir.json',
            'import',
            (string) count($ir['screens'] ?? []),
            $result['changes'] . ' change(s); ' . $notes,
        );
    }
}
