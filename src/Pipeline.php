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
     * @return array{report: array<string,mixed>, output_path: string}
     */
    public function run(string $inputMsapp, array $hops, string $outputMsapp, ?callable $onProgress = null): array
    {
        $emit = static function (array $event) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($event);
            }
        };

        $archive = new MsappArchive($inputMsapp);
        $report = new Report(static function (array $entry, int $count) use ($emit): void {
            $emit([
                'type' => 'change',
                'hop' => $entry['hop'],
                'control' => $entry['control'],
                'property' => $entry['property'],
                'from' => $entry['from'],
                'to' => $entry['to'],
                'count' => $count,
            ]);
        });

        try {
            $emit([
                'type' => 'phase',
                'phase' => 'unpack',
                'message' => 'Unpacking .msapp…',
            ]);
            $archive->unpack();

            $hopTotal = count($hops);
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
                    'message' => sprintf('Hop %d of %d: %s', $index + 1, $hopTotal, $hop::label()),
                    'count' => $report->count(),
                ]);
                $hop->apply($archive->documents(), $report, $options);
            }

            $emit([
                'type' => 'phase',
                'phase' => 'pack',
                'message' => 'Packing cleaned .msapp…',
                'count' => $report->count(),
            ]);
            $archive->pack($outputMsapp);
        } finally {
            $archive->cleanup();
        }

        return [
            'report' => $report->toArray(),
            'output_path' => $outputMsapp,
        ];
    }
}
