<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ControlDocument;
use PowerSweeper\EnglishSpelling;
use PowerSweeper\Report;

/**
 * Normalize English spelling variants in UI string literals to a chosen locale.
 *
 * options.locale: us | ca | uk (also en-US, en-CA, en-GB). Default: ca (Canadian).
 *
 * Only rewrites double-quoted string contents inside formulas — control names,
 * screen names, and identifier tokens are left alone.
 */
final class SpellingConsistencySweep implements SweepInterface
{
    public static function id(): string
    {
        return 'spelling_consistency';
    }

    public static function label(): string
    {
        return 'Spelling consistency';
    }

    public static function description(): string
    {
        return 'Normalize colour/color, honour/honor, organise/organize, and similar English variants in UI text to US, Canadian, or UK spelling (options.locale).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $localeRaw = is_string($options['locale'] ?? null) ? (string) $options['locale'] : EnglishSpelling::LOCALE_CA;
        $locale = EnglishSpelling::normalizeLocale($localeRaw);
        $map = EnglishSpelling::rewriteMap($locale);
        if ($map === []) {
            return;
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($locale, $report): string {
                $fixed = EnglishSpelling::rewriteFormulaStrings($formula, $locale);
                if ($fixed === $formula) {
                    return $formula;
                }
                $report->add(self::id(), $path, 'spelling · ' . EnglishSpelling::localeLabel($locale), self::preview($formula), self::preview($fixed));
                return $fixed;
            });
        }
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        if (strlen($s) > 160) {
            return substr($s, 0, 157) . '...';
        }
        return $s;
    }
}
