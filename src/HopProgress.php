<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Relative work weights for composite sub-passes — used to advance the run
 * progress bar smoothly instead of treating a whole composite as one unit.
 *
 * Weights are rough runtime ratios (not absolute ms). Prefer accuracy of
 * ordering/magnitude over precision; the UI also learns from live durations.
 */
final class HopProgress
{
    /**
     * @param array<string, mixed> $options
     * @return array{0:float,1:float} [base, span] within overall [0,1]
     */
    public static function boundsFromOptions(array $options): array
    {
        $base = isset($options['_progress_base']) ? (float) $options['_progress_base'] : 0.0;
        $span = isset($options['_progress_span']) ? (float) $options['_progress_span'] : 0.0;
        if ($span <= 0.0) {
            return [0.0, 0.0];
        }

        return [
            max(0.0, min(1.0, $base)),
            max(0.0, min(1.0 - $base, $span)),
        ];
    }

    public static function relativeWeight(string $hopId): float
    {
        return match ($hopId) {
            'enable_dark_mode' => 28.0,
            'repair_context_aware_refs' => 16.0,
            'repair_converge_formulas' => 14.0,
            'repair_control_refs' => 12.0,
            'repair_delegation' => 9.0,
            'unwhack_locale_formulas' => 7.0,
            'regenerate_sarif' => 6.0,
            'repair_double_qualified_refs' => 4.0,
            'repair_sharepoint_fields', 'repair_ghost_patch_fields' => 5.0,
            'repair_studio_syntax', 'repair_maintainability' => 4.0,
            'repair_var_current_package', 'repair_checked_booleans' => 3.0,
            'meaningful_names' => 2.0,
            'accessibility_labels', 'ensure_focus_visible', 'ensure_tab_index', 'tooltip_from_label' => 3.0,
            'prefer_classic_theme_controls', 'normalize_containers', 'strip_default_fill',
            'normalize_classic_button_chrome' => 4.0,
            'correlate_sharepoint' => 5.0,
            'export_web_ir', 'import_web_ir', 'configure_power_document' => 4.0,
            'translate' => 10.0,
            default => 3.0,
        };
    }

    /**
     * @param list<string> $stepIds
     * @return list<float> cumulative weight fractions in [0,1], length = count+1 (starts at 0)
     */
    public static function cumulativeFractions(array $stepIds): array
    {
        $weights = [];
        $total = 0.0;
        foreach ($stepIds as $id) {
            $w = self::relativeWeight($id);
            $weights[] = $w;
            $total += $w;
        }
        if ($total <= 0.0) {
            $n = max(1, count($stepIds));

            return array_map(static fn(int $i): float => $i / $n, range(0, count($stepIds)));
        }
        $cum = [0.0];
        $sum = 0.0;
        foreach ($weights as $w) {
            $sum += $w;
            $cum[] = $sum / $total;
        }

        return $cum;
    }

    public static function map(float $base, float $span, float $fraction): float
    {
        if ($span <= 0.0) {
            return max(0.0, min(1.0, $base));
        }

        return max(0.0, min(1.0, $base + $span * max(0.0, min(1.0, $fraction))));
    }
}
