<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;
use PowerSweeper\StudioLiveChecker;

/**
 * Run the live App checker and write fresh AppCheckerResult.sarif into the
 * unpacked archive so Studio shows current errors without Save.
 */
final class RegenerateSarifHop implements HopInterface
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
        return 'Run the live Studio-equivalent App checker and write AppCheckerResult.sarif (updates error counts without Studio Save).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = $options['_extract_dir'] ?? null;
        if (!is_string($extractDir) || $extractDir === '') {
            throw new \RuntimeException('regenerate_sarif requires _extract_dir from pipeline');
        }

        // One live check + write (previously checked twice and doubled peak RAM).
        $result = StudioLiveChecker::writeSarifToExtractDir($documents, $extractDir, null, true);

        $summary = sprintf(
            '%d issues (%d formulas, %d a11y, %d perf, %d maint)',
            $result['total'],
            $result['by_category']['formulas'] ?? 0,
            $result['by_category']['accessibility'] ?? 0,
            $result['by_category']['performance'] ?? 0,
            $result['by_category']['maintainability'] ?? 0
        );

        $report->add(self::id(), 'AppCheckerResult.sarif', 'regenerated', '(stale)', $summary);

        foreach ($result['by_rule'] as $ruleId => $count) {
            if ($count < 1) {
                continue;
            }
            $report->add(self::id(), $ruleId, 'count', '', (string) $count);
        }
    }
}
