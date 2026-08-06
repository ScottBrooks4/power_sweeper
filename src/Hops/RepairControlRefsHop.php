<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlRefCandidateGenerator;
use PowerSweeper\ControlTypoMap;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\FormulaReferenceExtractor;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;
use PowerSweeper\ScreenReferenceNormalizer;

/**
 * Repair stale suffixed control names (Foo_1 -> Foo), cross-screen copy-paste refs,
 * component template bindings (AutoRuleBindingString), and component child access.
 */
final class RepairControlRefsHop implements HopInterface
{
    /** Seed when discovery cannot find a form host (VCR-class apps). */
    private const FORM_HOST_SCREEN_SEED = 'VCR / VCN Form';

    public static function id(): string
    {
        return 'repair_control_refs';
    }

    public static function label(): string
    {
        return 'Repair control references';
    }

    public static function description(): string
    {
        return 'Fix stale _1/_2 suffixed control names, cross-screen refs, component template bindings, and identifier typos via catalog/fuzzy candidates (not blind replace).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);
        $screens = $catalog->screenNames();
        $candidates = new ControlRefCandidateGenerator();
        $formHost = $this->discoverFormHostScreen($catalog);

        foreach ($documents as $doc) {
            $localNames = $catalog->controlNamesForDocument($doc);
            if ($localNames === []) {
                continue;
            }

            $screen = $catalog->screenForDocument($doc);
            $isComponent = str_starts_with($doc->relativePath, 'Src/Components/');

            $relativePath = $doc->relativePath;
            $doc->transformFormulas(function (string $formula, string $path) use ($catalog, $screen, $screens, $localNames, $isComponent, $report, $candidates, $relativePath, $formHost): string {
                $new = $this->repairGhostLayoutBinding($formula, $localNames);
                $host = $this->hostControlFromPath($path, $relativePath);
                $map = $this->buildRenameMap($new, $screen, $catalog, $localNames, $candidates, $host);
                $new = $map === [] ? $new : $this->applyRenameMap($new, $map, $catalog);
                $new = ScreenReferenceNormalizer::normalize($new, $screens);
                if ($isComponent && $formHost !== null) {
                    $new = $this->qualifyFormSectionRefs($new, $catalog, $formHost);
                }
                if ($new !== $formula) {
                    $report->add(
                        self::id(),
                        $path,
                        'formula',
                        self::preview($formula),
                        self::preview($new)
                    );
                }

                return $new;
            });
        }
    }

    private function qualifyFormSectionRefs(string $formula, AppControlCatalog $catalog, string $host): string
    {
        if (!str_contains($formula, '_') && !str_contains($formula, 'Annex')) {
            return $formula;
        }

        if (!$catalog->isScreenName($host)) {
            return $formula;
        }

        $parts = PowerFxFormulaSegments::split($formula);
        $out = '';
        foreach ($parts as [$type, $text]) {
            if ($type !== 'code') {
                $out .= $text;
                continue;
            }
            $replaced = preg_replace_callback(
                "/'(\d+_[^']+)'/",
                static function (array $m) use ($catalog, $host): string {
                    $name = str_replace("''", "'", $m[1]);
                    if ($catalog->hasOnScreen($host, $name)) {
                        return "'" . str_replace("'", "''", $host) . ".'" . str_replace("'", "''", $name) . "'";
                    }

                    return $m[0];
                },
                $text
            );
            $replaced = preg_replace_callback(
                '/\b(Annex\d|EmergencyContact)\b/',
                static function (array $m) use ($catalog, $host): string {
                    if ($catalog->hasOnScreen($host, $m[1])) {
                        return $catalog->qualify($host, $m[1]);
                    }

                    return $m[0];
                },
                $replaced ?? $text
            ) ?? $text;
            $out .= $replaced;
        }

        return $out;
    }

    /**
     * Prefer the seed form screen when present; otherwise pick the screen that
     * owns the most numbered section containers / Annex* / EmergencyContact hosts.
     */
    private function discoverFormHostScreen(AppControlCatalog $catalog): ?string
    {
        if ($catalog->isScreenName(self::FORM_HOST_SCREEN_SEED)) {
            return self::FORM_HOST_SCREEN_SEED;
        }

        $best = null;
        $bestScore = 0;
        foreach ($catalog->screenNames() as $screen) {
            $score = 0;
            foreach ($catalog->controlNamesOnScreen($screen) as $name) {
                if (preg_match('/^\d+_/', $name) === 1
                    || preg_match('/^Annex\d+$/i', $name) === 1
                    || strcasecmp($name, 'EmergencyContact') === 0
                ) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $screen;
            }
        }

        return $bestScore >= 2 ? $best : null;
    }

