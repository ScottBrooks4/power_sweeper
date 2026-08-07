<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Scan a canvas .msapp and recommend an ordered hop sequence plus force mode.
 *
 * force_mode:
 * - all          → options.force=true on hops that honor force (overwrite existing)
 * - missing_only → options.force=false (fill gaps / preserve user values)
 */
final class AppProfileAdvisor
{
    /** Hops that honor options.force (must stay in sync with hop implementations). */
    public const FORCEABLE_HOPS = [
        'accessibility_labels',
        'tooltip_from_label',
        'enable_dark_mode',
        'unwhack_locale_formulas',
        'normalize_containers',
    ];

    /**
     * @return array{
     *   ok:true,
     *   recommended_profile:string,
     *   force_mode:string,
     *   force_mode_reason:string,
     *   reasons:list<string>,
     *   signals:array<string,mixed>,
     *   hops:list<array{id:string,options:array<string,mixed>}>,
     *   forceable_hops:list<string>
     * }
     */
    public function recommend(string $msappPath): array
    {
        $archive = new MsappArchive($msappPath);
        try {
            $archive->unpack();
            $documents = $archive->documents();
            $signals = $this->collectSignals($msappPath, $documents, $archive->extractDir());
            $plan = $this->buildPlan($signals);

            return [
                'ok' => true,
                'recommended_profile' => $plan['profile'],
                'force_mode' => $plan['force_mode'],
                'force_mode_reason' => $plan['force_mode_reason'],
                'reasons' => $plan['reasons'],
                'signals' => $signals,
                'hops' => $this->applyForceMode($plan['hops'], $plan['force_mode']),
                'forceable_hops' => self::FORCEABLE_HOPS,
            ];
        } finally {
            $archive->cleanup();
        }
    }

