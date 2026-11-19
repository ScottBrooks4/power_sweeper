<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\Report;

/** Canvas → web IR export with naming cleanup and document layout for browser. */
final class ExportToWebIrSweep implements SweepInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subSweeps(): array
    {
        return [
            ['id' => 'meaningful_names', 'options' => ['only_generic' => true]],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'export_web_ir', 'options' => ['configure_document' => true]],
            ['id' => 'configure_power_document', 'options' => ['mode' => 'web']],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subSweepId): string
    {
        return match ($subSweepId) {
            'meaningful_names' => 'names',
            'repair_double_qualified_refs' => 'screen refs',
            'export_web_ir' => 'export IR',
            'configure_power_document' => 'document',
            'regenerate_sarif' => 'SARIF',
            default => $subSweepId,
        };
    }

    public static function id(): string
    {
        return 'export_to_web_ir';
    }

    public static function label(): string
    {
        return 'Export to web IR';
    }

    public static function description(): string
    {
        return 'Rename generics, normalize screen refs, export WebApp IR + HTML preview, set document layout for web, then regenerate App checker SARIF.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeSweepSupport::run(
            self::id(),
            'export_to_web_ir',
            self::subSweeps(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
