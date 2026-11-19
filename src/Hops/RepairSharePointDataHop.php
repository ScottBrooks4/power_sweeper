<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/**
 * SharePoint list/column/package/ghost Patch cleanup.
 * Safe to run alongside Fix formula errors (overlapping SP passes may run twice).
 */
final class RepairSharePointDataHop implements HopInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subHops(): array
    {
        return [
            ['id' => 'correlate_sharepoint', 'options' => []],
            ['id' => 'repair_sharepoint_fields', 'options' => []],
            ['id' => 'repair_var_current_package', 'options' => []],
            ['id' => 'repair_ghost_patch_fields', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subHopId): string
    {
        return match ($subHopId) {
            'correlate_sharepoint' => 'correlate',
            'repair_sharepoint_fields' => 'columns',
            'repair_var_current_package' => 'package fields',
            'repair_ghost_patch_fields' => 'ghost Patch',
            'regenerate_sarif' => 'SARIF',
            default => $subHopId,
        };
    }

    public static function id(): string
    {
        return 'repair_sharepoint_data';
    }

    public static function label(): string
    {
        return 'Repair SharePoint data shape';
    }

    public static function description(): string
    {
        return 'Correlate SharePoint lists, repair column typos/fallbacks, fix varCurrentPackage shape, remove ghost Patch fields, then regenerate App checker SARIF.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeHopSupport::run(
            self::id(),
            'repair_sharepoint_data',
            self::subHops(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
