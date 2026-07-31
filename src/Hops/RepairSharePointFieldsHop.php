<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;
use PowerSweeper\SharePoint\SharePointCatalog;

/**
 * Fix known SharePoint record field typos and drop fields that do not exist on the list.
 * Code segments only — comments and strings are never modified.
 */
final class RepairSharePointFieldsHop implements HopInterface
{
    /** @var array<string, string> record.field renames (left side of loadedRequest.X) */
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

    /**
     * Replace loadedRequest.X / r.X when X is not a SharePoint column.
     *
     * @var array<string, string>
     */
    private const RECORD_FIELD_FALLBACKS = [
        'OneTimeVisit' => 'Coalesce(loadedRequest.VisitType.Value = "One-time", false)',
        'RecurringVisit' => 'Coalesce(loadedRequest.VisitType.Value = "Recurring", false)',
        'EmergencyVisit' => 'Coalesce(loadedRequest.VisitType.Value = "Emergency", false)',
        'AmendmentVisit' => 'Coalesce(loadedRequest.VisitType.Value = "Amendment", false)',
        'LevelConfidential' => 'Coalesce(loadedRequest.Confidential, false)',
        'LevelSecret' => 'false',
        'LevelTopSecret' => 'false',
        'LevelOther' => 'false',
        'LevelSpecify' => 'false',
        'LevelRestricted' => 'Coalesce(loadedRequest.UnclassifiedRestricted, false)',
        'LevelUnclassified' => 'Coalesce(loadedRequest.UnclassifiedRestricted, false)',
        'Restricted0' => 'Coalesce(loadedRequest.UnclassifiedRestricted, false)',
        'GovernmentInitiative' => 'Coalesce(loadedRequest.Government, false)',
        'CommercialInitiative' => 'Coalesce(loadedRequest.InitiativeType, false)',
        'InitiatedByRequestingAgency' => 'Coalesce(loadedRequest.Initiation, false)',
        'ByInvitationOfFacility' => 'false',
        'PertinentToEquipment' => 'false',
        'PertinentToSales' => 'false',
        'PertinentToProgramme' => 'false',
        'PertinentToDefense' => 'false',
        'PertinentToOther' => 'false',
        'PertinenceSpecification' => 'Coalesce(loadedRequest.Subject, "")',
        'SubjectSpecification' => 'Coalesce(loadedRequest.Subject, "")',
        'CanadianCellPhone' => 'Coalesce(loadedRequest.EmerContactCanadianPhone, "")',
    ];

    /** @var array<string, string> r.X in With({r: LookUp…}) blocks */
    private const LOOKUP_RECORD_FALLBACKS = [
        'OneTimeVisit' => 'Coalesce(r.VisitType.Value = "One-time", false)',
        'RecurringVisit' => 'Coalesce(r.VisitType.Value = "Recurring", false)',
        'EmergencyVisit' => 'Coalesce(r.VisitType.Value = "Emergency", false)',
        'AmendmentVisit' => 'Coalesce(r.VisitType.Value = "Amendment", false)',
        'LevelConfidential' => 'Coalesce(r.Confidential, false)',
        'LevelSecret' => 'false',
        'LevelTopSecret' => 'false',
        'LevelOther' => 'false',
        'GovernmentInitiative' => 'Coalesce(r.Government, false)',
        'CommercialInitiative' => 'Coalesce(r.InitiativeType, false)',
        'InitiatedByRequestingAgency' => 'Coalesce(r.Initiation, false)',
        'ByInvitationOfFacility' => 'false',
        'PertinentToEquipment' => 'false',
        'PertinentToSales' => 'false',
        'PertinentToProgramme' => 'false',
        'PertinentToDefense' => 'false',
        'PertinentToOther' => 'false',
        'CanadianCellPhone' => 'Coalesce(r.EmerContactCanadianPhone, "")',
    ];

    /** @var array<string, true>|null */
    private ?array $listColumns = null;

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
        $extractDir = isset($options['_extract_dir']) && is_string($options['_extract_dir'])
            ? $options['_extract_dir']
            : '';
        if ($extractDir !== '' && is_dir($extractDir)) {
            $catalog = SharePointCatalog::loadFromExtractDir($extractDir);
            $cols = $catalog->columnNamesFor('CDLS (L) VCR Tracking List');
            $this->listColumns = $cols !== [] ? array_flip($cols) : null;
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($report): string {
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

                $out2 = $this->applyMissingColumnFallbacks($out, $path, $report);
                if ($out2 !== $out) {
                    $changed = true;
                    $out = $out2;
                }

                return $changed ? $out : $formula;
            });
        }
    }

    private function applyMissingColumnFallbacks(string $formula, string $path, Report $report): string
    {
        if ($this->listColumns === null) {
            return $formula;
        }

        $changed = false;
        $out = PowerFxFormulaSegments::transformCode($formula, function (string $code) use ($report, $path, &$changed): string {
            $new = $code;
            foreach (self::RECORD_FIELD_FALLBACKS as $field => $fallback) {
                if (isset($this->listColumns[$field])) {
                    continue;
                }
                $expr = str_replace('loadedRequest.', 'loadedRequest.', $fallback);
                $pattern = '/\bloadedRequest\.' . preg_quote($field, '/') . '\b/';
                $replaced = preg_replace($pattern, $expr, $new);
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, 'loadedRequest.' . $field, '(missing column)', '(fallback)');
                    $new = $replaced;
                    $changed = true;
                }
            }
            foreach (self::LOOKUP_RECORD_FALLBACKS as $field => $fallback) {
                if (isset($this->listColumns[$field])) {
                    continue;
                }
                $pattern = '/\br\.' . preg_quote($field, '/') . '\b/';
                $replaced = preg_replace($pattern, $fallback, $new);
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, 'r.' . $field, '(missing column)', '(fallback)');
                    $new = $replaced;
                    $changed = true;
                }
            }

            return $new;
        });

        return $changed ? $out : $formula;
    }
}
