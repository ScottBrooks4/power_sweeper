<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\Report;

/** Web IR → canvas import, then a11y fill and classic Power document layout. */
final class ImportFromWebIrSweep implements SweepInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subSweeps(): array
    {
        return [
            ['id' => 'import_web_ir', 'options' => []],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'configure_power_document', 'options' => ['mode' => 'power']],
            ['id' => 'accessibility_labels', 'options' => []],
            ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
            ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
            ['id' => 'tooltip_from_label', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subSweepId): string
    {
        return match ($subSweepId) {
            'import_web_ir' => 'import IR',
            'repair_double_qualified_refs' => 'screen refs',
            'configure_power_document' => 'document',
            'accessibility_labels' => 'labels',
            'ensure_focus_visible' => 'focus',
            'ensure_tab_index' => 'tab index',
            'tooltip_from_label' => 'tooltips',
            'regenerate_sarif' => 'SARIF',
            default => $subSweepId,
        };
    }

    public static function id(): string
    {
        return 'import_from_web_ir';
    }

    public static function label(): string
    {
        return 'Import from web IR';
    }

    public static function description(): string
    {
        return 'Apply WebApp IR heuristics, normalize screen refs, restore Power document layout, fill accessibility gaps, then regenerate App checker SARIF.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeSweepSupport::run(
            self::id(),
            'import_from_web_ir',
            self::subSweeps(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
