<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/** Fill a11y gaps makers hit in the App checker in one pass. */
final class AccessibilityPolishHop implements HopInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subHops(): array
    {
        return [
            ['id' => 'accessibility_labels', 'options' => []],
            ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]],
            ['id' => 'ensure_tab_index', 'options' => ['value' => 0]],
            ['id' => 'tooltip_from_label', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subHopId): string
    {
        return match ($subHopId) {
            'accessibility_labels' => 'labels',
            'ensure_focus_visible' => 'focus',
            'ensure_tab_index' => 'tab index',
            'tooltip_from_label' => 'tooltips',
            'regenerate_sarif' => 'SARIF',
            default => $subHopId,
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
        return 'Fill missing AccessibleLabel with spoken purpose (captions, icon meaning, destinations), focus rings, TabIndex, and tooltips, then regenerate App checker SARIF.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeHopSupport::run(
            self::id(),
            'accessibility_polish',
            self::subHops(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
