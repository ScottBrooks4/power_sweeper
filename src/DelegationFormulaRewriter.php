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
        $offset = 0;
        $out = '';
        $len = strlen($formula);
        while ($offset < $len) {
            if (!preg_match('/Filter\s*\(\s*(\'[^\']+\')\s*,/i', $formula, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $out .= substr($formula, $offset);
                break;
            }
            $matchStart = (int) $m[0][1];
            $list = $m[1][0];
            $out .= substr($formula, $offset, $matchStart - $offset);
            $predStart = $matchStart + strlen($m[0][0]);
            $pred = self::readBalancedArgs($formula, $predStart);
            if ($pred === null) {
                $out .= substr($formula, $matchStart, $predStart - $matchStart);
                $offset = $predStart;
                continue;
            }
            [$predText, $endPos] = $pred;
            $original = substr($formula, $matchStart, $endPos - $matchStart);
            $replaced = self::tryNestFilter($list, trim($predText));
            $out .= $replaced ?? $original;
            $offset = $endPos;
        }

        return $out;
    }

    /**
     * Read arguments until the closing ')' of the Filter call (handles nested parens).
     *
     * @return null|array{0:string,1:int} [predicate text, position after closing paren]
     */
    private static function readBalancedArgs(string $formula, int $start): ?array
    {
        $depth = 1;
        $len = strlen($formula);
        for ($i = $start; $i < $len; $i++) {
            $ch = $formula[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return [substr($formula, $start, $i - $start), $i + 1];
                }
            }
        }

        return null;
    }

    private static function tryNestFilter(string $list, string $pred): ?string
    {
        if (preg_match('/^\s*Filter\s*\(/i', $pred)) {
            return null;
        }
        if (!preg_match('/\.Email\s*=\s*User\(\)\.Email/', $pred)) {
            return null;
        }
        // Date = <local ident> (locDate, varDate, selectedDate, …)
        if (!preg_match('/(?:\'[^\']+\'\.)?Date\s*=\s*[A-Za-z_][\w]*/', $pred)) {
            return null;
        }
        if (!preg_match('/Lower\s*\(\s*Trim\s*\(/i', $pred)) {
            return null;
        }

        $parts = preg_split('/\s*&&\s*/', $pred) ?: [];
        if (count($parts) < 3) {
            return null;
        }

        $inner = [];
        $outer = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/\.Email\s*=\s*User\(\)\.Email/', $part)
                || preg_match('/(?:\'[^\']+\'\.)?Date\s*=\s*[A-Za-z_][\w]*/', $part)
            ) {
                $inner[] = $part;
            } else {
                $outer[] = $part;
            }
        }
        if ($inner === [] || $outer === []) {
            return null;
        }

        return 'Filter('
            . "Filter({$list}, " . implode(' && ', $inner) . '), '
            . implode(' && ', $outer)
            . ')';
    }
}
