<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Align varCurrentPackage record shape with live references.
 *
 * - Adds missing loadPackage fields as new live code lines (never uncomments or edits // lines).
 * - Strips live usages of SharePoint columns known to be absent from the list.
 */
final class RepairVarCurrentPackageHop implements HopInterface
{
    /** @var list<string> Fields absent from SharePoint — strip usages instead of restoring. */
    private const DROP_FIELDS = [
        'AmendmentVisit',
    ];

    public static function id(): string
    {
        return 'repair_var_current_package';
    }

    public static function label(): string
    {
        return 'Repair varCurrentPackage record shape';
    }

    public static function description(): string
    {
        return 'Add missing loadPackage fields in live code and remove dropped SharePoint column usages. Comments are never modified.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $used = $this->collectUsedFields($documents);
        foreach (self::DROP_FIELDS as $drop) {
            unset($used[$drop]);
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($used, $report): string {
                $new = $formula;
                if (str_contains($path, 'loadPackage')) {
                    $new = $this->ensureLoadPackageFields($new, $used, $report, $path);
                }
                $new = $this->stripDroppedFieldUsages($new, $report, $path);

                return $new;
            });
        }
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, true>
     */
    private function collectUsedFields(array $documents): array
    {
        $used = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (preg_match_all('/\bvarCurrentPackage\.([A-Za-z_][\w]*)/', $value, $m)) {
                        foreach ($m[1] as $field) {
                            $used[$field] = true;
                        }
                    }
                }
            }
        }

        return $used;
    }

    /**
     * @param array<string, true> $used
     */
    private function ensureLoadPackageFields(string $formula, array $used, Report $report, string $path): string
    {
        if ($used === [] || !str_contains($formula, 'varCurrentPackage') || !str_contains($formula, 'loadedRequest')) {
            return $formula;
        }

        $parts = PowerFxFormulaSegments::split($formula);
        $activeInCode = [];
        foreach ($parts as [$type, $text]) {
            if ($type !== 'code') {
                continue;
            }
            if (preg_match_all('/^[ \t]*([A-Za-z_][\w]*)\s*:\s*loadedRequest\./m', $text, $m)) {
                foreach ($m[1] as $field) {
                    $activeInCode[$field] = true;
                }
            }
        }

        $missing = [];
        foreach (array_keys($used) as $field) {
            if (!isset($activeInCode[$field])) {
                $missing[] = $field;
            }
        }
        if ($missing === []) {
            return $formula;
        }

        $indent = '                ';
        foreach ($parts as [$type, $text]) {
            if ($type === 'code' && preg_match('/^([ \t]*)([A-Za-z_][\w]*)\s*:\s*loadedRequest\./m', $text, $im)) {
                $indent = $im[1];
                break;
            }
        }

        $insertBlock = '';
        foreach ($missing as $field) {
            $insertBlock .= $indent . $field . ': loadedRequest.' . $field . ",\n";
            $report->add(self::id(), $path, 'loadPackage field', '(missing)', $field);
        }

        $anchorIdx = null;
        foreach ($parts as $idx => [$type, $text]) {
            if ($type === 'code' && preg_match('/^[ \t]*VIP\s*:\s*loadedRequest\.VIP,/m', $text)) {
                $anchorIdx = $idx;
                break;
            }
        }
        if ($anchorIdx === null) {
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                if ($parts[$i][0] === 'code' && str_contains($parts[$i][1], 'loadedRequest.')) {
                    $anchorIdx = $i;
                    break;
                }
            }
        }
        if ($anchorIdx === null) {
            return $formula;
        }

        $parts[$anchorIdx][1] = rtrim($parts[$anchorIdx][1]) . "\n" . $insertBlock;

        return PowerFxFormulaSegments::join($parts);
    }

    private function stripDroppedFieldUsages(string $formula, Report $report, string $path): string
    {
        $changed = false;
        $out = PowerFxFormulaSegments::transformCode($formula, static function (string $code) use ($report, $path, &$changed): string {
            $new = $code;
            foreach (self::DROP_FIELDS as $field) {
                $pattern = '/\bvarCurrentPackage\.' . preg_quote($field, '/') . '\b/';
                $replaced = preg_replace($pattern, 'false', $new);
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, 'varCurrentPackage.' . $field, '(reference)', 'false');
                    $new = $replaced;
                    $changed = true;
                }
            }

            return $new;
        });

        return $changed ? $out : $formula;
    }
}
