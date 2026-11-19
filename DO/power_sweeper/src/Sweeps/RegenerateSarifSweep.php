<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\Report;
use PowerSweeper\StudioLiveChecker;

/**
 * Refresh AppCheckerResult.sarif from the live checker without claiming full
 * Studio parity. Accessibility (and DelayItemLoading) are rewritten from live
 * results; other prior Studio findings are preserved so formula/perf counts
 * are not falsely zeroed.
 */
final class RegenerateSarifSweep implements SweepInterface
{
    public static function id(): string
    {
        return 'regenerate_sarif';
    }

    public static function label(): string
    {
        return 'Regenerate App checker SARIF';
    }

    public static function description(): string
    {
        return 'Update AppCheckerResult.sarif: live a11y/DelayItemLoading, preserve other prior Studio findings (avoids false zero formula counts).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = $options['_extract_dir'] ?? null;
        if (!is_string($extractDir) || $extractDir === '') {
            throw new \RuntimeException('regenerate_sarif requires _extract_dir from pipeline');
        }

        // One live check + merge-write (previously checked twice and doubled peak RAM).
        $result = StudioLiveChecker::writeSarifToExtractDir($documents, $extractDir, null, true);

        $summary = sprintf(
            '%d issues (%d formulas, %d a11y, %d perf, %d maint; live=%d preserved≈%d)',
            $result['total'],
            $result['by_category']['formulas'] ?? 0,
            $result['by_category']['accessibility'] ?? 0,
            $result['by_category']['performance'] ?? 0,
            $result['by_category']['maintainability'] ?? 0,
            (int) ($result['live_total'] ?? $result['total']),
            (int) ($result['preserved_total'] ?? 0)
        );

        $report->add(self::id(), 'AppCheckerResult.sarif', 'regenerated', '(merged)', $summary);

        foreach ($result['by_rule'] as $ruleId => $count) {
            if ($count < 1) {
                continue;
            }
            $report->add(self::id(), $ruleId, 'count', '', (string) $count);
        }
    }
}
