<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;

/**
 * Restore fields on varCurrentPackage in ExternalFunctions.loadPackage that are
 * still referenced elsewhere (savePDFtest, savePDF, etc.) but were commented out.
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
        return 'Uncomment loadPackage fields referenced by varCurrentPackage.* and remove dropped SharePoint columns from live formulas.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $used = $this->collectUsedFields($documents);
        foreach (self::DROP_FIELDS as $drop) {
            unset($used[$drop]);
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($used, $report): string {
                $new = $this->uncommentLoadPackageFields($formula, $used, $report, $path);
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
    private function uncommentLoadPackageFields(string $formula, array $used, Report $report, string $path): string
    {
        if (!str_contains($formula, 'varCurrentPackage') || !str_contains($formula, 'loadedRequest')) {
            return $formula;
        }

        $new = $formula;
        foreach (array_keys($used) as $field) {
            // Studio often writes //FieldName without a space after //.
            $pattern = '/^[ \t]*\/\/[ \t]*(' . preg_quote($field, '/') . '\s*:\s*loadedRequest\.[^,\r\n]+,?)[ \t]*\r?$/m';
            $replaced = preg_replace($pattern, '$1', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'loadPackage field', '//' . $field, $field);
                $new = $replaced;
            }
        }

        return $new;
    }

    private function stripDroppedFieldUsages(string $formula, Report $report, string $path): string
    {
        $new = $formula;
        foreach (self::DROP_FIELDS as $field) {
            $block = '/\s*If\s*\(\s*varCurrentPackage\.' . preg_quote($field, '/')
                . '\s*,\s*"[^"]*"\s*,\s*""\s*\)\s*,?/';
            $replaced = preg_replace($block, '', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'varCurrentPackage.' . $field, '(If block)', '(removed)');
                $new = $replaced;
            }
            // HtmlText / string compares: varCurrentPackage.AmendmentVisit = "true"
            $cmp = '/\s*varCurrentPackage\.' . preg_quote($field, '/')
                . '\s*=\s*"[^"]*"\s*,?/';
            $replaced = preg_replace($cmp, '', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'varCurrentPackage.' . $field, '(compare)', '(removed)');
                $new = $replaced;
            }
        }
        return $new;
    }
}
