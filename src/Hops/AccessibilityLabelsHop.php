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
        return 'Fill missing AccessibleLabel on interactive controls from Text, Tooltip, or control name.';
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
                if (!$force && $existing !== null && !$this->isBlank($existing)) {
                    continue;
                }

                $label = $this->deriveLabel($control);
                if ($label === null || $label === '') {
                    continue;
                }

                $to = $control->format === 'yaml'
                    ? '="' . $this->escape($label) . '"'
                    : '"' . $this->escape($label) . '"';

                $before = $existing ?? '(unset)';
                $control->setProperty('AccessibleLabel', $to);
                $report->add(self::id(), $control->path, 'AccessibleLabel', $before, $label);
            }
        }
    }

    private function deriveLabel(ControlNode $control): ?string
    {
        foreach (['Text', 'Tooltip', 'ContentLanguage'] as $prop) {
            $val = $control->getProperty($prop);
            if ($val !== null && !$this->isBlank($val)) {
                $clean = $this->unwrap($val);
                if ($clean !== '') {
                    return $clean;
                }
            }
        }

        // Child label text
        foreach ($control->children as $child) {
            if (str_contains(strtolower($child->type), 'label')) {
                $text = $child->getProperty('Text');
                if ($text !== null && !$this->isBlank($text)) {
                    return $this->unwrap($text);
                }
            }
        }

        // Humanize control name: NewRequestButton -> New Request Button
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $control->name) ?? $control->name;
        $name = trim(preg_replace('/[_\-]+/', ' ', $name) ?? $name);
        return $name !== '' ? $name : null;
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
        }
        return trim($v);
    }

    private function escape(string $value): string
    {
        return str_replace('"', '""', $value);
    }
}
