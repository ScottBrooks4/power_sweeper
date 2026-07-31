<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Idempotent normalization of canvas screen references in Power Fx.
 *
 * Rules (canonical forms):
 * - Navigate / Screen: table fields / StartScreen branches: 'Screen Name' (one quoted screen)
 * - Cross-screen control access: 'Screen Name'.Control or 'Screen Name'.'8_Section'
 * - Never: 'Screen'.'Screen', triple quotes, or merged corruption tokens
 *
 * Safe to run multiple times — output stabilizes after the first pass.
 */
final class ScreenReferenceNormalizer
{
    /**
     * @param list<string> $screenNames canvas screen display names
     */
    public static function normalize(string $formula, array $screenNames): string
    {
        if ($formula === '' || $screenNames === []) {
            return $formula;
        }

        $screens = self::sortScreensLongestFirst($screenNames);
        $formula = FormulaArtifactCleaner::clean($formula);
        $formula = self::repairWholeQuotedLiteral($formula, $screens);
        $formula = self::repairCorruptedScreenLiterals($formula, $screens);

        foreach ($screens as $screen) {
            $formula = self::stripExcessQuotesAroundScreen($formula, $screen);
            $formula = self::normalizeMemberChains($formula, $screen);
        }

        $formula = self::normalizeNavigateFirstArguments($formula, $screens);
        $formula = self::ensureNumericControlMemberQuotes($formula);

        return $formula;
    }

