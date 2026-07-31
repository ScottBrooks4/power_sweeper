<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Safe, mechanical rewrites for common non-delegable SharePoint / collection patterns.
 * Code segments only — comments and strings are never modified.
 */
final class DelegationFormulaRewriter
{
    public static function rewrite(string $formula): string
    {
        // Single-quoted Power Fx names stay in code; only "..." literals and comments are opaque.
        return PowerFxFormulaSegments::transformCodePreservingLiterals($formula, static function (string $code): string {
            $out = $code;

            $out = self::replaceAll($out, 'Lower(request_user.Email) = Lower(User().Email)', 'request_user.Email = User().Email');
            $out = self::replaceAll($out, 'Lower(User().Email) = Lower(request_user.Email)', 'request_user.Email = User().Email');

            $out = self::replaceAll($out, 'CountIf(colAnnex1, !IsBlank(Trim(AgencyName)))', 'CountRows(Filter(colAnnex1, !IsBlank(AgencyName)))');
            $out = self::replaceAll($out, 'CountIf(colAnnex2, !IsBlank(Trim(ParticularSurname)))', 'CountRows(Filter(colAnnex2, !IsBlank(ParticularSurname)))');

            $out = self::splitDuplicateRequestFilters($out);
            $out = self::rewriteSubstituteInFilter($out);

            return $out;
        }, false);
    }

    private static function rewriteSubstituteInFilter(string $formula): string
    {
        $space = '(?:\x00PFX\d+\x00|" ")';
        $empty = '(?:\x00PFX\d+\x00|"")';
        $pattern = '/Substitute\(IDInput\.Text,\s*' . $space . ',\s*' . $empty
            . '\)\s+in\s+Substitute\(Title,\s*' . $space . ',\s*' . $empty . '\)/';
        $replaced = preg_replace($pattern, 'StartsWith(Title, IDInput.Text)', $formula);

        return is_string($replaced) ? $replaced : $formula;
    }

    private static function splitDuplicateRequestFilters(string $formula): string
    {
        $empty = '(?:\x00PFX\d+\x00|"")';
        $patterns = [
            '/Filter\(\s*\'CDLS \(L\) VCR Tracking List\'\s*,\s*request_user\.Email = User\(\)\.Email\s*&&\s*Lower\(Trim\(Destination\)\) = locDestination\s*&&\s*Lower\(Trim\(Requestor\)\) = locRequestor\s*&&\s*Date = locDate\s*&&\s*Lower\(Trim\(Coalesce\(ReferenceNumber, ' . $empty . '\)\)\) = locReferenceNumber\s*\)/s' =>
                "Filter(\n                                Filter(\n                                    'CDLS (L) VCR Tracking List',\n                                    request_user.Email = User().Email && Date = locDate\n                                ),\n                                Lower(Trim(Destination)) = locDestination &&\n                                Lower(Trim(Requestor)) = locRequestor &&\n                                Lower(Trim(Coalesce(ReferenceNumber, \"\"))) = locReferenceNumber\n                            )",
            '/Filter\(\s*\'CDLS \(L\) VCR Tracking List\'\s*,\s*request_user\.Email = User\(\)\.Email\s*&&\s*Lower\(Trim\(\'VCR \/ VCN Form\'\.Destination\)\) = locDestination\s*&&\s*Lower\(Trim\(\'VCR \/ VCN Form\'\.Requestor\)\) = locRequestor\s*&&\s*\'VCR \/ VCN Form\'\.Date = locDate\s*&&\s*Lower\(Trim\(Coalesce\(\'VCR \/ VCN Form\'\.ReferenceNumber, ' . $empty . '\)\)\) = locReferenceNumber\s*\)/s' =>
                "Filter(\n                                Filter(\n                                    'CDLS (L) VCR Tracking List',\n                                    request_user.Email = User().Email && 'VCR / VCN Form'.Date = locDate\n                                ),\n                                Lower(Trim('VCR / VCN Form'.Destination)) = locDestination &&\n                                Lower(Trim('VCR / VCN Form'.Requestor)) = locRequestor &&\n                                Lower(Trim(Coalesce('VCR / VCN Form'.ReferenceNumber, \"\"))) = locReferenceNumber\n                            )",
        ];

        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $formula);
            if (is_string($replaced)) {
                $formula = $replaced;
            }
        }

        return $formula;
    }

    private static function replaceAll(string $haystack, string $needle, string $replacement): string
    {
        if (!str_contains($haystack, $needle)) {
            return $haystack;
        }

        return str_replace($needle, $replacement, $haystack);
    }
}
