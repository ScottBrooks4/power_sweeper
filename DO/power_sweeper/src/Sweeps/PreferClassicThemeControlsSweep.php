<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\DocumentTwinMap;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Swap modern / Fluent controls that lack Fill or Color onto classic templates
 * so enable_dark_mode can bind gblTheme backgrounds and text colors.
 */
final class PreferClassicThemeControlsSweep implements SweepInterface
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $mapCache = null;

    public static function id(): string
    {
        return 'prefer_classic_theme_controls';
    }

    public static function label(): string
    {
        return 'Prefer classic controls for theming';
    }

    public static function description(): string
    {
        return 'Replace modern/Fluent controls that follow Appearance instead of Fill/Color with classic templates, then rewrite every formula reference (Self, screen-qualified, and cross-control) onto the classic property names so behavior stays the same. Run before enable_dark_mode.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $map = $this->loadMap($options);
        if ($map === []) {
            return;
        }

        $enabledOptional = [];
        foreach ($map as $entry) {
            $key = (string) ($entry['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!empty($entry['optional'])) {
                $optName = 'include_' . $key;
                $enabledOptional[$key] = !empty($options[$optName]) || !empty($options['include_optional']);
            }
        }

        $twins = new DocumentTwinMap($documents);
        // Names present on each YAML side of a twin pair (sync covers these on JSON).
        $yamlNamesByKey = [];
        foreach ($documents as $doc) {
            if ($doc->format !== 'yaml') {
                continue;
            }
            $names = [];
            foreach ($doc->controls() as $control) {
                $names[$control->name] = true;
            }
            $key = $this->twinKeyForDoc($doc);
            if ($key !== null) {
                $yamlNamesByKey[$key] = $names;
            }
        }

        /** @var list<array{name:string,key:string,itemsColumn:string}> $converted */
        $converted = [];
        foreach ($documents as $doc) {
            $touched = false;
            $twinKey = $this->twinKeyForDoc($doc);
            $skipNamed = ($doc->format === 'json' && $twins->isJsonTwin($doc) && $twinKey !== null)
                ? ($yamlNamesByKey[$twinKey] ?? [])
                : [];
            foreach ($doc->controls() as $control) {
                // JSON twin of a YAML control: convert once on YAML, sync later.
                if ($skipNamed !== [] && isset($skipNamed[$control->name])) {
                    continue;
                }
                $entry = $this->matchEntry($control->type, $map);
                if ($entry === null) {
                    continue;
                }
                $key = (string) $entry['key'];
                if (!empty($entry['optional']) && empty($enabledOptional[$key])) {
                    continue;
                }
                if ($this->alreadyTarget($control, $entry)) {
                    continue;
                }
                // Chrome-less modern controls (transparent buttons, icons) stay modern.
                // Converting them to classic invents an opaque Fill and a larger Size.
                if ($this->shouldLeaveModern($control, $key)) {
                    continue;
                }
                $wasTransparent = $this->isTransparentAppearance($control->getProperty('Appearance'));

                $fromType = $control->type;
                $this->preserveClassicTextInputMode($control, $key, $report);
                $this->preserveClassicFontSize($control, $key, $report);
                $this->remapProperties($control, $entry, $report);
                $this->seedClassicInputFill($control, $key, $report);
                $this->preserveClassicPickerFields($control, $key, $report);
                $this->preserveClassicRadioDefault($control, $key, $report);
                $this->preserveClassicRadioLayout($control, $report);
                $this->preserveClassicNumberFormat($control, $key, $report);
                $this->preserveClassicDateFormat($control, $key, $report);
                $this->preserveClassicFontSize($control, $key, $report);
                $this->preserveModernButtonChrome($control, $key);
                $fillNow = (string) ($control->getProperty('Fill') ?? '');
                // Neon save / transparent labeled links need classic paint (no Fluent
                // rest-shade / auto FontSize). Always drop the PCF blob on convert —
                // those strings dominate Controls/*.json RAM on THCEE-class apps.
                $lockClassicPaint = $wasTransparent
                    || preg_match('/RGBA\s*\(\s*8\s*,\s*222\s*,\s*8\b/i', $fillNow) === 1;
                if ($wasTransparent && ($key === 'modern_button' || str_contains(strtolower($control->type), 'button'))) {
                    $clear = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
                    $control->setProperty('Fill', $clear);
                    $control->setProperty('HoverFill', $clear);
                    $control->setProperty('PressedFill', $clear);
                    $control->setProperty('BorderThickness', $control->format === 'yaml' ? '=0' : '0');
                }
                $yaml = (string) $entry['yaml'];
                /** @var array{Id?:string,Name?:string,Version?:string} $json */
                $json = is_array($entry['json'] ?? null) ? $entry['json'] : [];
                $control->setControlType($yaml, $json, true);
                $report->add(self::id(), $control->path, 'Control', $fromType, $yaml);
                $converted[] = [
                    'name' => $control->name,
                    'key' => $key,
                    'itemsColumn' => $this->selectedItemsColumn($control),
                ];
                $touched = true;
            }
            if ($touched) {
                $doc->reindex();
            }
        }
        // Formula rewrites on YAML (+ JSON-only) only; JSON twins get a property sync.
        $rewriteDocs = [];
        foreach ($documents as $doc) {
            if (!$twins->isJsonTwin($doc)) {
                $rewriteDocs[] = $doc;
            }
        }
        $this->rewriteConvertedRefs($rewriteDocs, $converted, $report);
        $this->repairRadioControls($rewriteDocs, $report);
        $synced = $twins->syncYamlToJson();
        if ($synced > 0) {
            $report->add(self::id(), '(twins)', 'sync', 'yaml→json', (string) $synced . ' properties');
        }
    }

    private function twinKeyForDoc(ControlDocument $doc): ?string
    {
        // Mirror DocumentTwinMap keys without building a full map per call.
        $path = str_replace('\\', '/', $doc->relativePath);
        if ($doc->format === 'yaml') {
            if (str_starts_with($path, 'Src/App.')) {
                return 'screen:App';
            }
            if (str_starts_with($path, 'Src/Components/')) {
                return 'component:' . basename($path, '.pa.yaml');
            }
            if (str_starts_with($path, 'Src/') && str_ends_with($path, '.pa.yaml')) {
                $screen = $doc->screenName();

                return is_string($screen) && $screen !== '' ? 'screen:' . $screen : null;
            }
        }
        if ($doc->format === 'json') {
            if (str_starts_with($path, 'Controls/')) {
                $screen = $doc->screenName();

                return is_string($screen) && $screen !== '' ? 'screen:' . $screen : null;
            }
            if (str_starts_with($path, 'Components/')) {
                $data = $doc->rawData();
                if (is_object($data) && isset($data->TopParent) && is_object($data->TopParent)) {
                    $name = (string) ($data->TopParent->Name ?? '');
                    if ($name !== '') {
                        return 'component:' . $name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function loadMap(array $options): array
    {
        if (isset($options['control_map']) && is_array($options['control_map'])) {
            return array_values($options['control_map']);
        }

        $file = isset($options['control_map_file']) && is_string($options['control_map_file'])
            ? $options['control_map_file']
            : dirname(__DIR__, 2) . '/config/theme_control_map.php';

        if (self::$mapCache !== null && !isset($options['control_map_file'])) {
            return self::$mapCache;
        }

        if (!is_file($file)) {
            return [];
        }

        /** @var mixed $loaded */
        $loaded = include $file;
        $map = is_array($loaded) ? array_values($loaded) : [];
        if (!isset($options['control_map_file'])) {
            self::$mapCache = $map;
        }
        return $map;
    }

    /**
     * @param list<array<string, mixed>> $map
     * @return array<string, mixed>|null
     */
    private function matchEntry(string $type, array $map): ?array
    {
        $haystack = strtolower(trim($type));
        if ($haystack === '' || str_starts_with($haystack, 'classic/')) {
            return null;
        }

        foreach ($map as $entry) {
            $patterns = $entry['match'] ?? [];
            if (!is_array($patterns)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    continue;
                }
                if (@preg_match($pattern, $haystack) === 1) {
                    return $entry;
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $entry */
    private function alreadyTarget(ControlNode $control, array $entry): bool
    {
        $yaml = strtolower((string) ($entry['yaml'] ?? ''));
        $type = strtolower($control->type);
        if ($yaml !== '' && $type === $yaml) {
            return true;
        }
        $jsonName = strtolower((string) (($entry['json']['Name'] ?? '') ?: ''));
        if ($jsonName !== '' && ($type === $jsonName || str_ends_with($type, '/' . $jsonName))) {
            return true;
        }
        // Label@x is the modern_text target — don't re-match TextCanvas aliases incorrectly.
        if ($jsonName === 'label' && preg_match('#^label(?:@|$)#i', $control->type) === 1) {
            return true;
        }
        if ($jsonName === 'button' && preg_match('#^(?:classic/)?button@2\\.#i', $control->type) === 1) {
            return true;
        }
        if (in_array($jsonName, ['checkbox', 'toggle', 'radio', 'slider'], true)
            && preg_match('#^(?:classic/)?' . preg_quote($jsonName, '#') . '@2\\.#i', $control->type) === 1
        ) {
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $entry */
    private function remapProperties(ControlNode $control, array $entry, Report $report): void
    {
        $wasTransparent = $this->isTransparentAppearance($control->getProperty('Appearance'));
        $blankText = $this->isBlankButtonText($control->getProperty('Text'));
        $key = (string) ($entry['key'] ?? '');
        // Secondary / Outline / Subtle paint a neutral chip. BasePaletteColor is the
        // tint (border), not a solid fill — copying it turns Quitter into a red block.
        $neutralChrome = ($key === 'modern_button' || str_contains(strtolower($control->type), 'button'))
            && $this->isNeutralChromeAppearance($control->getProperty('Appearance'));
        $palette = $neutralChrome ? $control->getProperty('BasePaletteColor') : null;

        $map = $entry['property_map'] ?? [];
        if (is_array($map)) {
            foreach ($map as $fromProp => $toProp) {
                if (!is_string($fromProp) || !is_string($toProp) || $fromProp === '' || $toProp === '') {
                    continue;
                }
                if ($neutralChrome && strcasecmp($fromProp, 'BasePaletteColor') === 0 && strcasecmp($toProp, 'Fill') === 0) {
                    continue;
                }
                $fromVal = $control->getProperty($fromProp);
                if ($fromVal === null || trim($fromVal) === '' || trim($fromVal) === '=') {
                    continue;
                }
                $existing = $control->getProperty($toProp);
                if ($existing !== null && trim($existing) !== '' && trim($existing) !== '=') {
                    // Transparent Fluent CTAs often keep a studio-white Fill while the real
                    // chrome lives on BasePaletteColor — prefer the palette when Fill is default.
                    $preferPalette = $fromProp === 'BasePaletteColor'
                        && $toProp === 'Fill'
                        && ColorValue::isStudioDefault((string) $existing, 'Fill');
                    // Fluent Size is a control-size token (0 / 14 / Medium), not type size.
                    // FontSize is the visible label size and must win for classic Size.
                    $preferFontSize = $fromProp === 'FontSize'
                        && $toProp === 'Size'
                        && !$this->isUnusableClassicFontSize($this->formulaBody($fromVal));
                    $replaceZeroSize = $toProp === 'Size'
                        && $this->isUnusableClassicFontSize($this->formulaBody($existing));
                    if (!$preferPalette && !$preferFontSize && !$replaceZeroSize) {
                        continue;
                    }
                }
                $control->setProperty($toProp, $fromVal);
                $report->add(self::id(), $control->path, $toProp, $existing ?? '(unset)', $fromVal);
            }
        }

        $remove = $entry['remove'] ?? [];
        if (is_array($remove)) {
            foreach ($remove as $prop) {
                if (!is_string($prop) || $prop === '') {
                    continue;
                }
                // FontSize is the modern label size. Classic reads Size, but the
                // converted template still exposes FontSize — dropping the rule lets
                // dark mode fall back to a larger default.
                if (strcasecmp($prop, 'FontSize') === 0 && ($key === 'modern_button' || str_contains(strtolower($control->type), 'button'))) {
                    continue;
                }
                $before = $control->getProperty($prop);
                if ($before === null) {
                    continue;
                }
                $control->removeProperty($prop);
                $report->add(self::id(), $control->path, $prop, $before, '(removed)');
            }
        }

        // Sidebar / gallery hit-targets use Transparent modern buttons over icons.
        // Classic buttons default to an opaque Fill and cover the glyph (black squares).
        if (($key === 'modern_button' || str_contains(strtolower($control->type), 'button'))
            && $wasTransparent
            && $blankText
        ) {
            $transparent = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
            $fill = $control->getProperty('Fill');
            $fillStr = $fill !== null ? (string) $fill : '';
            if ($fillStr === '' || ColorValue::isStudioDefault($fillStr, 'Fill')) {
                $control->setProperty('Fill', $transparent);
                $report->add(self::id(), $control->path, 'Fill', $fillStr !== '' ? $fillStr : '(unset)', $transparent);
            }
        }

        if ($neutralChrome && !$wasTransparent && is_string($palette) && trim($palette) !== '' && trim($palette) !== '=') {
            $fill = $control->getProperty('Fill');
            $fillStr = $fill !== null ? trim((string) $fill) : '';
            if ($fillStr === '' || $fillStr === '=' || ColorValue::isStudioDefault($fillStr, 'Fill')) {
                $white = $control->format === 'yaml' ? '=RGBA(255, 255, 255, 1)' : 'RGBA(255, 255, 255, 1)';
                $control->setProperty('Fill', $white);
                $report->add(self::id(), $control->path, 'Fill', $fillStr !== '' ? $fillStr : '(unset)', $white);
            }
            $border = $control->getProperty('BorderColor');
            $borderStr = $border !== null ? trim((string) $border) : '';
            if ($borderStr === '' || $borderStr === '=' || ColorValue::isStudioDefault($borderStr, 'BorderColor')) {
                $control->setProperty('BorderColor', $palette);
                $report->add(self::id(), $control->path, 'BorderColor', $borderStr !== '' ? $borderStr : '(unset)', $palette);
            }
        }
    }

    /** Secondary, Outline, and Subtle keep a neutral face; the palette is only a tint. */
    private function isNeutralChromeAppearance(?string $appearance): bool
    {
        if ($appearance === null || trim($appearance) === '') {
            return false;
        }

        return preg_match('/\.(Secondary|Outline|Subtle)\b/i', $appearance) === 1;
    }

    /**
     * Classic inputs default to an opaque studio fill when Fill is omitted.
     * Seed white so enable_dark_mode can wrap If(gblDarkMode, InputFill, white).
     */
    private function seedClassicInputFill(ControlNode $control, string $key, Report $report): void
    {
        if (!in_array($key, ['modern_text_input', 'modern_dropdown', 'modern_combobox', 'modern_datepicker', 'modern_number_input'], true)) {
            return;
        }
        $fill = $control->getProperty('Fill');
        $fillStr = $fill !== null ? trim((string) $fill) : '';
        if ($fillStr !== '' && $fillStr !== '=') {
            return;
        }
        $white = $control->format === 'yaml' ? '=RGBA(255, 255, 255, 1)' : 'RGBA(255, 255, 255, 1)';
        $control->setProperty('Fill', $white);
        $report->add(self::id(), $control->path, 'Fill', $fillStr !== '' ? $fillStr : '(unset)', $white);
    }

    /**
     * Classic TextInput uses TextMode.*, not TextInputType.*. Password / multiline
     * must survive the swap or the field becomes a single-line text box.
     */
    private function preserveClassicTextInputMode(ControlNode $control, string $key, Report $report): void
    {
        if ($key !== 'modern_text_input') {
            return;
        }
        $type = (string) ($control->getProperty('Type') ?? '');
        $mode = (string) ($control->getProperty('Mode') ?? '');
        $src = $type !== '' ? $type : $mode;
        $classic = null;
        if (preg_match('/Password/i', $src) === 1) {
            $classic = 'TextMode.Password';
        } elseif (preg_match('/MultiLine|Multiline/i', $src) === 1) {
            $classic = 'TextMode.MultiLine';
        }
        if ($classic !== null && (trim($mode) === '' || preg_match('/TextInputType/i', $mode) === 1)) {
            $to = $control->format === 'yaml' ? '=' . $classic : $classic;
            $control->setProperty('Mode', $to);
            $report->add(self::id(), $control->path, 'Mode', $mode !== '' ? $mode : '(unset)', $to);
        }
        if ($control->getProperty('Type') !== null) {
            $before = (string) $control->getProperty('Type');
            $control->removeProperty('Type');
            $report->add(self::id(), $control->path, 'Type', $before, '(removed)');
        }
    }

    /**
     * Classic ComboBox needs DisplayFields/SearchFields for primitive Items, and
     * DefaultSelectedItems as a record table ({Value: "A"}) not ["A"].
     */
    private function preserveClassicPickerFields(ControlNode $control, string $key, Report $report): void
    {
        if ($key !== 'modern_combobox' && $key !== 'modern_dropdown') {
            return;
        }
        if ($key === 'modern_combobox') {
            foreach (['DisplayFields', 'SearchFields'] as $prop) {
                $val = $control->getProperty($prop);
                if ($val !== null && trim((string) $val) !== '' && trim((string) $val) !== '=') {
                    continue;
                }
                $to = $this->displayFieldsLiteral($this->selectedItemsColumn($control), $control->format === 'yaml');
                $control->setProperty($prop, $to);
                $report->add(self::id(), $control->path, $prop, $val !== null ? (string) $val : '(unset)', $to);
            }
        }
        $defaults = $control->getProperty('DefaultSelectedItems');
        $wrapped = $this->wrapPrimitiveSelectedItems($defaults, $control->format === 'yaml');
        if ($wrapped !== null) {
            $control->setProperty('DefaultSelectedItems', $wrapped);
            $report->add(
                self::id(),
                $control->path,
                'DefaultSelectedItems',
                $defaults !== null ? (string) $defaults : '(unset)',
                $wrapped
            );
        }
    }

    /**
     * Classic Radio.Default is a scalar. Modern DefaultSelectedItems is often ["English"].
     */
    private function preserveClassicRadioDefault(ControlNode $control, string $key, Report $report): void
    {
        if ($key !== 'modern_radio') {
            return;
        }
        $defaults = $control->getProperty('DefaultSelectedItems');
        $wrapped = $this->wrapPrimitiveSelectedItems($defaults, $control->format === 'yaml');
        if ($wrapped !== null) {
            $control->setProperty('DefaultSelectedItems', $wrapped);
            $report->add(
                self::id(),
                $control->path,
                'DefaultSelectedItems',
                $defaults !== null ? (string) $defaults : '(unset)',
                $wrapped
            );
        }
        $existing = $control->getProperty('Default');
        if ($existing !== null && trim((string) $existing) !== '' && trim((string) $existing) !== '=') {
            return;
        }
        $src = $defaults !== null ? trim(ltrim(trim((string) $defaults), '=')) : '';
        $scalar = null;
        if (preg_match('/^\[\s*("[^"]*"|\'[^\']*\')\s*\]$/', $src, $m) === 1) {
            $scalar = $m[1];
        } elseif (preg_match('/^If\s*\((.+),\s*\[\s*("[^"]*"|\'[^\']*\')\s*\]\s*,\s*\[\s*("[^"]*"|\'[^\']*\')\s*\]\s*\)$/is', $src, $m) === 1) {
            $scalar = 'If(' . $m[1] . ', ' . $m[2] . ', ' . $m[3] . ')';
        }
        if ($scalar === null) {
            return;
        }
        $to = $control->format === 'yaml' ? '=' . $scalar : $scalar;
        $control->setProperty('Default', $to);
        $report->add(self::id(), $control->path, 'Default', $existing !== null ? (string) $existing : '(unset)', $to);
    }

    /**
     * Classic Radio.Layout is Layout.Horizontal/Vertical. A leftover
     * 'RadioGroupCanvas.Layout'.* enum from a modern stub errors in Studio
     * and renders as a solid black bar (THCEE Language/Theme settings).
     */
    private function preserveClassicRadioLayout(ControlNode $control, Report $report): void
    {
        if (!$this->isClassicRadio($control)) {
            return;
        }
        $layout = $control->getProperty('Layout');
        $body = $this->formulaBody($layout);
        if ($body === null || !preg_match('/RadioGroupCanvas\\.Layout/i', $body)) {
            return;
        }
        $dir = preg_match('/Horizontal/i', $body) === 1 ? 'Horizontal' : 'Vertical';
        $to = $control->format === 'yaml' ? '=Layout.' . $dir : 'Layout.' . $dir;
        $control->setProperty('Layout', $to);
        $report->add(self::id(), $control->path, 'Layout', (string) $layout, $to);
    }

    /**
     * Re-runs must unbreak radios already converted by an older sweep.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairRadioControls(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!str_contains(strtolower($control->type), 'radio')) {
                    continue;
                }
                $this->preserveClassicRadioLayout($control, $report);
                $this->clearInventedRadioFill($control, $report);
                if ($this->isClassicRadio($control)) {
                    $size = $control->getProperty('Size');
                    if ($this->isUnusableClassicFontSize($this->formulaBody($size))) {
                        $to = $control->format === 'yaml' ? '=14' : '14';
                        $control->setProperty('Size', $to);
                        $report->add(
                            self::id(),
                            $control->path,
                            'Size',
                            $size !== null ? (string) $size : '(unset)',
                            $to
                        );
                    }
                }
            }
        }
    }

    /** Studio dummy blacks / themed Text on the radio track become a solid bar. */
    private function clearInventedRadioFill(ControlNode $control, Report $report): void
    {
        $fill = $control->getProperty('Fill');
        $fillStr = $fill !== null ? (string) $fill : '';
        $body = $this->formulaBody($fill);
        if ($body === null) {
            return;
        }
        $parsed = ColorValue::parse($fillStr);
        $darkLiteral = $parsed !== null
            && !ColorValue::isTransparent($parsed)
            && ColorValue::luminance($parsed) < 0.2
            && ColorValue::saturation($parsed) <= 0.35;
        $themedTrack = preg_match('/gblTheme\\.(Text|SurfaceInverse)\\b/i', $body) === 1;
        if (!$darkLiteral && !$themedTrack) {
            return;
        }
        $to = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
        $control->setProperty('Fill', $to);
        $report->add(self::id(), $control->path, 'Fill', $fillStr, $to);
    }

    private function isClassicRadio(ControlNode $control): bool
    {
        $type = strtolower($control->type);

        return str_contains($type, 'classic/')
            || preg_match('/(?:^|\\/)radio@2\\./i', $type) === 1;
    }

    private function preserveClassicNumberFormat(ControlNode $control, string $key, Report $report): void
    {
        if ($key !== 'modern_number_input') {
            return;
        }
        $format = $control->getProperty('Format');
        if ($format !== null && trim((string) $format) !== '' && trim((string) $format) !== '=') {
            return;
        }
        $to = $control->format === 'yaml' ? '=TextFormat.Number' : 'TextFormat.Number';
        $control->setProperty('Format', $to);
        $report->add(self::id(), $control->path, 'Format', $format !== null ? (string) $format : '(unset)', $to);
    }

    /**
     * Classic DatePicker defaults to DateTimeFormat.LongDate. Modern pickers
     * default to ShortDate (or an explicit Format / DateTimeFormat). Keep that
     * display contract so converting to classic does not change the date string.
     */
    private function preserveClassicDateFormat(ControlNode $control, string $key, Report $report): void
    {
        if ($key !== 'modern_datepicker') {
            return;
        }
        $format = $control->getProperty('Format');
        $alt = $control->getProperty('DateTimeFormat');
        $src = $this->firstMeaningfulFormula($format) ?? $this->firstMeaningfulFormula($alt);
        $mapped = $src !== null ? $this->classicDateFormat($src) : 'DateTimeFormat.ShortDate';
        $current = $this->firstMeaningfulFormula($format);
        if ($current !== null && $this->formulaBody($current) === $mapped) {
            return;
        }
        $to = $control->format === 'yaml' ? '=' . $mapped : $mapped;
        $control->setProperty('Format', $to);
        $report->add(
            self::id(),
            $control->path,
            'Format',
            $format !== null ? (string) $format : '(unset)',
            $to
        );
        if ($alt !== null && $this->firstMeaningfulFormula($format) === null) {
            $control->removeProperty('DateTimeFormat');
            $report->add(self::id(), $control->path, 'DateTimeFormat', (string) $alt, '(removed → Format)');
        }
    }

    /**
     * Modern Fluent often serializes Size=0 (auto / unused) beside FontSize.
     * Classic Label / TextInput / DatePicker use Size as the font size, so that
     * 0 becomes invisible text after enable_dark_theme converts the control.
     */
    private function preserveClassicFontSize(ControlNode $control, string $key, Report $report): void
    {
        if ($key === 'modern_icon' || $key === 'modern_slider') {
            return;
        }
        $size = $control->getProperty('Size');
        $fontSize = $control->getProperty('FontSize');
        $sizeBody = $this->formulaBody($size);
        $fontBody = $this->formulaBody($fontSize);
        $sizeUnusable = $this->isUnusableClassicFontSize($sizeBody);
        $buttonFontWins = $key === 'modern_button'
            && $fontBody !== null
            && !$this->isUnusableClassicFontSize($fontBody);
        if (!$sizeUnusable && !$buttonFontWins) {
            return;
        }
        $replacement = null;
        if ($fontBody !== null && !$this->isUnusableClassicFontSize($fontBody)) {
            $replacement = $this->classicFontSizeFromModern($fontBody) ?? $fontBody;
        } elseif ($sizeBody !== null) {
            $replacement = $this->classicFontSizeFromModern($sizeBody);
        }
        if ($replacement === null) {
            $replacement = $key === 'modern_button' ? '10.5' : '14';
        }
        $to = $control->format === 'yaml' && !str_starts_with(trim($replacement), '=')
            ? '=' . $replacement
            : $replacement;
        if ($control->format !== 'yaml' && str_starts_with(trim($to), '=')) {
            $to = ltrim(trim($to), '=');
        }
        $control->setProperty('Size', $to);
        $report->add(
            self::id(),
            $control->path,
            'Size',
            $size !== null ? (string) $size : '(unset)',
            $to
        );
    }

    private function firstMeaningfulFormula(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $body = $this->formulaBody($value);

        return $body === null ? null : $value;
    }

    private function formulaBody(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $body = trim(ltrim(trim($value), '='));

        return $body === '' ? null : $body;
    }

    private function isUnusableClassicFontSize(?string $body): bool
    {
        if ($body === null) {
            return true;
        }
        if (preg_match('/^Size\.(Small|Medium|Large|None)$/i', $body) === 1) {
            return true;
        }
        if (!is_numeric($body)) {
            return false;
        }

        return (float) $body <= 0.0;
    }

    private function classicFontSizeFromModern(string $body): ?string
    {
        if (preg_match('/^Size\.(Small|Medium|Large|None)$/i', $body, $m) === 1) {
            return match (strtolower($m[1])) {
                'small' => '12',
                'large' => '18',
                'none' => '14',
                default => '14',
            };
        }

        return is_numeric($body) && (float) $body > 0 ? $body : null;
    }

    private function classicDateFormat(string $src): string
    {
        $body = $this->formulaBody($src) ?? $src;
        if (preg_match('/DatePickerFormat\.(ShortDateTime|LongDateTime|ShortDate|LongDate|UTC)/i', $body, $m) === 1) {
            return 'DateTimeFormat.' . $m[1];
        }
        if (preg_match('/DateTimeFormat\.\w+/i', $body, $m) === 1) {
            return $m[0];
        }
        if (preg_match('/^["\'][^"\']+["\']$/', $body) === 1) {
            return $body;
        }

        return 'DateTimeFormat.ShortDate';
    }

    /**
     * After a modern→classic swap, every formula that still names the modern
     * output/chrome properties must be rewritten or Studio breaks
     * (Checked, Label, Placeholder, Selected, .Value, FontColor, …).
     *
     * Hot path: only touch formulas that mention a converted control (or Self on
     * that control). Walking every formula × every conversion is O(n²) and
     * stalls THCEE/EHCR classic prep for many minutes on shared hosts.
     *
     * @param list<ControlDocument> $documents
     * @param list<array{name:string,key:string,itemsColumn:string}> $converted
     */
    private function rewriteConvertedRefs(array $documents, array $converted, Report $report): void
    {
        if ($converted === []) {
            return;
        }
        /** @var array<string, array{name:string,key:string,itemsColumn:string}> $byName */
        $byName = [];
        foreach ($converted as $item) {
            $byName[$item['name']] = $item;
        }
        $nameList = array_keys($byName);
        usort($nameList, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($byName, $nameList, $report): string {
                $hits = $this->convertedRefsInFormula($formula, $path, $byName, $nameList);
                if ($hits === []) {
                    return $formula;
                }
                $next = PowerFxFormulaSegments::transformCode($formula, function (string $code) use ($path, $hits): string {
                    foreach ($hits as $item) {
                        $code = $this->rewriteHostAndSelfRefs(
                            $code,
                            $path,
                            $item['name'],
                            $item['key'],
                            $item['itemsColumn']
                        );
                    }

                    return $code;
                }, false);
                if ($next === $formula) {
                    return $formula;
                }
                $report->add(self::id(), $path, 'Ref', self::preview($formula), self::preview($next));

                return $next;
            });
        }
    }

    /**
     * @param array<string, array{name:string,key:string,itemsColumn:string}> $byName
     * @param list<string> $nameList
     * @return list<array{name:string,key:string,itemsColumn:string}>
     */
    private function convertedRefsInFormula(string $formula, string $path, array $byName, array $nameList): array
    {
        $hits = [];
        foreach ($nameList as $name) {
            if ($this->pathBelongsToControl($path, $name)) {
                $hits[$name] = $byName[$name];
            }
        }
        if ($formula === '' || $formula === '=') {
            return array_values($hits);
        }
        foreach ($nameList as $name) {
            if (isset($hits[$name])) {
                continue;
            }
            if (!str_contains($formula, $name)) {
                continue;
            }
            // Word-ish boundary so Button1 does not match Button10.
            $quoted = preg_quote($name, '/');
            if (preg_match('/(?<![A-Za-z_0-9])' . $quoted . '(?![A-Za-z_0-9])/', $formula) === 1
                || str_contains($formula, "'" . str_replace("'", "''", $name) . "'")
            ) {
                $hits[$name] = $byName[$name];
            }
        }

        return array_values($hits);
    }

    private function rewriteHostAndSelfRefs(string $code, string $path, string $name, string $key, string $itemsColumn): string
    {
        $host = $this->hostPattern($name);
        $self = $this->pathBelongsToControl($path, $name);
        $code = $this->rewriteSpecialHostRefs($code, $host, $key, $itemsColumn);
        if ($self) {
            $code = $this->rewriteSpecialSelfRefs($code, $key, $itemsColumn);
        }
        foreach ($this->refPropMap($key) as $from => $to) {
            $code = $this->rewriteMember($code, $host, $from, $to);
            if ($self) {
                $code = preg_replace('/\bSelf\.' . preg_quote($from, '/') . '\b/', 'Self.' . $to, $code) ?? $code;
            }
        }

        return $code;
    }

    private function rewriteSpecialHostRefs(string $code, string $host, string $key, string $itemsColumn): string
    {
        $head = $this->hostHead($host);
        if ($key === 'modern_number_input') {
            $code = preg_replace(
                '/\bValue\s*\(\s*' . $head . '\.Value\s*\)/',
                'Value($1.Text)',
                $code
            ) ?? $code;
            $code = preg_replace('/' . $head . '\.Value\b/', 'Value($1.Text)', $code) ?? $code;

            return $code;
        }
        if ($key === 'modern_datepicker') {
            return preg_replace('/' . $head . '\.Value\b/', '$1.SelectedDate', $code) ?? $code;
        }
        if (in_array($key, ['modern_radio', 'modern_combobox', 'modern_dropdown'], true)) {
            $code = preg_replace('/' . $head . '\.Selected\b(?!\s*\.)/', '$1.Selected.Value', $code) ?? $code;
        }
        if (in_array($key, ['modern_combobox', 'modern_dropdown'], true)) {
            $code = preg_replace(
                '/\bin\s+' . $head . '\.SelectedItems\b(?!\s*\.)/',
                'in $1.SelectedItems' . $this->selectedFieldSuffix($itemsColumn),
                $code
            ) ?? $code;
        }

        return $code;
    }

    private function rewriteSpecialSelfRefs(string $code, string $key, string $itemsColumn): string
    {
        if ($key === 'modern_number_input') {
            $code = preg_replace('/\bValue\s*\(\s*Self\.Value\s*\)/', 'Value(Self.Text)', $code) ?? $code;
            $code = preg_replace('/\bSelf\.Value\b/', 'Value(Self.Text)', $code) ?? $code;

            return $code;
        }
        if ($key === 'modern_datepicker') {
            return preg_replace('/\bSelf\.Value\b/', 'Self.SelectedDate', $code) ?? $code;
        }
        if (in_array($key, ['modern_radio', 'modern_combobox', 'modern_dropdown'], true)) {
            $code = preg_replace('/\bSelf\.Selected\b(?!\s*\.)/', 'Self.Selected.Value', $code) ?? $code;
        }
        if (in_array($key, ['modern_combobox', 'modern_dropdown'], true)) {
            $code = preg_replace(
                '/\bin\s+Self\.SelectedItems\b(?!\s*\.)/',
                'in Self.SelectedItems' . $this->selectedFieldSuffix($itemsColumn),
                $code
            ) ?? $code;
        }

        return $code;
    }

    /**
     * Column that `in Combo.SelectedItems` must project. A value list is Value;
     * ShowColumns(..., EnglishNameShort) and ThisItem.Text are not, and Studio
     * then reports "Name isn't valid. 'Value' isn't recognized" on gallery Items.
     */
    private function selectedItemsColumn(ControlNode $control): string
    {
        $display = $this->formulaBody($control->getProperty('ItemDisplayText')) ?? '';
        if (preg_match('/^ThisItem\.([A-Za-z_]\w*)$/', $display, $shown) === 1) {
            return $shown[1];
        }
        $items = $this->formulaBody($control->getProperty('Items')) ?? '';
        $fromShow = $this->showColumnsLastArg($items);
        if ($fromShow !== null) {
            return $fromShow;
        }
        $stripped = preg_replace('/(?<!:)\/\/[^\n]*/', '', $items) ?? $items;
        if (preg_match('/\.([A-Za-z_]\w*)\s*$/', trim($stripped), $tail) === 1) {
            return $tail[1];
        }

        return 'Value';
    }

    private function showColumnsLastArg(string $items): ?string
    {
        if (preg_match('/\bShowColumns\s*\(/i', $items, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $found[0][1] + strlen($found[0][0]);
        $depth = 1;
        $lastArgStart = $start;
        $len = strlen($items);
        for ($i = $start; $i < $len; $i++) {
            $ch = $items[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $arg = trim(substr($items, $lastArgStart, $i - $lastArgStart));
                    if (preg_match("/^'((?:[^']|'')*)'$/", $arg, $quoted) === 1) {
                        return str_replace("''", "'", $quoted[1]);
                    }
                    if (preg_match('/^[A-Za-z_]\w*$/', $arg) === 1) {
                        return $arg;
                    }

                    return null;
                }
            } elseif ($ch === ',' && $depth === 1) {
                $lastArgStart = $i + 1;
            }
        }

        return null;
    }

    private function selectedFieldSuffix(string $column): string
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $column) === 1) {
            return '.' . $column;
        }

        return ".'" . str_replace("'", "''", $column) . "'";
    }

    private function displayFieldsLiteral(string $column, bool $yaml): string
    {
        $body = '["' . str_replace('"', '""', $column) . '"]';

        return $yaml ? '=' . $body : $body;
    }

    private function rewriteMember(string $code, string $host, string $from, string $to): string
    {
        $head = $this->hostHead($host);

        return preg_replace('/' . $head . '\.' . preg_quote($from, '/') . '\b/', '$1.' . $to, $code) ?? $code;
    }

    /** Capture group 1 = optional screen qualifier + control name (quoted or bare). */
    private function hostHead(string $host): string
    {
        return '((?:(?:[A-Za-z_][\w]*|\'(?:[^\']|\'\')+\')\.)?' . $host . ')';
    }

    private function hostPattern(string $name): string
    {
        $quoted = "'(?:" . str_replace("'", "''", preg_quote($name, '/')) . ")'";
        if (preg_match('/^[A-Za-z_][\w]*$/', $name) === 1) {
            return '(?:(?<![A-Za-z_0-9])' . preg_quote($name, '/') . '\b|' . $quoted . ')';
        }

        return $quoted;
    }

    /** Simple member renames for formulas that read a converted control. */
    private function refPropMap(string $key): array
    {
        $shared = [
            'FontColor' => 'Color',
            'FontSize' => 'Size',
        ];

        return match ($key) {
            'modern_checkbox' => $shared + [
                'Checked' => 'Value',
                'Label' => 'Text',
                'BasePaletteColor' => 'Fill',
            ],
            'modern_toggle' => $shared + [
                'Checked' => 'Value',
                'Label' => 'TrueText',
                'BasePaletteColor' => 'TrueFill',
            ],
            'modern_text_input', 'modern_number_input' => $shared + [
                'Placeholder' => 'HintText',
                'PlaceholderText' => 'HintText',
                'BasePaletteColor' => 'Fill',
            ],
            'modern_button' => $shared + [
                'BasePaletteColor' => 'Fill',
            ],
            'modern_slider' => $shared + [
                'BasePaletteColor' => 'HandleFill',
            ],
            'modern_text', 'modern_dropdown', 'modern_combobox', 'modern_datepicker', 'modern_radio' => $shared + [
                'BasePaletteColor' => 'Fill',
            ],
            default => $shared,
        };
    }

    private function pathBelongsToControl(string $path, string $name): bool
    {
        return (bool) preg_match('/(?:^|[\\/#])' . preg_quote($name, '/') . '(?:[.\\/#]|$)/', $path);
    }

    private function wrapPrimitiveSelectedItems(?string $formula, bool $yaml): ?string
    {
        if ($formula === null) {
            return null;
        }
        $body = trim(ltrim(trim($formula), '='));
        if ($body === '' || str_contains($body, '{')) {
            return null;
        }
        if (preg_match('/^\[\s*(?:"[^"]*"|\'[^\']*\')(?:\s*,\s*(?:"[^"]*"|\'[^\']*\'))*\s*\]$/', $body) !== 1) {
            return null;
        }
        if (preg_match_all('/"[^"]*"|\'[^\']*\'/', $body, $m) < 1) {
            return null;
        }
        $records = [];
        foreach ($m[0] as $lit) {
            $records[] = '{Value: ' . $lit . '}';
        }
        $out = '[' . implode(', ', $records) . ']';

        return $yaml ? '=' . $out : $out;
    }

    private static function preview(string $value): string
    {
        $flat = preg_replace('/\s+/', ' ', $value) ?? $value;

        return strlen($flat) > 80 ? substr($flat, 0, 77) . '...' : $flat;
    }

    private function isTransparentAppearance(?string $appearance): bool
    {
        return is_string($appearance) && preg_match('/Transparent/i', $appearance) === 1;
    }

    private function isBlankButtonText(?string $text): bool
    {
        if ($text === null) {
            return true;
        }
        $trim = trim($text);
        if ($trim === '' || $trim === '=') {
            return true;
        }

        return preg_match('/^=\s*""\s*$/', $trim) === 1
            || preg_match("/^=\\s*''\\s*$/", $trim) === 1;
    }

    /**
     * Transparent / icon-only modern controls are layout chrome, not themed surfaces.
     * Leave them so dark mode can keep RGBA(0,0,0,0) instead of a classic Fill.
     */
    private function shouldLeaveModern(ControlNode $control, string $key): bool
    {
        if ($key === 'modern_icon') {
            return true;
        }
        // RadioGroupCanvas uses 'RadioGroupCanvas.Layout'.* and a transparent track.
        // Classic Radio rejects that enum (black error bars) and paints an opaque Fill.
        if ($key === 'modern_radio') {
            return true;
        }
        // Modern text is already transparent chrome (loading titles, step numerals).
        // Classic Label invents an opaque Fill → black bars on light screens.
        if ($key === 'modern_text') {
            $fill = $control->getProperty('Fill');
            if ($fill === null || trim($fill) === '' || trim($fill) === '=') {
                return true;
            }
            $parsed = ColorValue::parse($fill);
            if ($parsed !== null && ColorValue::isTransparent($parsed)) {
                return true;
            }
        }
        $isButton = $key === 'modern_button' || str_contains(strtolower($control->type), 'button');
        if (!$isButton) {
            return false;
        }
        if ($this->isTransparentAppearance($control->getProperty('Appearance'))) {
            // Icon-only hit targets and literal labels (homepage Start) stay
            // modern so FontColor still themes them. A formula label such as
            // the Activités back link is ButtonCanvas, and dark theme type
            // grows past FontSize. Classic Size keeps the author size.
            $font = $this->formulaBody($control->getProperty('FontSize'));
            if ($font === null || $this->isUnusableClassicFontSize($font)) {
                return true;
            }
            $text = $this->formulaBody($control->getProperty('Text'));

            return $text === null || preg_match('/\bIf\s*\(|&/', $text) !== 1;
        }
        $fill = $control->getProperty('Fill');
        if ($fill === null || trim($fill) === '') {
            return false;
        }
        $parsed = ColorValue::parse($fill);

        return $parsed !== null && ColorValue::isTransparent($parsed);
    }

    /**
     * Classic buttons default Size=14 and a 2px black border. Copy modern FontSize
     * and drop the invented border. Do not invent Radius — keep the modern control's
     * corners (pixel-perfect). Self.Height / 2 turns every CTA into a pill.
     */
    private function preserveModernButtonChrome(ControlNode $control, string $key): void
    {
        if ($key !== 'modern_button' && !str_contains(strtolower($control->type), 'button')) {
            return;
        }
        $eq = $control->format === 'yaml';
        $size = $control->getProperty('Size');
        $sizeBody = $this->formulaBody($size);
        $fontSize = $control->getProperty('FontSize');
        $fontBody = $this->formulaBody($fontSize);
        if ($fontBody !== null && !$this->isUnusableClassicFontSize($fontBody)) {
            $control->setProperty('Size', $eq && !str_starts_with(trim((string) $fontSize), '=')
                ? '=' . $fontBody
                : (string) $fontSize);
        } elseif ($this->isUnusableClassicFontSize($sizeBody)) {
            // Fluent default is ~10.5; classic Studio default 14 makes swept labels larger.
            $control->setProperty('Size', $eq ? '=10.5' : '10.5');
        }

        $thickness = $control->getProperty('BorderThickness');
        $thicknessEmpty = $thickness === null || trim($thickness) === '' || trim($thickness) === '=';
        if ($thicknessEmpty) {
            $control->setProperty('BorderThickness', $eq ? '=0' : '0');
        }

        // Fluent Primary with no Radius still paints a capsule. Classic with no
        // Radius inherits that look. Pin a 4px rounded-rect (not Height/2).
        $radii = ['RadiusTopLeft', 'RadiusTopRight', 'RadiusBottomLeft', 'RadiusBottomRight'];
        $hasRadius = false;
        foreach ($radii as $prop) {
            $val = $control->getProperty($prop);
            if ($val !== null && trim($val) !== '' && trim($val) !== '=') {
                $hasRadius = true;
                break;
            }
        }
        if (!$hasRadius) {
            $radius = $eq ? '=4' : '4';
            foreach ($radii as $prop) {
                $control->setProperty($prop, $radius);
            }
        }
    }
}
