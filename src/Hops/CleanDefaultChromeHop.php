<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/** Strip Studio default container/button chrome that fights custom layouts. */
final class CleanDefaultChromeHop implements HopInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subHops(): array
    {
        return [
            ['id' => 'normalize_containers', 'options' => []],
            ['id' => 'strip_default_fill', 'options' => []],
            ['id' => 'normalize_classic_button_chrome', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subHopId): string
    {
        return match ($subHopId) {
            'normalize_containers' => 'containers',
            'strip_default_fill' => 'fills',
            'normalize_classic_button_chrome' => 'button chrome',
            'regenerate_sarif' => 'SARIF',
            default => $subHopId,
        };
    }

    public static function id(): string
    {
        return 'clean_default_chrome';
    }

    public static function label(): string
    {
        return 'Clean default chrome';
    }

    public static function description(): string
    {
        return 'Remove default container shadow/border/padding, clear white default fills, and normalize transparent button chrome. Regenerates App checker SARIF.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        CompositeHopSupport::run(
            self::id(),
            'clean_default_chrome',
            self::subHops(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
