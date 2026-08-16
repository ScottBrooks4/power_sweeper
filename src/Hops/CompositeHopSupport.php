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
        $emit = is_callable($options['_on_progress'] ?? null) ? $options['_on_progress'] : null;
        $steps = [];
        foreach ($subHops as $step) {
            $id = (string) ($step['id'] ?? '');
            if ($id === '' || !$registry->has($id) || $id === $parentId) {
                continue;
            }
            if ($id === 'regenerate_sarif') {
                $extractDir = $options['_extract_dir'] ?? null;
                if (!is_string($extractDir) || $extractDir === '') {
                    continue;
                }
            }
            $steps[] = $step;
        }
        $stepTotal = count($steps);

        $report->pushHopAlias($parentId);
        try {
            foreach ($steps as $stepIndex => $step) {
                $id = (string) ($step['id'] ?? '');
                $kind = $kindLabel($id);
                $countBefore = $report->count();
                if ($emit !== null) {
                    $emit([
                        'type' => 'phase',
                        'phase' => 'subhop',
                        'hop' => $parentId,
                        'subhop' => $id,
                        'message' => sprintf(
                            '%s · %s (%d/%d)',
                            $parentId,
                            $kind,
                            $stepIndex + 1,
                            max(1, $stepTotal),
                        ),
                        'count' => $report->count(),
                    ]);
                }
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
                // Free hop-local graphs between passes — THCEE holds ~250MB of docs already.
                unset($hop);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                if ($emit !== null) {
                    $emit([
                        'type' => 'phase',
                        'phase' => 'subhop_done',
                        'hop' => $parentId,
                        'subhop' => $id,
                        'message' => sprintf(
                            'Finished %s · %s (%d changes)',
                            $parentId,
                            $kind,
                            $delta,
                        ),
                        'count' => $report->count(),
                    ]);
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
