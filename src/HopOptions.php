<?php

declare(strict_types=1);

namespace PowerSweeper;

/** Shared hop option helpers (e.g. options.force). */
final class HopOptions
{
    /** @param array<string, mixed> $options */
    public static function force(array $options): bool
    {
        return (bool) ($options['force'] ?? false);
    }
}
