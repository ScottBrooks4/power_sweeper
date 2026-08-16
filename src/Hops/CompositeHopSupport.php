<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\HopRegistry;
use PowerSweeper\Report;

/**
 * Shared runner for one-click composite hops (names/refs, a11y, theme, …).
 */
final class CompositeHopSupport
{
    /**
     * @param list<array{id:string,options?:array<string,mixed>}> $subHops
     * @param callable(string):string $kindLabel
     * @param list<\PowerSweeper\ControlDocument> $documents
     * @param array<string, mixed> $options
     */
    public static function run(
        string $parentId,
        string $summaryProperty,
        array $subHops,
        callable $kindLabel,
        array $documents,
        Report $report,
        array $options = [],
    ): void {
        $before = $report->count();
        $registry = new HopRegistry();
        $ran = 0;
        $byKind = [];

        $report->pushHopAlias($parentId);
        try {
            foreach ($subHops as $step) {
                $id = (string) ($step['id'] ?? '');
                if ($id === '' || !$registry->has($id) || $id === $parentId) {
                    continue;
                }
                // SARIF rewrite needs the unpacked archive; skip in unit/fixture runs.
                if ($id === 'regenerate_sarif') {
                    $extractDir = $options['_extract_dir'] ?? null;
                    if (!is_string($extractDir) || $extractDir === '') {
                        continue;
                    }
                }
                $kind = $kindLabel($id);
                $countBefore = $report->count();
                $report->pushPropertyPrefix($kind);
                try {
                    $hop = $registry->make($id);
                    $childOptions = array_merge($options, is_array($step['options'] ?? null) ? $step['options'] : []);
                    $childOptions['report_stats'] = false;
                    $hop->apply($documents, $report, $childOptions);
                } finally {
                    $report->popPropertyPrefix();
                }
                $delta = $report->count() - $countBefore;
                if ($delta > 0) {
                    $byKind[$kind] = ($byKind[$kind] ?? 0) + $delta;
                }
                $ran++;
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } finally {
            $report->popHopAlias();
        }

        $delta = $report->count() - $before;
        if ($delta === 0) {
            $report->add(
                $parentId,
                '(summary)',
                $summaryProperty,
                (string) $ran . ' passes',
                'no changes reported',
            );
            return;
        }

        $parts = [];
        foreach ($byKind as $kind => $n) {
            $parts[] = $kind . ': ' . $n;
        }
        $report->add(
            $parentId,
            '(summary)',
            $summaryProperty,
            (string) $delta . ' change(s)',
            $parts === []
                ? sprintf('%d change(s) across %d passes', $delta, $ran)
                : implode(' · ', $parts),
        );
    }
}
