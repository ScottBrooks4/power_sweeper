<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;

/**
 * Swap modern / Fluent controls that lack Fill or Color onto classic templates
 * so enable_dark_mode can bind gblTheme backgrounds and text colors.
 */
final class PreferClassicThemeControlsHop implements HopInterface
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $mapCache = null;

    public static function id(): string
    {
        return 'prefer_classic_theme_controls';
    }

    public static function label(): string
    {
        return 'Prefer classic controls for theming';
    }

    public static function description(): string
    {
        return 'Replace modern/Fluent controls that lack Fill or Color (ButtonCanvas, ModernButton, ModernText, ModernTextInput, …) with classic templates that support gblTheme background and text colors. Run before enable_dark_mode.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $map = $this->loadMap($options);
        if ($map === []) {
            return;
        }

        $enabledOptional = [];
        foreach ($map as $entry) {
            $key = (string) ($entry['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!empty($entry['optional'])) {
                $optName = 'include_' . $key;
                $enabledOptional[$key] = !empty($options[$optName]) || !empty($options['include_optional']);
            }
        }

        foreach ($documents as $doc) {
            $touched = false;
            foreach ($doc->controls() as $control) {
                $entry = $this->matchEntry($control->type, $map);
                if ($entry === null) {
                    continue;
                }
                $key = (string) $entry['key'];
                if (!empty($entry['optional']) && empty($enabledOptional[$key])) {
                    continue;
                }
                if ($this->alreadyTarget($control, $entry)) {
                    continue;
                }

                $fromType = $control->type;
                $this->remapProperties($control, $entry, $report);
                $yaml = (string) $entry['yaml'];
                /** @var array{Id?:string,Name?:string,Version?:string} $json */
                $json = is_array($entry['json'] ?? null) ? $entry['json'] : [];
                $control->setControlType($yaml, $json);
                $report->add(self::id(), $control->path, 'Control', $fromType, $yaml);
                $touched = true;
            }
            if ($touched) {
                $doc->reindex();
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function loadMap(array $options): array
    {
        if (isset($options['control_map']) && is_array($options['control_map'])) {
            return array_values($options['control_map']);
        }

        $file = isset($options['control_map_file']) && is_string($options['control_map_file'])
            ? $options['control_map_file']
            : dirname(__DIR__, 2) . '/config/theme_control_map.php';

        if (self::$mapCache !== null && !isset($options['control_map_file'])) {
            return self::$mapCache;
        }

        if (!is_file($file)) {
            return [];
        }

        /** @var mixed $loaded */
        $loaded = include $file;
        $map = is_array($loaded) ? array_values($loaded) : [];
        if (!isset($options['control_map_file'])) {
            self::$mapCache = $map;
        }
        return $map;
    }

    /**
     * @param list<array<string, mixed>> $map
     * @return array<string, mixed>|null
     */
    private function matchEntry(string $type, array $map): ?array
    {
        $haystack = strtolower(trim($type));
        if ($haystack === '' || str_starts_with($haystack, 'classic/')) {
            return null;
        }

        foreach ($map as $entry) {
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
        return null;
    }

    /** @param array<string, mixed> $entry */
    private function alreadyTarget(ControlNode $control, array $entry): bool
    {
        $yaml = strtolower((string) ($entry['yaml'] ?? ''));
        $type = strtolower($control->type);
        if ($yaml !== '' && $type === $yaml) {
            return true;
        }
        $jsonName = strtolower((string) (($entry['json']['Name'] ?? '') ?: ''));
        if ($jsonName !== '' && ($type === $jsonName || str_ends_with($type, '/' . $jsonName))) {
            return true;
        }
        // Label@x is the modern_text target — don't re-match TextCanvas aliases incorrectly.
        if ($jsonName === 'label' && preg_match('#^label(?:@|$)#i', $control->type) === 1) {
            return true;
        }
        if ($jsonName === 'button' && preg_match('#^(?:classic/)?button@2\\.#i', $control->type) === 1) {
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $entry */
    private function remapProperties(ControlNode $control, array $entry, Report $report): void
    {
        $map = $entry['property_map'] ?? [];
        if (is_array($map)) {
            foreach ($map as $fromProp => $toProp) {
                if (!is_string($fromProp) || !is_string($toProp) || $fromProp === '' || $toProp === '') {
                    continue;
                }
                $fromVal = $control->getProperty($fromProp);
                if ($fromVal === null || trim($fromVal) === '' || trim($fromVal) === '=') {
                    continue;
                }
                $existing = $control->getProperty($toProp);
                if ($existing !== null && trim($existing) !== '' && trim($existing) !== '=') {
                    continue;
                }
                $control->setProperty($toProp, $fromVal);
                $report->add(self::id(), $control->path, $toProp, $existing ?? '(unset)', $fromVal);
            }
        }

        $remove = $entry['remove'] ?? [];
        if (is_array($remove)) {
            foreach ($remove as $prop) {
                if (!is_string($prop) || $prop === '') {
                    continue;
                }
                $before = $control->getProperty($prop);
                if ($before === null) {
                    continue;
                }
                $control->removeProperty($prop);
                $report->add(self::id(), $control->path, $prop, $before, '(removed)');
            }
        }
    }
}
