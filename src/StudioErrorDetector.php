<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Full Studio App Checker inventory from AppCheckerResult.sarif plus optional
 * heuristic formula scan. Detection only — does not mutate the app.
 */
final class StudioErrorDetector
{
    /** @var array<string, array{category:string,description:string}> */
    private const RULE_META = [
        'app-ErrOperatorExpected' => ['category' => 'formulas', 'description' => 'Expected operator (+, *, &, etc.)'],
        'app-ErrInvalidName' => ['category' => 'formulas', 'description' => "Name isn't valid — unrecognized identifier"],
        'app-ErrBadArityMinimum' => ['category' => 'formulas', 'description' => 'Invalid number of arguments (too few)'],
        'app-ErrBadArity' => ['category' => 'formulas', 'description' => 'Invalid number of arguments'],
        'app-ErrInvalidArgs-Func' => ['category' => 'formulas', 'description' => 'Function has invalid arguments'],
        'app-ErrBadType-ExpectedType-ProvidedType' => ['category' => 'formulas', 'description' => 'Invalid argument type'],
        'app-ErrBadType-Type' => ['category' => 'formulas', 'description' => 'Invalid argument type for context'],
        'app-ErrInvalidDot' => ['category' => 'formulas', 'description' => "'.' operator used on incompatible value"],
        'app-ErrColDNE-Name' => ['category' => 'formulas', 'description' => 'Column does not exist'],
        'app-ErrUnknownFunction' => ['category' => 'formulas', 'description' => 'Unknown or unsupported function'],
        'app-ErrIncompatibleTypesForEquality-Left-Right' => ['category' => 'formulas', 'description' => 'Incompatible types for comparison'],
        'app-ErrIncompatibleCtxtVariableTypes' => ['category' => 'formulas', 'description' => 'Incompatible context variable types'],
        'app-ErrBadToken' => ['category' => 'formulas', 'description' => 'Unexpected characters in formula'],
        'app-ErrTypeError-Arg-Expected-Found' => ['category' => 'formulas', 'description' => 'Argument type mismatch'],
        'app-WarnBooleanExpected' => ['category' => 'formulas', 'description' => 'Boolean value expected'],
        'app-WarnNoUsableFields' => ['category' => 'formulas', 'description' => 'Rule produces only nested tables/records'],
        'acc-AccessibleLabelNeeded' => ['category' => 'accessibility', 'description' => 'Missing accessible label'],
        'acc-TabIndexShouldBeDefinedForInteractiveControl' => ['category' => 'accessibility', 'description' => 'Missing tab stop'],
        'acc-FocusBorderShouldBeVisible' => ['category' => 'accessibility', 'description' => "Focus isn't showing"],
        'app-SuggestRemoteExecutionHint' => ['category' => 'performance', 'description' => 'Delegation warning'],
        'app-SuggestRemoteExecutionHint-OpNotSupportedByService' => ['category' => 'performance', 'description' => 'Delegation — operation not supported by connector'],
        'app-SuggestRemoteExecutionHint-StringMatchSecondParam' => ['category' => 'performance', 'description' => 'Delegation — field name in second argument'],
        'app-SuggestRemoteExecutionHint-InOpRhs' => ['category' => 'performance', 'description' => 'Delegation — In operator RHS'],
        'app-InefficientDelayLoading' => ['category' => 'performance', 'description' => 'Inefficient delay loading'],
        'app-DataSourceDefaultMaxRowsLimit' => ['category' => 'performance', 'description' => 'Non-delegable row limit > 500'],
        'app-UnusedVariables' => ['category' => 'maintainability', 'description' => 'Unused variable'],
        'app-UnusedMediaResources' => ['category' => 'maintainability', 'description' => 'Unused media files'],
        'app-CollectingReadOnlyTable' => ['category' => 'maintainability', 'description' => 'Collection initialized but never updated'],
        'app-CrossScreenEventDependencies' => ['category' => 'maintainability', 'description' => 'Event references control on another screen'],
    ];

