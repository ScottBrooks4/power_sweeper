<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Named hop sequences for CLI/scripts.
 * The web UI uses HopAdvisor to detect which hops are needed and in what order.
 */
final class HopChains
{
    /**
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function studioRepair(): array
    {
        return self::normalize(include POWER_SWEEPER_ROOT . '/config/hop_chains/studio_repair.php');
    }

    /**
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function darkMode(bool $forceEnableDark = false): array
    {
        $hops = self::normalize(include POWER_SWEEPER_ROOT . '/config/hop_chains/dark_mode.php');
        if (!$forceEnableDark) {
            return $hops;
        }

        return array_map(static function (array $hop): array {
            $id = (string) ($hop['id'] ?? '');
            if ($id === 'enable_dark_mode' || $id === 'enable_dark_theme') {
                $hop['options']['force'] = true;
            }

            return $hop;
        }, $hops);
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
        return self::normalize(include POWER_SWEEPER_ROOT . '/config/hop_chains/power_to_web.php');
    }

    /**
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function webToPower(): array
    {
        return self::normalize(include POWER_SWEEPER_ROOT . '/config/hop_chains/web_to_power.php');
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
        foreach ($raw as $hop) {
            if (!is_array($hop) || empty($hop['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $hop['id'],
                'options' => is_array($hop['options'] ?? null) ? $hop['options'] : [],
            ];
        }

        return $out;
    }
}
