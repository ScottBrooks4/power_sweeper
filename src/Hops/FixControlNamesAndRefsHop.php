<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/**
 * Rename generic controls, then repair references that break or were already stale.
 * Safe to run alongside Fix formula errors (double-run of ref passes is OK).
 */
final class FixControlNamesAndRefsHop implements HopInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subHops(): array
    {
        return [
            ['id' => 'meaningful_names', 'options' => ['only_generic' => true]],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            // Screen normalize already runs before/after; skip the redundant per-formula pass.
            ['id' => 'repair_control_refs', 'options' => ['normalize_screens' => false]],
            ['id' => 'repair_context_aware_refs', 'options' => []],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subHopId): string
    {
        return match ($subHopId) {
            'meaningful_names' => 'names',
            'repair_double_qualified_refs' => 'screen refs',
            'repair_control_refs' => 'control refs',
            'repair_context_aware_refs' => 'context refs',
            'regenerate_sarif' => 'SARIF',
            default => $subHopId,
        };
    }

    public static function id(): string
    {
        return 'fix_control_names_and_refs';
    }

    public static function label(): string
    {
        return 'Fix control names & references';
    }

    public static function description(): string
    {
        return 'Rename auto-generated controls, then repair double-qualified, stale, and copy-paste control references. Regenerates App checker SARIF. Safe to run with Fix formula errors (ref passes may run twice).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeHopSupport::run(
            self::id(),
            'fix_control_names_and_refs',
            self::subHops(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
