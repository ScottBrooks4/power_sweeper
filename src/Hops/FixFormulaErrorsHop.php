<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\HopRegistry;
use PowerSweeper\Report;
use PowerSweeper\StudioLiveChecker;

/**
 * One-click formula repair: runs the Studio formula-error hops in the proven order.
 *
 * Covers locale separators, control/screen refs, SharePoint/ghost fields, syntax,
 * checked booleans, delegation/maintainability, and a live-checker converge loop.
 *
 * Child repairs attribute under this hop id with a kind prefix on the property
 * column so the UI report reads like enable_dark_mode (control · from → to).
 *
 * Each sub-pass is verified with the live App checker (SARIF-equivalent rules).
 * If a pass would raise formula errors — or damage App.Formulas statement
 * separators — it is reverted and noted in the report.
 */
final class FixFormulaErrorsHop implements HopInterface
{
    /**
     * Formula-repair subset used by studio_repair (a11y lives in accessibility_polish).
     * Ends with regenerate_sarif when the pipeline provides _extract_dir.
     *
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public static function subHops(): array
    {
        return [
            ['id' => 'unwhack_locale_formulas', 'options' => []],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'repair_control_refs', 'options' => []],
            ['id' => 'repair_context_aware_refs', 'options' => []],
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'repair_var_current_package', 'options' => []],
            ['id' => 'repair_sharepoint_fields', 'options' => []],
            ['id' => 'repair_ghost_patch_fields', 'options' => []],
            ['id' => 'repair_studio_syntax', 'options' => []],
            ['id' => 'repair_checked_booleans', 'options' => []],
            ['id' => 'repair_maintainability', 'options' => []],
            ['id' => 'repair_delegation', 'options' => []],
            ['id' => 'repair_converge_formulas', 'options' => []],
            // Converge can re-touch Navigate/StartScreen; normalize any over-quotes.
            ['id' => 'repair_double_qualified_refs', 'options' => []],
            ['id' => 'regenerate_sarif', 'options' => []],
        ];
    }

    public static function kindLabel(string $subHopId): string
    {
        return match ($subHopId) {
            'unwhack_locale_formulas' => 'locale',
            'repair_double_qualified_refs' => 'screen refs',
            'repair_control_refs' => 'control refs',
            'repair_context_aware_refs' => 'context refs',
            'repair_var_current_package' => 'package fields',
            'repair_sharepoint_fields' => 'SharePoint fields',
            'repair_ghost_patch_fields' => 'ghost Patch fields',
            'repair_studio_syntax' => 'syntax',
            'repair_checked_booleans' => 'checked booleans',
            'repair_maintainability' => 'maintainability',
            'repair_delegation' => 'delegation',
            'repair_converge_formulas' => 'converge',
            'regenerate_sarif' => 'SARIF',
            default => $subHopId,
        };
    }

    public static function id(): string
    {
        return 'fix_formula_errors';
    }

    public static function label(): string
    {
        return 'Fix formula errors';
    }

    public static function description(): string
    {
        return 'Fix formula errors of every supported kind: locale separators, broken control/screen refs, SharePoint and ghost fields, Studio syntax, checked booleans, delegation/maintainability, then re-check until errors stop falling and regenerate App checker SARIF. Each pass is verified against the live checker and reverted if it would increase formula errors. Safe to run with Fix control names & references (overlapping ref passes may run twice).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $before = $report->count();
        $registry = new HopRegistry();
        $ran = 0;
        $byKind = [];
        $reverted = 0;
        $extractDir = is_string($options['_extract_dir'] ?? null) ? $options['_extract_dir'] : null;
        $guard = ($options['guard'] ?? true) !== false;

        $report->pushHopAlias(self::id());
        try {
            foreach (self::subHops() as $step) {
                $id = (string) $step['id'];
                if (!$registry->has($id) || $id === self::id()) {
                    continue;
                }
                if ($id === 'regenerate_sarif' && ($extractDir === null || $extractDir === '')) {
                    continue;
                }
                $kind = self::kindLabel($id);
                $countBefore = $report->count();

                $snapshot = $guard ? self::snapshotFormulas($documents) : [];
                $errorsBefore = $guard ? self::formulaErrorCount($documents, $extractDir) : 0;
                $formulasHealthBefore = $guard ? self::appFormulasSeparatorCount($documents) : 0;

                $probe = new Report(null, 500, 280);
                $hop = $registry->make($id);
                $childOptions = array_merge($options, $step['options']);
                $childOptions['report_stats'] = false;
                $hop->apply($documents, $probe, $childOptions);

                $ran++;
                if ($guard) {
                    $errorsAfter = self::formulaErrorCount($documents, $extractDir);
                    $formulasHealthAfter = self::appFormulasSeparatorCount($documents);
                    $raisedErrors = $errorsAfter > $errorsBefore;
                    $brokeFormulas = $formulasHealthAfter < $formulasHealthBefore;
                    if ($raisedErrors || $brokeFormulas) {
                        self::restoreFormulas($documents, $snapshot);
                        $reverted++;
                        $reason = $brokeFormulas
                            ? sprintf(
                                'reverted — App.Formulas separators %d → %d',
                                $formulasHealthBefore,
                                $formulasHealthAfter
                            )
                            : sprintf(
                                'reverted — live formula errors %d → %d',
                                $errorsBefore,
                                $errorsAfter
                            );
                        $report->add(
                            self::id(),
                            '(guard)',
                            $kind,
                            (string) $probe->count() . ' tentative change(s)',
                            $reason,
                        );
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                        continue;
                    }
                }

                $report->pushPropertyPrefix($kind);
                try {
                    foreach ($probe->entries() as $entry) {
                        $report->add(
                            self::id(),
                            $entry['control'],
                            $entry['property'],
                            $entry['from'],
                            $entry['to'],
                        );
                    }
                } finally {
                    $report->popPropertyPrefix();
                }

                $delta = $report->count() - $countBefore;
                if ($delta > 0) {
                    $byKind[$kind] = ($byKind[$kind] ?? 0) + $delta;
                }
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } finally {
            $report->popHopAlias();
        }

        $delta = $report->count() - $before;
        if ($delta === 0 && $reverted === 0) {
            $report->add(
                self::id(),
                '(summary)',
                'fix_formula_errors',
                (string) $ran . ' passes',
                'no formula changes reported',
            );
            return;
        }

        $parts = [];
        foreach ($byKind as $kind => $n) {
            $parts[] = $kind . ': ' . $n;
        }
        if ($reverted > 0) {
            $parts[] = 'reverted passes: ' . $reverted;
        }
        $report->add(
            self::id(),
            '(summary)',
            'fix_formula_errors',
            (string) max(0, $delta - $reverted) . ' change(s)',
            $parts === []
                ? sprintf('%d change(s) across %d passes', $delta, $ran)
                : implode(' · ', $parts),
        );
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, string>
     */
    private static function snapshotFormulas(array $documents): array
    {
        $out = [];
        foreach ($documents as $doc) {
            $doc->transformFormulas(static function (string $formula, string $path) use (&$out): string {
                $out[$path] = $formula;
                return $formula;
            });
        }

        return $out;
    }