    /**
     * @param array<string, true> $localNames
     *
     * @return array<string, string>
     */
    private function buildRenameMap(
        string $formula,
        ?string $screen,
        AppControlCatalog $catalog,
        array $localNames,
        ControlRefCandidateGenerator $candidates,
        string $hostControl,
    ): array {
        $map = [];
        $ids = FormulaReferenceExtractor::identifiers($formula);
        usort($ids, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($ids as $id) {
            if ($id === '_' || $id === '') {
                continue;
            }
            if ($catalog->isReserved($id)) {
                continue;
            }
            if (isset($localNames[$id])) {
                continue;
            }

            $local = $this->resolveInLocalScope($id, $localNames);
            if ($local !== null && $local !== $id) {
                $map[$id] = $local;
                continue;
            }

            if ($screen === null) {
                // Seed map still helps App.OnStart / component templates without a screen.
                $typo = ControlTypoMap::fix($id);
                if ($typo !== null) {
                    $map[$id] = $typo;
                }
                continue;
            }

            $resolved = $catalog->resolveIdentifier($screen, $id);
            if ($resolved !== null && $resolved !== $id && !$this->wouldOverQualifyScreen($catalog, $id, $resolved)) {
                $map[$id] = $resolved;
                continue;
            }

            // Known typo seeds (still applied as renames; context-aware hop verifies cascades).
            $typo = ControlTypoMap::fix($id);
            if ($typo !== null) {
                $target = $typo;
                if ($catalog->hasOnScreen($screen, $target) || isset($localNames[$target])) {
                    $map[$id] = $target;
                    continue;
                }
                $others = array_values(array_filter(
                    $catalog->screensWith($target),
                    static fn(string $s): bool => $s !== $screen
                ));
                if (count($others) === 1 && !$catalog->isComponentInstance($target)) {
                    $map[$id] = $catalog->qualify($others[0], $target);
                    continue;
                }
                $map[$id] = $target;
                continue;
            }

            // Token-stem only: accept a generator candidate when it is a near-identical
            // spelling of a live control (Initiave/Initiative). Skip fuzzy long-shots.
            foreach ($candidates->candidates($id, $screen, $hostControl, $localNames, $catalog) as $candidate) {
                if (str_contains($candidate, '.') || $this->wouldOverQualifyScreen($catalog, $id, $candidate)) {
                    continue;
                }
                if (!isset($localNames[$candidate]) && !$catalog->hasOnScreen($screen, $candidate)) {
                    continue;
                }
                if ($this->nearIdenticalStem($id, $candidate)) {
                    $map[$id] = $candidate;
                    break;
                }
            }
        }

        return $map;
    }

    private function nearIdenticalStem(string $from, string $to): bool
    {
        $norm = static function (string $s): string {
            return strtolower(preg_replace('/[^a-z0-9]+/i', '', $s) ?? $s);
        };
        $a = $norm($from);
        $b = $norm($to);
        if ($a === '' || $b === '' || $a === $b) {
            return $a !== '' && $a === $b;
        }
        similar_text($a, $b, $pct);
        $lenDelta = abs(strlen($a) - strlen($b));

        return $pct >= 94.0 && $lenDelta <= 2;
    }

    private function hostControlFromPath(string $path, string $relativePath): string
    {
        $prefix = $relativePath . '/';
        $rest = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
        $rest = preg_replace('/\.[A-Za-z]\w*$/', '', $rest) ?? $rest;
        $parts = explode('/', str_replace('\\', '/', $rest));

        return (string) (end($parts) ?: '');
    }

    /**
     * @param array<string, true> $localNames
     */
    private function resolveInLocalScope(string $identifier, array $localNames): ?string
    {
        if (isset($localNames[$identifier])) {
            return null;
        }

        if (preg_match('/^(.+)_(\d+)$/', $identifier, $m)) {
            $base = $m[1];
            $suffix = (int) $m[2];
            if (isset($localNames[$base])) {
                return $base;
            }

            $candidates = [];
            foreach (array_keys($localNames) as $name) {
                if (!preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $name, $mm)) {
                    continue;
                }
                $candidates[$name] = abs((int) $mm[1] - $suffix);
            }
            if ($candidates !== []) {
                asort($candidates);

                return array_key_first($candidates);
            }
        }

        return null;
    }

    /**
     * Replace layout bindings that reference controls removed from component templates.
     *
     * @param array<string, true> $localNames
     */
    private function repairGhostLayoutBinding(string $formula, array $localNames): string
    {
        $trim = trim($formula);
        $patterns = [
            '/^([A-Za-z_][\w]*)\.Y \+ \1\.Height$/' => 'Parent.Y + Parent.Height',
            '/^([A-Za-z_][\w]*)\.X \+ \1\.Width$/' => 'Parent.X + Parent.Width',
            '/^([A-Za-z_][\w]*)\.Width$/' => 'Parent.Width',
            '/^([A-Za-z_][\w]*)\.Height$/' => 'Parent.Height',
        ];
        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $trim, $m) === 1 && !isset($localNames[$m[1]])) {
                return $replacement;
            }
        }

        return $formula;
    }

    /**
     * @param array<string, string> $map
     */
    private function applyRenameMap(string $formula, array $map, AppControlCatalog $catalog): string
    {
        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $filtered = [];
        foreach ($map as $old => $replacement) {
            if ($this->wouldOverQualifyScreen($catalog, $old, $replacement)) {
                continue;
            }
            $filtered[$old] = $replacement;
        }
        if ($filtered === []) {
            return $formula;
        }

        $parts = PowerFxFormulaSegments::split($formula);
        $out = '';
        foreach ($parts as [$type, $text]) {
            if ($type === 'code') {
                foreach ($filtered as $old => $replacement) {
                    if (!str_contains($replacement, '.')) {
                        continue;
                    }
                    $quotedOld = "'" . str_replace("'", "''", $old) . "'";
                    $resetPattern = '/Reset\s*\(\s*' . preg_quote($quotedOld, '/') . '\s*\)/i';
                    $replaced = preg_replace($resetPattern, 'Reset(' . $replacement . ')', $text);
                    if (is_string($replaced)) {
                        $text = $replaced;
                    }
                }
            }
            $out .= $text;
        }

        return FormulaIdentifierRewriter::rename($out, $filtered);
    }

    private function wouldOverQualifyScreen(AppControlCatalog $catalog, string $old, string $replacement): bool
    {
        if (!$catalog->isScreenName($old)) {
            return false;
        }

        $canonical = $catalog->quoteScreen($old);

        return $replacement !== $canonical && str_contains($replacement, '.');
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;

        return strlen($s) > 160 ? substr($s, 0, 157) . '...' : $s;
    }
}
