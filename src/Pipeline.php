<?php

declare(strict_types=1);

namespace PowerSweeper;

final class Pipeline
{
    public function __construct(private readonly HopRegistry $registry = new HopRegistry())
    {
    }

    /**
     * @param list<array{id:string,options?:array<string,mixed>}> $hops
     * @param null|callable(array<string,mixed>):void $onProgress
     * @return array{report: array<string,mixed>, output_path: string, elapsed_ms: int}
     */
    public function run(string $inputMsapp, array $hops, string $outputMsapp, ?callable $onProgress = null): array
    {
        $startedAt = microtime(true);
        $emit = static function (array $event) use ($onProgress, $startedAt): void {
            if ($onProgress === null) {
                return;
            }
            if (!isset($event['elapsed_ms'])) {
                $event['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            }
            $onProgress($event);
        };

        $archive = new MsappArchive($inputMsapp);
        // Cap retained detail rows: THCEE dark-mode alone can exceed 10k changes.
        $report = new Report(static function (array $entry, int $count) use ($emit): void {
            $emit([
                'type' => 'change',
                'hop' => $entry['hop'],
                'control' => $entry['control'],
                'property' => $entry['property'],
                // Omit bulky from/to on the wire; UI only needs count + a short hint.
                'to' => $entry['to'],
                'count' => $count,
            ]);
        }, 500, 160);

        $hopTotal = count($hops);
        // unpack + each hop + pack
        $units = max(1, $hopTotal + 2);
        $completedUnits = 0;

        try {
            $emit([
                'type' => 'phase',
                'phase' => 'unpack',
                'message' => 'Unpacking .msapp…',
                'index' => 0,
                'total' => $hopTotal,
                'unit' => 1,
                'units' => $units,
                'progress' => 0.0,
            ]);
            $unpackStarted = microtime(true);
            $archive->unpack();
            $completedUnits = 1;
            $complexity = AppComplexity::measure($inputMsapp, $archive->documents(), $archive->extractDir());
            $emit([
                'type' => 'phase',
                'phase' => 'unpack_done',
                'message' => 'Unpacked .msapp',
                'duration_ms' => (int) round((microtime(true) - $unpackStarted) * 1000),
                'index' => 0,
                'total' => $hopTotal,
                'unit' => $completedUnits,
                'units' => $units,
                'progress' => $completedUnits / $units,
                'count' => $report->count(),
                'complexity' => $complexity,
            ]);

            foreach ($hops as $index => $step) {
                $id = $step['id'] ?? '';
                if ($id === '' || !$this->registry->has($id)) {
                    throw new \InvalidArgumentException('Unknown hop in pipeline: ' . $id);
                }
                $options = $step['options'] ?? [];
                if (!is_array($options)) {
                    $options = [];
                }
                // Let hops that need Connections/DataSources or pack options see the archive.
                $options['_extract_dir'] = $archive->extractDir();
                $options['_msapp_archive'] = $archive;
                $hop = $this->registry->make($id);
                $emit([
                    'type' => 'phase',
                    'phase' => 'hop',
                    'hop' => $id,
                    'label' => $hop::label(),
                    'index' => $index + 1,
                    'total' => $hopTotal,
                    'unit' => $completedUnits,
                    'units' => $units,
                    'progress' => $completedUnits / $units,
                    'message' => sprintf('Hop %d of %d: %s', $index + 1, $hopTotal, $hop::label()),
                    'count' => $report->count(),
                ]);
                $countBefore = $report->count();
                $hopStarted = microtime(true);
                $hop->apply($archive->documents(), $report, $options);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                $completedUnits++;
                $emit([
                    'type' => 'hop_done',
                    'hop' => $id,
                    'label' => $hop::label(),
                    'index' => $index + 1,
                    'total' => $hopTotal,
                    'duration_ms' => (int) round((microtime(true) - $hopStarted) * 1000),
                    'changes' => max(0, $report->count() - $countBefore),
                    'unit' => $completedUnits,
                    'units' => $units,
                    'progress' => $completedUnits / $units,
                    'count' => $report->count(),
                    'message' => sprintf('Finished hop %d of %d: %s', $index + 1, $hopTotal, $hop::label()),
                ]);
            }

            $emit([
                'type' => 'phase',
                'phase' => 'pack',
                'message' => 'Packing cleaned .msapp…',
                'index' => $hopTotal,
                'total' => $hopTotal,
                'unit' => $completedUnits,
                'units' => $units,
                'progress' => $completedUnits / $units,
                'count' => $report->count(),
            ]);
            $packStarted = microtime(true);
            $archive->pack($outputMsapp);
            $completedUnits = $units;
            $emit([
                'type' => 'phase',
                'phase' => 'pack_done',
                'message' => 'Packed cleaned .msapp',
                'duration_ms' => (int) round((microtime(true) - $packStarted) * 1000),
                'unit' => $completedUnits,
                'units' => $units,
                'progress' => 1.0,
                'count' => $report->count(),
            ]);
        } finally {
            $archive->cleanup();
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'report' => $report->toArray(),
            'output_path' => $outputMsapp,
            'elapsed_ms' => $elapsedMs,
        ];
    }
}
