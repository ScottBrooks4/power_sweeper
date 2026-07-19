<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;

final class NormalizeClassicButtonChromeHop implements HopInterface
{
    public static function id(): string
    {
        return 'normalize_classic_button_chrome';
    }

    public static function label(): string
    {
        return 'Normalize button chrome';
    }

    public static function description(): string
    {
        return 'When a button Fill is transparent, also clear Hover/Pressed fills and borders (stops white flash).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $transparent = ['RGBA(0, 0, 0, 0)', 'Color.Transparent', 'RGBA(0,0,0,0)'];

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isButtonLike()) {
                    continue;
                }

                $fill = $control->getProperty('Fill');
                if ($fill === null || !$this->isTransparent($fill)) {
                    continue;
                }

                $toFill = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
                $props = [
                    'HoverFill' => $toFill,
                    'PressedFill' => $toFill,
                    'DisabledFill' => $toFill,
                    'BorderThickness' => $control->format === 'yaml' ? '=0' : '0',
                    'HoverBorderColor' => $toFill,
                    'PressedBorderColor' => $toFill,
                    'BorderColor' => $toFill,
                    'Color' => $toFill,
                    'HoverColor' => $toFill,
                    'PressedColor' => $toFill,
                ];

                foreach ($props as $prop => $to) {
                    $from = $control->getProperty($prop);
                    if ($from !== null && $this->isTransparent($from) && str_contains($prop, 'Fill')) {
                        continue;
                    }
                    if ($from !== null && $this->isZero($from) && str_contains($prop, 'Thickness')) {
                        continue;
                    }
                    $before = $from ?? '(unset)';
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $before, $to);
                }
            }
        }
    }

    private function isTransparent(string $value): bool
    {
        $v = strtolower(trim($value));
        $v = ltrim($v, '=');
        return str_contains($v, 'rgba(0, 0, 0, 0)')
            || str_contains($v, 'rgba(0,0,0,0)')
            || str_contains($v, 'color.transparent')
            || preg_match('/rgba?\([^)]+,\s*0\s*\)/', $v) === 1;
    }

    private function isZero(string $value): bool
    {
        $v = ltrim(trim($value), '=');
        return $v === '0' || $v === '0px';
    }
}
