<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\HopOptions;
use PowerSweeper\Report;

final class TooltipFromLabelHop implements HopInterface
{
    public static function id(): string
    {
        return 'tooltip_from_label';
    }

    public static function label(): string
    {
        return 'Tooltip from label';
    }

    public static function description(): string
    {
        return 'Copy Text or AccessibleLabel into empty Tooltip on buttons, icons, and images.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $force = HopOptions::force($options);

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $t = strtolower($control->type);
                if (!str_contains($t, 'button') && !str_contains($t, 'icon') && !str_contains($t, 'image')) {
                    continue;
                }

                $tooltip = $control->getProperty('Tooltip');
                if (
                    !$force
                    && $tooltip !== null
                    && !$this->isBlank($tooltip)
                    && !$this->isBrokenTooltip($tooltip, $control)
                ) {
                    continue;
                }

                $to = null;
                foreach (['AccessibleLabel', 'Text'] as $prop) {
                    $val = $control->getProperty($prop);
                    if ($val === null || $this->isBlank($val)) {
                        continue;
                    }
                    // Keep dynamic labels live (Self.Text / Self.AccessibleLabel / formulas).
                    if ($this->isDynamicExpression($val) || preg_match('/^Self\./i', $this->unwrap($val))) {
                        $expr = preg_match('/^Self\./i', $this->unwrap($val))
                            ? $this->unwrap($val)
                            : 'Self.' . $prop;
                        $to = $control->format === 'yaml' ? '=' . $expr : $expr;
                    } else {
                        $source = $this->unwrap($val);
                        $to = $control->format === 'yaml'
                            ? '="' . str_replace('"', '""', $source) . '"'
                            : '"' . str_replace('"', '""', $source) . '"';
                    }
                    break;
                }
                if ($to === null) {
                    $source = preg_replace('/([a-z])([A-Z])/', '$1 $2', $control->name) ?? $control->name;
                    $to = $control->format === 'yaml'
                        ? '="' . str_replace('"', '""', $source) . '"'
                        : '"' . str_replace('"', '""', $source) . '"';
                }

                $before = $tooltip ?? '(unset)';
                $control->setProperty('Tooltip', $to);
                $report->add(self::id(), $control->path, 'Tooltip', $before, $to);
            }
        }
    }

    private function isBrokenTooltip(string $existing, ControlNode $control): bool
    {
        $unwrapped = $this->unwrap($existing);
        if ($unwrapped === '' || preg_match('/^\s*If\s*\(/i', $unwrapped)) {
            return true;
        }
        if (preg_match('/^Self\.(Text|AccessibleLabel|Tooltip)\s*$/i', $unwrapped)) {
            return false;
        }
        foreach (['AccessibleLabel', 'Text'] as $prop) {
            $src = $control->getProperty($prop);
            if ($src === null || $this->isBlank($src)) {
                continue;
            }
            if ($this->isDynamicExpression($src) && $this->unwrap($src) === $unwrapped) {
                return true;
            }
        }

        return false;
    }

    private function isBlank(string $value): bool
    {
        $v = trim($this->unwrap($value));
        return $v === '' || strtolower($v) === 'blank()';
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
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"') && substr_count($v, '"') === 2)
            || (str_starts_with($v, "'") && str_ends_with($v, "'") && substr_count($v, "'") === 2)
        ) {
            return false;
        }

        return (bool) preg_match(
            '/\b(If|Switch|LookUp|Coalesce|Concatenate|With|Filter)\s*\(|\b(Self|Parent|ThisItem|var[A-Z]|com[A-Z]|gbl[A-Z])\b|[()&]|[A-Za-z_]\w*\./i',
            $v
        );
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
            $v = str_replace('""', '"', $v);
        }
        return trim($v);
    }
}
