<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\ControlNode;
use PowerSweeper\FormulaIdentifierRewriter;
use PowerSweeper\Report;
use PowerSweeper\StringSimilarity;

/**
 * Apply a structural web IR back onto canvas documents using heuristics.
 *
 * Safe updates only:
 * - Document layout / DocumentAppType in Properties.json
 * - Labels, literal layout, literal Visible/DisplayMode/TabIndex
 * - Non-screen control renames via previous_name → name (+ formula identifier rewrite)
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
        $docByScreen = $this->indexScreenDocuments($documents);
        $irScreens = is_array($ir['screens'] ?? null) ? $ir['screens'] : [];
        $usedNames = $this->collectUsedNames($documents);
        /** @var list<array{doc:ControlDocument,control:ControlNode,new:string,old:string}> */
        $pendingRenames = [];

        foreach ($irScreens as $irScreen) {
            if (!is_array($irScreen)) {
                continue;
            }
            $liveName = $this->resolveLiveScreenName($irScreen, array_keys($screenMap));
            if ($liveName === null || !isset($screenMap[$liveName])) {
                continue;
            }
            $doc = $docByScreen[$liveName] ?? null;
            if ($doc === null) {
                continue;
            }
            $n = $this->applyControlTree(
                $screenMap[$liveName],
                $irScreen,
                $report,
                $doc,
                $usedNames,
                $pendingRenames,
            );
            $changes += $n;
        }

        $n = $this->applyPendingRenames($pendingRenames, $documents, $report);
        $changes += $n;
        if ($n > 0) {
            $notes[] = 'control renames: ' . $n;
        }

        $screenRenames = $this->applyScreenRenames($documents, $irScreens, $extractDir, $report);
        $changes += count($screenRenames);
        if ($screenRenames !== []) {
            $notes[] = 'screen renames: ' . count($screenRenames);
        }

        // Also rewrite Navigate/SetFocus when IR points at a screen that already existed under the new name.
        $navRenames = $this->inferScreenRenames($irScreens, $this->liveScreenNames($documents));
        $navRenames = array_merge($screenRenames, $navRenames);
        if ($navRenames !== []) {
            $n = $this->rewriteNavAndFocusTargets($documents, $navRenames, $report);
            $changes += $n;
            if ($n > 0) {
                $notes[] = 'navigation/focus rewrites: ' . $n;
            }
        }

        $n = $this->parkSetFocusAfterNavigate($documents, $ir, $report);
        $changes += $n;
        if ($n > 0) {
            $notes[] = 'SetFocus park on OnVisible: ' . $n;
        }

        return ['changes' => $changes, 'notes' => $notes];
    }

    /**
     * @param list<ControlDocument> $documents
     * @return list<string>
     */
    private function liveScreenNames(array $documents): array
    {
        return array_keys($this->indexScreens($documents));
    }

    /**
     * Rename live screens when IR name ≠ previous_name and the new name is free.
     *
     * @param list<ControlDocument> $documents
     * @param list<array<string, mixed>> $irScreens
     * @return array<string, string> old => new
     */
    private function applyScreenRenames(
        array $documents,
        array $irScreens,
        ?string $extractDir,
        Report $report,
    ): array {
        $docByScreen = $this->indexScreenDocuments($documents);
        $used = $this->collectUsedNames($documents);
        $map = [];

        foreach ($irScreens as $irScreen) {
            if (!is_array($irScreen)) {
                continue;
            }
            $name = (string) ($irScreen['name'] ?? '');
            $prev = (string) ($irScreen['previous_name'] ?? '');
            if ($name === '' || $prev === '' || $name === $prev) {
                continue;
            }
            if (!isset($docByScreen[$prev]) || isset($used[$name])) {
                continue;
            }
            if (!ControlNaming::isValidScreenName($name)) {
                continue;
            }

            $doc = $docByScreen[$prev];
            $screenNode = null;
            foreach ($doc->controls() as $c) {
                if ($c->isScreen() && $c->name === $prev) {
                    $screenNode = $c;
                    break;
                }
            }
            if ($screenNode === null || !$doc->renameControl($screenNode, $name)) {
                continue;
            }

            $oldRel = $doc->relativePath;
            if ($extractDir !== null && $extractDir !== '') {
                $stem = ControlNaming::screenFileStem($name);
                $newRel = null;
                if (str_starts_with($oldRel, 'Src/') && str_ends_with(strtolower($oldRel), '.pa.yaml')) {
                    $newRel = dirname($oldRel) . '/' . $stem . '.pa.yaml';
                } elseif (str_starts_with($oldRel, 'Controls/') && str_ends_with(strtolower($oldRel), '.json')) {
                    $newRel = 'Controls/' . $stem . '.json';
                }
                if ($newRel !== null) {
                    $newRel = str_replace('\\', '/', $newRel);
                    if ($newRel !== $oldRel) {
                        $absOld = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldRel);
                        $absNew = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRel);
                        if (is_file($absOld) && !file_exists($absNew) && @rename($absOld, $absNew)) {
                            $doc->relativePath = $newRel;
                        }
                    }
                }
            }

            $doc->reindex();
            $map[$prev] = $name;
            $used[$name] = true;
            unset($used[$prev]);
            $report->add('import_web_ir', $oldRel, 'Screen', $prev, $name);
        }

        if ($map !== []) {
            foreach ($documents as $doc) {
                $doc->transformFormulas(static function (string $formula) use ($map): string {
                    return FormulaIdentifierRewriter::rename($formula, $map);
                });
            }
        }

        return $map;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, ControlDocument>
     */
    private function indexScreenDocuments(array $documents): array
    {
        $out = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $c) {
                if ($c->isScreen()) {
                    $out[$c->name] = $doc;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, true>
     */
    private function collectUsedNames(array $documents): array
    {
        $used = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $c) {
                $used[$c->name] = true;
            }
        }

        return $used;
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
     * @param array<string, true> $usedNames
     * @param list<array{doc:ControlDocument,control:ControlNode,new:string,old:string}> $pendingRenames
     */
    private function applyControlTree(
        ControlNode $node,
        array $irNode,
        Report $report,
        ControlDocument $doc,
        array &$usedNames,
        array &$pendingRenames,
    ): int {
        $changes = 0;
        $labels = is_array($irNode['labels'] ?? null) ? $irNode['labels'] : [];
        foreach (['Text', 'AccessibleLabel', 'Tooltip', 'HintText', 'HtmlText'] as $prop) {
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
            if ($prop === 'HtmlText') {
                // Markup: allow apply when blank/generic or both sides look like HTML (ignore length bias).
                if (
                    $currentClean !== ''
                    && !$this->isGenericLabel($currentClean)
                    && !str_contains($currentClean, '<')
                    && !str_contains($desired, '<')
                    && strlen($desired) < strlen($currentClean)
                ) {
                    continue;
                }
            } elseif (
                $currentClean !== ''
                && !$this->isGenericLabel($currentClean)
                && strlen($desired) < strlen($currentClean)
            ) {
                // Plain labels: only overwrite when IR text is longer/more specific
                continue;
            }
            $to = $node->format === 'yaml'
                ? '="' . str_replace('"', '""', $desired) . '"'
                : '"' . str_replace('"', '""', $desired) . '"';
            $node->setProperty($prop, $to);
            $report->add('import_web_ir', $node->path, $prop, $current ?? '(unset)', $desired);
            $changes++;
        }

        $layout = is_array($irNode['layout'] ?? null) ? $irNode['layout'] : [];
        $changes += $this->applyLiteralLayout($node, $layout, $report);

        $state = is_array($irNode['state'] ?? null) ? $irNode['state'] : [];
        $changes += $this->applyLiteralState($node, $state, $report);

        // Queue non-screen renames when IR name diverged from previous_name.
        if (!$node->isScreen() && !$node->isApp()) {
            $irName = (string) ($irNode['name'] ?? '');
            $prev = (string) ($irNode['previous_name'] ?? '');
            if (
                $irName !== ''
                && $prev !== ''
                && $prev === $node->name
                && $irName !== $prev
                && ControlNaming::isValidIdentifier($irName)
                && !isset($usedNames[$irName])
            ) {
                $pendingRenames[] = [
                    'doc' => $doc,
                    'control' => $node,
                    'new' => $irName,
                    'old' => $prev,
                ];
                $usedNames[$irName] = true;
            }
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
            $changes += $this->applyControlTree($match, $irChild, $report, $doc, $usedNames, $pendingRenames);
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function applyLiteralState(ControlNode $node, array $state, Report $report): int
    {
        $changes = 0;
        $map = [
            'visible' => 'Visible',
            'display_mode' => 'DisplayMode',
            'tab_index' => 'TabIndex',
            'focused_border_thickness' => 'FocusedBorderThickness',
            'focused_border_color' => 'FocusedBorderColor',
        ];
        foreach ($map as $irKey => $prop) {
            if (!array_key_exists($irKey, $state)) {
                continue;
            }
            $desiredRaw = $state[$irKey];
            $current = $node->getProperty($prop);
            $clean = $current !== null ? $this->unwrap($current) : '';
            $canCreate = (is_string($desiredRaw) && preg_match('/^DisplayMode\.\w+$/i', (string) $desiredRaw))
                || ($prop === 'FocusedBorderColor' && is_string($desiredRaw) && \PowerSweeper\ColorValue::parse((string) $desiredRaw) !== null)
                || ($prop === 'FocusedBorderThickness' && (is_int($desiredRaw) || (is_string($desiredRaw) && is_numeric($desiredRaw))));
            // Allow creating DisplayMode / focus chrome when unset; skip other props if missing/formula-driven.
            if ($current === null && !$canCreate) {
                continue;
            }
            if ($clean !== '' && $this->looksLikeFormula($clean)) {
                continue;
            }

            if (is_bool($desiredRaw)) {
                $desired = $desiredRaw ? 'true' : 'false';
                if (strcasecmp($clean, $desired) === 0) {
                    continue;
                }
                $to = $node->format === 'yaml' ? ('=' . $desired) : $desired;
            } elseif (is_int($desiredRaw) || (is_string($desiredRaw) && is_numeric($desiredRaw))) {
                $desiredN = (int) $desiredRaw;
                if ($clean !== '' && is_numeric($clean) && (int) round((float) $clean) === $desiredN) {
                    continue;
                }
                if ($clean !== '' && !is_numeric($clean)) {
                    continue;
                }
                $to = $node->format === 'yaml' ? ('=' . $desiredN) : (string) $desiredN;
                $desired = (string) $desiredN;
            } elseif (is_string($desiredRaw) && preg_match('/^DisplayMode\.\w+$/i', $desiredRaw)) {
                if (strcasecmp($clean, $desiredRaw) === 0) {
                    continue;
                }
                $to = $node->format === 'yaml' ? ('=' . $desiredRaw) : $desiredRaw;
                $desired = $desiredRaw;
            } elseif ($prop === 'FocusedBorderColor' && is_string($desiredRaw) && \PowerSweeper\ColorValue::parse($desiredRaw) !== null) {
                if (strcasecmp(str_replace(' ', '', $clean), str_replace(' ', '', $desiredRaw)) === 0) {
                    continue;
                }
                $to = $node->format === 'yaml' ? ('=' . $desiredRaw) : $desiredRaw;
                $desired = $desiredRaw;
            } else {
                continue;
            }

            $node->setProperty($prop, $to);
            $report->add('import_web_ir', $node->path, $prop, $clean !== '' ? $clean : '(unset)', $desired);
            $changes++;
        }

        return $changes;
    }

    /**
     * @param list<array{doc:ControlDocument,control:ControlNode,new:string,old:string}> $pending
     * @param list<ControlDocument> $documents
     */
    private function applyPendingRenames(array $pending, array $documents, Report $report): int
    {
        if ($pending === []) {
            return 0;
        }

        usort($pending, static function (array $a, array $b): int {
            return substr_count($b['control']->path, '/') <=> substr_count($a['control']->path, '/');
        });

        $map = [];
        $changes = 0;
        foreach ($pending as $item) {
            $old = $item['old'];
            $new = $item['new'];
            if (!$item['doc']->renameControl($item['control'], $new)) {
                continue;
            }
            $map[$old] = $new;
            $report->add('import_web_ir', $item['control']->path, 'Name', $old, $new);
            $changes++;
            $item['doc']->reindex();
        }

        if ($map === []) {
            return 0;
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(static function (string $formula) use ($map): string {
                return FormulaIdentifierRewriter::rename($formula, $map);
            });
        }

        return $changes;
    }

    /**
     * Apply IR layout when both sides are literal numbers (skip formula-driven X/Y/Width/Height).
     *
     * @param array<string, mixed> $layout
     */
    private function applyLiteralLayout(ControlNode $node, array $layout, Report $report): int
    {
        $changes = 0;
        $map = [
            'x' => 'X',
            'y' => 'Y',
            'width' => 'Width',
            'height' => 'Height',
        ];
        foreach ($map as $irKey => $prop) {
            if (!isset($layout[$irKey]) || !is_numeric($layout[$irKey])) {
                continue;
            }
            $desired = (int) round((float) $layout[$irKey]);
            $current = $node->getProperty($prop);
            if ($current === null) {
                continue;
            }
            $clean = $this->unwrap($current);
            if ($clean === '' || !is_numeric($clean) || $this->looksLikeFormula($clean)) {
                continue;
            }
            $currentN = (int) round((float) $clean);
            if ($currentN === $desired) {
                continue;
            }
            $to = $node->format === 'yaml' ? ('=' . $desired) : (string) $desired;
            $node->setProperty($prop, $to);
            $report->add('import_web_ir', $node->path, $prop, (string) $currentN, (string) $desired);
            $changes++;
        }

        return $changes;
    }

    /**
     * Match an IR child to a live control: previous_name → exact name → fuzzy name → role+label.
     *
     * @param array<string, mixed> $irChild
     * @param list<ControlNode> $liveChildren
     * @param array<string, true> $claimed
     */
    private function matchChild(array $irChild, array $liveChildren, array $claimed): ?ControlNode
    {
        $cname = (string) ($irChild['name'] ?? '');
        $prev = (string) ($irChild['previous_name'] ?? '');
        $byName = [];
        foreach ($liveChildren as $child) {
            if (isset($claimed[$child->name])) {
                continue;
            }
            $byName[$child->name] = $child;
        }

        if ($prev !== '' && isset($byName[$prev])) {
            return $byName[$prev];
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
        if ($prev !== '') {
            $hit = StringSimilarity::bestMatch($prev, array_keys($byName), 3);
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
     * When a formula Navigates to screen B and SetFocus(X) where X lives on B,
     * park SetFocus(X) on B.OnVisible (cross-screen SetFocus is ignored by Studio).
     *
     * @param list<ControlDocument> $documents
     * @param array<string, mixed> $ir
     */
    private function parkSetFocusAfterNavigate(array $documents, array $ir, Report $report): int
    {
        $controlScreen = [];
        $screenNodes = [];
        // YAML/JSON walks list children before the screen root — map via each screen's subtree.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $c) {
                if (!$c->isScreen()) {
                    continue;
                }
                $screenNodes[$c->name] = $c;
                $stack = [$c];
                while ($stack !== []) {
                    $node = array_pop($stack);
                    $controlScreen[$node->name] = $c->name;
                    foreach ($node->children as $child) {
                        $stack[] = $child;
                    }
                }
            }
        }

        /** @var array<string, list<string>> screen => focus targets */
        $parks = [];

        // From live formulas: Navigate(B) + SetFocus(X) in the same property
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['OnSelect', 'OnChange', 'OnTimerEnd', 'OnCheck'] as $prop) {
                    $formula = $control->getProperty($prop);
                    if ($formula === null || !str_contains($formula, 'Navigate') || !str_contains($formula, 'SetFocus')) {
                        continue;
                    }
                    $navTargets = [];
                    if (preg_match_all("/Navigate\\s*\\(\\s*('([^']+)'|\"([^\"]+)\"|([A-Za-z_][\\w]*))/i", $formula, $m, PREG_SET_ORDER)) {
                        foreach ($m as $match) {
                            $t = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
                            if ($t !== '') {
                                $navTargets[] = $t;
                            }
                        }
                    }
                    $focusTargets = [];
                    if (preg_match_all("/SetFocus\\s*\\(\\s*('([^']+)'|\"([^\"]+)\"|([A-Za-z_][\\w]*))/i", $formula, $m, PREG_SET_ORDER)) {
                        foreach ($m as $match) {
                            $t = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
                            if ($t !== '') {
                                $focusTargets[] = $t;
                            }
                        }
                    }
                    foreach ($navTargets as $dest) {
                        foreach ($focusTargets as $focus) {
                            if (($controlScreen[$focus] ?? null) === $dest) {
                                $parks[$dest][] = $focus;
                            }
                        }
                    }
                }
            }
        }

        // From IR edges: navigate A→B + setfocus A→X where X on B
        $nav = is_array($ir['navigation'] ?? null) ? $ir['navigation'] : [];
        $byFrom = [];
        foreach ($nav as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $from = (string) ($edge['from'] ?? '');
            $byFrom[$from][] = $edge;
        }
        foreach ($byFrom as $edges) {
            $dests = [];
            $focuses = [];
            foreach ($edges as $edge) {
                $kind = (string) ($edge['kind'] ?? 'navigate');
                $to = (string) ($edge['to'] ?? '');
                if ($to === '') {
                    continue;
                }
                if ($kind === 'setfocus') {
                    $focuses[] = $to;
                } else {
                    $dests[] = $to;
                }
            }
            foreach ($dests as $dest) {
                foreach ($focuses as $focus) {
                    if (($controlScreen[$focus] ?? null) === $dest) {
                        $parks[$dest][] = $focus;
                    }
                }
            }
        }

        $changes = 0;
        foreach ($parks as $screen => $targets) {
            if (!isset($screenNodes[$screen])) {
                continue;
            }
            foreach (array_values(array_unique($targets)) as $target) {
                $stmt = 'SetFocus(' . (preg_match('/^[A-Za-z_][\w]*$/', $target) ? $target : ("'" . str_replace("'", "''", $target) . "'")) . ')';
                $before = (string) ($screenNodes[$screen]->getProperty('OnVisible') ?? '');
                $screenNodes[$screen]->appendStatement('OnVisible', $stmt);
                $after = (string) ($screenNodes[$screen]->getProperty('OnVisible') ?? '');
                if ($after !== $before) {
                    $report->add('import_web_ir', $screenNodes[$screen]->path, 'OnVisible', 'SetFocus park', $stmt);
                    $changes++;
                }
            }
        }

        return $changes;
    }

    /**
     * @param list<ControlDocument> $documents
     * @param array<string, string> $renames
     */
    private function rewriteNavAndFocusTargets(array $documents, array $renames, Report $report): int
    {
        $changes = 0;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['OnSelect', 'OnChange', 'OnCheck', 'OnUncheck', 'OnVisible', 'OnStart', 'OnTimerEnd'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || trim($from) === '') {
                        continue;
                    }
                    $to = $from;
                    foreach ($renames as $old => $new) {
                        foreach (['Navigate', 'SetFocus'] as $fn) {
                            $patterns = [
                                '/' . $fn . '\\s*\\(\\s*\'' . preg_quote($old, '/') . '\'/' => $fn . "('" . $new . "'",
                                '/' . $fn . '\\s*\\(\\s*"' . preg_quote($old, '/') . '"/' => $fn . '("' . $new . '"',
                            ];
                            if (preg_match('/^[A-Za-z_][\w]*$/', $old) && preg_match('/^[A-Za-z_][\w]*$/', $new)) {
                                $patterns['/' . $fn . '\\s*\\(\\s*' . preg_quote($old, '/') . '\\b/'] = $fn . '(' . $new;
                            }
                            foreach ($patterns as $pattern => $replacement) {
                                $replaced = preg_replace($pattern, $replacement, $to);
                                if (is_string($replaced)) {
                                    $to = $replaced;
                                }
                            }
                        }
                    }
                    if ($to !== $from) {
                        $control->setProperty($prop, $to);
                        $report->add('import_web_ir', $control->path, $prop, 'Navigate/SetFocus targets', 'renamed via IR');
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
