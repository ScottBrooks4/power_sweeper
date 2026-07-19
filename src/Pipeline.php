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
     * @return array{report: array<string,mixed>, output_path: string}
     */
    public function run(string $inputMsapp, array $hops, string $outputMsapp): array
    {
        $archive = new MsappArchive($inputMsapp);
        $report = new Report();

        try {
            $archive->unpack();
            foreach ($hops as $step) {
                $id = $step['id'] ?? '';
                if ($id === '' || !$this->registry->has($id)) {
                    throw new \InvalidArgumentException('Unknown hop in pipeline: ' . $id);
                }
                $options = $step['options'] ?? [];
                if (!is_array($options)) {
                    $options = [];
                }
                $hop = $this->registry->make($id);
                $hop->apply($archive->documents(), $report, $options);
            }
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
