<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlTypoMap;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;
use PowerSweeper\SharePoint\SharePointCatalog;
use PowerSweeper\StringSimilarity;

/**
 * Fix SharePoint record field typos and replace references to columns that do not
 * exist on known lists. Columns are unioned across every SharePoint list in the
 * package. Fallbacks are derived heuristically from live column names (with a
 * small VisitType choice seed). Code segments only.
 */
final class RepairSharePointFieldsHop implements HopInterface
{
    /** @var list<string> */
    private const DROP_FIELDS = [
        'AmendmentVisit',
    ];

    /**
     * Choice-value seeds when a missing *Visit field maps to VisitType.Value.
     * Keys are missing fields; values are the choice string (not a full expression).
     *
     * @var array<string, string>
     */
    private const VISIT_TYPE_CHOICES = [
        'OneTimeVisit' => 'One-time',
        'RecurringVisit' => 'Recurring',
        'EmergencyVisit' => 'Emergency',
        'AmendmentVisit' => 'Amendment',
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
        return 'Rename mistyped SharePoint column references (typo seeds + fuzzy vs live columns) and replace missing columns with catalog-driven Coalesce fallbacks (code only).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = isset($options['_extract_dir']) && is_string($options['_extract_dir'])
            ? $options['_extract_dir']
            : '';
        $columnList = [];
        if ($extractDir !== '' && is_dir($extractDir)) {
            $catalog = SharePointCatalog::loadFromExtractDir($extractDir);
            $columnList = $this->unionColumns($catalog);
            $this->listColumns = $columnList !== [] ? array_flip($columnList) : null;
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($report, $columnList): string {
                $changed = false;
                $out = PowerFxFormulaSegments::transformCode($formula, function (string $code) use ($report, $path, &$changed, $columnList): string {
                    $new = $code;
                    $renames = ControlTypoMap::MAP;
                    // Heuristic: mistyped record fields → nearest live column (token distance).
                    if ($columnList !== [] && $this->listColumns !== null) {
                        foreach ($this->discoverUnknownRecordFields($new) as $field) {
                            if (isset($renames[$field]) || isset($this->listColumns[$field])) {
                                continue;
                            }
                            $hit = StringSimilarity::bestMatch($field, $columnList, 2);
                            if ($hit !== null && ($hit['score'] ?? 0) >= 88.0) {
                                $renames[$field] = $hit['match'];
                            }
                        }
                    }
                    foreach ($renames as $old => $replacement) {
                        $pattern = '/\b([A-Za-z_][\w]*)\.' . preg_quote($old, '/') . '\b/';
                        $replaced = preg_replace($pattern, '$1.' . $replacement, $new);
                        if ($replaced !== null && $replaced !== $new) {
                            $report->add(self::id(), $path, 'record field', $old, $replacement);
                            $new = $replaced;
                            $changed = true;
                        }
                    }
                    foreach (self::DROP_FIELDS as $field) {
                        // Only drop Patch record lines when the column is absent.
                        if ($this->listColumns !== null && isset($this->listColumns[$field])) {
                            continue;
                        }
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
            foreach ($this->discoverUnknownRecordFields($new) as $field) {
                if (isset($this->listColumns[$field])) {
                    continue;
                }
                foreach (['loadedRequest' => 'loadedRequest', 'r' => 'r'] as $var) {
                    $fallback = $this->deriveFallback($field, $var);
                    if ($fallback === null) {
                        continue;
                    }
                    $pattern = '/\b' . preg_quote($var, '/') . '\.' . preg_quote($field, '/') . '\b/';
                    $replaced = preg_replace($pattern, $fallback, $new);
                    if ($replaced !== null && $replaced !== $new) {
                        $report->add(self::id(), $path, $var . '.' . $field, '(missing column)', $fallback);
                        $new = $replaced;
                        $changed = true;
                    }
                }
            }

            return $new;
        });

        return $changed ? $out : $formula;
    }

    private function deriveFallback(string $field, string $recordVar): ?string
    {
        if (isset(self::VISIT_TYPE_CHOICES[$field]) && isset($this->listColumns['VisitType'])) {
            $choice = self::VISIT_TYPE_CHOICES[$field];

            return 'Coalesce(' . $recordVar . '.VisitType.Value = "' . $choice . '", false)';
        }

        // LevelConfidential → Confidential when that column exists
        if (preg_match('/^Level([A-Z].+)$/', $field, $m) && isset($this->listColumns[$m[1]])) {
            return 'Coalesce(' . $recordVar . '.' . $m[1] . ', false)';
        }

        // LevelUnclassified / LevelRestricted → UnclassifiedRestricted
        if (preg_match('/^Level(Unclassified|Restricted)$/', $field) && isset($this->listColumns['UnclassifiedRestricted'])) {
            return 'Coalesce(' . $recordVar . '.UnclassifiedRestricted, false)';
        }

        // FooVisit → VisitType choice when VisitType exists (humanize Foo)
        if (preg_match('/^(.+)Visit$/', $field, $m) && isset($this->listColumns['VisitType'])) {
            $choice = $this->humanizeChoiceLabel($m[1]);

            return 'Coalesce(' . $recordVar . '.VisitType.Value = "' . $choice . '", false)';
        }

        // GovernmentInitiative → Government; CommercialInitiative → InitiativeType
        if (preg_match('/^(.+)Initiative$/', $field, $m)) {
            if (isset($this->listColumns[$m[1]])) {
                return 'Coalesce(' . $recordVar . '.' . $m[1] . ', false)';
            }
            if (isset($this->listColumns['InitiativeType'])) {
                return 'Coalesce(' . $recordVar . '.InitiativeType, false)';
            }
        }

        // InitiatedByRequestingAgency → Initiation
        if (str_starts_with($field, 'Initiated') && isset($this->listColumns['Initiation'])) {
            return 'Coalesce(' . $recordVar . '.Initiation, false)';
        }

        // PertinenceSpecification / SubjectSpecification → Subject
        if (preg_match('/Specification$/i', $field) && isset($this->listColumns['Subject'])) {
            return 'Coalesce(' . $recordVar . '.Subject, "")';
        }

        // *Phone / *Tel → column whose name contains Phone/Tel (prefer longest shared token)
        if (preg_match('/(Phone|Tel|Mobile|Cell)$/i', $field)) {
            $phoneCol = $this->bestColumnContaining($field, ['Phone', 'Tel', 'Mobile', 'Cell']);
            if ($phoneCol !== null) {
                return 'Coalesce(' . $recordVar . '.' . $phoneCol . ', "")';
            }
        }

        // Novel spellings → nearest live column
        $cols = array_keys($this->listColumns ?? []);
        $hit = StringSimilarity::bestMatch($field, $cols, 3);
        if ($hit !== null && ($hit['score'] ?? 0) >= 72.0) {
            $default = $this->looksTextualField($field) ? '""' : 'false';

            return 'Coalesce(' . $recordVar . '.' . $hit['match'] . ', ' . $default . ')';
        }

        if ($this->looksTextualField($field)) {
            return '""';
        }

        // Boolean-ish control leftovers (Pertinent*, Initiated*, ByInvitation*, Level*)
        if ($this->looksBooleanField($field)) {
            return 'false';
        }

        return 'false';
    }

    private function humanizeChoiceLabel(string $pascal): string
    {
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1-$2', $pascal) ?? $pascal;
        $spaced = str_replace('_', '-', $spaced);

        return match (strtolower(str_replace('-', '', $pascal))) {
            'onetime' => 'One-time',
            default => ucfirst(strtolower($spaced)),
        };
    }

    private function looksTextualField(string $field): bool
    {
        return (bool) preg_match('/(Specification|Subject|Phone|Tel|Email|Name|Postal|Address|Text|Comment|Note)$/i', $field);
    }

    /**
     * Prefer a live column that shares the field's contact suffix and maximizes
     * shared PascalCase tokens (CanadianCellPhone → EmerContactCanadianPhone).
     *
     * @param list<string> $needles
     */
    private function bestColumnContaining(string $field, array $needles): ?string
    {
        $fieldTokens = $this->pascalTokens($field);
        $best = null;
        $bestScore = -1;
        foreach (array_keys($this->listColumns ?? []) as $col) {
            $hasNeedle = false;
            foreach ($needles as $n) {
                if (stripos($col, $n) !== false) {
                    $hasNeedle = true;
                    break;
                }
            }
            if (!$hasNeedle) {
                continue;
            }
            $colTokens = $this->pascalTokens($col);
            $shared = count(array_intersect($fieldTokens, $colTokens));
            $score = ($shared * 10) - abs(count($colTokens) - count($fieldTokens));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $col;
            }
        }

        return $bestScore >= 10 ? $best : null;
    }

    /** @return list<string> */
    private function pascalTokens(string $name): array
    {
        $parts = preg_split('/(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $t = strtolower($p);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function looksBooleanField(string $field): bool
    {
        return (bool) preg_match('/^(Level|Pertinent|Initiated|ByInvitation|Government|Commercial)/i', $field)
            || (bool) preg_match('/Visit$/i', $field);
    }

    /** @return list<string> */
    private function unionColumns(SharePointCatalog $catalog): array
    {
        $cols = [];
        $lists = $catalog->sharePointListNames();
        // Prefer lists with the richest column sets (no app-specific name seeds).
        usort($lists, function (string $a, string $b) use ($catalog): int {
            $ca = count($catalog->columnNamesFor($a));
            $cb = count($catalog->columnNamesFor($b));

            return $cb <=> $ca ?: strcasecmp($a, $b);
        });
        foreach ($lists as $list) {
            foreach ($catalog->columnNamesFor($list) as $col) {
                $cols[$col] = true;
            }
        }
        if ($cols === []) {
            foreach ($catalog->sources as $src) {
                foreach (array_keys($src['columns'] ?? []) as $col) {
                    if (is_string($col) && $col !== '') {
                        $cols[$col] = true;
                    }
                }
            }
        }

        return array_keys($cols);
    }

    /** @return list<string> */
    private function discoverUnknownRecordFields(string $code): array
    {
        $fields = [];
        if (preg_match_all('/\b(?:loadedRequest|r)\.([A-Za-z_][\w]*)\b/', $code, $m)) {
            foreach ($m[1] as $field) {
                $fields[$field] = true;
            }
        }

        return array_keys($fields);
    }
}
