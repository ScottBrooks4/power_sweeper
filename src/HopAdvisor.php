<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Scan a canvas .msapp and recommend an ordered hop sequence plus force mode.
 *
 * Only hops that are expected to change something are included. Empty is OK.
 *
 * force_mode:
 * - all          → options.force=true on hops that honor force (overwrite existing)
 * - missing_only → options.force=false (fill gaps / preserve user values)
 */
final class HopAdvisor
{
    /** Hops that honor options.force (must stay in sync with hop implementations). */
    public const FORCEABLE_HOPS = [
        'accessibility_labels',
        'tooltip_from_label',
        'enable_dark_mode',
        'translate',
        'unwhack_locale_formulas',
        'normalize_containers',
    ];

    /**
     * @param null|callable(array{type:string,message:string,phase?:string}):void $onProgress
     * @return array{
     *   ok:true,
     *   force_mode:string,
     *   force_mode_reason:string,
     *   reasons:list<string>,
     *   signals:array<string,mixed>,
     *   hops:list<array{id:string,options:array<string,mixed>}>,
     *   forceable_hops:list<string>
     * }
     */
    public function recommend(string $msappPath, ?callable $onProgress = null): array
    {
        $emit = static function (string $message, string $phase = 'scan') use ($onProgress): void {
            if ($onProgress === null) {
                return;
            }
            $onProgress([
                'type' => 'progress',
                'phase' => $phase,
                'message' => $message,
            ]);
        };

        $archive = new MsappArchive($msappPath);
        try {
            $emit('Unpacking .msapp package…', 'unpack');
            $archive->unpack();
            $emit('Reading screens, components, and controls…', 'catalog');
            $documents = $archive->documents();
            $complexity = AppComplexity::measure($msappPath, $documents, $archive->extractDir());
            $docCount = count($documents);
            $emit(
                $docCount === 1
                    ? 'Scanning 1 document for repair signals…'
                    : sprintf('Scanning %d documents for repair signals…', $docCount),
                'signals'
            );
            $signals = $this->collectSignals($msappPath, $documents, $archive->extractDir(), $emit);
            $emit('Choosing hop sequence and write mode…', 'plan');
            $plan = $this->buildPlan($signals);
            $hops = $this->applyForceMode($plan['hops'], $plan['force_mode']);
            $emit(
                sprintf(
                    'Selected %d hop%s.',
                    count($hops),
                    count($hops) === 1 ? '' : 's'
                ),
                'done'
            );

            return [
                'ok' => true,
                'force_mode' => $plan['force_mode'],
                'force_mode_reason' => $plan['force_mode_reason'],
                'reasons' => $plan['reasons'],
                'signals' => $signals,
                'complexity' => $complexity,
                'hops' => $hops,
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
     * @param null|callable(string,string):void $emit
     * @return array<string, mixed>
     */
    private function collectSignals(string $msappPath, array $documents, string $extractDir, ?callable $emit = null): array
    {
        $localeHits = 0;
        $genericNames = 0;
        $namedControls = 0;
        $interactive = 0;
        $missingLabel = 0;
        $missingTabIndex = 0;
        $missingFocus = 0;
        $missingTooltip = 0;
        $containerChrome = 0;
        $containers = 0;
        $whiteContainerFills = 0;
        $hasTheme = false;
        $themeToggle = false;
        $opaqueColors = 0;
        $themedColors = 0;
        $modernThemeable = 0;
        $hasI18n = false;
        $literalTexts = 0;
        $hasLangControl = false;
        $doubleQualifiedHits = 0;
        $syntaxHits = 0;
        $ghostSeedHits = 0;
        $galleryDelayIssues = 0;
        $maxRowsHigh = 0;

        $themeMap = $this->themeControlMap();
        $ghostSeeds = array_fill_keys([
            'OneTimeVisit', 'RecurringVisit', 'EmergencyVisit', 'AmendmentVisit',
            'GovernmentInitiave', 'CommercialInitiave',
        ], true);

        $emit?->__invoke('Checking accessibility, theme, and formula patterns…', 'signals');

        $catalog = AppControlCatalog::build($documents);
        $knownNames = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $c) {
                $knownNames[$c->name] = true;
            }
        }

        foreach ($documents as $doc) {
            $screenLabel = $doc->relativePath !== '' ? basename($doc->relativePath) : 'document';
            $emit?->__invoke('Scanning ' . $screenLabel . '…', 'signals');
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
                    if (
                        str_contains($onStart, 'gblStrings')
                        || str_contains($onStart, '/* ps-i18n:start */')
                        || str_contains($formulas, 'gblStrings')
                        || str_contains($onStart, 'varTranslations')
                    ) {
                        $hasI18n = true;
                    }
                    $maxRows = $control->getProperty('MaxDataRowCount') ?? $control->getProperty('DataRowLimit');
                    if ($maxRows !== null && is_numeric(trim(ltrim(trim((string) $maxRows), '='))) && (float) $maxRows > 500) {
                        $maxRowsHigh++;
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
                $langBlob = strtolower(
                    $control->name . ' '
                    . (string) ($control->getProperty('Items') ?? '')
                    . ' '
                    . (string) ($control->getProperty('OnChange') ?? '')
                );
                if (
                    (str_contains($langBlob, 'english') && str_contains($langBlob, 'french'))
                    || str_contains($langBlob, 'varlang')
                    || str_contains($langBlob, 'language')
                ) {
                    $hasLangControl = true;
                }

                if (!$control->isScreen() && !$control->isApp()) {
                    $namedControls++;
                    if (ControlNaming::isGenericName($control->name)) {
                        $genericNames++;
                    }
                }

                if ($this->matchesThemeableModern($control->type, $themeMap)) {
                    $modernThemeable++;
                }

                if ($control->isInteractive()) {
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

                $type = strtolower($control->type);
                if (
                    (str_contains($type, 'button') || str_contains($type, 'icon') || str_contains($type, 'image'))
                    && ControlNaming::isBlank($control->getProperty('Tooltip'))
                ) {
                    $missingTooltip++;
                }

                if ($control->isContainer()) {
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
                    $fill = $control->getProperty('Fill');
                    if ($fill !== null && $this->isDefaultOpaqueFill($fill)) {
                        $whiteContainerFills++;
                    }
                }

                if (str_contains($type, 'gallery')) {
                    $delay = $control->getProperty('DelayItemLoading');
                    if ($delay === null || in_array(strtolower(trim(ltrim(trim((string) $delay), '='))), ['false', '0', ''], true)) {
                        $galleryDelayIssues++;
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
                    if (str_contains($value, 'gblStrings.') || str_contains($value, 'comTranslations.Labels')) {
                        $hasI18n = true;
                    }
                    if (
                        in_array($prop, ['Text', 'HintText', 'Tooltip', 'TrueText', 'FalseText'], true)
                        && preg_match('/^=?\s*"[^"]+"\s*$/', trim($value))
                    ) {
                        $literalTexts++;
                    }
                    if (preg_match('/Fill|Color|Border|FontColor/i', $prop) && ColorValue::parse($value) !== null) {
                        $parsed = ColorValue::parse($value);
                        if ($parsed !== null && !ColorValue::isTransparent($parsed)) {
                            $opaqueColors++;
                        }
                    }
                    if (FormulaLocaleNormalizer::looksLocaleCorrupted($value)) {
                        $localeHits++;
                    }
                    if (preg_match("/'([^']+)'\s*\.\s*'\\1'/", $value)) {
                        $doubleQualifiedHits++;
                    }
                    if (
                        preg_match('/""\s*,\s*\)/', $value)
                        || preg_match('/\)\s*,\s*\)/', $value)
                        || preg_match("/'[^']+'\s*\.\s*Date\s*\(/i", $value)
                        || preg_match('/\bvarNewRequest\b/', $value)
                    ) {
                        $syntaxHits++;
                    }
                    foreach ($ghostSeeds as $seed => $_) {
                        if (str_contains($value, $seed) && !isset($knownNames[$seed])) {
                            $ghostSeedHits++;
                            break;
                        }
                    }
                    if (preg_match('/\b([A-Za-z_][\w-]*)\s*:\s*\1\s*\./', $value, $m)) {
                        if (!isset($knownNames[$m[1]])) {
                            $ghostSeedHits++;
                        }
                    }
                }
            }
        }

        $scanIssues = StudioIssueScanner::scan($documents);
        $scanByKind = [];
        foreach ($scanIssues as $issue) {
            $kind = (string) ($issue['kind'] ?? '');
            if ($kind === '') {
                continue;
            }
            $scanByKind[$kind] = ($scanByKind[$kind] ?? 0) + 1;
        }
        $localeFromScan = (int) ($scanByKind['locale_separators'] ?? 0);
        $boolIssues = (int) ($scanByKind['expecting_boolean'] ?? 0)
            + (int) ($scanByKind['expecting_boolean_if_numeric'] ?? 0);

        $embedded = StudioErrorDetector::detectFromMsapp($msappPath, false);
        $byRule = [];
        $formulaErr = 0;
        $a11ySarif = 0;
        $delegationHints = 0;
        $mangled = 0;
        $refErrors = 0;
        $columnErrors = 0;
        $boolSarif = 0;
        $maintSarif = 0;
        foreach ($embedded['issues'] ?? [] as $issue) {
            $rule = (string) ($issue['ruleId'] ?? '');
            if ($rule === '') {
                continue;
            }
            $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
            if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
                $formulaErr++;
            }
            if ($rule === 'app-formula-mangled-screen-ref') {
                $mangled++;
            }
            if (in_array($rule, ['app-ErrInvalidName', 'app-ErrInvalidDot', 'app-formula-mangled-screen-ref'], true)) {
                $refErrors++;
            }
            if ($rule === 'app-ErrColDNE-Name') {
                $columnErrors++;
            }
            if ($rule === 'app-WarnBooleanExpected') {
                $boolSarif++;
            }
            if (str_starts_with($rule, 'acc-')) {
                $a11ySarif++;
            }
            if (str_starts_with($rule, 'app-SuggestRemoteExecutionHint')) {
                $delegationHints++;
            }
            if (in_array($rule, [
                'app-UnusedVariables',
                'app-DataSourceDefaultMaxRowsLimit',
                'app-InefficientDelayLoading',
                'app-CrossScreenEventDependencies',
            ], true)) {
                $maintSarif++;
            }
        }

        // Live checker is accurate but heavier — use when SARIF is empty/stale.
        $liveFormulaErr = 0;
        $liveA11y = 0;
        if ($formulaErr === 0 && $a11ySarif === 0) {
            $emit?->__invoke('Running live formula checker (no embedded SARIF inventory)…', 'signals');
            $live = StudioLiveChecker::check($documents, ['extract_dir' => $extractDir]);
            foreach ($live['findings'] as $f) {
                $rule = (string) ($f['ruleId'] ?? '');
                if ($rule === '') {
                    continue;
                }
                $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
                if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
                    $liveFormulaErr++;
                }
                if ($rule === 'app-formula-mangled-screen-ref') {
                    $mangled++;
                }
                if (in_array($rule, ['app-ErrInvalidName', 'app-ErrInvalidDot', 'app-formula-mangled-screen-ref'], true)) {
                    $refErrors++;
                }
                if ($rule === 'app-ErrColDNE-Name') {
                    $columnErrors++;
                }
                if ($rule === 'app-WarnBooleanExpected') {
                    $boolSarif++;
                }
                if (str_starts_with($rule, 'acc-')) {
                    $liveA11y++;
                }
                if (str_starts_with($rule, 'app-SuggestRemoteExecutionHint')) {
                    $delegationHints++;
                }
                if (in_array($rule, [
                    'app-UnusedVariables',
                    'app-DataSourceDefaultMaxRowsLimit',
                    'app-InefficientDelayLoading',
                    'app-CrossScreenEventDependencies',
                ], true)) {
                    $maintSarif++;
                }
            }
        }

        $emit?->__invoke('Validating unresolved refs and package shape…', 'signals');
        $post = StudioPostRepairValidator::validate($documents, ['extract_dir' => $extractDir]);
        $unresolvedRefs = (int) ($post['by_kind']['unresolved_control_ref'] ?? 0);
        $missingPackageFields = (int) ($post['by_kind']['missing_package_field'] ?? 0);
        $delegationFromPost = (int) ($post['by_kind']['delegation_warning'] ?? 0);
        $delayFromPost = (int) ($post['by_kind']['inefficient_delay_loading'] ?? 0);

        $genericRatio = $namedControls > 0 ? $genericNames / $namedControls : 0.0;
        $labelMissingRatio = $interactive > 0 ? $missingLabel / $interactive : 0.0;
        $formulaErrors = max($formulaErr, $liveFormulaErr);

        return [
            'locale_hits' => max($localeHits, $localeFromScan),
            'bool_issues' => max($boolIssues, $boolSarif),
            'formula_errors' => $formulaErrors,
            'mangled_screen_refs' => $mangled,
            'ref_errors' => $refErrors,
            'unresolved_control_refs' => $unresolvedRefs,
            'column_errors' => $columnErrors,
            'missing_package_fields' => $missingPackageFields,
            'double_qualified_hits' => $doubleQualifiedHits,
            'syntax_hits' => $syntaxHits,
            'ghost_hits' => $ghostSeedHits,
            'delegation_hints' => max($delegationHints, $delegationFromPost),
            'maintainability_hits' => $maintSarif + $galleryDelayIssues + $delayFromPost + $maxRowsHigh,
            'a11y_sarif' => max($a11ySarif, $liveA11y),
            'interactive_controls' => $interactive,
            'missing_accessible_label' => $missingLabel,
            'missing_tab_index' => $missingTabIndex,
            'missing_focus_border' => $missingFocus,
            'missing_tooltip' => $missingTooltip,
            'label_missing_ratio' => round($labelMissingRatio, 3),
            'generic_names' => $genericNames,
            'named_controls' => $namedControls,
            'generic_ratio' => round($genericRatio, 3),
            'container_chrome' => $containerChrome,
            'containers' => $containers,
            'white_container_fills' => $whiteContainerFills,
            'modern_themeable_controls' => $modernThemeable,
            'has_theme' => $hasTheme || $themeToggle || $themedColors > 5,
            'theme_toggle' => $themeToggle,
            'opaque_colors' => $opaqueColors,
            'themed_colors' => $themedColors,
            'has_i18n' => $hasI18n,
            'literal_texts' => $literalTexts,
            'has_lang_control' => $hasLangControl,
            'embedded_sarif_total' => (int) ($embedded['total'] ?? 0),
            'by_rule' => $byRule,
            'catalog_screens' => count($catalog->screenNames()),
        ];
    }

    /**
     * @param array<string, mixed> $signals
     * @return array{
     *   force_mode:string,
     *   force_mode_reason:string,
     *   reasons:list<string>,
     *   hops:list<array{id:string,options?:array<string,mixed>}>
     * }
     */
    private function buildPlan(array $signals): array
    {
        $reasons = [];
        $hops = [];

        $localeHits = (int) $signals['locale_hits'];
        $boolIssues = (int) $signals['bool_issues'];
        $formulaErrors = (int) $signals['formula_errors'];
        $mangled = (int) $signals['mangled_screen_refs'];
        $refErrors = (int) $signals['ref_errors'];
        $unresolved = (int) $signals['unresolved_control_refs'];
        $columnErrors = (int) $signals['column_errors'];
        $missingPackage = (int) $signals['missing_package_fields'];
        $doubleQualified = (int) $signals['double_qualified_hits'];
        $syntaxHits = (int) $signals['syntax_hits'];
        $ghostHits = (int) $signals['ghost_hits'];
        $delegation = (int) $signals['delegation_hints'];
        $maintHits = (int) $signals['maintainability_hits'];
        $missingLabel = (int) $signals['missing_accessible_label'];
        $missingTab = (int) $signals['missing_tab_index'];
        $missingFocus = (int) $signals['missing_focus_border'];
        $missingTooltip = (int) $signals['missing_tooltip'];
        $a11ySarif = (int) $signals['a11y_sarif'];
        $genericNames = (int) $signals['generic_names'];
        $genericRatio = (float) $signals['generic_ratio'];
        $containerChrome = (int) $signals['container_chrome'];
        $whiteFills = (int) $signals['white_container_fills'];
        $modernThemeable = (int) $signals['modern_themeable_controls'];
        $hasTheme = (bool) $signals['has_theme'];
        $opaqueColors = (int) $signals['opaque_colors'];
        $hasI18n = (bool) ($signals['has_i18n'] ?? false);
        $literalTexts = (int) ($signals['literal_texts'] ?? 0);
        $needsTranslate = !$hasI18n && ($literalTexts >= 8);

        $needsNames = ($genericRatio >= 0.12) || ($genericNames >= 15);
        $needsTheme = !$hasTheme && ($opaqueColors >= 25 || $modernThemeable > 0);

        $needsRefRepair = $refErrors > 0 || $mangled > 0 || $doubleQualified > 0
            || ($unresolved > 0 && $formulaErrors > 0);
        $needsFormulaWork = $localeHits > 0
            || $needsRefRepair
            || $boolIssues > 0
            || $missingPackage > 0
            || $columnErrors > 0
            || ($ghostHits > 0 && $formulaErrors > 0)
            || $syntaxHits > 0
            || $formulaErrors > 0;

        if ($needsNames) {
            $reasons[] = sprintf(
                'Many generic Studio names (%d / %d controls)',
                $genericNames,
                (int) $signals['named_controls']
            );
            $hops[] = ['id' => 'meaningful_names', 'options' => ['only_generic' => true]];
        }
        if ($localeHits > 0) {
            $reasons[] = 'Locale-corrupted formula separators detected';
            $hops[] = ['id' => 'unwhack_locale_formulas', 'options' => []];
        }
        if ($formulaErrors > 0) {
            $reasons[] = sprintf('%d formula error(s) in checker inventory', $formulaErrors);
        }
        if ($boolIssues > 0) {
            $reasons[] = 'Checked/boolean formula issues detected';
        }
        if ($missingLabel > 0 || $a11ySarif > 0) {
            $reasons[] = sprintf(
                'Accessibility gaps (missing labels ≈ %d%% of interactive controls)',
                (int) round(((float) $signals['label_missing_ratio']) * 100)
            );
        }
        if ($containerChrome > 0) {
            $reasons[] = 'Default container chrome still present';
        }
        if ($needsTheme) {
            $reasons[] = 'No gblTheme palette detected — include dark-mode theming';
        } elseif ($hasTheme) {
            $reasons[] = 'Theme palettes already present — skip re-theme';
        }
        if ($needsTranslate) {
            $reasons[] = sprintf('Many hard-coded UI strings (%d) — centralize into language packs', $literalTexts);
        } elseif ($hasI18n) {
            $reasons[] = 'Language packs / translations already present — skip translate';
        }

        // Formula / ref repair — only hops that match signals (studio_repair order).
        if ($doubleQualified > 0 || $mangled > 0) {
            $hops[] = ['id' => 'repair_double_qualified_refs', 'options' => []];
        }
        if ($needsRefRepair) {
            $hops[] = ['id' => 'repair_control_refs', 'options' => []];
            $hops[] = ['id' => 'repair_context_aware_refs', 'options' => []];
            $hops[] = ['id' => 'repair_double_qualified_refs', 'options' => []];
        }
        if ($missingPackage > 0) {
            $hops[] = ['id' => 'repair_var_current_package', 'options' => []];
        }
        if ($columnErrors > 0) {
            $hops[] = ['id' => 'repair_sharepoint_fields', 'options' => []];
        }
        if ($ghostHits > 0 && $formulaErrors > 0) {
            $hops[] = ['id' => 'repair_ghost_patch_fields', 'options' => []];
        }
        if ($syntaxHits > 0) {
            $hops[] = ['id' => 'repair_studio_syntax', 'options' => []];
        }
        if ($boolIssues > 0) {
            $hops[] = ['id' => 'repair_checked_booleans', 'options' => []];
        }

        // A11y — per-gap, never bundled solely because formulas failed.
        if ($missingLabel > 0 || ((int) ($signals['by_rule']['acc-AccessibleLabelNeeded'] ?? 0)) > 0) {
            $hops[] = ['id' => 'accessibility_labels', 'options' => []];
        }
        if ($missingFocus > 0 || ((int) ($signals['by_rule']['acc-FocusBorderShouldBeVisible'] ?? 0)) > 0) {
            $hops[] = ['id' => 'ensure_focus_visible', 'options' => ['thickness' => 2]];
        }
        if ($missingTab > 0 || ((int) ($signals['by_rule']['acc-TabIndexShouldBeDefinedForInteractiveControl'] ?? 0)) > 0) {
            $hops[] = ['id' => 'ensure_tab_index', 'options' => ['value' => 0]];
        }
        if ($missingTooltip > 0) {
            $hops[] = ['id' => 'tooltip_from_label', 'options' => []];
        }

        if ($maintHits > 0) {
            $hops[] = ['id' => 'repair_maintainability', 'options' => []];
        }
        if ($delegation > 0) {
            $hops[] = ['id' => 'repair_delegation', 'options' => []];
        }

        $formulaHopIds = [
            'unwhack_locale_formulas' => true,
            'repair_double_qualified_refs' => true,
            'repair_control_refs' => true,
            'repair_context_aware_refs' => true,
            'repair_var_current_package' => true,
            'repair_sharepoint_fields' => true,
            'repair_ghost_patch_fields' => true,
            'repair_studio_syntax' => true,
            'repair_checked_booleans' => true,
        ];
        $addedFormulaHop = false;
        foreach ($hops as $step) {
            if (isset($formulaHopIds[$step['id'] ?? ''])) {
                $addedFormulaHop = true;
                break;
            }
        }
        if ($addedFormulaHop && $needsFormulaWork) {
            $hops[] = ['id' => 'repair_converge_formulas', 'options' => []];
            $hops[] = ['id' => 'repair_double_qualified_refs', 'options' => []];
        } elseif ($formulaErrors > 0) {
            // Unclassified formula errors: converge is the hop that re-checks live errors.
            $hops[] = ['id' => 'repair_converge_formulas', 'options' => []];
            $hops[] = ['id' => 'repair_double_qualified_refs', 'options' => []];
        }

        if ($containerChrome > 0) {
            $hops[] = ['id' => 'normalize_containers', 'options' => []];
        }
        if ($whiteFills > 0) {
            $hops[] = ['id' => 'strip_default_fill', 'options' => []];
        }

        if ($needsTheme) {
            if ($modernThemeable > 0) {
                $hops[] = ['id' => 'prefer_classic_theme_controls', 'options' => []];
            }
            $hops[] = ['id' => 'enable_dark_mode', 'options' => []];
        }

        if ($needsTranslate) {
            $hops[] = ['id' => 'translate', 'options' => []];
        }

        // Ensure classic theme prep sits immediately before enable_dark_mode when both present.
        $hasClassic = false;
        $darkAt = null;
        foreach ($hops as $i => $step) {
            $id = (string) ($step['id'] ?? '');
            if ($id === 'prefer_classic_theme_controls') {
                $hasClassic = true;
            }
            if ($id === 'enable_dark_mode') {
                $darkAt = $i;
            }
        }
        if ($darkAt !== null && !$hasClassic && $modernThemeable > 0) {
            array_splice($hops, $darkAt, 0, [['id' => 'prefer_classic_theme_controls', 'options' => []]]);
        }

        // Deduplicate consecutive identical hop ids (keep intentional double_qualified repeats).
        $hops = $this->dedupeNonRepeating($hops);

        $mutating = array_values(array_filter(
            $hops,
            static fn(array $h): bool => ($h['id'] ?? '') !== 'regenerate_sarif'
        ));
        if ($mutating !== []) {
            $hops[] = ['id' => 'regenerate_sarif', 'options' => []];
        }

        $forceMode = 'missing_only';
        $forceReason = 'Preserve existing labels, theme colors, and container values; fill gaps only.';
        if ($needsTheme && !$hasTheme) {
            $forceMode = 'all';
            $forceReason = 'App has no theme yet — force full palette/chrome application for a complete themed pass.';
        } elseif (((float) $signals['label_missing_ratio']) >= 0.55 && ((int) $signals['interactive_controls'] >= 20)) {
            $forceMode = 'all';
            $forceReason = 'Most interactive controls lack AccessibleLabel — overwrite/fill aggressively.';
        } elseif ($hasTheme || ((float) $signals['label_missing_ratio']) < 0.2) {
            $forceMode = 'missing_only';
            $forceReason = $hasTheme
                ? 'Theme already present — avoid re-theming literals; fill missing a11y/chrome only.'
                : 'Most labels already set — only fill missing values.';
        }

        if ($hops === []) {
            $reasons = ['No actionable changes detected — hop sequence left empty.'];
        } elseif ($reasons === []) {
            $reasons[] = 'Heuristic scan complete';
        }

        return [
            'force_mode' => $forceMode,
            'force_mode_reason' => $forceReason,
            'reasons' => $reasons,
            'hops' => $hops,
        ];
    }

    /**
     * @param list<array{id:string,options?:array<string,mixed>}> $hops
     * @return list<array{id:string,options?:array<string,mixed>}>
     */
    private function dedupeNonRepeating(array $hops): array
    {
        $out = [];
        $seen = [];
        foreach ($hops as $step) {
            $id = (string) ($step['id'] ?? '');
            if ($id === '') {
                continue;
            }
            // repair_double_qualified_refs may appear multiple times by design.
            if ($id !== 'repair_double_qualified_refs' && isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $step;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function themeControlMap(): array
    {
        $path = POWER_SWEEPER_ROOT . '/config/theme_control_map.php';
        if (!is_file($path)) {
            return [];
        }
        $map = include $path;

        return is_array($map) ? $map : [];
    }

    /** @param list<array<string, mixed>> $themeMap */
    private function matchesThemeableModern(string $type, array $themeMap): bool
    {
        $hay = strtolower($type);
        foreach ($themeMap as $entry) {
            if (!empty($entry['optional'])) {
                continue;
            }
            foreach ($entry['match'] ?? [] as $pattern) {
                if (is_string($pattern) && @preg_match($pattern, $hay)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isDefaultOpaqueFill(string $fill): bool
    {
        $parsed = ColorValue::parse($fill);
        if ($parsed === null) {
            $body = strtolower(trim(ltrim(trim($fill), '=')));

            return $body === 'color.white' || $body === 'white';
        }
        if (ColorValue::isTransparent($parsed)) {
            return false;
        }

        return $parsed['r'] >= 250 && $parsed['g'] >= 250 && $parsed['b'] >= 250;
    }
}
