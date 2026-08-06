<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Propose control-reference replacements using catalog rules, fuzzy matching,
 * copy-paste pattern inference, and local suffix alignment.
 */
final class ControlRefCandidateGenerator
{
    /** @var array<string, string> */
    private const TYPO_MAP = [
        'GovernmentInitiave' => 'GovernmentInitiative',
        'CommercialInitiave' => 'CommercialInitiative',
        'PertinenceSpecification-' => 'PertinenceSpecification',
        '8_Pertinence-' => '8_Pertinence',
        'LeveLTopSecret' => 'LevelTopSecret',
        'Restricted0' => 'UnclassifiedRestricted',
    ];

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

        if (isset(self::TYPO_MAP[$badId])) {
            $add($this->resolveTypo(self::TYPO_MAP[$badId], $screen, $localNames, $catalog));
        }

        $add($catalog->resolveIdentifier($screen, $badId));
        $add($this->resolveLocalSuffix($badId, $localNames));
        $add($this->resolveHostAligned($badId, $hostControl, $localNames));
        $add($this->resolveTokenStem($badId, $localNames));
        $add($this->fuzzyLocal($badId, $localNames));
        $add($this->fuzzyAppWide($badId, $screen, $catalog));
        $add($this->crossScreenQualify($badId, $screen, $catalog));

        return $out;
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
        $pool = [];
        foreach ($catalog->screensWith($badId) as $s) {
            if ($s !== $screen) {
                continue;
            }
        }
        foreach ($catalog->controlNamesOnScreen($screen) as $name) {
            $pool[] = $name;
        }
        foreach ($catalog->screenNames() as $s) {
            if ($s === $screen) {
                continue;
            }
            foreach ($catalog->controlNamesOnScreen($s) as $name) {
                if (!str_contains($name, ' ')) {
                    $pool[] = $name;
                }
            }
        }

        $match = StringSimilarity::bestMatch($badId, array_values(array_unique($pool)), 2);
        if ($match === null) {
            return null;
        }

        $found = $match['match'];
        if ($catalog->hasOnScreen($screen, $found)) {
            return $found;
        }

        $others = array_values(array_filter(
            $catalog->screensWith($found),
            static fn(string $s): bool => $s !== $screen
        ));
        if (count($others) === 1 && !$catalog->isComponentInstance($found)) {
            return $catalog->qualify($others[0], $found);
        }

        return null;
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
