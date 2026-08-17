<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\Report;

/** Strip Studio default container/button chrome that fights custom layouts. */
final class CleanDefaultChromeSweep implements SweepInterface
{
    /** @return list<array{id:string,options:array<string,mixed>}> */
    public static function subSweeps(): array
    {
        return [
            ['id' => 'normalize_containers', 'options' => []],
            ['id' => 'strip_default_fill', 'options' => []],
            ['id' => 'normalize_classic_button_chrome', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subSweepId): string
    {
        return match ($subSweepId) {
            'normalize_containers' => 'containers',
            'strip_default_fill' => 'fills',
            'normalize_classic_button_chrome' => 'button chrome',
            'regenerate_sarif' => 'SARIF',
            default => $subSweepId,
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
        CompositeSweepSupport::run(
            self::id(),
            'clean_default_chrome',
            self::subSweeps(),
            self::kindLabel(...),
            $documents,
            $report,
            $options,
        );
    }
}
