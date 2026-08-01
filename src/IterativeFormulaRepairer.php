<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Context-aware formula repair: propose fixes from patterns and catalog context,
 * try each candidate, and keep changes only when our live checker error count drops.
 */
final class IterativeFormulaRepairer
{
    private readonly ControlRefCandidateGenerator $generator;

    public function __construct(
        private readonly AppDataContext $dataContext,
        ?ControlRefCandidateGenerator $generator = null,
    ) {
        $this->generator = $generator ?? new ControlRefCandidateGenerator();
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array{iterations:int,repairs:int,pattern_map_size:int}
     */
    public function repair(array $documents, array $options = []): array
    {
        $maxIterations = max(1, (int) ($options['max_iterations'] ?? 5));
        $catalog = AppControlCatalog::build($documents);
        $perHostPatternMap = FormulaPatternAnalyzer::inferPerHostRenameMap($documents, $catalog);
        $patternMap = FormulaPatternAnalyzer::inferRenameMap($documents, $catalog);
        $checker = new PowerFxFormulaChecker($catalog, $this->dataContext);
        $totalRepairs = 0;

        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $iterRepairs = 0;

            foreach ($documents as $doc) {
                $screen = $catalog->screenForDocument($doc);
                if ($screen === null) {
                    continue;
                }

                $localNames = [];
                foreach ($doc->controls() as $control) {
                    $localNames[(string) $control->name] = true;
                }
                $screenRecordVars = FormulaRefContext::recordVariableNames(
                    $this->collectFormulaText($doc)
                );

                $doc->transformFormulas(function (string $formula, string $path) use (
                    $checker,
                    $catalog,
                    $screen,
                    $localNames,
                    $screenRecordVars,
                    $patternMap,
                    $perHostPatternMap,
                    &$iterRepairs,
                    $doc,
                ): string {
                    [$controlName, $property] = $this->parsePath($path, $doc);
                    if ($controlName === null || $property === null) {
                        return $formula;
                    }

                    $hostPatternMap = $perHostPatternMap[$controlName] ?? [];
                    if ($hostPatternMap === [] && !$this->quickNeedsWork($formula, $screen, $controlName, $localNames, $catalog)) {
                        return $formula;
                    }

                    $controlType = $this->controlTypeFor($doc, $controlName);
                    $location = $screen . '.' . $controlName . '.' . $property;

                    $badIds = $this->badIdentifiers(
                        $formula,
                        $screen,
                        $controlName,
                        $localNames,
                        $catalog,
                        $hostPatternMap,
                    );
                    if ($badIds === [] && $hostPatternMap === []) {
                        return $formula;
                    }

                    $beforeScore = $this->scoreFormula(
                        $checker,
                        $formula,
                        $screen,
                        $location,
                        $controlType,
                        $property,
                        $controlName,
                        $localNames,
                        $screenRecordVars,
                        $hostPatternMap,
                        $catalog,
                    );

                    $original = $formula;
                    $map = [];
                    foreach (array_keys($hostPatternMap + array_fill_keys($badIds, true)) as $badId) {
                        if (isset($map[$badId])) {
                            continue;
                        }

                        if (isset($hostPatternMap[$badId])) {
                            $candidate = $hostPatternMap[$badId];
                            $trial = $this->applyMap($formula, [$badId => $candidate], $catalog);
                            if ($this->isImprovement(
                                $checker,
                                $formula,
                                $trial,
                                $screen,
                                $location,
                                $controlType,
                                $property,
                                $controlName,
                                $localNames,
                                $screenRecordVars,
                                $hostPatternMap,
                                $catalog,
                            )) {
                                $map[$badId] = $candidate;
                                $formula = $trial;
                                continue;
                            }
                        }

                        $candidates = $this->generator->candidates(
                            $badId,
                            $screen,
                            $controlName,
                            $localNames,
                            $catalog,
                            $patternMap,
                        );

                        $best = null;
                        $bestCount = $beforeScore;
                        foreach ($candidates as $candidate) {
                            if ($this->wouldOverQualifyScreen($catalog, $badId, $candidate)) {
                                continue;
                            }
                            $trialMap = $map;
                            $trialMap[$badId] = $candidate;
                            $trial = $this->applyMap($formula, $trialMap, $catalog);
                            if ($this->isImprovement(
                                $checker,
                                $formula,
                                $trial,
                                $screen,
                                $location,
                                $controlType,
                                $property,
                                $controlName,
                                $localNames,
                                $screenRecordVars,
                                $hostPatternMap,
                                $catalog,
                            )) {
                                $best = $candidate;
                                $bestCount = $this->scoreFormula(
                                    $checker,
                                    $trial,
                                    $screen,
                                    $location,
                                    $controlType,
                                    $property,
                                    $controlName,
                                    $localNames,
                                    $screenRecordVars,
                                    $hostPatternMap,
                                    $catalog,
                                );
                            }
                            if ($bestCount <= 0) {
                                break;
                            }
                        }

                        if ($best !== null) {
                            $map[$badId] = $best;
                            $formula = $this->applyMap($formula, [$badId => $best], $catalog);
                        }
                    }

                    if ($map === [] && $formula === $original) {
                        return $formula;
                    }

                    if ($formula !== $original) {
                        $iterRepairs++;
                    }

                    return $formula;
                });
            }

            $totalRepairs += $iterRepairs;
            if ($iterRepairs === 0) {
                return [
                    'iterations' => $iter + 1,
                    'repairs' => $totalRepairs,
                    'pattern_map_size' => count($patternMap),
                ];
            }

            // Rebuild catalog after formula repairs may unlock further resolutions.
            $catalog = AppControlCatalog::build($documents);
            $checker = new PowerFxFormulaChecker($catalog, $this->dataContext);
            $perHostPatternMap = FormulaPatternAnalyzer::inferPerHostRenameMap($documents, $catalog);
            $patternMap = FormulaPatternAnalyzer::inferRenameMap($documents, $catalog);
        }

        return [
            'iterations' => $maxIterations,
            'repairs' => $totalRepairs,
            'pattern_map_size' => count($patternMap),
        ];
    }

