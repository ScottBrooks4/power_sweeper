<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\DelegationFormulaRewriter;
use PowerSweeper\Report;

/**
 * Rewrite delegable-safe formula patterns (email equality, collection CountIf, nested Filter splits).
 * Code segments only — comments and strings are never modified.
 */
final class RepairDelegationHop implements HopInterface
{
    public static function id(): string
    {
        return 'repair_delegation';
    }

    public static function label(): string
    {
        return 'Repair delegation formulas';
    }

    public static function description(): string
    {
        return 'Apply safe delegation rewrites in live code only: email Filter equality, collection CountIf→CountRows, nested Filter splits.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($report): string {
                $rewritten = DelegationFormulaRewriter::rewrite($formula);
                if ($rewritten !== $formula) {
                    $report->add(self::id(), $path, '(formula)', '(delegation)', '(rewritten)');
                }
                return $rewritten;
            });
        }
    }
}
