<?php

declare(strict_types=1);

namespace PowerSweeper;

final class Pipeline
{
    public function __construct(private readonly SweepRegistry $registry = new SweepRegistry())
    {
    }

    /**
     * @param list<array{id:string,options?:array<string,mixed>}> $sweeps
     * @param null|callable(array<string,mixed>):void $onProgress
     * @return array{report: array<string,mixed>, output_path: string, elapsed_ms: int}
     */
    public function run(string $inputMsapp, array $sweeps, string $outputMsapp, ?callable $onProgress = null): array
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
                'sweep' => $entry['sweep'],
                'control' => $entry['control'],
                'property' => $entry['property'],
                // Omit bulky from/to on the wire; UI only needs count + a short hint.
                'to' => $entry['to'],
                'count' => $count,
            ]);
        }, 500, 280);

        $sweepTotal = count($sweeps);
        // unpack + each sweep + pack
        $units = max(1, $sweepTotal + 2);
        $completedUnits = 0;

        try {
            $emit([
                'type' => 'phase',
                'phase' => 'unpack',
                'message' => 'Unpacking .msapp…',
                'index' => 0,
                'total' => $sweepTotal,
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
                'total' => $sweepTotal,
                'unit' => $completedUnits,
                'units' => $units,
                'progress' => $completedUnits / $units,
                'count' => $report->count(),
                'complexity' => $complexity,
            ]);

            foreach ($sweeps as $index => $step) {
                $id = $step['id'] ?? '';
                if ($id === '' || !$this->registry->has($id)) {
                    throw new \InvalidArgumentException('Unknown sweep in pipeline: ' . $id);
                }
                $options = $step['options'] ?? [];
                if (!is_array($options)) {
                    $options = [];
                }
                $options['_extract_dir'] = $archive->extractDir();
                $options['_msapp_archive'] = $archive;
                $options['_on_progress'] = $emit;
                $options['_progress_base'] = $completedUnits / $units;
                $options['_progress_span'] = 1.0 / $units;
                $sweep = $this->registry->make($id);
                $emit([
                    'type' => 'phase',
                    'phase' => 'sweep',
                    'sweep' => $id,
                    'label' => $sweep::label(),
                    'index' => $index + 1,
                    'total' => $sweepTotal,
                    'unit' => $completedUnits,
                    'units' => $units,
                    'progress' => $completedUnits / $units,
                    'message' => sprintf('Sweep %d of %d: %s', $index + 1, $sweepTotal, $sweep::label()),
                    'count' => $report->count(),
                ]);
                $countBefore = $report->count();
                $sweepStarted = microtime(true);
                $sweep->apply($archive->documents(), $report, $options);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                $completedUnits++;
                $emit([
                    'type' => 'sweep_done',
                    'sweep' => $id,
                    'label' => $sweep::label(),
                    'index' => $index + 1,
                    'total' => $sweepTotal,
                    'duration_ms' => (int) round((microtime(true) - $sweepStarted) * 1000),
                    'changes' => max(0, $report->count() - $countBefore),
                    'unit' => $completedUnits,
                    'units' => $units,
                    'progress' => $completedUnits / $units,
                    'count' => $report->count(),
                    'message' => sprintf('Finished sweep %d of %d: %s', $index + 1, $sweepTotal, $sweep::label()),
                ]);
            }

            $emit([
                'type' => 'phase',
                'phase' => 'pack',
                'message' => 'Packing cleaned .msapp…',
                'index' => $sweepTotal,
                'total' => $sweepTotal,
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
