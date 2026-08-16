<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\HopRegistry;
use PowerSweeper\Report;

/**
 * One-click formula repair: runs the Studio formula-error hops in the proven order.
 *
 * Covers locale separators, control/screen refs, SharePoint/ghost fields, syntax,
 * checked booleans, delegation/maintainability, and a live-checker converge loop.
 */
final class FixFormulaErrorsHop implements HopInterface
{
    /**
     * Formula-only subset of config/hop_chains/studio_repair.php (no a11y / SARIF).
     *
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function subHops(): array
    {
        return [
            ['id' => 'unwhack_locale_formulas', 'options' => []],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'repair_control_refs', 'options' => []],
            ['id' => 'repair_context_aware_refs', 'options' => []],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'repair_var_current_package', 'options' => []],
            ['id' => 'repair_sharepoint_fields', 'options' => []],
            ['id' => 'repair_ghost_patch_fields', 'options' => []],
            ['id' => 'repair_studio_syntax', 'options' => []],
            ['id' => 'repair_checked_booleans', 'options' => []],
            ['id' => 'repair_maintainability', 'options' => []],
            ['id' => 'repair_delegation', 'options' => []],
            ['id' => 'repair_converge_formulas', 'options' => []],
            // Converge can re-touch Navigate/StartScreen; normalize any over-quotes.
            ['id' => 'repair_double_qualified_refs', 'options' => []],
        ];
    }

    public static function id(): string
    {
        return 'fix_formula_errors';
    }

    public static function label(): string
    {
        return 'Fix formula errors';
    }

    public static function description(): string
    {
        return 'Fix formula errors of every supported kind: locale separators, broken control/screen refs, SharePoint and ghost fields, Studio syntax, checked booleans, delegation/maintainability, then re-check until errors stop falling.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $before = $report->count();
        $registry = new HopRegistry();
        $ran = 0;
        foreach (self::subHops() as $step) {
            $id = (string) $step['id'];
            if (!$registry->has($id) || $id === self::id()) {
                continue;
            }
            $hop = $registry->make($id);
            $childOptions = array_merge($options, $step['options']);
            $hop->apply($documents, $report, $childOptions);
            $ran++;
            // Sub-hops allocate large catalogs/checkers; free between passes on big apps.
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $delta = $report->count() - $before;
        $report->add(
            self::id(),
            '(summary)',
            'fix_formula_errors',
            (string) $ran . ' passes',
            $delta === 0
                ? 'no formula changes reported'
                : sprintf('%d change(s) across formula repair passes', $delta),
        );
    }
}
