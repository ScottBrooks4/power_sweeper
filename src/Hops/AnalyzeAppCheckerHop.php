<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\FormulaLocaleNormalizer;
use PowerSweeper\Report;

/**
 * Read AppCheckerResult.sarif from the .msapp (when present), summarize formula errors,
 * and apply targeted fixes for known repairable findings (locale separators, empty formulas,
 * boolean Expected true/false on Checked/Default).
 *
 * This is the "detect errors in the file and clean them up" hop — Studio embeds the last
 * App Checker run inside the package.
 */
final class AnalyzeAppCheckerHop implements HopInterface
{
    public static function id(): string
    {
        return 'analyze_app_checker';
    }

    public static function label(): string
    {
        return 'Analyze App Checker results';
    }

    public static function description(): string
    {
        return 'Parse AppCheckerResult.sarif inside the .msapp, report formula-error counts by rule, and repair known patterns (locale separators, empty formulas, boolean Checked/Default).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = is_string($options['_extract_dir'] ?? null) ? (string) $options['_extract_dir'] : '';
        $sarifPath = $extractDir !== '' ? $this->findSarif($extractDir) : null;

        $results = [];
        if ($sarifPath !== null) {
            $raw = file_get_contents($sarifPath);
            if ($raw !== false) {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    foreach ($json['runs'] ?? [] as $run) {
                        if (!is_array($run)) {
                            continue;
                        }
                        foreach ($run['results'] ?? [] as $result) {
                            if (is_array($result)) {
                                $results[] = $result;
                            }
                        }
                    }
                }
            }
        }

        if ($results === []) {
            // Still clean empty formulas / locale issues even without SARIF
            $this->repairEmptyFormulas($documents, $report);
            $report->add(
                self::id(),
                '(appchecker)',
                'sarif',
                '(missing AppCheckerResult.sarif)',
                'no embedded checker results — ran empty-formula cleanup only'
            );
            return;
        }

        $byRule = [];
        $formulaish = 0;
        foreach ($results as $result) {
            $rule = (string) ($result['ruleId'] ?? 'unknown');
            $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
            if (str_starts_with($rule, 'app-Err') || str_starts_with($rule, 'app-Warn')) {
                $formulaish++;
            }
        }
        arsort($byRule);
        $top = [];
        $i = 0;
        foreach ($byRule as $rule => $count) {
            $top[] = $rule . '=' . $count;
            if (++$i >= 12) {
                break;
            }
        }

        $report->add(
            self::id(),
            '(appchecker)',
            'summary',
            count($results) . ' findings (' . $formulaish . ' formula Err/Warn)',
            'top: ' . implode(', ', $top)
        );

