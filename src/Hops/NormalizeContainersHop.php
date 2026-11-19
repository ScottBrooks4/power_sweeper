<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;

final class NormalizeContainersHop implements HopInterface
{
    public static function id(): string
    {
        return 'normalize_containers';
    }

    public static function label(): string
    {
        return 'Normalize containers';
    }

    public static function description(): string
    {
        return 'Remove default drop shadow, border, border radius, and padding from containers.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $force = (bool) ($options['force'] ?? true);

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isContainer()) {
                    continue;
                }

                $targets = [
                    'DropShadow' => '=DropShadow.None',
                    'BorderThickness' => '=0',
                    'BorderRadius' => '=0',
                    'RadiusTopLeft' => '=0',
                    'RadiusTopRight' => '=0',
                    'RadiusBottomLeft' => '=0',
                    'RadiusBottomRight' => '=0',
                    'PaddingTop' => '=0',
                    'PaddingRight' => '=0',
                    'PaddingBottom' => '=0',
                    'PaddingLeft' => '=0',
                ];

                foreach ($targets as $prop => $to) {
                    $from = $control->getProperty($prop);
                    if ($from === null && !$force) {
                        continue;
                    }
                    // Skip if already normalized
                    if ($from !== null && $this->alreadyClean($prop, $from)) {
                        continue;
                    }
                    $before = $from ?? '(unset)';
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $before, $to);
                }
            }
        }
    }

    private function alreadyClean(string $prop, string $value): bool
    {
        $v = strtolower(trim($value));
        $v = ltrim($v, '=');
        if ($prop === 'DropShadow') {
            return str_contains($v, 'none') || $v === '0' || $v === 'false';
        }
        return $v === '0' || $v === '0px';
    }
}
