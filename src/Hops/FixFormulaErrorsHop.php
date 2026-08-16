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
 *
 * Child repairs attribute under this hop id with a kind prefix on the property
 * column so the UI report reads like enable_dark_mode (control · from → to).
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

    public static function kindLabel(string $subHopId): string
    {
        return match ($subHopId) {
            'unwhack_locale_formulas' => 'locale',
            'repair_double_qualified_refs' => 'screen refs',
            'repair_control_refs' => 'control refs',
            'repair_context_aware_refs' => 'context refs',
            'repair_var_current_package' => 'package fields',
            'repair_sharepoint_fields' => 'SharePoint fields',
            'repair_ghost_patch_fields' => 'ghost Patch fields',
            'repair_studio_syntax' => 'syntax',
            'repair_checked_booleans' => 'checked booleans',
            'repair_maintainability' => 'maintainability',
            'repair_delegation' => 'delegation',
            'repair_converge_formulas' => 'converge',
            default => $subHopId,
        };
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
        $byKind = [];

        $report->pushHopAlias(self::id());
        try {
            foreach (self::subHops() as $step) {
                $id = (string) $step['id'];
                if (!$registry->has($id) || $id === self::id()) {
                    continue;
                }
                $kind = self::kindLabel($id);
                $countBefore = $report->count();
                $report->pushPropertyPrefix($kind);
                try {
                    $hop = $registry->make($id);
                    $childOptions = array_merge($options, $step['options']);
                    // Avoid nested "(summary)" noise from context-aware / converge when
                    // we already emit per-formula from→to rows under this parent.
                    $childOptions['report_stats'] = false;
                    $hop->apply($documents, $report, $childOptions);
                } finally {
                    $report->popPropertyPrefix();
                }
                $delta = $report->count() - $countBefore;
                if ($delta > 0) {
                    $byKind[$kind] = ($byKind[$kind] ?? 0) + $delta;
                }
                $ran++;
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } finally {
            $report->popHopAlias();
        }

        $delta = $report->count() - $before;
        if ($delta === 0) {
            $report->add(
                self::id(),
                '(summary)',
                'fix_formula_errors',
                (string) $ran . ' passes',
                'no formula changes reported',
            );
            return;
        }

        // Dark-mode-style rollup: totals stay accurate; detail rows already listed.
        $parts = [];
        foreach ($byKind as $kind => $n) {
            $parts[] = $kind . ': ' . $n;
        }
        $report->add(
            self::id(),
            '(summary)',
            'fix_formula_errors',
            (string) $delta . ' change(s)',
            $parts === []
                ? sprintf('%d change(s) across %d passes', $delta, $ran)
                : implode(' · ', $parts),
        );
    }
}