    /**
     * @param array<string, string> $hostPatternMap
     * @param array<string, true> $localNames
     */
    private function isImprovement(
        PowerFxFormulaChecker $checker,
        string $before,
        string $after,
        string $screen,
        string $location,
        string $controlType,
        string $property,
        string $controlName,
        array $localNames,
        array $screenRecordVars,
        array $hostPatternMap,
        AppControlCatalog $catalog,
    ): bool {
        if ($before === $after) {
            return false;
        }

        $beforeScore = $this->scoreFormula(
            $checker,
            $before,
            $screen,
            $location,
            $controlType,
            $property,
            $controlName,
            $localNames,
            $screenRecordVars,
            $hostPatternMap,
            $catalog,
        );
        $afterScore = $this->scoreFormula(
            $checker,
            $after,
            $screen,
            $location,
            $controlType,
            $property,
            $controlName,
            $localNames,
            $screenRecordVars,
            $hostPatternMap,
            $catalog,
        );

        return $afterScore < $beforeScore;
    }

    /**
     * Lower is better. Pattern misalignments add weight even when Studio would not error.
     *
     * @param array<string, string> $hostPatternMap
     * @param array<string, true> $localNames
     */
    private function scoreFormula(
        PowerFxFormulaChecker $checker,
        string $formula,
        string $screen,
        string $location,
        string $controlType,
        string $property,
        string $controlName,
        array $localNames,
        array $screenRecordVars,
        array $hostPatternMap,
        AppControlCatalog $catalog,
    ): int {
        $score = $this->countRefErrors(
            $checker,
            $formula,
            $screen,
            $location,
            $controlType,
            $property,
            $controlName,
            $localNames,
            $screenRecordVars,
        );

        foreach ($hostPatternMap as $badId => $expected) {
            if (!str_contains($formula, $badId)) {
                continue;
            }
            $score += 10;
            if (str_contains($formula, $expected)) {
                $score -= 9;
            }
        }

        foreach (FormulaReferenceExtractor::identifiers($formula) as $id) {
            if (isset($localNames[$id]) || $catalog->isReserved($id)) {
                continue;
            }
            $resolved = $catalog->resolveIdentifier($screen, $id);
            if ($resolved !== null && $resolved !== $id && !str_contains($formula, $resolved . '.')) {
                if (preg_match('/(?<![\w.])' . preg_quote($id, '/') . '(?![\w])/', $formula) === 1) {
                    $score += 5;
                }
            }
        }

        return max(0, $score);
    }

    /**
     * @param array<string, true> $localNames
     * @return list<string>
     */
    private function badIdentifiers(
        string $formula,
        string $screen,
        string $controlName,
        array $localNames,
        AppControlCatalog $catalog,
        array $hostPatternMap = [],
    ): array {
        $bad = [];
        foreach (FormulaReferenceExtractor::identifiers($formula) as $id) {
            if ($id === $controlName || $id === '_' || preg_match('/^_\d+$/', $id)) {
                continue;
            }
            if (isset($localNames[$id])) {
                continue;
            }
            if ($catalog->isReserved($id)) {
                continue;
            }
            if (preg_match('/^(var|col|gbl)[A-Z]/', $id)) {
                continue;
            }
            if (isset($hostPatternMap[$id])) {
                $bad[] = $id;
                continue;
            }
            if ($catalog->hasOnScreen($screen, $id)) {
                continue;
            }

            $resolved = $catalog->resolveIdentifier($screen, $id);
            $aligned = $this->generator->candidates(
                $id,
                $screen,
                $controlName,
                $localNames,
                $catalog,
            )[0] ?? null;
            if ($resolved !== null && $resolved !== $id && !isset($localNames[$id])) {
                $bad[] = $id;
                continue;
            }
            if ($resolved !== null && $aligned !== null && $aligned !== $resolved && $aligned !== $id) {
                $bad[] = $id;
                continue;
            }
            if ($resolved !== null) {
                continue;
            }
            if (FormulaRefContext::hasBareCrossScreenControlRef($formula, $id, $screen, $catalog)) {
                $bad[] = $id;
                continue;
            }
            if (preg_match('/_\d+$/', $id) || str_ends_with($id, '-') || str_contains($id, 'Initiave')) {
                $bad[] = $id;
            }
        }

        return array_values(array_unique($bad));
    }