        // Index controls by short name for targeted fixes
        $byName = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $byName[strtolower($control->name)][] = $control;
            }
        }

        $fixed = 0;
        foreach ($results as $result) {
            $rule = (string) ($result['ruleId'] ?? '');
            $member = $this->memberOf($result);
            $controlName = $this->controlNameOf($result);
            if ($member === null || $controlName === null) {
                continue;
            }

            $controls = $byName[strtolower($controlName)] ?? [];
            foreach ($controls as $control) {
                $from = $control->getProperty($member);
                if ($from === null) {
                    continue;
                }

                // Empty formula: LayoutMaxHeight: =  → remove or blank-safe value
                if (trim(ltrim(trim($from), '=')) === '') {
                    if ($this->isOptionalLayoutProp($member)) {
                        $control->removeProperty($member);
                        $report->add(self::id(), $control->path, $member, $from, '(removed empty formula)');
                        $fixed++;
                        continue;
                    }
                }

                if (
                    in_array($rule, [
                        'app-ErrOperatorExpected',
                        'app-ErrBadArity',
                        'app-ErrBadArityMinimum',
                        'app-ErrBadToken',
                        'app-ErrInvalidArgs-Func',
                    ], true)
                    || FormulaLocaleNormalizer::looksLocaleCorrupted($from)
                ) {
                    $to = FormulaLocaleNormalizer::toInvariant($from);
                    if ($to !== $from) {
                        $control->setProperty($member, $to);
                        $report->add(self::id(), $control->path, $member, self::preview($from), self::preview($to));
                        $fixed++;
                        continue;
                    }
                }

                if ($rule === 'app-WarnBooleanExpected' || $rule === 'app-ErrBooleanExpected') {
                    $bool = $this->toBoolLiteral($from, $control->format === 'yaml');
                    if ($bool !== null && $bool !== $from) {
                        $control->setProperty($member, $bool);
                        $report->add(self::id(), $control->path, $member, $from, $bool);
                        $fixed++;
                    }
                }
            }
        }

        // Always sweep remaining empty optional layout formulas (often not in SARIF)
        $fixed += $this->repairEmptyFormulas($documents, $report);

        $report->add(
            self::id(),
            '(appchecker)',
            'repairs',
            (string) count($results) . ' findings scanned',
            $fixed . ' targeted repairs applied — re-run App Checker in Studio for a fresh count'
        );
    }

    private function findSarif(string $extractDir): ?string
    {
        $candidates = [
            $extractDir . '/AppCheckerResult.sarif',
            $extractDir . DIRECTORY_SEPARATOR . 'AppCheckerResult.sarif',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        // Case-insensitive walk (zip path styles vary).
        // BFS without following symlinks — CI runners can symlink into unreadable /tmp mounts.
        // Cap depth/visits so a mistaken extract root (e.g. sys temp) cannot hang.
        $queue = [[$extractDir, 0]];
        $visited = 0;
        $maxDepth = 16;
        $maxVisited = 4000;
        while ($queue !== []) {
            [$dir, $depth] = array_pop($queue);
            if ($depth > $maxDepth || $visited >= $maxVisited) {
                break;
            }
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $visited++;
                if ($visited > $maxVisited) {
                    break 2;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if ($depth < $maxDepth) {
                        $queue[] = [$path, $depth + 1];
                    }
                    continue;
                }
                if (is_file($path) && strcasecmp($name, 'AppCheckerResult.sarif') === 0) {
                    return $path;
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $result */
    private function memberOf(array $result): ?string
    {
        try {
            $loc = $result['locations'][0] ?? null;
            if (!is_array($loc)) {
                return null;
            }
            $props = $loc['properties'] ?? null;
            if (is_array($props) && isset($props['member']) && is_string($props['member'])) {
                return $props['member'];
            }
            $fq = $loc['logicalLocations'][0]['fullyQualifiedName']
                ?? $loc['physicalLocation']['address']['fullyQualifiedName']
                ?? null;
            if (!is_string($fq) || !str_contains($fq, '.')) {
                return null;
            }
            $parts = explode('.', $fq);
            return rtrim(array_pop($parts) ?: '', "'");
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $result */
    private function controlNameOf(array $result): ?string
    {
        try {
            $loc = $result['locations'][0] ?? null;
            if (!is_array($loc)) {
                return null;
            }
            $type = $loc['properties']['type'] ?? null;
            if (is_string($type) && $type !== '') {
                // SidebarMenu.Container3.Gallery1_1.'guid' → last segment
                if (preg_match("/\\.('[^']+'|[^'.]+)$/", $type, $m)) {
                    return trim($m[1], "'");
                }
                $parts = explode('.', $type);
                return trim((string) end($parts), "'");
            }
            $fq = $loc['logicalLocations'][0]['fullyQualifiedName'] ?? null;
            if (!is_string($fq)) {
                return null;
            }
            // 'VCR Home Page'.Size
            if (preg_match("/^'([^']+)'\\./", $fq, $m)) {
                return $m[1];
            }
            $parts = explode('.', $fq);
            if (count($parts) < 2) {
                return null;
            }
            array_pop($parts); // property
            return trim((string) end($parts), "'");
        } catch (\Throwable) {
            return null;
        }
    }

    private function isOptionalLayoutProp(string $prop): bool
    {
        return in_array($prop, [
            'LayoutMaxHeight',
            'LayoutMaxWidth',
            'LayoutMinHeight',
            'LayoutMinWidth',
            'FillPortions',
        ], true);
    }

    /**
     * @param list<ControlDocument> $documents
     */
    private function repairEmptyFormulas(array $documents, Report $report): int
    {
        $fixed = 0;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    if (!$this->isOptionalLayoutProp($prop)) {
                        continue;
                    }
                    $from = $control->getProperty($prop);
                    if ($from === null) {
                        continue;
                    }
                    if (trim(ltrim(trim($from), '=')) !== '') {
                        continue;
                    }
                    $control->removeProperty($prop);
                    $report->add(self::id(), $control->path, $prop, $from, '(removed empty formula)');
                    $fixed++;
                }
            }
        }
        return $fixed;
    }

    private function toBoolLiteral(string $value, bool $yamlEquals): ?string
    {
        $body = strtolower(trim(ltrim(trim($value), '=')));
        $map = [
            '1' => 'true',
            '0' => 'false',
            '"true"' => 'true',
            '"false"' => 'false',
            "'true'" => 'true',
            "'false'" => 'false',
        ];
        if (!isset($map[$body])) {
            return null;
        }
        return $yamlEquals ? '=' . $map[$body] : $map[$body];
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
    }
}
