<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Named sweep sequences for CLI/scripts.
 * The web UI uses SweepAdvisor to detect which sweeps are needed and in what order.
 */
final class SweepChains
{
    /**
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function studioRepair(): array
    {
        return self::normalize(include POWER_SWEEPER_ROOT . '/config/sweep_chains/studio_repair.php');
    }

    /**
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function darkMode(bool $forceEnableDark = false): array
    {
        $sweeps = self::normalize(include POWER_SWEEPER_ROOT . '/config/sweep_chains/dark_mode.php');
        if (!$forceEnableDark) {
            return $sweeps;
        }

        return array_map(static function (array $sweep): array {
            $id = (string) ($sweep['id'] ?? '');
            if ($id === 'enable_dark_mode' || $id === 'enable_dark_theme') {
                $sweep['options']['force'] = true;
            }

            return $sweep;
        }, $sweeps);
    }

    /**
     * Full Studio repair + dark mode (powered deliverable).
     *
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function powered(): array
    {
        return array_merge(self::studioRepair(), self::darkMode(true));
    }

    /**
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function powerToWeb(): array
    {
        return self::normalize(include POWER_SWEEPER_ROOT . '/config/sweep_chains/power_to_web.php');
    }

    /**
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function webToPower(): array
    {
        return self::normalize(include POWER_SWEEPER_ROOT . '/config/sweep_chains/web_to_power.php');
    }

    /**
     * Meaningful names then studio repair (smart repair).
     *
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function smartRepair(): array
    {
        return array_merge(
            [['id' => 'fix_control_names_and_refs', 'options' => []]],
            self::studioRepair()
        );
    }

    /**
     * @param mixed $raw
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    private static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $sweep) {
            if (!is_array($sweep) || empty($sweep['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $sweep['id'],
                'options' => is_array($sweep['options'] ?? null) ? $sweep['options'] : [],
            ];
        }

        return $out;
    }
}
