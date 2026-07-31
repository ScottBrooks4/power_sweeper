<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Post-repair validation mirroring Studio App checker classes without relying on
 * stale embedded SARIF. Used to verify repair coverage on unpacked documents.
 */
final class StudioPostRepairValidator
{
    /**
     * @param list<ControlDocument> $documents
     * @return array{
     *   total:int,
     *   by_category:array<string,int>,
     *   by_kind:array<string,int>,
     *   issues:list<array{category:string,kind:string,control:string,property:string,detail:string}>
     * }
     */
    public static function validate(array $documents): array
    {
        $catalog = AppControlCatalog::build($documents);
        $issues = [];

        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc) ?? '(unknown)';
            $localNames = [];
            foreach ($doc->controls() as $c) {
                $localNames[$c->name] = true;
            }

            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }

                    foreach (StudioIssueScanner::classify($prop, $value, $control) as $kind) {
                        $issues[] = [
                            'category' => 'formulas',
                            'kind' => $kind,
                            'control' => $control->path,
                            'property' => $prop,
                            'detail' => StudioIssueScanner::preview($value),
                        ];
                    }

                    foreach (self::unresolvedControlRefs($value, $screen, $catalog, $control->name, $localNames) as $ref) {
                        $issues[] = [
                            'category' => 'formulas',
                            'kind' => 'unresolved_control_ref',
                            'control' => $control->path,
                            'property' => $prop,
                            'detail' => $ref,
                        ];
                    }

                    if (str_contains(strtolower($prop), 'onselect') || str_contains(strtolower($prop), 'onchange') || str_contains(strtolower($prop), 'onvisible')) {
                        foreach (self::delegationHints($value) as $hint) {
                            $issues[] = [
                                'category' => 'performance',
                                'kind' => 'delegation_warning',
                                'control' => $control->path,
                                'property' => $prop,
                                'detail' => $hint,
                            ];
                        }
                    }
                }

                if ($control->isInteractive()) {
                    $label = $control->getProperty('AccessibleLabel');
                    if ($label === null || trim($label) === '') {
                        $issues[] = [
                            'category' => 'accessibility',
                            'kind' => 'missing_accessible_label',
                            'control' => $control->path,
                            'property' => 'AccessibleLabel',
                            'detail' => '(empty)',
                        ];
                    }
                    $tab = $control->getProperty('TabIndex');
                    if ($tab === null || trim($tab) === '') {
                        $issues[] = [
                            'category' => 'accessibility',
                            'kind' => 'missing_tab_index',
                            'control' => $control->path,
                            'property' => 'TabIndex',
                            'detail' => '(unset)',
                        ];
                    }
                    $ft = $control->getProperty('FocusedBorderThickness');
                    if ($ft === null || self::isBlankOrZero($ft)) {
                        $issues[] = [
                            'category' => 'accessibility',
                            'kind' => 'focus_not_showing',
                            'control' => $control->path,
                            'property' => 'FocusedBorderThickness',
                            'detail' => $ft ?? '(unset)',
                        ];
                    }
                }

                if (str_contains(strtolower($control->type), 'gallery')) {
                    $delay = $control->getProperty('DelayItemLoading');
                    if ($delay === null || in_array(strtolower(trim(ltrim(trim($delay), '='))), ['false', '0'], true)) {
                        $issues[] = [
                            'category' => 'performance',
                            'kind' => 'inefficient_delay_loading',
                            'control' => $control->path,
                            'property' => 'DelayItemLoading',
                            'detail' => $delay ?? '(unset)',
                        ];
                    }
                }
            }
        }

        $byCategory = [];
        $byKind = [];
        foreach ($issues as $issue) {
            $byCategory[$issue['category']] = ($byCategory[$issue['category']] ?? 0) + 1;
            $byKind[$issue['kind']] = ($byKind[$issue['kind']] ?? 0) + 1;
        }
        arsort($byCategory);
        arsort($byKind);

        return [
            'total' => count($issues),
            'by_category' => $byCategory,
            'by_kind' => $byKind,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string, true> $localNames
     * @return list<string>
     */
    private static function unresolvedControlRefs(string $formula, string $screen, AppControlCatalog $catalog, string $controlName, array $localNames): array
    {
        $bad = [];
        foreach (FormulaReferenceExtractor::identifiers($formula) as $id) {
            if ($id === $controlName || $id === '_' || preg_match('/^_\d+$/', $id)) {
                continue;
            }
            if (isset($localNames[$id])) {
                continue;
            }
            if ($catalog->isReserved($id)) {
                continue;
            }
            if (preg_match('/^(var|col|gbl)[A-Z]/', $id)) {
                continue;
            }
            if ($id === '_' || strlen($id) < 2) {
                continue;
            }
            if (preg_match('/^@/', $id)) {
                continue;
            }
            // Datasource / list names often contain spaces and are quoted — skip if not on any screen.
            if (str_contains($id, ' ')) {
                continue;
            }
            if ($catalog->hasOnScreen($screen, $id)) {
                continue;
            }
            if ($catalog->resolveIdentifier($screen, $id) !== null) {
                continue;
            }
            // Exists on another screen only — qualified access is valid; bare ref is not.
            $others = $catalog->screensWith($id);
            if ($others !== [] && !in_array($screen, $others, true)) {
                $bad[] = $id;
            } elseif ($others === [] && preg_match('/_\d+$/', $id)) {
                $bad[] = $id;
            }
        }
        return array_values(array_unique($bad));
    }

    /**
     * @return list<string>
     */
    private static function delegationHints(string $formula): array
    {
        $hints = [];
        if (preg_match_all('/\b(Lower|Upper|Trim|Len|CountIf|Find|MatchAll)\s*\(/i', $formula, $m)) {
            foreach (array_unique($m[1]) as $fn) {
                $hints[] = $fn;
            }
        }
        return $hints;
    }

    private static function isBlankOrZero(string $value): bool
    {
        $v = strtolower(trim(ltrim(trim($value), '=')));
        return $v === '' || $v === '0' || $v === '0.0' || $v === 'false';
    }
}
