<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Fix known SharePoint record field typos and drop fields that do not exist on the list.
 * Code segments only — comments and strings are never modified.
 */
final class RepairSharePointFieldsHop implements HopInterface
{
    /** @var array<string, string> */
    private const FIELD_RENAMES = [
        'Restricted0' => 'UnclassifiedRestricted',
        'LeveLTopSecret' => 'LevelTopSecret',
        'GovernmentInitiave' => 'GovernmentInitiative',
        'CommercialInitiave' => 'CommercialInitiative',
    ];

    /** @var list<string> */
    private const DROP_FIELDS = [
        'AmendmentVisit',
    ];

    public static function id(): string
    {
        return 'repair_sharepoint_fields';
    }

    public static function label(): string
    {
        return 'Repair SharePoint record fields';
    }

    public static function description(): string
    {
        return 'Rename mistyped SharePoint column references and remove fields known to be absent from the VCR tracking list (code only).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($report): string {
                if (str_contains($formula, 'Set(') && str_contains($formula, 'varCurrentPackage') && str_contains($formula, 'loadedRequest')) {
                    return $formula;
                }

                $changed = false;
                $out = PowerFxFormulaSegments::transformCode($formula, static function (string $code) use ($report, $path, &$changed): string {
                    $new = $code;
                    foreach (self::FIELD_RENAMES as $old => $replacement) {
                        $pattern = '/\b([A-Za-z_][\w]*)\.' . preg_quote($old, '/') . '\b/';
                        $replaced = preg_replace($pattern, '$1.' . $replacement, $new);
                        if ($replaced !== null && $replaced !== $new) {
                            $report->add(self::id(), $path, 'record field', $old, $replacement);
                            $new = $replaced;
                            $changed = true;
                        }
                    }
                    foreach (self::DROP_FIELDS as $field) {
                        $pattern = '/\s*' . preg_quote($field, '/') . '\s*:\s*[^,\n\/]+,?\s*/';
                        $replaced = preg_replace($pattern, '', $new);
                        if ($replaced !== null && $replaced !== $new) {
                            $report->add(self::id(), $path, 'record field', $field, '(removed)');
                            $new = $replaced;
                            $changed = true;
                        }
                    }

                    return $new;
                });

                return $changed ? $out : $formula;
            });
        }
    }
}
