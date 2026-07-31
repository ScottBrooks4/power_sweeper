<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Remove unused global variables (Set(varX,...) never referenced) and prune dead
 * ClearCollect on read-only collections where safe.
 */
final class RepairMaintainabilityHop implements HopInterface
{
    public static function id(): string
    {
        return 'repair_maintainability';
    }

    public static function label(): string
    {
        return 'Repair maintainability issues';
    }

    public static function description(): string
    {
        return 'Remove unused global variables, cap non-delegable row limits, and enable efficient gallery delay loading where missing.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $allFormulaText = '';
        $appDoc = null;
        $appNode = null;

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula) use (&$allFormulaText): string {
                $allFormulaText .= "\n" . $formula;
                return $formula;
            });
            if (str_ends_with($doc->relativePath, 'App.pa.yaml') || basename($doc->relativePath) === 'App.pa.yaml') {
                $appDoc = $doc;
                foreach ($doc->controls() as $control) {
                    if ($control->isApp()) {
                        $appNode = $control;
                    }
                }
            }
        }

        if ($appNode !== null) {
            $this->pruneUnusedVariables($appNode, $allFormulaText, $report);
            $this->fixRowLimit($appNode, $report);
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $this->fixDelayLoading($control, $report);
            }
        }
    }

    private function pruneUnusedVariables(ControlNode $app, string $allFormulas, Report $report): void
    {
        foreach (['OnStart', 'StartScreen'] as $prop) {
            $value = $app->getProperty($prop);
            if ($value === null || trim($value) === '') {
                continue;
            }
            $new = $this->removeUnusedSetStatements($value, $allFormulas, $report, $app->path . '.' . $prop);
            if ($new !== $value) {
                $app->setProperty($prop, $new);
            }
        }
    }

    private function removeUnusedSetStatements(string $formula, string $allFormulas, Report $report, string $path): string
    {
        if (!preg_match_all('/\bSet\s*\(\s*(var[A-Za-z0-9_]+)\s*,/i', $formula, $m)) {
            return $formula;
        }

        $toRemove = [];
        foreach (array_unique($m[1]) as $var) {
            $pattern = '/\b' . preg_quote($var, '/') . '\b/i';
            $declCount = preg_match_all($pattern, $formula);
            $useCount = preg_match_all($pattern, $allFormulas);
            if ($useCount <= $declCount) {
                $toRemove[] = $var;
            }
        }

        if ($toRemove === []) {
            return $formula;
        }

        return PowerFxFormulaSegments::transformCode($formula, static function (string $code) use ($toRemove, $report, $path): string {
            $new = $code;
            foreach ($toRemove as $var) {
                $pattern = '/\bSet\s*\(\s*' . preg_quote($var, '/') . '\s*,[^;]*\)\s*;\s*/i';
                $replaced = preg_replace($pattern, '', $new);
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, 'unused variable', $var, '(removed Set statement)');
                    $new = $replaced;
                }
            }

            return $new;
        });
    }

    private function fixRowLimit(ControlNode $app, Report $report): void
    {
        // Studio flags when default max rows for non-delegable queries exceeds 500.
        $maxRows = $app->getProperty('MaxDataRowCount') ?? $app->getProperty('DataRowLimit');
        if ($maxRows === null) {
            return;
        }
        $n = (int) preg_replace('/\D/', '', $maxRows);
        if ($n > 500) {
            $app->setProperty('MaxDataRowCount', '500');
            $report->add(self::id(), $app->path, 'MaxDataRowCount', $maxRows, '500');
        }
    }

    private function fixDelayLoading(ControlNode $control, Report $report): void
    {
        if (!str_contains(strtolower($control->type), 'gallery')) {
            return;
        }
        $delay = $control->getProperty('DelayItemLoading');
        if ($delay !== null && !in_array(strtolower(trim(ltrim(trim($delay), '='))), ['false', '0'], true)) {
            return;
        }
        $control->setProperty('DelayItemLoading', 'true');
        $report->add(self::id(), $control->path, 'DelayItemLoading', $delay ?? '(unset)', 'true');
    }
}
