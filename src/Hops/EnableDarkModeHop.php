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
 * App.OnStart defines:
 *   gblThemeLight / gblThemeDark  — named color tokens (Page, Surface, Text, Accent, …)
 *   gblTheme                      — active palette (swapped by the toggle)
 *   gblDarkMode                   — boolean
 *
 * Control color properties become =gblTheme.Token so makers edit colors in one place.
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
        'FontColor',
    ];

    private const LEGACY_SURFACE = 'DefaultGrayBackgroud';

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
        return 'Add a dark-mode toggle and central gblThemeLight/gblThemeDark/gblTheme palettes; rewrite literal colors to gblTheme.* tokens so makers edit theme colors in App.OnStart only.';
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

        // Seed missing core tokens from editable config; then apply explicit overrides
        [$coreDefaults, $forcedDefaults] = $this->resolveCoreDefaults($options);
        foreach ($coreDefaults as $core => $pair) {
            if (!isset($palette[$core])) {
                $palette[$core] = $pair;
            }
        }
        // Hop/profile theme_defaults always win (brand palette without touching controls)
        foreach ($forcedDefaults as $token => $pair) {
            $palette[$token] = $pair;
        }

        ksort($palette);

        $app = $this->findApp($documents);
        $apps = $this->findAllApps($documents);
        if ($apps !== []) {
            $block = $this->buildThemeBlock($var, $theme, $themeLight, $themeDark, $palette);
            foreach ($apps as $appControl) {
                $before = (string) ($appControl->getProperty('OnStart') ?? '');
                $after = $this->upsertThemeBlock($before, $block, $appControl->format === 'yaml');
                if ($after !== $before) {
                    $appControl->setProperty('OnStart', $after);
                    $report->add(
                        self::id(),
                        $appControl->path,
                        'OnStart',
                        $before !== '' ? self::preview($before) : '(empty)',
                        'theme palette ' . $themeLight . '/' . $themeDark . '/' . $theme . ' (' . count($palette) . ' tokens)'
                    );
                }
            }
        } elseif ($app !== null) {
            $report->add(self::id(), $app->path, 'OnStart', '(missing theme inject target)', 'skipped palette inject');
        } else {
            $report->add(self::id(), '(app)', 'OnStart', '(missing App control)', 'skipped palette inject — add an App control to edit theme tokens');
        }

        $existingToggle = $this->findDarkToggle($documents, $var);
        if ($existingToggle !== null) {
            $this->wireToggle($existingToggle, $var, $theme, $themeLight, $themeDark, $report);
        } elseif ($injectToggle) {
            $screen = $this->pickIntroScreen($documents);
            if ($screen !== null && $screen->format === 'yaml') {
                $this->injectToggle($screen, $var, $theme, $themeLight, $themeDark, $report);
                foreach ($documents as $doc) {
                    if (str_contains($screen->path, $doc->relativePath) || str_starts_with($screen->path, $doc->relativePath)) {
                        $doc->reindex();
                    }
                }
            }
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($this->isThemeRadio($control)) {
                    $this->wireThemeRadio($control, $var, $theme, $themeLight, $themeDark, $report);
                }
            }
        }

        // Pass 2: point literals at gblTheme.Token
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name === self::TOGGLE_NAME) {
                    // Keep toggle chrome on theme tokens too where literals remain
                }
                foreach (self::COLOR_PROPERTIES as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || trim($from) === '') {
                        continue;
                    }
                    if ($this->alreadyThemed($from, $var, $theme) && str_contains($from, $theme . '.')) {
                        continue;
                    }
                    // Rewrite old If(gblDarkMode, RGBA..., RGBA...) style to tokens when possible
                    if ($this->alreadyThemed($from, $var, $theme) && !str_contains($from, $theme . '.')) {
                        // leave complex existing theme formulas alone
                        if (!preg_match('/^[=]?\s*If\s*\(\s*' . preg_quote($var, '/') . '\s*,/i', trim($from))) {
                            continue;
                        }
                    }
                    $parsed = ColorValue::parse($from);
                    if ($parsed === null) {
                        // Try unwrap If(gblDarkMode, dark, light) literals → token from light side
                        $pair = $this->parseLegacyIfPair($from, $var);
                        if ($pair === null) {
                            continue;
                        }
                        $parsed = $pair['light'];
                        $token = ColorValue::themeToken($parsed, $prop);
                    } else {
                        if (ColorValue::isTransparent($parsed)) {
                            continue;
                        }
                        $token = ColorValue::themeToken($parsed, $prop);
                    }

                    if (!isset($palette[$token])) {
                        continue;
                    }

                    $to = $control->format === 'yaml' ? '=' . $theme . '.' . $token : $theme . '.' . $token;
                    if ($to === $from || trim(ltrim(trim($from), '=')) === $theme . '.' . $token) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from, $to);
                }
            }
        }

        // Pass 3: ensure canvas screens use theme page background (visible dark/light switch)
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isScreen()) {
                    continue;
                }
                foreach (['Fill', 'BackgroundColor'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || trim($from) === '') {
                        $to = $control->format === 'yaml' ? '=' . $theme . '.Page' : $theme . '.Page';
                        $control->setProperty($prop, $to);
                        $report->add(self::id(), $control->path, $prop, '(unset)', $to);
                        continue;
                    }
                    if (str_contains($from, $theme . '.Page')) {
                        continue;
                    }
                    $parsed = ColorValue::parse($from);
                    $isWhite = $parsed !== null && ColorValue::luminance($parsed) >= 0.92;
                    if ($isWhite || preg_match('/^[=]?\s*Color\.White\b/i', trim($from)) === 1) {
                        $to = $control->format === 'yaml' ? '=' . $theme . '.Page' : $theme . '.Page';
                        $control->setProperty($prop, $to);
                        $report->add(self::id(), $control->path, $prop, $from, $to);
                    }
                }
            }
        }

        // Pass 4: replace legacy DefaultGrayBackgroud surfaces with theme token
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (self::COLOR_PROPERTIES as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || !str_contains($from, self::LEGACY_SURFACE)) {
                        continue;
                    }
                    $to = $this->themeFormula($control, $theme, 'Surface');
                    if ($to === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from, $to);
                }
            }
        }

        // Pass 5: labels/text without explicit Color inherit theme foreground
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isTextControl($control)) {
                    continue;
                }
                $from = $control->getProperty('Color');
                if ($from !== null && trim($from) !== '') {
                    continue;
                }
                $to = $this->themeFormula($control, $theme, 'Text');
                $control->setProperty('Color', $to);
                $report->add(self::id(), $control->path, 'Color', '(unset)', $to);
            }
        }

        // Pass 6: input surfaces (white / legacy gray fills) use InputFill token
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isInputControl($control)) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                if ($from !== null && $this->alreadyThemed($from, $var, $theme)) {
                    continue;
                }
                $useToken = 'InputFill';
                if ($from !== null && trim($from) !== '') {
                    if (str_contains($from, self::LEGACY_SURFACE)) {
                        $useToken = 'Surface';
                    } else {
                        $parsed = ColorValue::parse($from);
                        $isWhite = $parsed !== null && ColorValue::luminance($parsed) >= 0.92;
                        if (!$isWhite && !preg_match('/^[=]?\s*Color\.White\b/i', trim($from))) {
                            continue;
                        }
                    }
                }
                $to = $this->themeFormula($control, $theme, $useToken);
                if ($to === $from) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', $from ?? '(unset)', $to);
            }
        }

        // Pass 7: hyperlinks — teal in dark mode via gblTheme.Link / LinkCss (not Accent)
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($theme, $report): string {
                if (!str_contains(strtolower($path), 'htmltext')) {
                    return $formula;
                }
                $rewritten = $this->rewriteInlineLinkHtml($formula, $theme);
                if ($rewritten !== $formula) {
                    $report->add(self::id(), $path, 'HtmlText', 'color: blue', $theme . '.LinkCss');
                }

                return $rewritten;
            });

            foreach ($doc->controls() as $control) {
                if (!$this->isInlineLinkHost($control)) {
                    continue;
                }
                foreach (['Color', 'HoverColor', 'PressedColor'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from !== null && str_contains($from, $theme . '.Link')) {
                        continue;
                    }
                    $token = $prop === 'Color' ? 'Link' : 'LinkHover';
                    $to = $this->themeFormula($control, $theme, $token);
                    if ($to === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from ?? '(unset)', $to);
                }
            }
        }
    }

    /**
     * @param array<string, array{light: array{r:int,g:int,b:int,a:float}, dark: array{r:int,g:int,b:int,a:float}}> $palette
     */
    private function buildThemeBlock(
        string $var,
        string $theme,
        string $themeLight,
        string $themeDark,
        array $palette
    ): string {
        $lightFields = [];
        $darkFields = [];
        foreach ($palette as $token => $pair) {
            $lightFields[] = $token . ': ' . ColorValue::formatRgba($pair['light']);
            $dark = $pair['dark'];
            // If light==dark accent, still fine; ensure dark side has a value
            if (
                $dark['r'] === $pair['light']['r']
                && $dark['g'] === $pair['light']['g']
                && $dark['b'] === $pair['light']['b']
                && abs($dark['a'] - $pair['light']['a']) < 0.001
                && in_array($token, ['Page', 'Surface', 'SurfaceMuted', 'Text', 'TextMuted', 'Border'], true)
            ) {
                $dark = ColorValue::defaultDarkForToken($token);
            }
            $darkFields[] = $token . ': ' . ColorValue::formatRgba($dark);
        }

        if (isset($palette['Link'])) {
            $lightFields[] = 'LinkCss: "' . ColorValue::toHex($palette['Link']['light']) . '"';
            $darkFields[] = 'LinkCss: "' . ColorValue::toHex($palette['Link']['dark']) . '"';
        }
        if (isset($palette['LinkHover'])) {
            $lightFields[] = 'LinkHoverCss: "' . ColorValue::toHex($palette['LinkHover']['light']) . '"';
            $darkFields[] = 'LinkHoverCss: "' . ColorValue::toHex($palette['LinkHover']['dark']) . '"';
        }

        return self::BLOCK_START
            . "\nSet(" . $var . ", false);\n"
            . "Set({$themeLight}, {\n    " . implode(",\n    ", $lightFields) . "\n});\n"
            . "Set({$themeDark}, {\n    " . implode(",\n    ", $darkFields) . "\n});\n"
            . "Set({$theme}, {$themeLight})\n"
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
            ) ?? ($body . '; ' . $block);
        } elseif ($body === '') {
            $body = $block;
        } else {
            if (!str_ends_with($body, ';')) {
                $body .= ';';
            }
            $body .= ' ' . $block;
        }

        $body = trim($body);
        return ($yamlEquals || $hadEquals) ? '=' . $body : $body;
    }

    /** @param list<ControlDocument> $documents */
    private function findApp(array $documents): ?ControlNode
    {
        $apps = $this->findAllApps($documents);
        return $apps[0] ?? null;
    }

    /** @param list<ControlDocument> $documents
     * @return list<ControlNode>
     */
    private function findAllApps(array $documents): array
    {
        $apps = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    $apps[] = $control;
                }
            }
        }

        usort($apps, static function (ControlNode $a, ControlNode $b): int {
            $score = static function (ControlNode $c): int {
                if (str_contains($c->path, 'Src/App.')) {
                    return 0;
                }
                if (str_contains($c->path, 'Controls/')) {
                    return 1;
                }
                return 2;
            };
            return $score($a) <=> $score($b);
        });

        return $apps;
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

    private function wireToggle(
        ControlNode $toggle,
        string $var,
        string $theme,
        string $themeLight,
        string $themeDark,
        Report $report
    ): void {
        $beforeDefault = (string) ($toggle->getProperty('Default') ?? '');
        $defaultTo = $toggle->format === 'yaml' ? '=' . $var : $var;
        if (trim(ltrim($beforeDefault, '=')) !== $var) {
            $toggle->setProperty('Default', $defaultTo);
            $report->add(self::id(), $toggle->path, 'Default', $beforeDefault !== '' ? $beforeDefault : '(empty)', $defaultTo);
        }

        $onCheck = 'Set(' . $var . ', true); Set(' . $theme . ', ' . $themeDark . ')';
        $onUncheck = 'Set(' . $var . ', false); Set(' . $theme . ', ' . $themeLight . ')';

        $beforeCheck = (string) ($toggle->getProperty('OnCheck') ?? '');
        $checkTo = $toggle->format === 'yaml' ? '=' . $onCheck : $onCheck;
        if (!str_contains($beforeCheck, $themeDark)) {
            $toggle->setProperty('OnCheck', $checkTo);
            $report->add(self::id(), $toggle->path, 'OnCheck', $beforeCheck !== '' ? $beforeCheck : '(empty)', $checkTo);
        }

        $beforeUn = (string) ($toggle->getProperty('OnUncheck') ?? '');
        $unTo = $toggle->format === 'yaml' ? '=' . $onUncheck : $onUncheck;
        if (!str_contains($beforeUn, $themeLight)) {
            $toggle->setProperty('OnUncheck', $unTo);
            $report->add(self::id(), $toggle->path, 'OnUncheck', $beforeUn !== '' ? $beforeUn : '(empty)', $unTo);
        }
    }

    private function wireThemeRadio(
        ControlNode $radio,
        string $var,
        string $theme,
        string $themeLight,
        string $themeDark,
        Report $report
    ): void {
        $onChangeBody = 'If(Self.Selected.Value = "Dark", Set(' . $var . ', true); Set(' . $theme . ', ' . $themeDark . '), Set(' . $var . ', false); Set(' . $theme . ', ' . $themeLight . '))';
        $defaultBody = 'If(' . $var . ', ["Dark"], ["Light"])';

        $beforeOnChange = (string) ($radio->getProperty('OnChange') ?? '');
        $onChangeTrim = trim(ltrim($beforeOnChange, '='));
        if ($onChangeTrim === '' || $onChangeTrim === 'false' || !str_contains($beforeOnChange, $themeDark)) {
            $onChangeTo = $radio->format === 'yaml' ? '=' . $onChangeBody : $onChangeBody;
            $radio->setProperty('OnChange', $onChangeTo);
            $report->add(self::id(), $radio->path, 'OnChange', $beforeOnChange !== '' ? $beforeOnChange : '(empty)', $onChangeTo);
        }

        $beforeDefault = (string) ($radio->getProperty('DefaultSelectedItems') ?? '');
        if (!str_contains($beforeDefault, $var)) {
            $defaultTo = $radio->format === 'yaml' ? '=' . $defaultBody : $defaultBody;
            $radio->setProperty('DefaultSelectedItems', $defaultTo);
            $report->add(self::id(), $radio->path, 'DefaultSelectedItems', $beforeDefault !== '' ? $beforeDefault : '(empty)', $defaultTo);
        }

        $fontColorTo = $this->themeFormula($radio, $theme, 'Text');
        $beforeFont = (string) ($radio->getProperty('FontColor') ?? '');
        $fontTrim = trim(ltrim($beforeFont, '='));
        if ($fontTrim === '' || $fontTrim === '""') {
            $radio->setProperty('FontColor', $fontColorTo);
            $report->add(self::id(), $radio->path, 'FontColor', $beforeFont !== '' ? $beforeFont : '(unset)', $fontColorTo);
        }
    }

    private function isThemeRadio(ControlNode $control): bool
    {
        if (!str_contains(strtolower($control->type), 'radio')) {
            return false;
        }
        if (str_contains(strtolower($control->name), 'theme')) {
            return true;
        }
        $items = strtolower((string) ($control->getProperty('Items') ?? ''));
        return str_contains($items, 'light') && str_contains($items, 'dark');
    }

    private function isTextControl(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if (str_contains($t, 'label') || str_contains($t, 'htmltext')) {
            return true;
        }
        return str_contains($t, 'text') && !str_contains($t, 'textinput') && !str_contains($t, 'richtext');
    }

    private function isInputControl(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        foreach (['textinput', 'combobox', 'dropdown', 'datepicker', 'richtexteditor'] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function themeFormula(ControlNode $control, string $theme, string $token): string
    {
        return $control->format === 'yaml' ? '=' . $theme . '.' . $token : $theme . '.' . $token;
    }

    private function isInlineLinkHost(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if (!str_contains($t, 'htmlviewer') && !str_contains($t, 'htmltext')) {
            return false;
        }
        $html = strtolower((string) ($control->getProperty('HtmlText') ?? ''));
        if (str_contains($html, 'color: blue') || str_contains($html, 'color:blue')) {
            return true;
        }
        if (str_contains($html, '.linkcss')) {
            return true;
        }
        $name = strtolower($control->name);
        return str_contains($name, 'jumpto') || str_contains($name, 'link');
    }

    private function rewriteInlineLinkHtml(string $formula, string $theme): string
    {
        if (str_contains($formula, $theme . '.LinkCss')) {
            return $formula;
        }
        if (!preg_match('/color\s*:\s*blue/i', $formula)) {
            return $formula;
        }

        $patterns = [
            '/="<span style=\'color: blue; text-decoration: underline;\'>([^<]+)<\/span>"/'
                => '="<span style=""color:" & ' . $theme . '.LinkCss & "; text-decoration: underline;"">$1</span>"',
            '/="<span style=""color: blue; text-decoration: underline;"">([^<]+)<\/span>"/'
                => '="<span style=""color:" & ' . $theme . '.LinkCss & "; text-decoration: underline;"">$1</span>"',
            '/"<span style=\'color: blue; text-decoration: underline;\'>([^<]+)<\/span>"/'
                => '"<span style=""color:" & ' . $theme . '.LinkCss & "; text-decoration: underline;"">$1</span>"',
        ];

        $out = $formula;
        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $out);
            if ($replaced !== null && $replaced !== $out) {
                $out = $replaced;
            }
        }

        return $out;
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
        string $themeLight,
        string $themeDark,
        Report $report
    ): void {
        $screen->addYamlChild(self::TOGGLE_NAME, [
            'Control' => 'Classic/Toggle@2.1.0',
            'Properties' => [
                'Text' => '="Dark mode"',
                'AccessibleLabel' => '="Toggle dark mode"',
                'Tooltip' => '="Switch theme — edit colors in App.OnStart gblThemeLight / gblThemeDark"',
                'Default' => '=' . $var,
                'OnCheck' => '=Set(' . $var . ', true); Set(' . $theme . ', ' . $themeDark . ')',
                'OnUncheck' => '=Set(' . $var . ', false); Set(' . $theme . ', ' . $themeLight . ')',
                'X' => '=16',
                'Y' => '=16',
                'Width' => '=180',
                'Height' => '=40',
                'TrueFill' => '=' . $theme . '.Accent',
                'FalseFill' => '=' . $theme . '.Rail',
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
     * Load core palette defaults from config/theme_defaults.php, plus hop option overrides.
     *
     * Options:
     *   - theme_defaults_file: path to a PHP file returning token => {light, dark?}
     *   - theme_defaults: array of token => {light, dark?} (forced; wins over discovered literals)
     *
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

        // Absolute minimum set if config was empty/missing
        $fallbackLight = [
            'Page' => ['r' => 250, 'g' => 250, 'b' => 252, 'a' => 1.0],
            'Surface' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
            'Text' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
            'TextMuted' => ['r' => 100, 'g' => 116, 'b' => 139, 'a' => 1.0],
            'Border' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
            'Accent' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
            'Focus' => ['r' => 59, 'g' => 130, 'b' => 246, 'a' => 1.0],
            'Link' => ['r' => 29, 'g' => 78, 'b' => 216, 'a' => 1.0],
            'LinkHover' => ['r' => 37, 'g' => 99, 'b' => 235, 'a' => 1.0],
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
