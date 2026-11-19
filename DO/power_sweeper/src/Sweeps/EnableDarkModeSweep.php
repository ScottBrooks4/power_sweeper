<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\ControlNode;
use PowerSweeper\DocumentTwinMap;
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

    private ?DocumentTwinMap $twinMap = null;
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

    /**
     * Button / caption colors belong to the maker. Config seeds these only when
     * the app never used that family — we do not overwrite first-seen literals.
     */
    private const AUTHOR_COLOR_TOKENS = [
        'Accent', 'AccentHover',
        'Success', 'SuccessHover',
        'Warning', 'WarningHover',
        'Danger', 'DangerHover',
        'TextMuted',
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
        $this->twinMap = new DocumentTwinMap($documents);

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
                foreach (['HtmlText', 'OnReset', 'OnStart'] as $harvestProp) {
                    $html = $control->getProperty($harvestProp);
                    if (!is_string($html) || !$this->looksLikeEmbeddedHtml($html)) {
                        continue;
                    }
                    if (preg_match_all('/#(?:[0-9a-f]{3}|[0-9a-f]{6})|rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(?:\s*,\s*[\d.]+\s*)?\)/i', $html, $hm) > 0) {
                        foreach ($hm[0] as $rawCss) {
                            $parsedHtml = $this->parseCssColor($rawCss);
                            if ($parsedHtml === null || ColorValue::isTransparent($parsedHtml)) {
                                continue;
                            }
                            $htmlProp = ColorValue::luminance($parsedHtml) < 0.50 ? 'Color' : 'Fill';
                            $htmlToken = ColorValue::themeToken($parsedHtml, $htmlProp);
                            if (!isset($palette[$htmlToken])) {
                                $palette[$htmlToken] = [
                                    'light' => [
                                        'r' => $parsedHtml['r'],
                                        'g' => $parsedHtml['g'],
                                        'b' => $parsedHtml['b'],
                                        'a' => $parsedHtml['a'],
                                    ],
                                    'dark' => ColorValue::toDark($parsedHtml, ColorValue::roleForProperty($htmlProp)),
                                ];
                            }
                        }
                    }
                }
            }
        }

        // Seed missing core tokens from editable config; then apply chrome overrides.
        // Author colors (Success/Danger/Warning/TextMuted/Accent) keep first-seen light values.
        [$coreDefaults, $forcedDefaults] = $this->resolveCoreDefaults($options);
        foreach ($coreDefaults as $core => $pair) {
            if (!isset($palette[$core])) {
                $palette[$core] = $pair;
            } elseif (in_array($core, self::AUTHOR_COLOR_TOKENS, true)) {
                $palette[$core]['dark'] = $pair['dark'];
            }
        }
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
                    if ($this->shouldLeaveRadioTrack($control, $prop)) {
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
            if ($this->skipTwinFormulaDoc($doc)) {
                continue;
            }
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
            if ($this->skipTwinFormulaDoc($doc)) {
                continue;
            }
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

        // Pass 5: labels/text — wrap Color/FontColor so dark mode inverts luminosity.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isTextControl($control)) {
                    continue;
                }
                foreach (['Color', 'FontColor'] as $prop) {
                    if ($prop === 'FontColor' && $control->getProperty('FontColor') === null
                        && !str_contains(strtolower($control->type), 'canvas')
                        && !str_contains(strtolower($control->type), 'modern')
                    ) {
                        continue;
                    }
                    $from = $control->getProperty($prop);
                    $fromStr = $from !== null ? (string) $from : '';
                    if ($fromStr !== '' && $this->usesActiveThemeToken($fromStr, $theme)
                        && str_contains($fromStr, $var)
                    ) {
                        continue;
                    }
                    $token = 'Text';
                    if ($fromStr !== '' && trim($fromStr) !== '') {
                        $parsed = ColorValue::parse($fromStr);
                        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                            $token = $this->coreTokenForLiteral($parsed, 'Color');
                        }
                    }
                    $to = $this->themeInkFormula($control, $theme, $var, $token, $fromStr);
                    if ($to === $fromStr) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $fromStr !== '' ? $fromStr : '(unset)', $to);
                }
            }
        }

        // Pass 5b: dark ink on pastel ColorFade chips / bright Warning|Success banners
        $this->applyTextOnLightSurfaces($documents, $theme, $report);
        // Pass 5c: ColorFade(..., +N%) status chips stay pastel in dark mode — flip fade when dark.
        $this->themePastelColorFadeFills($documents, $var, $report);

        // Pass 6: input surfaces (white / light-grey fills) use InputFill + readable Color
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isInputControl($control)) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                $fromStr = $from !== null ? (string) $from : '';
                $promoted = $this->promoteInputFill($control, $fromStr, $theme, $var);
                if ($promoted !== null && $promoted !== $fromStr) {
                    $control->setProperty('Fill', $promoted);
                    $report->add(self::id(), $control->path, 'Fill', $fromStr !== '' ? $fromStr : '(unset)', $promoted);
                } elseif ($fromStr === '' || !$this->alreadyThemed($fromStr, $var, $theme)) {
                    $skipFill = $fromStr !== '' && !$this->isThemeableInputFill($fromStr, $force, $theme, $var, $themeLight, $themeDark);
                    if (!$skipFill) {
                        $to = $this->themePropertyFormula($control, $theme, $var, 'InputFill', $fromStr, 'Fill');
                        if ($to !== $fromStr) {
                            $control->setProperty('Fill', $to);
                            $report->add(self::id(), $control->path, 'Fill', $fromStr !== '' ? $fromStr : '(unset)', $to);
                        }
                    }
                }
                $this->ensureInputReadableChrome($control, $theme, $var, $themeLight, $themeDark, $force, $report);
                $this->ensureInputFillWhenThemed($control, $theme, $var, $themeLight, $themeDark, $force, $report);
                $this->ensureDisabledInputChrome($control, $theme, $var, $report);
            }
        }

        // Pass 6a: Fluent combobox/dropdown ignore Fill/Color. YAML often has no Appearance
        // (Studio default Outline = white field + themed Text), while JSON packs keep the
        // template default Appearance.FilledDarker in both themes. Always wrap Appearance.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isFluentPicker($control)) {
                    continue;
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
            if ($this->skipTwinFormulaDoc($doc)) {
                continue;
            }
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
            if ($this->skipTwinFormulaDoc($doc)) {
                continue;
            }
            $doc->transformFormulas(function (string $formula, string $path) use ($theme, $report): string {
                $rewritten = $formula;
                if ($this->isTranslationTableFormula($formula)) {
                    $restored = $this->restoreSplicedTranslationHtml($formula);
                    if ($restored !== $formula) {
                        $report->add(self::id(), $path, 'OnReset', 'spliced gblTheme CSS', 'restored literal HTML');
                    }

                    return $restored;
                }
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
                    } elseif ($this->isTranslationTableFormula($html)) {
                        $restored = $this->restoreSplicedTranslationHtml($html);
                        if ($restored !== $html) {
                            $control->setProperty($htmlProp, $restored);
                            $report->add(self::id(), $control->path, $htmlProp, 'spliced gblTheme CSS', 'restored literal HTML');
                        }
                        continue;
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
        $this->ensureButtonLabelInk($documents, $theme, $var, $report);
        // Pass 8a: classic / modern icons with unset or near-black Color → Text
        $this->ensureIconInk($documents, $theme, $report);
        // Pass 8a2: dropdown/combobox chevron glyph is ChevronFill (ink), not a surface.
        $this->ensurePickerChevronChrome($documents, $theme, $var, $report);
        // Pass 8b: pale Modern button BasePaletteColor (Return Home taupe → Surface)
        $this->themePaleModernButtonPalettes($documents, $theme, $var, $themeLight, $themeDark, $force, $report);
        // Pass 8c: keep blank-text hit-target buttons transparent (sidebar overlays)
        $this->ensureTransparentHitTargetFills($documents, $report);
        // Pass 8d: classic buttons with no/light Fill + white Text paint a blank chip.
        $this->ensureLabeledButtonFill($documents, $theme, $var, $report);
        // Saturated CTA fills (popup Submit/Cancel) keep the maker color in light mode.
        $this->ensureChromaticButtonFill($documents, $theme, $var, $report);
        $this->ensureButtonLabelInk($documents, $theme, $var, $report);
        $this->ensureLabeledButtonWidth($documents, $report);
        // Pass 8d1: layout wrappers with unset Fill paint Studio's light default over dark cards.
        $this->ensureUnsetLayoutFillsTransparent($documents, $report);
        // Pass 8d2: disabled Save/CTA chips — Studio DisabledFill is a white pill.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($this->isButtonControl($control)) {
                    $this->ensureDisabledInputChrome($control, $theme, $var, $report);
                }
            }
        }
        // Pass 8d3: named brand fills (PacsTeal tab bar) stay neon unless darkened.
        $this->wrapNamedBrandSurfaces($documents, $theme, $var, $report);
        // Pass 9: TextOnLight on dark/page surfaces (status badges, legends) → Text or keep semantic tokens from pass 2.
        $this->rewriteDarkSurfaceInk($documents, $theme, $report);
        // Pass 10: bare gblTheme.Page on children paints black in Studio / light mode.
        $this->unwrapBareSurfaceFills($documents, $theme, $var, $report);
        // Pass 11: borders + hovers so controls stay distinct on dark surfaces.
        $this->ensureInteractiveChrome($documents, $theme, $var, $report);
        // Pass 11 hover chrome may reset HoverColor to bare TextOnAccent — re-pair ink.
        $this->ensureButtonLabelInk($documents, $theme, $var, $report);
        // Pass 11a: If(selected, Self.HoverFill) tab bars — after hover chrome so Selected sticks.
        $this->themeSegmentedTabChrome($documents, $theme, $var, $report);
        // Pass 11b: Studio drop shadows are a faint lift on white and a heavy halo on dark.
        $this->softenDarkShadows($documents, $var, $report);
        // Locked gallery overlays (Visible=ThisItem.Locked, full template) must stay a
        // wash — opaque inverted Page paints out the card titles underneath.
        $this->softenLockedCardOverlays($documents, $theme, $var, $report);
        // HTML in component OnReset (comTranslations.Labels.*) is spliced in pass 7 —
        // AccessAppScope must run after those gblTheme.*Css references exist.
        $this->ensureThemeComponentAppScope($documents, $report);
        $this->backfillUniqueThemeTokens($documents, $var, $theme, $themeLight, $themeDark, $palette, $report);
        // A white secondary face inverts to the dark end of that neutral.
        // The palette stored on BorderColor is not the face color.
        $this->keepNeutralButtonSpectrum($documents, $var, $report);
        $this->ensureButtonLabelInk($documents, $theme, $var, $report);
        // Pass 12: Size=0 leftover from modern→classic (Fluent Size auto) hides text.
        $this->repairZeroFontSize($documents, $report);
        // White ink on the save button reads larger than the same 12pt dark ink.
        $this->shrinkDarkSaveLabel($documents, $var, $report);
        // Arriving on Approvals leaves the transmittal tab unselected, so its
        // container (Visible when varTransmitalContainer = "1") stays hidden.
        $this->openTransmittalList($documents, $report);
        // VCDS TopbarHeader: title Label Height=Parent.Height covers settings radios
        // when the host expands to App.Height — clamp chrome and raise the panel.
        $this->ensureExpandableTopbarClicks($documents, $report);
        // Formula passes skipped JSON twins; copy YAML results onto them.
        if ($this->twinMap !== null) {
            $synced = $this->twinMap->syncYamlToJson();
            if ($synced > 0) {
                $report->add(self::id(), '(twins)', 'sync', 'yaml→json', (string) $synced . ' properties');
            }
        }
        $this->twinMap = null;
    }

    /** Skip JSON ControlDocuments that mirror Src YAML (formulas synced afterward). */
    private function skipTwinFormulaDoc(ControlDocument $doc): bool
    {
        return $this->twinMap !== null && $this->twinMap->isJsonTwin($doc);
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
                && ColorValue::luminance($dark) > 0.12
            ) {
                $dark = ColorValue::defaultDarkForToken($token);
            }
            // CTA ink must stay white. Harvested dark-grey Color literals used to
            // poison TextOnAccent (hallway Activate / Save buttons).
            if ($token === 'TextOnAccent' && ColorValue::luminance($dark) < 0.85) {
                $dark = ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0];
            }
            $darkFields[] = $token . ': ' . ColorValue::formatRgba($dark);
            if (ColorValue::isUniqueColorToken($token)) {
                $lightFields[] = $token . 'Css: "' . ColorValue::toHex($pair['light']) . '"';
                $darkFields[] = $token . 'Css: "' . ColorValue::toHex($dark) . '"';
            }
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
        if (isset($palette['SurfaceMuted'])) {
            $lightFields[] = 'SurfaceMutedCss: "' . ColorValue::toHex($palette['SurfaceMuted']['light']) . '"';
            $darkFields[] = 'SurfaceMutedCss: "' . ColorValue::toHex($palette['SurfaceMuted']['dark']) . '"';
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

    /**
     * HTML splice can name C*_Css tokens that pass 1 never harvested.
     * Missing fields evaluate Blank and can blank whole translation HTML records.
     *
     * @param list<ControlDocument> $documents
     * @param array<string, array{light: array{r:int,g:int,b:int,a:float}, dark: array{r:int,g:int,b:int,a:float}}> $palette
     */
    private function backfillUniqueThemeTokens(
        array $documents,
        string $var,
        string $theme,
        string $themeLight,
        string $themeDark,
        array &$palette,
        Report $report,
    ): void {
        $used = [];
        foreach ($documents as $doc) {
            if ($this->skipTwinFormulaDoc($doc)) {
                continue;
            }
            $doc->transformFormulas(function (string $formula) use ($theme, &$used): string {
                if (preg_match_all('/' . preg_quote($theme, '/') . '\.(C\d+_\d+_\d+(?:_A\d+)?)/', $formula, $m) > 0) {
                    foreach ($m[1] as $token) {
                        $used[$token] = true;
                    }
                }

                return $formula;
            });
        }
        $added = 0;
        foreach (array_keys($used) as $token) {
            if (isset($palette[$token])) {
                continue;
            }
            if (preg_match('/^C(\d+)_(\d+)_(\d+)(?:_A(\d+))?$/', $token, $m) !== 1) {
                continue;
            }
            $light = [
                'r' => (int) $m[1],
                'g' => (int) $m[2],
                'b' => (int) $m[3],
                'a' => isset($m[4]) && $m[4] !== '' ? ((int) $m[4]) / 100.0 : 1.0,
            ];
            $palette[$token] = [
                'light' => $light,
                'dark' => ColorValue::flipSpectrum($light),
            ];
            $added++;
        }
        if ($added === 0) {
            return;
        }
        ksort($palette);
        $apps = $this->findAllApps($documents);
        $block = $this->buildThemeBlock($var, $theme, $themeLight, $themeDark, $palette);
        foreach ($apps as $appControl) {
            $before = (string) ($appControl->getProperty('OnStart') ?? '');
            $after = $this->upsertThemeBlock($before, $block, $appControl->format === 'yaml');
            if ($after !== $before) {
                $appControl->setProperty('OnStart', $after);
                $report->add(self::id(), $appControl->path, 'OnStart', 'palette', 'backfill ' . $added . ' unique CSS tokens');
            }
        }
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
        // Classic Radio honors Default (scalar), not DefaultSelectedItems.
        $classicRadio = str_contains(strtolower($radio->type), 'classic/')
            || preg_match('/(?:^|\\/)radio@2\\./i', $radio->type) === 1;
        if ($classicRadio) {
            $beforeScalar = (string) ($radio->getProperty('Default') ?? '');
            if (!str_contains($beforeScalar, $var)) {
                $scalar = 'If(' . $var . ', "Dark", "Light")';
                $scalarTo = $radio->format === 'yaml' ? '=' . $scalar : $scalar;
                $radio->setProperty('Default', $scalarTo);
                $report->add(self::id(), $radio->path, 'Default', $beforeScalar !== '' ? $beforeScalar : '(empty)', $scalarTo);
            }
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
        // Modern RadioGroupCanvas item labels follow FontColor; classic Radio uses Color.
        $classic = str_contains(strtolower($radio->type), 'classic/')
            || preg_match('/(?:^|\\/)radio@2\\./i', $radio->type) === 1;
        $prop = $classic ? 'Color' : 'FontColor';
        $before = (string) ($radio->getProperty($prop) ?? '');
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
        $radio->setProperty($prop, $to);
        $report->add(self::id(), $radio->path, $prop, $before !== '' ? $before : '(unset)', $to);
    }

    /** Radio track/item fills are not input chrome — theming them paints a solid bar. */
    private function shouldLeaveRadioTrack(ControlNode $control, string $prop): bool
    {
        if (!$this->isRadioControl($control)) {
            return false;
        }

        return (bool) preg_match('/^(Fill|RadioBackgroundFill|SelectedFill|ItemFill|HoverFill|PressedFill)$/i', $prop);
    }

    /**
     * Modern ButtonCanvas / classic buttons often keep Studio's default black label when
     * only Fill/BasePaletteColor was themed (TDR Settings Annuler / Enregistrer).
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureButtonLabelInk(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control)) {
                    continue;
                }
                $fill = (string) ($control->getProperty('Fill') ?? $control->getProperty('BasePaletteColor') ?? '');
                // Prefer Color; also set FontColor when the modern control exposes it.
                foreach (['Color', 'FontColor', 'HoverColor', 'PressedColor'] as $prop) {
                    if ($prop === 'FontColor' && $control->getProperty('FontColor') === null
                        && !$this->isModernCanvasButton($control)
                    ) {
                        continue;
                    }
                    $before = $control->getProperty($prop);
                    $beforeStr = $before !== null ? (string) $before : '';
                    $trim = trim(ltrim($beforeStr, '='));
                    $parsed = $trim !== '' ? ColorValue::parse($beforeStr) : null;
                    $missing = $before === null || $trim === '' || $trim === '""';
                    if ($missing && $this->isTransparentAppearance($control->getProperty('Appearance'))) {
                        $beforeStr = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 1)' : 'RGBA(0, 0, 0, 1)';
                        $parsed = ColorValue::parse($beforeStr);
                    }
                    if ($prop !== 'Color' && $prop !== 'FontColor' && $missing) {
                        continue;
                    }
                    // Segmented tabs: If(var = n, Self.HoverColor, gblTheme.Text) — do not flatten.
                    if (!$missing && preg_match('/If\s*\(/i', $beforeStr) === 1
                        && str_contains($beforeStr, 'Self.HoverColor')
                        && !str_contains($beforeStr, $theme . '.TextOnLight')
                    ) {
                        continue;
                    }
                    $darkLiteral = $parsed !== null && ColorValue::luminance($parsed) < 0.35 && ($parsed['a'] ?? 1) > 0.8;
                    $lightLiteral = $parsed !== null && ColorValue::luminance($parsed) > 0.85 && ($parsed['a'] ?? 1) > 0.8;
                    $token = $this->buttonInkTokenForFill($fill, $theme, $control);
                    $currentToken = $this->fillTokenFromFormula($beforeStr, $theme);
                    $wrongPair = $currentToken !== null && $currentToken !== $token;
                    $bareOnAccent = $token === 'TextOnAccent' && $this->isPureThemeReference($beforeStr, $theme)
                        && str_contains($beforeStr, $theme . '.TextOnAccent');
                    $badgeNeedsWhite = $token === 'TextOnAccent' && $this->isViewModeBadge($control)
                        && str_contains($beforeStr, $theme . '.TextOnLight');
                    if (!$missing && !$darkLiteral && !$lightLiteral && !$wrongPair && !$bareOnAccent && !$badgeNeedsWhite) {
                        continue;
                    }
                    $to = $this->buttonInkFormula($control, $theme, $var, $token, $beforeStr);
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
     * White TextOnAccent vanishes when Studio paints a pale chip instead of Fill
     * (Request Role Submit/Cancel). Light mode uses TextOnLight; dark keeps white.
     */
    private function buttonInkFormula(ControlNode $control, string $theme, string $var, string $token, string $from = ''): string
    {
        if ($from !== '' && trim($from) !== '' && trim($from) !== '=') {
            $orig = $this->lightFallbackLiteral($from, $token);
            if (preg_match('/RGBA\s*\(|\bColor\.[A-Za-z]+\b/i', $orig) === 1) {
                $body = 'If(' . $var . ', ' . $theme . '.' . $token . ', ' . $orig . ')';

                return $control->format === 'yaml' ? '=' . $body : $body;
            }
        }
        if ($token === 'TextOnAccent') {
            // View-mode step badges (1/2/3) keep a dark navy Fill — TextOnLight makes a black square.
            if ($this->isViewModeBadge($control)) {
                return $this->themeFormula($control, $theme, 'TextOnAccent');
            }
            $body = 'If(' . $var . ', ' . $theme . '.TextOnAccent, ' . $theme . '.TextOnLight)';

            return $control->format === 'yaml' ? '=' . $body : $body;
        }

        return $this->themeFormula($control, $theme, $token);
    }

    private function isViewModeBadge(ControlNode $control): bool
    {
        $mode = (string) ($control->getProperty('DisplayMode') ?? '');
        $text = trim((string) ($control->getProperty('Text') ?? ''), "=\"");
        $numbered = preg_match('/^[1-9]$/', $text) === 1;
        if (preg_match('/DisplayMode\\.View/i', $mode) !== 1 && !$numbered) {
            return false;
        }
        $fill = (string) ($control->getProperty('Fill') ?? '');
        if (preg_match('/' . preg_quote('gblTheme', '/') . '\\.(Accent|Success|Warning|Danger)\\b/', $fill) === 1
            || preg_match('/' . preg_quote('gblTheme', '/') . '\\.C\\d+_\\d+_\\d+/', $fill) === 1
        ) {
            return $numbered || preg_match('/DisplayMode\\.View/i', $mode) === 1;
        }
        $lum = $this->lightBranchFillLuminance($fill);

        return $numbered || ($lum !== null && $lum < 0.42 && preg_match('/DisplayMode\\.View/i', $mode) === 1);
    }

    private function lightBranchFillLuminance(string $fill): ?float
    {
        if (preg_match('/If\s*\(\s*gblDarkMode\s*,[^,]+,\s*(RGBA\s*\([^)]+\))/i', $fill, $m) === 1) {
            $parsed = ColorValue::parse($m[1]);

            return $parsed !== null ? ColorValue::luminance($parsed) : null;
        }
        $parsed = ColorValue::parse($fill);
        if ($parsed === null || ColorValue::isTransparent($parsed)) {
            return null;
        }

        return ColorValue::luminance($parsed);
    }

    /**
     * AutoLayout popup buttons with no Width collapse to a stub; the label is clipped.
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureLabeledButtonWidth(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control) || $this->isBlankButtonText($control->getProperty('Text'))) {
                    continue;
                }
                $from = $control->getProperty('Width');
                $fromStr = $from !== null ? (string) $from : '';
                if ($fromStr !== '' && trim($fromStr) !== '' && trim($fromStr) !== '=') {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=150' : '150';
                $control->setProperty('Width', $to);
                $report->add(self::id(), $control->path, 'Width', $fromStr !== '' ? self::preview($fromStr) : '(unset)', $to);
                $min = $control->getProperty('LayoutMinWidth');
                $minStr = $min !== null ? trim((string) $min) : '';
                if ($minStr === '' || $minStr === '=') {
                    $minTo = $control->format === 'yaml' ? '=120' : '120';
                    $control->setProperty('LayoutMinWidth', $minTo);
                    $report->add(self::id(), $control->path, 'LayoutMinWidth', '(unset)', $minTo);
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
                    $lightLiteral = $parsed !== null && ColorValue::luminance($parsed) > 0.85 && ($parsed['a'] ?? 1) > 0.8;
                    $fill = (string) ($control->getProperty('Fill') ?? '');
                    $onAccent = preg_match('/' . preg_quote($theme, '/') . '\\.(Accent|Success|Warning|Danger)\\b/', $fill) === 1;
                    $wrongAccentInk = !$onAccent && str_contains($beforeStr, $theme . '.TextOnAccent');
                    $alreadyOk = $beforeStr !== '' && !$wrongAccentInk && (
                        str_contains($beforeStr, $theme . '.Text')
                        || ($onAccent && str_contains($beforeStr, $theme . '.TextOnAccent'))
                        || str_contains($beforeStr, $theme . '.Link')
                    );
                    if ($alreadyOk || (!$missing && !$darkLiteral && !$lightLiteral && !$wrongAccentInk)) {
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
     * Classic DropDown/ComboBox paint the arrow with ChevronFill and the waffle/search
     * glyph with IconColor. Treating those as surfaces maps them to InputFill (invisible).
     *
     * @param list<ControlDocument> $documents
     */
    private function ensurePickerChevronChrome(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isPickerChevronHost($control)) {
                    continue;
                }
                foreach (['ChevronFill', 'ChevronHoverFill', 'ChevronDisabledFill', 'IconColor', 'IconFill'] as $prop) {
                    $from = $control->getProperty($prop);
                    $fromStr = $from !== null ? (string) $from : '';
                    $missing = $from === null || trim($fromStr) === '' || trim($fromStr) === '=';
                    $isIconGlyph = in_array($prop, ['IconColor', 'IconFill'], true);
                    // DropDown has Chevron*; ComboBox also has the waffle IconColor. Do not invent
                    // unknown props on DatePicker / DropDown — Studio rejects them.
                    if ($missing && !($isIconGlyph ? $this->isComboBox($control) : $this->isComboOrDropdown($control))) {
                        continue;
                    }
                    $ink = $prop === 'ChevronDisabledFill' ? 'TextMuted' : 'Text';
                    $surfaceGlyph = preg_match(
                        '/' . preg_quote($theme, '/') . '\\.(InputFill|Surface|SurfaceAlt|SurfaceMuted|Page|TextOnAccent)\\b/',
                        $fromStr
                    ) === 1;
                    $plainInk = $this->isPureThemeReference($fromStr, $theme)
                        && str_ends_with(trim(ltrim(trim($fromStr), '=')), '.' . $ink);
                    if (!$missing && !$surfaceGlyph && $plainInk) {
                        continue;
                    }
                    $to = $this->themeFormula($control, $theme, $ink);
                    if ($to === $fromStr) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $missing ? '(unset)' : self::preview($fromStr), $to);
                }
                foreach (['ChevronBackground', 'ChevronHoverBackground', 'ChevronDisabledBackground', 'IconBackground'] as $prop) {
                    $from = $control->getProperty($prop);
                    $fromStr = $from !== null ? (string) $from : '';
                    $missing = $from === null || trim($fromStr) === '' || trim($fromStr) === '=';
                    $isIconWell = $prop === 'IconBackground';
                    if ($missing && !($isIconWell ? $this->isComboBox($control) : $this->isComboOrDropdown($control))) {
                        continue;
                    }
                    $token = $prop === 'ChevronDisabledBackground' ? 'Disabled' : 'SurfaceMuted';
                    if (preg_match('/' . preg_quote($theme, '/') . '\\.(' . $token . '|Accent)\\b/', $fromStr) === 1) {
                        continue;
                    }
                    $to = $this->themePropertyFormula($control, $theme, $var, $token, $fromStr, $prop);
                    if ($to === $fromStr) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $fromStr !== '' ? self::preview($fromStr) : '(unset)', self::preview($to));
                }
            }
        }
    }

    private function isComboBox(ControlNode $control): bool
    {
        return str_contains(strtolower($control->type), 'combobox');
    }

    private function isComboOrDropdown(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'dropdown') || str_contains($t, 'combobox');
    }

    private function isPickerChevronHost(ControlNode $control): bool
    {
        if ($this->isComboOrDropdown($control) || $this->isDatePicker($control)) {
            return true;
        }
        foreach (['ChevronFill', 'ChevronHoverFill', 'ChevronBackground', 'IconColor', 'IconFill'] as $prop) {
            if ($control->getProperty($prop) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Secondary buttons keep a white face in light mode. Dark mode is that
     * neutral at the other end of the spectrum (white → black). The palette
     * on BorderColor is not the face.
     *
     * @param list<ControlDocument> $documents
     */
    private function keepNeutralButtonSpectrum(array $documents, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control) || $this->isSegmentedTabButton($control)) {
                    continue;
                }
                if ($this->isTransparentAppearance($control->getProperty('Appearance'))) {
                    continue;
                }
                if ($this->isBlankButtonText($control->getProperty('Text'))) {
                    continue;
                }
                $border = (string) ($control->getProperty('BorderColor') ?? '');
                $pigment = ColorValue::parse($border);
                if ($pigment === null || ColorValue::isTransparent($pigment) || ColorValue::isShade($pigment)) {
                    continue;
                }
                if (ColorValue::saturation($pigment) < 0.2) {
                    continue;
                }
                $fill = (string) ($control->getProperty('Fill') ?? '');
                if (!$this->isNeutralButtonFace($fill)) {
                    continue;
                }
                $lightParsed = $this->lightNeutralFace($fill);
                if ($lightParsed === null) {
                    continue;
                }
                $dark = ColorValue::flipSpectrum($lightParsed, 'background');
                $body = 'If(' . $var . ', ' . ColorValue::formatRgba($dark) . ', ' . ColorValue::formatRgba($lightParsed) . ')';
                $to = $control->format === 'yaml' ? '=' . $body : $body;
                if ($to === $fill) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', $fill !== '' ? self::preview($fill) : '(unset)', self::preview($to));
            }
        }
    }

    /**
     * @return null|array{r:int,g:int,b:int,a:float}
     */
    private function lightNeutralFace(string $fill): ?array
    {
        $parsed = ColorValue::parse($fill);
        if ($parsed !== null && ColorValue::isShade($parsed) && !ColorValue::isTransparent($parsed) && ColorValue::luminance($parsed) >= 0.70) {
            return $parsed;
        }
        if (preg_match_all('/RGBA\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([0-9.]+)\s*)?\)/i', $fill, $matches, PREG_SET_ORDER) < 1) {
            return ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0];
        }
        $best = null;
        $bestLum = -1.0;
        foreach ($matches as $m) {
            $cand = [
                'r' => (int) $m[1],
                'g' => (int) $m[2],
                'b' => (int) $m[3],
                'a' => isset($m[4]) && $m[4] !== '' ? (float) $m[4] : 1.0,
            ];
            if (!ColorValue::isShade($cand) || ColorValue::isTransparent($cand)) {
                continue;
            }
            $lum = ColorValue::luminance($cand);
            if ($lum >= 0.70 && $lum > $bestLum) {
                $best = $cand;
                $bestLum = $lum;
            }
        }

        return $best;
    }

    private function isNeutralButtonFace(string $fill): bool
    {
        if (trim($fill) === '' || trim($fill) === '=') {
            return true;
        }
        if (preg_match('/gblTheme\.(Page|Surface|SurfaceMuted|SurfaceAlt|InputFill)\b/', $fill) === 1
            && preg_match('/gblTheme\.C\d+_\d+_\d+/', $fill) !== 1
            && preg_match('/gblTheme\.(Accent|Success|Warning|Danger)\b/', $fill) !== 1
        ) {
            return true;
        }
        $parsed = ColorValue::parse($fill);

        return $parsed !== null && (ColorValue::isShade($parsed) || ColorValue::isTransparent($parsed));
    }

    /**
     * Saturated button fills (popup Submit/Cancel purple) pair with white TextOnAccent.
     * Wrap the dark branch to Accent so the chip does not wash out on a themed card.
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureChromaticButtonFill(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control) || $this->isSegmentedTabButton($control)) {
                    continue;
                }
                if ($this->isBlankButtonText($control->getProperty('Text'))) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                $fromStr = $from !== null ? (string) $from : '';
                if ($fromStr === '' || str_contains($fromStr, $var)) {
                    continue;
                }
                if (preg_match('/' . preg_quote($theme, '/') . '\\.(Accent|Success|Warning|Danger)\\b/', $fromStr) === 1) {
                    continue;
                }
                $parsed = ColorValue::parse($fromStr);
                if ($parsed === null || ColorValue::isTransparent($parsed) || ColorValue::saturation($parsed) <= 0.35) {
                    continue;
                }
                $token = ColorValue::themeToken($parsed, 'Fill');
                if (!ColorValue::isUniqueColorToken($token) && !in_array($token, ['Accent', 'Success', 'Warning', 'Danger'], true)) {
                    continue;
                }
                $to = $this->themePropertyFormula($control, $theme, $var, $token, $fromStr, 'Fill');
                if ($to === $fromStr) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', self::preview($fromStr), self::preview($to));
            }
        }
    }

    /**
     * AutoLayout/manual wrappers with no Fill use Studio's light default and cover
     * the parent card (Request Role popup, Settings footer).
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureUnsetLayoutFillsTransparent(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isScreen() || $control->isApp()) {
                    continue;
                }
                $t = strtolower($control->type);
                if (!str_contains($t, 'groupcontainer') && !str_contains($t, 'layoutcontainer')) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                $fromStr = $from !== null ? (string) $from : '';
                if ($fromStr !== '' && trim($fromStr) !== '' && trim($fromStr) !== '=') {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=RGBA(0, 0, 0, 0)' : 'RGBA(0, 0, 0, 0)';
                if ($to === $fromStr) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', $fromStr !== '' ? self::preview($fromStr) : '(unset)', $to);
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

    /**
     * Classic buttons with a label but no Fill keep Studio's light grey chip.
     * gblTheme.Text is white in dark mode, so the caption disappears.
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureLabeledButtonFill(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control) || $this->isSegmentedTabButton($control)) {
                    continue;
                }
                if ($this->isTransparentAppearance($control->getProperty('Appearance'))) {
                    continue;
                }
                if ($this->isBlankButtonText($control->getProperty('Text'))) {
                    continue;
                }
                $from = $control->getProperty('Fill');
                $fromStr = $from !== null ? (string) $from : '';
                if (preg_match('/If\s*\(/i', $fromStr) === 1) {
                    continue;
                }
                if (preg_match('/' . preg_quote($theme, '/') . '\\.(Accent|Success|Warning|Danger|Surface|SurfaceAlt|SurfaceMuted|InputFill)\\b/', $fromStr) === 1) {
                    continue;
                }
                $parsed = $fromStr !== '' ? ColorValue::parse($fromStr) : null;
                $empty = $from === null || trim($fromStr) === '' || trim($fromStr) === '=';
                $lightChip = $parsed !== null && !ColorValue::isTransparent($parsed)
                    && ColorValue::isShade($parsed)
                    && ColorValue::luminance($parsed) >= 0.70;
                if (!$empty && !$lightChip) {
                    continue;
                }
                $to = $this->themePropertyFormula($control, $theme, $var, 'SurfaceAlt', $fromStr, 'Fill');
                if ($to === $fromStr) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', $empty ? '(unset)' : self::preview($fromStr), self::preview($to));
            }
        }
    }

    /**
     * App.Formulas brand colours (PacsTeal, …) used as Fill stay the light-theme neon.
     * Dark mode uses ColorFade(-50%) so the bar keeps its hue without glowing.
     *
     * @param list<ControlDocument> $documents
     */
    private function wrapNamedBrandSurfaces(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['Fill', 'HoverFill', 'PressedFill', 'BackgroundColor'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null) {
                        continue;
                    }
                    $name = $this->namedBrandFillName((string) $from);
                    if ($name === null || str_contains((string) $from, $var)) {
                        continue;
                    }
                    $body = 'If(' . $var . ', ColorFade(' . $name . ', -50%), ' . $name . ')';
                    $to = $control->format === 'yaml' ? '=' . $body : $body;
                    if ($to === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, self::preview((string) $from), self::preview($to));
                }
            }
        }
    }

    private function namedBrandFillName(string $from): ?string
    {
        $body = trim(ltrim(trim($from), '='));
        if (preg_match('/^[A-Z][A-Za-z0-9]*(?:Teal|Cyan|Navy|BrandColor)$/', $body) === 1) {
            return $body;
        }

        return null;
    }

    private function isSegmentedTabButton(ControlNode $control): bool
    {
        if (!$this->isButtonControl($control)) {
            return false;
        }
        $fill = (string) ($control->getProperty('Fill') ?? '');

        return (bool) preg_match('/Self\\.HoverFill/i', $fill);
    }

    /**
     * Tab bars bind Fill/Color to HoverFill/HoverColor. A mid-grey HoverFill plus
     * white HoverColor washes out; in dark mode use Selected + Text.
     *
     * @param list<ControlDocument> $documents
     */
    private function themeSegmentedTabChrome(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isSegmentedTabButton($control)) {
                    continue;
                }
                $hoverFill = (string) ($control->getProperty('HoverFill') ?? '');
                if ($hoverFill === '' || !str_contains($hoverFill, $theme . '.Selected')) {
                    // These tabs set Fill to Self.HoverFill when varTransmitalContainer
                    // matches. The light face must stay the author's gray, not white,
                    // or the selected section looks blank.
                    $orig = 'RGBA(110, 110, 110, 1)';
                    if (preg_match('/RGBA\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)/i', $hoverFill, $hoverRgba) === 1) {
                        $parsedHover = ColorValue::parse($hoverRgba[0]);
                        if ($parsedHover !== null && !ColorValue::isTransparent($parsedHover)) {
                            $orig = ColorValue::formatRgba($parsedHover);
                        }
                    }
                    $body = 'If(' . $var . ', ' . $theme . '.Selected, ' . $orig . ')';
                    $to = $control->format === 'yaml' ? '=' . $body : $body;
                    if ($to !== $hoverFill) {
                        $control->setProperty('HoverFill', $to);
                        $report->add(self::id(), $control->path, 'HoverFill', self::preview($hoverFill !== '' ? $hoverFill : '(unset)'), self::preview($to));
                    }
                }
                $hoverColor = (string) ($control->getProperty('HoverColor') ?? '');
                if ($hoverColor === '' || !$this->usesActiveThemeToken($hoverColor, $theme)) {
                    $to = $this->themeFormula($control, $theme, 'Text');
                    if ($to !== $hoverColor) {
                        $control->setProperty('HoverColor', $to);
                        $report->add(self::id(), $control->path, 'HoverColor', $hoverColor !== '' ? self::preview($hoverColor) : '(unset)', $to);
                    }
                }
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

    /** Fluent ButtonCanvas (Button@0.0.x) reads FontColor, not classic Color. */
    private function isModernCanvasButton(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return preg_match('/button@0\\.0|buttoncanvas|modernbutton/i', $t) === 1
            || (str_contains($t, 'canvas') && str_contains($t, 'button'));
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
        // The selected-section plate sits ON the rail. Treating it as chrome
        // paints it Page, the same color as the rail, so the highlight vanishes.
        if ($this->isNavSelectionPlate($control)) {
            return false;
        }
        $hay = strtolower($control->name . ' ' . $control->path);
        if (preg_match('/sidebar|side_bar|sidemenu|navbar|navpane|navrail|menurail|iconrail/i', $hay) === 1) {
            return true;
        }
        $fill = (string) ($control->getProperty('Fill') ?? '');

        return preg_match('/DefaultGrayBackgroud/i', $fill) === 1;
    }

    /**
     * Sidebar current-section chip: white plate + DropShadow.Regular, shown when
     * ThisItem.Screen = App.ActiveScreen. A dark shadow on a black rail is
     * invisible, so the plate itself has to be lighter than Page.
     */
    private function isNavSelectionPlate(ControlNode $control): bool
    {
        $visible = (string) ($control->getProperty('Visible') ?? '');
        if (stripos($visible, 'ActiveScreen') === false) {
            return false;
        }
        $shadow = (string) ($control->getProperty('DropShadow') ?? '');

        return preg_match('/DropShadow\.(Regular|Semibold|Bold|Light)/i', $shadow) === 1
            && preg_match('/DropShadow\.None/i', $shadow) !== 1;
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

    private function buttonInkTokenForFill(string $fill, string $theme, ?ControlNode $control = null): string
    {
        if ($control !== null && $this->isTransparentAppearance($control->getProperty('Appearance'))) {
            // Start / Open keep author ink (black → white). Link restyles them teal.
            return 'Text';
        }
        if (preg_match('/' . preg_quote($theme, '/') . '\\.(Accent|Success|Warning|Danger)(?:Hover)?\\b/', $fill) === 1
            || preg_match('/' . preg_quote($theme, '/') . '\\.C\\d+_\\d+_\\d+/', $fill) === 1
        ) {
            return 'TextOnAccent';
        }
        if (preg_match('/' . preg_quote($theme, '/') . '\\.(Page|Surface|SurfaceMuted|SurfaceAlt|InputFill)\\b/', $fill) === 1) {
            return 'Text';
        }
        $parsed = $fill !== '' ? ColorValue::parse($fill) : null;
        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
            // Cream/yellow status chips stay light in both themes — white Text fails contrast.
            if (ColorValue::luminance($parsed) >= 0.65) {
                return 'TextOnLight';
            }
            if (!ColorValue::isShade($parsed)) {
                return 'TextOnAccent';
            }
        }
        $base = $control !== null ? (string) ($control->getProperty('BasePaletteColor') ?? '') : '';
        if ($base !== '' && preg_match('/' . preg_quote($theme, '/') . '\\.(Accent|Success|Warning|Danger)\\b/', $base) === 1) {
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
            if ($this->skipTwinFormulaDoc($doc)) {
                continue;
            }
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
                    if (!ColorValue::isShade($parsed) || ColorValue::luminance($parsed) < 0.40) {
                        continue;
                    }
                } else {
                    $parsed = ColorValue::parse($from);
                    if ($parsed === null || ColorValue::isTransparent($parsed)) {
                        continue;
                    }
                    if (!ColorValue::isShade($parsed) || (ColorValue::luminance($parsed) < 0.40 && !$force)) {
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
        // Already rewritten with literal hexes. Do not touch a second time.
        if (str_contains($formula, 'Substitute(') && str_contains($formula, '#1C1C1E')) {
            return $formula;
        }

        $eq = str_starts_with(trim($formula), '=');
        $body = ltrim(trim($formula), '=');
        [$head, $tail] = $this->splitFormulaLead($body);
        if (!$this->isSimpleLabelsHtmlExpr($head) || $this->isUnsafeHtmlConcatHost($head)) {
            return $formula;
        }

        // A 20-deep Substitute errors when dark mode actually evaluates the branch
        // (light mode never does). The HtmlViewer then goes blank and AutoHeight
        // fills the card, which is why the transmittal buttons disappear.
        // Four literals match Approvals_HTML. Badge color:#ffffff is not one of them.
        $expr = 'psHtml';
        foreach (array_reverse($this->translationHtmlLiteralPairs()) as [$needle, $dark]) {
            $expr = 'Substitute(' . $expr . ', "' . $needle . '", "' . $dark . '")';
        }
        $expr = 'With({psHtml:(' . $head . ')}, If(' . self::VAR . ', ' . $expr . ', psHtml))';
        if ($tail !== '') {
            $expr .= (str_starts_with($tail, "\n") ? '' : "\n") . $tail;
        }

        return $eq ? '=' . $expr : $expr;
    }

    /**
     * Exactly the inline colors in Approvals_HTML. Keep this list short.
     * A longer Substitute chain is what blanked the transmittal card.
     *
     * @return list<array{0:string,1:string}>
     */
    private function translationHtmlLiteralPairs(): array
    {
        return [
            ['background: #ffffff', 'background: #1C1C1E'],
            ['background: #f7f6f3', 'background: #0C0C0E'],
            ['color: #1f1d1a', 'color: #FFFFFF'],
            ['color: #6b6760', 'color: #D4D4DC'],
        ];
    }

    /** Dark hex used when a theme *Css field is missing so Substitute cannot go Blank. */
    private function darkCssFallback(string $token): string
    {
        return match ($token) {
            'PageCss' => '#0C0C0E',
            'SurfaceCss' => '#1C1C1E',
            'SurfaceMutedCss' => '#34343A',
            'TextMutedCss' => '#D4D4DC',
            'LinkCss', 'LinkHoverCss' => '#2DD4BF',
            default => '#FFFFFF',
        };
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
        foreach (['#34495e', '#34495E'] as $hex) {
            foreach (['color: ', 'color:'] as $prop) {
                $pairs[] = [$prop . $hex, 'TextCss'];
            }
        }

        return $pairs;
    }

    /**
     * Reverse prior-run CSS splices inside Set(varTranslations, …) so Labels records parse again.
     * `background:" & If(gblDarkMode, gblTheme.XCss, "#fff3cd") & "` → `background: #fff3cd`.
     */
    private function restoreSplicedTranslationHtml(string $formula): string
    {
        if (!str_contains($formula, 'gblTheme.') || !str_contains($formula, 'If(' . self::VAR)) {
            return $formula;
        }

        $restored = preg_replace(
            '/(background(?:-color)?|color)\s*:\s*"\s*&\s*If\s*\(\s*' . preg_quote(self::VAR, '/')
            . '\s*,\s*(?:' . preg_quote(self::THEME, '/') . '|gblTheme)\.[A-Za-z0-9_]+\s*,\s*"([^"]+)"\s*\)\s*&\s*"/i',
            '$1: $2',
            $formula
        );

        return is_string($restored) ? $restored : $formula;
    }

    /**
     * Translation dictionaries live in component OnReset as Set(varTranslations, Table({Labels:{...}})).
     * Splicing CSS concatenations into those string fields blanks every Labels.* value at runtime.
     */
    private function isTranslationTableFormula(string $formula): bool
    {
        if (preg_match('/Set\s*\(\s*(?:varTranslations|colTranslations)\b/i', $formula) === 1) {
            return true;
        }

        return str_contains($formula, 'Labels:')
            && preg_match('/\b(?:varTranslations|colTranslations)\b/', $formula) === 1
            && preg_match('/\bTable\s*\(/i', $formula) === 1;
    }

    /** In-app section chrome (translation Labels / HtmlText), not outbound mail. */
    private function shouldThemeInAppHtml(string $formula, string $path = ''): bool
    {
        if ($this->isUnsafeHtmlConcatHost($formula)) {
            return false;
        }
        if ($this->isTranslationTableFormula($formula)) {
            return false;
        }
        if (preg_match('/htmltext/i', $path) === 1) {
            return $this->looksLikeEmbeddedHtml($formula);
        }
        if (preg_match('/tdr-section-header|tdr-section-card/i', $formula) === 1) {
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
            if (!preg_match('/<\s*(?:!doctype|html|body|div|span|p|table|h[1-6])\b|tdr-section-header|background|color\s*:|linear-gradient/i', $chunk)) {
                return $chunk;
            }
            $next = $this->rewriteHtmlColorDeclarations($chunk, $q, $theme);
            $next = $this->rewriteHtmlLinearGradients($next, $q, $theme);
            $next = $this->injectDefaultHtmlInk($next, $q, $theme);

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
                    if ($lum < 0.72 && ColorValue::isShade($parsed)) {
                        return $m[0];
                    }
                    if (!ColorValue::isShade($parsed)) {
                        $token = ColorValue::uniqueColorToken($parsed) . 'Css';
                    } else {
                        $token = $lum >= 0.98 ? 'SurfaceCss' : 'PageCss';
                    }

                    return 'background:' . $q . ' & If(' . self::VAR . ', ' . $theme . '.' . $token . ', "' . str_replace('"', '""', $raw) . '") & ' . $q;
                }
                if ($lum > 0.88) {
                    return $m[0];
                }
                $mapped = ColorValue::themeToken($parsed, 'Color');
                if (ColorValue::isUniqueColorToken($mapped)) {
                    $token = $mapped . 'Css';
                } else {
                    $token = in_array($mapped, ['TextMuted', 'TextOnAccent'], true) ? $mapped . 'Css' : 'TextCss';
                    if ($token === 'TextOnAccentCss') {
                        $token = 'TextCss';
                    }
                }

                return 'color:' . $q . ' & If(' . self::VAR . ', ' . $theme . '.' . $token . ', "' . str_replace('"', '""', $raw) . '") & ' . $q;
            },
            $chunk
        );

        return is_string($replaced) ? $replaced : $chunk;
    }

    /**
     * HtmlViewer page/sidebar chrome: lavender→white linear-gradients stay light unless
     * each stop is wrapped with If(gblDarkMode, gblTheme.*Css, original).
     */
    private function rewriteHtmlLinearGradients(string $chunk, string $q, string $theme): string
    {
        if (preg_match('/linear-gradient\s*\(/i', $chunk) !== 1) {
            return $chunk;
        }
        if (str_contains($chunk, 'If(' . self::VAR)
            && (str_contains($chunk, $theme . '.PageCss')
                || str_contains($chunk, $theme . '.SurfaceCss')
                || str_contains($chunk, $theme . '.SurfaceMutedCss'))
        ) {
            return $chunk;
        }

        $offset = 0;
        $out = '';
        $len = strlen($chunk);
        while (preg_match('/linear-gradient\s*\(/i', $chunk, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $m[0][1];
            $open = $start + strlen($m[0][0]) - 1;
            $depth = 0;
            $end = null;
            for ($i = $open; $i < $len; $i++) {
                $ch = $chunk[$i];
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            if ($end === null) {
                break;
            }
            $out .= substr($chunk, $offset, $start - $offset);
            $inner = substr($chunk, $open + 1, $end - $open - 1);
            $rewrittenInner = preg_replace_callback(
                '/#[0-9a-f]{3,8}|rgb[a]?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(?:\s*,\s*[\d.]+\s*)?\)/i',
                fn (array $cm): string => $this->wrapHtmlCssColorForDark($cm[0], $q, $theme),
                $inner
            );
            $out .= 'linear-gradient(' . (is_string($rewrittenInner) ? $rewrittenInner : $inner) . ')';
            $offset = $end + 1;
        }
        $out .= substr($chunk, $offset);

        $withFallback = preg_replace_callback(
            '/(background(?:-color)?)\s*:\s*(#[0-9a-f]{3,8}|rgb[a]?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(?:\s*,\s*[\d.]+\s*)?\))/i',
            function (array $m) use ($q, $theme): string {
                return $m[1] . ':' . $this->wrapHtmlCssColorForDark($m[2], $q, $theme);
            },
            $out
        );

        return is_string($withFallback) ? $withFallback : $out;
    }

    /** Light-mode brand stop stays literal; dark mode uses SurfaceMuted (tint) or Page (wash). */
    private function wrapHtmlCssColorForDark(string $raw, string $q, string $theme): string
    {
        if (str_contains($raw, $theme . '.') || str_contains($raw, 'If(' . self::VAR)) {
            return $raw;
        }
        $parsed = $this->parseCssColor($raw);
        if ($parsed === null) {
            return $raw;
        }
        if (!ColorValue::isShade($parsed)) {
            $token = ColorValue::uniqueColorToken($parsed) . 'Css';
        } else {
            $token = ColorValue::luminance($parsed) >= 0.72 ? 'PageCss' : 'SurfaceMutedCss';
        }

        return $q . ' & If(' . self::VAR . ', ' . $theme . '.' . $token . ', "' . str_replace('"', '""', $raw) . '") & ' . $q;
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
     * HtmlViewer Color is ignored when the markup sets font-weight without color
     * (THCEE "START A NEW EHCR…" headers). Inject theme ink so the words invert.
     */
    private function injectDefaultHtmlInk(string $chunk, string $q, string $theme): string
    {
        if (preg_match('/color\s*:/i', $chunk) === 1) {
            return $chunk;
        }
        if (preg_match('/<\s*(?:div|p|span|center|h[1-6]|td|th|li)\b/i', $chunk) !== 1) {
            return $chunk;
        }
        if (preg_match('/style\s*=/i', $chunk) !== 1) {
            return $chunk;
        }

        $ink = 'color:' . $q . ' & If(' . self::VAR . ', ' . $theme . '.TextCss, "#000000") & ' . $q;
        $replaced = preg_replace(
            '/(style\s*=\s*)([\'"])([^\'"]*)(\2)/i',
            '$1$2$3 ' . $ink . ';$4',
            $chunk,
            1
        );

        return is_string($replaced) ? $replaced : $chunk;
    }

    /**
     * Locked gallery cards stack a full-size container over title/description.
     * Inverting that cream fill to opaque Page hides the titles; keep a wash.
     *
     * @param list<ControlDocument> $documents
     */
    private function softenLockedCardOverlays(array $documents, string $theme, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isLockedTemplateOverlay($control)) {
                    continue;
                }
                $from = (string) ($control->getProperty('Fill') ?? '');
                if ($from === '') {
                    continue;
                }
                $parsed = ColorValue::parse($from);
                if ($parsed === null && preg_match('/RGBA\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([\d.]+)\s*\)/i', $from, $m) === 1) {
                    $parsed = [
                        'r' => (int) $m[1],
                        'g' => (int) $m[2],
                        'b' => (int) $m[3],
                        'a' => (float) $m[4],
                        'raw' => $m[0],
                    ];
                }
                if ($parsed === null || ColorValue::isTransparent($parsed)) {
                    continue;
                }
                if (($parsed['a'] ?? 1.0) < 0.95 && ColorValue::luminance($parsed) < 0.45) {
                    continue;
                }
                $light = $parsed;
                $light['a'] = 1.0;
                $dark = ColorValue::flipSpectrum($light);
                $dark['a'] = 0.55;
                $body = 'If(' . $var . ', ' . ColorValue::formatRgba($dark) . ', ' . ColorValue::formatRgba($light) . ')';
                $to = $control->format === 'yaml' ? '=' . $body : $body;
                if (trim(ltrim($from, '=')) === $body) {
                    continue;
                }
                $control->setProperty('Fill', $to);
                $report->add(self::id(), $control->path, 'Fill', self::preview($from), self::preview($to));
            }
        }
    }

    private function isLockedTemplateOverlay(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if (!str_contains($t, 'container') && !str_contains($t, 'group')) {
            return false;
        }
        $visible = (string) ($control->getProperty('Visible') ?? '');
        if (preg_match('/ThisItem\s*\.\s*Locked/i', $visible) !== 1) {
            return false;
        }
        $h = (string) ($control->getProperty('Height') ?? '');
        $w = (string) ($control->getProperty('Width') ?? '');

        return str_contains($h, 'TemplateHeight') && str_contains($w, 'TemplateWidth');
    }

    /**
     * @param callable(string, string): string $rewriter chunk + quote char
     */
    private function mapCssInQuotedChunks(string $formula, callable $rewriter): string
    {
        $out = '';
        foreach (PowerFxFormulaSegments::split($formula, true) as [$type, $text]) {
            if ($type !== 'string' || strlen($text) < 2) {
                $out .= $text;
                continue;
            }
            $q = $text[0];
            $end = $text[strlen($text) - 1];
            if (($q !== '"' && $q !== "'") || $end !== $q) {
                $out .= $text;
                continue;
            }
            $inner = substr($text, 1, -1);
            if ($inner === false
                || preg_match('/<\s*(?:!doctype|html|body|div|span|p|table|h[1-6]|center)\b|tdr-section-header|background(?:-color)?\s*:|color\s*:|linear-gradient/i', $inner) !== 1
            ) {
                $out .= $text;
                continue;
            }
            $next = $rewriter($inner, $q);
            $out .= $next === $inner ? $text : $q . $next . $q;
        }

        return $out;
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

    /**
     * Expandable settings hosts (Height = If(varTopMenu, App.Height, 65)) stretch
     * sibling title labels with Height=Parent.Height across the full screen. Those
     * labels sit above the settings body (theme / language radios) and swallow clicks.
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureExpandableTopbarClicks(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            if ($this->skipTwinFormulaDoc($doc)) {
                continue;
            }
            foreach ($doc->controls() as $control) {
                if (!$this->isComponentDefinitionRoot($doc, $control)) {
                    continue;
                }
                if (!$this->isExpandableTopMenuHost($control)) {
                    continue;
                }
                $this->uncoverTopMenuPanel($control, $report);
            }
        }
    }

    private function isExpandableTopMenuHost(ControlNode $root): bool
    {
        $height = (string) ($root->getProperty('Height') ?? '');
        if (preg_match('/varTopMenu/i', $height) === 1 && preg_match('/App\.Height/i', $height) === 1) {
            return true;
        }

        return $this->subtreeHasThemeRadio($root);
    }

    private function uncoverTopMenuPanel(ControlNode $root, Report $report): void
    {
        $chromeMaxZ = 0;
        $panels = [];
        foreach ($root->children as $child) {
            if ($this->subtreeHasThemeOrLanguageRadio($child) || $this->looksLikeTopMenuBody($child)) {
                $panels[] = $child;
                continue;
            }
            $z = $this->zIndexValue($child);
            if ($z > $chromeMaxZ) {
                $chromeMaxZ = $z;
            }
            // Title labels use Height=Parent.Height; when the host expands to App.Height
            // they become a full-screen transparent hit target over the settings radios.
            if (!$this->isTextControl($child)) {
                continue;
            }
            $fromH = (string) ($child->getProperty('Height') ?? '');
            if (!$this->isParentHeightOnly($fromH)) {
                continue;
            }
            $toH = $child->format === 'yaml' ? '=65' : '65';
            $child->setProperty('Height', $toH);
            $report->add(self::id(), $child->path, 'Height', $fromH !== '' ? $fromH : '(unset)', $toH);
        }
        if ($panels === []) {
            return;
        }
        // ZIndex often lives only on the JSON twin. YAML chrome children then look
        // like Z=0 — use a floor above typical top-bar chrome (Label/Container ~5).
        $need = max(20, $chromeMaxZ + 1);
        foreach ($panels as $panel) {
            $fromZ = (string) ($panel->getProperty('ZIndex') ?? '');
            if ($this->zIndexValue($panel) >= $need) {
                $need = $this->zIndexValue($panel) + 1;
                continue;
            }
            $toZ = $panel->format === 'yaml' ? '=' . (string) $need : (string) $need;
            $panel->setProperty('ZIndex', $toZ);
            $report->add(self::id(), $panel->path, 'ZIndex', $fromZ !== '' ? $fromZ : '(unset)', $toZ);
            $need++;
        }
    }

    private function looksLikeTopMenuBody(ControlNode $control): bool
    {
        $visible = (string) ($control->getProperty('Visible') ?? '');
        if (preg_match('/varTopMenu/i', $visible) === 1) {
            return true;
        }
        $height = (string) ($control->getProperty('Height') ?? '');

        return preg_match('/Parent\.Height\s*-\s*Self\.Y/i', $height) === 1;
    }

    private function isParentHeightOnly(string $height): bool
    {
        $body = trim(ltrim(trim($height), '='));
        $body = preg_replace('/\s+/', '', $body) ?? $body;

        return strcasecmp($body, 'Parent.Height') === 0;
    }

    private function zIndexValue(ControlNode $control): int
    {
        $raw = trim(ltrim(trim((string) ($control->getProperty('ZIndex') ?? '')), '='));
        if ($raw !== '' && is_numeric($raw)) {
            return (int) $raw;
        }

        return 0;
    }

    private function subtreeHasThemeRadio(ControlNode $root): bool
    {
        $stack = [$root];
        while ($stack !== []) {
            /** @var ControlNode $node */
            $node = array_pop($stack);
            if ($this->isThemeRadio($node)) {
                return true;
            }
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }

        return false;
    }

    private function subtreeHasThemeOrLanguageRadio(ControlNode $root): bool
    {
        $stack = [$root];
        while ($stack !== []) {
            /** @var ControlNode $node */
            $node = array_pop($stack);
            if ($this->isThemeRadio($node) || $this->isLanguageRadio($node)) {
                return true;
            }
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }

        return false;
    }

    private function isLanguageRadio(ControlNode $control): bool
    {
        if (!$this->isRadioControl($control)) {
            return false;
        }
        $items = strtolower((string) ($control->getProperty('Items') ?? ''));
        $name = strtolower($control->name);
        $blob = $name . ' ' . $items . ' ' . strtolower((string) ($control->getProperty('AccessibleLabel') ?? ''));

        return str_contains($items, 'english')
            || str_contains($items, 'french')
            || str_contains($blob, 'language')
            || str_contains($blob, 'langue')
            || str_contains($name, 'lang');
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
        if ($t === 'text' || $t === 'textarea') {
            return false;
        }
        if (str_contains($t, 'label') || str_contains($t, 'htmltext') || str_contains($t, 'htmlviewer')) {
            return true;
        }
        return str_contains($t, 'text') && !str_contains($t, 'textinput') && !str_contains($t, 'richtext');
    }

    private function isInputControl(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if ($t === 'text' || $t === 'textarea') {
            return true;
        }
        foreach (['textinput', 'combobox', 'dropdown', 'datepicker', 'richtexteditor', 'numberinput', 'attachments'] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fluent pickers (ModernCombobox, ModernDropdown, ComboBoxCanvas). Classic ComboBox@2 /
     * DropDown@2 honor Fill/Color and must not receive Appearance.
     */
    private function isFluentPicker(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if (str_contains($t, 'classic/')) {
            return false;
        }
        if (preg_match('/modern(?:combobox|dropdown|textinput|datepicker)|comboboxcanvas|dropdowncanvas|textinputcanvas|datepickercanvas/i', $t) === 1) {
            return true;
        }

        return (bool) preg_match('/(?:combobox|dropdown|textinput|datepicker)@0\\.0\\./i', $t);
    }

    /**
     * Page/Surface tokens from Pass 2, or already-wrapped If(gblDarkMode, gblTheme.Page, …),
     * should be InputFill on fields so they lift off the page in dark mode.
     */
    private function promoteInputFill(ControlNode $control, string $fromStr, string $theme, string $var): ?string
    {
        if ($fromStr === '') {
            return null;
        }
        $original = $fromStr;
        $fromStr = $this->rewriteDarkInputLightFallback($fromStr, $theme, $var);
        $surface = 'Page|SurfaceMuted|SurfaceAlt|Surface';
        if ($this->isPureThemeReference($fromStr, $theme)) {
            $trim = trim($fromStr);
            if (str_starts_with($trim, '=')) {
                $trim = trim(substr($trim, 1));
            }
            $token = substr($trim, strlen($theme) + 1);
            if (in_array($token, ['Page', 'Surface', 'SurfaceMuted', 'SurfaceAlt'], true)) {
                return $this->themeFormula($control, $theme, 'InputFill');
            }

            return $fromStr !== $original ? $fromStr : null;
        }
        if (preg_match('/' . preg_quote($theme, '/') . '\\.(?:' . $surface . ')\\b/', $fromStr) === 1) {
            $to = preg_replace(
                '/' . preg_quote($theme, '/') . '\\.(?:' . $surface . ')\\b/',
                $theme . '.InputFill',
                $fromStr
            );
            if (is_string($to)) {
                $fromStr = $this->rewriteDarkInputLightFallback($to, $theme, $var);
            }
        }

        return $fromStr !== $original ? $fromStr : null;
    }

    private function rewriteDarkInputLightFallback(string $fromStr, string $theme, string $var): string
    {
        $to = preg_replace_callback(
            '/If\s*\(\s*' . preg_quote($var, '/') . '\s*,\s*' . preg_quote($theme, '/') . '\\.InputFill\s*,\s*(RGBA\s*\([^)]+\)|Color\.\w+)/i',
            static function (array $m): string {
                $parsed = ColorValue::parse($m[1]);
                if ($parsed !== null && ColorValue::luminance($parsed) < 0.40 && ($parsed['a'] ?? 1.0) >= 0.45) {
                    return substr($m[0], 0, -strlen($m[1])) . 'RGBA(255, 255, 255, 1)';
                }

                return $m[0];
            },
            $fromStr
        );

        return is_string($to) ? $to : $fromStr;
    }

    private function isThemeableInputFill(
        string $from,
        bool $force,
        string $theme,
        string $var,
        string $themeLight,
        string $themeDark,
    ): bool {
        if (trim($from) === '' || trim($from) === '=') {
            return true;
        }
        if (str_contains($from, self::LEGACY_SURFACE)) {
            return true;
        }
        if (preg_match('/^[=]?\s*Color\.(White|LightGray|LightGrey|Gray|Grey|DarkGray|DarkGrey)\b/i', trim($from)) === 1) {
            return true;
        }
        $parsed = ColorValue::parse($from);
        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
            if (ColorValue::saturation($parsed) <= 0.28) {
                return true;
            }
            if (ColorValue::isNeutralSurface($parsed)) {
                return true;
            }
            if ($force && ColorValue::saturation($parsed) <= 0.35) {
                return true;
            }
        }

        return !$this->shouldPreserveUserValue($from, 'Fill', $force, $theme, $var, $themeLight, $themeDark);
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
        $inkToken = $this->inputInkToken($colorStr, $theme);
        if ($inkToken !== null) {
            $to = $this->themeFormula($control, $theme, $inkToken);
            if ($to !== $colorStr) {
                $control->setProperty('Color', $to, 'Data');
                $report->add(self::id(), $control->path, 'Color', $colorStr !== '' ? $colorStr : '(unset)', $to);
            }
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

        $this->themeInputAppearance($control, $var, $report);
        $this->ensureInputHoverInk($control, $theme, $report);
    }

    /**
     * Classic DropDown / ComboBox / TextInput default HoverColor is black.
     * HoverFill is a dark surface in dark mode, so unset or near-black hover ink disappears.
     */
    private function ensureInputHoverInk(ControlNode $control, string $theme, Report $report): void
    {
        foreach (['HoverColor', 'PressedColor'] as $prop) {
            $from = $control->getProperty($prop);
            $fromStr = $from !== null ? (string) $from : '';
            if ($this->usesActiveThemeToken($fromStr, $theme)) {
                continue;
            }
            if (preg_match('/Self\\.Color\\b/i', $fromStr) === 1) {
                continue;
            }
            $parsed = $fromStr !== '' ? ColorValue::parse($fromStr) : null;
            $empty = $from === null || trim($fromStr) === '' || trim($fromStr) === '=';
            $darkInk = $parsed !== null && !ColorValue::isTransparent($parsed)
                && ColorValue::luminance($parsed) < 0.35
                && ColorValue::saturation($parsed) <= 0.35;
            if (!$empty && !$darkInk) {
                continue;
            }
            $to = $this->themeFormula($control, $theme, 'Text');
            if ($to === $fromStr) {
                continue;
            }
            $control->setProperty($prop, $to);
            $report->add(self::id(), $control->path, $prop, $empty ? '(unset)' : self::preview($fromStr), $to);
        }
    }

    /**
     * Color can be themed in an earlier pass while Fill stays unset (classic TextInput
     * template defaults). Without a light InputFill branch the field paints black.
     */
    private function ensureInputFillWhenThemed(
        ControlNode $control,
        string $theme,
        string $var,
        string $themeLight,
        string $themeDark,
        bool $force,
        Report $report,
    ): void {
        if (!$this->isInputControl($control)) {
            return;
        }
        $color = trim((string) ($control->getProperty('Color') ?? ''));
        if ($color === '' || !$this->usesActiveThemeToken($color, $theme)) {
            return;
        }
        $from = $control->getProperty('Fill');
        $fromStr = $from !== null ? (string) $from : '';
        if ($fromStr !== '' && str_contains($fromStr, 'gblDarkMode') && str_contains($fromStr, $theme . '.InputFill')) {
            return;
        }
        if ($fromStr !== '' && !$this->isThemeableInputFill($fromStr, $force, $theme, $var, $themeLight, $themeDark)) {
            return;
        }
        $to = $this->themePropertyFormula($control, $theme, $var, 'InputFill', $fromStr, 'Fill');
        if ($to === $fromStr) {
            return;
        }
        $control->setProperty('Fill', $to);
        $report->add(self::id(), $control->path, 'Fill', $fromStr !== '' ? $fromStr : '(unset)', $to);
    }

    /**
     * Typed input ink must be gblTheme.Text so it tracks InputFill in both themes.
     * White / TextOnAccent on a light InputFill is invisible in light mode.
     */
    private function inputInkToken(string $colorStr, string $theme): ?string
    {
        if ($colorStr === '' || trim($colorStr) === '=') {
            return 'Text';
        }
        if ($this->isPureThemeReference($colorStr, $theme)) {
            $trim = trim($colorStr);
            if (str_starts_with($trim, '=')) {
                $trim = trim(substr($trim, 1));
            }
            $token = substr($trim, strlen($theme) + 1);
            if (in_array($token, ['TextOnAccent', 'TextMuted', 'TextOnLight'], true)) {
                return 'Text';
            }

            return $token === 'Text' ? 'Text' : null;
        }
        if (preg_match('/^[=]?\s*Color\.(White|Black|Gray|Grey|LightGray|LightGrey|DarkGray|DarkGrey)\s*$/i', $colorStr) === 1) {
            return 'Text';
        }
        $parsed = ColorValue::parse($colorStr);
        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
            if (ColorValue::saturation($parsed) > 0.35) {
                return null;
            }

            return 'Text';
        }
        if (preg_match('/^[=]?\s*Color\.\w+\s*$/i', $colorStr) === 1) {
            return 'Text';
        }

        return null;
    }

    /**
     * Fluent FilledDarker is dark in both themes — only use it when gblDarkMode is true
     * so light mode keeps Outline/Filled (white/light field chrome).
     *
     * ModernCombobox YAML often omits Appearance entirely; JSON still has the template
     * default Appearance.FilledDarker. Both must be rewritten or Rank/Language stay
     * white-on-white in YAML and black-on-black in light-mode JSON.
     */
    private function themeInputAppearance(ControlNode $control, string $var, Report $report): void
    {
        $type = strtolower($control->type);
        $appearance = trim((string) ($control->getProperty('Appearance') ?? ''));
        $fluent = $this->isFluentPicker($control)
            || str_contains($type, 'modern')
            || str_contains($type, 'richtext')
            || str_contains($type, 'canvas')
            || preg_match('/Appearance\./i', $appearance) === 1;
        if (!$fluent && $appearance === '') {
            return;
        }
        $needsCanvasEnum = preg_match('/(?:combobox|dropdown|button|radio)@0\\.0\\./i', $type) === 1;
        if ($appearance !== '' && preg_match('/If\s*\(\s*' . preg_quote($var, '/') . '/i', $appearance) === 1
            && str_contains($appearance, 'FilledDarker')
        ) {
            if (!$needsCanvasEnum || str_contains($appearance, $this->appearanceEnum($control, 'FilledDarker'))) {
                return;
            }
        }

        $light = $this->lightAppearanceFallback($control, $appearance);
        $dark = $this->appearanceEnum($control, 'FilledDarker');
        $body = 'If(' . $var . ', ' . $dark . ', ' . $light . ')';
        $to = $control->format === 'yaml' ? '=' . $body : $body;
        if ($to === $appearance) {
            return;
        }
        $control->setProperty('Appearance', $to, 'Design');
        $report->add(self::id(), $control->path, 'Appearance', $appearance !== '' ? $appearance : '(unset)', $to);
    }

    private function lightAppearanceFallback(ControlNode $control, string $appearance): string
    {
        $body = trim(ltrim(trim($appearance), '='));
        if ($body === '' || str_contains($appearance, 'FilledDarker')) {
            return $this->appearanceEnum($control, 'Filled');
        }
        if (preg_match('/^Appearance\.\w+$/i', $body) === 1) {
            return $body;
        }
        if (preg_match('/^\'[A-Za-z0-9_.]+\\.Appearance\'\\.(\w+)$/i', $body, $m) === 1
            && strcasecmp($m[1], 'FilledDarker') !== 0
        ) {
            return $body;
        }

        return $this->appearanceEnum($control, 'Filled');
    }

    /**
     * Canvas Fluent controls (ComboBox@0.0.x) reject Appearance.Filled —
     * Studio reports "Name isn't valid. 'Filled' isn't recognized".
     * They need 'ComboBox.Appearance'.Filled (same pattern as ButtonCanvas).
     */
    private function appearanceEnum(ControlNode $control, string $value): string
    {
        $t = strtolower($control->type);
        if (preg_match('/combobox@0\\.0\\./i', $t) === 1) {
            return "'ComboBox.Appearance'." . $value;
        }
        if (preg_match('/dropdown@0\\.0\\./i', $t) === 1) {
            return "'DropDown.Appearance'." . $value;
        }
        if (preg_match('/textinput@0\\.0\\./i', $t) === 1) {
            return "'TextInput.Appearance'." . $value;
        }
        if (preg_match('/datepicker@0\\.0\\./i', $t) === 1) {
            return "'DatePicker.Appearance'." . $value;
        }
        if (preg_match('/button@0\\.0\\./i', $t) === 1) {
            return "'ButtonCanvas.Appearance'." . $value;
        }
        if (preg_match('/radio@0\\.0\\./i', $t) === 1) {
            return "'Radio.Appearance'." . $value;
        }

        return 'Appearance.' . $value;
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
        // Numbered step badges stay navy + white; DisabledFill would turn them into grey/black squares.
        if ($this->isButtonControl($control) && $this->isViewModeBadge($control)) {
            return;
        }
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
        if ($appearance === '' || !str_contains($appearance, $var)) {
            $this->themeInputAppearance($control, $var, $report);
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
     * Keep the maker's light-mode ink; only swap to the theme token when dark.
     */
    private function themeInkFormula(
        ControlNode $control,
        string $theme,
        string $var,
        string $token,
        ?string $from,
    ): string {
        $fromStr = $from ?? '';
        if ($fromStr !== '' && trim($fromStr) !== '' && trim($fromStr) !== '=') {
            $orig = $this->lightFallbackLiteral($fromStr, $token);
            if (preg_match('/RGBA\s*\(|\bColor\.[A-Za-z]+\b/i', $orig) === 1) {
                $body = 'If(' . $var . ', ' . $theme . '.' . $token . ', ' . $orig . ')';

                return $control->format === 'yaml' ? '=' . $body : $body;
            }
        }

        // Unset ink is Studio black. Keep that in light; invert only when dark.
        $body = 'If(' . $var . ', ' . $theme . '.' . $token . ', RGBA(0, 0, 0, 1))';

        return $control->format === 'yaml' ? '=' . $body : $body;
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
            && (ColorValue::isUniqueColorToken($token) || in_array($token, ['Success', 'Danger', 'Warning', 'Accent'], true))
        ) {
            $orig = $this->lightFallbackLiteral($from, $token);
            if (preg_match('/RGBA\s*\(|\bColor\.[A-Za-z]+\b/i', $orig) === 1) {
                // Classic has no Fluent rest-shade. The save button already
                // paints this darker green; the raw lime would glow once the
                // ButtonCanvas definition is dropped so Size stays put.
                if (preg_match('/RGBA\s*\(\s*8\s*,\s*222\s*,\s*8\b/i', $orig) === 1) {
                    $orig = 'RGBA(4, 122, 4, 1)';
                }
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
        if (preg_match('/^(Color|FontColor)$/i', $prop) === 1) {
            $orig = $this->lightFallbackLiteral($from, $token);
            if (preg_match('/RGBA\s*\(|\bColor\.[A-Za-z]+\b/i', $orig) === 1) {
                $body = 'If(' . $var . ', ' . $theme . '.' . $token . ', ' . $orig . ')';

                return $control->format === 'yaml' ? '=' . $body : $body;
            }
        }
        if (ColorValue::isUniqueColorToken($token) && preg_match('/Fill|BackgroundColor|BasePaletteColor/i', $prop) === 1) {
            $orig = $this->lightFallbackLiteral($from, $token);
            if (preg_match('/RGBA\s*\(|\bColor\.[A-Za-z]+\b/i', $orig) === 1) {
                $body = 'If(' . $var . ', ' . $theme . '.' . $token . ', ' . $orig . ')';

                return $control->format === 'yaml' ? '=' . $body : $body;
            }
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
        if ($this->isNavSelectionPlate($control) && $this->isLightSurfaceToken($token)) {
            $darkToken = 'SurfaceMuted';
        }
        $orig = $this->lightFallbackLiteral($from, $token);
        if ($this->isInputControl($control) && preg_match('/Fill|BackgroundColor/i', $prop) === 1) {
            $origParsed = ColorValue::parse($orig);
            if ($origParsed !== null && ColorValue::luminance($origParsed) < 0.40) {
                $orig = 'RGBA(255, 255, 255, 1)';
            }
        }
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
            default => ColorValue::isUniqueColorToken($token) ? $token : 'SurfaceAlt',
        };
    }

    private function pressedTokenForSurface(string $token): string
    {
        return match ($token) {
            'Success' => 'SuccessHover',
            'Warning' => 'WarningHover',
            'Danger' => 'DangerHover',
            'Accent' => 'AccentHover',
            default => ColorValue::isUniqueColorToken($token) ? $token : 'SurfaceAlt',
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
        if (preg_match('/\bColor\.[A-Za-z]+\b/i', $from, $m) === 1) {
            return $m[0];
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

        // If(!varReview, light, tint) and If(varX, …) keep the maker's branches;
        // rewriteEmbeddedRgba wraps the RGBA literals so dark mode can follow the palette.
        return preg_match(
            '/App\\.ActiveScreen|ThisItem\\.|varNewSelectedRole'
            . '|\\bvar[A-Z][A-Za-z0-9_]*\\s*='
            . '|!\\s*var[A-Z][A-Za-z0-9_]*\\b'
            . '|If\\s*\\(\\s*var[A-Z][A-Za-z0-9_]*/i',
            $from
        ) === 1;
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
                        $ink = $this->buttonInkTokenForFill($fill, $theme, $control);
                        $hoverInk = $ink === 'Link' ? 'Link' : ($ink === 'TextOnAccent' ? 'TextOnAccent' : 'Text');
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
                || ($fillToken !== null && ColorValue::isUniqueColorToken($fillToken))
                || ($parsed !== null && !ColorValue::isShade($parsed));
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

    /**
     * DropShadow.Regular/Bold is almost invisible on light cards and a hard box on
     * dark Page/Surface. Keep the author's light-mode intensity; drop or step down
     * in dark mode.
     *
     * @param list<ControlDocument> $documents
     */
    private function softenDarkShadows(array $documents, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $from = $control->getProperty('DropShadow');
                if ($from === null) {
                    continue;
                }
                $fromStr = trim((string) $from);
                if ($fromStr === '' || $fromStr === '=') {
                    continue;
                }
                if (preg_match('/None|false|^\\s*=?\\s*0\\s*$/i', $fromStr) === 1) {
                    continue;
                }
                if (preg_match('/If\s*\(\s*' . preg_quote($var, '/') . '/i', $fromStr) === 1) {
                    continue;
                }
                // The selected sidebar section is a white chip plus Regular shadow.
                // Softening that shadow to None removes the only highlight.
                if ($this->isNavSelectionPlate($control)) {
                    continue;
                }
                $light = trim(ltrim($fromStr, '='));
                $dark = $this->darkShadowFallback($light);
                $body = 'If(' . $var . ', ' . $dark . ', ' . $light . ')';
                $to = $control->format === 'yaml' ? '=' . $body : $body;
                if ($to === $fromStr) {
                    continue;
                }
                $control->setProperty('DropShadow', $to);
                $report->add(self::id(), $control->path, 'DropShadow', self::preview($fromStr), $to);
            }
        }
    }

    private function darkShadowFallback(string $light): string
    {
        if (preg_match('/DropShadow\.(Semibold|Bold|ExtraBold|Heavy)/i', $light) === 1) {
            return 'DropShadow.Light';
        }

        return 'DropShadow.None';
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
                    '/If\s*\(\s*' . preg_quote($var, '/') . '\s*,\s*' . preg_quote($theme, '/') . '\\.[A-Za-z_][A-Za-z0-9_]*\\s*,\\s*' . preg_quote($m[0], '/') . '/i',
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
        // Edit-mode banners and status washes sit inside If/Switch. Mapping them
        // by luminance alone turns the orange bar into SurfaceAlt grey.
        if (!ColorValue::isShade($parsed) && !ColorValue::isTransparent($parsed) && ColorValue::saturation($parsed) >= 0.25) {
            return ColorValue::uniqueColorToken($parsed);
        }
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
                $wrapped = 'If(' . self::VAR . ', ' . $theme . '.' . $token . ', ' . $enum . ')';
                $mark = "\x00W" . $token . "\x00";
                $protected = preg_replace(
                    '/If\s*\(\s*' . preg_quote(self::VAR, '/') . '\s*,\s*' . preg_quote($theme, '/') . '\.' . preg_quote($token, '/') . '\s*,\s*' . preg_quote($enum, '/') . '\s*\)/i',
                    $mark,
                    $chunk
                );
                $replaced = preg_replace(
                    '/\b' . preg_quote($enum, '/') . '\b/i',
                    $wrapped,
                    is_string($protected) ? $protected : $chunk
                );
                if (is_string($replaced)) {
                    $chunk = str_replace($mark, $wrapped, $replaced);
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

        if (ColorValue::isUniqueColorToken($token)) {
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
        $parsed = ColorValue::parse($from);
        if ($parsed !== null && !ColorValue::isTransparent($parsed) && ColorValue::isShade($parsed)) {
            return false;
        }

        return !ColorValue::isStudioDefault($from, $prop);
    }

    /** Mid-grey labels ("Step 3 of 4", field captions, LightGray placeholders) must become TextMuted. */
    private function isMutedHierarchyInk(string $from, string $prop): bool
    {
        if (preg_match('/^(Color|FontColor)$/i', $prop) !== 1) {
            return false;
        }
        $parsed = ColorValue::parse($from);
        if ($parsed === null || ColorValue::isTransparent($parsed)) {
            return false;
        }

        return ColorValue::isNeutralInk($parsed);
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

        $forced = [];
        foreach ($core as $token => $pair) {
            if (!in_array($token, self::AUTHOR_COLOR_TOKENS, true)) {
                $forced[$token] = $pair;
            }
        }
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
     * Fluent Size=0 (auto) survives modern→classic as a 0pt font. Restore FontSize
     * or the classic default so dark theme never hides labels/inputs.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairZeroFontSize(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp() || $control->isScreen() || $this->isIconControl($control)) {
                    continue;
                }
                $t = strtolower($control->type);
                $textLike = str_contains($t, 'label')
                    || str_contains($t, 'button')
                    || str_contains($t, 'text')
                    || str_contains($t, 'dropdown')
                    || str_contains($t, 'combobox')
                    || str_contains($t, 'datepicker')
                    || str_contains($t, 'checkbox')
                    || str_contains($t, 'radio')
                    || str_contains($t, 'toggle')
                    || str_contains($t, 'html');
                if (!$textLike) {
                    continue;
                }
                $size = $control->getProperty('Size');
                $body = $size !== null ? trim(ltrim(trim($size), '=')) : '';
                $missing = $body === '' && $this->isButtonControl($control);
                $zero = $body !== '' && is_numeric($body) && (float) $body <= 0.0;
                $enum = preg_match('/^Size\.(Small|Medium|Large|None)$/i', $body) === 1;
                if (!$missing && !$zero && !$enum) {
                    continue;
                }
                $font = $control->getProperty('FontSize');
                $fontBody = $font !== null ? trim(ltrim(trim($font), '=')) : '';
                $next = ($fontBody !== '' && is_numeric($fontBody) && (float) $fontBody > 0)
                    ? $fontBody
                    : ($this->isButtonControl($control) ? '10.5' : '14');
                $to = $control->format === 'yaml' ? '=' . $next : $next;
                if ($to === $size) {
                    continue;
                }
                $control->setProperty('Size', $to);
                $report->add(self::id(), $control->path, 'Size', $size !== null ? self::preview($size) : '(unset)', $to);
            }
        }
    }

    /**
     * Enregistrer is 12pt in both themes. Dark mode paints that label white, so
     * the same point size looks larger. Draw it at 10pt only while dark.
     *
     * @param list<ControlDocument> $documents
     */
    /**
     * THCEE Approvals shows the transmittal list only while varTransmitalContainer
     * is "1". Sidebar navigation does not run the loading screen that sets it.
     *
     * @param list<ControlDocument> $documents
     */
    private function openTransmittalList(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name !== 'THCEE Approvals') {
                    continue;
                }
                $on = $control->getProperty('OnVisible');
                if (!is_string($on) || trim($on) === '' || trim($on) === '=') {
                    continue;
                }
                if (str_contains($on, 'varTransmitalContainer')) {
                    continue;
                }
                $body = rtrim($on);
                if (!str_ends_with($body, ';')) {
                    $body .= ';';
                }
                $next = $body . "\nSet(varTransmitalContainer, \"1\");";
                if ($control->format === 'yaml' && !str_starts_with(ltrim($next), '=')) {
                    $next = '=' . ltrim($next);
                }
                $control->setProperty('OnVisible', $next);
                $report->add(self::id(), $control->path, 'OnVisible', self::preview($on), self::preview($next));
            }
        }
    }

    private function shrinkDarkSaveLabel(array $documents, string $var, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->isButtonControl($control)) {
                    continue;
                }
                $fill = (string) ($control->getProperty('Fill') ?? '');
                if (preg_match('/C8_222_8|8\s*,\s*222\s*,\s*8|4\s*,\s*122\s*,\s*4/', $fill) !== 1) {
                    continue;
                }
                $text = (string) ($control->getProperty('Text') ?? '');
                if (!preg_match('/Enregistrer|"Save"/', $text)) {
                    continue;
                }
                $eq = $control->format === 'yaml';
                foreach (['Size', 'FontSize'] as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null) {
                        continue;
                    }
                    $body = trim(ltrim(trim((string) $from), '='));
                    if (!preg_match('/^12(?:\.0+)?$/', $body)) {
                        continue;
                    }
                    $toBody = 'If(' . $var . ', 10, 12)';
                    $to = $eq ? '=' . $toBody : $toBody;
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, self::preview((string) $from), $to);
                }
                $weight = (string) ($control->getProperty('FontWeight') ?? '');
                if (preg_match('/Semibold/i', $weight) === 1 && !str_contains($weight, $var)) {
                    $toBody = 'If(' . $var . ', FontWeight.Normal, FontWeight.Semibold)';
                    $to = $eq ? '=' . $toBody : $toBody;
                    $control->setProperty('FontWeight', $to);
                    $report->add(self::id(), $control->path, 'FontWeight', self::preview($weight), $to);
                }
            }
        }
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
