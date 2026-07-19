<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;

final class StripDefaultFillHop implements HopInterface
{
    public static function id(): string
    {
        return 'strip_default_fill';
    }

    public static function label(): string
    {
        return 'Strip default fills';
    }

    public static function description(): string
    {
        return 'Clear opaque default container fills (white/near-white) that fight transparent overlays.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isContainer()) {
                    continue;
                }
                $fill = $control->getProperty('Fill');
                if ($fill === null) {
                    continue;
                }
                if (!$this->isDefaultOpaque($fill)) {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', $fill, $to);
            }
        }
    }

    private function isDefaultOpaque(string $fill): bool
    {
        $v = strtolower(trim($fill));
        $v = ltrim($v, '=');
        if (str_contains($v, 'rgba(0, 0, 0, 0)') || str_contains($v, 'rgba(0,0,0,0)') || str_contains($v, 'color.transparent')) {
            return false;
        }
        // Common Studio defaults
        if (preg_match('/rgba?\(\s*255\s*,\s*255\s*,\s*255\b/', $v)) {
            return true;
        }
        if (str_contains($v, 'color.white') || $v === 'white') {
            return true;
        }
        return false;
    }
}
