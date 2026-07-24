<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ColorValue;
use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;

/**
 * Wire a dark-mode toggle and rewrite literal color properties for contrast-safe dark surfaces.
 *
 * - Ensures App.OnStart initializes gblDarkMode (default false)
 * - Reuses an existing settings/dark toggle when found; otherwise injects one on an intro screen
 * - Wraps literal Fill/Color/Border/… values as If(gblDarkMode, dark, light)
 */
final class EnableDarkModeHop implements HopInterface
{
    private const VAR = 'gblDarkMode';
    private const TOGGLE_NAME = 'tglPowerSweeperDarkMode';

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
        // Toggle / checkbox / choice
        'TrueFill',
        'FalseFill',
        'TrueHoverFill',
        'FalseHoverFill',
        'CheckmarkFill',
        'CheckboxBackgroundFill',
        'CheckboxBorderColor',
        // Gallery / list item surfaces
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
        // Misc chrome
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
        return 'Add a dark-mode toggle (settings/intro screen) and rewrite literal fills, text, borders, and related colors for accessible dark contrast via gblDarkMode.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $var = is_string($options['variable'] ?? null) && $options['variable'] !== ''
            ? (string) $options['variable']
            : self::VAR;
        $injectToggle = !array_key_exists('inject_toggle', $options) || (bool) $options['inject_toggle'];

        $app = $this->findApp($documents);
        if ($app !== null) {
            $before = (string) ($app->getProperty('OnStart') ?? '');
            $app->appendStatement('OnStart', 'Set(' . $var . ', false)');
            $after = (string) ($app->getProperty('OnStart') ?? '');
            if ($after !== $before) {
                $report->add(self::id(), $app->path, 'OnStart', $before !== '' ? $before : '(empty)', $after);
            }
        }

        $existingToggle = $this->findDarkToggle($documents, $var);
        if ($existingToggle !== null) {
            $this->wireToggle($existingToggle, $var, $report);
        } elseif ($injectToggle) {
            $screen = $this->pickIntroScreen($documents);
            if ($screen !== null && $screen->format === 'yaml') {
                $this->injectToggle($screen, $var, $report);
                // Reindex documents that own this screen so later scans see the toggle
                foreach ($documents as $doc) {
                    if (str_contains($screen->path, $doc->relativePath) || str_starts_with($screen->path, $doc->relativePath)) {
                        $doc->reindex();
                    }
                }
            }
        }

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
                    if ($this->alreadyThemed($from, $var)) {
                        continue;
                    }
                    $parsed = ColorValue::parse($from);
                    if ($parsed === null) {
                        continue;
                    }
                    if (ColorValue::isTransparent($parsed)) {
                        continue;
                    }

                    $role = ColorValue::roleForProperty($prop);
                    $dark = ColorValue::toDark($parsed, $role);
                    // Skip no-op mappings
                    if (
                        $dark['r'] === $parsed['r']
                        && $dark['g'] === $parsed['g']
                        && $dark['b'] === $parsed['b']
                        && abs($dark['a'] - $parsed['a']) < 0.001
                    ) {
                        continue;
                    }

                    $lightFx = ColorValue::formatRgba($parsed, false);
                    $darkFx = ColorValue::formatRgba($dark, false);
                    $wrapped = 'If(' . $var . ', ' . $darkFx . ', ' . $lightFx . ')';
                    $to = $control->format === 'yaml' ? '=' . $wrapped : $wrapped;
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $from, $to);
                }
            }
        }
    }

    /** @param list<ControlDocument> $documents */
    private function findApp(array $documents): ?ControlNode
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    return $control;
                }
            }
        }
        return null;
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
                // Settings-area toggle that already binds our variable
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

        $onCheck = 'Set(' . $var . ', true)';
        $onUncheck = 'Set(' . $var . ', false)';
        $beforeCheck = (string) ($toggle->getProperty('OnCheck') ?? '');
        $toggle->appendStatement('OnCheck', $onCheck);
        $afterCheck = (string) ($toggle->getProperty('OnCheck') ?? '');
        if ($afterCheck !== $beforeCheck) {
            $report->add(self::id(), $toggle->path, 'OnCheck', $beforeCheck !== '' ? $beforeCheck : '(empty)', $afterCheck);
        }

        $beforeUn = (string) ($toggle->getProperty('OnUncheck') ?? '');
        $toggle->appendStatement('OnUncheck', $onUncheck);
        $afterUn = (string) ($toggle->getProperty('OnUncheck') ?? '');
        if ($afterUn !== $beforeUn) {
            $report->add(self::id(), $toggle->path, 'OnUncheck', $beforeUn !== '' ? $beforeUn : '(empty)', $afterUn);
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
            foreach (['home', 'intro', 'welcome', 'start', 'landing', 'main', 'menu', 'settings', 'einstell'] as $i => $needle) {
                if (str_contains($n, $needle)) {
                    $score += 100 - $i;
                }
            }
            // Prefer screens that already look like settings hubs
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

    private function injectToggle(ControlNode $screen, string $var, Report $report): void
    {
        $screen->addYamlChild(self::TOGGLE_NAME, [
            'Control' => 'Classic/Toggle@2.1.0',
            'Properties' => [
                'Text' => '="Dark mode"',
                'AccessibleLabel' => '="Toggle dark mode"',
                'Tooltip' => '="Switch between light and dark theme"',
                'Default' => '=' . $var,
                'OnCheck' => '=Set(' . $var . ', true)',
                'OnUncheck' => '=Set(' . $var . ', false)',
                'X' => '=16',
                'Y' => '=16',
                'Width' => '=180',
                'Height' => '=40',
                'TrueFill' => '=RGBA(96, 165, 250, 1)',
                'FalseFill' => '=RGBA(80, 80, 80, 1)',
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

    private function alreadyThemed(string $formula, string $var): bool
    {
        $v = strtolower($formula);
        return str_contains($v, strtolower($var))
            || str_contains($v, 'gblappcolors')
            || str_contains($v, 'gbltheme')
            || str_contains($v, 'app.theme');
    }
}
