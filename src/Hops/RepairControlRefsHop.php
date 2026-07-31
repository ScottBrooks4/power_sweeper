<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\ControlDocument;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\FormulaReferenceExtractor;
use PowerSweeper\Report;

/**
 * Repair stale suffixed control names (Foo_1 -> Foo), cross-screen copy-paste refs,
 * and component child access (NavTabs -> topNav_1.NavTabs_2).
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
        return 'Fix stale _1/_2 suffixed control names, cross-screen refs, component child access, and known identifier typos after screen duplication.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);

        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc);
            if ($screen === null) {
                continue;
            }

            $doc->transformFormulas(function (string $formula, string $path) use ($catalog, $screen, $report): string {
                $map = $this->buildRenameMap($formula, $screen, $catalog);
                if ($map === []) {
                    return $formula;
                }
                $new = $this->applyRenameMap($formula, $map);
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
     * @return array<string, string>
     */
    private function buildRenameMap(string $formula, string $screen, AppControlCatalog $catalog): array
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
            if (isset(self::TYPO_MAP[$id])) {
                $target = self::TYPO_MAP[$id];
                if ($catalog->hasOnScreen($screen, $id)) {
                    continue;
                }
                if ($catalog->hasOnScreen($screen, $target)) {
                    $map[$id] = $target;
                    continue;
                }
                $others = array_values(array_filter(
                    $catalog->screensWith($target),
                    static fn(string $s): bool => $s !== $screen
                ));
                if (count($others) === 1) {
                    $map[$id] = $catalog->qualify($others[0], $target);
                } else {
                    $map[$id] = $target;
                }
                continue;
            }

            $resolved = $catalog->resolveIdentifier($screen, $id);
            if ($resolved !== null && $resolved !== $id) {
                $map[$id] = $resolved;
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $map
     */
    private function applyRenameMap(string $formula, array $map): string
    {
        $new = $formula;
        // Quoted control names ('2_Requesting_1') must be rewritten before bare identifiers.
        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($map as $old => $replacement) {
            $quotedOld = "'" . str_replace("'", "''", $old) . "'";
            if (str_contains($replacement, '.')) {
                $quotedNew = $replacement;
            } else {
                $quotedNew = "'" . str_replace("'", "''", $replacement) . "'";
            }
            if (str_contains($new, $quotedOld)) {
                $new = str_replace($quotedOld, $quotedNew, $new);
            }
            if (str_contains($replacement, '.')) {
                $resetPattern = '/Reset\s*\(\s*' . preg_quote($quotedOld, '/') . '\s*\)/i';
                $replaced = preg_replace($resetPattern, 'Reset(' . $replacement . ')', $new);
                if (is_string($replaced)) {
                    $new = $replaced;
                }
            }
        }
        return FormulaIdentifierRewriter::rename($new, $map);
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        return strlen($s) > 160 ? substr($s, 0, 157) . '...' : $s;
    }
}
