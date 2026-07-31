<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Live Studio App checker — scans unpacked .msapp documents and produces
 * findings equivalent to AppCheckerResult.sarif without requiring Studio Save.
 */
final class StudioLiveChecker
{
    /**
     * @param list<ControlDocument> $documents
     * @param array{extract_dir?:string,template_sarif?:array<string,mixed>|null} $options
     * @return array{
     *   total:int,
     *   by_category:array<string,int>,
     *   by_level:array<string,int>,
     *   by_rule:array<string,int>,
     *   by_screen:array<string,int>,
     *   findings:list<array{
     *     ruleId:string,
     *     level:string,
     *     messageArgs:list<string|int>,
     *     location:string,
     *     screen:string,
     *     controlType:string,
     *     property:string,
     *     snippet:string,
     *     charOffset:int,
     *     charLength:int
     *   }>
     * }
     */
    public static function check(array $documents, array $options = []): array
    {
        $extractDir = $options['extract_dir'] ?? null;
        $catalog = AppControlCatalog::build($documents);
        $dataContext = AppDataContext::build($documents, is_string($extractDir) ? $extractDir : null);
        $formulaChecker = new PowerFxFormulaChecker($catalog, $dataContext);
        $scanDocs = $dataContext->documentsToScan($documents);

        $allFormulaText = self::collectAllFormulaText($scanDocs);
        $findings = [];

        foreach ($scanDocs as $doc) {
            $screen = $catalog->screenForDocument($doc) ?? $doc->screenName() ?? '(unknown)';
            $localNames = [];
            foreach ($doc->controls() as $c) {
                $localNames[$c->name] = true;
            }

            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }
                    $location = self::qualifiedLocation($screen, $control->path, $prop);
                    $findings = array_merge(
                        $findings,
                        $formulaChecker->check(
                            $value,
                            $screen,
                            $location,
                            self::controlTypeFqn($screen, $control),
                            $prop,
                            $control->name,
                            $localNames
                        )
                    );
                }

