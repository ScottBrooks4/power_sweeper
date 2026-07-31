<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\ControlDocument;
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
    /** @var array<string, string> */
    private const TYPO_MAP = [
        'GovernmentInitiave' => 'GovernmentInitiative',
        'CommercialInitiave' => 'CommercialInitiative',
        'PertinenceSpecification-' => 'PertinenceSpecification',
        '8_Pertinence-' => '8_Pertinence',
        'LeveLTopSecret' => 'LevelTopSecret',
        'Restricted0' => 'UnclassifiedRestricted',
    ];

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
        return 'Fix stale _1/_2 suffixed control names, cross-screen refs, component template bindings, and known identifier typos after screen duplication.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);
        $screens = $catalog->screenNames();

        foreach ($documents as $doc) {
            $localNames = $catalog->controlNamesForDocument($doc);
            if ($localNames === []) {
                continue;
            }

            $screen = $catalog->screenForDocument($doc);

            $doc->transformFormulas(function (string $formula, string $path) use ($catalog, $screen, $screens, $localNames, $report): string {
                $new = $this->repairGhostLayoutBinding($formula, $localNames);
                $map = $this->buildRenameMap($new, $screen, $catalog, $localNames);
                $new = $map === [] ? $new : $this->applyRenameMap($new, $map, $catalog);
                $new = ScreenReferenceNormalizer::normalize($new, $screens);
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

    /**
     * @param array<string, true> $localNames
     *
     * @return array<string, string>
     */
    private function buildRenameMap(string $formula, ?string $screen, AppControlCatalog $catalog, array $localNames): array
    {
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
            if (isset(self::TYPO_MAP[$id])) {
                $target = self::TYPO_MAP[$id];
                if ($screen !== null && $catalog->hasOnScreen($screen, $id)) {
                    continue;
                }
                if ($screen !== null && $catalog->hasOnScreen($screen, $target)) {
                    $map[$id] = $target;
                    continue;
                }
                if (isset($localNames[$target])) {
                    $map[$id] = $target;
                    continue;
                }
                if ($screen !== null) {
                    $others = array_values(array_filter(
                        $catalog->screensWith($target),
                        static fn(string $s): bool => $s !== $screen
                    ));
                    if (count($others) === 1) {
                        $map[$id] = $catalog->qualify($others[0], $target);
                        continue;
                    }
                }
                $map[$id] = $target;
                continue;
            }

            $local = $this->resolveInLocalScope($id, $localNames);
            if ($local !== null && $local !== $id) {
                $map[$id] = $local;
                continue;
            }

            if ($screen === null) {
                continue;
            }

            $resolved = $catalog->resolveIdentifier($screen, $id);
            if ($resolved !== null && $resolved !== $id && !$this->wouldOverQualifyScreen($catalog, $id, $resolved)) {
                $map[$id] = $resolved;
            }
        }

        return $map;
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