    /**
     * @param list<ControlDocument> $documents
     * @param array<string, string> $snapshot
     */
    private static function restoreFormulas(array $documents, array $snapshot): void
    {
        foreach ($documents as $doc) {
            $doc->transformFormulas(static function (string $formula, string $path) use ($snapshot): string {
                return $snapshot[$path] ?? $formula;
            });
        }
    }

    /**
     * @param list<ControlDocument> $documents
     */
    private static function formulaErrorCount(array $documents, ?string $extractDir): int
    {
        $check = StudioLiveChecker::check($documents, [
            'extract_dir' => $extractDir,
        ]);
        $n = 0;
        foreach ($check['by_rule'] ?? [] as $rule => $count) {
            $rule = (string) $rule;
            if (str_starts_with($rule, 'app-Err') || str_starts_with($rule, 'app-formula')) {
                $n += (int) $count;
            }
        }

        return $n;
    }

    /**
     * Count top-level ";" separators in App.Formulas (named-formula terminators).
     * Losing these (replaced with ",") is a Studio-breaking false locale fix.
     *
     * @param list<ControlDocument> $documents
     */
    private static function appFormulasSeparatorCount(array $documents): int
    {
        foreach ($documents as $doc) {
            if (!str_ends_with($doc->relativePath, 'App.pa.yaml')
                && $doc->relativePath !== 'Src/App.pa.yaml'
            ) {
                continue;
            }
            foreach ($doc->controls() as $control) {
                if (strcasecmp($control->name, 'App') !== 0) {
                    continue;
                }
                $formulas = $control->getProperty('Formulas');
                if (!is_string($formulas) || $formulas === '') {
                    return 0;
                }

                return self::topLevelSemicolonCount($formulas);
            }
        }

        return 0;
    }

    private static function topLevelSemicolonCount(string $formula): int
    {
        $body = ltrim($formula);
        if (str_starts_with($body, '=')) {
            $body = substr($body, 1);
        }
        $len = strlen($body);
        $depth = 0;
        $count = 0;
        $inString = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inString) {
                if ($ch === '"' && ($body[$i + 1] ?? '') === '"') {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"' ) {
                $inString = true;
                continue;
            }
            if ($ch === '/' && ($body[$i + 1] ?? '') === '/') {
                while ($i < $len && $body[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($ch === ';' && $depth === 0) {
                $count++;
            }
        }

        return $count;
    }
}
