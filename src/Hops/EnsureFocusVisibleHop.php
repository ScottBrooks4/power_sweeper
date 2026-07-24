<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;

/**
 * Fix App checker "Focus isn't showing" by ensuring interactive controls have a visible focus ring.
 */
final class EnsureFocusVisibleHop implements HopInterface
{
    public static function id(): string
    {
        return 'ensure_focus_visible';
    }

    public static function label(): string
    {
        return 'Ensure focus visible';
    }

    public static function description(): string
    {
        return 'Set FocusedBorderThickness (and a sensible FocusedBorderColor when missing) on interactive controls so App checker "Focus isn\'t showing" is addressed.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $thickness = isset($options['thickness']) ? max(1, (int) $options['thickness']) : 2;
        $defaultColorYaml = is_string($options['color'] ?? null)
            ? (string) $options['color']
            : '=RGBA(37, 99, 235, 1)';

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isInteractive()) {
                    continue;
                }

                $current = $control->getProperty('FocusedBorderThickness');
                $needsThickness = $current === null
                    || $this->isBlankOrZero($current);

                if ($needsThickness) {
                    $to = $control->format === 'yaml' ? '=' . $thickness : (string) $thickness;
                    $control->setProperty('FocusedBorderThickness', $to);
                    $report->add(
                        self::id(),
                        $control->path,
                        'FocusedBorderThickness',
                        $current ?? '(unset)',
                        $to
                    );
                }

                $borderColor = $control->getProperty('FocusedBorderColor');
                if ($borderColor === null || $this->isBlank($borderColor)) {
                    $toColor = $control->format === 'yaml'
                        ? $defaultColorYaml
                        : ltrim($defaultColorYaml, '=');
                    $control->setProperty('FocusedBorderColor', $toColor);
                    $report->add(
                        self::id(),
                        $control->path,
                        'FocusedBorderColor',
                        $borderColor ?? '(unset)',
                        $toColor
                    );
                }
            }
        }
    }

    private function isBlankOrZero(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $v = strtolower(trim(ltrim(trim($value), '=')));
        return $v === '' || $v === '0' || $v === '0.0' || $v === 'false';
    }

    private function isBlank(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $v = strtolower(trim(ltrim(trim($value), '=')));
        return $v === '' || $v === 'blank()' || $v === '""' || $v === "''";
    }
}
