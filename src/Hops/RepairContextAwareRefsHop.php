<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\AppDataContext;
use PowerSweeper\ControlDocument;
use PowerSweeper\FormulaPatternAnalyzer;
use PowerSweeper\IterativeFormulaRepairer;
use PowerSweeper\Report;
use PowerSweeper\StudioPostRepairValidator;

/**
 * Context-aware control-reference repair with a verify loop.
 *
 * Detects copy-paste patterns across parallel controls, proposes catalog/fuzzy
 * replacements, and applies each candidate only when the live formula checker
 * reports fewer reference errors afterward.
 */
final class RepairContextAwareRefsHop implements HopInterface
{
    public static function id(): string
    {
        return 'repair_context_aware_refs';
    }

    public static function label(): string
    {
        return 'Context-aware reference repair';
    }

    public static function description(): string
    {
        return 'Iteratively repair stale control refs, copy-paste mistakes, and typos using pattern detection and live error verification (not blind find/replace).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = $options['_extract_dir'] ?? null;
        $before = StudioPostRepairValidator::validate($documents, [
            'extract_dir' => is_string($extractDir) ? $extractDir : null,
        ]);

        $catalog = AppControlCatalog::build($documents);
        $perHostPattern = FormulaPatternAnalyzer::inferPerHostRenameMap($documents, $catalog);
        if (($before['by_kind']['unresolved_control_ref'] ?? 0) === 0 && $perHostPattern === []) {
            return;
        }

        $patternMap = FormulaPatternAnalyzer::inferRenameMap($documents, $catalog);
        if ($patternMap !== []) {
            foreach ($patternMap as $from => $to) {
                $report->add(self::id(), '(pattern)', 'inferred', $from, $to);
            }
        }

        $snapshots = $this->snapshotFormulas($documents);
        $dataContext = AppDataContext::build($documents, is_string($extractDir) ? $extractDir : null);
        $engine = new IterativeFormulaRepairer($dataContext);
        $stats = $engine->repair($documents, $options);

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null) {
                        continue;
                    }
                    $key = $control->path . '.' . $prop;
                    $old = $snapshots[$key] ?? null;
                    if ($old !== null && $old !== $value) {
                        $report->add(
                            self::id(),
                            $key,
                            $prop,
                            self::preview($old),
                            self::preview($value),
                        );
                    }
                }
            }
        }

        $after = StudioPostRepairValidator::validate($documents, [
            'extract_dir' => is_string($extractDir) ? $extractDir : null,
        ]);

        if (($options['report_stats'] ?? true) && $stats['repairs'] > 0) {
            $report->add(
                self::id(),
                '(summary)',
                'iterations',
                (string) ($before['by_kind']['unresolved_control_ref'] ?? 0),
                sprintf(
                    '%d repairs in %d iterations; unresolved refs %d → %d',
                    $stats['repairs'],
                    $stats['iterations'],
                    $before['by_kind']['unresolved_control_ref'] ?? 0,
                    $after['by_kind']['unresolved_control_ref'] ?? 0,
                ),
            );
        }
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, string>
     */
    private function snapshotFormulas(array $documents): array
    {
        $snapshots = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value !== null && trim($value) !== '') {
                        $snapshots[$control->path . '.' . $prop] = $value;
                    }
                }
            }
        }

        return $snapshots;
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;

        return strlen($s) > 160 ? substr($s, 0, 157) . '...' : $s;
    }
}
