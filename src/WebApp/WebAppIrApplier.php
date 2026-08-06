<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;
use PowerSweeper\StringSimilarity;

/**
 * Apply a structural web IR back onto canvas documents using heuristics.
 *
 * Safe updates only:
 * - Document layout / DocumentAppType in Properties.json
 * - Control Text / AccessibleLabel / Tooltip when IR labels differ and look intentional
 * - Navigate() target renames when IR navigation maps old→new screen names
 * - Does NOT invent Power Fx or recreate missing controls (explicit non-goal)
 */
final class WebAppIrApplier
{
    /**
     * @param list<ControlDocument> $documents
     * @param array<string, mixed> $ir
     * @return array{changes:int, notes:list<string>}
     */
    public function apply(array $documents, array $ir, Report $report, ?string $extractDir = null): array
    {
        $changes = 0;
        $notes = [];

        if (($ir['format'] ?? '') !== 'power_sweeper_web_ir') {
            $notes[] = 'IR format not recognized; skipped';
            return ['changes' => 0, 'notes' => $notes];
        }

        if ($extractDir !== null && isset($ir['document']) && is_array($ir['document'])) {
            $n = $this->applyDocumentMeta($extractDir, $ir['document'], $report);
            $changes += $n;
            if ($n > 0) {
                $notes[] = "document meta: {$n} field(s)";
            }
        }

        $screenMap = $this->indexScreens($documents);
        $irScreens = is_array($ir['screens'] ?? null) ? $ir['screens'] : [];

        foreach ($irScreens as $irScreen) {
            if (!is_array($irScreen)) {
                continue;
            }
            $liveName = $this->resolveLiveScreenName($irScreen, array_keys($screenMap));
            if ($liveName === null || !isset($screenMap[$liveName])) {
                continue;
            }
            $n = $this->applyControlTree($screenMap[$liveName], $irScreen, $report);
            $changes += $n;
        }

        $renames = $this->inferScreenRenames($irScreens, array_keys($screenMap));
        if ($renames !== []) {
            $n = $this->rewriteNavigateTargets($documents, $renames, $report);
            $changes += $n;
            if ($n > 0) {
                $notes[] = 'navigation rewrites: ' . $n;
            }
        }

        return ['changes' => $changes, 'notes' => $notes];
    }

