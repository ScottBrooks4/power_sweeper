<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/**
 * Fix App checker acc-TabIndexShouldBeDefinedForInteractiveControl by setting
 * TabIndex to 0 when missing on interactive controls (include in tab order).
 */
final class EnsureTabIndexHop implements HopInterface
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
        return 'Set TabIndex = 0 on interactive controls when unset so App checker "TabIndex should be defined" is addressed.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $value = array_key_exists('value', $options) ? (int) $options['value'] : 0;

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isInteractive()) {
                    continue;
                }
                $current = $control->getProperty('TabIndex');
                if ($current !== null && trim(ltrim(trim($current), '=')) !== '') {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=' . $value : (string) $value;
                $control->setProperty('TabIndex', $to);
                $report->add(self::id(), $control->path, 'TabIndex', $current ?? '(unset)', $to);
            }
        }
    }
}
