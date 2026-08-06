<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Safe, mechanical rewrites for common non-delegable SharePoint / collection patterns.
 * Pattern-based (not app-specific string tables). Code segments only — comments/strings opaque.
 */
final class DelegationFormulaRewriter
{
    public static function rewrite(string $formula): string
    {
        // Single-quoted Power Fx names stay in code; only "..." literals and comments are opaque.
        return PowerFxFormulaSegments::transformCodePreservingLiterals($formula, static function (string $code): string {
            $out = $code;
            $out = self::rewriteLowerEmailCompare($out);
            $out = self::rewriteCountIfTrimBlank($out);
            $out = self::rewriteSubstituteInFilter($out);
            $out = self::splitDateEmailFilters($out);

            return $out;
        }, false);
    }

    /**
     * Lower(x.Email) = Lower(User().Email) (either order) → x.Email = User().Email
     */
    private static function rewriteLowerEmailCompare(string $formula): string
    {
        $emailRef = '((?:\'[^\']+\'|[A-Za-z_][\w]*)(?:\.(?:\'[^\']+\'|[A-Za-z_][\w]*))?\.Email|[A-Za-z_][\w]*\.Email)';
        $patterns = [
            '/Lower\(\s*' . $emailRef . '\s*\)\s*=\s*Lower\(\s*User\(\)\.Email\s*\)/i' => '$1 = User().Email',
            '/Lower\(\s*User\(\)\.Email\s*\)\s*=\s*Lower\(\s*' . $emailRef . '\s*\)/i' => '$1 = User().Email',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $formula);
            if (is_string($replaced)) {
                $formula = $replaced;
            }
        }

        return $formula;
    }

    /**
     * CountIf(col, !IsBlank(Trim(Field))) → CountRows(Filter(col, !IsBlank(Field)))
     */
    private static function rewriteCountIfTrimBlank(string $formula): string
    {
        $replaced = preg_replace(
            '/CountIf\(\s*([A-Za-z_][\w]*)\s*,\s*!IsBlank\(\s*Trim\(\s*([A-Za-z_][\w]*)\s*\)\s*\)\s*\)/i',
            'CountRows(Filter($1, !IsBlank($2)))',
            $formula,
        );

        return is_string($replaced) ? $replaced : $formula;
    }

    /**
     * Substitute(Control.Text, " ", "") in Substitute(Title, …) → StartsWith(Title, Control.Text)
     */
    private static function rewriteSubstituteInFilter(string $formula): string
    {
        $space = '(?:\x00PFX\d+\x00|" ")';
        $empty = '(?:\x00PFX\d+\x00|"")';
        $pattern = '/Substitute\(\s*([A-Za-z_][\w]*\.Text)\s*,\s*' . $space . '\s*,\s*' . $empty
            . '\s*\)\s+in\s+Substitute\(\s*Title\s*,\s*' . $space . '\s*,\s*' . $empty . '\s*\)/i';
        $replaced = preg_replace($pattern, 'StartsWith(Title, $1)', $formula);

        return is_string($replaced) ? $replaced : $formula;
    }

    /**
     * Split Filter(list, emailEq && dateEq && Lower(Trim…)…) into nested Filters so the
     * email+date predicates stay delegable on SharePoint.
     */
    private static function splitDateEmailFilters(string $formula): string
    {
        $empty = '(?:\x00PFX\d+\x00|"")';
        // Keep classic VCR shapes as high-confidence seeds (whitespace-tolerant via prior passes).
        $seeds = [
            '/Filter\(\s*\'CDLS \(L\) VCR Tracking List\'\s*,\s*request_user\.Email = User\(\)\.Email\s*&&\s*Lower\(Trim\(Destination\)\) = locDestination\s*&&\s*Lower\(Trim\(Requestor\)\) = locRequestor\s*&&\s*Date = locDate\s*&&\s*Lower\(Trim\(Coalesce\(ReferenceNumber, ' . $empty . '\)\)\) = locReferenceNumber\s*\)/s' =>
                "Filter(\n                                Filter(\n                                    'CDLS (L) VCR Tracking List',\n                                    request_user.Email = User().Email && Date = locDate\n                                ),\n                                Lower(Trim(Destination)) = locDestination &&\n                                Lower(Trim(Requestor)) = locRequestor &&\n                                Lower(Trim(Coalesce(ReferenceNumber, \"\"))) = locReferenceNumber\n                            )",
            '/Filter\(\s*\'CDLS \(L\) VCR Tracking List\'\s*,\s*request_user\.Email = User\(\)\.Email\s*&&\s*Lower\(Trim\(\'VCR \/ VCN Form\'\.Destination\)\) = locDestination\s*&&\s*Lower\(Trim\(\'VCR \/ VCN Form\'\.Requestor\)\) = locRequestor\s*&&\s*\'VCR \/ VCN Form\'\.Date = locDate\s*&&\s*Lower\(Trim\(Coalesce\(\'VCR \/ VCN Form\'\.ReferenceNumber, ' . $empty . '\)\)\) = locReferenceNumber\s*\)/s' =>
                "Filter(\n                                Filter(\n                                    'CDLS (L) VCR Tracking List',\n                                    request_user.Email = User().Email && 'VCR / VCN Form'.Date = locDate\n                                ),\n                                Lower(Trim('VCR / VCN Form'.Destination)) = locDestination &&\n                                Lower(Trim('VCR / VCN Form'.Requestor)) = locRequestor &&\n                                Lower(Trim(Coalesce('VCR / VCN Form'.ReferenceNumber, \"\"))) = locReferenceNumber\n                            )",
        ];
        foreach ($seeds as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $formula);
            if (is_string($replaced)) {
                $formula = $replaced;
            }
        }

        // General: Filter('List', email && … && date && Lower(Trim…)…)
        $general = preg_replace_callback(
            '/Filter\(\s*(\'[^\']+\')\s*,\s*((?:[^()]|\([^()]*\))+?)\)/s',
            static function (array $m): string {
                $list = $m[1];
                $pred = trim($m[2]);
                if (preg_match('/^\s*Filter\s*\(/i', $pred)) {
                    return $m[0];
                }
                if (!preg_match('/\.Email\s*=\s*User\(\)\.Email/', $pred)) {
                    return $m[0];
                }
                if (!preg_match('/(?:^|&&)\s*((?:\'[^\']+\'\.)?Date\s*=\s*locDate)/', $pred)) {
                    return $m[0];
                }
                if (!preg_match('/Lower\s*\(\s*Trim\s*\(/i', $pred)) {
                    return $m[0];
                }

                $parts = preg_split('/\s*&&\s*/', $pred) ?: [];
                if (count($parts) < 3) {
                    return $m[0];
                }

                $inner = [];
                $outer = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    if (preg_match('/\.Email\s*=\s*User\(\)\.Email/', $part)
                        || preg_match('/(?:\'[^\']+\'\.)?Date\s*=\s*locDate/', $part)
                    ) {
                        $inner[] = $part;
                    } else {
                        $outer[] = $part;
                    }
                }
                if ($inner === [] || $outer === []) {
                    return $m[0];
                }

                return 'Filter('
                    . "Filter({$list}, " . implode(' && ', $inner) . '), '
                    . implode(' && ', $outer)
                    . ')';
            },
            $formula,
        );

        return is_string($general) ? $general : $formula;
    }
}
