<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\ControlNode;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\SweepOptions;
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
final class EnableDarkModeSweep implements SweepInterface
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
        'TextOnLight', 'Border', 'BorderStrong', 'Accent', 'AccentHover', 'Focus', 'Rail', 'Link', 'LinkHover',
        'Disabled', 'Success', 'SuccessHover', 'Warning', 'WarningHover', 'Danger', 'DangerHover', 'SurfaceAlt', 'Selected',
    ];

    /** @var array<string, string> Power Apps Color enum → gblTheme token */
    private const COLOR_ENUM_MAP = [
        'Color.Green' => 'Success',
        'Color.Yellow' => 'Warning',
        'Color.Red' => 'Accent',
        'Color.Blue' => 'Accent',
        // PAT / PACS / VCDS chrome — light tints vs strong fills
        'Color.Azure' => 'SurfaceMuted',
        'Color.LightBlue' => 'SurfaceMuted',
        'Color.SkyBlue' => 'SurfaceMuted',
        'Color.AliceBlue' => 'SurfaceMuted',
        'Color.DarkCyan' => 'Accent',
        'Color.Aquamarine' => 'Accent',
        'Color.DodgerBlue' => 'Accent',
        'Color.CornflowerBlue' => 'Accent',
        'Color.SteelBlue' => 'Accent',
        'Color.RoyalBlue' => 'Accent',
        'Color.MediumBlue' => 'Accent',
        'Color.Navy' => 'Accent',
        'Color.MidnightBlue' => 'Accent',
        'Color.Teal' => 'Accent',
        'Color.Turquoise' => 'Accent',
        'Color.CadetBlue' => 'Accent',
        'Color.Indigo' => 'Accent',
    ];

    private const LEGACY_SURFACE = 'DefaultGrayBackgroud';
    private const LEGACY_BORDER = 'DefaultBorderColor';

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
        $force = SweepOptions::force($options);

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
                    $from = $this->recoverCommentedColorFormula($from, $prop);
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
        // Sweep theme_defaults always win (brand palette without touching controls)
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
                    'AccentHover' => ['r' => 30, 'g' => 64, 'b' => 175, 'a' => 1.0],
                    'Success' => ['r' => 22, 'g' => 163, 'b' => 74, 'a' => 1.0],
                    'SuccessHover' => ['r' => 21, 'g' => 128, 'b' => 61, 'a' => 1.0],
                    'Warning' => ['r' => 234, 'g' => 179, 'b' => 8, 'a' => 1.0],
                    'WarningHover' => ['r' => 202, 'g' => 138, 'b' => 4, 'a' => 1.0],
                    'Danger' => ['r' => 220, 'g' => 38, 'b' => 38, 'a' => 1.0],
                    'DangerHover' => ['r' => 185, 'g' => 28, 'b' => 28, 'a' => 1.0],
                    'SurfaceAlt' => ['r' => 226, 'g' => 232, 'b' => 240, 'a' => 1.0],
                    'Disabled' => ['r' => 244, 'g' => 244, 'b' => 244, 'a' => 1.0],
                    'Selected' => ['r' => 79, 'g' => 90, 'b' => 94, 'a' => 1.0],
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
                } elseif ($this->isRadioControl($control)) {
                    // Language (and other) radios need the same readable FontColor as theme radios.
                    $this->ensureRadioFontColor($control, $theme, $report);
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
                    if ($this->shouldKeepTransparentFill($control, $prop, $from)) {
                        $this->ensureExplicitTransparentFill($control, $prop, $from, $report);
                        continue;
                    }
                    if ($this->shouldLeaveModernChrome($control, $prop)) {
                        continue;
                    }
                    $from = $this->recoverCommentedColorFormula($from, $prop);
                    $activeBody = trim(ltrim(trim(preg_replace('/\/\/[^\n]*/', '', $from) ?? $from), '='));
                    // Comment-only Color stubs like //FontColour → treat as unset text ink.
                    if ($activeBody === '' && strcasecmp($prop, 'Color') === 0) {
                        $to = $this->themeFormula($control, $theme, 'Text');
                        if ($to !== $from) {
                            $control->setProperty($prop, $to);
                            $report->add(self::id(), $control->path, $prop, self::preview($from), self::preview($to));
                        }
                        continue;
                    }
                    // Pure gblTheme.Token refs are done; mixed formulas may still embed RGBA literals.
                    if ($this->isPureThemeReference($from, $theme)) {
                        continue;
                    }
                    if ($this->usesActiveThemeToken($from, $theme) && preg_match('/RGBA\s*\(|\bColor\.[A-Za-z]+\b/i', $from)) {
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
                    // Stepper / selection Ifs must keep App.ActiveScreen — theme the literals inside.
                    if ($this->isAppStateColorFormula($from)) {
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

                    $to = $this->themePropertyFormula($control, $theme, $var, $token, $from, $prop);
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
                $prop = $this->propertyFromPath($path);
                if ($this->isAppStateColorFormula($formula)) {
                    $embedded = $this->rewriteEmbeddedRgba($formula, $prop, $theme, $var, true);
                    if ($embedded !== $formula) {
                        $report->add(self::id(), $path, 'formula', 'App.ActiveScreen If', self::preview($embedded));
                    }

                    return $embedded;
                }
                if ($this->usesActiveThemeToken($formula, $theme)) {
                    return $formula;
                }
                if ($this->shouldPreserveUserValue($formula, $prop, $force, $theme, $var, $themeLight, $themeDark)) {
                    return $formula;
                }
                $trim = trim(ltrim(trim($formula), '='));
                $parsed = ColorValue::parse($trim);
                if ($parsed !== null && !ColorValue::isTransparent($parsed)
                    && $this->isInkHostFillPath($path)
                    && ColorValue::isStudioDefault($trim, $this->propertyFromPath($path))
                ) {
                    return $formula;
                }
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
                $prop = $this->propertyFromPath($path);
                if ($this->isAppStateColorFormula($formula)) {
                    $embedded = $this->rewriteEmbeddedRgba($formula, $prop, $theme, $var, true);
                    if ($embedded !== $formula) {
                        $report->add(self::id(), $path, 'formula', 'App.ActiveScreen If', self::preview($embedded));
                    }

                    return $embedded;
                }
                if ($this->usesActiveThemeToken($formula, $theme)) {
                    return $formula;
                }
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
                    // Don't smash If(App.ActiveScreen, …, DefaultGray, …) into a single token.
                    if ($this->isAppStateColorFormula($from) || preg_match('/If\s*\(/i', $from) === 1) {
                        $legacyToken = $this->isAppChromeSurface($control) ? 'Page' : 'Surface';
                        $to = preg_replace(
                            '/\b' . preg_quote(self::LEGACY_SURFACE, '/') . '\b/',
                            $theme . '.' . $legacyToken,
                            $from
                        ) ?? $from;
                    } else {
                        $legacyToken = $this->isAppChromeSurface($control) ? 'Page' : 'Surface';
                        $to = $this->themePropertyFormula($control, $theme, $var, $legacyToken, $from, $prop);
                    }
                    if ($to === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from, $to);
                }
            }
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (self::COLOR_PROPERTIES as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || !str_contains($from, self::LEGACY_BORDER)) {
                        continue;
                    }
                    if ($this->isAppStateColorFormula($from) || preg_match('/If\s*\(/i', $from) === 1) {
                        $to = preg_replace(
                            '/\b' . preg_quote(self::LEGACY_BORDER, '/') . '\b/',
                            $theme . '.Border',
                            $from
                        ) ?? $from;
                    } else {
                        $to = $this->themeFormula($control, $theme, 'Border');
                    }
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
        // Pass 5c: ColorFade(..., +N%) status chips stay pastel in dark mode — flip fade when dark.
        $this->themePastelColorFadeFills($documents, $var, $report);

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
                $this->ensureDisabledInputChrome($control, $theme, $var, $report);
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

        // Pass 6e: DatePicker + DataTable auxiliary color chrome (trip dates, rate tables, …)
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

        // Pass 6d: containers that host rich-text editors — Surface so RTE chrome contrasts.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isContainer() || !$this->containsRichTextDescendant($control)) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                $alreadySurface = is_string($from) && $this->usesActiveThemeToken($from, $theme)
                    && str_contains((string) $from, 'Surface');
                if ($alreadySurface) {
                    continue;
                }
                // Upgrade Page (from earlier container pass) or unset/literal fills to Surface.
                if ($from !== null && trim($from) !== '' && $this->alreadyThemed($from, $var, $theme)
                    && !str_contains((string) $from, 'Page')) {
                    continue;
                }
                if ($from !== null && trim($from) !== '' && !$this->alreadyThemed($from, $var, $theme)) {
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

        // Pass 7: hyperlinks + embedded HTML section chrome (THCEE translation HTML bodies)
        // + dark SVG icon fills (#333/#000 → TextCss) in Image / gallery Items formulas
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($theme, $report): string {
                $rewritten = $formula;
                if ($this->isTranslationHtmlBinding($formula)) {
                    $next = $this->wrapTranslationHtmlBinding($rewritten, $theme);
                    if ($next !== $rewritten) {
                        $report->add(self::id(), $path, 'HtmlText', 'Labels.*Html', $theme . '.*Css');
                        $rewritten = $next;
                    }
                } elseif ($this->shouldThemeInAppHtml($formula, $path)) {
                    $next = $this->rewriteInlineLinkHtml($rewritten, $theme);
                    if ($next !== $rewritten) {
                        $report->add(self::id(), $path, 'HtmlText', 'color: blue', $theme . '.LinkCss');
                        $rewritten = $next;
                    }
                    $next = $this->rewriteEmbeddedHtmlSurfaces($rewritten, $theme);
                    if ($next !== $rewritten) {
                        $report->add(self::id(), $path, 'HtmlTheme', 'light HTML chrome', $theme . '.*Css');
                        $rewritten = $next;
                    }
                }
                if ($this->looksLikeEmbeddedSvg($rewritten)) {
                    $next = $this->rewriteEmbeddedSvgInk($rewritten, $theme);
                    if ($next !== $rewritten) {
                        $report->add(self::id(), $path, 'SvgInk', 'dark SVG fill', $theme . '.TextCss');
                        $rewritten = $next;
                    }
                }

                return $rewritten;
            });

            foreach ($doc->controls() as $control) {
                foreach (['HtmlText', 'OnReset', 'OnStart'] as $htmlProp) {
                    $html = $control->getProperty($htmlProp);
                    if (!is_string($html) || $html === '') {
                        continue;
                    }
                    if ($htmlProp === 'HtmlText' && $this->isTranslationHtmlBinding($html)) {
                        $rewritten = $this->wrapTranslationHtmlBinding($html, $theme);
                    } elseif ($this->shouldThemeInAppHtml($html, $control->path . '.' . $htmlProp)) {
                        $rewritten = $this->rewriteInlineLinkHtml($html, $theme);
                        $rewritten = $this->rewriteEmbeddedHtmlSurfaces($rewritten, $theme);
                    } else {
                        continue;
                    }
                    if ($rewritten !== $html) {
                        $control->setProperty($htmlProp, $rewritten);
                        $report->add(self::id(), $control->path, $htmlProp, self::preview($html), self::preview($rewritten));
                    }
                }
                $image = $control->getProperty('Image');
                if (is_string($image) && $image !== '' && $this->looksLikeEmbeddedSvg($image)) {
                    $rewritten = $this->rewriteEmbeddedSvgInk($image, $theme);
                    if ($rewritten !== $image) {
                        $control->setProperty('Image', $rewritten);
                        $report->add(self::id(), $control->path, 'Image', self::preview($image), self::preview($rewritten));
                    }
                }
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

        // Pass 8: buttons with themed fills but missing/black labels; radios already handled above.
        $this->ensureButtonLabelInk($documents, $theme, $report);
        // Pass 8a: classic / modern icons with unset or near-black Color → Text
        $this->ensureIconInk($documents, $theme, $report);
        // Pass 8b: pale Modern button BasePaletteColor (Return Home taupe → Surface)
        $this->themePaleModernButtonPalettes($documents, $theme, $var, $themeLight, $themeDark, $force, $report);
        // Pass 8c: keep blank-text hit-target buttons transparent (sidebar overlays)
        $this->ensureTransparentHitTargetFills($documents, $report);
        // Pass 9: TextOnLight on dark/page surfaces (status badges, legends) → Text or keep semantic tokens from pass 2.
        $this->rewriteDarkSurfaceInk($documents, $theme, $report);
        // Pass 10: bare gblTheme.Page on children paints black in Studio / light mode.
        $this->unwrapBareSurfaceFills($documents, $theme, $var, $report);
        // Pass 11: borders + hovers so controls stay distinct on dark surfaces.
        $this->ensureInteractiveChrome($documents, $theme, $var, $report);
        // HTML in component OnReset (comTranslations.Labels.*) is spliced in pass 7 —
        // AccessAppScope must run after those gblTheme.*Css references exist.
        $this->ensureThemeComponentAppScope($documents, $report);
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
                && in_array($token, ['Page', 'Surface', 'SurfaceMuted', 'Text', 'TextMuted', 'Border', 'Success', 'Warning', 'Danger'], true)
            ) {
                $dark = ColorValue::defaultDarkForToken($token);
            }
            if (in_array($token, ['Success', 'Warning', 'Danger'], true)
                && ColorValue::luminance($dark) > 0.42
            ) {
                $dark = ColorValue::defaultDarkForToken($token);
            }
            if (in_array($token, ['Border', 'BorderStrong'], true)
                && ColorValue::luminance($dark) < 0.35
            ) {
                $dark = ColorValue::defaultDarkForToken($token);
            }
            $darkFields[] = $token . ': ' . ColorValue::formatRgba($dark);
        }

        if (isset($palette['Link'])) {
            $lightFields[] = 'LinkCss: "' . ColorValue::toHex($palette['Link']['light']) . '"';
            $darkFields[] = 'LinkCss: "' . ColorValue::toHex($palette['Link']['dark']) . '"';
        }
        if (isset($palette['Text'])) {
            $lightFields[] = 'TextCss: "' . ColorValue::toHex($palette['Text']['light']) . '"';
            $darkFields[] = 'TextCss: "' . ColorValue::toHex($palette['Text']['dark']) . '"';
        }
        if (isset($palette['TextMuted'])) {
            $lightFields[] = 'TextMutedCss: "' . ColorValue::toHex($palette['TextMuted']['light']) . '"';
            $darkFields[] = 'TextMutedCss: "' . ColorValue::toHex($palette['TextMuted']['dark']) . '"';
        }
        if (isset($palette['Page'])) {
            $lightFields[] = 'PageCss: "' . ColorValue::toHex($palette['Page']['light']) . '"';
            $darkFields[] = 'PageCss: "' . ColorValue::toHex($palette['Page']['dark']) . '"';
        }
        if (isset($palette['Surface'])) {
            $lightFields[] = 'SurfaceCss: "' . ColorValue::toHex($palette['Surface']['light']) . '"';
            $darkFields[] = 'SurfaceCss: "' . ColorValue::toHex($palette['Surface']['dark']) . '"';
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
        // Replace empty/false stubs and any OnChange that does not yet swap the dark palette.
        if (
            $onChangeTrim === ''
            || strtolower($onChangeTrim) === 'false'
            || !str_contains($beforeOnChange, $themeDark)
        ) {
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

        // Help makers recognize the control when it was a generic RadioGroupCanvas stub.
        $beforeA11y = (string) ($radio->getProperty('AccessibleLabel') ?? '');
        if (ControlNaming::isBlank($beforeA11y) || strtolower(trim(ltrim($beforeA11y, '='))) === '""') {
            $a11yTo = $radio->format === 'yaml' ? '="Theme"' : '"Theme"';
            $radio->setProperty('AccessibleLabel', $a11yTo);
            $report->add(self::id(), $radio->path, 'AccessibleLabel', $beforeA11y !== '' ? $beforeA11y : '(unset)', $a11yTo);
        }

        $this->ensureRadioFontColor($radio, $theme, $report);
    }

    private function isRadioControl(ControlNode $control): bool
    {
        return str_contains(strtolower($control->type), 'radio');
    }

    private function ensureRadioFontColor(ControlNode $radio, string $theme, Report $report): void
    {
        // Modern RadioGroupCanvas item labels follow FontColor.
        $before = (string) ($radio->getProperty('FontColor') ?? '');
        $trim = trim(ltrim($before, '='));
        $parsed = $trim !== '' && $trim !== '""' ? ColorValue::parse($before) : null;
        $needs = $trim === '' || $trim === '""'
            || ($parsed !== null && !$this->usesActiveThemeToken($before, $theme) && ColorValue::luminance($parsed) < 0.45);
        if (!$needs) {
            return;
        }
        $to = $this->themeFormula($radio, $theme, 'Text');
        if ($to === $before) {
            return;
        }
        $radio->setProperty('FontColor', $to);
        $report->add(self::id(), $radio->path, 'FontColor', $before !== '' ? $before : '(unset)', $to);
    }

    /**
     * Modern ButtonCanvas / classic buttons often keep Studio's default black label when
     * only Fill/BasePaletteColor was themed (TDR Settings Annuler / Enregistrer).
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureButtonLabelInk(array $documents, string $theme, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control)) {
                    continue;
                }
                $fill = (string) ($control->getProperty('Fill') ?? $control->getProperty('BasePaletteColor') ?? '');
                // Prefer Color; also set FontColor when the modern control exposes it.
                foreach (['Color', 'FontColor'] as $prop) {
                    if ($prop === 'FontColor' && $control->getProperty('FontColor') === null && !str_contains(strtolower($control->type), 'canvas')) {
                        continue;
                    }
                    $before = $control->getProperty($prop);
                    $beforeStr = $before !== null ? (string) $before : '';
                    $trim = trim(ltrim($beforeStr, '='));
                    $parsed = $trim !== '' ? ColorValue::parse($beforeStr) : null;
                    $missing = $before === null || $trim === '' || $trim === '""';
                    $darkLiteral = $parsed !== null && ColorValue::luminance($parsed) < 0.35 && ($parsed['a'] ?? 1) > 0.8;
                    if (!$missing && !$darkLiteral) {
                        continue;
                    }
                    $token = $this->buttonInkTokenForFill($fill, $theme);
                    $to = $this->themeFormula($control, $theme, $token);
                    if ($to === $beforeStr) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $missing ? '(unset)' : self::preview($beforeStr), $to);
                }
            }
        }
    }

    /**
     * Classic/modern icons with unset or near-black Color disappear on dark chrome (THCEE sidebar).
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureIconInk(array $documents, string $theme, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isIconControl($control)) {
                    continue;
                }
                foreach (['Color', 'IconColor', 'FontColor'] as $prop) {
                    $before = $control->getProperty($prop);
                    if ($prop !== 'Color' && $before === null) {
                        continue;
                    }
                    $beforeStr = $before !== null ? (string) $before : '';
                    $trim = trim(ltrim($beforeStr, '='));
                    $parsed = $trim !== '' ? ColorValue::parse($beforeStr) : null;
                    $missing = $before === null || $trim === '' || $trim === '""';
                    $darkLiteral = $parsed !== null && ColorValue::luminance($parsed) < 0.35 && ($parsed['a'] ?? 1) > 0.8;
                    $alreadyOk = $beforeStr !== '' && (
                        str_contains($beforeStr, $theme . '.Text')
                        || str_contains($beforeStr, $theme . '.TextOnAccent')
                        || str_contains($beforeStr, $theme . '.Accent')
                        || str_contains($beforeStr, $theme . '.Link')
                    );
                    if ($alreadyOk || (!$missing && !$darkLiteral)) {
                        continue;
                    }
                    $to = $this->themeFormula($control, $theme, 'Text');
                    if ($to === $beforeStr) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $missing ? '(unset)' : self::preview($beforeStr), $to);
                }
            }
        }
    }

    /**
     * Blank-text classic buttons that were Transparent hit-targets must stay see-through
     * so sibling icons remain visible on dark sidebars.
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureTransparentHitTargetFills(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control) || !$this->isBlankButtonText($control->getProperty('Text'))) {
                    continue;
                }
                $fill = $control->getProperty('Fill');
                $fillStr = $fill !== null ? (string) $fill : '';
                $parsed = $fillStr !== '' ? ColorValue::parse($fillStr) : null;
                $needsTransparent = $fillStr === ''
                    || ($parsed !== null && !ColorValue::isTransparent($parsed) && ColorValue::isStudioDefault($fillStr, 'Fill'))
                    || ($fillStr !== '' && preg_match('/gblTheme\.(Page|Surface|SurfaceMuted|TextMuted|Text)\b/', $fillStr) === 1
                        && $this->isBlankButtonText($control->getProperty('Text')));
                // Only force transparent when there is no meaningful label and fill is default/theme surface
                // (not Accent/Success CTAs — those have text).
                if ($fillStr !== '' && preg_match('/gblTheme\.(Accent|Success|Warning)\b/', $fillStr) === 1) {
                    continue;
                }
                if (!$needsTransparent && $parsed !== null && ColorValue::isTransparent($parsed)) {
                    continue;
                }
                if (!$needsTransparent) {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
                if ($to === $fillStr) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', $fillStr !== '' ? self::preview($fillStr) : '(unset)', $to);
            }
        }
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

    private function isButtonControl(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if (str_contains($t, 'button')) {
            return true;
        }
        $n = strtolower($control->name);
        return str_starts_with($n, 'btn') || str_contains($n, 'button');
    }

    private function isTransparentAppearance(?string $appearance): bool
    {
        return is_string($appearance) && preg_match('/Transparent/i', $appearance) === 1;
    }

    /**
     * Transparent / default fills on ink hosts and overlay containers must stay see-through.
     * Mapping them to gblTheme.Page paints an opaque box over images and loading chrome.
     */
    private function shouldKeepTransparentFill(ControlNode $control, string $prop, ?string $from): bool
    {
        if ($control->isScreen() || $control->isApp()) {
            return false;
        }
        if (!preg_match('/Fill|BackgroundColor/i', $prop)) {
            return false;
        }
        if (preg_match('/HoverFill|PressedFill|ItemHoverFill|RailHoverFill/i', $prop) === 1) {
            return false;
        }
        if ($this->shouldLeaveModernChrome($control, $prop)) {
            return true;
        }
        if ($from !== null && preg_match('/Color\.Transparent\b/i', $from) === 1) {
            return true;
        }
        if ($from !== null && trim($from) !== '') {
            $parsed = ColorValue::parse($from);
            if ($parsed !== null && ColorValue::isTransparent($parsed)) {
                return true;
            }
        }
        $inkHost = $this->isTextControl($control)
            || $this->isIconControl($control)
            || $this->isHtmlViewer($control);
        if ($inkHost && $from !== null && preg_match('/gblTheme\.(Page|Surface|SurfaceMuted|SurfaceAlt)\b/i', $from) === 1
            && preg_match('/If\s*\(/i', $from) !== 1) {
            return true;
        }
        if ($inkHost && ($from === null || trim((string) $from) === '' || ColorValue::isStudioDefault((string) $from, 'Fill'))) {
            // HtmlViewer white Fill is a section card (Activities header), not unpainted chrome.
            if ($this->isHtmlViewer($control) && $from !== null && trim((string) $from) !== '') {
                $parsedHost = ColorValue::parse((string) $from);
                if ($parsedHost !== null && !ColorValue::isTransparent($parsedHost) && ColorValue::luminance($parsedHost) > 0.85) {
                    return false;
                }
            }

            return true;
        }
        if ($control->isContainer() && $this->isOverlayContainer($control, $from)) {
            return true;
        }

        return false;
    }

    /** Transparent Fluent buttons keep their own chrome — do not invent Fill/BasePaletteColor. */
    private function shouldLeaveModernChrome(ControlNode $control, string $prop): bool
    {
        if (!$this->isTransparentAppearance($control->getProperty('Appearance'))) {
            return false;
        }

        return (bool) preg_match('/Fill|BackgroundColor|BasePaletteColor/i', $prop);
    }

    private function isOverlayContainer(ControlNode $control, ?string $from): bool
    {
        if ($from !== null && trim($from) !== '') {
            $parsed = ColorValue::parse($from);
            if ($parsed !== null && ColorValue::isTransparent($parsed)) {
                return true;
            }
        }
        $whiteOrEmpty = $from === null
            || trim($from) === ''
            || $this->isUnpaintedFill((string) $from);
        if (!$whiteOrEmpty) {
            return false;
        }

        return !$this->containerHasVisibleChrome($control);
    }

    /** True grouping default (white / empty) — not DefaultGray rails or painted mid-grays. */
    private function isUnpaintedFill(string $from): bool
    {
        $trim = trim($from);
        if ($trim === '' || $trim === '=') {
            return true;
        }
        if (preg_match('/DefaultGrayBackgroud|App\\.Theme\\.Colors\\./i', $trim) === 1) {
            return false;
        }
        $parsed = ColorValue::parse($from);
        if ($parsed !== null && ColorValue::isTransparent($parsed)) {
            return true;
        }
        if (preg_match('/^[=]?\\s*Color\\.(White|Transparent)\\b/i', $trim) === 1) {
            return true;
        }
        if ($parsed === null) {
            return false;
        }

        return ColorValue::luminance($parsed) >= 0.93 && ($parsed['a'] ?? 1.0) >= 0.85;
    }

    /** Narrow nav rails / DefaultGray chrome should follow Page, not a lighter Surface. */
    private function isAppChromeSurface(ControlNode $control): bool
    {
        $hay = strtolower($control->name . ' ' . $control->path);
        if (preg_match('/sidebar|side_bar|sidemenu|navbar|navpane|navrail|menurail|iconrail/i', $hay) === 1) {
            return true;
        }
        $fill = (string) ($control->getProperty('Fill') ?? '');

        return preg_match('/DefaultGrayBackgroud/i', $fill) === 1;
    }

    private function containerHasVisibleChrome(ControlNode $control): bool
    {
        $border = (string) ($control->getProperty('BorderThickness') ?? '');
        $borderActive = trim((string) preg_replace('/\/\/[^\n]*/', '', $border));
        if (preg_match('/[1-9]/', $borderActive) === 1) {
            return true;
        }
        $shadow = (string) ($control->getProperty('DropShadow') ?? '');
        if ($shadow !== '' && preg_match('/None|false|^\\s*0\\s*$/i', $shadow) !== 1) {
            return true;
        }

        return false;
    }

    private function isHtmlViewer(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'htmlviewer') || str_contains($t, 'htmltext');
    }

    private function isInkHostFillPath(string $path): bool
    {
        if (preg_match('/\.(Fill|HoverFill|PressedFill|DisabledFill|BackgroundColor)(\.|$)/i', $path) !== 1) {
            return false;
        }

        return preg_match('/(Label|TextCanvas|ModernText|HtmlViewer|HtmlText|Icon|ModernIcon)(?:@|\\/|$)/i', $path) === 1;
    }

    private function ensureExplicitTransparentFill(
        ControlNode $control,
        string $prop,
        string $from,
        Report $report,
    ): void {
        $parsed = ColorValue::parse($from);
        if ($parsed !== null && ColorValue::isTransparent($parsed)) {
            return;
        }
        if (preg_match('/Color\\.Transparent\\b/i', $from) === 1) {
            return;
        }
        // Unset/empty stays Studio-transparent; only rewrite painted defaults (white / Page).
        if (trim($from) === '') {
            return;
        }
        $to = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
        if ($to === $from) {
            return;
        }
        $control->setProperty($prop, $to);
        $report->add(self::id(), $control->path, $prop, self::preview($from), $to);
    }

    private function buttonInkTokenForFill(string $fill, string $theme): string
    {
        if (str_contains($fill, $theme . '.Accent') || str_contains($fill, $theme . '.Success') || str_contains($fill, $theme . '.Warning')) {
            return 'TextOnAccent';
        }
        return 'Text';
    }

    /**
     * Replace leftover TextOnLight ink when the control (or its fill) is a dark surface token.
     *
     * @param list<ControlDocument> $documents
     */
    private function rewriteDarkSurfaceInk(array $documents, string $theme, Report $report): void
    {
        /** @var array<string, string> $fillByPath */
        $fillByPath = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $fillByPath[$control->path] = (string) ($control->getProperty('Fill') ?? '');
            }
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($theme, $report): string {
                if (!$this->isColorPropertyPath($path) && !preg_match('/\.(Color|FontColor)(\.|$)/i', $path)) {
                    return $formula;
                }
                if (!str_contains($formula, $theme . '.TextOnLight')) {
                    return $formula;
                }
                // Status/legend Switch() formulas: TextOnLight on Surface* is unreadable in dark mode.
                if (preg_match('/Switch\s*\(/i', $formula) || str_contains($formula, $theme . '.Surface') || str_contains($formula, $theme . '.Page')) {
                    $replaced = str_replace($theme . '.TextOnLight', $theme . '.Text', $formula);
                    if ($replaced !== $formula) {
                        $report->add(self::id(), $path, 'Color', $theme . '.TextOnLight', $theme . '.Text');
                    }
                    return $replaced;
                }
                return $formula;
            });

            foreach ($doc->controls() as $control) {
                foreach (['Color', 'FontColor'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null || !str_contains($from, $theme . '.TextOnLight')) {
                        continue;
                    }
                    if (preg_match('/Switch\s*\(/i', $from)) {
                        $to = str_replace($theme . '.TextOnLight', $theme . '.Text', $from);
                        if ($to !== $from) {
                            $control->setProperty($prop, $to);
                            $report->add(self::id(), $control->path, $prop, self::preview($from), self::preview($to));
                        }
                        continue;
                    }
                    // Pastel ColorFade chips need TextOnLight even when the label fill is transparent.
                    $ownFill = (string) ($control->getProperty('Fill') ?? '');
                    if ($this->isPastelChipFill($ownFill)) {
                        continue;
                    }
                    foreach ($this->ancestorFills($control->path, $fillByPath) as $parentFill) {
                        if ($this->isPastelChipFill($parentFill)) {
                            continue 2;
                        }
                    }
                    $fill = trim($ownFill);
                    $fillBody = trim(ltrim(preg_replace('/\/\/[^\n]*/', '', $fill) ?? $fill, '='));
                    $parsedFill = $fillBody !== '' ? ColorValue::parse($fillBody) : null;
                    $transparent = $fillBody === '' || ($parsedFill !== null && ColorValue::isTransparent($parsedFill));
                    $onDarkSurface = $transparent
                        || str_contains($fill, $theme . '.Page')
                        || str_contains($fill, $theme . '.Surface');
                    if (!$onDarkSurface) {
                        continue;
                    }
                    $to = str_replace($theme . '.TextOnLight', $theme . '.Text', $from);
                    if ($to === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, self::preview($from), self::preview($to));
                }
            }
        }
    }

    /**
     * Status chips use ColorFade(BorderColor, 80%) — stays light yellow in dark mode.
     * Flip positive fades to a darken percentage when gblDarkMode is on.
     *
     * @param list<ControlDocument> $documents
     */
    private function themePastelColorFadeFills(array $documents, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $from = $control->getProperty('Fill');
                if ($from === null || !$this->isPastelChipFill($from)) {
                    continue;
                }
                if (str_contains($from, $var) && preg_match('/gblTheme\.(Warning|Success|Danger)\b/i', $from) === 1) {
                    continue;
                }
                $token = $this->pastelChipDarkToken($control, $from);
                $light = trim(ltrim(trim($from), '='));
                $body = 'If(' . $var . ', ' . 'gblTheme.' . $token . ', ' . $light . ')';
                $to = $control->format === 'yaml' ? '=' . $body : $body;
                if ($to === $from) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', self::preview($from), self::preview($to));
            }
        }
    }

    /**
     * Modern buttons with pale taupe/grey BasePaletteColor read as white chips in dark mode.
     *
     * @param list<ControlDocument> $documents
     */
    private function themePaleModernButtonPalettes(
        array $documents,
        string $theme,
        string $var,
        string $themeLight,
        string $themeDark,
        bool $force,
        Report $report,
    ): void {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $t = strtolower($control->type);
                if (!str_contains($t, 'button') && !str_contains($t, 'modernbutton')) {
                    continue;
                }
                if ($this->isTransparentAppearance($control->getProperty('Appearance'))) {
                    continue;
                }
                $from = $control->getProperty('BasePaletteColor');
                if ($from === null || trim($from) === '') {
                    continue;
                }
                // Earlier passes sometimes map taupe chrome to TextMuted (foreground token) —
                // Fluent then paints a washed-out light chip. Force Surface for those.
                if (
                    str_contains($from, $theme . '.TextMuted')
                    || preg_match('/' . preg_quote($theme, '/') . '\.Text\b/', $from) === 1
                ) {
                    $to = $this->themeFormula($control, $theme, 'Surface');
                    if ($to !== $from) {
                        $control->setProperty('BasePaletteColor', $to, 'Design');
                        $report->add(self::id(), $control->path, 'BasePaletteColor', $from, $to);
                    }
                    continue;
                }
                if ($this->alreadyThemed($from, $var, $theme)) {
                    continue;
                }
                if ($this->shouldPreserveUserValue($from, 'BasePaletteColor', $force, $theme, $var, $themeLight, $themeDark)) {
                    $parsed = ColorValue::parse($from);
                    // Still rewrite washed-out greys/taupes even in missing-only.
                    if ($parsed === null || ColorValue::isTransparent($parsed)) {
                        continue;
                    }
                    $lum = ColorValue::luminance($parsed);
                    $sat = ColorValue::saturation($parsed);
                    if (!($lum >= 0.40 && $sat <= 0.28)) {
                        continue;
                    }
                } else {
                    $parsed = ColorValue::parse($from);
                    if ($parsed === null || ColorValue::isTransparent($parsed)) {
                        continue;
                    }
                    $lum = ColorValue::luminance($parsed);
                    $sat = ColorValue::saturation($parsed);
                    if (!($lum >= 0.40 && $sat <= 0.35) && !$force) {
                        continue;
                    }
                }
                $orig = $this->lightFallbackLiteral($from, 'Surface');
                $body = 'If(' . $var . ', ' . $theme . '.Surface, ' . $orig . ')';
                $to = $control->format === 'yaml' ? '=' . $body : $body;
                if ($to === $from) {
                    continue;
                }
                $control->setProperty('BasePaletteColor', $to, 'Design');
                $report->add(self::id(), $control->path, 'BasePaletteColor', $from, $to);
            }
        }
    }

    private function looksLikeEmbeddedSvg(string $formula): bool
    {
        return str_contains($formula, 'svg+xml')
            || (bool) preg_match('/<\s*svg\b/i', $formula)
            || (bool) preg_match('/fill\s*=\s*[\'"]?#(?:0{3,6}|1{3,6}|222(?:222)?|333(?:333)?|1f1d1a)\b/i', $formula);
    }

    /**
     * Homepage / gallery SVG icons hard-code fill='#333333' (invisible on dark cards).
     * Splice gblTheme.TextCss like HTML chrome does for section headers.
     */
    private function rewriteEmbeddedSvgInk(string $formula, string $theme): string
    {
        if (!$this->looksLikeEmbeddedSvg($formula) || str_contains($formula, $theme . '.TextCss')
            || $this->isUnsafeHtmlConcatHost($formula)
        ) {
            return $formula;
        }

        $next = preg_replace_callback(
            '/fill\s*=\s*(["\'])#(?:000000|000|111111|1a1a1a|1f1d1a|222222|333333|3f3f3f)\1/i',
            static function (array $m) use ($theme): string {
                $q = $m[1];
                // Break out of the surrounding Power Fx string: fill='" & gblTheme.TextCss & "'
                return 'fill=' . $q . '" & ' . $theme . '.TextCss & "' . $q;
            },
            $formula
        );

        return is_string($next) ? $next : $formula;
    }

    private function looksLikeEmbeddedHtml(string $formula): bool
    {
        return (bool) preg_match('/<\s*(?:!doctype|html|body|div|span|p|table|h[1-6])\b/i', $formula)
            || str_contains($formula, 'tdr-section-header')
            || (bool) preg_match('/background(?:-color)?\s*:\s*(?:#|rgb|white)/i', $formula);
    }

    /**
     * Outlook / $"" email HTML must not be spliced — " & gblTheme.X & " closes the
     * interpolated string and floods Email_SendReminder with Expected operator errors.
     */
    private function isUnsafeHtmlConcatHost(string $formula): bool
    {
        if (preg_match('/Office365Outlook|SendEmailV2?\b|SendEmail\s*\(/i', $formula) === 1) {
            return true;
        }

        // $"<table ...> email bodies — not "Exceeds DoA $" (dollar + closing quote).
        return preg_match('/\$\s*"<\s*table\b/i', $formula) === 1;
    }

    /** HtmlText that only points at translation Labels.*Html — colors live in OnReset. */
    private function isTranslationHtmlBinding(string $formula): bool
    {
        return preg_match('/\.Labels\.[A-Za-z_][\w]*\.[A-Za-z_]*Html[\w]*/i', $formula) === 1;
    }

    /**
     * Theme Labels HTML at display time so section cards follow gblTheme without
     * baking hex in OnReset (OnReset does not re-run when dark mode toggles).
     * Only CSS backgrounds / dark ink — never color:#ffffff (badge text).
     */
    private function wrapTranslationHtmlBinding(string $formula, string $theme): string
    {
        if (str_contains($formula, 'Substitute(') && str_contains($formula, $theme . '.SurfaceCss')) {
            return $formula;
        }

        $eq = str_starts_with(trim($formula), '=');
        $body = ltrim(trim($formula), '=');
        [$head, $tail] = $this->splitFormulaLead($body);
        if (!$this->isSimpleLabelsHtmlExpr($head) || $this->isUnsafeHtmlConcatHost($head)) {
            return $formula;
        }

        $expr = $head;
        foreach (array_reverse($this->translationHtmlSubstitutePairs()) as [$needle, $token]) {
            $prop = preg_match('/^(background(?:-color)?|color)\s*:/i', $needle, $m) === 1
                ? strtolower($m[1]) . ':'
                : '';
            $expr = 'Substitute(' . $expr . ', "' . $needle . '", "' . $prop . '" & ' . $theme . '.' . $token . ')';
        }
        if ($tail !== '') {
            $expr .= (str_starts_with($tail, "\n") ? '' : "\n") . $tail;
        }

        return $eq ? '=' . $expr : $expr;
    }

    /** @return array{0:string,1:string} head (code+strings) and trailing comments */
    private function splitFormulaLead(string $body): array
    {
        $head = '';
        $tail = '';
        $inTail = false;
        foreach (PowerFxFormulaSegments::split($body) as [$type, $text]) {
            if (!$inTail && $type === 'comment') {
                $inTail = true;
            }
            if ($inTail) {
                $tail .= $text;
            } else {
                $head .= $text;
            }
        }

        return [trim($head), $tail];
    }

    private function isSimpleLabelsHtmlExpr(string $head): bool
    {
        if ($head === '' || !$this->isTranslationHtmlBinding($head)) {
            return false;
        }
        $stripped = preg_replace('/"(?:[^"]|"")*"/s', '', $head) ?? $head;
        $stripped = preg_replace("/'(?:[^']|'')*'/", '', $stripped) ?? $stripped;
        $stripped = trim((string) preg_replace('/\s+/', '', $stripped));

        return preg_match('/^[A-Za-z_][\w.]*&*$/', $stripped) === 1;
    }

    /** @return list<array{0:string,1:string}> */
    private function translationHtmlSubstitutePairs(): array
    {
        $pairs = [];
        foreach (['#ffffff', '#FFFFFF'] as $hex) {
            foreach (['background: ', 'background:', 'background-color: ', 'background-color:'] as $prop) {
                $pairs[] = [$prop . $hex, 'SurfaceCss'];
            }
        }
        foreach (['#f7f6f3', '#F7F6F3'] as $hex) {
            foreach (['background: ', 'background:'] as $prop) {
                $pairs[] = [$prop . $hex, 'PageCss'];
            }
        }
        foreach (['#1f1d1a', '#1F1D1A', '#0F172A', '#0f172a'] as $hex) {
            foreach (['color: ', 'color:'] as $prop) {
                $pairs[] = [$prop . $hex, 'TextCss'];
            }
        }
        foreach (['#6b6760', '#6B6760'] as $hex) {
            foreach (['color: ', 'color:'] as $prop) {
                $pairs[] = [$prop . $hex, 'TextMutedCss'];
            }
        }

        return $pairs;
    }

    /** In-app section chrome (translation Labels / HtmlText), not outbound mail. */
    private function shouldThemeInAppHtml(string $formula, string $path = ''): bool
    {
        if ($this->isUnsafeHtmlConcatHost($formula)) {
            return false;
        }
        if (preg_match('/htmltext/i', $path) === 1) {
            return $this->looksLikeEmbeddedHtml($formula);
        }
        if (preg_match('/tdr-section-header|tdr-section-card|varTranslations/i', $formula) === 1) {
            return true;
        }

        return (bool) preg_match('/<\s*(?:!doctype|html|body)\b/i', $formula)
            && preg_match('/<\s*table\b[^>]*(?:role|bgcolor|cellpadding)/i', $formula) !== 1;
    }

    /**
     * Splice gblTheme.*Css into HTML string literals (VCR / THCEE section headers).
     * Works inside both "..." and '...' Power Fx strings so light/dark follow the palette.
     */
    private function rewriteEmbeddedHtmlSurfaces(string $formula, string $theme): string
    {
        if (!$this->looksLikeEmbeddedHtml($formula) || $this->isUnsafeHtmlConcatHost($formula)) {
            return $formula;
        }
        if (str_contains($formula, $theme . '.PageCss')
            && str_contains($formula, $theme . '.TextCss')
            && str_contains($formula, $theme . '.TextMutedCss')
        ) {
            return $formula;
        }

        $spliced = $this->mapCssInQuotedChunks($formula, function (string $chunk, string $q) use ($theme): string {
            if (!preg_match('/<\s*(?:!doctype|html|body|div|span|p|table|h[1-6])\b|tdr-section-header|background|color\s*:/i', $chunk)) {
                return $chunk;
            }
            $next = $this->rewriteHtmlColorDeclarations($chunk, $q, $theme);

            return $next;
        });

        return $spliced;
    }

    /** Light HTML backgrounds / dark ink → gblTheme.*Css by luminance, not a hex whitelist. */
    private function rewriteHtmlColorDeclarations(string $chunk, string $q, string $theme): string
    {
        $replaced = preg_replace_callback(
            '/(background(?:-color)?|color)\s*:\s*(#[0-9a-f]{3,8}|rgb[a]?\([^)]+\)|white|black|whitesmoke|ghostwhite|snow)/i',
            function (array $m) use ($q, $theme): string {
                $prop = strtolower($m[1]);
                $raw = $m[2];
                $parsed = $this->parseCssColor($raw);
                if ($parsed === null) {
                    return $m[0];
                }
                $lum = ColorValue::luminance($parsed);
                if (str_starts_with($prop, 'background')) {
                    if ($lum < 0.72) {
                        return $m[0];
                    }
                    // Pure white cards → Surface; cream page slabs → Page.
                    $token = $lum >= 0.98 ? 'SurfaceCss' : 'PageCss';

                    return 'background:' . $q . ' & ' . $theme . '.' . $token . ' & ' . $q;
                }
                if ($lum > 0.62) {
                    return $m[0];
                }
                $token = $lum > 0.10 ? 'TextMutedCss' : 'TextCss';

                return 'color:' . $q . ' & ' . $theme . '.' . $token . ' & ' . $q;
            },
            $chunk
        );

        return is_string($replaced) ? $replaced : $chunk;
    }

    /** @return array{r:int,g:int,b:int,a:float}|null */
    private function parseCssColor(string $raw): ?array
    {
        $trim = strtolower(trim($raw));
        if (in_array($trim, ['white', 'whitesmoke', 'ghostwhite', 'snow'], true)) {
            return ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0];
        }
        if ($trim === 'black') {
            return ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 1.0];
        }
        if (preg_match('/^#([0-9a-f]{3,8})$/i', $trim, $m) === 1) {
            $hex = $m[1];
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) < 6) {
                return null;
            }

            return [
                'r' => hexdec(substr($hex, 0, 2)),
                'g' => hexdec(substr($hex, 2, 2)),
                'b' => hexdec(substr($hex, 4, 2)),
                'a' => 1.0,
            ];
        }
        $fromRgba = ColorValue::parse($raw);
        if ($fromRgba !== null) {
            return $fromRgba;
        }

        return null;
    }

    /**
     * @param callable(string, string): string $rewriter chunk + quote char
     */
    private function mapCssInQuotedChunks(string $formula, callable $rewriter): string
    {
        $out = preg_replace_callback(
            '/(")([^"]*?)(")/s',
            static function (array $m) use ($rewriter): string {
                $next = $rewriter($m[2], '"');

                return $next === $m[2] ? $m[0] : '"' . $next . '"';
            },
            $formula
        );
        $formula = is_string($out) ? $out : $formula;

        $out = preg_replace_callback(
            '/(\')([^\']*?)(\')/s',
            static function (array $m) use ($rewriter): string {
                $chunk = $m[2];
                if (!preg_match('/background|color\s*:|<\s*(?:div|body|span)/i', $chunk)) {
                    return $m[0];
                }
                $next = $rewriter($chunk, "'");

                return $next === $chunk ? $m[0] : "'" . $next . "'";
            },
            $formula
        );

        return is_string($out) ? $out : $formula;
    }

    /** @param list<ControlDocument> $documents */
    private function ensureThemeComponentAppScope(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isComponentDefinitionRoot($doc, $control)) {
                    continue;
                }
                if (!$this->componentReferencesThemeGlobals($control)) {
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

    private function isComponentDefinitionRoot(ControlDocument $doc, ControlNode $control): bool
    {
        if (preg_match('/(?:^|\/|\.)ComponentDefinitions(?:\/|\.)(' . preg_quote($control->name, '/') . ')$/', $control->path) === 1) {
            return true;
        }
        if (!str_starts_with(str_replace('\\', '/', $doc->relativePath), 'Src/Components/')) {
            return false;
        }
        $base = pathinfo(str_replace('\\', '/', $doc->relativePath), PATHINFO_FILENAME);

        return $control->name === $base;
    }

    private function componentReferencesThemeGlobals(ControlNode $control): bool
    {
        $stack = [$control];
        while ($stack !== []) {
            /** @var ControlNode $node */
            $node = array_pop($stack);
            foreach ($node->propertyNames() as $prop) {
                $value = (string) ($node->getProperty($prop) ?? '');
                if ($value !== '' && preg_match('/\b(?:gblTheme|gblThemeLight|gblThemeDark|gblDarkMode)\b/', $value) === 1) {
                    return true;
                }
            }
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }

        return false;
    }

    private function containsRichTextDescendant(ControlNode $control): bool
    {
        $stack = $control->children;
        while ($stack !== []) {
            /** @var ControlNode $node */
            $node = array_pop($stack);
            if ($this->isRichTextInput($node)) {
                return true;
            }
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }

        return false;
    }

    private function isThemeRadio(ControlNode $control): bool
    {
        if (!str_contains(strtolower($control->type), 'radio')) {
            return false;
        }
        $name = strtolower($control->name);
        if (str_contains($name, 'theme')) {
            return true;
        }

        $items = strtolower((string) ($control->getProperty('Items') ?? ''));
        $onChange = strtolower((string) ($control->getProperty('OnChange') ?? ''));
        $defaultSel = strtolower((string) ($control->getProperty('DefaultSelectedItems') ?? ''));
        $accessible = strtolower((string) ($control->getProperty('AccessibleLabel') ?? ''));
        $tooltip = strtolower((string) ($control->getProperty('Tooltip') ?? ''));
        $path = strtolower($control->path);
        $blob = $name . ' ' . $items . ' ' . $onChange . ' ' . $defaultSel . ' ' . $accessible . ' ' . $tooltip;

        // Language radios must never be treated as theme (THCEE/TDR/ASC Topbar).
        if (
            str_contains($items, 'english')
            || str_contains($items, 'french')
            || str_contains($blob, 'language')
            || str_contains($blob, 'langue')
            || str_contains($name, 'lang')
        ) {
            return false;
        }

        if (str_contains($items, 'light') && str_contains($items, 'dark')) {
            return true;
        }

        // VCDS TopbarHeader pattern: Items = ["Light"] only (YAML + JSON twin).
        // Matches component definitions AND Controls/Components/*.json copies.
        if ($this->isLightOnlyThemeStub($items, $defaultSel)) {
            return true;
        }

        // Incomplete theme stub with theme-oriented OnChange / settings placement.
        if (str_contains($items, 'light') && (
            str_contains($onChange, 'gbldarkmode')
            || str_contains($onChange, 'gbltheme')
            || str_contains($path, 'componentdefinitions')
            || str_contains($path, 'topbar')
            || str_contains($path, 'setting')
            || str_contains($blob, 'theme')
        )) {
            return true;
        }

        return false;
    }

    /**
     * True when Items/DefaultSelectedItems is the common Light-only theme stub
     * (["Light"]) used across VCDS TopbarHeader components before Dark is added.
     */
    private function isLightOnlyThemeStub(string $itemsLower, string $defaultSelLower): bool
    {
        $normalize = static function (string $s): string {
            $s = strtolower(trim($s));
            if (str_starts_with($s, '=')) {
                $s = trim(substr($s, 1));
            }
            // Strip /* comments */ that sometimes wrap Items.
            $s = preg_replace('#/\*.*?\*/#s', '', $s) ?? $s;
            $s = preg_replace('/\s+/', '', $s) ?? $s;

            return $s;
        };

        $items = $normalize($itemsLower);
        if ($items === '["light"]' || $items === "['light']") {
            return true;
        }

        $def = $normalize($defaultSelLower);
        if (($items === '' || $items === 'false') && ($def === '["light"]' || $def === "['light']")) {
            return true;
        }

        return false;
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
                // ColorFade chips use Warning/Success in dark mode — keep Text (light ink).
                if ($this->isPastelChipFill($ownFill)) {
                    continue;
                }
                $needsDarkInk = false;
                foreach ($this->ancestorFills($control->path, $fillByPath) as $parentFill) {
                    if ($this->isPastelChipFill($parentFill)) {
                        continue 2;
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

    /** Status chips (Draft / In-process) follow the same dark token as warning/success banners. */
    private function pastelChipDarkToken(ControlNode $control, string $from): string
    {
        $candidates = [
            (string) ($control->getProperty('BorderColor') ?? ''),
            $from,
        ];
        foreach ($candidates as $formula) {
            $parsed = ColorValue::parse($formula);
            if ($parsed === null && preg_match('/RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+/i', $formula, $m) === 1) {
                $parsed = ColorValue::parse($m[0]);
            }
            if ($parsed === null || ColorValue::isTransparent($parsed)) {
                continue;
            }
            $token = ColorValue::themeToken($parsed, 'Fill');
            if (in_array($token, ['Warning', 'Success', 'Danger', 'Accent'], true)) {
                return $token;
            }
        }

        return 'Warning';
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

    /**
     * Disabled / view-mode fields must not share InputFill — that is what makes
     * L1/L2/Submission Type look enabled after a dark-theme pass.
     */
    private function ensureDisabledInputChrome(ControlNode $control, string $theme, string $var, Report $report): void
    {
        $mode = (string) ($control->getProperty('DisplayMode') ?? '');
        $from = $control->getProperty('DisabledFill');
        $fromStr = $from !== null ? (string) $from : '';
        $canDisable = preg_match('/DisplayMode\.(Disabled|View)/i', $mode) === 1;
        $followsFill = preg_match('/Self\\.Fill/i', $fromStr) === 1;
        if (!$this->isInputControl($control) && !$canDisable && !$followsFill) {
            return;
        }
        if (!$canDisable && !$followsFill && $fromStr === '') {
            return;
        }

        if (preg_match('/' . preg_quote($theme, '/') . '\\.Disabled\\b/', $fromStr) === 1
            && preg_match('/If\s*\(\s*' . preg_quote($var, '/') . '/i', $fromStr) === 1
        ) {
            return;
        }

        $seed = $fromStr;
        if ($followsFill || $fromStr === ''
            || preg_match('/' . preg_quote($theme, '/') . '\\.(InputFill|Surface|SurfaceMuted|Page)\\b/', $fromStr) === 1
        ) {
            $seed = 'RGBA(244, 244, 244, 1)';
        }
        $to = $this->themePropertyFormula($control, $theme, $var, 'Disabled', $seed, 'DisabledFill');
        if ($to === $fromStr) {
            return;
        }
        $control->setProperty('DisabledFill', $to);
        $report->add(self::id(), $control->path, 'DisabledFill', $fromStr !== '' ? $fromStr : '(unset)', $to);

        $dc = $control->getProperty('DisabledColor');
        $dcStr = $dc !== null ? (string) $dc : '';
        if ($dcStr === '' || preg_match('/Self\\.Color/i', $dcStr) === 1) {
            $ink = $this->themeFormula($control, $theme, 'TextMuted');
            if ($ink !== $dcStr) {
                $control->setProperty('DisabledColor', $ink);
                $report->add(self::id(), $control->path, 'DisabledColor', $dcStr !== '' ? $dcStr : '(unset)', $ink);
            }
        }
    }

    private function disabledLightFallback(string $from): string
    {
        if (preg_match('/Self\\.Fill/i', $from) === 1
            || preg_match('/gblTheme\\.(InputFill|Surface|SurfaceMuted|Page)\\b/', $from) === 1
        ) {
            return 'RGBA(244, 244, 244, 1)';
        }
        $parsed = ColorValue::parse($from);
        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
            return ColorValue::formatRgba($parsed);
        }

        return 'RGBA(244, 244, 244, 1)';
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
            'DisabledFill' => 'Disabled',
        ];
        foreach ($props as $prop => $token) {
            $this->applyThemedControlProperty($control, $prop, $token, $theme, $var, $themeLight, $themeDark, $force, $report, 'Design', $prop === 'BorderColor');
        }
        $this->ensureDisabledInputChrome($control, $theme, $var, $report);
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
            'DisabledFill' => ['token' => 'Disabled', 'category' => 'Design'],
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
        $this->ensureDisabledInputChrome($control, $theme, $var, $report);
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
            'DisabledFill' => 'Disabled',
            'DisabledColor' => 'TextMuted',
        ];
        foreach ($props as $prop => $token) {
            $this->applyThemedControlProperty($control, $prop, $token, $theme, $var, $themeLight, $themeDark, $force, $report, 'Data', false);
        }
        $this->ensureDisabledInputChrome($control, $theme, $var, $report);
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

    /**
     * Child surface fills must not be bare gblTheme.Page — Studio leaves gblTheme
     * blank, so Page paints black on the default/light canvas.
     */
    private function themePropertyFormula(
        ControlNode $control,
        string $theme,
        string $var,
        string $token,
        string $from,
        string $prop,
    ): string {
        if (preg_match('/DisabledFill|DisabledItemFill|ChevronDisabledBackground/i', $prop) === 1) {
            $orig = $this->disabledLightFallback($from);
            $body = 'If(' . $var . ', ' . $theme . '.Disabled, ' . $orig . ')';

            return $control->format === 'yaml' ? '=' . $body : $body;
        }
        if ($this->isButtonControl($control) && preg_match('/^(Fill|BasePaletteColor)$/i', $prop) === 1
            && in_array($token, ['Success', 'Danger', 'Warning', 'Accent'], true)
        ) {
            $orig = $this->lightFallbackLiteral($from, $token);
            if (preg_match('/RGBA\s*\(/i', $orig) === 1) {
                $body = 'If(' . $var . ', ' . $theme . '.' . $token . ', ' . $orig . ')';

                return $control->format === 'yaml' ? '=' . $body : $body;
            }
        }
        if (preg_match('/HoverFill|ItemHoverFill|RailHoverFill|HoverDateFill/i', $prop) === 1) {
            $token = $this->hoverTokenForSurface($token);
            $orig = $this->lightFallbackLiteral($from, $token);
            $body = 'If(' . $var . ', ' . $theme . '.' . $token . ', ' . $orig . ')';

            return $control->format === 'yaml' ? '=' . $body : $body;
        }
        if (preg_match('/PressedFill|PressedItemFill/i', $prop) === 1) {
            $token = $this->pressedTokenForSurface($token);
        }
        if (!$this->isLightSurfaceToken($token) || $control->isScreen() || $control->isApp()) {
            return $this->themeFormula($control, $theme, $token);
        }
        if (!preg_match('/Fill|BackgroundColor/i', $prop)) {
            return $this->themeFormula($control, $theme, $token);
        }
        $darkToken = $token === 'Page' ? 'Surface' : $token;
        if ($this->isInputControl($control) && $this->isLightSurfaceToken($token)
            && preg_match('/DisabledFill/i', $prop) !== 1
        ) {
            $darkToken = 'InputFill';
        }
        if ($this->isAppChromeSurface($control) && $this->isLightSurfaceToken($token)) {
            $darkToken = 'Page';
        }
        $orig = $this->lightFallbackLiteral($from, $token);
        $body = 'If(' . $var . ', ' . $theme . '.' . $darkToken . ', ' . $orig . ')';

        return $control->format === 'yaml' ? '=' . $body : $body;
    }

    private function isLightSurfaceToken(string $token): bool
    {
        return in_array($token, ['Page', 'Surface', 'SurfaceMuted', 'SurfaceAlt', 'InputFill'], true);
    }

    private function hoverTokenForSurface(string $token): string
    {
        return match ($token) {
            'Page', 'Surface' => 'SurfaceMuted',
            'SurfaceMuted', 'InputFill' => 'SurfaceAlt',
            'Success' => 'SuccessHover',
            'Warning' => 'WarningHover',
            'Danger' => 'DangerHover',
            'Accent' => 'AccentHover',
            default => 'SurfaceAlt',
        };
    }

    private function pressedTokenForSurface(string $token): string
    {
        return match ($token) {
            'Success' => 'SuccessHover',
            'Warning' => 'WarningHover',
            'Danger' => 'DangerHover',
            'Accent' => 'AccentHover',
            default => 'SurfaceAlt',
        };
    }

    private function fillTokenFromFormula(?string $formula, string $theme): ?string
    {
        if ($formula === null || trim($formula) === '') {
            return null;
        }
        if (preg_match('/' . preg_quote($theme, '/') . '\\.([A-Za-z_][\\w]*)/', $formula, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function lightFallbackLiteral(string $from, string $token): string
    {
        $parsed = ColorValue::parse($from);
        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
            return ColorValue::formatRgba($parsed);
        }
        if (preg_match('/Color\.White\b/i', $from) === 1) {
            return 'RGBA(255, 255, 255, 1)';
        }
        if (preg_match('/DefaultGrayBackgroud/i', $from) === 1) {
            return 'DefaultGrayBackgroud';
        }

        return 'RGBA(255, 255, 255, 1)';
    }

    /**
     * Screen-state chips (steppers, selected tabs) are not theme pairs — leave them.
     */
    private function isAppStateColorFormula(string $from): bool
    {
        if (preg_match('/If\s*\(/i', $from) !== 1) {
            return false;
        }

        return preg_match('/App\\.ActiveScreen|ThisItem\\.|varNewSelectedRole|var[A-Z][A-Za-z0-9_]*\\s*=/i', $from) === 1;
    }

    /**
     * @param list<ControlDocument> $documents
     */
    private function unwrapBareSurfaceFills(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isScreen() || $control->isApp() || $control->name === self::TOGGLE_NAME) {
                    continue;
                }
                foreach (['Fill', 'HoverFill', 'PressedFill', 'DisabledFill', 'BackgroundColor', 'TemplateFill'] as $prop) {
                    $from = $control->getProperty($prop);
                    $inkHost = $this->isTextControl($control)
                        || $this->isIconControl($control)
                        || $this->isHtmlViewer($control);
                    if (($from === null || trim($from) === '') && $prop === 'Fill' && $inkHost
                        && preg_match('/label@|classic\\/label/i', $control->type) === 1) {
                        $to = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
                        $control->setProperty($prop, $to);
                        $report->add(self::id(), $control->path, $prop, '(unset)', $to);
                        continue;
                    }
                    if ($from === null || trim($from) === '') {
                        continue;
                    }
                    if ($this->isAppStateColorFormula($from)) {
                        continue;
                    }
                    if ($this->shouldKeepTransparentFill($control, $prop, $from)) {
                        $this->ensureExplicitTransparentFill($control, $prop, $from, $report);
                        continue;
                    }
                    $body = trim(ltrim(trim($from), '='));
                    if (preg_match('/^' . preg_quote($theme, '/') . '\\.(Page|Surface|SurfaceMuted|SurfaceAlt|InputFill)\\s*$/i', $body, $m) !== 1) {
                        continue;
                    }
                    $token = $m[1];
                    $to = $this->themePropertyFormula($control, $theme, $var, $token, $from, $prop);
                    if ($to === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, self::preview($from), self::preview($to));
                }
            }
        }
    }

    /**
     * Buttons, inputs, and bordered cards need a visible edge and a hover that is
     * not the same token as Fill — otherwise dark mode flattens every control.
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureInteractiveChrome(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isScreen() || $control->isApp() || $control->name === self::TOGGLE_NAME) {
                    continue;
                }
                if ($this->shouldLeaveModernChrome($control, 'Fill')) {
                    continue;
                }
                $fill = (string) ($control->getProperty('Fill') ?? $control->getProperty('BasePaletteColor') ?? '');
                $fillToken = $this->fillTokenFromFormula($fill, $theme);
                $wantsHover = $this->shouldThemeHoverChrome($control, $fill);
                $wantsBorder = $this->shouldThemeBorderChrome($control, $theme);

                if ($wantsBorder) {
                    $this->ensureThemedEdge($control, 'BorderColor', 'Border', $theme, $report);
                    $focus = $this->isInputControl($control) || $this->isButtonControl($control);
                    if ($focus) {
                        $this->ensureThemedEdge($control, 'FocusedBorderColor', 'Accent', $theme, $report, false);
                    }
                }

                if ($wantsHover) {
                    $hoverToken = $this->hoverTokenForSurface($fillToken ?? 'Surface');
                    $this->ensureDistinctHoverFill($control, $theme, $var, $hoverToken, $fillToken, $report);
                    $this->ensureThemedEdge($control, 'HoverBorderColor', 'BorderStrong', $theme, $report, false);
                    if ($this->isButtonControl($control)) {
                        $ink = $this->buttonInkTokenForFill($fill, $theme);
                        $hoverInk = $ink === 'TextOnAccent' ? 'TextOnAccent' : 'Text';
                        $this->ensureThemedEdge($control, 'HoverColor', $hoverInk, $theme, $report, false);
                    }
                }
            }
        }
    }

    private function shouldThemeHoverChrome(ControlNode $control, string $fill): bool
    {
        if ($this->isTransparentAppearance($control->getProperty('Appearance'))) {
            return false;
        }
        $parsed = $fill !== '' ? ColorValue::parse($fill) : null;
        if ($parsed !== null && ColorValue::isTransparent($parsed)) {
            return false;
        }
        if ($this->isButtonControl($control) && $this->isBlankButtonText($control->getProperty('Text'))) {
            return false;
        }
        if ($this->isInputControl($control) || $this->isButtonControl($control)) {
            return true;
        }

        return $control->getProperty('HoverFill') !== null;
    }

    private function shouldThemeBorderChrome(ControlNode $control, string $theme = 'gblTheme'): bool
    {
        if ($this->isInputControl($control)) {
            return true;
        }
        if ($this->isButtonControl($control) && !$this->isTransparentAppearance($control->getProperty('Appearance'))
            && !$this->isBlankButtonText($control->getProperty('Text'))) {
            $fill = (string) ($control->getProperty('Fill') ?? '');
            $parsed = $fill !== '' ? ColorValue::parse($fill) : null;
            if ($parsed !== null && ColorValue::isTransparent($parsed)) {
                return false;
            }
            $thickness = (string) ($control->getProperty('BorderThickness') ?? '');
            $hasThickness = preg_match('/[1-9]/', trim((string) preg_replace('/\/\/[^\n]*/', '', $thickness))) === 1;
            $fillToken = $this->fillTokenFromFormula($fill, $theme);
            $chromatic = in_array($fillToken, ['Success', 'Danger', 'Warning', 'Accent'], true)
                || ($parsed !== null && ColorValue::saturation($parsed) > 0.35);
            // Filled CTAs are color blocks — don't invent a Border token edge.
            if ($chromatic && !$hasThickness) {
                return false;
            }

            return true;
        }
        $thickness = (string) ($control->getProperty('BorderThickness') ?? '');
        $active = trim((string) preg_replace('/\/\/[^\n]*/', '', $thickness));

        return $control->isContainer() && preg_match('/[1-9]/', $active) === 1;
    }

    private function ensureThemedEdge(
        ControlNode $control,
        string $prop,
        string $token,
        string $theme,
        Report $report,
        bool $replaceTransparent = true,
    ): void {
        $from = $control->getProperty($prop);
        $fromStr = $from !== null ? (string) $from : '';
        if ($this->usesActiveThemeToken($fromStr, $theme)) {
            return;
        }
        if ($fromStr !== '' && preg_match('/If\s*\(\s*gblDarkMode|App\\.ActiveScreen/i', $fromStr) === 1) {
            return;
        }
        $parsed = $fromStr !== '' ? ColorValue::parse($fromStr) : null;
        $empty = $from === null || trim($fromStr) === '' || trim($fromStr) === '=';
        $transparent = $parsed !== null && ColorValue::isTransparent($parsed);
        $legacy = $fromStr !== '' && str_contains($fromStr, self::LEGACY_BORDER);
        if (!$empty && !$legacy && !($transparent && $replaceTransparent)) {
            return;
        }
        $to = $this->themeFormula($control, $theme, $token);
        if ($to === $fromStr) {
            return;
        }
        $control->setProperty($prop, $to);
        $report->add(self::id(), $control->path, $prop, $empty ? '(unset)' : self::preview($fromStr), $to);
    }

    private function ensureDistinctHoverFill(
        ControlNode $control,
        string $theme,
        string $var,
        string $hoverToken,
        ?string $fillToken,
        Report $report,
    ): void {
        $from = $control->getProperty('HoverFill');
        $fromStr = $from !== null ? (string) $from : '';
        $parsed = $fromStr !== '' ? ColorValue::parse($fromStr) : null;
        $empty = $from === null || trim($fromStr) === '' || trim($fromStr) === '=';
        $transparent = $parsed !== null && ColorValue::isTransparent($parsed);
        $sameAsFill = $fillToken !== null && $this->fillTokenFromFormula($fromStr, $theme) === $fillToken;
        $flatSurface = $this->fillTokenFromFormula($fromStr, $theme);
        $tooFlat = $flatSurface !== null && in_array($flatSurface, ['Page', 'Surface', 'InputFill'], true)
            && in_array($fillToken, [null, 'Page', 'Surface', 'InputFill', 'SurfaceMuted'], true);
        if (!$empty && !$transparent && !$sameAsFill && !$tooFlat) {
            return;
        }
        $orig = $empty || $transparent ? 'ColorFade(Self.Fill, 20%)' : $this->lightFallbackLiteral($fromStr, $hoverToken);
        $body = 'If(' . $var . ', ' . $theme . '.' . $hoverToken . ', ' . $orig . ')';
        $to = $control->format === 'yaml' ? '=' . $body : $body;
        if ($to === $fromStr) {
            return;
        }
        $control->setProperty('HoverFill', $to);
        $report->add(self::id(), $control->path, 'HoverFill', $empty ? '(unset)' : self::preview($fromStr), self::preview($to));
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
        if ($this->isUnsafeHtmlConcatHost($formula)) {
            return $formula;
        }
        if (str_contains($formula, $theme . '.LinkCss') && str_contains($formula, $theme . '.TextCss')) {
            // Still allow partial rewrites below when only one was applied.
        }
        $out = $formula;
        if (!str_contains($out, $theme . '.LinkCss') && preg_match('/color\s*:\s*blue/i', $out)) {
            $patterns = [
                '/="<span style=\'color: blue; text-decoration: underline;\'>([^<]+)<\/span>"/'
                    => '="<span style=""color:" & ' . $theme . '.LinkCss & "; text-decoration: underline;"">$1</span>"',
                '/="<span style=""color: blue; text-decoration: underline;"">([^<]+)<\/span>"/'
                    => '="<span style=""color:" & ' . $theme . '.LinkCss & "; text-decoration: underline;"">$1</span>"',
                '/"<span style=\'color: blue; text-decoration: underline;\'>([^<]+)<\/span>"/'
                    => '"<span style=""color:" & ' . $theme . '.LinkCss & "; text-decoration: underline;"">$1</span>"',
            ];
            foreach ($patterns as $pattern => $replacement) {
                $replaced = preg_replace($pattern, $replacement, $out);
                if ($replaced !== null && $replaced !== $out) {
                    $out = $replaced;
                }
            }
        }

        // PAT HtmlText field labels use hard-coded near-black ink (#111111) that vanishes on dark surfaces.
        if (!str_contains($out, $theme . '.TextCss')) {
            $replaced = preg_replace(
                '/color\s*:\s*#(?:111111|000000|0{3,6}|1a1a1a|222222|333333)\b/i',
                'color:" & ' . $theme . '.TextCss & "',
                $out
            );
            if (is_string($replaced) && $replaced !== $out) {
                // Keep formula concatenations valid inside quoted HTML strings.
                // Common shape: ="...<div style='... color:#111111; ...'>..."
                // After replace: color:" & gblTheme.TextCss & ";  which breaks unless we splice quotes.
                $out = $this->spliceCssColorConcat($formula, $theme);
            }
        }

        return $out;
    }

    /**
     * Rewrite dark hex text colors inside HtmlText formulas to gblTheme.TextCss.
     */
    private function spliceCssColorConcat(string $formula, string $theme): string
    {
        if (str_contains($formula, $theme . '.TextCss')) {
            return $formula;
        }
        $replaced = preg_replace_callback(
            '/(["\'])([^"\']*?)color\s*:\s*#(?:111111|000000|0{3,6}|1a1a1a|222222|333333)\b([^"\']*?)\1/i',
            static function (array $m) use ($theme): string {
                $q = $m[1];
                $before = $m[2];
                $after = $m[3];
                return $q . $before . 'color:' . $q . ' & ' . $theme . '.TextCss & ' . $q . $after . $q;
            },
            $formula
        );

        return is_string($replaced) ? $replaced : $formula;
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
        $stateFormula = $this->isAppStateColorFormula($formula);
        if (!$stateFormula && !$allowMixedTheme && $this->usesActiveThemeToken($formula, $theme)) {
            return $formula;
        }
        $changed = false;
        $out = \PowerSweeper\PowerFxFormulaSegments::transformCode($formula, function (string $code) use ($property, $theme, $var, $stateFormula, &$changed): string {
            $replaced = preg_replace_callback('/RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[0-9.]+)?\s*\)/i', function (array $m) use ($property, $theme, $var, $stateFormula, $code, &$changed): string {
                $parsed = ColorValue::parse($m[0]);
                if ($parsed === null || ColorValue::isTransparent($parsed)) {
                    return $m[0];
                }
                if (preg_match(
                    '/If\s*\(\s*' . preg_quote($var, '/') . '\s*,\s*' . preg_quote($theme, '/') . '\\.[A-Za-z_]+\\s*,\\s*' . preg_quote($m[0], '/') . '/i',
                    $code
                ) === 1) {
                    return $m[0];
                }
                $token = $stateFormula
                    ? $this->shadeTokenForStateLiteral($parsed, $property)
                    : $this->coreTokenForLiteral($parsed, $property);
                $changed = true;
                if ($stateFormula) {
                    return 'If(' . $var . ', ' . $theme . '.' . $token . ', ' . $m[0] . ')';
                }

                return $theme . '.' . $token;
            }, $code);
            if ($stateFormula) {
                $src = $replaced ?? $code;
                if (preg_match('/If\s*\(\s*' . preg_quote($var, '/') . '\s*,\s*' . preg_quote($theme, '/') . '\\.[A-Za-z_]+\\s*,\\s*Color\\.White/i', $src) !== 1) {
                    $src = preg_replace(
                        '/\bColor\.White\b/i',
                        'If(' . $var . ', ' . $theme . '.' . $this->shadeTokenForWhite($property) . ', Color.White)',
                        $src
                    ) ?? $src;
                }
                if (preg_match('/If\s*\(\s*' . preg_quote($var, '/') . '\s*,\s*' . preg_quote($theme, '/') . '\\.[A-Za-z_]+\\s*,\\s*' . preg_quote(self::LEGACY_SURFACE, '/') . '/i', $src) !== 1) {
                    $src = preg_replace(
                        '/\b' . preg_quote(self::LEGACY_SURFACE, '/') . '\b/',
                        'If(' . $var . ', ' . $theme . '.SurfaceMuted, ' . self::LEGACY_SURFACE . ')',
                        $src
                    ) ?? $src;
                }
                $replaced = $src;
            } else {
                $replaced = preg_replace('/\bColor\.White\b/i', $theme . '.Page', $replaced ?? $code);
            }
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

    /**
     * Shade tokens for App.ActiveScreen steppers: keep the original literal in light
     * mode and use a distinct dark surface/ink so completed pills are not white-on-black.
     *
     * @param array{r:int,g:int,b:int,a:float} $parsed
     */
    private function shadeTokenForStateLiteral(array $parsed, string $property): string
    {
        $role = ColorValue::roleForProperty($property);
        $lum = ColorValue::luminance($parsed);
        if ($role === 'foreground' || (preg_match('/Color$/i', $property) === 1 && preg_match('/Fill|Border|Background/i', $property) !== 1)) {
            if ($lum > 0.10 && $lum < 0.85) {
                return 'TextMuted';
            }

            return 'Text';
        }
        if ($lum >= 0.92) {
            return 'Surface';
        }
        if ($lum >= 0.70) {
            return 'SurfaceMuted';
        }
        // Active stepper slate (79,90,94) — lifted Selected, not the same grey as completed.
        if ($lum < 0.25) {
            return 'Selected';
        }

        return 'SurfaceAlt';
    }

    private function shadeTokenForWhite(string $property): string
    {
        $role = ColorValue::roleForProperty($property);
        if ($role === 'foreground' || (preg_match('/Color$/i', $property) === 1 && preg_match('/Fill|Border|Background/i', $property) !== 1)) {
            return 'Text';
        }

        return 'Surface';
    }

    private function rewriteColorEnums(string $formula, string $theme): string
    {
        // Do not rewrite Color.* tokens that only appear inside // comments (PAT keeps history there).
        $chunks = preg_split('/(\/\/[^\n]*)/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($chunks === false) {
            $chunks = [$formula];
        }
        $out = '';
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '//')) {
                $out .= $chunk;
                continue;
            }
            foreach (self::COLOR_ENUM_MAP as $enum => $token) {
                $replaced = preg_replace('/\b' . preg_quote($enum, '/') . '\b/i', $theme . '.' . $token, $chunk);
                if (is_string($replaced)) {
                    $chunk = $replaced;
                }
            }
            $legacyTheme = [
                'App.Theme.Colors.Primary' => $theme . '.Accent',
                'App.Theme.Colors.Lighter70' => $theme . '.SurfaceMuted',
                'App.Theme.Colors.Lighter30' => $theme . '.SurfaceMuted',
                'App.Theme.Colors.Darker70' => $theme . '.Accent',
                'App.Theme.Colors.Darker30' => $theme . '.Accent',
                'DefaultBorderColor' => $theme . '.Border',
            ];
            foreach ($legacyTheme as $from => $to) {
                if (str_contains($chunk, $from)) {
                    $chunk = str_replace($from, $to, $chunk);
                }
            }
            $out .= $chunk;
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
            // Colored legend/status ink must stay chromatic on dark pages — not TextOnLight (dark slate).
            $token === 'WarningText' => 'Warning',
            $token === 'SuccessText' => 'Success',
            $token === 'DangerText' => 'Danger',
            preg_match('/^(Accent|Success|Warning|Danger)(Hover|Pressed)$/', $token) === 1 => $token,
            str_contains($token, 'OnLight') => 'TextOnLight',
            str_contains($token, 'Text') || str_ends_with($token, 'Text') => 'Text',
            str_contains($token, 'Border') || $token === 'Focus' => str_contains($token, 'Strong') ? 'BorderStrong' : 'Border',
            str_contains($token, 'Success') => 'Success',
            str_contains($token, 'Warning') => 'Warning',
            str_contains($token, 'Danger') => 'Danger',
            str_contains($token, 'Accent') || str_contains($token, 'Selection') || str_contains($token, 'Highlight')
                || str_contains($token, 'Checkmark') || str_contains($token, 'Progress') => 'Accent',
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
        if ($this->isAppStateColorFormula($from)) {
            return true;
        }
        if ($this->isMutedHierarchyInk($from, $prop)) {
            return false;
        }
        if ($force || trim($from) === '') {
            return false;
        }
        if ($this->usesActiveThemeToken($from, $theme)) {
            return true;
        }
        if (str_contains($from, self::LEGACY_SURFACE) || str_contains($from, self::LEGACY_BORDER)) {
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
        if ($this->isBrandChromeColor($from, $prop)) {
            return false;
        }

        return !ColorValue::isStudioDefault($from, $prop);
    }

    /** Mid-grey labels ("Step 3 of 4", field captions) must become TextMuted, not stay #757575. */
    private function isMutedHierarchyInk(string $from, string $prop): bool
    {
        if (preg_match('/^(Color|FontColor)$/i', $prop) !== 1) {
            return false;
        }
        $parsed = ColorValue::parse($from);
        if ($parsed === null || ColorValue::isTransparent($parsed)) {
            return false;
        }
        $sat = ColorValue::saturation($parsed);
        $lum = ColorValue::luminance($parsed);

        return $sat <= 0.22 && $lum >= 0.10 && $lum <= 0.62;
    }

    /**
     * PAT/PACS-style brand blues/cyans used as chrome (quick-action bars, nav, borders).
     * These should theme even when Write mode is "missing only".
     */
    private function isBrandChromeColor(string $from, string $prop): bool
    {
        if (preg_match('/ColorFade\s*\(\s*RGBA\s*\(/i', $from)) {
            return true;
        }
        $parsed = ColorValue::parse($from);
        if ($parsed === null || ColorValue::isTransparent($parsed)) {
            return false;
        }
        $hue = ColorValue::hue($parsed);
        $sat = ColorValue::saturation($parsed);
        if ($hue < 0) {
            return false;
        }
        // Blue / cyan / teal family
        if ($hue < 175 || $hue > 265) {
            return false;
        }
        if ($sat < 0.18) {
            return false;
        }
        $p = strtolower($prop);
        if (str_contains($p, 'border') || str_contains($p, 'fill') || $p === 'color') {
            return true;
        }

        return $sat > 0.35;
    }

    /**
     * PAT details buttons often leave Fill as comments only (//RGBA(...)), which Studio
     * treats as blank and falls back to the default primary blue. Recover the last
     * commented color literal so we can theme it.
     */
    private function recoverCommentedColorFormula(string $from, string $prop): string
    {
        $active = preg_replace('/\/\/[^\n]*/', '', $from) ?? $from;
        $active = trim(preg_replace('/\s+/', ' ', $active) ?? $active);
        $body = trim(ltrim($active, '='));
        if ($body !== '') {
            return $from;
        }
        if (preg_match('/ColorFade\s*\(\s*RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[0-9.]+\s*)?\)\s*,\s*-?\d+%\s*\)/i', $from, $m)) {
            return str_starts_with(trim($from), '=') ? '=' . $m[0] : $m[0];
        }
        if (preg_match('/RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[0-9.]+\s*)?\)/i', $from, $m)) {
            return str_starts_with(trim($from), '=') ? '=' . $m[0] : $m[0];
        }
        if (preg_match('/\bColor\.[A-Za-z]+\b/i', $from, $m)) {
            return str_starts_with(trim($from), '=') ? '=' . $m[0] : $m[0];
        }

        return $from;
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
     * Load core palette defaults from config/theme_defaults.php, plus sweep option overrides.
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
