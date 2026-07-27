<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;

/**
 * Wire dark mode via a central editable theme palette.
 *
 * App.Formulas defines static named-formula records (App Checker can type-check fields):
 *   gblThemeLight / gblThemeDark  — tokens like Page, Surface, Text, Accent, …
 *
 * App.OnStart only initializes:
 *   Set(gblDarkMode, false)
 *
 * Control colors become:
 *   If(Coalesce(gblDarkMode, false), gblThemeDark.Token, gblThemeLight.Token)
 *
 * We intentionally do NOT use a reactive named formula `gblTheme = If(gblDarkMode, …)` —
 * named formulas that reference Set() variables often fail to register, which produces
 * thousands of "Name isn't valid. 'gblTheme'…" App Checker errors.
 *
 * Settings Theme radio (Light/Dark) is preferred over injecting a floating toggle.
 */
final class EnableDarkModeHop implements HopInterface
{
    private const VAR = 'gblDarkMode';
    private const THEME = 'gblTheme';
    private const THEME_LIGHT = 'gblThemeLight';
    private const THEME_DARK = 'gblThemeDark';
    private const TOGGLE_NAME = 'tglPowerSweeperDarkMode';
    private const BLOCK_START = '/* ps-theme:start */';
    private const BLOCK_END = '/* ps-theme:end */';

    /** Core tokens always taken from theme_defaults (not first-seen literals). */
    private const CORE_TOKENS = [
        'Page', 'Surface', 'SurfaceMuted', 'Text', 'TextMuted', 'Border', 'Accent', 'Focus', 'Rail',
    ];

    /** @var list<string> */
    private const COLOR_PROPERTIES = [
        'Fill',
        'Color',
        'BorderColor',
        'HoverFill',
        'HoverColor',
        'HoverBorderColor',
        'PressedFill',
        'PressedColor',
        'PressedBorderColor',
        'DisabledFill',
        'DisabledColor',
        'DisabledBorderColor',
        'FocusedBorderColor',
        'IconBackground',
        'IconColor',
        'ChevronBackground',
        'ChevronFill',
        'SelectionColor',
        'SelectionFill',
        'UnderlineColor',
        'RailFill',
        'RailHoverFill',
        'HandleFill',
        'TrueFill',
        'FalseFill',
        'TrueHoverFill',
        'FalseHoverFill',
        'CheckmarkFill',
        'CheckboxBackgroundFill',
        'CheckboxBorderColor',
        'TemplateFill',
        'ItemColor',
        'ItemFill',
        'ItemBorderColor',
        'SelectedFill',
        'SelectedColor',
        'SelectedBorderColor',
        'PressedItemFill',
        'HoverItemFill',
        'DisabledItemFill',
        'HighlightColor',
        'TintColor',
        'IndicatorFill',
        'TrackFill',
        'ProgressColor',
        'BarColor',
        'ActiveFill',
        'InactiveFill',
        'ValueFill',
        'RadioBackgroundFill',
        'RadioBorderColor',
        'LoadingSpinnerColor',
    ];

    public static function id(): string
    {
        return 'enable_dark_mode';
    }

    public static function label(): string
    {
        return 'Enable dark mode';
    }

