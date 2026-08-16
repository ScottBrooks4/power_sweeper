<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\FormulaRepairConverger;
use PowerSweeper\Report;

/**
 * Live-checker converge loop: re-scan formula errors after each targeted repair
 * pass (refs, locale, booleans) until errors stop decreasing.
 */
final class RepairConvergeFormulasHop implements HopInterface
{
    public static function id(): string
    {
        return 'repair_converge_formulas';
    }

    public static function label(): string
    {
        return 'Converge formula repairs';
    }

    public static function description(): string
    {
        return 'Re-run the live App checker between repair passes (refs, locale, booleans) until formula errors stop decreasing — catches cascades and copy-paste leftovers.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = $options['_extract_dir'] ?? null;
        $options['extract_dir'] = is_string($extractDir) ? $extractDir : null;

        $snapshots = $this->snapshotFormulas($documents);
        $converger = new FormulaRepairConverger();
        $stats = $converger->converge($documents, $options);

        $detailRows = 0;
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
                            $control->path,
                            $prop,
                            self::preview($old),
                            self::preview((string) $value),
                        );
                        $detailRows++;
                    }
                }
            }
        }

        if ($stats['repairs'] === 0 && $stats['rounds'] === 0 && $detailRows === 0) {
            return;
        }

        if (($options['report_stats'] ?? true) === true) {
            $report->add(
                self::id(),
                '(summary)',
                'converge',
                (string) $stats['before'],
                sprintf(
                    '%d repairs in %d rounds; formula errors %d → %d',
                    max((int) $stats['repairs'], $detailRows),
                    $stats['rounds'],
                    $stats['before'],
                    $stats['after'],
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
        $out = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null) {
                        continue;
                    }
                    $out[$control->path . '.' . $prop] = (string) $value;
                }
            }
        }

        return $out;
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        return strlen($s) > 160 ? substr($s, 0, 157) . '...' : $s;
    }
}
