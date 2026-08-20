<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\Report;

/**
 * Fix App checker acc-TabIndexShouldBeDefinedForInteractiveControl ("Missing tab stop").
 *
 * Studio flags both unset TabIndex on interactive controls and TabIndex = -1
 * (common on Labels/Images/Icons even when OnSelect is false).
 */
final class EnsureTabIndexSweep implements SweepInterface
{
    public static function id(): string
    {
        return 'ensure_tab_index';
    }

    public static function label(): string
    {
        return 'Ensure tab index';
    }

    public static function description(): string
    {
        return 'Set TabIndex = 0 on interactive / action / TabIndex=-1 controls so App checker "Missing tab stop" is cleared (does not touch static labels with no TabIndex).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $value = array_key_exists('value', $options) ? (int) $options['value'] : 0;

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isAccessibilityInteractive()) {
                    continue;
                }
                $current = $control->getProperty('TabIndex');
                $parsed = $control->tabIndexValue();
                // Already a valid tab stop (>= 0).
                if ($parsed !== null && $parsed >= 0) {
                    continue;
                }
                // Unset on accessibility-interactive, or explicit -1 (Studio Missing tab stop).
                $needs = $current === null
                    || trim(ltrim(trim((string) $current), '=')) === ''
                    || ($parsed !== null && $parsed < 0);
                if (!$needs) {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=' . $value : (string) $value;
                $control->setProperty('TabIndex', $to);
                $report->add(
                    self::id(),
                    $control->path,
                    'TabIndex',
                    $current !== null && trim((string) $current) !== '' ? (string) $current : '(unset)',
                    $to
                );
            }
        }
    }
}