    /**
     * @param list<array{id:string,options?:array<string,mixed>}> $hops
     * @return list<array{id:string,options:array<string,mixed>}>
     */
    public function applyForceMode(array $hops, string $forceMode): array
    {
        $force = $forceMode === 'all';
        $forceable = array_fill_keys(self::FORCEABLE_HOPS, true);
        $out = [];
        foreach ($hops as $step) {
            if (!is_array($step) || empty($step['id'])) {
                continue;
            }
            $id = (string) $step['id'];
            $options = is_array($step['options'] ?? null) ? $step['options'] : [];
            if (isset($forceable[$id])) {
                $options['force'] = $force;
            }
            $out[] = ['id' => $id, 'options' => $options];
        }

        return $out;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, mixed>
     */
    private function collectSignals(string $msappPath, array $documents, string $extractDir): array
    {
        $localeHits = 0;
        $formulaSamples = 0;
        $genericNames = 0;
        $namedControls = 0;
        $interactive = 0;
        $missingLabel = 0;
        $missingTabIndex = 0;
        $missingFocus = 0;
        $containerChrome = 0;
        $containers = 0;
        $hasTheme = false;
        $themeToggle = false;
        $opaqueColors = 0;
        $themedColors = 0;

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    $onStart = (string) ($control->getProperty('OnStart') ?? '');
                    $formulas = (string) ($control->getProperty('Formulas') ?? '');
                    if (
                        str_contains($onStart, 'gblThemeLight')
                        || str_contains($onStart, '/* ps-theme:start */')
                        || str_contains($formulas, 'gblThemeLight')
                    ) {
                        $hasTheme = true;
                    }
                }
                if (
                    $control->name === 'tglPowerSweeperDarkMode'
                    || ($control->name === 'ThemeRadio' && str_contains((string) $control->getProperty('OnChange'), 'gblDarkMode'))
                    || (
                        str_contains(strtolower($control->type), 'radio')
                        && str_contains((string) $control->getProperty('OnChange'), 'gblThemeDark')
                    )
                ) {
                    $themeToggle = true;
                }

                if (!$control->isScreen() && !$control->isApp()) {
                    $namedControls++;
                    if (ControlNaming::isGenericName($control->name)) {
                        $genericNames++;
                    }
                }

                $type = strtolower($control->type);
                $isInteractive = str_contains($type, 'button')
                    || str_contains($type, 'textinput')
                    || str_contains($type, 'dropdown')
                    || str_contains($type, 'combobox')
                    || str_contains($type, 'checkbox')
                    || str_contains($type, 'toggle')
                    || str_contains($type, 'radio')
                    || str_contains($type, 'datepicker')
                    || str_contains($type, 'slider')
                    || str_contains($type, 'icon');
                if ($isInteractive) {
                    $interactive++;
                    $label = trim((string) ($control->getProperty('AccessibleLabel') ?? ''));
                    if ($label === '' || $label === '=""' || $label === '=Blank()') {
                        $missingLabel++;
                    }
                    $tab = trim((string) ($control->getProperty('TabIndex') ?? ''));
                    if ($tab === '') {
                        $missingTabIndex++;
                    }
                    $focus = trim((string) ($control->getProperty('FocusedBorderThickness') ?? ''));
                    if ($focus === '' || $focus === '0' || $focus === '=0') {
                        $missingFocus++;
                    }
                }

                if (str_contains($type, 'groupcontainer') || str_contains($type, 'container')) {
                    $containers++;
                    $shadow = (string) ($control->getProperty('DropShadow') ?? '');
                    $radius = (string) ($control->getProperty('BorderRadius') ?? '');
                    $pad = (string) ($control->getProperty('PaddingTop') ?? '');
                    if (
                        ($shadow !== '' && !str_contains($shadow, 'None'))
                        || ($radius !== '' && !preg_match('/^=?0\b/', $radius))
                        || ($pad !== '' && !preg_match('/^=?0\b/', $pad))
                    ) {
                        $containerChrome++;
                    }
                }

                foreach ($control->propertyNames() as $prop) {
                    $value = (string) ($control->getProperty($prop) ?? '');
                    if ($value === '') {
                        continue;
                    }
                    if (str_contains($value, 'gblTheme.')) {
                        $themedColors++;
                    }
                    if (preg_match('/Fill|Color|Border|FontColor/i', $prop) && ColorValue::parse($value) !== null) {
                        $parsed = ColorValue::parse($value);
                        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                            $opaqueColors++;
                        }
                    }
                    if ($formulaSamples < 400) {
                        $formulaSamples++;
                        if (FormulaLocaleNormalizer::looksLocaleCorrupted($value)) {
                            $localeHits++;
                        }
                    }
                }
            }
        }

        $scanner = new StudioIssueScanner();
        $scan = $scanner->scan($documents);
        $localeFromScan = (int) ($scan['by_kind']['locale_separators'] ?? 0);
        $boolIssues = (int) ($scan['by_kind']['expecting_boolean'] ?? 0)
            + (int) ($scan['by_kind']['expecting_boolean_checked'] ?? 0);

        $embedded = StudioErrorDetector::detectFromMsapp($msappPath, false);
        $formulaErr = 0;
        $a11ySarif = 0;
        $delegationHints = 0;
        $mangled = 0;
        foreach ($embedded['issues'] ?? [] as $issue) {
            $rule = (string) ($issue['ruleId'] ?? '');
            if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
                $formulaErr++;
            }
            if ($rule === 'app-formula-mangled-screen-ref') {
                $mangled++;
            }
            if (str_starts_with($rule, 'acc-')) {
                $a11ySarif++;
            }
            if (str_starts_with($rule, 'app-SuggestRemoteExecutionHint')) {
                $delegationHints++;
            }
        }

        // Live checker is accurate but heavier — use when SARIF is empty/stale.
        $liveFormulaErr = 0;
        $liveA11y = 0;
        if ($formulaErr === 0 && $a11ySarif === 0) {
            $live = StudioLiveChecker::check($documents, ['extract_dir' => $extractDir]);
            foreach ($live['findings'] as $f) {
                $rule = (string) ($f['ruleId'] ?? '');
                if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
                    $liveFormulaErr++;
                }
                if (str_starts_with($rule, 'acc-')) {
                    $liveA11y++;
                }
            }
        }

        $genericRatio = $namedControls > 0 ? $genericNames / $namedControls : 0.0;
        $labelMissingRatio = $interactive > 0 ? $missingLabel / $interactive : 0.0;

        return [
            'locale_hits' => max($localeHits, $localeFromScan),
            'bool_issues' => $boolIssues,
            'formula_errors' => max($formulaErr, $liveFormulaErr),
            'mangled_screen_refs' => $mangled,
            'delegation_hints' => $delegationHints,
            'a11y_sarif' => max($a11ySarif, $liveA11y),
            'interactive_controls' => $interactive,
            'missing_accessible_label' => $missingLabel,
            'missing_tab_index' => $missingTabIndex,
            'missing_focus_border' => $missingFocus,
            'label_missing_ratio' => round($labelMissingRatio, 3),
            'generic_names' => $genericNames,
            'named_controls' => $namedControls,
            'generic_ratio' => round($genericRatio, 3),
            'container_chrome' => $containerChrome,
            'containers' => $containers,
            'has_theme' => $hasTheme || $themeToggle || $themedColors > 5,
            'theme_toggle' => $themeToggle,
            'opaque_colors' => $opaqueColors,
            'themed_colors' => $themedColors,
            'embedded_sarif_total' => (int) ($embedded['total'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $signals
     * @return array{
     *   profile:string,
     *   force_mode:string,
     *   force_mode_reason:string,
     *   reasons:list<string>,
     *   hops:list<array{id:string,options?:array<string,mixed>}>
     * }
     */
    private function buildPlan(array $signals): array
    {
        $reasons = [];
        $studioRepair = include dirname(__DIR__) . '/profiles/includes/studio_repair_hops.php';
        if (!is_array($studioRepair)) {
            $studioRepair = [];
        }

        $needsRepair = ((int) $signals['formula_errors'] > 0)
            || ((int) $signals['locale_hits'] > 0)
            || ((int) $signals['bool_issues'] > 0)
            || ((int) $signals['mangled_screen_refs'] > 0);
        $needsA11y = ((float) $signals['label_missing_ratio'] >= 0.08)
            || ((int) $signals['missing_accessible_label'] >= 5)
            || ((int) $signals['a11y_sarif'] > 0)
            || ((int) $signals['missing_tab_index'] >= 5)
            || ((int) $signals['missing_focus_border'] >= 5);
        $needsNames = ((float) $signals['generic_ratio'] >= 0.12)
            || ((int) $signals['generic_names'] >= 15);
        $needsContainers = ((int) $signals['container_chrome'] >= 3);
        $hasTheme = (bool) $signals['has_theme'];
        $needsTheme = !$hasTheme && (
            $needsRepair
            || ((int) $signals['opaque_colors'] >= 25)
        );

        $hops = [];
        $profile = 'default';

        if ($needsNames) {
            $reasons[] = sprintf(
                'Many generic Studio names (%d / %d controls)',
                (int) $signals['generic_names'],
                (int) $signals['named_controls']
            );
        }
        if ((int) $signals['locale_hits'] > 0) {
            $reasons[] = 'Locale-corrupted formula separators detected';
        }
        if ((int) $signals['formula_errors'] > 0) {
            $reasons[] = sprintf('%d formula error(s) in checker inventory', (int) $signals['formula_errors']);
        }
        if ((int) $signals['bool_issues'] > 0) {
            $reasons[] = 'Checked/boolean formula issues detected';
        }
        if ($needsA11y) {
            $reasons[] = sprintf(
                'Accessibility gaps (missing labels ≈ %d%% of interactive controls)',
                (int) round(((float) $signals['label_missing_ratio']) * 100)
            );
        }
        if ($needsContainers) {
            $reasons[] = 'Default container chrome still present';
        }
        if ($needsTheme) {
            $reasons[] = 'No gblTheme palette detected — include dark-mode theming';
        } elseif ($hasTheme) {
            $reasons[] = 'Theme palettes already present — skip re-theme unless forced';
        }

        if ($needsRepair) {
            if ($needsNames) {
                $hops[] = ['id' => 'meaningful_names', 'options' => ['only_generic' => true]];
                $hops = array_merge($hops, $studioRepair);
                $profile = $needsTheme ? 'repair_powered' : 'repair_smart';
            } else {
                $hops = $studioRepair;
                $profile = $needsTheme ? 'repair_powered' : 'repair_studio_errors';
            }
            if ($needsContainers) {
                $hops[] = ['id' => 'normalize_containers', 'options' => []];
                $hops[] = ['id' => 'strip_default_fill', 'options' => []];
            }
            if ($needsTheme) {
                $hops[] = ['id' => 'enable_dark_mode', 'options' => []];
            }
        } else {
            // Light / modular cleanup
            if ((int) $signals['locale_hits'] > 0) {
                $hops[] = ['id' => 'unwhack_locale_formulas', 'options' => []];
                $profile = 'unwhack_locale';
            }
            if ($needsNames) {
                array_unshift($hops, ['id' => 'meaningful_names', 'options' => ['only_generic' => true]]);
            }
            if ($needsContainers) {
                $hops[] = ['id' => 'normalize_containers', 'options' => []];
                $hops[] = ['id' => 'strip_default_fill', 'options' => []];
            }
            $hops[] = ['id' => 'align_near_miss', 'options' => ['tolerance' => 3]];
            if ($needsA11y) {
                $hops[] = ['id' => 'accessibility_labels', 'options' => []];
                $hops[] = ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]];
                $hops[] = ['id' => 'ensure_tab_index', 'options' => ['value' => 0]];
                $hops[] = ['id' => 'tooltip_from_label', 'options' => []];
                $profile = 'a11y_pass';
            } elseif ($hops === []) {
                $hops = [
                    ['id' => 'normalize_containers', 'options' => []],
                    ['id' => 'align_near_miss', 'options' => ['tolerance' => 3]],
                    ['id' => 'accessibility_labels', 'options' => []],
                ];
                $profile = 'default';
                $reasons[] = 'No major issues — balanced default cleanup';
            }
            if ($needsTheme) {
                $hops[] = ['id' => 'enable_dark_mode', 'options' => []];
                $profile = 'repair_studio_errors_then_dark';
            }
        }

        // Deduplicate consecutive identical hop ids while preserving intentional double_qualified repeats in studio chain.
        // (studio_repair_hops intentionally repeats repair_double_qualified_refs — keep as-is)

        $forceMode = 'missing_only';
        $forceReason = 'Preserve existing labels, theme colors, and container values; fill gaps only.';
        if ($needsTheme && !$hasTheme) {
            $forceMode = 'all';
            $forceReason = 'App has no theme yet — force full palette/chrome application for a complete powered pass.';
        } elseif (((float) $signals['label_missing_ratio']) >= 0.55 && ((int) $signals['interactive_controls'] >= 20)) {
            $forceMode = 'all';
            $forceReason = 'Most interactive controls lack AccessibleLabel — overwrite/fill aggressively.';
        } elseif ($hasTheme || ((float) $signals['label_missing_ratio']) < 0.2) {
            $forceMode = 'missing_only';
            $forceReason = $hasTheme
                ? 'Theme already present — avoid re-theming literals; fill missing a11y/chrome only.'
                : 'Most labels already set — only fill missing values.';
        }

        if ($reasons === []) {
            $reasons[] = 'Heuristic scan complete';
        }

        return [
            'profile' => $profile,
            'force_mode' => $forceMode,
            'force_mode_reason' => $forceReason,
            'reasons' => $reasons,
            'hops' => $hops,
        ];
    }
}