    /**
     * @param array<string, true> $localNames
     */
    private function countRefErrors(
        PowerFxFormulaChecker $checker,
        string $formula,
        string $screen,
        string $location,
        string $controlType,
        string $property,
        string $controlName,
        array $localNames,
        array $screenRecordVars,
    ): int {
        $findings = $checker->check(
            $formula,
            $screen,
            $location,
            $controlType,
            $property,
            $controlName,
            $localNames,
            $screenRecordVars,
        );

        $count = 0;
        foreach ($findings as $finding) {
            if (!in_array($finding['ruleId'], ['app-ErrInvalidName', 'app-ErrInvalidDot', 'app-formula-mangled-screen-ref'], true)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, string> $map
     */
    private function applyMap(string $formula, array $map, AppControlCatalog $catalog): string
    {
        if ($map === []) {
            return $formula;
        }

        uksort($map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $next = ScreenReferenceNormalizer::normalize($formula, $catalog->screenNames());

        return FormulaIdentifierRewriter::rename($next, $map);
    }

    private function wouldOverQualifyScreen(AppControlCatalog $catalog, string $old, string $replacement): bool
    {
        if (!$catalog->isScreenName($old)) {
            return false;
        }

        $canonical = $catalog->quoteScreen($old);

        return $replacement !== $canonical && str_contains($replacement, '.');
    }

    /**
     * Fast pre-filter — avoids running the live checker on clean formulas.
     *
     * @param array<string, true> $localNames
     */
    private function quickNeedsWork(
        string $formula,
        string $screen,
        string $controlName,
        array $localNames,
        AppControlCatalog $catalog,
    ): bool {
        foreach (FormulaReferenceExtractor::identifiers($formula) as $id) {
            if ($id === $controlName || isset($localNames[$id]) || $catalog->isReserved($id)) {
                continue;
            }
            $resolved = $catalog->resolveIdentifier($screen, $id);
            if ($resolved !== null && $resolved !== $id) {
                return true;
            }
            if (preg_match('/_\d+$/', $id) || str_contains($id, 'Initiave') || str_ends_with($id, '-')) {
                return true;
            }
            if (FormulaRefContext::hasBareCrossScreenControlRef($formula, $id, $screen, $catalog)) {
                return true;
            }
        }

        return false;
    }

    private function collectFormulaText(ControlDocument $doc): string
    {
        $parts = [];
        foreach ($doc->controls() as $control) {
            foreach ($control->propertyNames() as $prop) {
                $value = $control->getProperty($prop);
                if ($value !== null && trim($value) !== '') {
                    $parts[] = $value;
                }
            }
        }

        return implode("\n", $parts);
    }

    /** @return array{0:?string,1:?string} */
    private function parsePath(string $path, ControlDocument $doc): array
    {
        if (preg_match('#/Properties\.([A-Za-z0-9_]+)$#', $path, $m)) {
            $property = $m[1];
            $prefix = substr($path, 0, -strlen($m[0]));
            $controlName = basename(str_replace('\\', '/', $prefix));

            return [$controlName !== '' ? $controlName : null, $property];
        }

        $prefix = $doc->relativePath . '/';
        if (!str_starts_with($path, $prefix)) {
            if (str_contains($path, '.')) {
                $pos = strrpos($path, '.');
                if ($pos !== false) {
                    $controlPath = substr($path, 0, $pos);
                    $property = substr($path, $pos + 1);
                    $controlName = basename(str_replace('\\', '/', $controlPath));

                    return [$controlName, $property];
                }
            }

            return [null, null];
        }

        $rest = substr($path, strlen($prefix));
        if (str_contains($rest, '.')) {
            $pos = strrpos($rest, '.');
            if ($pos === false) {
                return [null, null];
            }
            $controlPath = substr($rest, 0, $pos);
            $property = substr($rest, $pos + 1);

            return [basename(str_replace('\\', '/', $controlPath)), $property];
        }

        return [null, null];
    }

    private function controlTypeFor(ControlDocument $doc, string $controlName): string
    {
        foreach ($doc->controls() as $control) {
            if ($control->name === $controlName) {
                return $control->type;
            }
        }

        return 'Control';
    }
}
