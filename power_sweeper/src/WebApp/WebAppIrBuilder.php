<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;

/**
 * Build a full conversion file from a canvas .msapp.
 *
 * Version 2 stores every control property as formula text so the trip back to
 * Power Apps and the web package both see the same app.
 */
final class WebAppIrBuilder
{
    private const IR_VERSION = 2;

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
     * @param array{runtime?:string,storage?:string,layout?:string,server_language?:string} $exportOptions
     * @return array<string, mixed>
     */
    public function build(array $documents, ?string $extractDir = null, array $exportOptions = []): array
    {
        $screens = [];
        $app = null;
        $components = [];
        $navEdges = [];
        $themeTokens = [];
        $datasources = [];

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($this->isScreenNode($control)) {
                    $serialized = $this->serializeControl($control, $themeTokens, $navEdges, true);
                    $existing = $screens[$control->name] ?? null;
                    if ($existing === null || $this->prefersOver($serialized, $existing)) {
                        $screens[$control->name] = $serialized;
                    }
                } elseif ($control->isApp()) {
                    $serialized = $this->serializeControl($control, $themeTokens, $navEdges, false);
                    $serialized['kind'] = 'app';
                    if ($app === null || $this->prefersOver($serialized, $app)) {
                        $app = $serialized;
                    }
                } elseif ($this->isComponentDefinitionRoot($control)) {
                    $serialized = $this->serializeControl($control, $themeTokens, $navEdges, false);
                    $serialized['kind'] = 'component';
                    $existing = $components[$control->name] ?? null;
                    if ($existing === null || $this->prefersOver($serialized, $existing)) {
                        $components[$control->name] = $serialized;
                    }
                }
            }
            $this->collectDatasourceHints($doc, $datasources);
        }
        $this->enrichDatasourcesFromPackage($extractDir, $datasources);
        $collections = $this->harvestCollections($app);
        $media = $this->readMediaResources($extractDir);

        $screens = array_values($screens);
        usort($screens, static fn(array $a, array $b): int => ($a['name'] ?? '') <=> ($b['name'] ?? ''));

        $nav = $this->uniqueEdges($navEdges);
        $runtime = self::normalizeRuntime((string) ($exportOptions['runtime'] ?? $exportOptions['server_language'] ?? 'php'));
        $storage = self::normalizeStorage((string) ($exportOptions['storage'] ?? 'xml'));
        $layout = self::normalizeLayout((string) ($exportOptions['layout'] ?? 'canvas'));

        $tables = $this->buildTableSchemas($datasources, $extractDir);

        return [
            'format' => 'power_sweeper_web_ir',
            'version' => self::IR_VERSION,
            'generated_at' => gmdate('c'),
            'server_language' => $runtime,
            'export' => [
                'runtime' => $runtime,
                'storage' => $storage,
                'layout' => $layout,
            ],
            'fidelity' => [
                'level' => $layout,
                'notes' => [
                    'Every control property is stored as formula text',
                    'Web package executes formulas; import restores them onto the .msapp',
                    'runtime is php, python, or csharp; storage is xml or sql; layout is mvc, canvas, responsive, or wet (Web Experience Toolkit)',
                ],
            ],
            'document' => $this->readDocumentMeta($extractDir),
            'app' => $app,
            'components' => $components,
            'collections' => $collections,
            'media' => $media,
            'theme_tokens' => array_values(array_unique($themeTokens)),
            'datasources' => array_values($datasources),
            'tables' => $tables,
            'navigation' => $nav,
            // Canonical nav Anchor* → section tops (web) / mid-pane SetFocus (Power Apps).
            'section_anchors' => SectionAnchors::map(),
            'screens' => $screens,
            'stats' => [
                'screens' => count($screens),
                'controls' => $this->countControls($screens),
                'navigation_edges' => count($nav),
                'properties' => $this->countProperties($screens, $app),
                'tables' => count($tables),
            ],
        ];
    }

    public static function normalizeRuntime(string $value): string
    {
        $v = strtolower(trim($value));
        $compact = str_replace([' ', '_', '-'], '', $v);

        return match (true) {
            in_array($compact, ['python', 'py'], true) => 'python',
            in_array($compact, ['csharp', 'cs', 'c#'], true) => 'csharp',
            default => 'php',
        };
    }

    public static function normalizeStorage(string $value): string
    {
        $v = strtolower(trim($value));
        if (in_array($v, ['sql', 'sqlite'], true)) {
            return 'sql';
        }

        // lom was the earlier name for the XML document store.
        return 'xml';
    }

    public static function normalizeLayout(string $value): string
    {
        $v = strtolower(trim($value));
        $v = str_replace(['_', '-'], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        if (str_contains($v, 'mvc')) {
            return 'mvc';
        }
        // WET = Web Experience Toolkit (Canada.ca / GCWeb family).
        if ($v === 'wet' || str_contains($v, 'web experience') || preg_match('/\bwet\b/', $v) === 1) {
            return 'wet';
        }
        if ($v === 'modern' || str_contains($v, 'responsive')) {
            return 'responsive';
        }

        return 'canvas';
    }

    /**
     * Player settings for the web runtime. Design X/Y stay canvas pixels; the
     * published page scale-to-fits the device the way Power Apps do.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function withPlayer(array $document): array
    {
        $layout = is_array($document['layout'] ?? null) ? $document['layout'] : [];
        $existing = is_array($document['player'] ?? null) ? $document['player'] : [];
        $w = (int) ($existing['width'] ?? $layout['width'] ?? 0);
        $h = (int) ($existing['height'] ?? $layout['height'] ?? 0);
        if ($w <= 0) {
            $w = 1366;
        }
        if ($h <= 0) {
            $h = 768;
        }
        $document['player'] = [
            'scale_to_fit' => array_key_exists('scale_to_fit', $existing)
                ? (bool) $existing['scale_to_fit']
                : true,
            'maintain_aspect_ratio' => array_key_exists('maintain_aspect_ratio', $existing)
                ? (bool) $existing['maintain_aspect_ratio']
                : true,
            'width' => $w,
            'height' => $h,
        ];

        return $document;
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

        $properties = [];
        foreach ($control->propertyNames() as $propName) {
            $raw = $control->getProperty($propName);
            if ($raw === null) {
                continue;
            }
            $properties[$propName] = $raw;
        }

        foreach (self::NAV_PROPS as $prop) {
            $formula = $properties[$prop] ?? $control->getProperty($prop);
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

        foreach (['Fill', 'Color', 'BorderColor', 'FontColor', 'FocusedBorderColor'] as $prop) {
            $formula = $properties[$prop] ?? null;
            if ($formula !== null) {
                $this->collectThemeTokens($formula, $themeTokens);
            }
        }

        $node = [
            'name' => $control->name,
            'type' => $control->type,
            'role' => $role,
            'path' => $control->path,
            'previous_name' => $control->name,
            'properties' => $properties,
        ];

        $variant = $control->getYamlDefinitionField('Variant');
        if (is_string($variant) && $variant !== '') {
            $node['variant'] = $variant;
        }
        $componentName = $control->getYamlDefinitionField('ComponentName');
        if (is_string($componentName) && $componentName !== '') {
            $node['component'] = $componentName;
        }

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

        $style = $this->styleSnapshot($control, $properties);
        if ($style !== []) {
            $node['style'] = $style;
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
            // RichTextEditor contains "text" — classify before the generic text→label rule.
            str_contains($t, 'richtext') => 'richtext',
            str_contains($t, 'label') || (str_contains($t, 'text') && !str_contains($t, 'input')) => 'label',
            str_contains($t, 'textinput') || str_contains($t, 'combobox') || str_contains($t, 'dropdown')
                || str_contains($t, 'datepicker') || str_contains($t, 'numberinput') => 'input',
            str_contains($t, 'toggle') || str_contains($t, 'checkbox') || str_contains($t, 'radio') => 'choice',
            str_contains($t, 'icon') || str_contains($t, 'image') => 'media',
            str_contains($t, 'container') || str_contains($t, 'group') || str_contains($t, 'canvascomponent') => 'container',
            str_contains($t, 'html') => 'html',
            str_contains($t, 'tablist') => 'tabs',
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

    /**
     * @param array<string, string> $properties
     * @return array<string, string>
     */
    private function styleSnapshot(ControlNode $control, array $properties): array
    {
        $out = [];
        $map = [
            'Fill' => 'fill',
            'Color' => 'color',
            'Font' => 'font',
            'FontSize' => 'font_size',
            'FontWeight' => 'font_weight',
            'BorderColor' => 'border_color',
            'BorderThickness' => 'border_thickness',
            'BorderRadius' => 'border_radius',
            'RadiusTopLeft' => 'radius_top_left',
            'RadiusTopRight' => 'radius_top_right',
            'RadiusBottomLeft' => 'radius_bottom_left',
            'RadiusBottomRight' => 'radius_bottom_right',
            'PaddingTop' => 'padding_top',
            'PaddingRight' => 'padding_right',
            'PaddingBottom' => 'padding_bottom',
            'PaddingLeft' => 'padding_left',
            'Align' => 'align',
            'VerticalAlign' => 'vertical_align',
        ];
        foreach ($map as $prop => $key) {
            $val = $properties[$prop] ?? $control->getProperty($prop);
            if ($val === null || trim($val) === '') {
                continue;
            }
            $clean = $this->unwrapLiteral($val);
            if ($clean === '' || preg_match('/\b(If|Switch|LookUp|Filter|Navigate|Set)\s*\(/i', $clean)) {
                continue;
            }
            $out[$key] = $clean;
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

    /** @param list<array<string, mixed>> $screens */
    private function countProperties(array $screens, ?array $app): int
    {
        $n = 0;
        $walk = function (array $node) use (&$walk, &$n): void {
            $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $n += count($props);
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        foreach ($screens as $s) {
            $walk($s);
        }
        if ($app !== null) {
            $walk($app);
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
                        if (
                            preg_match('/\b(List|Tracking|User|Version|SharePoint|Package|PACS|THCEE|TDR|Approvals?|Directory|Pass|Incident|Items|Records|Table)\b/i', $name)
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

    /**
     * @param array<string, string>|list<string> $datasources
     * @return list<array{name:string,columns:list<string>,rows:list<array<string,mixed>>}>
     */
    private function buildTableSchemas(array $datasources, ?string $extractDir): array
    {
        $tables = [];
        $names = array_values($datasources);
        if ($names === []) {
            $names = ['Items'];
        }

        foreach ($names as $name) {
            $safe = preg_replace('/[^\w]+/', '_', (string) $name) ?: 'Items';
            $columns = ['Id', 'Title', 'Value'];
            $rows = [
                ['Id' => 1, 'Title' => 'Sample 1', 'Value' => 'A'],
                ['Id' => 2, 'Title' => 'Sample 2', 'Value' => 'B'],
            ];

            if ($extractDir !== null && $extractDir !== '') {
                try {
                    $catalog = \PowerSweeper\SharePoint\SharePointCatalog::loadFromExtractDir($extractDir);
                    foreach ($catalog->sources as $source) {
                        if (($source['name'] ?? '') !== $name) {
                            continue;
                        }
                        $cols = array_keys($source['columns'] ?? []);
                        if ($cols !== []) {
                            $columns = $this->uniqueColumns(array_merge(['Id'], $cols));
                        }
                    }
                } catch (\Throwable) {
                }
            }

            $tables[] = [
                'name' => (string) $name,
                'safe_name' => $safe,
                'columns' => $columns,
                'rows' => $rows,
            ];
        }

        return $tables;
    }

    /**
     * SQLite treats Id and ID as the same column.
     *
     * @param list<string> $cols
     * @return list<string>
     */
    private function uniqueColumns(array $cols): array
    {
        $seen = [];
        $out = [];
        foreach ($cols as $col) {
            $key = strtolower((string) $col);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = (string) $col;
        }

        return $out;
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

    private function isComponentDefinitionRoot(ControlNode $control): bool
    {
        return (bool) preg_match('#(?:^|/)ComponentDefinitions/[^/]+$#', $control->path);
    }

    private function isScreenNode(ControlNode $control): bool
    {
        if ($control->isScreen()) {
            return true;
        }

        if (preg_match('#(?:^|/)Screens/(.+)$#', $control->path, $m) !== 1) {
            return false;
        }

        return $m[1] === $control->name;
    }

    /**
     * YAML source keeps formula geometry; JSON twins often freeze stale numeric boxes.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $existing
     */
    private function prefersOver(array $candidate, array $existing): bool
    {
        $candYaml = str_contains((string) ($candidate['path'] ?? ''), '.pa.yaml');
        $existYaml = str_contains((string) ($existing['path'] ?? ''), '.pa.yaml');
        if ($candYaml !== $existYaml) {
            return $candYaml;
        }
        $candKids = count($candidate['children'] ?? []);
        $existKids = count($existing['children'] ?? []);

        return $candKids > $existKids;
    }

    /**
     * @param array<string, mixed>|null $app
     * @return array<string, list<array<string, string>>>
     */
    private function harvestCollections(?array $app): array
    {
        $blobs = [];
        $props = is_array($app['properties'] ?? null) ? $app['properties'] : [];
        foreach (['OnStart', 'Formulas', 'StartScreen'] as $key) {
            if (isset($props[$key]) && is_string($props[$key])) {
                $blobs[] = $props[$key];
            }
        }
        $out = [];
        foreach ($blobs as $blob) {
            if (preg_match_all('/ClearCollect\s*\(\s*([A-Za-z_][\w]*)\s*,(.*?)\)\s*;/s', $blob, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $rows = $this->parseRecordList($match[2]);
                    if ($rows !== []) {
                        $out[$match[1]] = $rows;
                    }
                }
            }
            if (preg_match_all('/\b([A-Za-z_][\w]*)\s*=\s*\[(.*?)\]\s*;/s', $blob, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    if (isset($out[$match[1]])) {
                        continue;
                    }
                    $rows = $this->parseRecordList($match[2]);
                    if ($rows !== []) {
                        $out[$match[1]] = $rows;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseRecordList(string $body): array
    {
        $rows = [];
        if (preg_match_all('/\{([^{}]+)\}/s', $body, $m)) {
            foreach ($m[1] as $record) {
                $row = [];
                if (preg_match_all('/([A-Za-z_][\w]*)\s*:\s*(?:"((?:\\\\.|[^"])*)"|\'((?:\\\\.|[^\'])*)\'|([^,\n]+))/', $record, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $quoted = ($pair[2] ?? '') !== '' || ($pair[3] ?? '') !== '';
                        $val = ($pair[2] ?? '') !== '' ? $pair[2]
                            : (($pair[3] ?? '') !== '' ? $pair[3] : trim((string) ($pair[4] ?? ''), " \t\"'"));
                        $val = stripcslashes(trim((string) $val));
                        // Keep quoted strings even when empty or containing "(" (org names).
                        // Skip only bare formula-like unquoted values.
                        if (!$quoted && ($val === '' || preg_match('/^[A-Za-z_][\w]*\s*\(/', $val) === 1)) {
                            continue;
                        }
                        $row[$pair[1]] = $val;
                    }
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<array{name:string,file:string,path:string}>
     */
    private function readMediaResources(?string $extractDir): array
    {
        if ($extractDir === null || $extractDir === '') {
            return [];
        }
        $path = $extractDir . DIRECTORY_SEPARATOR . 'References' . DIRECTORY_SEPARATOR . 'Resources.json';
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        $list = is_array($json['Resources'] ?? null) ? $json['Resources'] : [];
        $out = [];
        foreach ($list as $res) {
            if (!is_array($res)) {
                continue;
            }
            $name = (string) ($res['Name'] ?? '');
            $file = (string) ($res['FileName'] ?? '');
            $rel = str_replace('\\', '/', (string) ($res['Path'] ?? ''));
            if ($name === '' || $file === '' || str_starts_with($file, '/')) {
                continue;
            }
            if ($rel === '') {
                $rel = 'Assets/Images/' . $file;
            }
            $out[] = ['name' => $name, 'file' => $file, 'path' => $rel];
        }

        return $out;
    }
}
