<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Build Studio-compatible AppCheckerResult.sarif from live checker findings.
 */
final class SarifWriter
{
    private const SCHEMA = 'https://schemastore.azurewebsites.net/schemas/json/sarif-2.1.0-rtm.4.json';
    private const TOOL_NAME = 'PowerApps app checker';
    private const TOOL_VERSION = '1.349';

    /**
     * @param list<array{
     *   ruleId:string,
     *   level:string,
     *   messageArgs:list<string|int>,
     *   location:string,
     *   screen:string,
     *   controlType:string,
     *   property:string,
     *   snippet:string,
     *   charOffset:int,
     *   charLength:int
     * }> $findings
     * @param array<string, mixed>|null $templateSarif existing SARIF to copy rule metadata from
     */
    public static function toJson(array $findings, ?array $templateSarif = null): string
    {
        $rules = self::buildRules($findings, $templateSarif);
        $results = [];
        foreach ($findings as $f) {
            $results[] = self::findingToResult($f);
        }

        $sarif = [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => self::TOOL_NAME,
                        'version' => self::TOOL_VERSION,
                        'rules' => array_values($rules),
                    ],
                ],
                'results' => $results,
            ]],
        ];

        $json = json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode SARIF JSON');
        }
        return $json . "\n";
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param array<string, mixed>|null $templateSarif
     * @return array<string, array<string,mixed>>
     */
    private static function buildRules(array $findings, ?array $templateSarif): array
    {
        $templateRules = self::extractTemplateRules($templateSarif);
        $used = [];
        foreach ($findings as $f) {
            $used[$f['ruleId']] = true;
        }

        $rules = [];
        foreach (array_keys($used) as $ruleId) {
            if (isset($templateRules[$ruleId])) {
                $rules[$ruleId] = $templateRules[$ruleId];
                continue;
            }
            $rules[$ruleId] = self::defaultRule($ruleId);
        }

        // Include all template rules for Studio UI compatibility
        foreach ($templateRules as $id => $rule) {
            if (!isset($rules[$id])) {
                $rules[$id] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param array<string, mixed>|null $templateSarif
     * @return array<string, array<string,mixed>>
     */
    private static function extractTemplateRules(?array $templateSarif): array
    {
        $out = [];
        if ($templateSarif === null) {
            return $out;
        }
        foreach ($templateSarif['runs'] ?? [] as $run) {
            if (!is_array($run)) {
                continue;
            }
            foreach ($run['tool']['driver']['rules'] ?? [] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $id = (string) ($rule['id'] ?? '');
                if ($id !== '') {
                    $out[$id] = $rule;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private static function findingToResult(array $f): array
    {
        $location = (string) $f['location'];
        $screen = (string) $f['screen'];
        $property = (string) $f['property'];
        $controlType = (string) $f['controlType'];
        $snippet = (string) $f['snippet'];
        $offset = (int) $f['charOffset'];
        $length = max(1, (int) $f['charLength']);

        return [
            'ruleId' => $f['ruleId'],
            'message' => [
                'id' => 'issue',
                'arguments' => array_values($f['messageArgs'] ?? []),
            ],
            'locations' => [[
                'physicalLocation' => [
                    'address' => [
                        'relativeAddress' => 0,
                        'fullyQualifiedName' => $location,
                    ],
                    'region' => [
                        'charOffset' => $offset,
                        'charLength' => $length,
                        'snippet' => ['text' => $snippet],
                    ],
                ],
                'logicalLocations' => [[
                    'fullyQualifiedName' => $location,
                ]],
                'properties' => [
                    'module' => $screen,
                    'type' => $controlType,
                    'member' => $property,
                ],
            ]],
            'properties' => [
                'level' => $f['level'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultRule(string $ruleId): array
    {
        $meta = StudioErrorDetector::RULE_META[$ruleId] ?? null;
        $text = $meta['description'] ?? $ruleId;
        $level = str_starts_with($ruleId, 'acc-') || str_contains($ruleId, 'Suggest') || str_contains($ruleId, 'Inefficient') || str_contains($ruleId, 'Unused')
            ? 'Medium'
            : 'High';

        return [
            'id' => $ruleId,
            'messageStrings' => [
                'issue' => ['text' => $text],
            ],
            'properties' => [
                'level' => $level,
                'componentType' => str_starts_with($ruleId, 'acc-') ? 'control' : 'app',
                'primaryCategory' => str_starts_with($ruleId, 'acc-') ? 'accessibility' : 'formula',
            ],
        ];
    }
}