    /**
     * @param list<string> $screens
     * @return list<string>
     */
    private static function sortScreensLongestFirst(array $screens): array
    {
        $unique = array_values(array_unique($screens));
        usort($unique, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $unique;
    }

    /**
     * When the entire formula is one quoted literal (App.Formulas tables), repair in place.
     *
     * @param list<string> $screens
     */
    private static function repairWholeQuotedLiteral(string $formula, array $screens): string
    {
        $trim = trim($formula);
        if (!str_starts_with($trim, "'") || !str_ends_with($trim, "'") || strlen($trim) < 2) {
            return $formula;
        }

        if (preg_match("/^'((?:[^']|'')+)'$/", $trim, $m)) {
            $literal = str_replace("''", "'", $m[1]);
            if (in_array($literal, $screens, true)) {
                return self::quote($literal);
            }
            $repaired = self::repairMergedScreenLiteral($literal, $screens);

            return $repaired !== null ? self::quote($repaired) : $formula;
        }

        // Malformed nested quotes: 'VCR 'VCR Home Page'.Admin Screen'
        $literal = str_replace("''", "'", substr($trim, 1, -1));
        $repaired = self::repairMergedScreenLiteral($literal, $screens);
        if ($repaired !== null) {
            return self::quote($repaired);
        }
        if (in_array($literal, $screens, true)) {
            return self::quote($literal);
        }

        return $formula;
    }

    /**
     * Replace corrupted single-quoted tokens that embed partial qualifications
     * with the best matching catalog screen (longest substring match).
     *
     * @param list<string> $screens
     */
    private static function repairCorruptedScreenLiterals(string $formula, array $screens): string
    {
        $parts = PowerFxFormulaSegments::splitForStructure($formula);

        return PowerFxFormulaSegments::mapCode($parts, static function (string $code) use ($screens): string {
            return preg_replace_callback(
                "/'((?:[^']|'')+)'/",
                static function (array $m) use ($screens): string {
                    $literal = str_replace("''", "'", $m[1]);
                    if (in_array($literal, $screens, true)) {
                        return self::quote($literal);
                    }
                    if (!str_contains($literal, '.') && !str_contains($literal, ' ')) {
                        return $m[0];
                    }
                    $repaired = self::repairMergedScreenLiteral($literal, $screens);
                    if ($repaired !== null) {
                        return self::quote($repaired);
                    }

                    return $m[0];
                },
                $code
            ) ?? $code;
        });
    }

    /**
     * Collapse member chains whose leading segments all denote the same screen.
     */
    private static function normalizeMemberChains(string $formula, string $screen): string
    {
        $canonical = self::quote($screen);
        $pattern = "/(?:'(?:[^']|'')+'|[A-Za-z_][\w]*)(?:\.(?:'(?:[^']|'')+'|[A-Za-z_][\w]*))+/";

        $parts = PowerFxFormulaSegments::splitForStructure($formula);

        return PowerFxFormulaSegments::mapCode($parts, static function (string $code) use ($pattern, $screen, $canonical): string {
            return preg_replace_callback(
                $pattern,
                static function (array $m) use ($screen, $canonical): string {
                    $chain = $m[0];
                    $segments = self::splitMemberChain($chain);
                    if ($segments === []) {
                        return $chain;
                    }

                    $unquoted = array_map(self::unquoteToken(...), $segments);
                    $screenRun = 0;
                    foreach ($unquoted as $seg) {
                        if ($seg !== $screen) {
                            break;
                        }
                        $screenRun++;
                    }

                    if ($screenRun < 2) {
                        return $chain;
                    }

                    if ($screenRun === count($unquoted)) {
                        return $canonical;
                    }

                    $controlSegments = array_slice($segments, $screenRun);

                    return $canonical . '.' . implode('.', $controlSegments);
                },
                $code
            ) ?? $code;
        });
    }

    /**
     * Navigate('Screen'.'Screen', ...) -> Navigate('Screen', ...)
     *
     * @param list<string> $screens
     */
    private static function normalizeNavigateFirstArguments(string $formula, array $screens): string
    {
        $parts = PowerFxFormulaSegments::splitForStructure($formula);

        return PowerFxFormulaSegments::mapCode($parts, static function (string $code) use ($screens): string {
            return preg_replace_callback(
                '/\bNavigate\s*\(\s*([^,;]+)/i',
                static function (array $m) use ($screens): string {
                    $arg = trim($m[1]);
                    foreach ($screens as $screen) {
                        $canonical = self::quote($screen);
                        $segments = self::splitMemberChain($arg);
                        if ($segments === []) {
                            continue;
                        }
                        $unquoted = array_map(self::unquoteToken(...), $segments);
                        if ($unquoted !== [] && count(array_filter($unquoted, static fn(string $s): bool => $s === $screen)) === count($unquoted)) {
                            return 'Navigate(' . $canonical;
                        }
                    }

                    return $m[0];
                },
                $code
            ) ?? $code;
        });
    }

    /** 'Screen'.8_Foo -> 'Screen'.'8_Foo' */
    private static function ensureNumericControlMemberQuotes(string $formula): string
    {
        $parts = PowerFxFormulaSegments::splitForStructure($formula);
        $out = PowerFxFormulaSegments::mapCode($parts, static function (string $code): string {
            $replaced = preg_replace("/(\'(?:[^']|'')+')\.(\d[\w]*)/", "$1.'$2'", $code);

            return is_string($replaced) ? $replaced : $code;
        });

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function splitMemberChain(string $chain): array
    {
        $segments = [];
        $current = '';
        $len = strlen($chain);
        $i = 0;
        while ($i < $len) {
            if ($chain[$i] === "'") {
                if ($current !== '') {
                    $segments[] = $current;
                    $current = '';
                }
                $j = $i + 1;
                while ($j < $len) {
                    if ($chain[$j] === "'") {
                        if (($chain[$j + 1] ?? '') === "'") {
                            $j += 2;
                            continue;
                        }
                        $j++;
                        break;
                    }
                    $j++;
                }
                $segments[] = substr($chain, $i, $j - $i);
                $i = $j;
                if (($chain[$i] ?? '') === '.') {
                    $i++;
                }
                continue;
            }
            if ($chain[$i] === '.') {
                if ($current !== '') {
                    $segments[] = $current;
                    $current = '';
                }
                $i++;
                continue;
            }
            $current .= $chain[$i];
            $i++;
        }
        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * Detect merged corruption like VCR 'VCR Home Page'.Admin Screen -> VCR Admin Screen.
     *
     * @param list<string> $screens
     */
    private static function repairMergedScreenLiteral(string $literal, array $screens): ?string
    {
        if (!str_contains($literal, '.') && !str_contains($literal, ' ')) {
            return null;
        }

        if (preg_match("/^(.+?) '(?:[^']|'')+'\.(.+)$/", $literal, $m) === 1) {
            $candidate = trim($m[1] . ' ' . $m[2]);
            if (in_array($candidate, $screens, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function stripExcessQuotesAroundScreen(string $formula, string $screen): string
    {
        $q = self::quote($screen);
        if (!str_starts_with($q, "'")) {
            return $formula;
        }

        $inner = preg_quote(str_replace("'", "''", $screen), '/');
        $parts = PowerFxFormulaSegments::splitForStructure($formula);

        return PowerFxFormulaSegments::mapCode($parts, static function (string $code) use ($inner, $q): string {
            $replaced = preg_replace("/'{2,}{$inner}'{2,}/", $q, $code);

            return is_string($replaced) ? $replaced : $code;
        });
    }

    private static function unquoteToken(string $token): string
    {
        $t = trim($token);
        while (strlen($t) >= 2 && str_starts_with($t, "'") && str_ends_with($t, "'")) {
            $inner = substr($t, 1, -1);
            $t = str_replace("''", "'", $inner);
        }

        return $t;
    }

    public static function quote(string $name): string
    {
        if (preg_match('/^[A-Za-z_][\w]*$/', $name)) {
            return $name;
        }

        return "'" . str_replace("'", "''", $name) . "'";
    }
}
