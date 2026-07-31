<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Read Studio's AppCheckerResult.sarif from an unpacked .msapp (when present).
 * Used to inventory real checker issues and estimate repair coverage.
 */
final class AppCheckerSarif
{
    /**
     * @return list<array{ruleId:string,location:string,snippet:string,level:string}>
     */
    public static function loadFromMsapp(string $msappPath): array
    {
        $raw = ZipTool::readEntry($msappPath, 'AppCheckerResult.sarif');
        if ($raw === null || $raw === '') {
            return [];
        }
        return self::parse($raw);
    }

    /**
     * @return list<array{ruleId:string,location:string,snippet:string,level:string}>
     */
    public static function parse(string $sarifJson): array
    {
        $data = json_decode($sarifJson, true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data['runs'] ?? [] as $run) {
            if (!is_array($run)) {
                continue;
            }
            foreach ($run['results'] ?? [] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $ruleId = (string) ($result['ruleId'] ?? '');
                $level = (string) (($result['properties']['level'] ?? '') ?: 'unknown');
                $location = '';
                $snippet = '';
                foreach ($result['locations'] ?? [] as $loc) {
                    if (!is_array($loc)) {
                        continue;
                    }
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
                $out[] = [
                    'ruleId' => $ruleId,
                    'location' => $location,
                    'snippet' => $snippet,
                    'level' => $level,
                ];
            }
        }
        return $out;
    }

    /**
     * Classify whether a SARIF issue is in a category Power Sweeper auto-fixes.
     *
     * @param array{ruleId:string,location:string,snippet:string,level:string} $issue
     */
    public static function isAutoFixable(array $issue): bool
    {
        $rule = $issue['ruleId'];
        $snip = $issue['snippet'];

        // Locale / formula syntax class (separator & alpha corruption)
        if (in_array($rule, [
            'app-ErrOperatorExpected',
            'app-ErrBadArity',
            'app-ErrBadArityMinimum',
            'app-ErrBadToken',
            'app-WarnBooleanExpected',
        ], true)) {
            return true;
        }

        // "Name isn't valid. 'Value'" / ';' on Size is a cascade from CountIf(...; Value ...)
        if ($rule === 'app-ErrInvalidName' && ($snip === 'Value' || $snip === ';')) {
            return true;
        }

        // Invalid args only when the snippet itself is a locale-damaged call shape
        if ($rule === 'app-ErrInvalidArgs-Func' && ($snip === 'If' || $snip === 'RGBA' || $snip === 'CountIf' || str_contains($snip, ';'))) {
            return true;
        }

        // A11y class our hops cover
        if (in_array($rule, [
            'acc-AccessibleLabelNeeded',
            'acc-FocusBorderShouldBeVisible',
            'acc-TabIndexShouldBeDefinedForInteractiveControl',
        ], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array{ruleId:string,location:string,snippet:string,level:string}> $issues
     * @return array{total:int,auto_fixable:int,by_rule:array<string,int>,fixable_by_rule:array<string,int>}
     */
    public static function summarize(array $issues): array
    {
        $byRule = [];
        $fixableByRule = [];
        $fixable = 0;
        foreach ($issues as $issue) {
            $r = $issue['ruleId'];
            $byRule[$r] = ($byRule[$r] ?? 0) + 1;
            if (self::isAutoFixable($issue)) {
                $fixable++;
                $fixableByRule[$r] = ($fixableByRule[$r] ?? 0) + 1;
            }
        }
        arsort($byRule);
        arsort($fixableByRule);
        return [
            'total' => count($issues),
            'auto_fixable' => $fixable,
            'by_rule' => $byRule,
            'fixable_by_rule' => $fixableByRule,
        ];
    }
}
