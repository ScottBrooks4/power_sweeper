<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\FormulaRepairConverger;
use PowerSweeper\Report;

/**
 * Live-checker converge loop: re-scan formula errors after each targeted repair
 * pass (refs, locale, booleans) until errors stop decreasing.
 */
final class RepairConvergeFormulasHop implements HopInterface
{
    public static function id(): string
    {
        return 'repair_converge_formulas';
    }

    public static function label(): string
    {
        return 'Converge formula repairs';
    }

    public static function description(): string
    {
        return 'Re-run the live App checker between repair passes (refs, locale, booleans) until formula errors stop decreasing — catches cascades and copy-paste leftovers.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = $options['_extract_dir'] ?? null;
        $options['extract_dir'] = is_string($extractDir) ? $extractDir : null;

        $converger = new FormulaRepairConverger();
        $stats = $converger->converge($documents, $options);

        if ($stats['repairs'] === 0 && $stats['rounds'] === 0) {
            return;
        }

        $report->add(
            self::id(),
            '(summary)',
            'converge',
            (string) $stats['before'],
            sprintf(
                '%d repairs in %d rounds; formula errors %d → %d',
                $stats['repairs'],
                $stats['rounds'],
                $stats['before'],
                $stats['after'],
            ),
        );
    }
}