    /**
     * @return array{
     *   source:string,
     *   sarif_present:bool,
     *   total:int,
     *   by_category:array<string,int>,
     *   by_level:array<string,int>,
     *   by_rule:array<string,int>,
     *   rules:array<string,array{category:string,description:string,message_template:string,count:int,auto_fixable:int}>,
     *   by_screen:array<string,int>,
     *   by_property:array<string,int>,
     *   auto_fixable:int,
     *   not_auto_fixable:int,
     *   by_root_cause:array<string,int>,
     *   issues:list<array{
     *     ruleId:string,
     *     category:string,
     *     level:string,
     *     message:string,
     *     location:string,
     *     screen:string,
     *     property:string,
     *     snippet:string,
     *     auto_fixable:bool,
     *     root_cause:string
     *   }>,
     *   heuristic?:array{total:int,by_kind:array<string,int>,issues:list<array{control:string,property:string,kind:string,snippet:string}>}
     * }
     */
    public static function detectFromMsapp(string $msappPath, bool $includeHeuristic = true): array
    {
        $raw = ZipTool::readEntry($msappPath, 'AppCheckerResult.sarif');
        $sarifPresent = $raw !== null && $raw !== '';

        $messageTemplates = [];
        $sarifData = [];
        if ($sarifPresent) {
            $sarifData = json_decode($raw, true);
            if (!is_array($sarifData)) {
                $sarifPresent = false;
            } else {
                $messageTemplates = self::extractMessageTemplates($sarifData);
            }
        }

        $issues = $sarifPresent ? self::parseIssues($sarifData, $messageTemplates) : [];

        $byCategory = [];
        $byLevel = [];
        $byRule = [];
        $byScreen = [];
        $byProperty = [];
        $byRootCause = [];
        $rules = [];
        $autoFixable = 0;

        foreach ($issues as $issue) {
            $ruleId = $issue['ruleId'];
            $cat = $issue['category'];
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
            $byLevel[$issue['level']] = ($byLevel[$issue['level']] ?? 0) + 1;
            $byRule[$ruleId] = ($byRule[$ruleId] ?? 0) + 1;
            $byScreen[$issue['screen']] = ($byScreen[$issue['screen']] ?? 0) + 1;
            if ($issue['property'] !== '') {
                $byProperty[$issue['property']] = ($byProperty[$issue['property']] ?? 0) + 1;
            }
            $byRootCause[$issue['root_cause']] = ($byRootCause[$issue['root_cause']] ?? 0) + 1;
            if ($issue['auto_fixable']) {
                $autoFixable++;
            }

            if (!isset($rules[$ruleId])) {
                $meta = self::RULE_META[$ruleId] ?? ['category' => self::inferCategory($ruleId), 'description' => $ruleId];
                $rules[$ruleId] = [
                    'category' => $meta['category'],
                    'description' => $meta['description'],
                    'message_template' => $messageTemplates[$ruleId] ?? '',
                    'count' => 0,
                    'auto_fixable' => 0,
                ];
            }
            $rules[$ruleId]['count']++;
            if ($issue['auto_fixable']) {
                $rules[$ruleId]['auto_fixable']++;
            }
        }

        arsort($byCategory);
        arsort($byLevel);
        arsort($byRule);
        arsort($byScreen);
        arsort($byProperty);
        arsort($byRootCause);
        uasort($rules, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $report = [
            'source' => basename($msappPath),
            'sarif_present' => $sarifPresent,
            'total' => count($issues),
            'by_category' => $byCategory,
            'by_level' => $byLevel,
            'by_rule' => $byRule,
            'rules' => $rules,
            'by_screen' => $byScreen,
            'by_property' => $byProperty,
            'by_root_cause' => $byRootCause,
            'auto_fixable' => $autoFixable,
            'not_auto_fixable' => count($issues) - $autoFixable,
            'issues' => $issues,
        ];

        if ($includeHeuristic) {
            $report['heuristic'] = self::runHeuristicScan($msappPath);
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $sarifData
     * @param array<string, string> $messageTemplates
     * @return list<array{
     *   ruleId:string,
     *   category:string,
     *   level:string,
     *   message:string,
     *   location:string,
     *   screen:string,
     *   property:string,
     *   snippet:string,
     *   auto_fixable:bool,
     *   root_cause:string
     * }>
     */
    private static function parseIssues(array $sarifData, array $messageTemplates): array
    {
        $out = [];
        foreach ($sarifData['runs'] ?? [] as $run) {
            if (!is_array($run)) {
                continue;
            }
            $driverRules = $run['tool']['driver']['rules'] ?? [];
            foreach ($run['results'] ?? [] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $ruleId = (string) ($result['ruleId'] ?? '');
                $level = (string) (($result['properties']['level'] ?? '') ?: 'unknown');
                $location = '';
                $snippet = '';
                $module = '';

                foreach ($result['locations'] ?? [] as $loc) {
                    if (!is_array($loc)) {
                        continue;
                    }
                    $module = (string) (($loc['properties']['module'] ?? '') ?: $module);
                    foreach ($loc['logicalLocations'] ?? [] as $ll) {
                        if (is_array($ll) && isset($ll['fullyQualifiedName'])) {
                            $location = (string) $ll['fullyQualifiedName'];
                        }
                    }
                    $phys = $loc['physicalLocation'] ?? null;
                    if (is_array($phys)) {
                        $sn = $phys['region']['snippet']['text'] ?? null;
                        if (is_string($sn) && $sn !== '') {
                            $snippet = $sn;
                        }
                        if ($location === '' && isset($phys['address']['fullyQualifiedName'])) {
                            $location = (string) $phys['address']['fullyQualifiedName'];
                        }
                    }
                }

                $base = [
                    'ruleId' => $ruleId,
                    'location' => $location,
                    'snippet' => $snippet,
                    'level' => $level,
                ];
                $meta = self::RULE_META[$ruleId] ?? ['category' => self::inferCategory($ruleId), 'description' => $ruleId];
                [$screen, $property] = self::parseLocation($location, $module);

                $out[] = [
                    'ruleId' => $ruleId,
                    'category' => $meta['category'],
                    'level' => $level,
                    'message' => self::formatMessage($ruleId, $result, $messageTemplates),
                    'location' => $location,
                    'screen' => $screen,
                    'property' => $property,
                    'snippet' => $snippet,
                    'auto_fixable' => AppCheckerSarif::isAutoFixable($base),
                    'root_cause' => self::classifyRootCause($ruleId, $location, $property, $snippet),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $sarifData
     * @return array<string, string>
     */
    private static function extractMessageTemplates(array $sarifData): array
    {
        $templates = [];
        foreach ($sarifData['runs'] ?? [] as $run) {
            if (!is_array($run)) {
                continue;
            }
            foreach ($run['tool']['driver']['rules'] ?? [] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $id = (string) ($rule['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $text = $rule['messageStrings']['issue']['text']
                    ?? $rule['shortDescription']['text']
                    ?? '';
                $templates[$id] = (string) $text;
            }
        }
        return $templates;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, string> $templates
     */
    private static function formatMessage(string $ruleId, array $result, array $templates): string
    {
        $template = $templates[$ruleId] ?? '';
        if ($template === '') {
            return $ruleId;
        }
        $args = $result['message']['arguments'] ?? [];
        if (!is_array($args)) {
            return $template;
        }
        $msg = $template;
        foreach (array_values($args) as $i => $arg) {
            $msg = str_replace('{' . $i . '}', (string) $arg, $msg);
        }
        return $msg;
    }

  /**
     * @return array{0:string,1:string} screen, property
     */
    private static function parseLocation(string $location, string $module): array
    {
        if ($location === '') {
            return [$module !== '' ? $module : '(unknown)', ''];
        }

        $parts = explode('.', $location);
        $screen = $parts[0] ?? '(unknown)';
        $property = count($parts) >= 2 ? $parts[count($parts) - 1] : '';

        return [$screen, $property];
    }

    private static function inferCategory(string $ruleId): string
    {
        if (str_starts_with($ruleId, 'acc-')) {
            return 'accessibility';
        }
        if (str_starts_with($ruleId, 'app-SuggestRemoteExecutionHint') || str_contains($ruleId, 'Inefficient') || str_contains($ruleId, 'MaxRows')) {
            return 'performance';
        }
        if (str_starts_with($ruleId, 'app-Err') || str_starts_with($ruleId, 'app-Warn')) {
            return 'formulas';
        }
        return 'maintainability';
    }

    private static function classifyRootCause(string $ruleId, string $location, string $property, string $snippet): string
    {
        if (str_starts_with($ruleId, 'acc-')) {
            return 'accessibility_' . substr($ruleId, 4);
        }
        if (str_starts_with($ruleId, 'app-SuggestRemoteExecutionHint')) {
            return 'delegation_warning';
        }
        if ($ruleId === 'app-InefficientDelayLoading') {
            return 'inefficient_delay_loading';
        }
        if ($ruleId === 'app-DataSourceDefaultMaxRowsLimit') {
            return 'row_limit_over_500';
        }
        if ($ruleId === 'app-UnusedVariables') {
            return 'unused_variable';
        }
        if ($ruleId === 'app-UnusedMediaResources') {
            return 'unused_media';
        }
        if ($ruleId === 'app-CollectingReadOnlyTable') {
            return 'readonly_collection';
        }
        if ($ruleId === 'app-CrossScreenEventDependencies') {
            return 'cross_screen_event';
        }

        if ($ruleId === 'app-ErrOperatorExpected' && $snippet === ';') {
            return 'locale_list_separator';
        }
        if ($ruleId === 'app-ErrInvalidName' && $snippet === 'Value') {
            return 'locale_countif_value_cascade';
        }
        if ($ruleId === 'app-ErrBadArityMinimum' && str_contains($snippet, 'CountIf') && str_contains($snippet, ';')) {
            return 'locale_countif_separator';
        }
        if (($ruleId === 'app-ErrBadArity' || $ruleId === 'app-ErrInvalidArgs-Func') && str_contains($snippet, 'RGBA')) {
            return 'locale_rgba_alpha';
        }
        if ($ruleId === 'app-ErrInvalidArgs-Func' && ($snippet === 'If' || str_contains($snippet, ';'))) {
            return 'locale_if_separator';
        }
        if ($ruleId === 'app-ErrInvalidName' && preg_match('/_\d+$/', $snippet)) {
            return 'cross_screen_or_missing_control';
        }
        if ($ruleId === 'app-ErrInvalidDot' && in_array($snippet, ['.Checked', '.Text', '.SelectedDate', '.Selected', '.Value', '.HtmlText'], true)) {
            return 'cascade_from_invalid_control';
        }
        if ($ruleId === 'app-ErrColDNE-Name') {
            return 'missing_sharepoint_column';
        }
        if ($ruleId === 'app-ErrInvalidName' && !in_array($snippet, ['Value', ';', ''], true)) {
            return 'unrecognized_identifier';
        }

        return 'formula_other';
    }

    /**
     * @return array{total:int,by_kind:array<string,int>,issues:list<array{control:string,property:string,kind:string,snippet:string}>}
     */
    private static function runHeuristicScan(string $msappPath): array
    {
        $archive = new MsappArchive($msappPath);
        try {
            $archive->unpack();
            $issues = StudioIssueScanner::scan($archive->documents());
        } finally {
            $dir = $archive->extractDir();
            if (is_dir($dir)) {
                self::rmTree($dir);
            }
        }

        $byKind = [];
        foreach ($issues as $issue) {
            $byKind[$issue['kind']] = ($byKind[$issue['kind']] ?? 0) + 1;
        }
        arsort($byKind);

        return [
            'total' => count($issues),
            'by_kind' => $byKind,
            'issues' => $issues,
        ];
    }

    private static function rmTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param array{
     *   source:string,
     *   total:int,
     *   by_category:array<string,int>,
     *   by_level:array<string,int>,
     *   by_rule:array<string,int>,
     *   auto_fixable:int,
     *   not_auto_fixable:int,
     *   by_screen:array<string,int>,
     *   rules:array<string,array{category:string,description:string,count:int,auto_fixable:int}>,
     *   heuristic?:array{total:int,by_kind:array<string,int>}
     * } $report
     */
    public static function formatSummary(array $report): string
    {
        $lines = [];
        $lines[] = 'Studio App Checker — error inventory';
        $lines[] = 'Source: ' . $report['source'];
        $lines[] = str_repeat('=', 60);
        $lines[] = '';
        $lines[] = 'TOTAL ISSUES: ' . $report['total'];
        $lines[] = '  Auto-fixable (current hops): ' . $report['auto_fixable'];
        $lines[] = '  Not auto-fixable: ' . $report['not_auto_fixable'];
        $lines[] = '';

        $lines[] = 'BY CATEGORY (Studio buckets):';
        foreach ($report['by_category'] as $cat => $count) {
            $lines[] = sprintf('  %-18s %4d', ucfirst($cat) . ':', $count);
        }
        $lines[] = '';

        $lines[] = 'BY SEVERITY:';
        foreach ($report['by_level'] as $level => $count) {
            $lines[] = sprintf('  %-18s %4d', $level . ':', $count);
        }
        $lines[] = '';

        $lines[] = 'BY RULE:';
        foreach ($report['rules'] as $ruleId => $meta) {
            $fix = $meta['auto_fixable'] > 0 ? ' [' . $meta['auto_fixable'] . ' fixable]' : '';
            $lines[] = sprintf('  %4d  %s%s', $meta['count'], $ruleId, $fix);
            $lines[] = '        ' . $meta['description'];
        }
        $lines[] = '';

        $lines[] = 'BY ROOT CAUSE:';
        foreach ($report['by_root_cause'] as $cause => $count) {
            $lines[] = sprintf('  %4d  %s', $count, $cause);
        }
        $lines[] = '';

        $lines[] = 'TOP SCREENS:';
        $i = 0;
        foreach ($report['by_screen'] as $screen => $count) {
            $lines[] = sprintf('  %4d  %s', $count, $screen);
            if (++$i >= 15) {
                break;
            }
        }

        if (isset($report['heuristic'])) {
            $h = $report['heuristic'];
            $lines[] = '';
            $lines[] = 'HEURISTIC SCAN (formula patterns not in SARIF):';
            $lines[] = '  Total: ' . $h['total'];
            foreach ($h['by_kind'] as $kind => $count) {
                $lines[] = sprintf('    %-30s %4d', $kind . ':', $count);
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
