<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\Report;

/**
 * Repair formulas corrupted by comma-decimal locale authoring (e.g. German),
 * including InvariantScript / internal rules the Studio formula bar may not expose.
 *
 * Converts locale separators back to invariant Power Fx:
 *   decimal "," → ".", list ";" → ",", chaining ";;" → ";"
 */
final class UnwhackLocaleFormulasHop implements HopInterface
{
    public static function id(): string
    {
        return 'unwhack_locale_formulas';
    }

    public static function label(): string
    {
        return 'Unwhack locale formulas';
    }

    public static function description(): string
    {
        return 'Fix comma-decimal / semicolon list-separator corruption (e.g. after switching the app language to German) in YAML and internal InvariantScript the editor cannot always reach.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $force = !empty($options['force']);

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($force, $report): string {
                if (!$force && !FormulaLocaleNormalizer::looksLocaleCorrupted($formula)) {
                    return $formula;
                }
                $fixed = FormulaLocaleNormalizer::toInvariant($formula, $force);
                if ($fixed === $formula) {
                    return $formula;
                }
                $report->add(self::id(), $path, 'formula', self::preview($formula), self::preview($fixed));
                return $fixed;
            });
        }
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        if (strlen($s) > 160) {
            return substr($s, 0, 157) . '...';
        }
        return $s;
    }
}
