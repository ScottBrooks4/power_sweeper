<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Heuristic scan for remaining Studio App-checker-class formula issues
 * after repair hops. Used by tests and optional reporting — does not mutate.
 */
final class StudioIssueScanner
{
    /**
     * @param list<ControlDocument> $documents
     * @return list<array{control:string,property:string,kind:string,snippet:string}>
     */
    public static function scan(array $documents): array
    {
        $issues = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }
                    foreach (self::classify($prop, $value, $control) as $kind) {
                        $issues[] = [
                            'control' => $control->path,
                            'property' => $prop,
                            'kind' => $kind,
                            'snippet' => self::preview($value),
                        ];
                    }
                }

                if ($control->isInteractive()) {
                    $ft = $control->getProperty('FocusedBorderThickness');
                    if ($ft === null || self::isBlankOrZero($ft)) {
                        $issues[] = [
                            'control' => $control->path,
                            'property' => 'FocusedBorderThickness',
                            'kind' => 'focus_not_showing',
                            'snippet' => $ft === null ? '(unset)' : self::preview($ft),
                        ];
                    }
                }
            }
        }
        return $issues;
    }

    /**
     * @return list<string>
     */
    public static function classify(string $prop, string $value, ControlNode $control): array
    {
        $kinds = [];
        if (FormulaLocaleNormalizer::looksLocaleCorrupted($value)) {
            $kinds[] = 'locale_separators';
        }

        $body = strtolower(trim(ltrim(trim($value), '=')));
        $boolProps = ['checked', 'default', 'value', 'reset', 'visible', 'wrap', 'autoheight', 'autowidth', 'underline', 'strikethrough', 'italic', 'bold'];
        $t = strtolower($control->type . ' ' . $control->name);
        $isChoice = str_contains($t, 'checkbox') || str_contains($t, 'toggle') || str_contains($t, 'radio') || str_contains($t, 'switch');

        if (in_array(strtolower($prop), $boolProps, true)) {
            if (in_array($body, ['1', '0', '"true"', '"false"', "'true'", "'false'"], true)) {
                if ($isChoice || strtolower($prop) === 'visible' || !in_array(strtolower($prop), ['default', 'value', 'reset', 'checked'], true)) {
                    $kinds[] = 'expecting_boolean';
                }
            }
            if (preg_match('/^if\s*\(.*,\s*[01]\s*,\s*[01]\s*\)$/is', $body)) {
                $kinds[] = 'expecting_boolean_if_numeric';
            }
        }

        return $kinds;
    }

    private static function isBlankOrZero(string $value): bool
    {
        $v = strtolower(trim(ltrim(trim($value), '=')));
        return $v === '' || $v === '0' || $v === '0.0' || $v === 'false';
    }

    public static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
    }
}
