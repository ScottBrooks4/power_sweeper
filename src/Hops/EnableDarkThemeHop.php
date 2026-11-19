<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/** Classic control prep + dark-mode theming in one hop. */
final class EnableDarkThemeHop implements HopInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subHops(): array
    {
        return [
            ['id' => 'prefer_classic_theme_controls', 'options' => []],
            ['id' => 'enable_dark_mode', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subHopId): string
    {
        return match ($subHopId) {
            'prefer_classic_theme_controls' => 'classic controls',
            'enable_dark_mode' => 'dark mode',
            'regenerate_sarif' => 'SARIF',
            default => $subHopId,
        };
    }

    public static function id(): string
    {
        return 'enable_dark_theme';
    }

    public static function label(): string
    {
        return 'Enable dark theme';
    }

    public static function description(): string
    {
        return 'Swap modern controls that block theming for classic ones, enable dark mode with gblTheme palettes, then regenerate App checker SARIF.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeHopSupport::run(
            self::id(),
            'enable_dark_theme',
            self::subHops(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
