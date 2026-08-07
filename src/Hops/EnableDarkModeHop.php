<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\HopOptions;
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
        'BasePaletteColor',
        'BackgroundColor',
        'ChevronHoverFill',
        'ChevronHoverBackground',
        'ChevronDisabledFill',
        'ChevronDisabledBackground',
        'ItemHoverFill',
        'ItemHoverColor',
        'AddedItemFill',
        'RemovedItemFill',
        'ItemErrorFill',
        'ItemErrorColor',
        'DropTargetBorderColor',
        'DropTargetBackgroundColor',
        'DropTargetTextColor',
    ];

    /** Tokens guaranteed in gblThemeLight/gblThemeDark — all control colors map here. */
    private const CORE_THEME_TOKENS = [
        'Page', 'Surface', 'SurfaceMuted', 'InputFill', 'Text', 'TextMuted', 'TextOnAccent',
        'TextOnLight', 'Border', 'BorderStrong', 'Accent', 'Focus', 'Rail', 'Link', 'LinkHover',
        'Disabled', 'Success', 'Warning',
    ];

    /** @var array<string, string> Power Apps Color enum → gblTheme token */
    private const COLOR_ENUM_MAP = [
        'Color.Green' => 'Success',
        'Color.Yellow' => 'Warning',
        'Color.Red' => 'Accent',
        'Color.Blue' => 'Accent',
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
        $force = HopOptions::force($options);

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
                    if ($this->shouldPreserveUserValue($from, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
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
        foreach (self::CORE_THEME_TOKENS as $core) {
            if (isset($palette[$core])) {
                continue;
            }
            $palette[$core] = [
                'light' => match ($core) {
                    'Page' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
                    'Surface' => ['r' => 240, 'g' => 240, 'b' => 240, 'a' => 1.0],
                    'SurfaceMuted' => ['r' => 230, 'g' => 230, 'b' => 230, 'a' => 1.0],
                    'InputFill' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
                    'Text' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
                    'TextMuted' => ['r' => 100, 'g' => 116, 'b' => 139, 'a' => 1.0],
                    'TextOnAccent' => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
                    'TextOnLight' => ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0],
                    'Border' => ['r' => 200, 'g' => 200, 'b' => 200, 'a' => 1.0],
                    'BorderStrong' => ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 1.0],
                    'Accent' => ['r' => 0, 'g' => 18, 'b' => 107, 'a' => 1.0],
                    'Success' => ['r' => 22, 'g' => 163, 'b' => 74, 'a' => 1.0],
                    'Warning' => ['r' => 234, 'g' => 179, 'b' => 8, 'a' => 1.0],
                    'Disabled' => ['r' => 119, 'g' => 119, 'b' => 119, 'a' => 0.4],
                    default => ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0],
                },
                'dark' => ColorValue::defaultDarkForToken($core),
            ];
            if ($core === 'Text') {
                $palette[$core]['dark'] = ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0];
            }
            if ($core === 'TextOnLight') {
                $palette[$core]['dark'] = ['r' => 15, 'g' => 23, 'b' => 42, 'a' => 1.0];
            }
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

        $hasThemeRadio = false;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($this->isThemeRadio($control)) {
                    $hasThemeRadio = true;
                    break 2;
                }
            }
        }

        $existingToggle = $this->findDarkToggle($documents, $var);
        if ($existingToggle !== null) {
            $this->wireToggle($existingToggle, $var, $theme, $themeLight, $themeDark, $report);
        } elseif ($injectToggle && !$hasThemeRadio) {
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

        $this->ensureThemeComponentAppScope($documents, $report);

        // Pass 2: point literals at gblTheme.Token (always gblTheme — never gblThemeDark/Light on controls)
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name === self::TOGGLE_NAME) {
                    continue;
                }
                foreach (self::COLOR_PROPERTIES as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || trim($from) === '') {
                        continue;
                    }
                    // Pure gblTheme.Token refs are done; mixed formulas may still embed RGBA literals.
                    if ($this->isPureThemeReference($from, $theme)) {
                        continue;
                    }
                    if ($this->usesActiveThemeToken($from, $theme) && preg_match('/RGBA\s*\(|\bColor\.(White|Yellow|Green|Red|Blue)\b/i', $from)) {
                        $embedded = $this->rewriteEmbeddedRgba($from, $prop, $theme, $var, true);
                        if ($embedded !== $from) {
                            $to = $control->format === 'yaml' && !str_starts_with(trim($embedded), '=')
                                && str_starts_with(trim($from), '=')
                                ? '=' . ltrim($embedded, '=')
                                : $embedded;
                            $control->setProperty($prop, $to);
                            $report->add(self::id(), $control->path, $prop, self::preview($from), self::preview($to));
                        }
                        continue;
                    }
                    if ($this->usesActiveThemeToken($from, $theme)) {
                        continue;
                    }
                    if ($this->shouldPreserveUserValue($from, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
                        continue;
                    }
                    $enumRewritten = $this->rewriteColorEnums($from, $theme);
                    if ($enumRewritten !== $from) {
                        // Color.Red → Accent may leave sibling RGBA(...) literals in the same If().
                        if (preg_match('/RGBA\s*\(|\bColor\.White\b/i', $enumRewritten)) {
                            $enumRewritten = $this->rewriteEmbeddedRgba($enumRewritten, $prop, $theme, $var, true);
                        }
                        $to = $control->format === 'yaml' && !str_starts_with(trim($enumRewritten), '=')
                            && str_starts_with(trim($from), '=')
                            ? '=' . ltrim($enumRewritten, '=')
                            : $enumRewritten;
                        $control->setProperty($prop, $to);
                        $report->add(self::id(), $control->path, $prop, self::preview($from), self::preview($to));
                        continue;
                    }
                    if ($this->usesStaticThemePalette($from, $themeLight, $themeDark)) {
                        $token = $this->extractStaticThemeToken($from, $themeLight, $themeDark);
                        if ($token !== null) {
                            $to = $this->themeFormula($control, $theme, $token);
                            $control->setProperty($prop, $to);
                            $report->add(self::id(), $control->path, $prop, $from, $to);
                        }
                        continue;
                    }

                    $parsed = ColorValue::parse($from);
                    if ($parsed === null) {
                        $embedded = $this->rewriteEmbeddedRgba($from, $prop, $theme, $var, false);
                        if ($embedded !== $from) {
                            $to = $control->format === 'yaml' && !str_starts_with(trim($embedded), '=')
                                && str_starts_with(trim($from), '=')
                                ? '=' . ltrim($embedded, '=')
                                : $embedded;
                            $control->setProperty($prop, $to);
                            $report->add(self::id(), $control->path, $prop, self::preview($from), self::preview($to));
                            continue;
                        }
                        $pair = $this->parseLegacyIfPair($from, $var);
                        if ($pair === null) {
                            continue;
                        }
                        $parsed = $pair['light'];
                    }
                    if (ColorValue::isTransparent($parsed)) {
                        continue;
                    }

                    $token = $this->coreTokenForLiteral($parsed, $prop);

                    $to = $this->themeFormula($control, $theme, $token);
                    if ($to === $from || trim(ltrim(trim($from), '=')) === $theme . '.' . $token) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from, $to);
                }
            }
        }

        // Pass 2b: sweep formulas (JSON twins / multiline) for bare RGBA literals on color props
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($theme, $themeLight, $themeDark, $var, $force, $report): string {
                if (!$this->isColorPropertyPath($path)) {
                    return $formula;
                }
                if ($this->usesActiveThemeToken($formula, $theme)) {
                    return $formula;
                }
                $prop = $this->propertyFromPath($path);
                if ($this->shouldPreserveUserValue($formula, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
                    return $formula;
                }
                $trim = trim(ltrim(trim($formula), '='));
                $parsed = ColorValue::parse($trim);
                if ($parsed === null || ColorValue::isTransparent($parsed)) {
                    if ($this->usesStaticThemePalette($formula, $themeLight, $themeDark)) {
                        $token = $this->extractStaticThemeToken($formula, $themeLight, $themeDark);
                        if ($token !== null) {
                            $report->add(self::id(), $path, 'formula', $themeLight . '/' . $themeDark, $theme . '.' . $token);
                            return str_starts_with(trim($formula), '=') ? '=' . $theme . '.' . $token : $theme . '.' . $token;
                        }
                    }
                    $pair = $this->parseLegacyIfPair($formula, $var);
                    if ($pair !== null && !ColorValue::isTransparent($pair['light'])) {
                        $prop = $this->propertyFromPath($path);
                        $token = $this->coreTokenForLiteral($pair['light'], $prop);
                        $report->add(self::id(), $path, 'formula', 'If(' . $var . ',…)', $theme . '.' . $token);
                        return str_starts_with(trim($formula), '=') ? '=' . $theme . '.' . $token : $theme . '.' . $token;
                    }
                    return $formula;
                }
                $prop = $this->propertyFromPath($path);
                $token = $this->coreTokenForLiteral($parsed, $prop);
                $report->add(self::id(), $path, 'formula', ColorValue::formatRgba($parsed), $theme . '.' . $token);
                return str_starts_with(trim($formula), '=') ? '=' . $theme . '.' . $token : $theme . '.' . $token;
            });
        }

        // Pass 2c: replace embedded RGBA(...) in color formulas (ColorFade, commented blocks, etc.)
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($theme, $themeLight, $themeDark, $var, $force, $report): string {
                if (!$this->isColorPropertyPath($path)) {
                    return $formula;
                }
                if ($this->usesActiveThemeToken($formula, $theme)) {
                    return $formula;
                }
                $prop = $this->propertyFromPath($path);
                if ($this->shouldPreserveUserValue($formula, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
                    return $formula;
                }
                $changed = false;
                $out = \PowerSweeper\PowerFxFormulaSegments::transformCode($formula, function (string $code) use ($theme, $prop, $path, $report, &$changed): string {
                    $enumCode = $this->rewriteColorEnums($code, $theme);
                    if ($enumCode !== $code) {
                        $report->add(self::id(), $path, 'Color enum', $code, $enumCode);
                        $code = $enumCode;
                        $changed = true;
                    }
                    $replaced = preg_replace_callback('/RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[0-9.]+)?\s*\)/i', function (array $m) use ($theme, $prop, $path, $report, &$changed): string {
                        $parsed = ColorValue::parse($m[0]);
                        if ($parsed === null || ColorValue::isTransparent($parsed)) {
                            return $m[0];
                        }
                        $token = $this->coreTokenForLiteral($parsed, $prop);
                        $changed = true;
                        $report->add(self::id(), $path, 'embedded RGBA', $m[0], $theme . '.' . $token);

                        return $theme . '.' . $token;
                    }, $code);

                    return is_string($replaced) ? $replaced : $code;
                });

                return $changed ? $out : $formula;
            });
        }

        // Pass 3: all canvas screens use gblTheme.Page (visible light/dark page background)
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isScreen()) {
                    continue;
                }
                foreach (['Fill', 'BackgroundColor'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from !== null && $this->usesActiveThemeToken($from, $theme) && str_contains($from, $theme . '.Page')) {
                        continue;
                    }
                    if ($from !== null && $this->shouldPreserveUserValue($from, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
                        continue;
                    }
                    $to = $this->themeFormula($control, $theme, 'Page');
                    if ($to === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from ?? '(unset)', $to);
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

        // Pass 5: labels/text — unset Color or hard-coded literals → gblTheme.Text / TextMuted
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isTextControl($control)) {
                    continue;
                }
                $from = $control->getProperty('Color');
                if ($from !== null && $this->usesActiveThemeToken($from, $theme)) {
                    continue;
                }
                if ($from !== null && $this->shouldPreserveUserValue($from, 'Color', $force, $theme, $var, $themeLight, $themeDark)) {
                    continue;
                }
                $token = 'Text';
                if ($from !== null && trim($from) !== '') {
                    $parsed = ColorValue::parse($from);
                    if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                        $token = $this->coreTokenForLiteral($parsed, 'Color');
                        if ($token !== 'Text' && $token !== 'TextMuted' && $token !== 'TextOnAccent' && $token !== 'TextOnLight' && $token !== 'Link') {
                            $token = ColorValue::luminance($parsed) > 0.55 ? 'TextMuted' : 'Text';
                        }
                    }
                }
                $to = $this->themeFormula($control, $theme, $token);
                if ($to === $from) {
                    continue;
                }
                $control->setProperty('Color', $to);
                $report->add(self::id(), $control->path, 'Color', $from ?? '(unset)', $to);
            }
        }

        // Pass 5b: dark ink on pastel ColorFade chips / bright Warning|Success banners
        $this->applyTextOnLightSurfaces($documents, $theme, $report);

        // Pass 6: input surfaces (white / legacy gray fills) use InputFill token + readable Color
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isInputControl($control)) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                $fromStr = $from !== null ? (string) $from : '';
                // White literals often become Page/Surface in Pass 2 — promote inputs to InputFill.
                if ($this->isPureThemeReference($fromStr, $theme)) {
                    $trim = trim($fromStr);
                    if (str_starts_with($trim, '=')) {
                        $trim = trim(substr($trim, 1));
                    }
                    $token = substr($trim, strlen($theme) + 1);
                    if (in_array($token, ['Page', 'Surface', 'SurfaceMuted'], true)) {
                        $to = $this->themeFormula($control, $theme, 'InputFill');
                        if ($to !== $fromStr) {
                            $control->setProperty('Fill', $to);
                            $report->add(self::id(), $control->path, 'Fill', $fromStr, $to);
                        }
                    }
                } elseif ($fromStr === '' || !$this->alreadyThemed($fromStr, $var, $theme)) {
                    $useToken = 'InputFill';
                    $skipFill = false;
                    if ($fromStr !== '') {
                        if (str_contains($fromStr, self::LEGACY_SURFACE)) {
                            $useToken = 'Surface';
                        } elseif ($this->shouldPreserveUserValue($fromStr, 'Fill', $force, $theme, $var, $themeLight, $themeDark)) {
                            $skipFill = true;
                        } else {
                            $parsed = ColorValue::parse($fromStr);
                            $isWhite = $parsed !== null && ColorValue::luminance($parsed) >= 0.92;
                            if (!$isWhite && !preg_match('/^[=]?\s*Color\.White\b/i', trim($fromStr))) {
                                $skipFill = true;
                            }
                        }
                    }
                    if (!$skipFill) {
                        $to = $this->themeFormula($control, $theme, $useToken);
                        if ($to !== $fromStr) {
                            $control->setProperty('Fill', $to);
                            $report->add(self::id(), $control->path, 'Fill', $fromStr !== '' ? $fromStr : '(unset)', $to);
                        }
                    }
                }
                $this->ensureInputReadableChrome($control, $theme, $var, $themeLight, $themeDark, $force, $report);
            }
        }

        // Pass 6b: number inputs + rich text editors — Fill/Color/Border chrome (often unset on ModernNumberInput)
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($this->isRichTextInput($control)) {
                    $this->applyRichTextChrome($control, $theme, $var, $themeLight, $themeDark, $force, $report);
                    continue;
                }
                if ($this->isNumberInput($control)) {
                    $this->applyModernInputChrome($control, $theme, $var, $themeLight, $themeDark, $force, $report);
                }
            }
        }

        // Pass 6e: DatePicker + DataTable auxiliary color chrome (THCEE cost-rate tables, trip dates)
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($this->isDatePicker($control)) {
                    $this->applyDatePickerChrome($control, $theme, $var, $themeLight, $themeDark, $force, $report);
                    continue;
                }
                if ($this->isDataTable($control)) {
                    $this->applyDataTableChrome($control, $theme, $var, $themeLight, $themeDark, $force, $report);
                }
            }
        }

        // Pass 6d: section containers around rich text editors (e.g. 10_Remarks)
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isContainer()) {
                    continue;
                }
                if (!preg_match('/(?:^|\/)10_Remarks(\/|\.|$)|BugReportingContainer(\/|\.|$)/i', $control->path)) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                if ($from !== null && trim($from) !== '' && $this->alreadyThemed($from, $var, $theme)) {
                    continue;
                }
                if ($from !== null && trim($from) !== '') {
                    $parsed = ColorValue::parse($from);
                    if ($parsed !== null && !ColorValue::isTransparent($parsed) && !$this->usesActiveThemeToken($from, $theme)) {
                        if ($this->shouldPreserveUserValue($from, 'Fill', $force, $theme, $var, $themeLight, $themeDark)) {
                            continue;
                        }
                    }
                }
                $to = $this->themeFormula($control, $theme, 'Surface');
                if ($to === $from) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', $from ?? '(unset)', $to);
            }
        }

        // Pass 6c: sweep JSON/YAML twins for nested rich-text / number template colors
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($theme, $themeLight, $themeDark, $var, $force, $report): string {
                if (!$this->isModernInputColorPath($path)) {
                    return $formula;
                }
                if ($this->usesActiveThemeToken($formula, $theme)) {
                    return $formula;
                }
                $prop = $this->propertyFromPath($path);
                if ($this->shouldPreserveUserValue($formula, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
                    return $formula;
                }
                $enumRewritten = $this->rewriteColorEnums($formula, $theme);
                if ($enumRewritten !== $formula) {
                    $report->add(self::id(), $path, 'Color enum', $formula, $enumRewritten);
                    $formula = $enumRewritten;
                }
                $trim = trim(ltrim(trim($formula), '='));
                $parsed = ColorValue::parse($trim);
                if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                    $token = $this->coreTokenForLiteral($parsed, $prop);
                    $report->add(self::id(), $path, 'formula', ColorValue::formatRgba($parsed), $theme . '.' . $token);
                    return str_starts_with(trim($formula), '=') ? '=' . $theme . '.' . $token : $theme . '.' . $token;
                }
                if ($this->usesStaticThemePalette($formula, $themeLight, $themeDark)) {
                    $token = $this->extractStaticThemeToken($formula, $themeLight, $themeDark);
                    if ($token !== null) {
                        $report->add(self::id(), $path, 'formula', $themeLight . '/' . $themeDark, $theme . '.' . $token);
                        return str_starts_with(trim($formula), '=') ? '=' . $theme . '.' . $token : $theme . '.' . $token;
                    }
                }

                return $formula;
            });
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
        $itemsBody = '["Light", "Dark"]';

        $beforeItems = (string) ($radio->getProperty('Items') ?? '');
        if (!str_contains(strtolower($beforeItems), 'dark')) {
            $itemsTo = $radio->format === 'yaml' ? '=' . $itemsBody : $itemsBody;
            $radio->setProperty('Items', $itemsTo);
            $report->add(self::id(), $radio->path, 'Items', $beforeItems !== '' ? $beforeItems : '(unset)', $itemsTo);
        }

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

    /** @param list<ControlDocument> $documents */
    private function ensureThemeComponentAppScope(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            if (!str_contains($doc->relativePath, 'TopbarHeader')) {
                continue;
            }
            foreach ($doc->controls() as $control) {
                if ($control->name !== 'TopbarHeader' || !str_contains($control->path, 'ComponentDefinitions')) {
                    continue;
                }
                $beforeRoot = $control->getYamlDefinitionField('AccessAppScope');
                $hadInProperties = $control->getProperty('AccessAppScope') !== null;
                if ($beforeRoot !== true && $beforeRoot !== 'true') {
                    $control->setYamlDefinitionField('AccessAppScope', true);
                    $report->add(
                        self::id(),
                        $control->path,
                        'AccessAppScope',
                        $beforeRoot === null ? '(unset)' : (string) $beforeRoot,
                        'true'
                    );
                }
                if ($hadInProperties) {
                    $control->removeProperty('AccessAppScope');
                    $report->add(self::id(), $control->path, 'AccessAppScope', '(duplicate in Properties)', '(root only)');
                }
            }
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
        if (str_contains($items, 'english') || str_contains($items, 'french')) {
            return false;
        }
        if (str_contains($items, 'light') && str_contains($items, 'dark')) {
            return true;
        }
        // Incomplete theme stub (Light only) inside TopbarHeader settings
        return str_contains($items, 'light') && str_contains($control->path, 'TopbarHeader');
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
        foreach (['textinput', 'combobox', 'dropdown', 'datepicker', 'richtexteditor', 'numberinput'] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Status pills often use ColorFade(BorderColor, 80%) — a pastel that stays light in dark mode.
     * White gblTheme.Text on those chips fails contrast; switch child labels to TextOnLight.
     *
     * @param list<ControlDocument> $documents
     */
    private function applyTextOnLightSurfaces(array $documents, string $theme, Report $report): void
    {
        /** @var array<string, string> $fillByPath */
        $fillByPath = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $fillByPath[$control->path] = (string) ($control->getProperty('Fill') ?? '');
            }
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isTextControl($control) && !$this->isIconControl($control)) {
                    continue;
                }
                $ownFill = (string) ($control->getProperty('Fill') ?? '');
                // ColorFade chips stay pastel/light in both themes — need dark ink.
                // Solid Warning/Success banners use darkened palette + white Text instead.
                $needsDarkInk = $this->isPastelChipFill($ownFill);
                if (!$needsDarkInk) {
                    foreach ($this->ancestorFills($control->path, $fillByPath) as $parentFill) {
                        if ($this->isPastelChipFill($parentFill)) {
                            $needsDarkInk = true;
                            break;
                        }
                    }
                }
                if (!$needsDarkInk) {
                    continue;
                }
                $colorProp = $this->isIconControl($control) && $control->getProperty('IconColor') !== null
                    ? 'IconColor'
                    : 'Color';
                $from = $control->getProperty($colorProp);
                // Only rewrite light/white theme text — leave explicit Accent/Link alone.
                if ($from !== null && trim($from) !== '' && !$this->isLightInkCandidate((string) $from, $theme)) {
                    continue;
                }
                $to = $this->themeFormula($control, $theme, 'TextOnLight');
                if ($to === $from) {
                    continue;
                }
                $control->setProperty($colorProp, $to);
                $report->add(self::id(), $control->path, $colorProp, $from ?? '(unset)', $to);
            }
        }
    }

    /** @param array<string, string> $fillByPath @return list<string> */
    private function ancestorFills(string $path, array $fillByPath): array
    {
        $fills = [];
        $parts = explode('/', $path);
        while (count($parts) > 1) {
            array_pop($parts);
            $parent = implode('/', $parts);
            if (array_key_exists($parent, $fillByPath)) {
                $fills[] = $fillByPath[$parent];
            }
        }

        return $fills;
    }

    private function isPastelChipFill(string $fill): bool
    {
        return (bool) preg_match('/ColorFade\s*\(/i', $fill);
    }

    private function isLightInkCandidate(string $colorFormula, string $theme): bool
    {
        if ($this->isPureThemeReference($colorFormula, $theme)) {
            $trim = trim($colorFormula);
            if (str_starts_with($trim, '=')) {
                $trim = trim(substr($trim, 1));
            }
            $token = substr($trim, strlen($theme) + 1);

            return in_array($token, ['Text', 'TextMuted', 'TextOnAccent'], true);
        }
        $parsed = ColorValue::parse($colorFormula);
        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
            return ColorValue::luminance($parsed) > 0.55;
        }

        // Mixed If(...) with gblTheme.Text — still a light-ink candidate.
        return str_contains($colorFormula, $theme . '.Text')
            || str_contains($colorFormula, $theme . '.TextOnAccent');
    }

    private function isIconControl(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'icon') && !str_contains($t, 'button');
    }

    private function ensureInputReadableChrome(
        ControlNode $control,
        string $theme,
        string $var,
        string $themeLight,
        string $themeDark,
        bool $force,
        Report $report,
    ): void {
        $color = $control->getProperty('Color');
        $colorStr = $color !== null ? trim((string) $color) : '';
        // Only fill missing / simple literal colors — do not clobber If(...) formulas.
        if ($colorStr === '' || ColorValue::parse($colorStr) !== null || preg_match('/^[=]?\s*Color\.\w+\s*$/i', $colorStr)) {
            $this->applyThemedControlProperty(
                $control,
                'Color',
                'Text',
                $theme,
                $var,
                $themeLight,
                $themeDark,
                $force,
                $report,
                'Data',
                false
            );
        }

        $base = $control->getProperty('BasePaletteColor');
        if ($base !== null && trim((string) $base) !== '') {
            $baseStr = (string) $base;
            // TextMuted-as-brand washes out Fluent FilledDarker inputs in dark mode.
            if (
                str_contains($baseStr, $theme . '.TextMuted')
                || preg_match('/RGBA\s*\(\s*1\d{2}\s*,\s*1\d{2}\s*,\s*1\d{2}/i', $baseStr)
            ) {
                $to = $this->themeFormula($control, $theme, 'Accent');
                if ($to !== $baseStr) {
                    $control->setProperty('BasePaletteColor', $to, 'Design');
                    $report->add(self::id(), $control->path, 'BasePaletteColor', $baseStr, $to);
                }
            }
        }

        $appearance = trim((string) ($control->getProperty('Appearance') ?? ''));
        $type = strtolower($control->type);
        if (
            $appearance !== ''
            && (str_contains($type, 'moderntextinput') || (str_contains($type, 'textinput') && str_contains($type, 'modern')))
            && preg_match('/Appearance\.Outline\b/i', $appearance)
        ) {
            $to = $control->format === 'yaml' ? '=Appearance.FilledDarker' : 'Appearance.FilledDarker';
            if ($to !== $appearance) {
                $control->setProperty('Appearance', $to, 'Design');
                $report->add(self::id(), $control->path, 'Appearance', $appearance, $to);
            }
        }
    }

    private function isNumberInput(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'numberinput');
    }

    private function isRichTextInput(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'richtext');
    }

    private function isDatePicker(ControlNode $control): bool
    {
        return str_contains(strtolower($control->type), 'datepicker');
    }

    private function isDataTable(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'datatable') && !str_contains($t, 'column');
    }

    private function applyDatePickerChrome(ControlNode $control, string $theme, string $var, string $themeLight, string $themeDark, bool $force, Report $report): void
    {
        $props = [
            'IconFill' => 'Text',
            'CurrentDateFill' => 'InputFill',
            'MonthColor' => 'Text',
            'WeekColor' => 'TextMuted',
            'DayColor' => 'Text',
            'SelectedDateFill' => 'Accent',
            'HoverDateFill' => 'SurfaceMuted',
            'CalendarHeaderFill' => 'Accent',
            'BorderColor' => 'Border',
            'FocusedBorderColor' => 'Accent',
            'Color' => 'Text',
            'DisabledColor' => 'TextMuted',
            'DisabledFill' => 'InputFill',
        ];
        foreach ($props as $prop => $token) {
            $this->applyThemedControlProperty($control, $prop, $token, $theme, $var, $themeLight, $themeDark, $force, $report, 'Design', $prop === 'BorderColor');
        }
    }

    private function applyDataTableChrome(ControlNode $control, string $theme, string $var, string $themeLight, string $themeDark, bool $force, Report $report): void
    {
        $props = [
            'LinkColor' => 'Link',
            'PrimaryColor1' => 'Accent',
            'PrimaryColor2' => 'Accent',
            'PrimaryColor3' => 'SurfaceMuted',
            'InputFill' => 'InputFill',
            'InvertedColor' => 'Text',
            'HeadingColor' => 'Text',
            'Fill' => 'Surface',
            'Color' => 'Text',
            'BorderColor' => 'Border',
        ];
        foreach ($props as $prop => $token) {
            $this->applyThemedControlProperty($control, $prop, $token, $theme, $var, $themeLight, $themeDark, $force, $report, 'Design', $prop === 'BorderColor');
        }
    }

    /** Nested template + YAML paths for modern number / rich-text controls. */
    private function isModernInputColorPath(string $path): bool
    {
        if (!preg_match('/RichTextEditor|richTextEditor|ModernNumberInput|modernNumberInput|DatePicker|datePicker|DataTable|dataTable|\/Remarks(\/|\.|$)/i', $path)) {
            return false;
        }

        return (bool) preg_match('/\.(Fill|Color|BorderColor|FontColor|BasePaletteColor|TemplateFill|Appearance|HoverFill|HoverColor|DisabledFill|DisabledColor|FocusedBorderColor|PressedFill|PressedColor|BackgroundColor|LoadingSpinnerColor|IconFill|CurrentDateFill|MonthColor|WeekColor|DayColor|SelectedDateFill|HoverDateFill|CalendarHeaderFill|LinkColor|PrimaryColor1|PrimaryColor2|PrimaryColor3|InputFill|InvertedColor|HeadingColor)(\.|$)/i', $path);
    }

    private function applyRichTextChrome(ControlNode $control, string $theme, string $var, string $themeLight, string $themeDark, bool $force, Report $report): void
    {
        $styleName = $control->getStyleName();
        if ($styleName !== null && $styleName !== '') {
            $control->clearStyleName();
            $report->add(self::id(), $control->path, 'StyleName', $styleName, '(cleared for gblTheme)');
        }

        $props = [
            'Fill' => ['token' => 'InputFill', 'category' => 'Data'],
            'Color' => ['token' => 'Text', 'category' => 'Data'],
            'FontColor' => ['token' => 'Text', 'category' => 'Design'],
            'TemplateFill' => ['token' => 'InputFill', 'category' => 'Design'],
            'BasePaletteColor' => ['token' => 'Accent', 'category' => 'Design'],
            'FocusedBorderColor' => ['token' => 'Accent', 'category' => 'Design'],
            'BorderColor' => ['token' => 'Border', 'category' => 'Design'],
            'HoverFill' => ['token' => 'InputFill', 'category' => 'Design'],
            'HoverColor' => ['token' => 'Text', 'category' => 'Design'],
            'DisabledFill' => ['token' => 'InputFill', 'category' => 'Design'],
            'DisabledColor' => ['token' => 'TextMuted', 'category' => 'Design'],
            'LoadingSpinnerColor' => ['token' => 'Accent', 'category' => 'Design'],
        ];
        foreach ($props as $prop => $meta) {
            $this->applyThemedControlProperty($control, $prop, $meta['token'], $theme, $var, $themeLight, $themeDark, $force, $report, $meta['category'], $prop === 'BorderColor');
        }

        $appearance = (string) ($control->getProperty('Appearance') ?? '');
        if ($appearance === '' || !str_contains($appearance, 'FilledDarker')) {
            $to = $control->format === 'yaml' ? '=Appearance.FilledDarker' : 'Appearance.FilledDarker';
            $control->setProperty('Appearance', $to, 'Design');
            $report->add(self::id(), $control->path, 'Appearance', $appearance !== '' ? $appearance : '(unset)', $to);
        }
    }

    private function applyModernInputChrome(ControlNode $control, string $theme, string $var, string $themeLight, string $themeDark, bool $force, Report $report): void
    {
        $props = [
            'Fill' => 'InputFill',
            'Color' => 'Text',
            'FocusedBorderColor' => 'Accent',
            'BorderColor' => 'Border',
            'HoverFill' => 'InputFill',
            'HoverColor' => 'Text',
            'DisabledFill' => 'InputFill',
            'DisabledColor' => 'TextMuted',
        ];
        foreach ($props as $prop => $token) {
            $this->applyThemedControlProperty($control, $prop, $token, $theme, $var, $themeLight, $themeDark, $force, $report, 'Data', false);
        }
    }

    private function applyThemedControlProperty(
        ControlNode $control,
        string $prop,
        string $token,
        string $theme,
        string $var,
        string $themeLight,
        string $themeDark,
        bool $force,
        Report $report,
        string $category = 'Data',
        bool $overrideTransparentBorder = false,
    ): void {
        $from = $control->getProperty($prop);
        if ($from !== null && $this->alreadyThemed($from, $var, $theme)) {
            return;
        }
        if ($from !== null && trim($from) !== '' && $this->shouldPreserveUserValue($from, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
            return;
        }
        if ($from !== null && trim($from) !== '') {
            $parsed = ColorValue::parse($from);
            if ($parsed !== null && ColorValue::isTransparent($parsed) && $prop !== 'BorderColor') {
                return;
            }
            if ($parsed !== null && ColorValue::isTransparent($parsed) && $prop === 'BorderColor' && !$overrideTransparentBorder) {
                return;
            }
            if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                $mapped = $this->coreTokenForLiteral($parsed, $prop);
                $to = $this->themeFormula($control, $theme, $mapped);
                if ($to !== $from) {
                    $control->setProperty($prop, $to, $category);
                    $report->add(self::id(), $control->path, $prop, $from, $to);
                }
                return;
            }
            $enumRewritten = $this->rewriteColorEnums($from, $theme);
            if ($enumRewritten !== $from) {
                $to = $control->format === 'yaml' && !str_starts_with(trim($enumRewritten), '=')
                    && str_starts_with(trim($from), '=')
                    ? '=' . ltrim($enumRewritten, '=')
                    : $enumRewritten;
                $control->setProperty($prop, $to, $category);
                $report->add(self::id(), $control->path, $prop, $from, $to);
                return;
            }
        }
        $to = $this->themeFormula($control, $theme, $token);
        if ($to === $from) {
            return;
        }
        $control->setProperty($prop, $to, $category);
        $report->add(self::id(), $control->path, $prop, $from ?? '(unset)', $to);
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

    private function rewriteEmbeddedRgba(string $formula, string $property, string $theme, string $var, bool $allowMixedTheme = false): string
    {
        if (!$allowMixedTheme && $this->usesActiveThemeToken($formula, $theme)) {
            return $formula;
        }
        $changed = false;
        $out = \PowerSweeper\PowerFxFormulaSegments::transformCode($formula, function (string $code) use ($property, $theme, &$changed): string {
            $replaced = preg_replace_callback('/RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[0-9.]+)?\s*\)/i', function (array $m) use ($property, $theme, &$changed): string {
                $parsed = ColorValue::parse($m[0]);
                if ($parsed === null || ColorValue::isTransparent($parsed)) {
                    return $m[0];
                }
                $changed = true;

                return $theme . '.' . $this->coreTokenForLiteral($parsed, $property);
            }, $code);
            $replaced = preg_replace('/\bColor\.White\b/i', $theme . '.Page', $replaced ?? $code);
            $replaced = $this->rewriteColorEnums($replaced ?? $code, $theme);
            if ($replaced !== $code) {
                $changed = true;
            }

            return is_string($replaced) ? $replaced : $code;
        });
        if (!$changed && !$allowMixedTheme) {
            $pair = $this->parseLegacyIfPair($formula, $var);
            if ($pair !== null && !ColorValue::isTransparent($pair['light'])) {
                return $theme . '.' . $this->coreTokenForLiteral($pair['light'], $property);
            }
        }

        return $changed ? $out : $formula;
    }

    private function rewriteColorEnums(string $formula, string $theme): string
    {
        $out = $formula;
        foreach (self::COLOR_ENUM_MAP as $enum => $token) {
            $replaced = preg_replace('/\b' . preg_quote($enum, '/') . '\b/i', $theme . '.' . $token, $out);
            if (is_string($replaced)) {
                $out = $replaced;
            }
        }

        $legacyTheme = [
            'App.Theme.Colors.Primary' => $theme . '.Accent',
            'App.Theme.Colors.Lighter70' => $theme . '.SurfaceMuted',
            'App.Theme.Colors.Lighter30' => $theme . '.SurfaceMuted',
            'App.Theme.Colors.Darker70' => $theme . '.Accent',
            'App.Theme.Colors.Darker30' => $theme . '.Accent',
        ];
        foreach ($legacyTheme as $from => $to) {
            if (str_contains($out, $from)) {
                $out = str_replace($from, $to, $out);
            }
        }

        return $out;
    }

    private function usesActiveThemeToken(string $formula, string $theme): bool
    {
        return str_contains($formula, $theme . '.');
    }

    /** True when the formula is only a gblTheme.Token reference (optional leading =). */
    private function isPureThemeReference(string $formula, string $theme): bool
    {
        $trim = trim($formula);
        if (str_starts_with($trim, '=')) {
            $trim = trim(substr($trim, 1));
        }

        return (bool) preg_match(
            '/^' . preg_quote($theme, '/') . '\.[A-Za-z_][\w]*$/',
            $trim
        );
    }

    private function usesStaticThemePalette(string $formula, string $themeLight, string $themeDark): bool
    {
        return str_contains($formula, $themeLight . '.') || str_contains($formula, $themeDark . '.');
    }

    private function extractStaticThemeToken(string $formula, string $themeLight, string $themeDark): ?string
    {
        foreach ([$themeLight, $themeDark] as $paletteVar) {
            if (preg_match('/' . preg_quote($paletteVar, '/') . '\.([A-Za-z_][\w]*)/', $formula, $m)) {
                return $this->normalizeToCoreToken($m[1]);
            }
        }

        return null;
    }

    /**
     * Map any literal + property to a core gblTheme token (toggle-safe).
     *
     * @param array{r:int,g:int,b:int,a:float} $parsed
     */
    private function coreTokenForLiteral(array $parsed, string $property): string
    {
        return $this->normalizeToCoreToken(ColorValue::themeToken($parsed, $property));
    }

    private function normalizeToCoreToken(string $token): string
    {
        if (in_array($token, self::CORE_THEME_TOKENS, true)) {
            return $token;
        }

        return match (true) {
            str_contains($token, 'Link') => str_contains($token, 'Hover') ? 'LinkHover' : 'Link',
            // Semantic *Text tokens are ink-on-bright-fill → TextOnLight (not white Text).
            $token === 'WarningText' || $token === 'SuccessText' || $token === 'DangerText' => 'TextOnLight',
            str_contains($token, 'OnLight') => 'TextOnLight',
            str_contains($token, 'Text') || str_ends_with($token, 'Text') => 'Text',
            str_contains($token, 'Border') || $token === 'Focus' => str_contains($token, 'Strong') ? 'BorderStrong' : 'Border',
            str_contains($token, 'Success') => 'Success',
            str_contains($token, 'Warning') => 'Warning',
            str_contains($token, 'Accent') || str_contains($token, 'Selection') || str_contains($token, 'Highlight')
                || str_contains($token, 'Checkmark') || str_contains($token, 'Progress') || str_contains($token, 'Danger') => 'Accent',
            str_contains($token, 'Disabled') => 'Disabled',
            str_contains($token, 'Rail') || str_contains($token, 'Handle') || str_contains($token, 'Track') => 'Rail',
            str_contains($token, 'Input') => 'InputFill',
            str_contains($token, 'Page') => 'Page',
            str_contains($token, 'Muted') => 'SurfaceMuted',
            str_contains($token, 'Inverse') || str_contains($token, 'Alt') => 'Surface',
            default => 'Surface',
        };
    }

    private function isColorPropertyPath(string $path): bool
    {
        foreach (self::COLOR_PROPERTIES as $prop) {
            if (str_ends_with($path, '.' . $prop) || str_contains($path, '.' . $prop)) {
                return true;
            }
        }

        return (bool) preg_match('/\.(Fill|Color|BorderColor|FontColor|BasePaletteColor|BackgroundColor|HoverFill|HoverColor|DisabledFill|DisabledColor|LoadingSpinnerColor|ItemHoverFill|ItemHoverColor|AddedItemFill|RemovedItemFill|ItemErrorFill|ItemErrorColor|DropTargetBorderColor|DropTargetBackgroundColor|DropTargetTextColor)(\.|$)/i', $path);
    }

    private function propertyFromPath(string $path): string
    {
        foreach (self::COLOR_PROPERTIES as $prop) {
            if (str_ends_with($path, '.' . $prop)) {
                return $prop;
            }
        }
        if (preg_match('/\.([A-Za-z_][\w]*)$/', $path, $m)) {
            return $m[1];
        }

        return 'Fill';
    }

    private function alreadyThemed(string $formula, string $var, string $theme): bool
    {
        return $this->usesActiveThemeToken($formula, $theme);
    }

    /**
     * When force is off, keep literals the maker likely chose (non-default colors/fills).
     */
    private function shouldPreserveUserValue(
        string $from,
        string $prop,
        bool $force,
        string $theme,
        string $var,
        string $themeLight,
        string $themeDark,
    ): bool {
        if ($force || trim($from) === '') {
            return false;
        }
        if ($this->usesActiveThemeToken($from, $theme)) {
            return true;
        }
        if (str_contains($from, self::LEGACY_SURFACE)) {
            return false;
        }
        if ($this->usesStaticThemePalette($from, $themeLight, $themeDark)) {
            return false;
        }
        if ($this->parseLegacyIfPair($from, $var) !== null) {
            return false;
        }
        foreach (array_keys(self::COLOR_ENUM_MAP) as $enum) {
            if (preg_match('/\b' . preg_quote($enum, '/') . '\b/i', $from)) {
                return false;
            }
        }
        if (preg_match('/App\.Theme\.Colors\./', $from)) {
            return false;
        }
        if (preg_match('/\bColor\.White\b/i', $from)) {
            return false;
        }

        return !ColorValue::isStudioDefault($from, $prop);
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

        $forced = $this->normalizePaletteMap($loaded);
        if (isset($options['theme_defaults']) && is_array($options['theme_defaults'])) {
            $forced = array_merge($forced, $this->normalizePaletteMap($options['theme_defaults']));
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
