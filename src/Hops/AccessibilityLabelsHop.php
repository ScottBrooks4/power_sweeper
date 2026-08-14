<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\HopOptions;
use PowerSweeper\Report;

final class AccessibilityLabelsHop implements HopInterface
{
    public static function id(): string
    {
        return 'accessibility_labels';
    }

    public static function label(): string
    {
        return 'Accessibility labels';
    }

    public static function description(): string
    {
        return 'Fill missing AccessibleLabel on interactive controls from Text, Tooltip, or control name. Dynamic Text/Tooltip formulas bind via Self.Text / Self.Tooltip instead of stringifying.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $force = HopOptions::force($options);

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isInteractive()) {
                    continue;
                }

                $existing = $control->getProperty('AccessibleLabel');
                if (
                    !$force
                    && $existing !== null
                    && !$this->isBlank($existing)
                    && !$this->isBrokenLabel($existing, $control)
                ) {
                    continue;
                }

                $to = $this->resolveAccessibleLabel($control);
                if ($to === null || $to === '') {
                    continue;
                }

                $before = $existing ?? '(unset)';
                $control->setProperty('AccessibleLabel', $to);
                $report->add(self::id(), $control->path, 'AccessibleLabel', $before, $to);
            }
        }
    }

    /**
     * Build the AccessibleLabel assignment (yaml includes leading = when needed).
     */
    private function resolveAccessibleLabel(ControlNode $control): ?string
    {
        $text = $control->getProperty('Text');
        if ($text !== null && !$this->isBlank($text)) {
            if ($this->isDynamicExpression($text)) {
                return $this->formulaRef($control, 'Self.Text');
            }
            return $this->quotedLiteral($control, $this->unwrap($text));
        }

        foreach (['Tooltip', 'HintText', 'ContentLanguage'] as $prop) {
            $val = $control->getProperty($prop);
            if ($val === null || $this->isBlank($val)) {
                continue;
            }
            if ($this->isDynamicExpression($val)) {
                return $this->formulaRef($control, 'Self.' . $prop);
            }
            return $this->quotedLiteral($control, $this->unwrap($val));
        }

        // Child label text
        foreach ($control->children as $child) {
            if (str_contains(strtolower($child->type), 'label')) {
                $childText = $child->getProperty('Text');
                if ($childText !== null && !$this->isBlank($childText)) {
                    if ($this->isDynamicExpression($childText)) {
                        // Can't reliably Self-ref a sibling; fall back to literal unwrap of simple strings only.
                        $clean = $this->unwrap($childText);
                        if ($clean !== '' && !$this->isDynamicExpression('=' . $clean)) {
                            return $this->quotedLiteral($control, $clean);
                        }
                        continue;
                    }
                    return $this->quotedLiteral($control, $this->unwrap($childText));
                }
            }
        }

        // Humanize control name: NewRequestButton -> New Request Button
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $control->name) ?? $control->name;
        $name = trim(preg_replace('/[_\-]+/', ' ', $name) ?? $name);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        return $name !== '' ? $this->quotedLiteral($control, $name) : null;
    }

    /**
     * Labels that were previously written as a stringified copy of Text/Tooltip (no live binding).
     */
    private function isBrokenLabel(string $existing, ControlNode $control): bool
    {
        $unwrapped = $this->unwrap($existing);
        if ($unwrapped === '') {
            return true;
        }
        // Classic failure mode: AccessibleLabel = "If(varLang,""Save"",""Enregistrer"")"
        if ($this->isDynamicExpression('=' . $unwrapped) || preg_match('/^\s*If\s*\(/i', $unwrapped)) {
            return true;
        }
        foreach (['Text', 'Tooltip', 'HintText'] as $prop) {
            $src = $control->getProperty($prop);
            if ($src === null || $this->isBlank($src)) {
                continue;
            }
            if ($this->isDynamicExpression($src) && $this->unwrap($src) === $unwrapped) {
                return true;
            }
        }
        // Self.Text / Self.Tooltip bindings are good.
        if (preg_match('/^Self\.(Text|Tooltip|HintText)\s*$/i', $unwrapped)) {
            return false;
        }

        // Stale humanized control-name labels lose to a real Text/Tooltip/HintText source.
        $humanized = preg_replace('/([a-z])([A-Z])/', '$1 $2', $control->name) ?? $control->name;
        $humanized = trim(preg_replace('/\s+/', ' ', trim(preg_replace('/[_\-]+/', ' ', $humanized) ?? $humanized)) ?? $humanized);
        if (strcasecmp($unwrapped, $humanized) === 0) {
            foreach (['Text', 'Tooltip', 'HintText'] as $prop) {
                $src = $control->getProperty($prop);
                if ($src !== null && !$this->isBlank($src)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isDynamicExpression(string $value): bool
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = trim(substr($v, 1));
        }
        if ($v === '') {
            return false;
        }
        // Simple quoted string is not dynamic.
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"') && substr_count($v, '"') === 2)
            || (str_starts_with($v, "'") && str_ends_with($v, "'") && substr_count($v, "'") === 2)
        ) {
            return false;
        }
        // Function calls / operators / known dynamic roots (avoid bare words like "User reviewed").
        return (bool) preg_match(
            '/\b(If|Switch|LookUp|Coalesce|Concatenate|With|Filter|LookUp)\s*\(|\b(Self|Parent|ThisItem|var[A-Z]|com[A-Z]|gbl[A-Z])\b|[()&]|[A-Za-z_]\w*\./i',
            $v
        );
    }

    private function formulaRef(ControlNode $control, string $expr): string
    {
        return $control->format === 'yaml' ? '=' . $expr : $expr;
    }

    private function quotedLiteral(ControlNode $control, string $label): string
    {
        $escaped = str_replace('"', '""', $label);

        return $control->format === 'yaml'
            ? '="' . $escaped . '"'
            : '"' . $escaped . '"';
    }

    private function isBlank(string $value): bool
    {
        $v = trim($this->unwrap($value));
        return $v === '' || strtolower($v) === 'blank()' || $v === '""' || $v === "''";
    }

    private function unwrap(string $value): string
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = substr($v, 1);
        }
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
            // Undo Power Fx doubling inside string literals.
            $v = str_replace('""', '"', $v);
        }
        return trim($v);
    }
}
