<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\HopProgress;
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
        [$progressBase, $progressSpan] = HopProgress::boundsFromOptions($options);
        $parentLabel = $registry->has($parentId)
            ? $registry->make($parentId)::label()
            : $parentId;

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
        $stepIds = array_map(static fn(array $s): string => (string) $s['id'], $steps);
        $cum = HopProgress::cumulativeFractions($stepIds);

        $report->pushHopAlias($parentId);
        try {
            foreach ($steps as $stepIndex => $step) {
                $id = (string) ($step['id'] ?? '');
                $kind = $kindLabel($id);
                $countBefore = $report->count();
                $fracStart = $cum[$stepIndex] ?? ($stepIndex / max(1, $stepTotal));
                $fracEnd = $cum[$stepIndex + 1] ?? (($stepIndex + 1) / max(1, $stepTotal));
                if ($emit !== null) {
                    $emit([
                        'type' => 'phase',
                        'phase' => 'subhop',
                        'hop' => $parentId,
                        'label' => $parentLabel,
                        'subhop' => $id,
                        'subhop_label' => $kind,
                        'index' => $stepIndex + 1,
                        'total' => max(1, $stepTotal),
                        'progress' => HopProgress::map($progressBase, $progressSpan, $fracStart),
                        'message' => sprintf(
                            '%s · %s (%d/%d)',
                            $parentLabel,
                            $kind,
                            $stepIndex + 1,
                            max(1, $stepTotal),
                        ),
                        'count' => $report->count(),
                    ]);
                }
                $report->pushPropertyPrefix($kind);
                $stepStarted = microtime(true);
                try {
                    $hop = $registry->make($id);
                    $childOptions = array_merge($options, is_array($step['options'] ?? null) ? $step['options'] : []);
                    $childOptions['report_stats'] = false;
                    $childOptions['_progress_base'] = HopProgress::map($progressBase, $progressSpan, $fracStart);
                    $childOptions['_progress_span'] = max(
                        0.0,
                        HopProgress::map($progressBase, $progressSpan, $fracEnd)
                            - HopProgress::map($progressBase, $progressSpan, $fracStart)
                    );
                    $hop->apply($documents, $report, $childOptions);
                } finally {
                    $report->popPropertyPrefix();
                }
                $durationMs = (int) round((microtime(true) - $stepStarted) * 1000);
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
                        'label' => $parentLabel,
                        'subhop' => $id,
                        'subhop_label' => $kind,
                        'index' => $stepIndex + 1,
                        'total' => max(1, $stepTotal),
                        'duration_ms' => $durationMs,
                        'changes' => $delta,
                        'progress' => HopProgress::map($progressBase, $progressSpan, $fracEnd),
                        'message' => sprintf(
                            'Finished %s · %s (%d changes)',
                            $parentLabel,
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
