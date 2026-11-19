<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Load and query config/web_control_map.php (YAML/JSON template → web tag).
 */
final class WebControlMap
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = dirname(__DIR__, 2) . '/config/web_control_map.php';
        if (!is_file($file)) {
            return self::$cache = [];
        }
        /** @var mixed $loaded */
        $loaded = include $file;

        return self::$cache = is_array($loaded) ? array_values($loaded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function match(string $type): array
    {
        $haystack = strtolower(trim($type));
        foreach (self::all() as $entry) {
            $patterns = $entry['match'] ?? [];
            if (!is_array($patterns)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    continue;
                }
                if (@preg_match($pattern, $haystack) === 1) {
                    return $entry;
                }
            }
        }

        return [
            'key' => 'control',
            'html' => 'div',
            'role' => 'control',
            'match' => [],
        ];
    }
}