                $findings = array_merge($findings, self::checkAccessibility($control, $screen, $catalog));
                $findings = array_merge($findings, self::checkPerformance($control, $screen));
            }
        }

        $findings = array_merge($findings, self::checkAppLevel($scanDocs, $allFormulaText));

        $findings = self::dedupeFindings($findings);

        return self::summarize($findings);
    }

    /**
     * @param list<ControlDocument> $documents
     */
    public static function writeSarifToExtractDir(array $documents, string $extractDir, ?string $templateSarifPath = null): string
    {
        $template = null;
        if ($templateSarifPath !== null && is_file($templateSarifPath)) {
            $raw = file_get_contents($templateSarifPath);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $template = $decoded;
                }
            }
        } else {
            $candidate = $extractDir . '/AppCheckerResult.sarif';
            if (is_file($candidate)) {
                $raw = file_get_contents($candidate);
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $template = $decoded;
                    }
                }
            }
        }

        $report = self::check($documents, ['extract_dir' => $extractDir, 'template_sarif' => $template]);
        $json = SarifWriter::toJson($report['findings'], $template);
        $outPath = $extractDir . '/AppCheckerResult.sarif';
        file_put_contents($outPath, $json);
        return $outPath;
    }

    /**
     * @param list<ControlDocument> $documents
     */
    private static function collectAllFormulaText(array $documents): string
    {
        $text = '';
        foreach ($documents as $doc) {
            $doc->transformFormulas(static function (string $formula) use (&$text): string {
                $text .= "\n" . $formula;
                return $formula;
            });
        }
        return $text;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function checkAccessibility(ControlNode $control, string $screen, AppControlCatalog $catalog): array
    {
        if (!$control->isInteractive()) {
            return [];
        }

        $findings = [];
        $locationBase = self::qualifiedLocation($screen, $control->path, '');

        $label = $control->getProperty('AccessibleLabel');
        if ($label === null || trim($label) === '') {
            $findings[] = self::structFinding(
                'acc-AccessibleLabelNeeded',
                'Medium',
                [],
                self::qualifiedLocation($screen, $control->path, 'AccessibleLabel'),
                $screen,
                self::controlTypeFqn($screen, $control),
                'AccessibleLabel',
                '',
                0,
                0
            );
        }

        $tab = $control->getProperty('TabIndex');
        if ($tab === null || trim($tab) === '') {
            $findings[] = self::structFinding(
                'acc-TabIndexShouldBeDefinedForInteractiveControl',
                'Medium',
                [],
                self::qualifiedLocation($screen, $control->path, 'TabIndex'),
                $screen,
                self::controlTypeFqn($screen, $control),
                'TabIndex',
                '',
                0,
                0
            );
        }

        $ft = $control->getProperty('FocusedBorderThickness');
        if ($ft === null || self::isBlankOrZero($ft)) {
            $findings[] = self::structFinding(
                'acc-FocusBorderShouldBeVisible',
                'Medium',
                [],
                self::qualifiedLocation($screen, $control->path, 'FocusedBorderThickness'),
                $screen,
                self::controlTypeFqn($screen, $control),
                'FocusedBorderThickness',
                '',
                0,
                0
            );
        }

        unset($locationBase, $catalog);
        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function checkPerformance(ControlNode $control, string $screen): array
    {
        $findings = [];
        if (str_contains(strtolower($control->type), 'gallery')) {
            $delay = $control->getProperty('DelayItemLoading');
            if ($delay === null || in_array(strtolower(trim(ltrim(trim($delay), '='))), ['false', '0'], true)) {
                $findings[] = self::structFinding(
                    'app-InefficientDelayLoading',
                    'Medium',
                    [],
                    self::qualifiedLocation($screen, $control->path, 'DelayItemLoading'),
                    $screen,
                    self::controlTypeFqn($screen, $control),
                    'DelayItemLoading',
                    '',
                    0,
                    0
                );
            }
        }
        return $findings;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return list<array<string,mixed>>
     */
    private static function checkAppLevel(array $documents, string $allFormulaText): array
    {
        $findings = [];
        $app = null;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    $app = $control;
                }
            }
        }
        if ($app === null) {
            return [];
        }

        $maxRows = $app->getProperty('MaxDataRowCount') ?? $app->getProperty('DataRowLimit');
        if ($maxRows !== null) {
            $n = (int) preg_replace('/\D/', '', $maxRows);
            if ($n > 500) {
                $findings[] = self::structFinding(
                    'app-DataSourceDefaultMaxRowsLimit',
                    'Medium',
                    [],
                    'App',
                    'App',
                    'App',
                    'MaxDataRowCount',
                    '',
                    0,
                    0
                );
            }
        }

        // Unused variables declared in App.OnStart
        $onStart = $app->getProperty('OnStart') ?? '';
        if ($onStart !== '' && preg_match_all('/\bSet\s*\(\s*(var[A-Za-z0-9_]+)\s*,/i', $onStart, $m)) {
            foreach (array_unique($m[1]) as $var) {
                $pattern = '/\b' . preg_quote($var, '/') . '\b/i';
                $declCount = preg_match_all($pattern, $onStart);
                $useCount = preg_match_all($pattern, $allFormulaText);
                if ($useCount <= $declCount) {
                    $findings[] = self::structFinding(
                        'app-UnusedVariables',
                        'Medium',
                        [],
                        'App.' . $var,
                        'App',
                        'App',
                        $var,
                        '',
                        0,
                        0
                    );
                }
            }
        }

        // Cross-screen event dependencies
        $catalog = AppControlCatalog::build($documents);
        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc);
            if ($screen === null) {
                continue;
            }
            foreach ($doc->controls() as $control) {
                foreach (['OnSelect', 'OnChange', 'OnVisible', 'OnCheck', 'OnUncheck'] as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }
                    foreach (FormulaReferenceExtractor::identifiers($value) as $id) {
                        if ($catalog->hasOnScreen($screen, $id)) {
                            continue;
                        }
                        $others = $catalog->screensWith($id);
                        if ($others !== [] && !in_array($screen, $others, true)) {
                            $findings[] = self::structFinding(
                                'app-CrossScreenEventDependencies',
                                'Medium',
                                [],
                                self::qualifiedLocation($screen, $control->path, $prop),
                                $screen,
                                self::controlTypeFqn($screen, $control),
                                $prop,
                                '',
                                0,
                                0
                            );
                            break 2;
                        }
                    }
                }
            }
        }

        return $findings;
    }

    private static function qualifiedLocation(string $screen, string $controlPath, string $property): string
    {
        $controlFqn = self::pathToControlFqn($controlPath, $screen);
        if ($property === '') {
            return $controlFqn;
        }
        return $controlFqn . '.' . $property;
    }

    private static function pathToControlFqn(string $path, string $screen): string
    {
        $parts = array_values(array_filter(explode('/', $path)));
        $meaningful = [];
        foreach ($parts as $part) {
            if (str_ends_with($part, '.json') || str_ends_with($part, '.pa.yaml')) {
                continue;
            }
            if (in_array($part, ['Src', 'Controls', 'Components', 'Screens', 'ComponentDefinitions'], true)) {
                continue;
            }
            $meaningful[] = $part;
        }

        if ($meaningful !== [] && $screen !== '' && $meaningful[0] === $screen) {
            array_shift($meaningful);
        }

        if ($meaningful === []) {
            return $screen !== '' ? self::quoteName($screen) : '';
        }

        if ($screen === '' || $screen === '(unknown)') {
            return self::quoteName($meaningful[0]);
        }

        $screenQ = self::quoteName($screen);
        return $screenQ . '.' . implode('.', array_map([self::class, 'quoteName'], $meaningful));
    }

    /** @deprecated use pathToControlFqn */
    private static function pathToFqn(string $path): string
    {
        return self::pathToControlFqn($path, '');
    }

    private static function quoteName(string $name): string
    {
        if (preg_match('/^[A-Za-z_][\w]*$/', $name)) {
            return $name;
        }
        return "'" . str_replace("'", "''", $name) . "'";
    }

    private static function controlTypeFqn(string $screen, ControlNode $control): string
    {
        return self::pathToControlFqn($control->path, $screen);
    }

    /**
     * @param list<string|int> $args
     * @return array<string,mixed>
     */
    private static function structFinding(
        string $ruleId,
        string $level,
        array $args,
        string $location,
        string $screen,
        string $controlType,
        string $property,
        string $snippet,
        int $charOffset,
        int $charLength,
    ): array {
        return [
            'ruleId' => $ruleId,
            'level' => $level,
            'messageArgs' => $args,
            'location' => $location,
            'screen' => $screen,
            'controlType' => $controlType,
            'property' => $property,
            'snippet' => $snippet,
            'charOffset' => $charOffset,
            'charLength' => $charLength,
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private static function dedupeFindings(array $findings): array
    {
        $seen = [];
        $out = [];
        foreach ($findings as $f) {
            $key = $f['ruleId'] . '|' . $f['location'] . '|' . $f['snippet'] . '|' . $f['charOffset'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $f;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array{
     *   total:int,
     *   by_category:array<string,int>,
     *   by_level:array<string,int>,
     *   by_rule:array<string,int>,
     *   by_screen:array<string,int>,
     *   findings:list<array<string,mixed>>
     * }
     */
    private static function summarize(array $findings): array
    {
        $byCategory = [];
        $byLevel = [];
        $byRule = [];
        $byScreen = [];

        foreach ($findings as $f) {
            $ruleId = (string) $f['ruleId'];
            $cat = StudioErrorDetector::ruleCategory($ruleId);
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            $byLevel[(string) $f['level']] = ($byLevel[(string) $f['level']] ?? 0) + 1;
            $byRule[$ruleId] = ($byRule[$ruleId] ?? 0) + 1;
            $byScreen[(string) $f['screen']] = ($byScreen[(string) $f['screen']] ?? 0) + 1;
        }

        arsort($byCategory);
        arsort($byLevel);
        arsort($byRule);
        arsort($byScreen);

        return [
            'total' => count($findings),
            'by_category' => $byCategory,
            'by_level' => $byLevel,
            'by_rule' => $byRule,
            'by_screen' => $byScreen,
            'findings' => $findings,
        ];
    }

    private static function inferCategory(string $ruleId): string
    {
        if (str_starts_with($ruleId, 'acc-')) {
            return 'accessibility';
        }
        if (str_starts_with($ruleId, 'app-SuggestRemoteExecutionHint') || str_contains($ruleId, 'Inefficient') || str_contains($ruleId, 'MaxRows')) {
            return 'performance';
        }
        if (str_starts_with($ruleId, 'app-Unused') || str_contains($ruleId, 'CrossScreen') || str_contains($ruleId, 'Collecting')) {
            return 'maintainability';
        }
        return 'formulas';
    }

    private static function isBlankOrZero(string $value): bool
    {
        $v = strtolower(trim(ltrim(trim($value), '=')));
        return $v === '' || $v === '0' || $v === '0.0' || $v === 'false';
    }
}
