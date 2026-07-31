<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\Report;

/**
 * Collapse accidental double screen qualification introduced when cross-screen
 * refs are repaired more than once: 'Screen'.'Screen'.Control -> 'Screen'.Control
 *
 * Also repairs Canvas re-save damage: triple quotes, repeated Screen.Screen.Screen,
 * and merged names like 'VCR 'VCR Home Page'.Admin Screen'.
 */
final class RepairDoubleQualifiedRefsHop implements HopInterface
{
    /** @var array<string, string> */
    private const MERGED_SCREEN_FIXES = [
        "'VCR 'VCR Home Page'.Admin Screen'" => "'VCR Admin Screen'",
        "''VCR 'VCR Home Page'.Admin Screen''" => "'VCR Admin Screen'",
        "'''VCR 'VCR Home Page'.Admin Screen'''" => "'VCR Admin Screen'",
    ];

    public static function id(): string
    {
        return 'repair_double_qualified_refs';
    }

    public static function label(): string
    {
        return 'Repair double-qualified screen refs';
    }

    public static function description(): string
    {
        return "Collapse over-qualified screen references ('Screen'.'Screen', triple quotes, Navigate/StartScreen/App.Formulas table damage).";
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);
        $screens = $catalog->screenNames();

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($screens, $report): string {
                $new = self::collapse($formula, $screens);
                if ($new !== $formula) {
                    $report->add(self::id(), $path, 'formula', '(over-qualified screen)', '(collapsed)');
                }
                return $new;
            });
        }
    }

    /**
     * @param list<string> $screens
     */
    private static function collapse(string $formula, array $screens): string
    {
        $new = $formula;
        foreach (self::MERGED_SCREEN_FIXES as $bad => $good) {
            if (str_contains($new, $bad)) {
                $new = str_replace($bad, $good, $new);
            }
        }

        foreach ($screens as $screen) {
            $new = self::collapseScreenRepeats($new, $screen);
            $new = self::stripExcessQuotesAroundScreen($new, $screen);
        }

        // Canvas/YAML merge artifact: duplicate line prefixed with =
        $new = self::dedupeLeadingEqualsLine($new);
        $new = self::dedupeRepeatedFormula($new);
        $new = self::quoteNumericControlMembers($new);

        return $new;
    }

    private static function dedupeRepeatedFormula(string $formula): string
    {
        $trim = trim($formula);
        $len = strlen($trim);
        if ($len < 40 || $len % 2 !== 0) {
            return $formula;
        }
        $half = (int) ($len / 2);
        if (substr($trim, 0, $half) === substr($trim, $half)) {
            return substr($trim, 0, $half);
        }

        return $formula;
    }

    /** 'Screen'.8_Foo -> 'Screen'.'8_Foo' */
    private static function quoteNumericControlMembers(string $formula): string
    {
        $replaced = preg_replace("/(\'(?:[^']|'')+')\.(\d[\w]*)/", "$1.'$2'", $formula);
        return is_string($replaced) ? $replaced : $formula;
    }

    private static function dedupeLeadingEqualsLine(string $formula): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $formula) ?: [$formula];
        if (count($lines) < 2) {
            return $formula;
        }
        $first = ltrim(trim($lines[0]), '=');
        foreach (array_slice($lines, 1) as $line) {
            if (ltrim(trim($line), '=') === $first) {
                return $lines[0];
            }
        }

        return $formula;
    }

    private static function stripExcessQuotesAroundScreen(string $formula, string $screen): string
    {
        $q = "'" . str_replace("'", "''", $screen) . "'";
        $inner = preg_quote(str_replace("'", "''", $screen), '/');
        $replaced = preg_replace("/'{2,}{$inner}'{2,}/", $q, $formula);
        return is_string($replaced) ? $replaced : $formula;
    }

    private static function collapseScreenRepeats(string $formula, string $screen): string
    {
        $q = "'" . str_replace("'", "''", $screen) . "'";
        $inner = str_replace("'", "''", $screen);

        $patterns = [
            "'''" . $inner . "''.''" . $inner . "'''" => $q,
            "'''" . $inner . "''.''" . $inner . "'''.'''" . $inner . "''.''" . $inner . "'''" => $q,
            "''" . $inner . "''.''" . $inner . "''" => $q,
            $q . '.' . $q . '.' . $q => $q,
            $q . '.' . $q => $q,
        ];

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($patterns as $bad => $good) {
                if (str_contains($formula, $bad)) {
                    $formula = str_replace($bad, $good, $formula);
                    $changed = true;
                }
            }
            // Navigate('Screen'.'Screen', -> Navigate('Screen',
            $navDup = "Navigate(" . $q . "." . $q;
            if (str_contains($formula, $navDup)) {
                $formula = str_replace($navDup, "Navigate(" . $q, $formula);
                $changed = true;
            }
        }

        return $formula;
    }
}
