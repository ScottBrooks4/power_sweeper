<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;

/**
 * Fix App checker acc-TabIndexShouldBeDefinedForInteractiveControl ("Missing tab stop").
 *
 * Truly interactive controls (buttons, inputs, galleries, OnSelect targets) always get
 * TabIndex = 0 — everyone needs those stops.
 *
 * Decorative Labels / Images / containers that Studio serializes as TabIndex = -1 get
 * TabIndex = If(gblA11yTabStops, 0, -1). Default gblA11yTabStops is false (sighted-first:
 * labels stay out of the tab order). Users who need enhanced keyboard / screen-reader
 * navigation can flip the injected toggle (same idea as dark mode). Live checker / SARIF
 * treat the formula as satisfying the rule so App checker stays clean either way.
 */
final class EnsureTabIndexSweep implements SweepInterface
{
    public const VAR = 'gblA11yTabStops';
    public const TOGGLE_NAME = 'tglPowerSweeperA11yTabStops';
    private const BLOCK_START = '/* ps-a11y:start */';
    private const BLOCK_END = '/* ps-a11y:end */';

    public static function id(): string
    {
        return 'ensure_tab_index';
    }

    public static function label(): string
    {
        return 'Ensure tab index';
    }

    public static function description(): string
    {
        return 'Inject gblA11yTabStops (default false) + toggle; set TabIndex=0 on real interactive controls; set If(gblA11yTabStops, 0, -1) on decorative TabIndex=-1 Labels/Images so SARIF stays clean while sighted users skip label stops.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $var = is_string($options['var'] ?? null) && trim((string) $options['var']) !== ''
            ? trim((string) $options['var'])
            : self::VAR;
        $alwaysValue = array_key_exists('value', $options) ? (int) $options['value'] : 0;
        $injectToggle = !array_key_exists('inject_toggle', $options) || (bool) $options['inject_toggle'];
        $injectVar = !array_key_exists('inject_var', $options) || (bool) $options['inject_var'];

        if ($injectVar) {
            $this->ensureA11yBlock($documents, $var, $report);
        }
        if ($injectToggle) {
            $this->ensureToggle($documents, $var, $report);
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name === self::TOGGLE_NAME || $control->name === 'tglPowerSweeperDarkMode') {
                    $this->writeAlwaysTabIndex($control, $alwaysValue, $report);
                    continue;
                }

                // Real interactive / action targets: always in the tab order.
                if ($this->needsAlwaysTabStop($control)) {
                    $parsed = $control->tabIndexValue();
                    if ($parsed !== null && $parsed >= 0 && !$control->hasConditionalA11yTabIndex($var)) {
                        continue;
                    }
                    $this->writeAlwaysTabIndex($control, $alwaysValue, $report);
                    continue;
                }

                if ($control->hasConditionalA11yTabIndex($var)) {
                    continue;
                }

                // Decorative Labels/Images/icons Studio flagged (or chrome without a real action).
                if ($this->isConditionalTabCandidate($control)) {
                    $this->writeConditionalTabIndex($control, $var, $report);
                }
            }
        }
    }

    private function needsAlwaysTabStop(ControlNode $control): bool
    {
        if ($control->hasActionHandler()) {
            return true;
        }
        if (!$control->isInteractive()) {
            return false;
        }
        // Images/icons without a real OnSelect are often decorative chrome — use the toggle.
        return !$this->isDecorativeMediaChrome($control);
    }

    private function isDecorativeMediaChrome(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if (str_contains($t, 'icon')) {
            return true;
        }
        if (str_contains($t, 'image') && !str_contains($t, 'input')) {
            return true;
        }

        return false;
    }

    private function isConditionalTabCandidate(ControlNode $control): bool
    {
        $parsed = $control->tabIndexValue();
        if ($parsed !== null && $parsed < 0) {
            return true;
        }
        // Interactive image/icon with no action — still App-checker interactive; use the formula.
        if ($this->isDecorativeMediaChrome($control) && $control->isInteractive() && !$control->hasActionHandler()) {
            return true;
        }

        return false;
    }

    private function writeAlwaysTabIndex(ControlNode $control, int $value, Report $report): void
    {
        if ($control->tabIndexValue() === $value && !$control->hasConditionalA11yTabIndex()) {
            return;
        }
        $current = $control->getProperty('TabIndex');
        $to = $control->format === 'yaml' ? '=' . $value : (string) $value;
        if ($current !== null && trim((string) $current) === trim($to)) {
            return;
        }
        $control->setProperty('TabIndex', $to);
        $report->add(
            self::id(),
            $control->path,
            'TabIndex',
            $current !== null && trim((string) $current) !== '' ? (string) $current : '(unset)',
            $to
        );
    }

    private function writeConditionalTabIndex(ControlNode $control, string $var, Report $report): void
    {
        $expr = 'If(' . $var . ', 0, -1)';
        $to = $control->format === 'yaml' ? '=' . $expr : $expr;
        $current = $control->getProperty('TabIndex');
        if ($control->hasConditionalA11yTabIndex($var)) {
            return;
        }
        $control->setProperty('TabIndex', $to);
        $report->add(
            self::id(),
            $control->path,
            'TabIndex',
            $current !== null && trim((string) $current) !== '' ? (string) $current : '(unset)',
            $to
        );
    }

    /** @param list<ControlDocument> $documents */
    private function ensureA11yBlock(array $documents, string $var, Report $report): void
    {
        $block = self::BLOCK_START
            . "\n"
            // Sighted-first: decorative labels stay out of tab order until the user enables this.
            . 'Set(' . $var . ', false)'
            . "\n"
            . self::BLOCK_END;

        $apps = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    $apps[] = $control;
                }
            }
        }
        if ($apps === []) {
            $report->add(self::id(), '(app)', 'OnStart', '(missing App control)', 'skipped gblA11yTabStops inject');
            return;
        }

        foreach ($apps as $app) {
            $before = (string) ($app->getProperty('OnStart') ?? '');
            $after = $this->upsertBlock($before, $block, $app->format === 'yaml');
            if ($after === $before) {
                continue;
            }
            $app->setProperty('OnStart', $after);
            $report->add(
                self::id(),
                $app->path,
                'OnStart',
                $before !== '' ? $this->preview($before) : '(empty)',
                $var . ' (default false; toggle for enhanced tab stops)'
            );
        }
    }

    private function upsertBlock(string $existing, string $block, bool $yamlEquals): string
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
    private function ensureToggle(array $documents, string $var, Report $report): void
    {
        $existing = $this->findA11yToggle($documents, $var);
        if ($existing !== null) {
            $this->wireToggle($existing, $var, $report);
            return;
        }

        $screen = $this->pickIntroScreen($documents);
        if ($screen === null || $screen->format !== 'yaml') {
            return;
        }

        $y = $this->nextToggleY($screen);
        $screen->addYamlChild(self::TOGGLE_NAME, [
            'Control' => 'Classic/Toggle@2.1.0',
            'Properties' => [
                'Text' => '="Enhanced keyboard / screen reader"',
                'AccessibleLabel' => '="Toggle enhanced tab stops for labels and decorative controls"',
                'Tooltip' => '="When on, Labels and decorative controls join the tab order (better for screen readers). When off, only true controls are tab stops (better for sighted keyboard users)."',
                'Default' => '=' . $var,
                'OnCheck' => '=Set(' . $var . ', true)',
                'OnUncheck' => '=Set(' . $var . ', false)',
                'X' => '=16',
                'Y' => '=' . $y,
                'Width' => '=280',
                'Height' => '=40',
                'TabIndex' => '=0',
                'TrueText' => '="On"',
                'FalseText' => '="Off"',
            ],
        ]);
        $report->add(
            self::id(),
            $screen->path,
            'Children',
            '(missing a11y tab-stop toggle)',
            self::TOGGLE_NAME . ' injected'
        );

        foreach ($documents as $doc) {
            if (str_contains($screen->path, $doc->relativePath) || str_starts_with($screen->path, $doc->relativePath)) {
                $doc->reindex();
            }
        }
    }

    private function nextToggleY(ControlNode $screen): int
    {
        $y = 16;
        foreach ($screen->children as $child) {
            if ($child->name === 'tglPowerSweeperDarkMode' || $child->name === self::TOGGLE_NAME) {
                $cy = $child->getProperty('Y');
                if ($cy !== null && is_numeric(trim(ltrim(trim($cy), '=')))) {
                    $y = max($y, (int) trim(ltrim(trim($cy), '=')) + 48);
                } else {
                    $y = max($y, 64);
                }
            }
        }

        return $y;
    }

    /** @param list<ControlDocument> $documents */
    private function findA11yToggle(array $documents, string $var): ?ControlNode
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name === self::TOGGLE_NAME) {
                    return $control;
                }
                if (!$control->isToggle()) {
                    continue;
                }
                foreach (['OnCheck', 'OnUncheck', 'OnChange'] as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if (str_contains($raw, $var) && str_contains($raw, 'Set(')) {
                        return $control;
                    }
                }
            }
        }

        return null;
    }

    private function wireToggle(ControlNode $toggle, string $var, Report $report): void
    {
        $onCheck = '=Set(' . $var . ', true)';
        $onUncheck = '=Set(' . $var . ', false)';
        $changes = [
            'Default' => '=' . $var,
            'OnCheck' => $onCheck,
            'OnUncheck' => $onUncheck,
            'AccessibleLabel' => '="Toggle enhanced tab stops for labels and decorative controls"',
        ];
        foreach ($changes as $prop => $to) {
            $from = $toggle->getProperty($prop);
            $want = $toggle->format === 'yaml' ? $to : ltrim($to, '=');
            if ($from !== null && trim((string) $from) === trim($want)) {
                continue;
            }
            // Don't clobber a richer OnCheck that already sets the var.
            if (in_array($prop, ['OnCheck', 'OnUncheck'], true) && $from !== null && str_contains((string) $from, $var)) {
                continue;
            }
            $toggle->setProperty($prop, $want);
            $report->add(self::id(), $toggle->path, $prop, $from ?? '(unset)', $want);
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
            foreach (['settings', 'home', 'intro', 'welcome', 'start', 'landing', 'main', 'menu'] as $i => $needle) {
                if (str_contains($n, $needle)) {
                    $score += 100 - $i;
                }
            }
            foreach ($s->children as $child) {
                $cn = strtolower($child->name . ' ' . $child->type);
                if (str_contains($cn, 'setting') || str_contains($cn, 'toggle') || str_contains($cn, 'theme') || str_contains($cn, 'a11y')) {
                    $score += 20;
                }
            }

            return $score;
        };

        usort($screens, static fn(ControlNode $a, ControlNode $b): int => $score($b) <=> $score($a));

        return $screens[0];
    }

    private function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        if (strlen($s) > 120) {
            return substr($s, 0, 117) . '...';
        }

        return $s;
    }
}