    public static function description(): string
    {
        return 'Add Light/Dark theme control and central gblThemeLight/gblThemeDark named-formula palettes; rewrite literal colors to If(gblDarkMode, gblThemeDark.Token, gblThemeLight.Token) (edit colors in App.Formulas / config/theme_defaults.php).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $var = is_string($options['variable'] ?? null) && $options['variable'] !== ''
            ? (string) $options['variable']
            : self::VAR;
        $theme = is_string($options['theme_var'] ?? null) && $options['theme_var'] !== ''
            ? (string) $options['theme_var']
            : self::THEME;
        $themeLight = is_string($options['theme_light_var'] ?? null) && $options['theme_light_var'] !== ''
            ? (string) $options['theme_light_var']
            : self::THEME_LIGHT;
        $themeDark = is_string($options['theme_dark_var'] ?? null) && $options['theme_dark_var'] !== ''
            ? (string) $options['theme_dark_var']
            : self::THEME_DARK;
        $injectToggle = !array_key_exists('inject_toggle', $options) || (bool) $options['inject_toggle'];

        /** @var array<string, array{light: array{r:int,g:int,b:int,a:float}, dark: array{r:int,g:int,b:int,a:float}}> $palette */
        $palette = [];

        // Pass 1: collect tokens from literal colors
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name === self::TOGGLE_NAME) {
                    continue;
                }
                foreach (self::COLOR_PROPERTIES as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || trim($from) === '' || $this->alreadyThemed($from, $var, $theme)) {
                        continue;
                    }
                    $parsed = ColorValue::parse($from);
                    if ($parsed === null || ColorValue::isTransparent($parsed)) {
                        continue;
                    }
                    $token = ColorValue::themeToken($parsed, $prop);
                    $role = ColorValue::roleForProperty($prop);
                    $dark = ColorValue::toDark($parsed, $role);
                    if (!isset($palette[$token])) {
                        $palette[$token] = [
                            'light' => [
                                'r' => $parsed['r'],
                                'g' => $parsed['g'],
                                'b' => $parsed['b'],
                                'a' => $parsed['a'],
                            ],
                            'dark' => $dark,
                        ];
                    }
                }
            }
        }

        // Seed missing core tokens; force core + explicit overrides for editable quality
        [$coreDefaults, $forcedDefaults] = $this->resolveCoreDefaults($options);
        foreach ($coreDefaults as $core => $pair) {
            if (!isset($palette[$core])) {
                $palette[$core] = $pair;
            }
        }
        foreach (self::CORE_TOKENS as $core) {
            if (isset($coreDefaults[$core])) {
                $palette[$core] = $coreDefaults[$core];
            }
        }
        foreach ($forcedDefaults as $token => $pair) {
            $palette[$token] = $pair;
        }

        // Known semantic tokens: prefer contrast-safe dark defaults over first-seen role mapping
        foreach (array_keys($palette) as $token) {
            if (in_array($token, self::CORE_TOKENS, true)) {
                continue;
            }
            $known = ColorValue::defaultDarkForToken($token);
            // Keep discovered light; replace washed-out / role-confused dark for named tokens
            if (isset($palette[$token]) && ColorValue::hasNamedDarkDefault($token)) {
                $palette[$token]['dark'] = $known;
            }
        }

        ksort($palette);

        $apps = $this->findApps($documents);
        if ($apps !== []) {
            $formulasBlock = $this->buildFormulasThemeBlock($var, $theme, $themeLight, $themeDark, $palette);
            $onStartBlock = self::BLOCK_START . ' Set(' . $var . ', false) ' . self::BLOCK_END;
            foreach ($apps as $app) {
                $formulasBefore = (string) ($app->getProperty('Formulas') ?? '');
                $formulasAfter = $this->upsertThemeBlock($formulasBefore, $formulasBlock, $app->format === 'yaml');
                if ($formulasAfter !== $formulasBefore) {
                    $app->setProperty('Formulas', $formulasAfter);
                    $report->add(
                        self::id(),
                        $app->path,
                        'Formulas',
                        $formulasBefore !== '' ? self::preview($formulasBefore) : '(empty)',
                        'named theme palette ' . $themeLight . '/' . $themeDark . '/' . $theme . ' (' . count($palette) . ' tokens)'
                    );
                }

                $onStartBefore = (string) ($app->getProperty('OnStart') ?? '');
                // Strip legacy OnStart palette Sets (moved to Formulas) and keep a tiny init.
                $onStartStripped = $this->stripThemeBlock($onStartBefore);
                $onStartAfter = $this->upsertThemeBlock($onStartStripped, $onStartBlock, $app->format === 'yaml');
                if ($onStartAfter !== $onStartBefore) {
                    $app->setProperty('OnStart', $onStartAfter);
                    $report->add(
                        self::id(),
                        $app->path,
                        'OnStart',
                        $onStartBefore !== '' ? self::preview($onStartBefore) : '(empty)',
                        'init ' . $var . ' (palettes live in App.Formulas)'
                    );
                }
            }
        } else {
            $report->add(self::id(), '(app)', 'Formulas', '(missing App control)', 'skipped palette inject — add an App control to edit theme tokens');
        }

        $themeRadio = $this->findThemeRadio($documents);
        $existingToggle = $this->findDarkToggle($documents, $var);
        $wiredSelector = false;

        if ($themeRadio !== null) {
            $this->wireThemeRadio($themeRadio, $var, $report);
            $wiredSelector = true;
        }

        if ($existingToggle !== null) {
            $this->wireToggle($existingToggle, $var, $report);
            $wiredSelector = true;
        } elseif (!$wiredSelector && $injectToggle) {
            $screen = $this->pickIntroScreen($documents);
            if ($screen !== null && $screen->format === 'yaml') {
                $this->injectToggle($screen, $var, $theme, $report);
                foreach ($documents as $doc) {
                    if (str_contains($screen->path, $doc->relativePath) || str_starts_with($screen->path, $doc->relativePath)) {
                        $doc->reindex();
                    }
                }
                $wiredSelector = true;
            }
        }

        if (!$wiredSelector) {
            $report->add(self::id(), '(ui)', 'Theme', '(no settings radio/toggle found)', 'palette applied; add a Theme Light/Dark control manually if needed');
        }

        // Pass 2: point literals (and legacy gblTheme.Token) at If(gblDarkMode, dark.Token, light.Token)
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (self::COLOR_PROPERTIES as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || trim($from) === '') {
                        continue;
                    }

                    $token = $this->tokenFromExistingThemeFormula($from, $var, $theme, $themeLight, $themeDark);
                    if ($token === null) {
                        if ($this->isIfThemeFormula($from, $var, $themeLight, $themeDark)) {
                            continue;
                        }
                        $parsed = ColorValue::parse($from);
                        if ($parsed === null) {
                            $pair = $this->parseLegacyIfPair($from, $var);
                            if ($pair === null) {
                                continue;
                            }
                            $parsed = $pair['light'];
                        } elseif (ColorValue::isTransparent($parsed)) {
                            continue;
                        }
                        $token = ColorValue::themeToken($parsed, $prop);
                    }

                    if (!isset($palette[$token])) {
                        continue;
                    }

                    $to = $this->themeColorFormula($token, $var, $themeLight, $themeDark, $control->format === 'yaml');
                    if ($to === $from || trim(ltrim(trim($from), '=')) === trim(ltrim($to, '='))) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from, $to);
                }
            }
        }
    }

    private function themeColorFormula(
        string $token,
        string $var,
        string $themeLight,
        string $themeDark,
        bool $yamlEquals
    ): string {
        $body = 'If(Coalesce(' . $var . ', false), '
            . $themeDark . '.' . $token . ', '
            . $themeLight . '.' . $token . ')';
        return $yamlEquals ? '=' . $body : $body;
    }

    private function isIfThemeFormula(string $formula, string $var, string $themeLight, string $themeDark): bool
    {
        $v = trim(ltrim(trim($formula), '='));
        return (bool) preg_match(
            '/^If\s*\(\s*Coalesce\s*\(\s*' . preg_quote($var, '/')
            . '\s*,\s*false\s*\)\s*,\s*' . preg_quote($themeDark, '/')
            . '\.\w+\s*,\s*' . preg_quote($themeLight, '/')
            . '\.\w+\s*\)$/i',
            $v
        );
    }

    /**
     * Extract token from legacy `gblTheme.Token` or already-migrated If(…) form.
     */
    private function tokenFromExistingThemeFormula(
        string $formula,
        string $var,
        string $theme,
        string $themeLight,
        string $themeDark
    ): ?string {
        $v = trim(ltrim(trim($formula), '='));
        if (preg_match('/^' . preg_quote($theme, '/') . '\.(\w+)$/', $v, $m)) {
            return $m[1];
        }
        if (preg_match(
            '/^If\s*\(\s*Coalesce\s*\(\s*' . preg_quote($var, '/')
            . '\s*,\s*false\s*\)\s*,\s*' . preg_quote($themeDark, '/')
            . '\.(\w+)\s*,\s*' . preg_quote($themeLight, '/')
            . '\.\w+\s*\)$/i',
            $v,
            $m
        )) {
            return $m[1];
        }
        if (preg_match(
            '/^If\s*\(\s*' . preg_quote($var, '/')
            . '\s*,\s*' . preg_quote($themeDark, '/')
            . '\.(\w+)\s*,\s*' . preg_quote($themeLight, '/')
            . '\.\w+\s*\)$/i',
            $v,
            $m
        )) {
            return $m[1];
        }
        return null;
    }

    /**
     * @param array<string, array{light: array{r:int,g:int,b:int,a:float}, dark: array{r:int,g:int,b:int,a:float}}> $palette
     */
    private function buildFormulasThemeBlock(
        string $var,
        string $theme,
        string $themeLight,
        string $themeDark,
        array $palette
    ): string {
        $lightFields = [];
        $darkFields = [];
        foreach ($palette as $token => $pair) {
            $light = $pair['light'];
            // Opaque core surfaces/text — translucent first-seen literals break contrast
            if (in_array($token, self::CORE_TOKENS, true) && $light['a'] < 0.99) {
                $light['a'] = 1.0;
            }
            $dark = $pair['dark'];
            if (
                $dark['r'] === $pair['light']['r']
                && $dark['g'] === $pair['light']['g']
                && $dark['b'] === $pair['light']['b']
                && abs($dark['a'] - $pair['light']['a']) < 0.001
                && in_array($token, ['Page', 'Surface', 'SurfaceMuted', 'Text', 'TextMuted', 'Border'], true)
            ) {
                $dark = ColorValue::defaultDarkForToken($token);
            }
            if (in_array($token, self::CORE_TOKENS, true) && $dark['a'] < 0.99) {
                $dark['a'] = 1.0;
            }
            $lightFields[] = $token . ': ' . ColorValue::formatRgba($light);
            $darkFields[] = $token . ': ' . ColorValue::formatRgba($dark);
        }

        // Static named-formula records only — do not bind gblTheme to gblDarkMode here.
        // Controls use If(Coalesce(gblDarkMode,false), gblThemeDark.Token, gblThemeLight.Token).
        return self::BLOCK_START . "\n"
            . $themeLight . ' = { ' . implode(', ', $lightFields) . " };\n"
            . $themeDark . ' = { ' . implode(', ', $darkFields) . " };\n"
            . self::BLOCK_END;
    }

    private function upsertThemeBlock(string $existing, string $block, bool $yamlEquals): string
    {
        $body = trim($existing);
        $hadEquals = str_starts_with($body, '=') || $yamlEquals;
        if (str_starts_with($body, '=')) {
            $body = substr($body, 1);
        }
        $body = trim($body);

        if (str_contains($body, self::BLOCK_START) && str_contains($body, self::BLOCK_END)) {
            $body = preg_replace(
                '/' . preg_quote(self::BLOCK_START, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . '/s',
                $block,
                $body
            ) ?? ($body . "\n\n" . $block);
        } elseif ($body === '') {
            $body = $block;
        } else {
            if (!str_ends_with($body, ';')) {
                $body .= ';';
            }
            $body .= "\n\n" . $block;
        }

        $body = trim($body);
        return ($yamlEquals || $hadEquals) ? '=' . $body : $body;
    }

    private function stripThemeBlock(string $existing): string
    {
        $body = trim($existing);
        $hadEquals = str_starts_with($body, '=');
        if ($hadEquals) {
            $body = substr($body, 1);
        }
        $body = trim($body);
        if (str_contains($body, self::BLOCK_START) && str_contains($body, self::BLOCK_END)) {
            $body = preg_replace(
                '/\s*;?\s*' . preg_quote(self::BLOCK_START, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . '/s',
                '',
                $body
            ) ?? $body;
            $body = trim($body);
            $body = rtrim($body, ';');
            $body = trim($body);
        }
        if ($body === '') {
            return $hadEquals ? '=' : '';
        }
        return $hadEquals ? '=' . $body : $body;
    }

    /**
     * Real canvas App controls (YAML + Controls JSON twin). Skips AppTests harness.
     *
     * @param list<ControlDocument> $documents
     * @return list<ControlNode>
     */
    private function findApps(array $documents): array
    {
        $apps = [];
        foreach ($documents as $doc) {
            $rel = str_replace('\\', '/', $doc->relativePath);
            if (str_starts_with(strtolower($rel), 'apptests/')) {
                continue;
            }
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    $apps[] = $control;
                }
            }
        }
        return $apps;
    }

    /** @param list<ControlDocument> $documents */
    private function findThemeRadio(array $documents): ?ControlNode
    {
        $fallback = null;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isRadio()) {
                    continue;
                }
                $name = strtolower($control->name);
                $items = strtolower((string) ($control->getProperty('Items') ?? ''));
                $text = strtolower((string) ($control->getProperty('Text') ?? ''));
                $accessible = strtolower((string) ($control->getProperty('AccessibleLabel') ?? ''));
                $blob = $name . ' ' . $text . ' ' . $accessible . ' ' . $items;

                if (
                    str_contains($blob, 'theme')
                    || (str_contains($items, 'light') && (str_contains($items, 'dark') || str_contains($name, 'theme')))
                    || ($name === 'themeradio' || str_contains($name, 'themeradio'))
                ) {
                    return $control;
                }
                // Items is only ["Light"] — CDLS Settings placeholder waiting for Dark
                if (preg_match('/\[\s*"light"\s*\]/i', $items) && $fallback === null) {
                    $fallback = $control;
                }
            }
        }
        return $fallback;
    }

    private function wireThemeRadio(ControlNode $radio, string $var, Report $report): void
    {
        $itemsTo = $radio->format === 'yaml' ? '=["Light", "Dark"]' : '["Light", "Dark"]';
        $beforeItems = (string) ($radio->getProperty('Items') ?? '');
        if (!preg_match('/dark/i', $beforeItems)) {
            $radio->setProperty('Items', $itemsTo);
            $report->add(self::id(), $radio->path, 'Items', $beforeItems !== '' ? $beforeItems : '(empty)', $itemsTo);
        }

        $defaultTo = $radio->format === 'yaml'
            ? '=If(Coalesce(' . $var . ', false), ["Dark"], ["Light"])'
            : 'If(Coalesce(' . $var . ', false), ["Dark"], ["Light"])';
        $beforeDefault = (string) ($radio->getProperty('DefaultSelectedItems') ?? '');
        if (!str_contains($beforeDefault, $var)) {
            $radio->setProperty('DefaultSelectedItems', $defaultTo);
            $report->add(
                self::id(),
                $radio->path,
                'DefaultSelectedItems',
                $beforeDefault !== '' ? self::preview($beforeDefault) : '(empty)',
                $defaultTo
            );
        }

        $onChange = 'Set(' . $var . ', Self.Selected.Value = "Dark")';
        $onChangeTo = $radio->format === 'yaml' ? '=' . $onChange : $onChange;
        $beforeChange = (string) ($radio->getProperty('OnChange') ?? '');
        if (!str_contains($beforeChange, $var)) {
            $radio->setProperty('OnChange', $onChangeTo);
            $report->add(
                self::id(),
                $radio->path,
                'OnChange',
                $beforeChange !== '' ? self::preview($beforeChange) : '(empty)',
                $onChangeTo
            );
        }

        // Some modern radio builds fire OnSelect instead of / as well as OnChange
        $beforeSelect = (string) ($radio->getProperty('OnSelect') ?? '');
        if ($beforeSelect !== '' && trim($beforeSelect) !== '=' && !str_contains($beforeSelect, $var)) {
            $radio->appendStatement('OnSelect', $onChange);
            $report->add(self::id(), $radio->path, 'OnSelect', self::preview($beforeSelect), 'appended theme Set');
        } elseif ($beforeSelect === '' || trim($beforeSelect) === '=') {
            // Leave OnSelect empty if unused — OnChange is the primary hook for Radio@0.0.25
        }
    }

    /** @param list<ControlDocument> $documents */
    private function findDarkToggle(array $documents, string $var): ?ControlNode
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isToggle() && !str_contains(strtolower($control->name), 'toggle')) {
                    continue;
                }
                $blob = strtolower(
                    $control->name . ' '
                    . (string) ($control->getProperty('Text') ?? '')
                    . ' '
                    . (string) ($control->getProperty('AccessibleLabel') ?? '')
                    . ' '
                    . (string) ($control->getProperty('OnCheck') ?? '')
                    . ' '
                    . (string) ($control->getProperty('OnChange') ?? '')
                    . ' '
                    . (string) ($control->getProperty('OnSelect') ?? '')
                );
                if (
                    str_contains($blob, 'dark')
                    || str_contains($blob, 'theme')
                    || str_contains($blob, strtolower($var))
                    || str_contains($blob, 'nacht')
                    || str_contains($blob, 'dunkel')
                ) {
                    return $control;
                }
                $default = (string) ($control->getProperty('Default') ?? '');
                if (str_contains($default, $var)) {
                    return $control;
                }
            }
        }
        return null;
    }

    private function wireToggle(ControlNode $toggle, string $var, Report $report): void
    {
        $beforeDefault = (string) ($toggle->getProperty('Default') ?? '');
        $defaultTo = $toggle->format === 'yaml' ? '=' . $var : $var;
        if (trim(ltrim($beforeDefault, '=')) !== $var) {
            $toggle->setProperty('Default', $defaultTo);
            $report->add(self::id(), $toggle->path, 'Default', $beforeDefault !== '' ? $beforeDefault : '(empty)', $defaultTo);
        }

        // Named-formula gblTheme follows gblDarkMode — only flip the boolean.
        $onCheck = 'Set(' . $var . ', true)';
        $onUncheck = 'Set(' . $var . ', false)';

        $beforeCheck = (string) ($toggle->getProperty('OnCheck') ?? '');
        $checkTo = $toggle->format === 'yaml' ? '=' . $onCheck : $onCheck;
        if (!str_contains($beforeCheck, 'Set(' . $var . ', true)') && !str_contains($beforeCheck, 'Set(' . $var . ',true)')) {
            $toggle->setProperty('OnCheck', $checkTo);
            $report->add(self::id(), $toggle->path, 'OnCheck', $beforeCheck !== '' ? $beforeCheck : '(empty)', $checkTo);
        }

        $beforeUn = (string) ($toggle->getProperty('OnUncheck') ?? '');
        $unTo = $toggle->format === 'yaml' ? '=' . $onUncheck : $onUncheck;
        if (!str_contains($beforeUn, 'Set(' . $var . ', false)') && !str_contains($beforeUn, 'Set(' . $var . ',false)')) {
            $toggle->setProperty('OnUncheck', $unTo);
            $report->add(self::id(), $toggle->path, 'OnUncheck', $beforeUn !== '' ? $beforeUn : '(empty)', $unTo);
        }
    }

    /** @param list<ControlDocument> $documents */
    private function pickIntroScreen(array $documents): ?ControlNode
    {
        $screens = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isScreen()) {
                    $screens[] = $control;
                }
            }
        }
        if ($screens === []) {
            return null;
        }

        $score = static function (ControlNode $s): int {
            $n = strtolower($s->name);
            $score = 0;
            foreach (['settings', 'home', 'intro', 'welcome', 'start', 'landing', 'main', 'menu', 'einstell'] as $i => $needle) {
                if (str_contains($n, $needle)) {
                    $score += 100 - $i;
                }
            }
            foreach ($s->children as $child) {
                $cn = strtolower($child->name . ' ' . $child->type);
                if (str_contains($cn, 'setting') || str_contains($cn, 'toggle') || str_contains($cn, 'theme')) {
                    $score += 20;
                }
            }
            return $score;
        };

        usort($screens, static fn(ControlNode $a, ControlNode $b): int => $score($b) <=> $score($a));
        return $screens[0];
    }

    private function injectToggle(
        ControlNode $screen,
        string $var,
        string $theme,
        Report $report
    ): void {
        $screen->addYamlChild(self::TOGGLE_NAME, [
            'Control' => 'Classic/Toggle@2.1.0',
            'Properties' => [
                'Text' => '="Dark mode"',
                'AccessibleLabel' => '="Toggle dark mode"',
                'Tooltip' => '="Switch theme — edit colors in App.Formulas gblThemeLight / gblThemeDark"',
                'Default' => '=' . $var,
                'OnCheck' => '=Set(' . $var . ', true)',
                'OnUncheck' => '=Set(' . $var . ', false)',
                'X' => '=16',
                'Y' => '=16',
                'Width' => '=180',
                'Height' => '=40',
                'TrueFill' => $this->themeColorFormula('Accent', $var, self::THEME_LIGHT, self::THEME_DARK, true),
                'FalseFill' => $this->themeColorFormula('Rail', $var, self::THEME_LIGHT, self::THEME_DARK, true),
                'TrueText' => '="Dark"',
                'FalseText' => '="Light"',
            ],
        ]);
        $report->add(
            self::id(),
            $screen->path,
            'Children',
            '(missing dark-mode toggle)',
            self::TOGGLE_NAME . ' injected'
        );
    }

    private function alreadyThemed(string $formula, string $var, string $theme): bool
    {
        $v = strtolower($formula);
        return str_contains($v, strtolower($theme))
            || str_contains($v, 'gblthemelight')
            || str_contains($v, 'gblthemedark')
            || str_contains($v, strtolower($var))
            || str_contains($v, 'gblappcolors')
            || str_contains($v, 'app.theme');
    }

    /**
     * @return null|array{light: array{r:int,g:int,b:int,a:float,raw:string}}
     */
    private function parseLegacyIfPair(string $formula, string $var): ?array
    {
        $v = trim(ltrim(trim($formula), '='));
        $pattern = '/^If\s*\(\s*' . preg_quote($var, '/') . '\s*,\s*(.+)\s*,\s*(.+)\s*\)$/is';
        if (!preg_match($pattern, $v, $m)) {
            return null;
        }
        $light = ColorValue::parse($m[2]);
        if ($light === null) {
            return null;
        }
        return ['light' => $light];
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: array<string, array{light: array{r:int,g:int,b:int,a:float}, dark: array{r:int,g:int,b:int,a:float}>>, 1: array<string, array{light: array{r:int,g:int,b:int,a:float}, dark: array{r:int,g:int,b:int,a:float}>>}
     */
    private function resolveCoreDefaults(array $options): array
    {
        $file = is_string($options['theme_defaults_file'] ?? null) && $options['theme_defaults_file'] !== ''
            ? (string) $options['theme_defaults_file']
            : dirname(__DIR__, 2) . '/config/theme_defaults.php';

        $loaded = [];
        if (is_file($file)) {
            $raw = require $file;
            if (is_array($raw)) {
                $loaded = $raw;
            }
        }

        $core = $this->normalizePaletteMap($loaded);

        $fallbackLight = [
            'Page' => ['r' => 250, 'g' => 250, 'b' => 252, 'a' => 1.0],
            'Surface' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
            'Text' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
            'TextMuted' => ['r' => 100, 'g' => 116, 'b' => 139, 'a' => 1.0],
            'Border' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
            'Accent' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
            'Focus' => ['r' => 59, 'g' => 130, 'b' => 246, 'a' => 1.0],
        ];
        foreach ($fallbackLight as $token => $light) {
            if (!isset($core[$token])) {
                $core[$token] = [
                    'light' => $light,
                    'dark' => ColorValue::defaultDarkForToken($token),
                ];
            }
        }

        $forced = [];
        if (isset($options['theme_defaults']) && is_array($options['theme_defaults'])) {
            $forced = $this->normalizePaletteMap($options['theme_defaults']);
        }

        return [$core, $forced];
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, array{light: array{r:int,g:int,b:int,a:float}, dark: array{r:int,g:int,b:int,a:float}}>
     */
    private function normalizePaletteMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $token => $pair) {
            if (!is_string($token) || !is_array($pair) || !isset($pair['light']) || !is_array($pair['light'])) {
                continue;
            }
            $light = $this->normalizeRgba($pair['light']);
            if ($light === null) {
                continue;
            }
            $dark = isset($pair['dark']) && is_array($pair['dark'])
                ? $this->normalizeRgba($pair['dark'])
                : null;
            $out[$token] = [
                'light' => $light,
                'dark' => $dark ?? ColorValue::defaultDarkForToken($token),
            ];
        }
        return $out;
    }

    /**
     * @param array<mixed> $c
     * @return null|array{r:int,g:int,b:int,a:float}
     */
    private function normalizeRgba(array $c): ?array
    {
        if (!isset($c['r'], $c['g'], $c['b'])) {
            return null;
        }
        return [
            'r' => max(0, min(255, (int) $c['r'])),
            'g' => max(0, min(255, (int) $c['g'])),
            'b' => max(0, min(255, (int) $c['b'])),
            'a' => isset($c['a']) ? max(0.0, min(1.0, (float) $c['a'])) : 1.0,
        ];
    }
}
