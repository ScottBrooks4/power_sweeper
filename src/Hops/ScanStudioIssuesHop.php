<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;
use PowerSweeper\StudioIssueScanner;

/**
 * Non-mutating scan: report remaining App-checker-class issues after repair hops.
 * Useful to verify a pack is clean; does not change formulas.
 *
 * Options:
 *   - max_report (int, default 200): cap entries added to the change report
 */
final class ScanStudioIssuesHop implements HopInterface
{
    public static function id(): string
    {
        return 'scan_studio_issues';
    }

    public static function label(): string
    {
        return 'Scan remaining Studio issues';
    }

    public static function description(): string
    {
        return 'Report remaining locale-separator, boolean-literal, and focus-ring issues (does not modify the app). Run after repair hops to verify cleanup.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $max = isset($options['max_report']) ? max(0, (int) $options['max_report']) : 200;
        $issues = StudioIssueScanner::scan($documents);
        $n = 0;
        foreach ($issues as $issue) {
            if ($n >= $max) {
                $report->add(
                    self::id(),
                    '(summary)',
                    'truncated',
                    (string) count($issues),
                    'showing first ' . $max . ' of ' . count($issues)
                );
                break;
            }
            $report->add(
                self::id(),
                $issue['control'],
                $issue['property'] . ' [' . $issue['kind'] . ']',
                $issue['snippet'],
                '(remaining — not auto-fixed)'
            );
            $n++;
        }
    }
}
