<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Propose control-reference replacements using catalog rules, fuzzy matching,
 * copy-paste pattern inference, and local suffix alignment.
 */
final class ControlRefCandidateGenerator
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    private string $patternEpoch = '';

    /** Call when the global pattern map changes so memo keys stay small. */
    public function beginPatternEpoch(array $patternMap = []): void
    {
        $this->patternEpoch = $patternMap === []
            ? ''
            : hash('xxh128', serialize($patternMap));
        // Pattern map identity changed — prior memo entries are stale.
        $this->memo = [];
    }

    public function clearMemo(): void
    {
        $this->memo = [];
    }

    /**
     * @param array<string, true> $localNames
     * @param array<string, string> $patternMap
     * @return list<string> ordered best-first
     */
    public function candidates(
        string $badId,
        string $screen,
        string $hostControl,
        array $localNames,
        AppControlCatalog $catalog,
        array $patternMap = [],
    ): array {
        if ($badId === '' || $catalog->isReserved($badId)) {
            return [];
        }
        if (isset($localNames[$badId]) || $catalog->hasOnScreen($screen, $badId)) {
            return [];
        }

        $memoKey = $badId . "\0" . $screen . "\0" . $hostControl . "\0"
            . count($localNames) . "\0" . $this->patternEpoch;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $seen = [];
        $out = [];

        $add = static function (?string $candidate) use (&$out, &$seen, $badId): void {
            if ($candidate === null || $candidate === '' || $candidate === $badId) {
                return;
            }
            if (isset($seen[$candidate])) {
                return;
            }
            $seen[$candidate] = true;
            $out[] = $candidate;
        };

        if (isset($patternMap[$badId])) {
            $add($patternMap[$badId]);
        }

        $typo = ControlTypoMap::fix($badId);
        if ($typo !== null) {
            $add($this->resolveTypo($typo, $screen, $localNames, $catalog));
        }

        $add($catalog->resolveIdentifier($screen, $badId));
        $add($this->resolveLocalSuffix($badId, $localNames));
        $add($this->resolveHostAligned($badId, $hostControl, $localNames));
        $add($this->resolveComponentHostAlias($badId, $catalog));
        $add($this->resolveTokenStem($badId, $localNames));
        $add($this->fuzzyLocal($badId, $localNames));
        // App-wide fuzzy matching is O(controls) per bad id — skip when cheaper
        // candidates already exist (critical for THCEE-scale apps).
        if ($out === []) {
            $add($this->fuzzyAppWide($badId, $screen, $catalog));
        }
        $add($this->crossScreenQualify($badId, $screen, $catalog));

        // Bound memo growth for long request lifetimes.
        if (count($this->memo) > 8000) {
            $this->memo = [];
        }
        $this->memo[$memoKey] = $out;

        return $out;
    }

    /**
     * Map missing comTranslations / comExternalFunctions hosts onto the live
     * canvas component instance (e.g. TranslationComponent_1) by token overlap.
     */
    private function resolveComponentHostAlias(string $badId, AppControlCatalog $catalog): ?string
    {
        if ($catalog->isComponentInstance($badId)) {
            return null;
        }
        $instances = $catalog->componentInstanceNames();
        if ($instances === []) {
            return null;
        }

        $badTokens = $this->tokenizeIdentifier($badId);
        // Strip leading "com" host prefix for comparison (comTranslations → translations).
        if (($badTokens[0] ?? '') === 'com' && count($badTokens) >= 2) {
            $badTokens = array_slice($badTokens, 1);
        }
        if ($badTokens === []) {
            return null;
        }
        $norm = static fn(string $t): string => rtrim($t, 's');
        $badNorm = array_values(array_filter(array_map($norm, $badTokens), static fn(string $t): bool => strlen($t) >= 4));
        if ($badNorm === []) {
            return null;
        }
        $badStem = implode('', $badNorm);

        $best = null;
        $bestScore = 0.0;
        foreach ($instances as $inst) {
            $base = preg_replace('/_\d+$/', '', $inst) ?? $inst;
            $tokens = $this->tokenizeIdentifier($base);
            if (($tokens[0] ?? '') === 'com' && count($tokens) >= 2) {
                $tokens = array_slice($tokens, 1);
            }
            $tokNorm = array_values(array_filter(array_map($norm, $tokens), static fn(string $t): bool => strlen($t) >= 4));
            $stem = implode('', $tokNorm);
            if ($stem === '') {
                continue;
            }
            $shared = count(array_intersect($badNorm, $tokNorm));
            // comTranslations ↔ TranslationComponent: shared "translation" token.
            if ($shared >= 1) {
                return $inst;
            }
            if ($stem === $badStem || str_contains($stem, $badStem) || str_contains($badStem, $stem)) {
                return $inst;
            }
            similar_text($badStem, $stem, $pct);
            $score = $pct;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $inst;
            }
        }

        return $bestScore >= 72.0 ? $best : null;
    }

    /**
     * CamelCase / token-stem heuristic: GovernmentInitiave ≈ GovernmentInitiative
     * without relying solely on a hard-coded typo map.
     *
     * @param array<string, true> $localNames
     */
    private function resolveTokenStem(string $badId, array $localNames): ?string
    {
        $badTokens = $this->tokenizeIdentifier($badId);
        if (count($badTokens) < 2) {
            return null;
        }
        $badStem = implode('', $badTokens);
        $best = null;
        $bestScore = 0.0;

        foreach (array_keys($localNames) as $name) {
            // PHP may coerce numeric-looking control names to int array keys.
            $tokens = $this->tokenizeIdentifier((string) $name);
            if ($tokens === []) {
                continue;
            }
            $stem = implode('', $tokens);
            $name = (string) $name;
            if ($stem === $badStem) {
                return $name;
            }
            similar_text($badStem, $stem, $pct);
            // Prefer same token count and shared prefix token
            $bonus = 0.0;
            if (count($tokens) === count($badTokens)) {
                $bonus += 8.0;
            }
            if (($tokens[0] ?? '') === ($badTokens[0] ?? '')) {
                $bonus += 10.0;
            }
            $score = $pct + $bonus;
            if ($score > $bestScore && $pct >= 78.0) {
                $bestScore = $score;
                $best = $name;
            }
        }

        return $bestScore >= 88.0 ? $best : null;
    }

    /** @return list<string> */
    private function tokenizeIdentifier(string $name): array
    {
        $name = preg_replace('/[_\\-]+/', ' ', $name) ?? $name;
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name) ?? $name;
        $name = preg_replace('/(\\d+)/', ' $1 ', $name) ?? $name;
        $parts = preg_split('/\\s+/', strtolower(trim($name))) ?: [];

        return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    /**
     * @param array<string, true> $localNames
     */
    private function resolveTypo(string $target, string $screen, array $localNames, AppControlCatalog $catalog): ?string
    {
        if (isset($localNames[$target]) || $catalog->hasOnScreen($screen, $target)) {
            return $target;
        }

        $others = array_values(array_filter(
            $catalog->screensWith($target),
            static fn(string $s): bool => $s !== $screen
        ));
        if (count($others) === 1 && !$catalog->isComponentInstance($target)) {
            return $catalog->qualify($others[0], $target);
        }

        return $target;
    }

    /**
     * @param array<string, true> $localNames
     */
    private function resolveLocalSuffix(string $badId, array $localNames): ?string
    {
        if (preg_match('/^(.+)_(\d+)$/', $badId, $m)) {
            $base = $m[1];
            $suffix = (int) $m[2];
            if (isset($localNames[$base])) {
                return $base;
            }

            $best = null;
            $bestDist = PHP_INT_MAX;
            foreach (array_keys($localNames) as $name) {
                // PHP may coerce numeric-looking control names to int array keys.
                $name = (string) $name;
                if (!preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $name, $mm)) {
                    continue;
                }
                $dist = abs((int) $mm[1] - $suffix);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $name;
                }
            }

            return $best;
        }

        if (str_ends_with($badId, 'A') && strlen($badId) > 2) {
            $trimmed = substr($badId, 0, -1);
            if (isset($localNames[$trimmed])) {
                return $trimmed;
            }
            $withSuffix = $trimmed . '_1';
            if (isset($localNames[$withSuffix])) {
                return $withSuffix;
            }
        }

        if (preg_match('/^(.+)[\-_]$/', $badId, $m) && isset($localNames[$m[1]])) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param array<string, true> $localNames
     */
    private function resolveHostAligned(string $badId, string $hostControl, array $localNames): ?string
    {
        $hostIndex = self::controlIndex($hostControl);
        if ($hostIndex === null) {
            return null;
        }

        $base = self::stripIndex($badId);
        if ($base === '') {
            return null;
        }

        foreach (array_keys($localNames) as $local) {
            if (self::stripIndex($local) === $base && self::controlIndex($local) === $hostIndex) {
                return $local;
            }
        }

        if ($hostIndex === 1 && isset($localNames[$base])) {
            return $base;
        }

        return null;
    }

    /**
     * @param array<string, true> $localNames
     */
    private function fuzzyLocal(string $badId, array $localNames): ?string
    {
        $match = StringSimilarity::bestMatch($badId, array_keys($localNames), 3);

        return $match['match'] ?? null;
    }

    private function fuzzyAppWide(string $badId, string $screen, AppControlCatalog $catalog): ?string
    {
        // Same-screen only. Scanning every control in the app with levenshtein
        // for each bad identifier times out on THCEE-class packages (~5k controls).
        $pool = [];
        foreach ($catalog->controlNamesOnScreen($screen) as $name) {
            $pool[] = $name;
        }
        if ($pool === []) {
            return null;
        }

        $match = StringSimilarity::bestMatch($badId, array_values(array_unique($pool)), 2);
        if ($match === null) {
            return null;
        }

        return $match['match'];
    }

    private function crossScreenQualify(string $badId, string $screen, AppControlCatalog $catalog): ?string
    {
        $others = array_values(array_filter(
            $catalog->screensWith($badId),
            static fn(string $s): bool => $s !== $screen
        ));
        if (count($others) !== 1) {
            return null;
        }
        if ($catalog->isComponentInstance($badId)) {
            return null;
        }

        return $catalog->qualify($others[0], $badId);
    }

    private static function controlIndex(string|int $name): ?int
    {
        $name = (string) $name;
        if (preg_match('/_(\d+)$/', $name, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)$/', $name, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private static function stripIndex(string|int $name): string
    {
        $name = (string) $name;
        $base = preg_replace('/_\d+$/', '', $name) ?? $name;

        return preg_replace('/\d+$/', '', $base) ?? $base;
    }
}
