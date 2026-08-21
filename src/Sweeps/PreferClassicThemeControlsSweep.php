<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;

/**
 * Swap modern / Fluent controls that lack Fill or Color onto classic templates
 * so enable_dark_mode can bind gblTheme backgrounds and text colors.
 */
final class PreferClassicThemeControlsSweep implements SweepInterface
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
                // Chrome-less modern controls (transparent buttons, icons) stay modern.
                // Converting them to classic invents an opaque Fill and a larger Size.
                if ($this->shouldLeaveModern($control, $key)) {
                    continue;
                }

                $fromType = $control->type;
                $this->remapProperties($control, $entry, $report);
                $this->preserveModernButtonChrome($control, $key);
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
        $wasTransparent = $this->isTransparentAppearance($control->getProperty('Appearance'));
        $blankText = $this->isBlankButtonText($control->getProperty('Text'));
        $key = (string) ($entry['key'] ?? '');

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
                    // Transparent Fluent CTAs often keep a studio-white Fill while the real
                    // chrome lives on BasePaletteColor — prefer the palette when Fill is default.
                    $preferPalette = $fromProp === 'BasePaletteColor'
                        && $toProp === 'Fill'
                        && ColorValue::isStudioDefault((string) $existing, 'Fill');
                    if (!$preferPalette) {
                        continue;
                    }
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

        // Sidebar / gallery hit-targets use Transparent modern buttons over icons.
        // Classic buttons default to an opaque Fill and cover the glyph (black squares).
        if (($key === 'modern_button' || str_contains(strtolower($control->type), 'button'))
            && $wasTransparent
            && $blankText
        ) {
            $transparent = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
            $fill = $control->getProperty('Fill');
            $fillStr = $fill !== null ? (string) $fill : '';
            if ($fillStr === '' || ColorValue::isStudioDefault($fillStr, 'Fill')) {
                $control->setProperty('Fill', $transparent);
                $report->add(self::id(), $control->path, 'Fill', $fillStr !== '' ? $fillStr : '(unset)', $transparent);
            }
        }
    }

    private function isTransparentAppearance(?string $appearance): bool
    {
        return is_string($appearance) && preg_match('/Transparent/i', $appearance) === 1;
    }

    private function isBlankButtonText(?string $text): bool
    {
        if ($text === null) {
            return true;
        }
        $trim = trim($text);
        if ($trim === '' || $trim === '=') {
            return true;
        }

        return preg_match('/^=\s*""\s*$/', $trim) === 1
            || preg_match("/^=\\s*''\\s*$/", $trim) === 1;
    }

    /**
     * Transparent / icon-only modern controls are layout chrome, not themed surfaces.
     * Leave them so dark mode can keep RGBA(0,0,0,0) instead of a classic Fill.
     */
    private function shouldLeaveModern(ControlNode $control, string $key): bool
    {
        if ($key === 'modern_icon') {
            return true;
        }
        // Classic CheckBox has Value, not Checked — converting breaks
        // If(Self.Checked, DisplayMode.View, …) and OnCheck formulas.
        if ($key === 'modern_checkbox') {
            return true;
        }
        // Classic ComboBox/Dropdown SelectedItems is a record table. Gallery
        // Items that use `x in Combo.SelectedItems` then fail:
        // "Invalid schema, expected a one-column table".
        if ($key === 'modern_combobox' || $key === 'modern_dropdown') {
            return true;
        }
        // Modern text is already transparent chrome (loading titles, step numerals).
        // Classic Label invents an opaque Fill → black bars on light screens.
        if ($key === 'modern_text') {
            $fill = $control->getProperty('Fill');
            if ($fill === null || trim($fill) === '' || trim($fill) === '=') {
                return true;
            }
            $parsed = ColorValue::parse($fill);
            if ($parsed !== null && ColorValue::isTransparent($parsed)) {
                return true;
            }
        }
        $isButton = $key === 'modern_button' || str_contains(strtolower($control->type), 'button');
        if (!$isButton) {
            return false;
        }
        if ($this->isTransparentAppearance($control->getProperty('Appearance'))) {
            return true;
        }
        $fill = $control->getProperty('Fill');
        if ($fill === null || trim($fill) === '') {
            return false;
        }
        $parsed = ColorValue::parse($fill);

        return $parsed !== null && ColorValue::isTransparent($parsed);
    }

    /**
     * Classic buttons default Size=14 and a 2px black border. Copy modern FontSize
     * and drop the invented border. Do not invent Radius — keep the modern control's
     * corners (pixel-perfect). Self.Height / 2 turns every CTA into a pill.
     */
    private function preserveModernButtonChrome(ControlNode $control, string $key): void
    {
        if ($key !== 'modern_button' && !str_contains(strtolower($control->type), 'button')) {
            return;
        }
        $eq = $control->format === 'yaml';
        $size = $control->getProperty('Size');
        if ($size === null || trim($size) === '' || trim($size) === '=') {
            $fontSize = $control->getProperty('FontSize');
            if ($fontSize !== null && trim($fontSize) !== '' && trim($fontSize) !== '=') {
                $control->setProperty('Size', $fontSize);
            } else {
                $control->setProperty('Size', $eq ? '=10.5' : '10.5');
            }
        }

        $thickness = $control->getProperty('BorderThickness');
        $thicknessEmpty = $thickness === null || trim($thickness) === '' || trim($thickness) === '=';
        if ($thicknessEmpty) {
            $control->setProperty('BorderThickness', $eq ? '=0' : '0');
        }

        // Fluent Primary with no Radius still paints a capsule. Classic with no
        // Radius inherits that look. Pin a 4px rounded-rect (not Height/2).
        $radii = ['RadiusTopLeft', 'RadiusTopRight', 'RadiusBottomLeft', 'RadiusBottomRight'];
        $hasRadius = false;
        foreach ($radii as $prop) {
            $val = $control->getProperty($prop);
            if ($val !== null && trim($val) !== '' && trim($val) !== '=') {
                $hasRadius = true;
                break;
            }
        }
        if (!$hasRadius) {
            $radius = $eq ? '=4' : '4';
            foreach ($radii as $prop) {
                $control->setProperty($prop, $radius);
            }
        }
    }
}