    /**
     * @param array<string, mixed> $irScreen
     * @param list<string> $liveNames
     */
    private function resolveLiveScreenName(array $irScreen, array $liveNames): ?string
    {
        $name = (string) ($irScreen['name'] ?? '');
        $prev = (string) ($irScreen['previous_name'] ?? '');

        // Prefer previous_name when web tooling renamed the IR screen but the .msapp still uses the old key.
        if ($prev !== '' && in_array($prev, $liveNames, true)) {
            return $prev;
        }
        if ($name !== '' && in_array($name, $liveNames, true)) {
            return $name;
        }
        if ($name !== '') {
            return $this->fuzzyScreenName($name, $liveNames);
        }
        if ($prev !== '') {
            return $this->fuzzyScreenName($prev, $liveNames);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function applyDocumentMeta(string $extractDir, array $document, Report $report): int
    {
        $path = $extractDir . DIRECTORY_SEPARATOR . 'Properties.json';
        if (!is_file($path)) {
            return 0;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return 0;
        }
        $json = json_decode($raw, false);
        if (!is_object($json)) {
            return 0;
        }

        $changes = 0;
        if (isset($document['app_type']) && is_string($document['app_type']) && $document['app_type'] !== '') {
            $before = (string) ($json->DocumentAppType ?? '');
            if ($before !== $document['app_type']) {
                $json->DocumentAppType = $document['app_type'];
                $report->add('import_web_ir', 'Properties.json', 'DocumentAppType', $before, $document['app_type']);
                $changes++;
            }
        }

        $layout = is_array($document['layout'] ?? null) ? $document['layout'] : [];
        $map = [
            'width' => 'DocumentLayoutWidth',
            'height' => 'DocumentLayoutHeight',
            'orientation' => 'DocumentLayoutOrientation',
            'scale_to_fit' => 'DocumentLayoutScaleToFit',
            'maintain_aspect_ratio' => 'DocumentLayoutMaintainAspectRatio',
            'lock_orientation' => 'DocumentLayoutLockOrientation',
        ];
        foreach ($map as $irKey => $jsonKey) {
            if (!array_key_exists($irKey, $layout) || $layout[$irKey] === null) {
                continue;
            }
            $before = $json->{$jsonKey} ?? null;
            $after = $layout[$irKey];
            if ($before !== $after) {
                $json->{$jsonKey} = $after;
                $report->add(
                    'import_web_ir',
                    'Properties.json',
                    $jsonKey,
                    $before === null ? '(unset)' : (is_bool($before) ? ($before ? 'true' : 'false') : (string) $before),
                    is_bool($after) ? ($after ? 'true' : 'false') : (string) $after,
                );
                $changes++;
            }
        }

        if ($changes > 0) {
            $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $encoded = str_replace("\n", "\r\n", $encoded);
                file_put_contents($path, $encoded);
            }
        }

        return $changes;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, ControlNode>
     */
    private function indexScreens(array $documents): array
    {
        $out = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $c) {
                if ($c->isScreen()) {
                    $out[$c->name] = $c;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $names
     */
    private function fuzzyScreenName(string $want, array $names): ?string
    {
        if ($want === '') {
            return null;
        }
        $hit = StringSimilarity::bestMatch($want, $names, 3);

        return ($hit !== null && $hit['score'] >= 82.0) ? $hit['match'] : null;
    }

    /**
     * @param array<string, mixed> $irNode
     */
    private function applyControlTree(ControlNode $node, array $irNode, Report $report): int
    {
        $changes = 0;
        $labels = is_array($irNode['labels'] ?? null) ? $irNode['labels'] : [];
        foreach (['Text', 'AccessibleLabel', 'Tooltip'] as $prop) {
            if (!isset($labels[$prop]) || !is_string($labels[$prop])) {
                continue;
            }
            $desired = trim($labels[$prop]);
            if ($desired === '' || $this->looksLikeFormula($desired)) {
                continue;
            }
            $current = $node->getProperty($prop);
            $currentClean = $current !== null ? $this->unwrap($current) : '';
            if ($currentClean === $desired) {
                continue;
            }
            // Heuristic: only overwrite when current is blank/generic or IR label is longer/more specific
            if (
                $currentClean !== ''
                && !$this->isGenericLabel($currentClean)
                && strlen($desired) < strlen($currentClean)
            ) {
                continue;
            }
            $to = $node->format === 'yaml'
                ? '="' . str_replace('"', '""', $desired) . '"'
                : '"' . str_replace('"', '""', $desired) . '"';
            $node->setProperty($prop, $to);
            $report->add('import_web_ir', $node->path, $prop, $current ?? '(unset)', $desired);
            $changes++;
        }

        $irChildren = is_array($irNode['children'] ?? null) ? $irNode['children'] : [];
        $liveChildren = $node->children;
        $claimed = [];

        foreach ($irChildren as $irChild) {
            if (!is_array($irChild)) {
                continue;
            }
            $match = $this->matchChild($irChild, $liveChildren, $claimed);
            if ($match === null) {
                continue;
            }
            $claimed[$match->name] = true;
            $changes += $this->applyControlTree($match, $irChild, $report);
        }

        return $changes;
    }

    /**
     * Match an IR child to a live control: exact name → fuzzy name → role+label.
     *
     * @param array<string, mixed> $irChild
     * @param list<ControlNode> $liveChildren
     * @param array<string, true> $claimed
     */
    private function matchChild(array $irChild, array $liveChildren, array $claimed): ?ControlNode
    {
        $cname = (string) ($irChild['name'] ?? '');
        $byName = [];
        foreach ($liveChildren as $child) {
            if (isset($claimed[$child->name])) {
                continue;
            }
            $byName[$child->name] = $child;
        }

        if ($cname !== '' && isset($byName[$cname])) {
            return $byName[$cname];
        }

        if ($cname !== '') {
            $hit = StringSimilarity::bestMatch($cname, array_keys($byName), 3);
            if ($hit !== null && $hit['score'] >= 88.0) {
                return $byName[$hit['match']];
            }
        }

        $wantRole = strtolower((string) ($irChild['role'] ?? ''));
        $wantText = '';
        $labels = is_array($irChild['labels'] ?? null) ? $irChild['labels'] : [];
        foreach (['Text', 'AccessibleLabel', 'Tooltip'] as $prop) {
            if (isset($labels[$prop]) && is_string($labels[$prop]) && trim($labels[$prop]) !== '') {
                $wantText = trim($labels[$prop]);
                break;
            }
        }
        if ($wantRole === '' && $wantText === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($byName as $child) {
            $role = $this->inferLiveRole($child);
            $score = 0.0;
            if ($wantRole !== '' && $role === $wantRole) {
                $score += 40.0;
            }
            if ($wantText !== '') {
                $liveText = $this->primaryLabel($child);
                if ($liveText !== '') {
                    similar_text(strtolower($wantText), strtolower($liveText), $pct);
                    $score += $pct * 0.6;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $child;
            }
        }

        // Require a meaningful role+label agreement (not name-only luck).
        return ($best !== null && $bestScore >= 70.0) ? $best : null;
    }

    private function inferLiveRole(ControlNode $control): string
    {
        if ($control->isScreen()) {
            return 'screen';
        }
        $t = strtolower($control->type);
        return match (true) {
            str_contains($t, 'gallery') => 'gallery',
            str_contains($t, 'form') => 'form',
            str_contains($t, 'button') => 'button',
            str_contains($t, 'label') || (str_contains($t, 'text') && !str_contains($t, 'input')) => 'label',
            str_contains($t, 'textinput') || str_contains($t, 'combobox') || str_contains($t, 'dropdown')
                || str_contains($t, 'datepicker') || str_contains($t, 'numberinput') => 'input',
            str_contains($t, 'toggle') || str_contains($t, 'checkbox') || str_contains($t, 'radio') => 'choice',
            str_contains($t, 'icon') || str_contains($t, 'image') => 'media',
            str_contains($t, 'container') || str_contains($t, 'group') => 'container',
            str_contains($t, 'html') => 'html',
            default => 'control',
        };
    }

    private function primaryLabel(ControlNode $control): string
    {
        foreach (['Text', 'AccessibleLabel', 'Tooltip', 'HintText'] as $prop) {
            $v = $control->getProperty($prop);
            if ($v === null || trim($v) === '') {
                continue;
            }
            $clean = $this->unwrap($v);
            if ($clean !== '' && !$this->looksLikeFormula($clean)) {
                return $clean;
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $irScreens
     * @param list<string> $liveNames
     * @return array<string, string> old => new
     */
    private function inferScreenRenames(array $irScreens, array $liveNames): array
    {
        $map = [];
        foreach ($irScreens as $s) {
            if (!is_array($s)) {
                continue;
            }
            $name = (string) ($s['name'] ?? '');
            $prev = (string) ($s['previous_name'] ?? '');
            if ($name === '' || $prev === '' || $prev === $name) {
                continue;
            }
            // Only rewrite Navigate when the destination screen already exists under the new name.
            if (in_array($name, $liveNames, true)) {
                $map[$prev] = $name;
            }
        }

        return $map;
    }

    /**
     * @param list<ControlDocument> $documents
     * @param array<string, string> $renames
     */
    private function rewriteNavigateTargets(array $documents, array $renames, Report $report): int
    {
        $changes = 0;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['OnSelect', 'OnChange', 'OnVisible', 'OnStart', 'OnTimerEnd'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || trim($from) === '') {
                        continue;
                    }
                    $to = $from;
                    foreach ($renames as $old => $new) {
                        $patterns = [
                            "/Navigate\\s*\\(\\s*'" . preg_quote($old, '/') . "'/" => "Navigate('" . $new . "'",
                            '/Navigate\\s*\\(\\s*"' . preg_quote($old, '/') . '"/' => 'Navigate("' . $new . '"',
                        ];
                        if (preg_match('/^[A-Za-z_][\w]*$/', $old) && preg_match('/^[A-Za-z_][\w]*$/', $new)) {
                            $patterns['/Navigate\\s*\\(\\s*' . preg_quote($old, '/') . '\\b/'] = 'Navigate(' . $new;
                        }
                        foreach ($patterns as $pattern => $replacement) {
                            $replaced = preg_replace($pattern, $replacement, $to);
                            if (is_string($replaced)) {
                                $to = $replaced;
                            }
                        }
                    }
                    if ($to !== $from) {
                        $control->setProperty($prop, $to);
                        $report->add('import_web_ir', $control->path, $prop, 'Navigate targets', 'renamed via IR');
                        $changes++;
                    }
                }
            }
        }

        return $changes;
    }

    private function unwrap(string $value): string
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = substr($v, 1);
        }
        $v = trim($v);
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"'))
            || (str_starts_with($v, "'") && str_ends_with($v, "'"))
        ) {
            $v = substr($v, 1, -1);
        }

        return trim($v);
    }

    private function looksLikeFormula(string $value): bool
    {
        return (bool) preg_match('/\b(If|Switch|LookUp|Filter|Navigate|Set|gblTheme)\b/i', $value);
    }

    private function isGenericLabel(string $value): bool
    {
        return (bool) preg_match(
            '/^(Button|Label|Icon|Image|Container|Gallery|Text|TextInput|Checkbox|Toggle)\\d*(_\\d+)?$/i',
            trim($value)
        );
    }
}
