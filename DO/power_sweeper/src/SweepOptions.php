<?php

declare(strict_types=1);

namespace PowerSweeper;

/** Shared sweep option helpers (e.g. options.force). */
final class SweepOptions
{
    /** @param array<string, mixed> $options */
    public static function force(array $options): bool
    {
        return (bool) ($options['force'] ?? false);
    }

    /**
     * Server language for web export (PHP first; Python is generated with the same contract).
     *
     * @param array<string, mixed> $options
     */
    public static function serverLanguage(array $options): string
    {
        $raw = strtolower((string) ($options['server_language'] ?? $options['serverLanguage'] ?? $options['runtime'] ?? 'php'));
        $compact = str_replace([' ', '_', '-'], '', $raw);
        if (in_array($compact, ['python', 'py'], true)) {
            return 'python';
        }
        if (in_array($compact, ['csharp', 'cs', 'c#'], true)) {
            return 'csharp';
        }

        return 'php';
    }
}
