<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;

/**
 * Build a structural intermediate representation (IR) from a canvas .msapp.
 *
 * Heuristics (not a full Power Fx runtime):
 * - Screen / control tree with typed roles
 * - Visible text, a11y, tooltips
 * - Navigate() targets → navigation graph
 * - Theme token names when gblTheme.* is referenced
 * - Document layout from Properties.json when present
 *
 * Round-trip fidelity is intentionally structural — formulas stay in the .msapp;
 * the IR is the edit surface for web-oriented tooling.
 */
final class WebAppIrBuilder
{
    private const IR_VERSION = 1;

    private const TEXT_PROPS = ['Text', 'HtmlText', 'AccessibleLabel', 'Tooltip', 'HintText', 'ContentLanguage'];

    private const NAV_PROPS = ['OnSelect', 'OnChange', 'OnCheck', 'OnUncheck', 'OnVisible', 'OnStart', 'OnTimerEnd'];

    /** Literal state props safe for structural round-trip (skip formula-driven values). */
    private const STATE_PROPS = [
        'Visible',
        'DisplayMode',
        'TabIndex',
        'FocusedBorderThickness',
        'FocusedBorderColor',
    ];

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, mixed>
     */
    public function build(array $documents, ?string $extractDir = null): array
    {
        $screens = [];
        $navEdges = [];
        $themeTokens = [];
        $datasources = [];

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isScreen()) {
                    $screens[] = $this->serializeControl($control, $themeTokens, $navEdges, true);
                }
            }
            $this->collectDatasourceHints($doc, $datasources);
        }
        $this->enrichDatasourcesFromPackage($extractDir, $datasources);

        usort($screens, static fn(array $a, array $b): int => ($a['name'] ?? '') <=> ($b['name'] ?? ''));

        $nav = $this->uniqueEdges($navEdges);

        return [
            'format' => 'power_sweeper_web_ir',
            'version' => self::IR_VERSION,
            'generated_at' => gmdate('c'),
            'fidelity' => [
                'level' => 'structural',
                'notes' => [
                    'Power Fx formulas remain authoritative in the .msapp',
                    'IR captures structure, labels, navigation edges, and document layout for heuristic round-trip',
                    'Not a full executable web runtime of the canvas app',
                ],
            ],
            'document' => $this->readDocumentMeta($extractDir),
            'theme_tokens' => array_values(array_unique($themeTokens)),
            'datasources' => array_values($datasources),
            'navigation' => $nav,
            'screens' => $screens,
            'stats' => [
                'screens' => count($screens),
                'controls' => $this->countControls($screens),
                'navigation_edges' => count($nav),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $themeTokens by-ref list collector (values appended)
     * @param list<array{from:string,to:string,via:string}> $navEdges
     * @return array<string, mixed>
     */
    private function serializeControl(ControlNode $control, array &$themeTokens, array &$navEdges, bool $isScreen = false): array
    {
        $role = $this->inferRole($control);
        $labels = [];
        foreach (self::TEXT_PROPS as $prop) {
            $val = $control->getProperty($prop);
            if ($val === null || trim($val) === '') {
                continue;
            }
            $clean = $this->unwrapLiteral($val);
            if ($clean !== '') {
                $labels[$prop] = $clean;
            }
        }

        foreach (self::NAV_PROPS as $prop) {
            $formula = $control->getProperty($prop);
            if ($formula === null || trim($formula) === '') {
                continue;
            }
            $this->collectThemeTokens($formula, $themeTokens);
            foreach ($this->extractNavigateTargets($formula) as $target) {
                $navEdges[] = [
                    'from' => $this->screenHint($control),
                    'to' => $target,
                    'via' => $control->path . '.' . $prop,
                    'control' => $control->name,
                    'kind' => 'navigate',
                ];
            }
            foreach ($this->extractSetFocusTargets($formula) as $target) {
                $navEdges[] = [
                    'from' => $this->screenHint($control),
                    'to' => $target,
                    'via' => $control->path . '.' . $prop,
                    'control' => $control->name,
                    'kind' => 'setfocus',
                ];
            }
        }

        // Theme refs on color props
        foreach (['Fill', 'Color', 'BorderColor', 'FontColor', 'FocusedBorderColor'] as $prop) {
            $formula = $control->getProperty($prop);
            if ($formula !== null) {
                $this->collectThemeTokens($formula, $themeTokens);
            }
        }

        $node = [
            'name' => $control->name,
            'type' => $control->type,
            'role' => $role,
            'path' => $control->path,
            // Round-trip anchor: web tooling / meaningful_names may rename `name`.
            'previous_name' => $control->name,
        ];
        if ($isScreen) {
            $node['kind'] = 'screen';
        }
        if ($labels !== []) {
            $node['labels'] = $labels;
        }

        $layout = $this->layoutSnapshot($control);
        if ($layout !== []) {
            $node['layout'] = $layout;
        }

        $state = $this->stateSnapshot($control);
        if ($state !== []) {
            $node['state'] = $state;
        }

        $children = [];
        foreach ($control->children as $child) {
            $children[] = $this->serializeControl($child, $themeTokens, $navEdges, false);
        }
        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }

    private function inferRole(ControlNode $control): string
    {
        if ($control->isScreen()) {
            return 'screen';
        }
        if ($control->isApp()) {
            return 'app';
        }
        $t = strtolower($control->type);
        return match (true) {
            str_contains($t, 'gallery') => 'gallery',
            str_contains($t, 'form') => 'form',
            str_contains($t, 'button') => 'button',
            str_contains($t, 'label') || str_contains($t, 'text') && !str_contains($t, 'input') => 'label',
            str_contains($t, 'textinput') || str_contains($t, 'combobox') || str_contains($t, 'dropdown')
                || str_contains($t, 'datepicker') || str_contains($t, 'numberinput') => 'input',
            str_contains($t, 'toggle') || str_contains($t, 'checkbox') || str_contains($t, 'radio') => 'choice',
            str_contains($t, 'icon') || str_contains($t, 'image') => 'media',
            str_contains($t, 'container') || str_contains($t, 'group') => 'container',
            str_contains($t, 'html') => 'html',
            default => 'control',
        };
    }

    /** @return array<string, int|string> */
    private function layoutSnapshot(ControlNode $control): array
    {
        $out = [];
        foreach (['X', 'Y', 'Width', 'Height'] as $prop) {
            $val = $control->getProperty($prop);
            if ($val === null) {
                continue;
            }
            $n = $this->unwrapLiteral($val);
            if ($n !== '' && is_numeric($n)) {
                $out[strtolower($prop)] = (int) round((float) $n);
            }
        }

        return $out;
    }

    /** @return array<string, bool|int|string> */
    private function stateSnapshot(ControlNode $control): array
    {
        $out = [];
        foreach (self::STATE_PROPS as $prop) {
            $val = $control->getProperty($prop);
            if ($val === null || trim($val) === '') {
                continue;
            }
            $clean = $this->unwrapLiteral($val);
            if ($clean === '' || preg_match('/\b(If|Switch|LookUp|Filter|Navigate|Set)\s*\(/i', $clean)) {
                continue;
            }
            $key = match ($prop) {
                'FocusedBorderThickness' => 'focused_border_thickness',
                'FocusedBorderColor' => 'focused_border_color',
                'DisplayMode' => 'display_mode',
                'TabIndex' => 'tab_index',
                default => strtolower($prop),
            };
            if (strcasecmp($clean, 'true') === 0 || strcasecmp($clean, 'false') === 0) {
                $out[$key] = strcasecmp($clean, 'true') === 0;
            } elseif (is_numeric($clean)) {
                $out[$key] = (int) round((float) $clean);
            } elseif (preg_match('/^DisplayMode\.\w+$/i', $clean)) {
                $out[$key] = $clean;
            } elseif ($prop === 'FocusedBorderColor' && \PowerSweeper\ColorValue::parse($clean) !== null) {
                $out[$key] = $clean;
            }
        }

        return $out;
    }

    private function unwrapLiteral(string $value): string
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

        // Skip complex formulas as "labels"
        if (preg_match('/\b(If|Switch|LookUp|Filter|Navigate|Set)\s*\(/i', $v)) {
            return '';
        }

        return trim($v);
    }

    /** @return list<string> */
    private function extractNavigateTargets(string $formula): array
    {
        $targets = [];
        if (preg_match_all("/Navigate\\s*\\(\\s*('([^']+)'|\"([^\"]+)\"|([A-Za-z_][\\w]*))/i", $formula, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $t = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
                if ($t !== '') {
                    $targets[] = $t;
                }
            }
        }

        return $targets;
    }

    /** @return list<string> */
    private function extractSetFocusTargets(string $formula): array
    {
        $targets = [];
        if (preg_match_all("/SetFocus\\s*\\(\\s*('([^']+)'|\"([^\"]+)\"|([A-Za-z_][\\w]*))/i", $formula, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $t = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
                if ($t !== '') {
                    $targets[] = $t;
                }
            }
        }

        return $targets;
    }

    /** @param list<string> $themeTokens */
    private function collectThemeTokens(string $formula, array &$themeTokens): void
    {
        if (preg_match_all('/\bgblTheme(?:Light|Dark)?\.([A-Za-z_][\w]*)/', $formula, $m)) {
            foreach ($m[1] as $token) {
                $themeTokens[] = $token;
            }
        }
    }

    private function screenHint(ControlNode $control): string
    {
        if (preg_match('#(?:Screens/|Src/)([^/]+)#', $control->path, $m)) {
            return html_entity_decode(str_replace([' _ ', '_'], [' / ', ' '], $m[1]));
        }
        $parts = explode('/', $control->path);

        return $parts[0] ?? $control->name;
    }

    /**
     * @param list<array{from:string,to:string,via:string,control?:string}> $edges
     * @return list<array{from:string,to:string,via:string,control?:string}>
     */
    private function uniqueEdges(array $edges): array
    {
        $seen = [];
        $out = [];
        foreach ($edges as $e) {
            $key = ($e['from'] ?? '') . '=>' . ($e['to'] ?? '') . '@' . ($e['control'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $e;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $screens */
    private function countControls(array $screens): int
    {
        $n = 0;
        $walk = function (array $node) use (&$walk, &$n): void {
            $n++;
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        foreach ($screens as $s) {
            $walk($s);
        }

        return $n;
    }

    /** @param array<string, string> $datasources */
    private function collectDatasourceHints(ControlDocument $doc, array &$datasources): void
    {
        foreach ($doc->controls() as $c) {
            foreach (['Items', 'DataSource', 'Default', 'OnSelect'] as $prop) {
                $v = $c->getProperty($prop);
                if ($v !== null && preg_match_all("/'([^']+)'/", $v, $m)) {
                    foreach ($m[1] as $name) {
                        // Broad heuristic for canvas list names across VCR / THCEE / ASC / TDR.
                        if (
                            preg_match('/\b(List|Tracking|User|Version|SharePoint|Package|PACS|THCEE|TDR|Approvals?|Directory|Pass|Incident)\b/i', $name)
                            || str_contains($name, '(L)')
                            || str_contains($name, 'VCDS')
                            || str_contains($name, 'CDLS')
                        ) {
                            $datasources[$name] = $name;
                        }
                    }
                }
            }
        }
    }

    /**
     * Prefer live SharePoint catalog names from the package when available.
     *
     * @param array<string, string> $datasources
     */
    private function enrichDatasourcesFromPackage(?string $extractDir, array &$datasources): void
    {
        if ($extractDir === null || $extractDir === '' || !is_dir($extractDir)) {
            return;
        }
        try {
            $catalog = \PowerSweeper\SharePoint\SharePointCatalog::loadFromExtractDir($extractDir);
        } catch (\Throwable) {
            return;
        }
        foreach ($catalog->sharePointListNames() as $name) {
            $datasources[$name] = $name;
        }
    }

    /** @return array<string, mixed> */
    private function readDocumentMeta(?string $extractDir): array
    {
        $meta = [
            'app_type' => null,
            'layout' => [],
        ];
        if ($extractDir === null || $extractDir === '') {
            return $meta;
        }
        $path = $extractDir . DIRECTORY_SEPARATOR . 'Properties.json';
        if (!is_file($path)) {
            return $meta;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return $meta;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $meta;
        }
        $meta['app_type'] = $json['DocumentAppType'] ?? null;
        $meta['name'] = $json['Name'] ?? null;
        $meta['layout'] = [
            'width' => $json['DocumentLayoutWidth'] ?? null,
            'height' => $json['DocumentLayoutHeight'] ?? null,
            'orientation' => $json['DocumentLayoutOrientation'] ?? null,
            'scale_to_fit' => $json['DocumentLayoutScaleToFit'] ?? null,
            'maintain_aspect_ratio' => $json['DocumentLayoutMaintainAspectRatio'] ?? null,
            'lock_orientation' => $json['DocumentLayoutLockOrientation'] ?? null,
        ];

        return $meta;
    }
}
