<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\ControlNode;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\Report;

/**
 * Rename generic Studio control names (Button1, Container54_2, …) to meaningful
 * PascalCase identifiers derived from Text, AccessibleLabel, Tooltip, and child labels.
 */
final class MeaningfulNamesHop implements HopInterface
{
    public static function id(): string
    {
        return 'meaningful_names';
    }

    public static function label(): string
    {
        return 'Meaningful control names';
    }

    public static function description(): string
    {
        return 'Rename auto-generated control names using Text, AccessibleLabel, Tooltip, and child label text — aligned with accessibility naming.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $onlyGeneric = !array_key_exists('only_generic', $options) || (bool) $options['only_generic'];
        $renameScreens = (bool) ($options['rename_screens'] ?? false);

        $catalog = AppControlCatalog::build($documents);
        $renameMap = $this->buildRenameMap($documents, $catalog, $onlyGeneric, $renameScreens);
        if ($renameMap === []) {
            return;
        }

        foreach ($documents as $doc) {
            $toRename = [];
            foreach ($doc->controls() as $control) {
                if (isset($renameMap[$control->name])) {
                    $toRename[] = $control;
                }
            }
            usort($toRename, static function (ControlNode $a, ControlNode $b): int {
                return substr_count($b->path, '/') <=> substr_count($a->path, '/');
            });

            foreach ($toRename as $control) {
                $newName = $renameMap[$control->name];
                $oldName = $control->name;
                if (!$doc->renameControl($control, $newName)) {
                    unset($renameMap[$oldName]);
                    continue;
                }
                $report->add(self::id(), $control->path, 'Name', $oldName, $newName);
            }
            $doc->reindex();
        }

        $finalMap = array_filter($renameMap, static fn(string $new, string $old): bool => $old !== $new, ARRAY_FILTER_USE_BOTH);
        if ($finalMap === []) {
            return;
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(static function (string $formula) use ($finalMap): string {
                return FormulaIdentifierRewriter::rename($formula, $finalMap);
            });
        }
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, string> oldName => newName
     */
    private function buildRenameMap(
        array $documents,
        AppControlCatalog $catalog,
        bool $onlyGeneric,
        bool $renameScreens,
    ): array {
        $used = [];
        foreach ($catalog->screenNames() as $screen) {
            foreach ($catalog->controlNamesOnScreen($screen) as $name) {
                $used[$name] = true;
            }
        }

        $candidates = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    continue;
                }
                if ($control->isScreen() && !$renameScreens) {
                    continue;
                }
                if (str_contains($control->path, 'ComponentDefinitions')) {
                    continue;
                }

                $proposed = ControlNaming::proposeName($control, $onlyGeneric);
                if ($proposed === null) {
                    continue;
                }

                $candidates[] = ['control' => $control, 'proposed' => $proposed];
            }
        }

        // Deepest controls first so nested renames don't collide with parent context
        usort($candidates, static function (array $a, array $b): int {
            return substr_count($b['control']->path, '/') <=> substr_count($a['control']->path, '/');
        });

        $map = [];
        foreach ($candidates as $item) {
            /** @var ControlNode $control */
            $control = $item['control'];
            $proposed = $item['proposed'];
            $oldName = $control->name;

            if (isset($map[$oldName])) {
                continue;
            }

            $unique = $this->allocateUnique($proposed, $used, $oldName);
            if ($unique === null || strcasecmp($unique, $oldName) === 0) {
                continue;
            }

            $map[$oldName] = $unique;
            unset($used[$oldName]);
            $used[$unique] = true;
        }

        return $map;
    }

    /**
     * @param array<string, true> $used
     */
    private function allocateUnique(string $base, array &$used, string $oldName): ?string
    {
        if (!ControlNaming::isValidIdentifier($base)) {
            return null;
        }

        if (!isset($used[$base]) || $base === $oldName) {
            return $base;
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = $base . '_' . $i;
            if (strlen($candidate) > 64) {
                $candidate = substr($base, 0, max(1, 64 - strlen('_' . $i))) . '_' . $i;
            }
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
