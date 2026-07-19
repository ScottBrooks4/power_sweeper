<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
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
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $t = strtolower($control->type);
                if (!str_contains($t, 'button') && !str_contains($t, 'icon') && !str_contains($t, 'image')) {
                    continue;
                }

                $tooltip = $control->getProperty('Tooltip');
                if ($tooltip !== null && !$this->isBlank($tooltip)) {
                    continue;
                }

                $source = null;
                foreach (['AccessibleLabel', 'Text'] as $prop) {
                    $val = $control->getProperty($prop);
                    if ($val !== null && !$this->isBlank($val)) {
                        $source = $this->unwrap($val);
                        break;
                    }
                }
                if ($source === null || $source === '') {
                    $source = preg_replace('/([a-z])([A-Z])/', '$1 $2', $control->name) ?? $control->name;
                }

                $to = $control->format === 'yaml'
                    ? '="' . str_replace('"', '""', $source) . '"'
                    : '"' . str_replace('"', '""', $source) . '"';

                $before = $tooltip ?? '(unset)';
                $control->setProperty('Tooltip', $to);
                $report->add(self::id(), $control->path, 'Tooltip', $before, $source);
            }
        }
    }

    private function isBlank(string $value): bool
    {
        $v = trim($this->unwrap($value));
        return $v === '' || strtolower($v) === 'blank()';
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
}
