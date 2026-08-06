<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Infer control-reference rename maps from parallel formula blocks on a screen.
 *
 * Copy-pasted galleries and form rows often share the same formula shape with
 * stale identifiers (TextInput5 still references TextInput1_1). When three or
 * more siblings share a skeleton, we align numeric suffixes between the host
 * control and referenced identifiers.
 */
final class FormulaPatternAnalyzer
{
    /**
     * @param list<ControlDocument> $documents
     * @return array<string, array<string, string>> hostControl => [badIdentifier => replacement]
     */
    public static function inferPerHostRenameMap(array $documents, AppControlCatalog $catalog): array
    {
        $map = [];

        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc);
            if ($screen === null) {
                continue;
            }

            $localNames = [];
            foreach ($doc->controls() as $control) {
                $localNames[(string) $control->name] = true;
            }

            /** @var array<string, list<array{host:string,formula:string,ids:list<string>}>> */
            $groups = [];

            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }
                    if (!self::looksLikeFormulaProperty($prop)) {
                        continue;
                    }

                    $ids = FormulaReferenceExtractor::identifiers($value);
                    if ($ids === []) {
                        continue;
                    }

                    $skeleton = self::skeleton($value, $ids);
                    $key = strtolower($prop) . '|' . $skeleton;
                    $groups[$key][] = [
                        'host' => $control->name,
                        'formula' => $value,
                        'ids' => $ids,
                    ];
                }
            }

            foreach ($groups as $entries) {
                // Three+ siblings: strong copy-paste signal.
                // Two siblings: only when both hosts carry numeric indexes and
                // each produces a unique local alignment (avoids blind replace).
                $minPeers = 3;
                if (count($entries) === 2 && self::pairLooksIndexed($entries)) {
                    $minPeers = 2;
                }
                if (count($entries) < $minPeers) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $hostIndex = self::controlIndex($entry['host']);
                    if ($hostIndex === null) {
                        continue;
                    }

                    foreach ($entry['ids'] as $id) {
                        if ($catalog->isReserved($id)) {
                            continue;
                        }
                        if ($id === $entry['host']) {
                            continue;
                        }

                        $aligned = self::alignToHostIndex($id, $hostIndex, $localNames, $catalog, $screen);
                        if ($aligned === null || $aligned === $id) {
                            continue;
                        }

                        $resolved = $catalog->resolveIdentifier($screen, $id);
                        if ($resolved === $aligned) {
                            continue;
                        }

                        // For 2-peer groups, require the aligned name to exist locally
                        // (or be a single-screen qualify) before proposing.
                        if ($minPeers === 2 && !isset($localNames[$aligned]) && !str_contains($aligned, '.')) {
                            continue;
                        }

                        $map[$entry['host']][$id] = $aligned;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param list<array{host:string,formula:string,ids:list<string>}> $entries
     */
    private static function pairLooksIndexed(array $entries): bool
    {
        if (count($entries) !== 2) {
            return false;
        }
        $a = self::controlIndex($entries[0]['host']);
        $b = self::controlIndex($entries[1]['host']);

        return $a !== null && $b !== null && $a !== $b;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, string> badIdentifier => replacement
     *
     * @deprecated Use inferPerHostRenameMap for copy-paste alignment
     */
    public static function inferRenameMap(array $documents, AppControlCatalog $catalog): array
    {
        $flat = [];
        foreach (self::inferPerHostRenameMap($documents, $catalog) as $hostMap) {
            foreach ($hostMap as $from => $to) {
                $flat[$from] = $to;
            }
        }

        return $flat;
    }

    /**
     * @param array<string, true> $localNames
     */
    private static function alignToHostIndex(
        string $badId,
        int $hostIndex,
        array $localNames,
        AppControlCatalog $catalog,
        string $screen,
    ): ?string {
        $base = self::stripIndex($badId);
        if ($base === '') {
            return null;
        }

        $candidates = [];
        foreach (array_keys($localNames) as $local) {
            if (self::stripIndex($local) !== $base) {
                continue;
            }
            $idx = self::controlIndex($local);
            if ($idx === $hostIndex) {
                $candidates[] = $local;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Unsuffixed base when host is index 1
        if ($hostIndex === 1 && isset($localNames[$base])) {
            return $base;
        }

        // Cross-screen: same base+index exists elsewhere
        foreach ($catalog->screensWith($badId) as $otherScreen) {
            if ($otherScreen === $screen) {
                continue;
            }
            $target = $base . ($hostIndex > 1 ? '_' . $hostIndex : '');
            if ($catalog->hasOnScreen($otherScreen, $target)) {
                return $catalog->qualify($otherScreen, $target);
            }
            if ($hostIndex === 1 && $catalog->hasOnScreen($otherScreen, $base)) {
                return $catalog->qualify($otherScreen, $base);
            }
        }

        return null;
    }

    /**
     * @param list<string> $ids
     */
    private static function skeleton(string $formula, array $ids): string
    {
        $body = ltrim(trim($formula), '=');
        usort($ids, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($ids as $id) {
            if ($id === '') {
                continue;
            }
            $body = preg_replace('/(?<![\w.])' . preg_quote($id, '/') . '(?![\w])/', '__ID__', $body) ?? $body;
            $quoted = "'" . str_replace("'", "''", $id) . "'";
            $body = str_replace($quoted, '__ID__', $body);
        }

        return preg_replace('/\s+/', ' ', $body) ?? $body;
    }

    private static function looksLikeFormulaProperty(string $prop): bool
    {
        $lower = strtolower($prop);
        if (str_starts_with($lower, 'on')) {
            return true;
        }

        return in_array($lower, [
            'default', 'text', 'items', 'visible', 'displaymode', 'fill',
            'color', 'hinttext', 'tooltip', 'accessiblelabel', 'htmltext',
            'content', 'value', 'selected', 'checked', 'reset',
        ], true);
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
        $base = preg_replace('/\d+$/', '', $base) ?? $base;

        return $base;
    }
}
