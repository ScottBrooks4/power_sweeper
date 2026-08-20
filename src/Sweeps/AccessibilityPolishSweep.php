<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\Report;

/** Fill a11y gaps makers hit in the App checker in one pass. */
final class AccessibilityPolishSweep implements SweepInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subSweeps(): array
    {
        return [
            ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
            ['id' => 'accessibility_labels', 'options' => []],
            ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
            ['id' => 'tooltip_from_label', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subSweepId): string
    {
        return match ($subSweepId) {
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
        return 'accessibility_polish';
    }

    public static function label(): string
    {
        return 'Accessibility polish';
    }

    public static function description(): string
    {
        return 'Clear Missing tab stop (including TabIndex=-1), fill AccessibleLabel with spoken purpose, add focus rings and tooltips, then regenerate App checker SARIF.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeSweepSupport::run(
            self::id(),
            'accessibility_polish',
            self::subSweeps(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
