<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Derive human-readable labels and Studio-safe control identifiers from control properties.
 * Aligns with accessibility label sources (Text, AccessibleLabel, Tooltip, child labels).
 */
final class ControlNaming
{
    /** Studio auto-generated names: Button1, Label7_183, Container54_2, Gallery1, … */
    private const GENERIC_NAME = '/^(?:'
        . 'Button|Label|Icon|Image|Container|TextInput|TextBox|Gallery|Dropdown|ComboBox|Timer|Radio|Toggle|Switch|Slider|DatePicker|HtmlText|HtmlViewer|GroupContainer|Rectangle|DataCard|Form|ListBox|RichTextEditor|ModernButton|ModernTextInput|ModernNumberInput|Checkbox|Link|Header|Footer|Separator|Import|Export|Attach|PDF|Barcode|Camera|Microphone|Video|Audio|Chart|Map|Timer|List|Table|Icon|Shape|Circle|IconBadge|InfoButton|ResetButton|CancelButton|SubmitButton'
        . ')\d+(?:_\d+)*$/i';

    public static function isBlank(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $v = trim(self::unwrap($value));
        if ($v === '') {
            return true;
        }
        $lower = strtolower($v);
        return $lower === 'blank()' || $v === '""' || $v === "''";
    }

    public static function unwrap(string $value): string
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = substr($v, 1);
        }
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }

        return trim($v);
    }

    public static function isGenericName(string $name): bool
    {
        if (preg_match(self::GENERIC_NAME, $name) === 1) {
            return true;
        }
        // Screen-copy suffixes: Foo_1, Bar_2_3
        if (preg_match('/_\d+(_\d+)*$/', $name) === 1 && preg_match('/[A-Za-z]+\d/', $name) === 1) {
            return true;
        }

        return false;
    }

    public static function isValidIdentifier(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 64
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * Display text for naming — mirrors accessibility label derivation order.
     */
    public static function deriveDisplayText(ControlNode $control): ?string
    {
        foreach (['Text', 'AccessibleLabel', 'Tooltip', 'ContentLanguage', 'HintText', 'HtmlText'] as $prop) {
            $val = $control->getProperty($prop);
            if ($val !== null && !self::isBlank($val)) {
                $clean = self::unwrap($val);
                if ($clean !== '' && !self::looksLikeFormula($clean)) {
                    return self::truncateDisplay($clean);
                }
            }
        }

        foreach ($control->children as $child) {
            if (str_contains(strtolower($child->type), 'label')) {
                $text = $child->getProperty('Text');
                if ($text !== null && !self::isBlank($text)) {
                    $clean = self::unwrap($text);
                    if ($clean !== '' && !self::looksLikeFormula($clean)) {
                        return self::truncateDisplay($clean);
                    }
                }
            }
        }

        if ($control->isContainer()) {
            foreach ($control->children as $child) {
                $nested = self::deriveDisplayText($child);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    public static function typeSuffix(ControlNode $control): string
    {
        $t = strtolower($control->type);
        $map = [
            'button' => 'Button',
            'label' => 'Label',
            'textinput' => 'Input',
            'numberinput' => 'Input',
            'combobox' => 'Combo',
            'dropdown' => 'Dropdown',
            'gallery' => 'Gallery',
            'datepicker' => 'DatePicker',
            'checkbox' => 'Checkbox',
            'toggle' => 'Toggle',
            'radio' => 'Radio',
            'slider' => 'Slider',
            'image' => 'Image',
            'icon' => 'Icon',
            'htmltext' => 'Html',
            'htmlviewer' => 'Html',
            'richtext' => 'RichText',
            'container' => 'Container',
            'groupcontainer' => 'Container',
            'form' => 'Form',
            'datacard' => 'Card',
        ];
        foreach ($map as $needle => $suffix) {
            if (str_contains($t, $needle)) {
                return $suffix;
            }
        }

        return 'Control';
    }

    /**
     * Convert display text + optional type suffix to a PascalCase control name.
     */
    public static function toIdentifier(string $displayText, string $suffix = ''): string
    {
        $displayText = trim($displayText);
        if ($displayText === '') {
            return '';
        }

        // Split on whitespace and common separators (# delimiter — / would close a /…/ pattern)
        $chunks = preg_split('#[\s\-_/]+#', $displayText) ?: [];
        $parts = [];
        foreach ($chunks as $chunk) {
            $chunk = preg_replace('/[^A-Za-z0-9]/', '', $chunk) ?? '';
            if ($chunk === '') {
                continue;
            }
            // Preserve existing PascalCase segments (EHCR, VCR)
            if (preg_match('/^[A-Z0-9]{2,}$/', $chunk) === 1) {
                $parts[] = $chunk;
                continue;
            }
            $parts[] = ucfirst(strtolower($chunk));
        }

        $id = implode('', $parts);
        if ($suffix !== '' && !str_ends_with($id, $suffix)) {
            $id .= $suffix;
        }

        if ($id === '' || !preg_match('/^[A-Za-z]/', $id)) {
            $id = 'Named' . ($suffix !== '' ? $suffix : 'Control');
        }

        if (strlen($id) > 64) {
            $id = substr($id, 0, 64);
        }

        return $id;
    }

    /**
     * Propose a meaningful name for a control, or null if no improvement.
     */
    public static function proposeName(ControlNode $control, bool $onlyGeneric = true): ?string
    {
        if ($control->isApp() || $control->isScreen()) {
            return null;
        }

        if ($onlyGeneric && !self::isGenericName($control->name)) {
            return null;
        }

        $display = self::deriveDisplayText($control);
        if ($display === null || strlen($display) < 2) {
            return null;
        }

        $suffix = self::typeSuffix($control);
        $candidate = self::toIdentifier($display, $suffix);
        if (!self::isValidIdentifier($candidate)) {
            return null;
        }
        if (strcasecmp($candidate, $control->name) === 0) {
            return null;
        }

        return $candidate;
    }

    private static function looksLikeFormula(string $text): bool
    {
        if (str_contains($text, 'Parent.') || str_contains($text, 'ThisItem.') || str_contains($text, 'Self.')) {
            return true;
        }
        if (preg_match('/\b(If|Switch|Concatenate|LookUp|Filter|Set|UpdateContext)\s*\(/i', $text) === 1) {
            return true;
        }

        return false;
    }

    private static function truncateDisplay(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (strlen($text) > 80) {
            $text = substr($text, 0, 77) . '...';
        }

        return trim($text);
    }
}
