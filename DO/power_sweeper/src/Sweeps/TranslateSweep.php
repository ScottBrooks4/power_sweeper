<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\ControlNode;
use PowerSweeper\LangTableHarvester;
use PowerSweeper\SweepOptions;
use PowerSweeper\Report;

/**
 * Centralize UI strings behind language packs (dark-mode analogue for text).
 *
 * App.OnStart defines:
 *   gblLang     — optional language code ("en", "fr") when the app has no varLang
 *   gblStrings  — one record per token with En/Fr side-by-side for translators
 *
 * Literal Text / HintText / Tooltip / TrueText / FalseText become
 *   If(varLang, gblStrings.Token.En, gblStrings.Token.Fr)
 * so existing EN/FR header buttons and settings radios update live (no pack swap).
 * Existing language radios / varLang toggles are detected and wired when present.
 */
final class TranslateSweep implements SweepInterface
{
    private const LANG = 'gblLang';
    private const STRINGS = 'gblStrings';
    private const STRINGS_EN = 'gblStringsEn';
    private const STRINGS_FR = 'gblStringsFr';
    private const RADIO_NAME = 'rdoPowerSweeperLanguage';
    private const SLIDER_NAME = 'psLangSlider';
    private const KNOB_X = 'varPsLangKnobX';
    /** EN (left) and FR (right) knob X inside the 88px slider track. */
    private const KNOB_EN_X = 2;
    private const KNOB_FR_X = 46;
    private const BLOCK_START = '/* ps-i18n:start */';
    private const BLOCK_END = '/* ps-i18n:end */';

    /** @var list<string> */
    private const TEXT_PROPERTIES = [
        'Text',
        'HintText',
        'Tooltip',
        'TrueText',
        'FalseText',
        'AccessibleLabel',
        'Label',
        'Placeholder',
        'NoAttachmentsText',
        'AddAttachmentText',
        'MaxAttachmentsText',
        'AttachedFileListLabel',
        'DownloadErrorMessageText',
        'DownloadErrorMessageTitle',
        'FetchErrorMessageText',
        'RemoveAttachmentTooltip',
        'UndoRemoveAttachmentTooltip',
    ];

    public static function id(): string
    {
        return 'translate';
    }

    public static function label(): string
    {
        return 'Translate (language packs)';
    }

    public static function description(): string
    {
        return 'Centralize label/button/text into gblStrings with En/Fr side-by-side; bind controls to If(varLang, gblStrings.Token.En, gblStrings.Token.Fr); unhide settings Language Preference radios; add an EN/FR slider beside the Settings title.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $langVar = is_string($options['lang_var'] ?? null) && $options['lang_var'] !== ''
            ? (string) $options['lang_var']
            : self::LANG;
        $stringsVar = is_string($options['strings_var'] ?? null) && $options['strings_var'] !== ''
            ? (string) $options['strings_var']
            : self::STRINGS;
        $stringsEn = is_string($options['strings_en_var'] ?? null) && $options['strings_en_var'] !== ''
            ? (string) $options['strings_en_var']
            : self::STRINGS_EN;
        $stringsFr = is_string($options['strings_fr_var'] ?? null) && $options['strings_fr_var'] !== ''
            ? (string) $options['strings_fr_var']
            : self::STRINGS_FR;
        $injectToggle = !array_key_exists('inject_toggle', $options) || (bool) $options['inject_toggle'];
        $injectSlider = !array_key_exists('inject_slider', $options) || (bool) $options['inject_slider'];
        $force = SweepOptions::force($options);

        /** @var array<string, array{en:string,fr:string}> $packs */
        $packs = [];
        /** @var array<string, string> $literalToToken */
        $literalToToken = [];
        /** @var array<string, string> $commentTokens */
        $commentTokens = [];
        /** @var list<array{control:ControlNode,property:string,kind:string,en:string,fr:?string}> $targets */
        $targets = [];

        $existingLangVar = $this->detectExistingLangVar($documents) ?? 'varLang';
        $langIsBoolean = $this->langVarIsBoolean($documents, $existingLangVar);
        $this->stripStrayLanguageToggles($documents, [$existingLangVar, $langVar], $report);
        $langControls = $this->findLanguageControls($documents, $existingLangVar);

        // Pass 0 — harvest EN/FR pairs from App.Formulas LangFilter tables (StatusTable, …)
        // and keep any already-written gblStrings pack (re-run after labels are bound).
        foreach ($this->findAllApps($documents) as $appControl) {
            $formulas = (string) ($appControl->getProperty('Formulas') ?? '');
            if ($formulas !== '' && str_contains($formulas, 'LangFilter')) {
                foreach (LangTableHarvester::harvest($formulas) as $token => $pair) {
                    if (!isset($packs[$token])) {
                        $packs[$token] = $pair;
                        $literalToToken[mb_strtolower($pair['en'])] = $token;
                    }
                }
            }
            foreach ($this->harvestExistingStringPack((string) ($appControl->getProperty('OnStart') ?? '')) as $token => $pair) {
                if ($this->isCommentOnly($pair['en']) || $this->isTechnicalLiteral($pair['en'])) {
                    $commentTokens[$token] = $pair['en'];
                    continue;
                }
                if (!isset($packs[$token])) {
                    $packs[$token] = $pair;
                    $literalToToken[mb_strtolower($pair['en'])] = $token;
                } elseif ($packs[$token]['fr'] === $packs[$token]['en'] && $pair['fr'] !== $pair['en']) {
                    $packs[$token]['fr'] = $pair['fr'];
                }
            }
        }
        $this->restoreCommentBindings($documents, $commentTokens, $stringsVar, $report);

        // Pass 1 — harvest literals / simple bilingual If(varLang, …) strings.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp() || $control->isScreen()) {
                    continue;
                }
                if (!$this->isTextualControl($control)) {
                    continue;
                }
                if ($this->isLanguageControlPath($langControls, $control->path)) {
                    continue;
                }
                if ($control->name === self::RADIO_NAME) {
                    continue;
                }

                foreach (self::TEXT_PROPERTIES as $prop) {
                    $raw = $control->getProperty($prop);
                    if ($raw === null || ControlNaming::isBlank($raw)) {
                        continue;
                    }
                    if ($this->isCommentOnly((string) $raw)) {
                        continue;
                    }
                    if ($this->alreadyBoundToStrings($raw, $stringsVar)) {
                        continue;
                    }
                    if ($this->alreadyBoundToTranslations($raw)) {
                        continue;
                    }

                    $bilingual = $this->parseBilingualIf($raw, $existingLangVar);
                    if ($bilingual !== null) {
                        if ($this->isIgnorableGlyph($bilingual['en']) && $this->isIgnorableGlyph($bilingual['fr'])) {
                            continue;
                        }
                        $token = $this->tokenFor($control, $prop, $bilingual['en'], $packs, $literalToToken);
                        $packs[$token]['en'] = $bilingual['en'];
                        $packs[$token]['fr'] = $bilingual['fr'];
                        $targets[] = [
                            'control' => $control,
                            'property' => $prop,
                            'kind' => 'bilingual',
                            'en' => $bilingual['en'],
                            'fr' => $bilingual['fr'],
                            'token' => $token,
                        ];
                        continue;
                    }

                    $concat = $this->parseConcatLiteral($raw);
                    if ($concat !== null) {
                        $token = $this->tokenFor($control, $prop, $concat['literal'], $packs, $literalToToken);
                        if (!isset($packs[$token])) {
                            $packs[$token] = ['en' => $concat['literal'], 'fr' => $concat['literal']];
                        }
                        $targets[] = [
                            'control' => $control,
                            'property' => $prop,
                            'kind' => $concat['side'] === 'prefix' ? 'concat_prefix' : 'concat_suffix',
                            'en' => $concat['literal'],
                            'fr' => $concat['literal'],
                            'token' => $token,
                            'rest' => $concat['rest'],
                        ];
                        continue;
                    }

                    if ($this->isDynamicExpression($raw) && !$force) {
                        continue;
                    }
                    if ($this->isDynamicExpression($raw) && $force) {
                        // force still skips rich formulas (LookUp/Concatenate/…) — only literals.
                        continue;
                    }

                    $literal = ControlNaming::unwrap($raw);
                    if ($literal === '' || $this->isIgnorableGlyph($literal) || $this->isCommentOnly($literal)) {
                        continue;
                    }

                    $token = $this->tokenFor($control, $prop, $literal, $packs, $literalToToken);
                    if (!isset($packs[$token])) {
                        $packs[$token] = ['en' => $literal, 'fr' => $literal];
                    }
                    $targets[] = [
                        'control' => $control,
                        'property' => $prop,
                        'kind' => 'literal',
                        'en' => $literal,
                        'fr' => $literal,
                        'token' => $token,
                    ];
                }
            }
        }

        // Pass 1b — gallery Items tables, HtmlText copy, DataSourceInfo display names,
        // and quoted UI literals inside If/Concatenate (Good Morning, card titles, …).
        $tableBound = [];
        $this->rewriteEmbeddedStrings(
            $documents,
            $langControls,
            $packs,
            $literalToToken,
            $stringsVar,
            $existingLangVar,
            $langIsBoolean,
            $report,
            $tableBound
        );
        $this->rewriteTableLiteralComparisons(
            $documents,
            $tableBound,
            $packs,
            $stringsVar,
            $existingLangVar,
            $langIsBoolean,
            $report
        );

        if ($packs === [] && $langControls === [] && !$injectToggle) {
            return;
        }

        VcrLanguageProfile::preparePacks($packs);
        // Pass 2 — inject / refresh OnStart language packs (En/Fr side-by-side).
        $apps = $this->findAllApps($documents);
        if ($apps !== []) {
            $block = $this->buildI18nBlock(
                $langVar,
                $stringsVar,
                $packs,
                $existingLangVar,
                $langIsBoolean,
                $langControls !== [],
                $injectSlider
            );
            foreach ($apps as $appControl) {
                $before = (string) ($appControl->getProperty('OnStart') ?? '');
                $after = $this->upsertBlock($before, $block, $appControl->format === 'yaml');
                if ($after !== $before) {
                    $appControl->setProperty('OnStart', $after);
                    $report->add(
                        self::id(),
                        $appControl->path,
                        'OnStart',
                        $before !== '' ? $before : '(empty)',
                        $after
                    );
                }
            }
        }

        $hasTheme = $this->documentsMention($documents, 'gblTheme');

        // Pass 3 — wire every language control (settings radio AND header EN/FR).
        if ($langControls !== []) {
            foreach ($langControls as $langControl) {
                $this->wireLanguageControl(
                    $langControl,
                    $langVar,
                    $existingLangVar,
                    $langIsBoolean,
                    $hasTheme,
                    $report
                );
            }
            $this->unhideLanguagePreferencePanels($documents, $langControls, $report);
        } elseif ($injectToggle) {
            $screen = $this->pickIntroScreen($documents);
            if ($screen !== null) {
                $this->injectLanguageRadio(
                    $screen,
                    $langVar,
                    $stringsVar,
                    $report
                );
            }
        }

        // Pass 3b — EN/FR pill slider beside Settings in the top bar chrome.
        if ($injectSlider) {
            $this->ensureLanguageSlider(
                $documents,
                $existingLangVar,
                $langIsBoolean,
                $report
            );
        }

        VcrLanguageProfile::apply($documents, $packs, $existingLangVar, $langIsBoolean, $report);
        $this->rewriteStableValueDisplays($documents, $existingLangVar, $langIsBoolean, $report);
        $this->rewriteChoiceLists($documents, $existingLangVar, $langIsBoolean, $report);
        $this->repairThemeRadios($documents, $report);
        $this->repairLanguagePersistence($documents, $existingLangVar, $langIsBoolean, $report);

        // Pass 4 — rewrite harvested targets to If(varLang, gblStrings.Token.En, gblStrings.Token.Fr).
        foreach ($targets as $target) {
            /** @var ControlNode $control */
            $control = $target['control'];
            $prop = (string) $target['property'];
            $token = (string) $target['token'];
            $before = (string) ($control->getProperty($prop) ?? '');
            $expr = $this->stringsBinding($stringsVar, $token, $existingLangVar, $langIsBoolean);
            $kind = (string) ($target['kind'] ?? '');
            if ($kind === 'concat_prefix' && isset($target['rest'])) {
                $expr .= ' & ' . (string) $target['rest'];
            } elseif ($kind === 'concat_suffix' && isset($target['rest'])) {
                $expr = (string) $target['rest'] . ' & ' . $expr;
            }
            $to = $control->format === 'yaml' ? '=' . $expr : $expr;
            if (trim(ltrim($before, '=')) === $expr) {
                continue;
            }
            $control->setProperty($prop, $to);
            $report->add(self::id(), $control->path, $prop, $before !== '' ? $before : '(empty)', $to);
        }

        // Pass 5 — components that reference gblStrings need AccessAppScope.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$this->looksLikeComponentDefinition($control)) {
                    continue;
                }
                $needs = false;
                foreach ($control->propertyNames() as $prop) {
                    $val = (string) ($control->getProperty($prop) ?? '');
                    if (str_contains($val, $stringsVar) || str_contains($val, $langVar) || str_contains($val, $existingLangVar)) {
                        $needs = true;
                        break;
                    }
                }
                if (!$needs) {
                    continue;
                }
                $before = (string) ($control->getProperty('AccessAppScope') ?? '');
                if (strtolower(trim(ltrim($before, '='))) === 'true') {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=true' : 'true';
                $control->setProperty('AccessAppScope', $to);
                $report->add(
                    self::id(),
                    $control->path,
                    'AccessAppScope',
                    $before !== '' ? $before : '(unset)',
                    $to
                );
            }
        }

        $this->repairSliderTheme($documents, $existingLangVar, $langIsBoolean, $report);
        $this->repairNoticeTheme($documents, $report);
        $this->repairValidationMessages($documents, $existingLangVar, $langIsBoolean, $report);
        $this->repairAttachmentText($documents, $existingLangVar, $langIsBoolean, $report);
        $this->commentPkiSubmit($documents, $report);
        $this->repairHoverInk($documents, $report);
        $this->repairNotifyToasts($documents, $existingLangVar, $langIsBoolean, $report);
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function tokenFor(
        ControlNode $control,
        string $prop,
        string $literal,
        array &$packs,
        array &$literalToToken
    ): string {
        $key = mb_strtolower($literal);
        if (isset($literalToToken[$key])) {
            return $literalToToken[$key];
        }

        $base = null;
        if (!ControlNaming::isGenericName($control->name) && ControlNaming::isValidIdentifier($control->name)) {
            $base = $control->name;
            if ($prop !== 'Text') {
                $base .= $this->pascal($prop);
            }
        }
        if ($base === null) {
            $base = $this->pascal($literal);
        }
        if ($base === '' || !ControlNaming::isValidIdentifier($base)) {
            $base = 'String' . (count($packs) + 1);
        }
        $token = $base;
        $n = 2;
        while (isset($packs[$token]) && $packs[$token]['en'] !== $literal) {
            $token = $base . $n;
            $n++;
        }
        $literalToToken[$key] = $token;

        return $token;
    }

    private function pascal(string $text): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]+/', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', trim($clean)) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out .= ucfirst(strtolower($part));
        }
        if ($out !== '' && preg_match('/^\d/', $out)) {
            $out = 'T' . $out;
        }

        return substr($out, 0, 48);
    }

    private function isTextualControl(ControlNode $control): bool
    {
        $t = strtolower($control->type . ' ' . $control->name);
        foreach ([
            'label', 'button', 'text', 'htmltext', 'htmlviewer', 'textbox', 'textinput',
            'checkbox', 'toggle', 'switch', 'radio', 'dropdown', 'combobox', 'datepicker',
            'link', 'icon', 'header', 'footer', 'info',
        ] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }
        foreach (self::TEXT_PROPERTIES as $prop) {
            if ($control->getProperty($prop) !== null) {
                return true;
            }
        }

        return false;
    }

    /** Power Fx comment-only formula — blank at runtime, not UI copy. */
    private function isCommentOnly(string $raw): bool
    {
        $v = trim(ltrim(trim($raw), '='));

        return str_starts_with($v, '//');
    }

    /**
     * Undo gblStrings bindings that are not UI copy: // placeholders (Studio then
     * tries Value(...) → "cannot be converted to a number") and CSS fragments.
     *
     * @param list<ControlDocument> $documents
     * @param array<string, string> $commentTokens
     */
    private function restoreCommentBindings(
        array $documents,
        array $commentTokens,
        string $stringsVar,
        Report $report
    ): void {
        if ($commentTokens === []) {
            return;
        }
        $q = preg_quote($stringsVar, '/');
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '' || !str_contains($raw, $stringsVar)) {
                        continue;
                    }
                    $next = $raw;
                    foreach ($commentTokens as $token => $en) {
                        $tq = preg_quote($token, '/');
                        $ifRe = '/If\s*\(\s*(?:Or\s*\([^)]*\)|[A-Za-z_]\w*)\s*,\s*'
                            . $q . '\s*\.\s*' . $tq . '\s*\.\s*En\s*,\s*'
                            . $q . '\s*\.\s*' . $tq . '\s*\.\s*Fr\s*\)/';
                        $replacement = $this->isCommentOnly($en)
                            ? $en
                            : '"' . $this->escapeFx($en) . '"';
                        $next = preg_replace($ifRe, $replacement, $next) ?? $next;
                    }
                    if ($next === $raw) {
                        continue;
                    }
                    $body = trim(ltrim(trim($next), '='));
                    if ($this->isCommentOnly($body)) {
                        $next = $control->format === 'yaml' ? '=' . $body : $body;
                    }
                    $control->setProperty($prop, $next);
                    $report->add(self::id(), $control->path, $prop, $raw, $next);
                }
            }
        }
    }

    /** CSS / unit fragments that must stay quoted concatenations, not gblStrings. */
    private function isTechnicalLiteral(string $raw): bool
    {
        $t = trim(ltrim(trim($raw), '='));
        if ($t === '') {
            return false;
        }
        if (preg_match('/^px\b/i', $t) === 1) {
            return true;
        }
        if (preg_match('/^(width|height|min-width|max-width|min-height|max-height|background|background-color|color|border|padding|margin|font-size|font-family|display|overflow|box-shadow|border-radius)\s*:/i', $t) === 1) {
            return true;
        }

        return preg_match('/^\s*[\d.]+\s*(px|em|rem|%|vh|vw)\s*;?\s*$/i', $t) === 1;
    }

    private function isIgnorableGlyph(string $literal): bool
    {
        $t = trim($literal);
        if ($t === '') {
            return true;
        }
        // Single punctuation / arrows / emoji-only chips.
        if (preg_match('/^[\p{P}\p{S}\s]+$/u', $t) === 1 && mb_strlen($t) <= 3) {
            return true;
        }
        // Header EN/FR chips stay If(varLang, "EN", "FR") — not gblStrings.En.En.
        if (preg_match('/^(en|fr|en-ca|fr-ca)$/i', $t) === 1) {
            return true;
        }

        return false;
    }

    private function alreadyBoundToStrings(string $raw, string $stringsVar): bool
    {
        $q = preg_quote($stringsVar, '/');

        return (bool) preg_match('/\b' . $q . '\s*\.\s*\w+\s*\.\s*(En|Fr)\b/', $raw);
    }

    private function alreadyBoundToTranslations(string $raw): bool
    {
        return (bool) preg_match(
            '/\bcomTranslations\s*\.\s*Labels\b|\bTranslationComponent\w*\s*\.\s*Labels\b|\b(?:LookUp|Filter)\s*\(\s*\w*(?:Table|Labels)\w*\s*,\s*LangFilter\s*=|\bLangFilter\s*=\s*If\s*\(\s*!?varLang/i',
            $raw
        );
    }

    private function isDynamicExpression(string $value): bool
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = trim(substr($v, 1));
        }
        if ($v === '') {
            return false;
        }
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"') && substr_count($v, '"') === 2)
            || (str_starts_with($v, "'") && str_ends_with($v, "'") && substr_count($v, "'") === 2)
        ) {
            return false;
        }
        // =fnRequestTitle and other bare names are formulas, not copy.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $v) === 1) {
            return true;
        }

        return (bool) preg_match(
            '/\b(If|Switch|LookUp|Coalesce|Concatenate|With|Filter)\s*\(|\b(Self|Parent|ThisItem|var[A-Z]|com[A-Z]|gbl[A-Z])\b|[()&]|[A-Za-z_]\w*\./i',
            $v
        );
    }

    /**
     * @return null|array{en:string,fr:string}
     */
    private function parseBilingualIf(string $raw, string $langVar): ?array
    {
        $v = trim($raw);
        if (str_starts_with($v, '=')) {
            $v = trim(substr($v, 1));
        }
        // If(varLang, "EN", "FR") or If(varLang=true, …) / If(!varLang, fr, en)
        $q = preg_quote($langVar, '/');
        if (preg_match('/^If\s*\(\s*!?\s*' . $q . '\s*(?:=\s*true)?\s*,\s*"([^"]*)"\s*,\s*"([^"]*)"\s*\)$/is', $v, $m)) {
            $negated = str_contains(substr($v, 0, strpos($v, ',') ?: 0), '!');
            if ($negated) {
                return ['en' => $m[2], 'fr' => $m[1]];
            }

            return ['en' => $m[1], 'fr' => $m[2]];
        }
        if (preg_match('/^If\s*\(\s*' . $q . '\s*,\s*"([^"]*)"\s*,\s*"([^"]*)"\s*\)$/is', $v, $m)) {
            return ['en' => $m[1], 'fr' => $m[2]];
        }

        return null;
    }

    /** @param list<ControlDocument> $documents */
    private function detectExistingLangVar(array $documents): ?string
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['OnChange', 'OnSelect', 'OnCheck', 'OnUncheck', 'OnStart', 'Default'] as $prop) {
                    $val = (string) ($control->getProperty($prop) ?? '');
                    if (preg_match('/\bSet\s*\(\s*(varLang|gblLang)\s*,/i', $val, $m)) {
                        return $m[1];
                    }
                    if (preg_match('/\b(varLang|gblLang)\b/', $val, $m) && str_contains(strtolower($control->name), 'lang')) {
                        return $m[1];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return list<ControlNode>
     */
    private function findLanguageControls(array $documents, string $langVar): array
    {
        $found = [];
        $q = preg_quote($langVar, '/');
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $type = strtolower($control->type);
                $onSelect = (string) ($control->getProperty('OnSelect') ?? '');
                $onChange = (string) ($control->getProperty('OnChange') ?? '');
                $setsLang = preg_match('/\bSet\s*\(\s*' . $q . '\s*,/i', $onSelect . ' ' . $onChange) === 1;
                $isChooser = str_contains($type, 'radio')
                    || str_contains($type, 'dropdown')
                    || str_contains($type, 'combobox')
                    || $control->isToggle()
                    || str_contains(strtolower($control->name), 'toggle')
                    || $control->isButtonLike()
                    || (str_contains($type, 'label') && $setsLang);
                if (!$isChooser && !$setsLang) {
                    continue;
                }
                // Bound labels are If(varLang, gblStrings.X.En, …) — that is not a language toggle.
                $text = (string) ($control->getProperty('Text') ?? '');
                $textForScore = $this->alreadyBoundToStrings($text, self::STRINGS) ? '' : $text;
                $labelBlob = strtolower(
                    $control->name . ' '
                    . $textForScore . ' '
                    . (string) ($control->getProperty('AccessibleLabel') ?? '')
                    . ' '
                    . (string) ($control->getProperty('Items') ?? '')
                );
                $blob = $labelBlob . ' ' . strtolower($onChange . ' ' . $onSelect);
                $score = 0;
                if (str_contains($labelBlob, 'language') || str_contains($labelBlob, 'langue')) {
                    $score += 50;
                }
                if (str_contains($labelBlob, 'english') && str_contains($labelBlob, 'french')) {
                    $score += 80;
                }
                if (str_contains($labelBlob, 'english') || str_contains($labelBlob, 'french')) {
                    $score += 30;
                }
                if ($setsLang) {
                    $score += 100;
                }
                if (preg_match('/\b(en|fr|en-ca|fr-ca)\b/', $labelBlob)) {
                    $score += 10;
                }
                // Exclude dark-theme radios.
                if (
                    (str_contains($blob, 'dark') || str_contains($blob, 'light') || str_contains($blob, 'theme'))
                    && !str_contains($labelBlob, 'english')
                    && !str_contains($labelBlob, 'french')
                    && !str_contains($labelBlob, 'language')
                    && !$setsLang
                ) {
                    $score -= 100;
                }
                // Buttons that only mention varLang in a gblStrings Text binding are CTAs, not toggles.
                if ($control->isButtonLike() && !$setsLang && !str_contains($blob, 'english') && !str_contains($blob, 'french')) {
                    continue;
                }
                if ($score >= 40) {
                    $found[] = $control;
                }
            }
        }

        return $found;
    }

    /** @param list<ControlNode> $langControls */
    private function isLanguageControlPath(array $langControls, string $path): bool
    {
        foreach ($langControls as $control) {
            if ($control->path === $path) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ControlDocument> $documents */
    private function langVarIsBoolean(array $documents, string $langVar): bool
    {
        $q = preg_quote($langVar, '/');
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['OnChange', 'OnSelect', 'OnCheck', 'OnUncheck', 'OnStart', 'Default'] as $prop) {
                    $val = (string) ($control->getProperty($prop) ?? '');
                    if (preg_match('/\bSet\s*\(\s*' . $q . '\s*,\s*(true|false|!' . $q . ')/i', $val) === 1) {
                        return true;
                    }
                    if (preg_match('/\bIf\s*\(\s*!' . $q . '\s*,/', $val) === 1) {
                        return true;
                    }
                }
            }
        }

        return strcasecmp($langVar, 'varLang') === 0;
    }

    private function stringsBinding(string $stringsVar, string $token, string $langVar, bool $langIsBoolean): string
    {
        $en = $stringsVar . '.' . $token . '.En';
        $fr = $stringsVar . '.' . $token . '.Fr';
        if ($langIsBoolean) {
            return 'If(' . $langVar . ', ' . $en . ', ' . $fr . ')';
        }

        return 'If(Or(' . $langVar . ' = "fr", ' . $langVar . ' = "FR"), ' . $fr . ', ' . $en . ')';
    }

    private function wireLanguageControl(
        ControlNode $control,
        string $langVar,
        string $existingLangVar,
        bool $langIsBoolean,
        bool $hasTheme,
        Report $report
    ): void {
        $type = strtolower($control->type);
        $activeVar = $langIsBoolean ? $existingLangVar : $langVar;

        if (str_contains($type, 'radio') || str_contains($type, 'dropdown') || str_contains($type, 'combobox')) {
            $this->ensureLanguageItems($control, $activeVar, $langIsBoolean, $report);
            $setLang = $langIsBoolean
                ? 'Set(' . $existingLangVar . ', ' . $this->selectedEnglishExpr() . ')'
                : 'Set(' . $langVar . ', If(' . $this->selectedEnglishExpr() . ', "en", "fr"))';
            $this->replaceLangOnlyFormula($control, 'OnChange', $setLang, [$existingLangVar, $langVar], $report);
            // Radio@0.0.25 (same control as Theme) has no OnSelect. A lang-only OnSelect
            // is invalid authoring and can block the OnChange that actually sets varLang.
            $this->dropLangOnlyProperty($control, 'OnSelect', [$existingLangVar, $langVar], $report);
            // Match theme radios: readable label color when the app already has gblTheme.
            if (str_contains($type, 'radio') && !$control->hasProperty('FontColor') && $hasTheme) {
                $fontTo = $control->format === 'yaml' ? '=gblTheme.Text' : 'gblTheme.Text';
                $control->setProperty('FontColor', $fontTo);
                $report->add(self::id(), $control->path, 'FontColor', '(unset)', $fontTo);
            }

            return;
        }

        if ($control->isToggle() || str_contains(strtolower($control->name), 'toggle')) {
            $onCheck = $langIsBoolean
                ? 'Set(' . $existingLangVar . ', false)'
                : 'Set(' . $langVar . ', "fr")';
            $onUncheck = $langIsBoolean
                ? 'Set(' . $existingLangVar . ', true)'
                : 'Set(' . $langVar . ', "en")';
            $this->replaceLangOnlyFormula($control, 'OnCheck', $onCheck, [$existingLangVar, $langVar], $report);
            $this->replaceLangOnlyFormula($control, 'OnUncheck', $onUncheck, [$existingLangVar, $langVar], $report);

            return;
        }

        if ($control->isButtonLike() || str_contains($type, 'label')) {
            $toggle = $langIsBoolean
                ? 'Set(' . $existingLangVar . ', !Coalesce(' . $existingLangVar . ', true))'
                : 'Set(' . $langVar . ', If(Or(' . $langVar . ' = "fr", ' . $langVar . ' = "FR"), "en", "fr"))';
            $this->replaceLangOnlyFormula($control, 'OnSelect', $toggle, [$existingLangVar, $langVar], $report);
        }
    }

    /**
     * Fluent Radio@0.0.x keeps Selected as a string when Items is ["English","French"].
     * Self.Selected.Value (and record Items) error at authoring time so OnChange never runs.
     */
    private function ensureLanguageItems(
        ControlNode $control,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        $items = (string) ($control->getProperty('Items') ?? '');
        $wantItems = $control->format === 'yaml'
            ? '=["English", "French"]'
            : '["English", "French"]';
        $itemsBody = trim(ltrim(trim($items), '='));
        $alreadyString = preg_match('/^\[\s*"English"\s*,\s*"French"\s*\]$/i', $itemsBody) === 1
            && !str_contains($items, 'Value:');
        if (!$alreadyString) {
            $control->setProperty('Items', $wantItems);
            $report->add(self::id(), $control->path, 'Items', $items !== '' ? $items : '(unset)', $wantItems);
        }

        $default = (string) ($control->getProperty('DefaultSelectedItems') ?? '');
        $expr = $langIsBoolean
            ? 'If(' . $langVar . ', ["English"], ["French"])'
            : 'If(Or(' . $langVar . ' = "fr", ' . $langVar . ' = "FR"), ["French"], ["English"])';
        $to = $control->format === 'yaml' ? '=' . $expr : $expr;
        $defaultBody = trim(ltrim(trim($default), '='));
        if ($defaultBody !== $expr) {
            $control->setProperty('DefaultSelectedItems', $to);
            $report->add(self::id(), $control->path, 'DefaultSelectedItems', $default !== '' ? $default : '(unset)', $to);
        }
    }

    private function selectedEnglishExpr(): string
    {
        // Same shape as the working Theme radio (Radio@0.0.25, Items as a text array):
        // Selected is a record, so Selected.Value is the label. Comparing Self.Selected
        // to text, or Or()-ing both shapes, is a type error and OnChange never runs.
        return 'Self.Selected.Value = "English"';
    }

    /** @param list<string> $langVars */
    private function dropLangOnlyProperty(ControlNode $control, string $prop, array $langVars, Report $report): void
    {
        $before = (string) ($control->getProperty($prop) ?? '');
        $body = trim(ltrim(trim($before), '='));
        if ($body === '' || !$this->isLangOnlyFormula($body, $langVars)) {
            return;
        }
        $control->removeProperty($prop);
        $report->add(self::id(), $control->path, $prop, $before, '(removed)');
    }

    /** @param list<string> $langVars */
    private function replaceLangOnlyFormula(
        ControlNode $control,
        string $prop,
        string $canonical,
        array $langVars,
        Report $report
    ): void {
        $before = (string) ($control->getProperty($prop) ?? '');
        $body = trim(ltrim(trim($before), '='));
        $langOnly = $body === '' || $this->isLangOnlyFormula($body, $langVars);
        if (!$langOnly) {
            return;
        }
        $to = $control->format === 'yaml' ? '=' . $canonical : $canonical;
        if (trim(ltrim(trim($before), '=')) === $canonical) {
            return;
        }
        $control->setProperty($prop, $to);
        $report->add(self::id(), $control->path, $prop, $before !== '' ? $before : '(empty)', $to);
    }

    /**
     * Earlier translate passes appended Set(varLang, !varLang) onto real CTAs.
     * That made every such button look like a language toggle and blocked harvest.
     *
     * @param list<ControlDocument> $documents
     * @param list<string> $langVars
     */
    private function stripStrayLanguageToggles(array $documents, array $langVars, Report $report): void
    {
        $q = implode('|', array_map(static fn(string $v): string => preg_quote($v, '/'), $langVars));
        $tail = '/;\s*Set\(\s*(?:' . $q . ')\s*,\s*!?((?:Coalesce\(\s*(?:' . $q . ')\s*,\s*true\s*\))|(?:' . $q . '))\s*\)\s*;?\s*$/i';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['OnSelect', 'OnChange'] as $prop) {
                    $before = (string) ($control->getProperty($prop) ?? '');
                    $body = trim(ltrim(trim($before), '='));
                    if ($body === '' || $this->isLangOnlyFormula($body, $langVars)) {
                        continue;
                    }
                    $cleaned = preg_replace($tail, '', $body);
                    if (!is_string($cleaned) || $cleaned === $body) {
                        continue;
                    }
                    $cleaned = trim($cleaned);
                    $to = $control->format === 'yaml' ? '=' . $cleaned : $cleaned;
                    $control->setProperty($prop, $to);
                    $report->add(self::id(), $control->path, $prop, $before, $to);
                }
            }
        }
    }

    /** @return array{literal:string,rest:string,side:'prefix'|'suffix'}|null */
    private function parseConcatLiteral(string $raw): ?array
    {
        $v = trim(ltrim(trim($raw), '='));
        if (preg_match('/^"((?:[^"]|"")*)"\s*&\s*(.+)$/s', $v, $m) === 1) {
            $lit = str_replace('""', '"', $m[1]);
            if ($this->isIgnorableGlyph($lit) || str_starts_with(ltrim($lit), '<')) {
                return null;
            }

            return ['literal' => $lit, 'rest' => trim($m[2]), 'side' => 'prefix'];
        }
        if (preg_match('/^(.+?)\s*&\s*"((?:[^"]|"")*)"$/s', $v, $m) === 1) {
            $lit = str_replace('""', '"', $m[2]);
            if ($this->isIgnorableGlyph($lit) || str_starts_with(ltrim($lit), '<')) {
                return null;
            }

            return ['literal' => $lit, 'rest' => trim($m[1]), 'side' => 'suffix'];
        }

        return null;
    }

    /**
     * Bind UI copy that is not a lone Text= literal: gallery Tables, HtmlText, DataSourceInfo labels.
     *
     * @param list<ControlDocument> $documents
     * @param list<ControlNode> $langControls
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteEmbeddedStrings(
        array $documents,
        array $langControls,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean,
        Report $report,
        array &$tableBound = []
    ): void {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isScreen()) {
                    continue;
                }
                $skipItems = $this->isLanguageControlPath($langControls, $control->path);
                $props = $control->isApp()
                    ? ['OnStart', 'Formulas']
                    : ['Text', 'HintText', 'Tooltip', 'HtmlText', 'Items', 'DisplayName', 'AccessibleLabel', 'Label', 'Placeholder', 'NoAttachmentsText', 'AddAttachmentText', 'MaxAttachmentsText', 'AttachedFileListLabel', 'DownloadErrorMessageText', 'DownloadErrorMessageTitle', 'FetchErrorMessageText', 'RemoveAttachmentTooltip', 'UndoRemoveAttachmentTooltip'];
                foreach ($props as $prop) {
                    if ($skipItems && $prop === 'Items') {
                        continue;
                    }
                    $raw = $control->getProperty($prop);
                    if ($raw === null || ControlNaming::isBlank($raw)) {
                        continue;
                    }
                    if ($this->alreadyBoundToStrings((string) $raw, $stringsVar) && !str_contains((string) $raw, 'DataSourceInfo')) {
                        // Still rewrite remaining raw quotes / HTML phrases.
                        if (!preg_match('/"[^"]*[A-Za-z]{3,}/', (string) $raw) && !str_contains((string) $raw, 'My Account')) {
                            continue;
                        }
                    }
                    $after = $this->rewriteFormulaEmbedded(
                        (string) $raw,
                        $prop,
                        $control,
                        $packs,
                        $literalToToken,
                        $stringsVar,
                        $langVar,
                        $langIsBoolean,
                        $tableBound
                    );
                    if ($after === null || $after === (string) $raw) {
                        continue;
                    }
                    $control->setProperty($prop, $after);
                    $report->add(self::id(), $control->path, $prop, (string) $raw, $after);
                }
            }
        }
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteFormulaEmbedded(
        string $raw,
        string $prop,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean,
        array &$tableBound = []
    ): ?string {
        $hadEquals = str_starts_with(trim($raw), '=') || $control->format === 'yaml';
        $body = trim(ltrim(trim($raw), '='));
        $ds = $this->rewriteDataSourceDisplayName($body, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
        if ($ds !== null) {
            return $hadEquals ? '=' . $ds : $ds;
        }
        if ($prop === 'OnStart' || $prop === 'Formulas') {
            $next = $this->rewriteAnchorDisplayFields($body, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
            $next = $this->rewriteKeyLabelFields($next, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
            // fn() captures by value, so pack entries added inside it were discarded
            // while the formula still referenced the missing token.
            $next = $this->mapOutsideI18n(
                $next,
                function (string $part) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean): string {
                    return $this->rewriteHtmlStringLiterals($part, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
                }
            );
        } elseif ($prop === 'Items') {
            $next = $this->rewriteItemsTableLiterals($body, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean, $tableBound);
        } else {
            $next = $this->rewriteQuotedUiLiterals($body, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
        }
        if ($next === $body) {
            return null;
        }

        return $hadEquals ? '=' . $next : $next;
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteDataSourceDisplayName(
        string $body,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean
    ): ?string {
        if (preg_match('/DataSourceInfo\s*\(\s*.+?,\s*DataSourceInfo\.DisplayName\s*,\s*(.+?)\s*\)\s*$/s', $body, $m) !== 1) {
            return null;
        }
        $field = trim($m[1]);
        $en = trim($field, "'\"");
        if ($en === '' || $this->isIgnorableGlyph($en)) {
            return null;
        }
        $token = $this->tokenFor($control, 'DisplayName', $en, $packs, $literalToToken);
        if (!isset($packs[$token])) {
            $packs[$token] = ['en' => $en, 'fr' => $en];
        }

        return $this->stringsBinding($stringsVar, $token, $langVar, $langIsBoolean);
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteItemsTableLiterals(
        string $body,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean,
        array &$tableBound = []
    ): string {
        $out = preg_replace_callback(
            '/\b(Title|Description|StepTitle|StepDesc|Label|Subtitle|Heading|Caption|Name)\s*:\s*"((?:[^"]|"")*)"/i',
            function (array $m) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean, &$tableBound): string {
                $lit = str_replace('""', '"', $m[2]);
                if (!$this->isUiLiteral($lit)) {
                    return $m[0];
                }
                $token = $this->tokenFor($control, $m[1], $lit, $packs, $literalToToken);
                if (!isset($packs[$token])) {
                    $packs[$token] = ['en' => $lit, 'fr' => $lit];
                }
                $tableBound[$lit] = $token;

                return $m[1] . ': ' . $this->stringsBinding($stringsVar, $token, $langVar, $langIsBoolean);
            },
            $body
        );

        return is_string($out) ? $out : $body;
    }

    /**
     * Gallery templates compare ThisItem.Title = "View My Pairing". After Items.Title
     * is localized, those comparisons must use the same gblStrings binding.
     *
     * @param list<ControlDocument> $documents
     * @param array<string, string> $tableBound
     * @param array<string, array{en:string,fr:string}> $packs
     */
    private function rewriteTableLiteralComparisons(
        array $documents,
        array $tableBound,
        array $packs,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        if ($tableBound === []) {
            return;
        }
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    continue;
                }
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $next = $raw;
                    foreach ($tableBound as $lit => $token) {
                        if (!isset($packs[$token])) {
                            continue;
                        }
                        $quoted = '"' . str_replace('"', '""', $lit) . '"';
                        if (!str_contains($next, $quoted)) {
                            continue;
                        }
                        $bind = $this->stringsBinding($stringsVar, $token, $langVar, $langIsBoolean);
                        $next = str_replace($quoted, $bind, $next);
                    }
                    if ($next !== $raw) {
                        $control->setProperty($prop, $next);
                        $report->add(self::id(), $control->path, $prop, $raw, $next);
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteQuotedUiLiterals(
        string $body,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean
    ): string {
        // Gallery labels match ThisItem.Value, which stays English. Rebinding
        // those quotes makes the match French and the button falls back to English.
        if (preg_match('/Switch\s*\(\s*ThisItem\.(?:Value|approval_state)\s*,/i', $body) === 1) {
            return $body;
        }
        $out = preg_replace_callback(
            '/"((?:[^"]|"")*)"/s',
            function (array $m) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean): string {
                $lit = str_replace('""', '"', $m[1]);
                if (str_contains($lit, '<') && preg_match('/[A-Za-z]{3,}/', $lit) === 1) {
                    return $this->injectHtmlPhrases($m[0], $lit, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
                }
                if (!$this->isUiLiteral($lit)) {
                    return $m[0];
                }
                $token = $this->tokenFor($control, 'Text', $lit, $packs, $literalToToken);
                if (!isset($packs[$token])) {
                    $packs[$token] = ['en' => $lit, 'fr' => $lit];
                }

                return $this->stringsBinding($stringsVar, $token, $langVar, $langIsBoolean);
            },
            $body
        );

        return is_string($out) ? $out : $body;
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function injectHtmlPhrases(
        string $quoted,
        string $inner,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean
    ): string {
        $chunk = $inner;
        $placeholders = [];
        $n = 0;
        $bind = function (string $en) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean, &$placeholders, &$n): ?string {
            if ($en === '' || !$this->isUiLiteral($en) || $this->isNonTranslatableFragment($en)) {
                return null;
            }
            $token = $this->tokenFor($control, 'HtmlText', $en, $packs, $literalToToken);
            if (!isset($packs[$token])) {
                $packs[$token] = ['en' => $en, 'fr' => $en];
            }
            $ph = "\x1Ebind{$n}\x1E";
            $placeholders[$ph] = '" & ' . $this->stringsBinding($stringsVar, $token, $langVar, $langIsBoolean) . ' & "';
            $n++;

            return $ph;
        };
        // Tag-bounded, longest first, so "Name" does not rewrite the middle of "FileName".
        $nodes = $this->htmlTextNodes($inner);
        usort($nodes, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($nodes as $en) {
            $pattern = '/>\s*' . preg_quote($en, '/') . '\s*</u';
            if (preg_match($pattern, $chunk) !== 1) {
                continue;
            }
            $ph = $bind($en);
            if ($ph === null) {
                continue;
            }
            $chunk = preg_replace($pattern, '>' . $ph . '<', $chunk, 1) ?? $chunk;
        }
        foreach ($this->htmlPhrases() as $en) {
            if ($en === '' || !str_contains(mb_strtolower($chunk), mb_strtolower($en))) {
                continue;
            }
            $ph = $bind($en);
            if ($ph === null) {
                continue;
            }
            $chunk = preg_replace('/' . preg_quote($en, '/') . '/iu', $ph, $chunk, 1) ?? $chunk;
        }
        if ($placeholders === []) {
            return $quoted;
        }
        $wrapped = '"' . str_replace('"', '""', $chunk) . '"';

        return strtr($wrapped, $placeholders);
    }

    /**
     * Visible copy between HTML tags (summary cards, help pages, PDF headers).
     *
     * @return list<string>
     */
    private function htmlTextNodes(string $html): array
    {
        if (preg_match_all('/>([^<]+)</u', $html, $m) !== 1 && ($m[1] ?? []) === []) {
            return [];
        }
        $nodes = [];
        foreach ($m[1] as $raw) {
            $t = trim(html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!$this->isUiLiteral($t) || $this->isNonTranslatableFragment($t)) {
                continue;
            }
            // Concatenations inside a tag ("Location: " & EmerAcomAddress) are not copy.
            if (preg_match('/&|\bThis(Record|Item)\b|\b(Parent|Self)\b|\b(var|gbl)[A-Z]/', $t) === 1) {
                continue;
            }
            $nodes[] = $t;
        }

        return $nodes;
    }

    /**
     * Section tabs store the visible name in Value and Label beside AnchorName.
     * Key / AnchorName stay stable so navigation does not break.
     *
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    /**
     * @param callable(string): string $map
     */
    private function mapOutsideI18n(string $body, callable $map): string
    {
        if (!str_contains($body, self::BLOCK_START)) {
            return $map($body);
        }
        $pattern = '/' . preg_quote(self::BLOCK_START, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . '/s';
        $parts = preg_split($pattern, $body);
        if (!is_array($parts) || !preg_match($pattern, $body, $block)) {
            return $map($body);
        }
        $rebuilt = '';
        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $rebuilt .= $block[0];
            }
            $rebuilt .= $map($part);
        }

        return $rebuilt;
    }

    private function rewriteAnchorDisplayFields(
        string $body,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean
    ): string {
        return $this->mapOutsideI18n(
            $body,
            function (string $part) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean): string {
                return $this->rewriteAnchorDisplayFieldsIn($part, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
            }
        );
    }

    /**
     * HTML built in App.OnStart / Formulas (summary cards, PDF) is not an HtmlText property.
     *
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteHtmlStringLiterals(
        string $body,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean
    ): string {
        $out = preg_replace_callback(
            '/"((?:[^"]|"")*)"/s',
            function (array $m) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean): string {
                $lit = str_replace('""', '"', $m[1]);
                if (!str_contains($lit, '<') || preg_match('/[A-Za-z]{3,}/', $lit) !== 1) {
                    return $m[0];
                }

                return $this->injectHtmlPhrases($m[0], $lit, $control, $packs, $literalToToken, $stringsVar, $langVar, $langIsBoolean);
            },
            $body
        );

        return is_string($out) ? $out : $body;
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteAnchorDisplayFieldsIn(
        string $body,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean
    ): string {
        // Section tabs display ThisItem.Label. Binding those fields to gblStrings
        // freezes them at startup, and a Switch in ItemDisplayText blanks the
        // modern tab list. The VCR profile keeps plain labels and a French copy.
        return $body;
    }

    /**
     * Email-token tables store the visible name in Label beside Key.
     * Key stays stable so templates keep working.
     *
     * @param array<string, array{en:string,fr:string}> $packs
     * @param array<string, string> $literalToToken
     */
    private function rewriteKeyLabelFields(
        string $body,
        ControlNode $control,
        array &$packs,
        array &$literalToToken,
        string $stringsVar,
        string $langVar,
        bool $langIsBoolean
    ): string {
        return $this->mapOutsideI18n(
            $body,
            function (string $part) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean): string {
                if (!str_contains($part, 'Key:')) {
                    return $part;
                }
                $out = preg_replace_callback(
                    '/\{[^{}]*\bKey\s*:\s*"(?:[^"]|"")*"[^{}]*\}/s',
                    function (array $rec) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean): string {
                        if (str_contains($rec[0], 'AnchorName')) {
                            return $rec[0];
                        }
                        $replaced = preg_replace_callback(
                            '/\bLabel\s*:\s*"((?:[^"]|"")*)"/',
                            function (array $m) use ($control, &$packs, &$literalToToken, $stringsVar, $langVar, $langIsBoolean): string {
                                $lit = str_replace('""', '"', $m[1]);
                                if (!$this->isUiLiteral($lit)) {
                                    return $m[0];
                                }
                                $token = $this->tokenFor($control, 'Label', $lit, $packs, $literalToToken);
                                if (!isset($packs[$token])) {
                                    $packs[$token] = ['en' => $lit, 'fr' => $lit];
                                }

                                return 'Label: ' . $this->stringsBinding($stringsVar, $token, $langVar, $langIsBoolean);
                            },
                            $rec[0]
                        );

                        return is_string($replaced) ? $replaced : $rec[0];
                    },
                    $part
                );

                return is_string($out) ? $out : $part;
            }
        );
    }

    /**
     * Classic dropdowns display the same text they store. Show French in the list
     * and map the selection back to the English value everywhere it is read,
     * so SharePoint choices and comparisons stay English.
     * Modern dropdowns that already have ItemDisplayText keep the English value
     * and only change the visible label.
     *
     * @param list<ControlDocument> $documents
     * @param array<string, string> $pairs
     */
    private function rewriteChoiceLists(
        array $documents,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        /** @var array<string, string> $pairs */
        $pairs = [];
        /** @var list<array{control: ControlNode, names: list<string>}> $classic */
        $classic = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $type = strtolower($control->type);
                if (!str_contains($type, 'dropdown') && !str_contains($type, 'combobox')) {
                    continue;
                }
                $this->repairChoiceChrome($control, $report);
                $itemsRaw = (string) ($control->getProperty('Items') ?? '');
                if ($itemsRaw === '' || str_contains($itemsRaw, 'fnUiFr') || preg_match('/\bIf\s*\(\s*' . preg_quote($langVar, '/') . '\s*,/i', $itemsRaw) === 1) {
                    continue;
                }
                $body = trim(ltrim(trim($itemsRaw), '='));
                $trim = trim($body);
                $frBody = null;
                if (!str_starts_with($trim, '[') || !str_ends_with($trim, ']')) {
                    $named = $this->namedChoiceArray($documents, $body);
                    if ($named !== null) {
                        $body = $named;
                    } else {
                        $body = $this->stripChoiceComments($this->fillBlankChoiceBranch($body));
                        $frBody = $this->translateQuotedChoices($body, $pairs);
                        if ($frBody === null) {
                            continue;
                        }
                    }
                }
                if ($frBody === null) {
                    $frBody = $this->translateStringArray($body, $pairs);
                    if ($frBody === null) {
                        continue;
                    }
                }
                $prefix = str_starts_with(trim($itemsRaw), '=') || $control->format === 'yaml' ? '=' : '';
                $nextItems = $prefix . 'If(' . $isEn . ', ' . $body . ', ' . $frBody . ')';
                $control->setProperty('Items', $nextItems);
                $report->add(self::id(), $control->path, 'Items', $itemsRaw, $nextItems);
                $classic[] = ['control' => $control, 'fields' => ['Value' => true]];
                $defaultRaw = (string) ($control->getProperty('Default') ?? '');
                if ($defaultRaw !== '' && !str_contains($defaultRaw, 'fnUiFr')) {
                    $defaultBody = trim(ltrim(trim($defaultRaw), '='));
                    if (preg_match('/\bBlank\s*\(/', $defaultBody) === 1) {
                        $wrapped = 'With({_psChoice: ' . $defaultBody . '}, If(IsBlank(_psChoice), Blank(), If(' . $isEn . ', _psChoice, fnUiFr(_psChoice))))';
                    } else {
                        $wrapped = 'If(' . $isEn . ', ' . $defaultBody . ', fnUiFr(' . $defaultBody . '))';
                    }
                    $control->setProperty('Default', $prefix . $wrapped);
                    $report->add(self::id(), $control->path, 'Default', $defaultRaw, $prefix . $wrapped);
                }
            }
        }
        $this->rewriteRecordChoiceCollections($documents, $isEn, $pairs, $classic, $report);
        if ($pairs === [] || $classic === []) {
            return;
        }
        $this->addChoiceCanonFormulas($documents, $pairs, $report);
        /** @var array<string, array<string, true>> $fieldsByName */
        $fieldsByName = [];
        foreach ($classic as $row) {
            foreach ($row['fields'] as $field => $_) {
                $fieldsByName[$row['control']->name][$field] = true;
            }
        }
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    if ($prop === 'Default') {
                        continue;
                    }
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '' || preg_match('/Selected\.(?:Value|Name|Acronym)\b/', $raw) !== 1) {
                        continue;
                    }
                    $next = preg_replace_callback(
                        '/(?<!fnUiEn\()((?:(?:\'[^\']+\'|[A-Za-z_][A-Za-z0-9_]*)\.)*)((?:\'[^\']+\'|[A-Za-z_][A-Za-z0-9_]*))\.Selected\.(Value|Name|Acronym)\b/',
                        static function (array $match) use ($fieldsByName): string {
                            $bare = trim($match[2], "'");
                            if (!isset($fieldsByName[$bare][$match[3]])) {
                                return $match[0];
                            }

                            return 'fnUiEn(' . $match[0] . ')';
                        },
                        $raw
                    ) ?? $raw;
                    foreach (array_keys($fieldsByName[$control->name] ?? []) as $field) {
                        $next = preg_replace(
                            '/(?<!fnUiEn\()Self\.Selected\.' . $field . '\b/',
                            'fnUiEn(Self.Selected.' . $field . ')',
                            $next
                        ) ?? $next;
                    }
                    if ($next !== $raw) {
                        $control->setProperty($prop, $next);
                        $report->add(self::id(), $control->path, $prop, $raw, $next);
                    }
                }
            }
        }
    }

    private function repairChoiceChrome(ControlNode $control, Report $report): void
    {
        $prefix = $control->format === 'yaml' ? '=' : '';
        $set = function (string $prop, string $expr) use ($control, $report, $prefix): void {
            $raw = (string) ($control->getProperty($prop) ?? '');
            if (trim(ltrim(trim($raw), '=')) === $expr) {
                return;
            }
            $next = $prefix . $expr;
            $control->setProperty($prop, $next, 'Design', 'User');
            $report->add(self::id(), $control->path, $prop, $raw !== '' ? $raw : '(unset)', $next);
        };

        $font = trim(ltrim(trim((string) ($control->getProperty('Font') ?? '')), '='));
        if ($font === '' || $font === '""' || $font === "''") {
            $set('Font', "Font.'Open Sans'");
        }
        if (trim((string) ($control->getProperty('Size') ?? '')) === '' && str_contains(strtolower($control->type), 'modern')) {
            $set('Size', '14');
        }
        if (trim((string) ($control->getProperty('Color') ?? '')) === '') {
            $set('Color', 'gblTheme.InputText');
        }
        $selection = trim(ltrim(trim((string) ($control->getProperty('SelectionColor') ?? '')), '='));
        if (preg_match('/^RGBA\(\s*255\s*,\s*255\s*,\s*255\s*,\s*1\s*\)$/i', $selection) === 1) {
            $set('SelectionColor', 'gblTheme.InputText');
            $selectionFill = trim(ltrim(trim((string) ($control->getProperty('SelectionFill') ?? '')), '='));
            if ($selectionFill === '' || str_starts_with($selectionFill, 'RGBA(')) {
                $set('SelectionFill', 'If(gblDarkMode, RGBA(55, 65, 85, 1), RGBA(219, 234, 254, 1))');
            }
        }
        $hover = trim(ltrim(trim((string) ($control->getProperty('HoverFill') ?? '')), '='));
        if (preg_match('/^RGBA\(\s*186\s*,\s*202\s*,\s*226\s*,\s*1\s*\)$/i', $hover) === 1) {
            $set('HoverFill', 'If(gblDarkMode, RGBA(50, 50, 58, 1), RGBA(226, 232, 240, 1))');
        }
        $height = $this->choiceNumber($control->getProperty('Height'));
        $size = $this->choiceNumber($control->getProperty('Size'));
        $padTop = $this->choiceNumber($control->getProperty('PaddingTop'));
        $padBottom = $this->choiceNumber($control->getProperty('PaddingBottom'));
        if ($height !== null && $size !== null && $padTop !== null && $padBottom !== null
            && ($padTop + $padBottom + $size) >= ($height - 1)) {
            $set('PaddingTop', '4');
            $set('PaddingBottom', '4');
        }
    }

    private function choiceNumber(mixed $raw): ?float
    {
        $text = trim(ltrim(trim((string) $raw), '='));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }

    /**
     * A lookup dropdown that returns Blank() until a status is already chosen
     * has nothing to show. Use the choice list itself in that branch.
     */
    private function fillBlankChoiceBranch(string $body): string
    {
        $updated = preg_replace_callback(
            '/If\s*\(\s*IsBlank\s*\(\s*\w+\s*\)\s*,\s*Blank\s*\(\s*\)\s*,\s*Filter\s*\(\s*(\[[\s\S]*?\])/i',
            static function (array $match): string {
                return preg_replace('/Blank\s*\(\s*\)/', $match[1], $match[0], 1) ?? $match[0];
            },
            $body
        );

        return is_string($updated) ? $updated : $body;
    }

    private function stripChoiceComments(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*\/\//', $line) === 1) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    /**
     * @param array<string, string> $pairs
     */
    private function translateQuotedChoices(string $body, array &$pairs): ?string
    {
        $lits = $this->quotedUiLiterals($body);
        if ($lits === []) {
            return null;
        }
        usort($lits, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $frBody = $body;
        $changed = false;
        foreach ($lits as $en) {
            $fr = $this->frenchFallback($en);
            if ($fr === $en) {
                continue;
            }
            $changed = true;
            $pairs[$en] = $fr;
            $from = '"' . str_replace('"', '""', $en) . '"';
            $to = '"' . str_replace('"', '""', $fr) . '"';
            $frBody = str_replace($from, $to, $frBody);
        }

        return $changed ? $frBody : null;
    }

    /**
     * Record lists such as colL1Orgs keep English in the original collection.
     * A French copy is what the dropdown shows, and selected Name/Acronym reads
     * are mapped back to English.
     *
     * @param list<ControlDocument> $documents
     * @param array<string, string> $pairs
     * @param list<array{control: ControlNode, fields: array<string, true>}> $classic
     */
    private function rewriteRecordChoiceCollections(
        array $documents,
        string $isEn,
        array &$pairs,
        array &$classic,
        Report $report
    ): void {
        /** @var array<string, array<string, true>> $fieldsByCollection */
        $fieldsByCollection = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach (['OnStart', 'Formulas'] as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '' || !str_contains($raw, 'ClearCollect')) {
                        continue;
                    }
                    $updated = $this->appendFrenchChoiceCollects($raw, $pairs, $fieldsByCollection);
                    if ($updated === $raw) {
                        continue;
                    }
                    $control->setProperty($prop, $updated);
                    $report->add(self::id(), $control->path, $prop, '(record choices)', 'French choice collections');
                }
            }
        }
        if ($fieldsByCollection === []) {
            return;
        }
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $type = strtolower($control->type);
                if (!str_contains($type, 'dropdown') && !str_contains($type, 'combobox')) {
                    continue;
                }
                $itemsRaw = (string) ($control->getProperty('Items') ?? '');
                $body = trim(ltrim(trim($itemsRaw), '='));
                if ($body === '' || str_contains($body, 'fnUiFr')) {
                    continue;
                }
                $matched = null;
                $matchedFields = [];
                foreach ($fieldsByCollection as $name => $fields) {
                    if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $body) !== 1) {
                        continue;
                    }
                    if (preg_match('/\b' . preg_quote($name . 'Fr', '/') . '\b/', $body) === 1) {
                        continue;
                    }
                    $matched = $name;
                    $matchedFields = $fields;
                    break;
                }
                if ($matched === null) {
                    continue;
                }
                $frBody = preg_replace('/\b' . preg_quote($matched, '/') . '\b/', $matched . 'Fr', $body) ?? $body;
                $prefix = str_starts_with(trim($itemsRaw), '=') || $control->format === 'yaml' ? '=' : '';
                $nextItems = $prefix . 'If(' . $isEn . ', ' . $body . ', ' . $frBody . ')';
                $control->setProperty('Items', $nextItems);
                $report->add(self::id(), $control->path, 'Items', $itemsRaw, $nextItems);
                $classic[] = ['control' => $control, 'fields' => $matchedFields];
                $defaultRaw = (string) ($control->getProperty('Default') ?? '');
                if ($defaultRaw !== '' && !str_contains($defaultRaw, 'fnUiFr')) {
                    $defaultBody = trim(ltrim(trim($defaultRaw), '='));
                    $wrapped = 'If(' . $isEn . ', ' . $defaultBody . ', fnUiFr(' . $defaultBody . '))';
                    $control->setProperty('Default', $prefix . $wrapped);
                    $report->add(self::id(), $control->path, 'Default', $defaultRaw, $prefix . $wrapped);
                }
            }
        }
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, array<string, true>> $fieldsByCollection
     */
    private function appendFrenchChoiceCollects(string $raw, array &$pairs, array &$fieldsByCollection): string
    {
        $updated = preg_replace_callback(
            '/ClearCollect\s*\(\s*(col[A-Za-z0-9_]+)\s*,([\s\S]*?)\)\s*;/',
            function (array $match) use (&$pairs, &$fieldsByCollection, $raw): string {
                $name = $match[1];
                if (str_ends_with($name, 'Fr')) {
                    return $match[0];
                }
                if (preg_match('/ClearCollect\s*\(\s*' . preg_quote($name . 'Fr', '/') . '\b/', $raw) === 1) {
                    return $match[0];
                }
                $fields = [];
                $french = preg_replace_callback(
                    '/\b(Name|Acronym)\s*:\s*"((?:[^"]|"")*)"/',
                    function (array $field) use (&$pairs, &$fields): string {
                        $en = str_replace('""', '"', $field[2]);
                        if (!$this->isUiLiteral($en)) {
                            return $field[0];
                        }
                        $fr = $this->frenchFallback($en);
                        if ($fr === $en) {
                            return $field[0];
                        }
                        $pairs[$en] = $fr;
                        $fields[$field[1]] = true;

                        return $field[1] . ': "' . str_replace('"', '""', $fr) . '"';
                    },
                    $match[2]
                );
                if ($fields === [] || !is_string($french)) {
                    return $match[0];
                }
                $fieldsByCollection[$name] = $fields;

                return $match[0] . "\nClearCollect(" . $name . 'Fr,' . $french . ');';
            },
            $raw
        );

        return is_string($updated) ? $updated : $raw;
    }

    /**
     * Items = ApprovalChoices, where App.Formulas defines that name as a string array.
     *
     * @param list<ControlDocument> $documents
     */
    private function namedChoiceArray(array $documents, string $itemsBody): ?string
    {
        $name = preg_replace('/\/\*[\s\S]*?\*\//', '', $itemsBody) ?? $itemsBody;
        $name = preg_replace('/\/\/[^\n]*/', '', $name) ?? $name;
        $name = trim($name);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return null;
        }
        $pattern = '/(?:^|[;\n])[ \t]*' . preg_quote($name, '/') . '\s*=\s*(\[[\s\S]*?\])\s*;/';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isApp()) {
                    continue;
                }
                $formulas = (string) ($control->getProperty('Formulas') ?? '');
                if (preg_match_all($pattern, $formulas, $matches) < 1) {
                    continue;
                }
                $last = $matches[1][count($matches[1]) - 1] ?? null;
                if (is_string($last) && str_contains($last, '"')) {
                    return $last;
                }
            }
        }

        return null;
    }

    /**
     * Modern lists keep the English value. Translate the visible label only.
     *
     * @param array<string, string> $pairs
     */
    private function setChoiceDisplay(
        ControlNode $control,
        string $body,
        array $pairs,
        string $isEn,
        Report $report
    ): void {
        $type = strtolower($control->type);
        if (!str_contains($type, 'modern') && !$control->hasProperty('ItemDisplayText')) {
            return;
        }
        $arms = [];
        foreach ($this->quotedUiLiterals($body) as $en) {
            $fr = $pairs[$en] ?? $this->frenchFallback($en);
            if ($fr === $en) {
                continue;
            }
            $arms[] = '"' . $this->escapeFx($en) . '", "' . $this->escapeFx($fr) . '"';
        }
        if ($arms === []) {
            return;
        }
        $display = (string) ($control->getProperty('ItemDisplayText') ?? '');
        $displayBody = trim(ltrim(trim($display), '='));
        $switch = 'If(' . $isEn . ', ThisItem.Value, Switch(ThisItem.Value, ' . implode(', ', $arms) . ', ThisItem.Value))';
        if ($displayBody === '' || $displayBody === 'ThisItem.Value') {
            $next = $switch;
        } elseif (preg_match('/^ThisItem\.Value\b/', $displayBody) === 1 && !str_contains($displayBody, 'If(' . $isEn)) {
            $next = preg_replace('/^ThisItem\.Value\b/', $switch, $displayBody, 1) ?? $displayBody;
        } else {
            return;
        }
        $prefix = str_starts_with(trim($display), '=') || $control->format === 'yaml' ? '=' : '';
        $control->setProperty('ItemDisplayText', $prefix . $next);
        $report->add(self::id(), $control->path, 'ItemDisplayText', $display !== '' ? $display : '(unset)', $prefix . $next);
    }

    /** @return list<string> */
    private function quotedUiLiterals(string $items): array
    {
        $body = trim(ltrim(trim($items), '='));
        $body = preg_replace('/\/\*[\s\S]*?\*\//', '', $body) ?? $body;
        $body = preg_replace('/\/\/[^\n]*/', '', $body) ?? $body;
        if (preg_match_all('/"((?:[^"]|"")*)"/', $body, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $lit) {
            $lit = str_replace('""', '"', $lit);
            if ($lit !== '' && $this->isUiLiteral($lit)) {
                $out[] = $lit;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, string> $pairs
     */
    private function translateStringArray(string $body, array &$pairs): ?string
    {
        $lits = $this->stringArrayLiterals('=' . $body);
        if ($lits === []) {
            return null;
        }
        usort($lits, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $changed = false;
        $frBody = $body;
        foreach ($lits as $en) {
            $fr = $this->frenchFallback($en);
            if ($fr === $en) {
                continue;
            }
            $changed = true;
            $pairs[$en] = $fr;
            $from = '"' . str_replace('"', '""', $en) . '"';
            $to = '"' . str_replace('"', '""', $fr) . '"';
            $frBody = str_replace($from, $to, $frBody);
        }

        return $changed ? $frBody : null;
    }

    /**
     * @param list<ControlDocument> $documents
     * @param array<string, string> $pairs
     */
    private function addChoiceCanonFormulas(array $documents, array $pairs, Report $report): void
    {
        $enArms = [];
        $frArms = [];
        foreach ($pairs as $en => $fr) {
            $enArms[] = '"' . $this->escapeFx($fr) . '", "' . $this->escapeFx($en) . '"';
            $frArms[] = '"' . $this->escapeFx($en) . '", "' . $this->escapeFx($fr) . '"';
        }
        $fn = 'fnUiEn(v: Text): Text = Switch(v, ' . implode(', ', $enArms) . ', v);'
            . "\n" . 'fnUiFr(v: Text): Text = Switch(v, ' . implode(', ', $frArms) . ', v);';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isApp()) {
                    continue;
                }
                $raw = (string) ($control->getProperty('Formulas') ?? '');
                if ($raw === '' || str_contains($raw, 'fnUiEn')) {
                    continue;
                }
                $body = rtrim(rtrim(ltrim(trim($raw), '=')), ";\n\r\t ");
                $control->setProperty('Formulas', '=' . $body . ";\n" . $fn);
                $report->add(self::id(), $control->path, 'Formulas', '(choice canon)', 'fnUiEn');
            }
        }
    }

    /**
     * Gallery buttons and dropdowns show ThisItem.Value, which is also the stored
     * value comparisons use. Translate the display only.
     *
     * @param list<ControlDocument> $documents
     */
    private function rewriteStableValueDisplays(
        array $documents,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $this->rewriteFieldDisplay($control, 'Text', 'ThisItem.approval_state', $this->statusDisplayArms(), $isEn, $report);
                $literals = $this->nearestStringItems($documents, $control);
                if ($literals === []) {
                    continue;
                }
                $arms = [];
                foreach ($literals as $en) {
                    $fr = $this->frenchFallback($en);
                    if ($fr === $en) {
                        continue;
                    }
                    $arms[] = '"' . $this->escapeFx($en) . '", "' . $this->escapeFx($fr) . '"';
                }
                if ($arms === []) {
                    continue;
                }
                foreach (['Text', 'ItemDisplayText'] as $prop) {
                    $this->rewriteFieldDisplay($control, $prop, 'ThisItem.Value', $arms, $isEn, $report);
                }
            }
        }
    }

    /**
     * @param list<string> $arms
     */
    private function rewriteFieldDisplay(
        ControlNode $control,
        string $prop,
        string $source,
        array $arms,
        string $isEn,
        Report $report
    ): void {
        if ($arms === []) {
            return;
        }
        $raw = $control->getProperty($prop);
        if ($raw === null) {
            return;
        }
        $body = trim(ltrim(trim($raw), '='));
        $expr = 'If(' . $isEn . ', ' . $source . ', Switch(' . $source . ', ' . implode(', ', $arms) . ', ' . $source . '))';
        if ($body === $expr) {
            return;
        }
        $stale = $body === $source
            || (str_contains($body, 'gblStrings') && preg_match('/Switch\s*\(\s*' . preg_quote($source, '/') . '\s*,/i', $body) === 1);
        if (!$stale) {
            return;
        }
        $to = '=' . $expr;
        $control->setProperty($prop, $to);
        $report->add(self::id(), $control->path, $prop, $raw, $to);
    }

    /** @return list<string> */
    private function statusDisplayArms(): array
    {
        $arms = [];
        foreach (['Acknowledged', 'Awaiting Acknowledgment', 'Awaiting Acknowledgement', 'Not Acknowledged'] as $en) {
            $fr = $this->frenchFallback($en);
            if ($fr === $en) {
                continue;
            }
            $arms[] = '"' . $this->escapeFx($en) . '", "' . $this->escapeFx($fr) . '"';
        }

        return $arms;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return list<string>
     */
    private function nearestStringItems(array $documents, ControlNode $control): array
    {
        $best = '';
        $literals = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $candidate) {
                if ($candidate->path === $control->path || !str_starts_with($control->path, $candidate->path . '/')) {
                    continue;
                }
                if (strlen($candidate->path) < strlen($best)) {
                    continue;
                }
                $items = (string) ($candidate->getProperty('Items') ?? '');
                $found = $this->stringArrayLiterals($items);
                if ($found === []) {
                    continue;
                }
                $best = $candidate->path;
                $literals = $found;
            }
        }

        return $literals;
    }

    /** @return list<string> */
    private function stringArrayLiterals(string $items): array
    {
        $body = trim(ltrim(trim($items), '='));
        if (!str_contains($body, '[')) {
            return [];
        }
        if (preg_match('/\b(Filter|LookUp|Sort|ShowColumns|AddColumns)\s*\(/i', $body) === 1) {
            return [];
        }
        if (preg_match_all('/"((?:[^"]|"")*)"/', $body, $m) < 1) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $lit) {
            $lit = str_replace('""', '"', $lit);
            if ($lit !== '' && $this->isUiLiteral($lit)) {
                $out[] = $lit;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function htmlPhrases(): array
    {
        return [
            'View and manage your account details and participant information.',
            "Please Fill In All Information Truthfully. Click 'Next!' to continue.",
            'Please Fill In All Information Truthfully. Click \'Next!\' to continue.',
            'TDR — My Account',
            'TDR - My Account',
            'My Account',
            'My Profile',
            'Admin Portal',
        ];
    }

    private function isUiLiteral(string $lit): bool
    {
        $t = trim($lit);
        if ($t === '' || $this->isIgnorableGlyph($t) || $this->isCommentOnly($t) || $this->isTechnicalLiteral($t)) {
            return false;
        }
        if (preg_match('/^(english|french|light|dark|en|fr|true|false)$/i', $t) === 1) {
            return false;
        }
        // SharePoint keys (ivco_notice_en, ack_deny) are not visible copy.
        if (preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)+$/', $t) === 1) {
            return false;
        }
        if (preg_match('/^(data:|https?:|#[0-9A-Fa-f]{3,8}$)/i', $t) === 1) {
            return false;
        }
        if (str_starts_with($t, '<') || str_contains($t, 'data:image') || str_contains($t, '<svg')) {
            return false;
        }
        if (preg_match('/[A-Za-z]/', $t) !== 1) {
            return false;
        }
        if (mb_strlen($t) < 2) {
            return false;
        }

        return true;
    }

    /** @param list<string> $langVars */
    private function isLangOnlyFormula(string $body, array $langVars): bool
    {
        if (preg_match('/\b(Navigate|Patch|Collect|ClearCollect|Notify|Remove|Refresh|UpdateContext|LookUp|With|Select)\b/i', $body) === 1) {
            return false;
        }
        $q = implode('|', array_map(static fn(string $v): string => preg_quote($v, '/'), $langVars));

        return preg_match('/\bSet\s*\(\s*(?:' . $q . ')\s*,/i', $body) === 1
            || preg_match('/\bIf\s*\(\s*(?:Self\.Selected|!' . $q . '|' . $q . ')/i', $body) === 1;
    }

    private function formulaAlreadyCanonical(string $body, string $canonical): bool
    {
        $norm = static fn(string $s): string => strtolower(preg_replace('/\s+/', '', $s) ?? $s);

        return str_contains($norm($body), $norm($canonical));
    }

    private function injectLanguageRadio(
        ControlNode $screen,
        string $langVar,
        string $stringsVar,
        Report $report
    ): void {
        $onChange = 'Set(' . $langVar . ', If(' . $this->selectedEnglishExpr() . ', "en", "fr"))';
        $screen->addYamlChild(self::RADIO_NAME, [
            'Control' => 'Classic/Radio@2.1.0',
            'Properties' => [
                'Items' => '=[{Value: "English"}, {Value: "French"}]',
                'Layout' => '=Layout.Horizontal',
                'AccessibleLabel' => '="Language"',
                'Tooltip' => '="Switch UI language — edit En/Fr side-by-side in App.OnStart ' . $stringsVar . '"',
                'DefaultSelectedItems' => '=If(' . $langVar . ' = "fr", [{Value: "French"}], [{Value: "English"}])',
                'OnChange' => '=' . $onChange,
                'X' => '=16',
                'Y' => '=64',
                'Width' => '=260',
                'Height' => '=40',
            ],
        ]);
        $report->add(
            self::id(),
            $screen->path,
            'Children',
            '(missing language control)',
            self::RADIO_NAME . ' injected'
        );
    }

    /**
     * Settings Language Preference panels are often shipped Visible=false (VCR).
     * Unhide them so the English/French radio appears next to Theme like makers expect.
     *
     * @param list<ControlDocument> $documents
     * @param list<ControlNode> $langControls
     */
    private function unhideLanguagePreferencePanels(array $documents, array $langControls, Report $report): void
    {
        $byPath = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $byPath[$control->path] = $control;
            }
        }

        $candidates = [];
        foreach ($langControls as $langControl) {
            $path = $langControl->path;
            while (($pos = strrpos($path, '/')) !== false) {
                $path = substr($path, 0, $pos);
                if ($path === '' || !isset($byPath[$path])) {
                    continue;
                }
                $parent = $byPath[$path];
                $name = strtolower($parent->name);
                if (
                    str_contains($name, 'languagepreference')
                    || $name === 'language'
                    || (str_contains($name, 'language') && str_contains(strtolower($parent->type), 'group'))
                ) {
                    $candidates[$parent->path] = $parent;
                }
            }
        }
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (strcasecmp($control->name, 'LanguagePreference') === 0) {
                    $candidates[$control->path] = $control;
                }
            }
        }

        foreach ($candidates as $panel) {
            $visible = (string) ($panel->getProperty('Visible') ?? '');
            $body = strtolower(trim(ltrim(trim($visible), '=')));
            if ($body === '' || $body === 'true') {
                continue;
            }
            if ($body !== 'false') {
                continue;
            }
            $to = $panel->format === 'yaml' ? '=true' : 'true';
            $panel->setProperty('Visible', $to);
            $report->add(
                self::id(),
                $panel->path,
                'Visible',
                $visible !== '' ? $visible : '(unset)',
                $to
            );
        }
    }

    /**
     * Place an EN/FR pill slider in the top bar beside Settings (SettingsAndHelpCont).
     * Animation reads the language var each tick (radio + labels stay in sync) with a
     * faster lerp than the sample Language Slider Example (Duration 10, factor 0.6, snap).
     *
     * @param list<ControlDocument> $documents
     */
    private function ensureLanguageSlider(
        array $documents,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        $byPath = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $byPath[$control->path] = $control;
            }
        }

        // Studio draws the component from Components/*.json and the copy embedded
        // on each screen. A YAML-only child never appears in the tree view.
        foreach ($this->topBarLanguageHosts($documents) as $host) {
            $existing = null;
            foreach ($host->children as $child) {
                if (strcasecmp($child->name, self::SLIDER_NAME) === 0) {
                    $existing = $child;
                    break;
                }
            }
            if ($existing !== null) {
                $this->wireExistingLanguageSlider($existing, $langVar, $langIsBoolean, $report);
                continue;
            }
            if ($host->format === 'yaml') {
                $this->injectLanguageSlider($host, $langVar, $langIsBoolean, $report);
            } else {
                $this->injectLanguageSliderJson($host, $langVar, $langIsBoolean, $report);
            }
        }

        // Drop a slider that an earlier pass parked on the settings panel header.
        foreach ($byPath as $control) {
            if (strcasecmp($control->name, self::SLIDER_NAME) !== 0) {
                continue;
            }
            if ($this->pathIsTopBar($control->path)) {
                continue;
            }
            $parentPath = substr($control->path, 0, (int) strrpos($control->path, '/'));
            $parent = $byPath[$parentPath] ?? null;
            if ($parent !== null && $parent->removeYamlChild(self::SLIDER_NAME)) {
                $report->add(
                    self::id(),
                    $parent->path,
                    'Children',
                    self::SLIDER_NAME . ' in settings panel',
                    self::SLIDER_NAME . ' removed (relocating to top bar)'
                );
            }
        }
    }

    /**
     * Every top-bar chrome host, YAML definition and JSON copies.
     *
     * @param list<ControlDocument> $documents
     * @return list<ControlNode>
     */
    private function topBarLanguageHosts(array $documents): array
    {
        $hosts = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $name = strtolower($control->name);
                if ($name !== 'settingsandhelpcont' && $name !== 'settingsandhelp' && $name !== 'topbaractions') {
                    continue;
                }
                $hasSettings = false;
                foreach ($control->children as $child) {
                    $childName = strtolower($child->name);
                    if ($childName === 'settingscont' || $childName === 'settingsbutton' || $childName === 'settingslabel') {
                        $hasSettings = true;
                        break;
                    }
                }
                if ($hasSettings) {
                    $hosts[] = $control;
                }
            }
        }

        return $hosts;
    }

    private function pathIsTopBar(string $path): bool
    {
        $path = strtolower($path);

        return str_contains($path, 'settingsandhelpcont')
            || str_contains($path, 'settingsandhelp/')
            || str_contains($path, 'topbaractions');
    }

    /**
     * Top-bar chrome that holds Settings / Help (VCR: SettingsAndHelpCont).
     * Prefer YAML component definitions — Controls/*.json instances share the name
     * and cannot receive addYamlChild.
     *
     * @param list<ControlDocument> $documents
     */
    private function findTopBarLanguageHost(array $documents): ?ControlNode
    {
        $candidates = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $name = strtolower($control->name);
                if (
                    $name === 'settingsandhelpcont'
                    || $name === 'settingsandhelp'
                    || $name === 'topmenu'
                    || $name === 'topbaractions'
                ) {
                    $candidates[] = $control;
                    continue;
                }
                // Horizontal strip that already hosts a SettingsCont / Settings button.
                if (!str_contains(strtolower($control->type), 'group')) {
                    continue;
                }
                $dir = strtolower((string) ($control->getProperty('LayoutDirection') ?? ''));
                if (!str_contains($dir, 'horizontal')) {
                    continue;
                }
                if ($this->hasSettingsChromeChild($control)) {
                    $candidates[] = $control;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            fn(ControlNode $a, ControlNode $b): int => $this->topBarHostScore($b) <=> $this->topBarHostScore($a)
        );
        $best = $candidates[0];

        return $best->format === 'yaml' && $this->topBarHostScore($best) > 0 ? $best : null;
    }

    private function hasSettingsChromeChild(ControlNode $host): bool
    {
        foreach ($host->children as $child) {
            $n = strtolower($child->name);
            if (
                $n === 'settingscont'
                || $n === 'settingsbutton'
                || $n === 'settingslabel'
                || str_contains($n, 'settings')
            ) {
                return true;
            }
        }

        return false;
    }

    private function topBarHostScore(ControlNode $control): int
    {
        $score = 0;
        if ($control->format === 'yaml') {
            $score += 50;
        }
        $path = strtolower($control->path);
        if (str_contains($path, 'componentdefinitions') || str_contains($path, 'src/components')) {
            $score += 40;
        }
        $name = strtolower($control->name);
        if ($name === 'settingsandhelpcont' || $name === 'settingsandhelp') {
            $score += 80;
        }
        // Penalize the settings *panel* header (big "Settings" title) — not the top bar.
        if ($name === 'settingscontainer') {
            $score -= 100;
        }
        if ($this->hasSettingsChromeChild($control)) {
            $score += 40;
        }

        return $score;
    }

    private function wireExistingLanguageSlider(
        ControlNode $slider,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        $setEn = $this->sliderClickFormula($langVar, $langIsBoolean, true);
        $setFr = $this->sliderClickFormula($langVar, $langIsBoolean, false);
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $timerEnd = $this->sliderTimerFormula($langVar, $langIsBoolean);
        foreach ($slider->children as $child) {
            $n = strtolower($child->name);
            if (
                $n === 'pslangen'
                || $n === 'pslangenhit'
                || str_ends_with($n, 'enlabel')
                || $n === 'enlabel'
            ) {
                $this->replaceLangOnlyFormula($child, 'OnSelect', $setEn, [$langVar], $report);
            }
            if (
                $n === 'pslangfr'
                || $n === 'pslangfrhit'
                || str_ends_with($n, 'frlabel')
                || $n === 'frlabel'
            ) {
                $this->replaceLangOnlyFormula($child, 'OnSelect', $setFr, [$langVar], $report);
            }
            if ($n === 'pslangslidertimer') {
                $before = (string) ($child->getProperty('OnTimerEnd') ?? '');
                $to = $child->format === 'yaml' ? '=' . $timerEnd : $timerEnd;
                if (trim(ltrim(trim($before), '=')) !== $timerEnd) {
                    $child->setProperty('OnTimerEnd', $to);
                    $report->add(self::id(), $child->path, 'OnTimerEnd', $before !== '' ? $before : '(empty)', $to);
                }
            }
        }
    }

    private function injectLanguageSlider(
        ControlNode $host,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        $enX = (string) self::KNOB_EN_X;
        $frX = (string) self::KNOB_FR_X;
        $knob = self::KNOB_X;
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $setEn = $this->sliderClickFormula($langVar, $langIsBoolean, true);
        $setFr = $this->sliderClickFormula($langVar, $langIsBoolean, false);
        // varPsLangKnobX is Set in the i18n OnStart block so the name is valid.
        // The visible knob follows varLang directly so a click moves it even if the timer is blocked.
        $timerEnd = $this->sliderTimerFormula($langVar, $langIsBoolean);

        $accent = 'If(gblDarkMode, RGBA(62, 62, 68, 1), RGBA(196, 194, 192, 1))';
        $text = 'If(gblDarkMode, RGBA(230, 230, 232, 1), RGBA(50, 49, 48, 1))';

        // Sit beside SettingsCont in the top bar (before it when present).
        $beforeSibling = null;
        foreach ($host->children as $child) {
            if (strcasecmp($child->name, 'SettingsCont') === 0) {
                $beforeSibling = 'SettingsCont';
                break;
            }
        }

        $host->addYamlChild(self::SLIDER_NAME, [
            'Control' => 'GroupContainer@1.5.0',
            'Variant' => 'ManualLayout',
            'Properties' => [
                'BorderColor' => '=' . (str_contains($accent, 'gblTheme') ? 'gblTheme.BorderStrong' : 'RGBA(0, 0, 0, 0)'),
                'DropShadow' => '=DropShadow.None',
                'FillPortions' => '=0',
                'Height' => '=36',
                'LayoutMinHeight' => '=36',
                'LayoutMinWidth' => '=88',
                'Width' => '=88',
                'AccessibleLabel' => '="Language"',
            ],
            'Children' => [
                [
                    'psLangTrackMid' => [
                        'Control' => 'Rectangle@2.3.0',
                        'Properties' => [
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'BorderStyle' => '=BorderStyle.None',
                            'Fill' => '=' . $accent,
                            'Height' => '=28',
                            'Width' => '=50',
                            'X' => '=19',
                            'Y' => '=4',
                        ],
                    ],
                ],
                [
                    'psLangLeftCap' => [
                        'Control' => 'Circle@2.3.0',
                        'Properties' => [
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'BorderStyle' => '=BorderStyle.None',
                            'Fill' => '=' . $accent,
                            'Height' => '=28',
                            'Width' => '=28',
                            'X' => '=0',
                            'Y' => '=4',
                        ],
                    ],
                ],
                [
                    'psLangRightCap' => [
                        'Control' => 'Circle@2.3.0',
                        'Properties' => [
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'BorderStyle' => '=BorderStyle.None',
                            'Fill' => '=' . $accent,
                            'Height' => '=28',
                            'Width' => '=28',
                            'X' => '=60',
                            'Y' => '=4',
                        ],
                    ],
                ],
                [
                    'psLangKnob' => [
                        'Control' => 'Circle@2.3.0',
                        'Properties' => [
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'BorderStyle' => '=BorderStyle.None',
                            'Fill' => '=RGBA(255, 255, 255, 1)',
                            'Height' => '=24',
                            'Width' => '=40',
                            'X' => '=If(' . $isEn . ', ' . $enX . ', ' . $frX . ')',
                            'Y' => '=6',
                        ],
                    ],
                ],
                [
                    'psLangEN' => [
                        'Control' => 'Label@2.5.1',
                        'Properties' => [
                            'Align' => '=Align.Center',
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'BorderStyle' => '=BorderStyle.None',
                            'Color' => '=' . $text,
                            'Font' => "=Font.'Segoe UI'",
                            'FontWeight' => '=FontWeight.Semibold',
                            'Height' => '=28',
                            'Size' => '=11',
                            'Text' => '="EN"',
                            'VerticalAlign' => '=VerticalAlign.Middle',
                            'Width' => '=44',
                            'X' => '=0',
                            'Y' => '=4',
                        ],
                    ],
                ],
                [
                    'psLangFR' => [
                        'Control' => 'Label@2.5.1',
                        'Properties' => [
                            'Align' => '=Align.Center',
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'BorderStyle' => '=BorderStyle.None',
                            'Color' => '=' . $text,
                            'Font' => "=Font.'Segoe UI'",
                            'FontWeight' => '=FontWeight.Semibold',
                            'Height' => '=28',
                            'Size' => '=11',
                            'Text' => '="FR"',
                            'VerticalAlign' => '=VerticalAlign.Middle',
                            'Width' => '=44',
                            'X' => '=44',
                            'Y' => '=4',
                        ],
                    ],
                ],
                // Transparent hit targets (Label OnSelect is unreliable in components).
                [
                    'psLangENHit' => [
                        'Control' => 'Classic/Button@2.2.0',
                        'Properties' => [
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'Color' => '=RGBA(0, 0, 0, 0)',
                            'Fill' => '=RGBA(0, 0, 0, 0)',
                            'HoverFill' => '=RGBA(0, 0, 0, 0)',
                            'PressedFill' => '=RGBA(0, 0, 0, 0)',
                            'Height' => '=36',
                            'Width' => '=44',
                            'X' => '=0',
                            'Y' => '=0',
                            'Text' => '=',
                            'Tooltip' => '="English"',
                            'OnSelect' => '=' . $setEn,
                        ],
                    ],
                ],
                [
                    'psLangFRHit' => [
                        'Control' => 'Classic/Button@2.2.0',
                        'Properties' => [
                            'BorderColor' => '=RGBA(0, 0, 0, 0)',
                            'Color' => '=RGBA(0, 0, 0, 0)',
                            'Fill' => '=RGBA(0, 0, 0, 0)',
                            'HoverFill' => '=RGBA(0, 0, 0, 0)',
                            'PressedFill' => '=RGBA(0, 0, 0, 0)',
                            'Height' => '=36',
                            'Width' => '=44',
                            'X' => '=44',
                            'Y' => '=0',
                            'Text' => '=',
                            'Tooltip' => '="Français"',
                            'OnSelect' => '=' . $setFr,
                        ],
                    ],
                ],
                [
                    'psLangPersist' => [
                        'Control' => 'Classic/Button@2.2.0',
                        'Properties' => [
                            'Visible' => '=false',
                            'Width' => '=1',
                            'Height' => '=1',
                            'Text' => '=',
                            'OnSelect' => '=' . $this->languagePersistFormula($langVar, $langIsBoolean),
                        ],
                    ],
                ],
                [
                    'psLangRestore' => [
                        'Control' => 'Classic/Button@2.2.0',
                        'Properties' => [
                            'Visible' => '=false',
                            'Width' => '=1',
                            'Height' => '=1',
                            'Text' => '=',
                            'OnSelect' => '=' . $this->languageRestoreFormula($langVar, $langIsBoolean),
                        ],
                    ],
                ],
                [
                    'psLangSliderTimer' => [
                        'Control' => 'Timer@2.1.0',
                        'Properties' => [
                            'AutoStart' => '=true',
                            'Duration' => '=10',
                            'OnTimerEnd' => '=' . $timerEnd,
                            'Repeat' => '=true',
                            'Visible' => '=false',
                            'Width' => '=1',
                            'Height' => '=1',
                            'X' => '=0',
                            'Y' => '=0',
                        ],
                    ],
                ],
            ],
        ], $beforeSibling);

        $report->add(
            self::id(),
            $host->path,
            'Children',
            '(missing language slider)',
            self::SLIDER_NAME . ' injected in top bar beside Settings'
        );
    }

    /**
     * Same pill, written into the JSON tree Studio actually shows.
     * Rectangles stand in for circles because this app has no circle template.
     */
    private function injectLanguageSliderJson(
        ControlNode $host,
        string $langVar,
        bool $langIsBoolean,
        Report $report
    ): void {
        $enX = (string) self::KNOB_EN_X;
        $frX = (string) self::KNOB_FR_X;
        $knob = self::KNOB_X;
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $setEn = $this->sliderClickFormula($langVar, $langIsBoolean, true);
        $setFr = $this->sliderClickFormula($langVar, $langIsBoolean, false);
        $timerEnd = $this->sliderTimerFormula($langVar, $langIsBoolean);
        $gray = 'If(gblDarkMode, RGBA(62, 62, 68, 1), RGBA(196, 194, 192, 1))';
        $text = 'If(' . $isEn . ', RGBA(32, 31, 30, 1), If(gblDarkMode, RGBA(230, 230, 232, 1), RGBA(50, 49, 48, 1)))';
        $textFr = 'If(' . $isEn . ', If(gblDarkMode, RGBA(230, 230, 232, 1), RGBA(50, 49, 48, 1)), RGBA(32, 31, 30, 1))';
        $parent = $host->name;
        // Group containers honor corner radius in this app. Rectangles do not.
        $track = $this->fxControl('psLangTrack', self::SLIDER_NAME, 'http://microsoft.com/appmagic/groupContainer', 'groupContainer', '1.5.0', [
            'Fill' => $gray,
            'BorderColor' => 'RGBA(0, 0, 0, 0)',
            'BorderThickness' => '0',
            'RadiusTopLeft' => '14',
            'RadiusTopRight' => '14',
            'RadiusBottomLeft' => '14',
            'RadiusBottomRight' => '14',
            'X' => '0',
            'Y' => '4',
            'Width' => '88',
            'Height' => '28',
            'ZIndex' => '1',
        ], [], 'manualLayoutContainer');
        $knobShape = $this->fxControl('psLangKnob', self::SLIDER_NAME, 'http://microsoft.com/appmagic/groupContainer', 'groupContainer', '1.5.0', [
            'Fill' => 'RGBA(255, 255, 255, 1)',
            'BorderColor' => 'RGBA(0, 0, 0, 0)',
            'BorderThickness' => '0',
            'RadiusTopLeft' => '12',
            'RadiusTopRight' => '12',
            'RadiusBottomLeft' => '12',
            'RadiusBottomRight' => '12',
            'X' => 'If(' . $isEn . ', ' . $enX . ', ' . $frX . ')',
            'Y' => '6',
            'Width' => '40',
            'Height' => '24',
            'ZIndex' => '2',
        ], [], 'manualLayoutContainer');
        $enLabel = $this->fxControl('psLangEN', self::SLIDER_NAME, 'http://microsoft.com/appmagic/label', 'label', '2.5.1', [
            'Align' => 'Align.Center',
            'VerticalAlign' => 'VerticalAlign.Middle',
            'Color' => $text,
            'Font' => "Font.'Segoe UI'",
            'FontWeight' => 'FontWeight.Semibold',
            'Size' => '11',
            'Text' => '"EN"',
            'Fill' => 'RGBA(0, 0, 0, 0)',
            'BorderColor' => 'RGBA(0, 0, 0, 0)',
            'BorderThickness' => '0',
            'X' => '0',
            'Y' => '4',
            'Width' => '44',
            'Height' => '28',
            'ZIndex' => '3',
            'OnSelect' => $setEn,
        ]);
        $frLabel = $this->fxControl('psLangFR', self::SLIDER_NAME, 'http://microsoft.com/appmagic/label', 'label', '2.5.1', [
            'Align' => 'Align.Center',
            'VerticalAlign' => 'VerticalAlign.Middle',
            'Color' => $textFr,
            'Font' => "Font.'Segoe UI'",
            'FontWeight' => 'FontWeight.Semibold',
            'Size' => '11',
            'Text' => '"FR"',
            'Fill' => 'RGBA(0, 0, 0, 0)',
            'BorderColor' => 'RGBA(0, 0, 0, 0)',
            'BorderThickness' => '0',
            'X' => '44',
            'Y' => '4',
            'Width' => '44',
            'Height' => '28',
            'ZIndex' => '3',
            'OnSelect' => $setFr,
        ]);
        $enHit = $this->fxControl(
            'psLangENHit',
            self::SLIDER_NAME,
            'http://microsoft.com/appmagic/button',
            'button',
            '2.2.0',
            $this->invisibleHitRules($setEn, '"English"', '0')
        );
        $frHit = $this->fxControl(
            'psLangFRHit',
            self::SLIDER_NAME,
            'http://microsoft.com/appmagic/button',
            'button',
            '2.2.0',
            $this->invisibleHitRules($setFr, '"Français"', '44')
        );
        $persist = $this->fxControl('psLangPersist', self::SLIDER_NAME, 'http://microsoft.com/appmagic/button', 'button', '2.2.0', [
            'Visible' => 'false',
            'Width' => '1',
            'Height' => '1',
            'Text' => '""',
            'OnSelect' => $this->languagePersistFormula($langVar, $langIsBoolean),
        ]);
        $restore = $this->fxControl('psLangRestore', self::SLIDER_NAME, 'http://microsoft.com/appmagic/button', 'button', '2.2.0', [
            'Visible' => 'false',
            'Width' => '1',
            'Height' => '1',
            'Text' => '""',
            'OnSelect' => $this->languageRestoreFormula($langVar, $langIsBoolean),
        ]);
        $timer = $this->fxControl('psLangSliderTimer', self::SLIDER_NAME, 'http://microsoft.com/appmagic/timer', 'timer', '2.1.0', [
            'AutoStart' => 'true',
            'Duration' => '10',
            'Repeat' => 'true',
            'Visible' => 'false',
            'OnTimerEnd' => $timerEnd,
            'X' => '0',
            'Y' => '0',
            'Width' => '1',
            'Height' => '1',
        ]);
        // Children of the slider must parent to the slider, not the host.
        $track->Parent = self::SLIDER_NAME;

        $slider = $this->fxControl(
            self::SLIDER_NAME,
            $parent,
            'http://microsoft.com/appmagic/groupContainer',
            'groupContainer',
            '1.5.0',
            [
                'Fill' => 'RGBA(0, 0, 0, 0)',
                'BorderColor' => 'RGBA(0, 0, 0, 0)',
                'BorderThickness' => '0',
                'DropShadow' => 'DropShadow.None',
                'Width' => '88',
                'Height' => '36',
                'Visible' => 'true',
                'AccessibleLabel' => '"Language"',
            ],
            [$track, $knobShape, $enLabel, $frLabel, $enHit, $frHit, $persist, $restore, $timer],
            'manualLayoutContainer',
            [
                'FillPortions' => '0',
                'AlignInContainer' => 'AlignInContainer.Center',
                'LayoutMinWidth' => '88',
                'LayoutMinHeight' => '36',
                'LayoutMaxWidth' => '88',
                'LayoutMaxHeight' => '36',
            ]
        );

        $host->addJsonChild($slider, 'SettingsCont');
        $report->add(
            self::id(),
            $host->path,
            'Children',
            '(missing language slider)',
            self::SLIDER_NAME . ' injected in JSON top bar beside Settings'
        );
    }

    /**
     * @param array<string, string> $rules
     * @param list<object> $children
     * @param array<string, string> $dynamic
     */
    private function fxControl(
        string $name,
        string $parent,
        string $id,
        string $template,
        string $version,
        array $rules,
        array $children = [],
        string $variant = '',
        array $dynamic = []
    ): object {
        $ruleObjs = [];
        foreach ($rules as $property => $script) {
            $category = 'Design';
            if (in_array($property, ['OnSelect', 'OnTimerEnd', 'OnChange', 'OnVisible'], true)) {
                $category = 'Behavior';
            } elseif (in_array($property, ['Text', 'Tooltip', 'AccessibleLabel'], true)) {
                $category = 'Data';
            }
            $ruleObjs[] = (object) [
                'Property' => $property,
                'Category' => $category,
                'InvariantScript' => $script,
                'RuleProviderType' => 'User',
            ];
        }
        $styles = [
            'groupContainer' => 'defaultGroupContainerStyle',
            'label' => 'defaultLabelStyle',
            'button' => 'defaultButtonStyle',
            'rectangle' => 'defaultRectangleStyle',
            'timer' => 'defaultTimerStyle',
            'htmlViewer' => 'defaultHtmlViewerStyle',
        ];
        $node = (object) [
            'Type' => 'ControlInfo',
            'Name' => $name,
            'HasDynamicProperties' => $dynamic !== [],
            'Template' => (object) [
                'Id' => $id,
                'Version' => $version,
                'LastModifiedTimestamp' => '0',
                'Name' => $template,
                'FirstParty' => true,
                'IsPremiumPcfControl' => false,
                'IsCustomGroupControlTemplate' => false,
                'CustomGroupControlTemplateName' => '',
                'IsComponentDefinition' => false,
                'OverridableProperties' => (object) [],
            ],
            'Index' => 0,
            'VariantName' => $variant,
            'Parent' => $parent,
            'IsDataControl' => false,
            'AllowAccessToGlobals' => true,
            'OptimizeForDevices' => 'Off',
            'IsGroupControl' => false,
            'IsAutoGenerated' => false,
            'StyleName' => $styles[$template] ?? '',
            'Rules' => $ruleObjs,
            'ControlPropertyState' => array_map(
                static fn (string $property): object => (object) [
                    'InvariantPropertyName' => $property,
                    'AutoRuleBindingEnabled' => false,
                    'AutoRuleBindingString' => '',
                    'NameMapSourceSchema' => '?',
                    'IsLockable' => false,
                    'AFDDataSourceName' => '',
                ],
                array_keys($rules)
            ),
            'Children' => $children,
        ];
        if ($dynamic !== []) {
            $dyn = [];
            foreach ($dynamic as $property => $script) {
                $dyn[] = (object) [
                    'PropertyName' => $property,
                    'Rule' => (object) [
                        'Property' => $property,
                        'Category' => 'Design',
                        'InvariantScript' => $script,
                        'RuleProviderType' => 'Unknown',
                    ],
                    'ControlPropertyState' => $property,
                ];
            }
            $node->DynamicProperties = $dyn;
        }

        return $node;
    }

    private function sliderClickFormula(string $langVar, bool $langIsBoolean, bool $english): string
    {
        $flag = $english ? 'true' : 'false';
        $code = $english ? '"en"' : '"fr"';
        $x = $english ? (string) self::KNOB_EN_X : (string) self::KNOB_FR_X;
        $setLang = $langIsBoolean
            ? 'Set(' . $langVar . ', ' . $flag . ')'
            : 'Set(' . $langVar . ', ' . $code . ')';

        return $setLang . '; Set(' . self::KNOB_X . ', ' . $x . ')';
    }

    private function sliderTimerFormula(string $langVar, bool $langIsBoolean): string
    {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $code = $langIsBoolean
            ? 'If(' . $langVar . ', "en", "fr")'
            : 'If(Or(Lower(' . $langVar . ') = "fr", Lower(' . $langVar . ') = "french"), "fr", "en")';
        $target = 'If(' . $isEn . ', ' . self::KNOB_EN_X . ', ' . self::KNOB_FR_X . ')';

        return 'If(!varPsLangRestored, Set(varPsLangRestored, true); Select(psLangRestore)); '
            . 'If(varPsLangLast <> ' . $code . ', Set(varPsLangLast, ' . $code . '); Select(psLangPersist)); '
            . 'If(Abs(' . self::KNOB_X . ' - ' . $target . ') > 1, Set(' . self::KNOB_X . ', ' . self::KNOB_X . ' + (' . $target . ' - ' . self::KNOB_X . ') * 0.5))';
    }

    /** @return array<string, string> */
    private function invisibleHitRules(string $onSelect, string $tooltip, string $x): array
    {
        $clear = 'RGBA(0, 0, 0, 0)';

        return [
            'Text' => '""',
            'Tooltip' => $tooltip,
            'Fill' => $clear,
            'HoverFill' => $clear,
            'PressedFill' => $clear,
            'DisabledFill' => $clear,
            'Color' => $clear,
            'HoverColor' => $clear,
            'PressedColor' => $clear,
            'DisabledColor' => $clear,
            'BorderColor' => $clear,
            'HoverBorderColor' => $clear,
            'PressedBorderColor' => $clear,
            'DisabledBorderColor' => $clear,
            'FocusedBorderColor' => $clear,
            'BorderThickness' => '0',
            'DisplayMode' => 'DisplayMode.Edit',
            'X' => $x,
            'Y' => '0',
            'Width' => '44',
            'Height' => '36',
            'ZIndex' => '10',
            'OnSelect' => $onSelect,
        ];
    }

    private function languagePersistFormula(string $langVar, bool $langIsBoolean): string
    {
        $code = $langIsBoolean
            ? 'If(' . $langVar . ', "en", "fr")'
            : 'If(Or(Lower(' . $langVar . ') = "fr", Lower(' . $langVar . ') = "french"), "fr", "en")';

        return 'If(!IsBlank(varCurrentUser), '
            . 'Set(varCurrentUser, Patch(varCurrentUser, { preferences: Patch(varCurrentUser.preferences, { language: ' . $code . ' }) })); '
            . 'With({ locRow: LookUp(varTempUser, fnEmailLocal(Text(user)) = fnEmailLocal(User().Email)) }, If(!IsBlank(locRow), Patch(varTempUser, locRow, { preferences: varCurrentUser.preferences }))))';
    }

    private function languageRestoreFormula(string $langVar, bool $langIsBoolean): string
    {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';

        return 'Set(' . self::KNOB_X . ', If(' . $isEn . ', ' . self::KNOB_EN_X . ', ' . self::KNOB_FR_X . '))';
    }

    /**
     * The pill is painted on the dark header. The track follows the theme, and
     * the caption on the white knob stays dark while the other caption stays light.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairSliderTheme(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $track = 'If(gblDarkMode, RGBA(62, 62, 68, 1), RGBA(196, 194, 192, 1))';
        $ink = 'RGBA(32, 31, 30, 1)';
        $onTrack = 'If(gblDarkMode, RGBA(230, 230, 232, 1), RGBA(50, 49, 48, 1))';
        $en = 'If(' . $isEn . ', ' . $ink . ', ' . $onTrack . ')';
        $fr = 'If(' . $isEn . ', ' . $onTrack . ', ' . $ink . ')';
        $fills = [
            'psLangTrack' => $track,
            'psLangTrackMid' => $track,
            'psLangLeftCap' => $track,
            'psLangRightCap' => $track,
            'psLangKnob' => 'RGBA(255, 255, 255, 1)',
        ];
        $colors = [
            'psLangEN' => $en,
            'psLangFR' => $fr,
        ];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (isset($fills[$control->name])) {
                    $this->setDesign($control, 'Fill', $fills[$control->name], $report);
                }
                if (isset($colors[$control->name])) {
                    $this->setDesign($control, 'Color', $colors[$control->name], $report);
                }
            }
        }
    }

    /**
     * HtmlViewer ignores its Color for the document body, so the notice text
     * is painted inside the HTML and follows the theme from there.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairNoticeTheme(array $documents, Report $report): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name !== 'psIvcoHtml') {
                    continue;
                }
                $before = (string) ($control->getProperty('HtmlText') ?? '');
                $body = ltrim(trim($before), '=');
                if ($body === '' || str_contains($body, '_psPaper')) {
                    continue;
                }
                $wrapped = 'With({ _psBody: ' . $body
                    . ', _psInk: If(gblDarkMode, "#F5F5F7", "#111827")'
                    . ', _psLink: If(gblDarkMode, "#93C5FD", "#1D4ED8")'
                    . ', _psPaper: If(gblDarkMode, "#1C1C20", "#FFFFFF") }, '
                    . '"<div style=""background-color:" & _psPaper & "; color:" & _psInk & ";"">" & '
                    . 'Substitute(Substitute(Substitute(Substitute(_psBody, "<p>", "<p style=""color:" & _psInk & ";"">"), "<li>", "<li style=""color:" & _psInk & ";"">"), "<strong>", "<strong style=""color:" & _psInk & ";"">"), "<a ", "<a style=""color:" & _psLink & ";"" ")'
                    . ' & "</div>")';
                $to = $control->format === 'yaml' ? '=' . $wrapped : $wrapped;
                $control->setProperty('HtmlText', $to, 'Data', 'User');
                $report->add(self::id(), $control->path, 'HtmlText', $before, $to);
            }
        }
    }

    private function setDesign(ControlNode $control, string $prop, string $formula, Report $report): void
    {
        $before = (string) ($control->getProperty($prop) ?? '');
        if (trim(ltrim(trim($before), '=')) === $formula) {
            return;
        }
        $to = $control->format === 'yaml' ? '=' . $formula : $formula;
        $control->setProperty($prop, $to, 'Design', 'User');
        $report->add(self::id(), $control->path, $prop, $before !== '' ? $before : '(unset)', $to);
    }

    /**
     * The submit warning is display copy. Translate it after the list is built
     * so the click formula that actually saves the request stays unchanged.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairValidationMessages(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $this->addMissingMessageFormula($documents, $report);
        $missingFrom = 'Notify("Missing: " & locMissing, NotificationType.Warning)';
        $missingTo = 'Notify(If(' . $isEn . ', "Missing: " & locMissing, "Manquant : " & fnPsMissing(locMissing)), NotificationType.Warning)';
        $complete = [
            'Complete required Emergency Contact fields (and acknowledgement).' => "Remplissez les champs obligatoires du contact d'urgence (et l'accusé de réception).",
            'Complete all required fields before continuing.' => 'Remplissez tous les champs obligatoires avant de continuer.',
        ];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '' || !str_contains($raw, 'locMissing')) {
                        continue;
                    }
                    $next = str_replace($missingFrom, $missingTo, $raw);
                    foreach ($complete as $en => $fr) {
                        $from = '"' . $en . '"';
                        $to = 'If(' . $isEn . ', "' . $this->escapeFx($en) . '", "' . $this->escapeFx($fr) . '")';
                        if (!str_contains($next, $to)) {
                            $next = str_replace($from, $to, $next);
                        }
                    }
                    if ($next === $raw) {
                        continue;
                    }
                    $control->setProperty($prop, $next, 'Behavior', 'User');
                    $report->add(self::id(), $control->path, $prop, $raw, $next);
                }
            }
        }
    }

    /** @param list<ControlDocument> $documents */
    private function addMissingMessageFormula(array $documents, Report $report): void
    {
        $pairs = [
            ['Proof of security clearance (USS signature and attachment); ', 'Preuve de cote de sécurité (signature USS et pièce jointe); '],
            ['L1 acknowledger email; ', "Courriel de l'accusé de réception N1; "],
            ['Security officer telephone; ', "Téléphone de l'officier de sécurité; "],
            ['Security officer signature; ', "Signature de l'officier de sécurité; "],
            ['Security officer email; ', "Courriel de l'officier de sécurité; "],
            ['Security officer name; ', "Nom de l'officier de sécurité; "],
            ['Classification specific; ', 'Précision de la classification; '],
            ['Destination / BOT; ', 'Destination / TOM; '],
            ['Agency telephone; ', "Téléphone de l'organisme; "],
            ['Initiative type; ', "Type d'initiative; "],
            ['Agency email; ', "Courriel de l'organisme; "],
            ['Postal address; ', 'Adresse postale; '],
            ['Personal email; ', 'Courriel personnel; '],
            ['Departure number; ', 'Numéro de départ; '],
            ['Departure mode; ', 'Mode de départ; '],
            ['Departure date; ', 'Date de départ; '],
            ['Departure exit; ', 'Point de sortie; '],
            ['Arrival number; ', "Numéro d'arrivée; "],
            ['Canadian cell; ', 'Cellulaire canadien; '],
            ['Leading visitor; ', 'Visiteur principal; '],
            ['Acknowledgement; ', 'Accusé de réception; '],
            ['CDLS reference; ', 'Référence du CDLS; '],
            ['Arrival mode; ', "Mode d'arrivée; "],
            ['Arrival entry; ', "Point d'entrée; "],
            ['Arrival date; ', "Date d'arrivée; "],
            ['Daytime phone; ', 'Téléphone de jour; '],
            ['Contact phone; ', 'Téléphone du contact; '],
            ['Visitor fields; ', 'Champs des visiteurs; '],
            ['Local cell; ', 'Cellulaire local; '],
            ['Visit dates; ', 'Dates de la visite; '],
            ['Agency type; ', "Type d'organisme; "],
            ['Visit type; ', 'Type de visite; '],
            ['Unit email; ', "Courriel de l'unité; "],
            ['Site fields; ', 'Champs des sites; '],
            ['Unit name; ', "Nom de l'unité; "],
            ['Initiation; ', 'Initiative; '],
            ['Pertinence; ', 'Pertinence; '],
            ['VIP level; ', 'Niveau VIP; '],
            ['Classification; ', 'Classification; '],
            ['Subject; ', 'Objet; '],
            ['L1; ', 'N1; '],
        ];
        $chunks = array_chunk($pairs, 8);
        $defs = [];
        $names = [];
        foreach ($chunks as $index => $chunk) {
            $name = 'fnPsMissing' . ($index + 1);
            $expr = 'msg';
            foreach ($chunk as [$en, $fr]) {
                $expr = 'Substitute(' . $expr . ', "' . $this->escapeFx($en) . '", "' . $this->escapeFx($fr) . '")';
            }
            $defs[] = $name . '(msg: Text): Text = ' . $expr;
            $names[] = $name;
        }
        $call = 'msg';
        foreach ($names as $name) {
            $call = $name . '(' . $call . ')';
        }
        $defs[] = 'fnPsMissing(msg: Text): Text = ' . $call;
        $block = implode(";\n", $defs) . ';';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isApp()) {
                    continue;
                }
                $raw = (string) ($control->getProperty('Formulas') ?? '');
                if ($raw === '' || str_contains($raw, 'fnPsMissing(')) {
                    continue;
                }
                $body = rtrim(rtrim(ltrim(trim($raw), '=')), ";\n\r\t ");
                $control->setProperty('Formulas', '=' . $body . ";\n" . $block);
                $report->add(self::id(), $control->path, 'Formulas', '(missing message)', 'fnPsMissing');
            }
        }
    }

    /**
     * User-facing Notify text. Stored status values stay English; only the toast changes.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairNotifyToasts(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $pairs = $this->notifyToastPairs();
        uksort($pairs, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '' || !str_contains($raw, 'Notify')) {
                        continue;
                    }
                    $next = $raw;
                    foreach ($pairs as $en => $fr) {
                        $quoted = '"' . str_replace('"', '""', $en) . '"';
                        $wrapped = 'If(' . $isEn . ', ' . $quoted . ', "';
                        if (str_contains($next, $wrapped)) {
                            continue;
                        }
                        $replacement = 'If(' . $isEn . ', ' . $quoted . ', "' . $this->escapeFx($fr) . '")';
                        $next = str_replace($quoted, $replacement, $next);
                    }
                    if (str_contains($next, 'Request is now') && !str_contains($next, 'fnUiFr(locState)')) {
                        $next = str_replace(
                            '& locState &',
                            '& If(' . $isEn . ', locState, fnUiFr(locState)) &',
                            $next
                        );
                    }
                    if ($next === $raw) {
                        continue;
                    }
                    $control->setProperty($prop, $next, 'Behavior', 'User');
                    $report->add(self::id(), $control->path, $prop, $raw, $next);
                }
            }
        }
    }

    /** @return array<string, string> */
    private function notifyToastPairs(): array
    {
        return [
            'You must wait for the acknowledger before you to acknowledge this request. We will notify you when it is your turn to acknowledge this request.' => "Vous devez attendre que le responsable précédent accuse réception. Nous vous aviserons lorsque ce sera votre tour.",
            'Your request must be acknowledged first. Once all other information is complete, please select submit to await acknowledgment.' => "Votre demande doit d'abord être accusée de réception. Une fois les autres renseignements complets, appuyez sur Soumettre pour attendre l'accusé.",
            'Enter the name of the table that is storing the personnel details. Use table VIP_Tracking on the VIP Tracking sheet. If Excel has no table, upload Class Rank Sheet import.xlsx from Downloads.' => "Entrez le nom du tableau qui contient les renseignements du personnel. Utilisez le tableau VIP_Tracking de la feuille VIP Tracking. Si Excel n'a pas de tableau, téléversez Class Rank Sheet import.xlsx depuis Téléchargements.",
            'VCR Submission confirmation email has no body. Admin → VCR Submission Confirmation: paste the template and Submit.' => "Le courriel de confirmation de soumission VAC est vide. Admin, Confirmation de soumission VAC : collez le modèle, puis soumettez.",
            'ECF confirmation email has no body. Admin → ECF Submission Confirmation: paste the template and Submit.' => "Le courriel de confirmation FCU est vide. Admin, Confirmation FCU : collez le modèle, puis soumettez.",
            'Acknowledger email has no body. Admin → Acknowledgment Request: paste the template and Submit.' => "Le courriel au responsable est vide. Admin, Demande d'accusé : collez le modèle, puis soumettez.",
            'IVCO email has no body. Admin → IVCO Message: paste the template and Submit.' => "Le courriel IVCO est vide. Admin, Message IVCO : collez le modèle, puis soumettez.",
            'Power BI refresh started. Wait 30–90 seconds, then click Refresh Data again to reload the ' => "Actualisation Power BI lancée. Attendez 30 à 90 secondes, puis cliquez de nouveau sur Actualiser les données pour recharger ",
            'This request must be acknowledged before the Emergency Contact form is available.' => "Cette demande doit être accusée de réception avant que le formulaire de contact d'urgence soit disponible.",
            'Cannot send the submission receipt because your mailbox address is missing.' => "Impossible d'envoyer l'accusé de soumission : votre adresse de courriel est manquante.",
            'Enter the Excel table name (Table Design → Table Name), click off the box, then Upload.' => "Entrez le nom du tableau Excel (Création de tableau, Nom du tableau), quittez le champ, puis téléversez.",
            'You must wait for the acknowledger before you to acknowledge this request.' => "Vous devez attendre que le responsable précédent accuse réception de cette demande.",
            'Save blocked: in-memory user list is empty. SharePoint was not changed.' => "Enregistrement bloqué : la liste des utilisateurs est vide. SharePoint n'a pas été modifié.",
            'Save blocked: at least one Admin is required. SharePoint was not changed.' => "Enregistrement bloqué : au moins un Admin est requis. SharePoint n'a pas été modifié.",
            'PDF skipped — request did not reload. Open the request and send the PDF again.' => "PDF ignoré. La demande ne s'est pas rechargée. Ouvrez la demande et envoyez le PDF de nouveau.",
            'Denied. The submitter has been notified. Request is now ' => "Refusée. Le demandeur a été avisé. La demande est maintenant ",
            'Cannot send the acknowledger email because the address is missing.' => "Impossible d'envoyer le courriel au responsable : l'adresse est manquante.",
            'Patch failed. Column names do not match the list. Fix names, then retry.' => "Échec de la mise à jour. Les noms de colonnes ne correspondent pas à la liste.",
            'No request is loaded. Open the request again, then Export.' => "Aucune demande n'est chargée. Ouvrez la demande de nouveau, puis exportez.",
            'Enter the IVCO number, click off the box, then Send Email.' => "Entrez le numéro IVCO, quittez le champ, puis envoyez le courriel.",
            'PDF is not ready yet. Try Export again in a few seconds.' => "Le PDF n'est pas encore prêt. Réessayez l'exportation dans quelques secondes.",
            'Cannot notify originator — email is missing.' => "Impossible d'aviser le demandeur : le courriel est manquant.",
            'Cannot save PDF — request row was not found.' => "Impossible d'enregistrer le PDF. La demande est introuvable.",
            'You do not have the permissions to edit this user\'s permissions' => "Vous n'avez pas la permission de modifier les droits de cet utilisateur.",
            'Save failed - SharePoint did not return the request.' => "Échec de l'enregistrement. SharePoint n'a pas renvoyé la demande.",
            'Save failed - SharePoint did not update this acknowledger.' => "Échec de l'enregistrement. SharePoint n'a pas mis à jour ce responsable.",
            'Missing request ID - cannot update the tracking list.' => "N° de demande manquant. Impossible de mettre à jour la liste de suivi.",
            'Cleared tracking, approvals, and member locations.' => "Suivi, approbations et emplacements des membres effacés.",
            'Default acknowledger reset to the saved value.' => "Responsable par défaut rétabli à la valeur enregistrée.",
            'Please choose an option above or below the line.' => "Veuillez choisir une option au-dessus ou au-dessous de la ligne.",
            'VCR Submission Confirmation email template saved.' => "Modèle de confirmation de soumission VAC enregistré.",
            'VCR Submission Confirmation email template reset.' => "Modèle de confirmation de soumission VAC réinitialisé.",
            'ECF Submission Confirmation email template saved.' => "Modèle de confirmation FCU enregistré.",
            'ECF Submission Confirmation email template reset.' => "Modèle de confirmation FCU réinitialisé.",
            'Acknowledgment Request email template saved.' => "Modèle de demande d'accusé enregistré.",
            'Acknowledgedment Request email template reset.' => "Modèle de demande d'accusé réinitialisé.",
            'Acknowledged Request email template saved.' => "Modèle de demande accusée enregistré.",
            'Acknowledged Request email template reset.' => "Modèle de demande accusée réinitialisé.",
            'This feature is upcoming. Stay tuned...' => "Cette fonction sera offerte bientôt.",
            'Refreshed all data sources successfully' => "Sources de données actualisées.",
            'Please fill out all of the required fields' => "Veuillez remplir tous les champs obligatoires.",
            'Decision submitted. Request is now ' => "Décision soumise. La demande est maintenant ",
            'Change requested. Request is now ' => "Modification demandée. La demande est maintenant ",
            'Reviewed. Request is now ' => "Révisé. La demande est maintenant ",
            'Denied Request email template saved.' => "Modèle de demande refusée enregistré.",
            'Denied Request email template reset.' => "Modèle de demande refusée réinitialisé.",
            'IVCO Message email template saved.' => "Modèle du message IVCO enregistré.",
            'IVCO Message email template reset.' => "Modèle du message IVCO réinitialisé.",
            'An error occurred while importing the data' => "Une erreur s'est produite pendant l'importation.",
            'The attached file must be in Excel (.xlsx) format' => "Le fichier joint doit être un fichier Excel (.xlsx).",
            'Cannot send: recipient is not a valid email.' => "Envoi impossible : le destinataire n'est pas un courriel valide.",
            'Click out of the email box first, then Submit.' => "Quittez d'abord le champ de courriel, puis soumettez.",
            'Could not find App Settings row Title = 1.' => "Ligne des paramètres de l'application introuvable (Title = 1).",
            'Missing request ID - cannot submit.' => "N° de demande manquant. Impossible de soumettre.",
            'At least one accommodation is required.' => "Au moins un hébergement est requis.",
            'Decision submitted successfully' => "Décision soumise.",
            'At least one visitor is required.' => "Au moins un visiteur est requis.",
            'At least one visit is required' => "Au moins une visite est requise.",
            'Default acknowledger saved.' => "Responsable par défaut enregistré.",
            'Successfully imported data' => "Données importées.",
            'Data successfully refreshed' => "Données actualisées.",
            'Sent request successfully.' => "Demande envoyée.",
            'Item successfully deleted.' => "Élément supprimé.",
            'Bug has been reported' => "Le bogue a été signalé.",
            'You must describe the bug first' => "Décrivez d'abord le bogue.",
            'Reset debug log successfully' => "Journal de débogage réinitialisé.",
            'Debug log copied to clipboard' => "Journal de débogage copié.",
            'Pick a user first.' => "Choisissez d'abord un utilisateur.",
            'Must first select a file' => "Sélectionnez d'abord un fichier.",
            'An acknowledger must be selected to notify first' => "Sélectionnez d'abord un responsable à aviser.",
            'Coming soon...' => "Bientôt disponible.",
            'Submitting…' => "Soumission…",
            'Could not find request ' => "Demande introuvable : ",
            ' on the VCR tracking list.' => " dans la liste de suivi VAC.",
            'Next acknowledger\'s email is invalid: ' => "Le courriel du prochain responsable n'est pas valide : ",
            'Power BI refresh failed. ' => "L'actualisation de Power BI a échoué. ",
            'Delete all failed. ' => "La suppression a échoué. ",
            'Sending From: ' => "Envoi depuis : ",
            'SharePoint did not save L1 Data: ' => "SharePoint n'a pas enregistré les données N1 : ",
            'Acknowledger saved for ' => "Responsable enregistré pour ",
            'Enter an email for ' => "Entrez un courriel pour ",
            'Please fill: ' => "Veuillez remplir : ",
            'IVCO Number (' => "Numéro IVCO (",
        ];
    }

    /**
     * frmPKI--- is not a real form. Submitting it stops the rest of the click.
     *
     * @param list<ControlDocument> $documents
     */
    private function commentPkiSubmit(array $documents, Report $report): void
    {
        $pattern = '/If\s*\(\s*!fnIsEmerStep\s*&&\s*!IsBlank\s*\(\s*PKIImage\.FileName\s*\)\s*,\s*EditForm\s*\(\s*\'frmPKI---\'\s*\)\s*;\s*SubmitForm\s*\(\s*\'frmPKI---\'\s*\)\s*\)\s*;/';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $raw = (string) ($control->getProperty($prop) ?? '');
                    if ($raw === '' || !str_contains($raw, 'frmPKI---')) {
                        continue;
                    }
                    $next = preg_replace_callback(
                        $pattern,
                        static function (array $m) use ($raw): string {
                            $pos = strpos($raw, $m[0]);
                            $before = $pos === false ? '' : substr($raw, 0, $pos);
                            if (substr_count($before, '/*') > substr_count($before, '*/')) {
                                return $m[0];
                            }

                            return '/* ' . rtrim($m[0]) . ' */';
                        },
                        $raw
                    );
                    if (!is_string($next) || $next === $raw) {
                        continue;
                    }
                    $control->setProperty($prop, $next, 'Behavior', 'User');
                    $report->add(self::id(), $control->path, $prop, $raw, $next);
                }
            }
        }
    }

    /**
     * Classic hover ink defaults to black. On a dark surface that turns themed
     * text black while the pointer is over it.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairHoverInk(array $documents, Report $report): void
    {
        $hoverFill = 'If(gblDarkMode, RGBA(50, 50, 58, 1), RGBA(226, 232, 240, 1))';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (str_contains(strtolower($control->type), 'modern')) {
                    continue;
                }
                $color = trim(ltrim(trim((string) ($control->getProperty('Color') ?? '')), '='));
                if ($color === '' || !str_contains($color, 'gblTheme.')) {
                    continue;
                }
                if (preg_match('/RGBA\s*\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/', $color) === 1) {
                    continue;
                }
                $changedInk = false;
                foreach (['HoverColor', 'PressedColor'] as $prop) {
                    $raw = $control->getProperty($prop);
                    $body = trim(ltrim(trim((string) $raw), '='));
                    if ($body === 'Self.Color' || str_contains($body, 'gblTheme.')) {
                        continue;
                    }
                    if ($body !== '' && preg_match('/^RGBA\s*\(\s*0\s*,\s*0\s*,\s*0\s*,\s*1\s*\)$/i', $body) !== 1 && !str_contains($body, 'Color.Black')) {
                        continue;
                    }
                    if ($body === '' && !preg_match('/dropdown|combobox|text|label|button|checkbox|radio|listbox|attachment/i', $control->type)) {
                        continue;
                    }
                    $this->setDesign($control, $prop, 'Self.Color', $report);
                    $changedInk = true;
                }
                $fill = trim(ltrim(trim((string) ($control->getProperty('HoverFill') ?? '')), '='));
                if ($changedInk && $this->isLightLiteralFill($fill)) {
                    $this->setDesign($control, 'HoverFill', $hoverFill, $report);
                }
            }
        }
    }

    private function isLightLiteralFill(string $fill): bool
    {
        if (preg_match('/^RGBA\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*1(?:\.0+)?\s*\)$/i', $fill, $m) !== 1) {
            return false;
        }
        $r = (int) $m[1];
        $g = (int) $m[2];
        $b = (int) $m[3];

        return ($r + $g + $b) / (3 * 255) > 0.55;
    }

    /**
     * Attachment controls with no caption use the English template default.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairAttachmentText(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        $isEn = $langIsBoolean
            ? $langVar
            : 'Or(' . $langVar . ' = "en", ' . $langVar . ' = "EN", ' . $langVar . ' = "en-ca")';
        $phrases = [
            'NoAttachmentsText' => ['There is nothing attached.', 'Aucune pièce jointe.'],
            'AddAttachmentText' => ['Attach file', 'Joindre un fichier'],
            'MaxAttachmentsText' => ['Max. number files reached.', 'Nombre max. de fichiers atteint.'],
            'AttachedFileListLabel' => ['Attached files', 'Fichiers joints'],
            'RemoveAttachmentTooltip' => ['Remove', 'Retirer'],
        ];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!str_contains(strtolower($control->type), 'attachment')) {
                    continue;
                }
                foreach ($phrases as $prop => [$en, $fr]) {
                    $raw = trim(ltrim(trim((string) ($control->getProperty($prop) ?? '')), '='));
                    if ($raw !== '') {
                        continue;
                    }
                    $expr = 'If(' . $isEn . ', "' . $this->escapeFx($en) . '", "' . $this->escapeFx($fr) . '")';
                    $to = $control->format === 'yaml' ? '=' . $expr : $expr;
                    $control->setProperty($prop, $to, 'Data', 'User');
                    $report->add(self::id(), $control->path, $prop, '(unset)', $to);
                }
            }
        }
    }

    /**
     * Theme has to change before any preference write. A failure while reading
     * the user record was cancelling the palette swap.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairThemeRadios(array $documents, Report $report): void
    {
        $onChange = 'Set(gblDarkMode, Self.Selected.Value = "Dark"); '
            . 'Set(gblTheme, If(gblDarkMode, gblThemeDark, gblThemeLight)); '
            . 'If(!IsBlank(varCurrentUser), Set(varCurrentUser, Patch(varCurrentUser, { preferences: Patch(varCurrentUser.preferences, { theme: If(gblDarkMode, "dark", "light") }) }))); '
            . 'With({ locRow: LookUp(varTempUser, fnEmailLocal(Text(user)) = fnEmailLocal(User().Email)) }, If(!IsBlank(locRow), Patch(varTempUser, locRow, { preferences: varCurrentUser.preferences }))); '
            . 'With({ locSp: LookUp(\'CDLS (L) App Users\', fnEmailLocal(Title) = fnEmailLocal(User().Email)) }, If(!IsBlank(locSp), Patch(\'CDLS (L) App Users\', locSp, { Theme: If(gblDarkMode, "dark", "light") })))';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name !== 'ThemeRadio') {
                    continue;
                }
                $before = (string) ($control->getProperty('OnChange') ?? '');
                if (str_contains($before, 'Set(gblDarkMode, Self.Selected.Value = "Dark")')) {
                    continue;
                }
                $to = $control->format === 'yaml' ? '=' . $onChange : $onChange;
                $control->setProperty('OnChange', $to, 'Behavior', 'User');
                $report->add(self::id(), $control->path, 'OnChange', $before !== '' ? $before : '(unset)', $to);
            }
        }
    }

    /**
     * The startup timer must not read or write PreferredLanguage. That column
     * is not on the users list, and a missing column fails the formula.
     *
     * @param list<ControlDocument> $documents
     */
    private function repairLanguagePersistence(array $documents, string $langVar, bool $langIsBoolean, Report $report): void
    {
        $persist = $this->languagePersistFormula($langVar, $langIsBoolean);
        $restore = $this->languageRestoreFormula($langVar, $langIsBoolean);
        $applyTheme = 'If(!IsBlank(varCurrentUser), Set(gblDarkMode, Lower(Coalesce(varCurrentUser.preferences.theme, "light")) = "dark"); Set(gblTheme, If(gblDarkMode, gblThemeDark, gblThemeLight)))';
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name === 'psLangPersist' && str_contains((string) ($control->getProperty('OnSelect') ?? ''), 'PreferredLanguage')) {
                    $this->setBehavior($control, 'OnSelect', $persist, $report);
                }
                if ($control->name === 'psLangRestore') {
                    $onSelect = (string) ($control->getProperty('OnSelect') ?? '');
                    if (str_contains($onSelect, 'PreferredLanguage') || str_contains($onSelect, '!Coalesce(' . $langVar)) {
                        $this->setBehavior($control, 'OnSelect', $restore, $report);
                    }
                }
                $load = (string) ($control->getProperty('loadAppUsers') ?? '');
                if ($load !== '' && str_contains($load, 'varTempUser') && !str_contains($load, 'gblDarkMode')) {
                    $body = rtrim(ltrim(trim($load), '='));
                    $this->setBehavior($control, 'loadAppUsers', rtrim($body, ";\n\r\t ") . ";\n" . $applyTheme, $report);
                }
            }
        }
    }

    private function setBehavior(ControlNode $control, string $prop, string $formula, Report $report): void
    {
        $before = (string) ($control->getProperty($prop) ?? '');
        if (trim(ltrim(trim($before), '=')) === $formula) {
            return;
        }
        $to = $control->format === 'yaml' ? '=' . $formula : $formula;
        $control->setProperty($prop, $to, 'Behavior', 'User');
        $report->add(self::id(), $control->path, $prop, $before !== '' ? $before : '(unset)', $to);
    }

    private function controlTreeMentionsTheme(ControlNode $control): bool
    {
        foreach ($control->propertyNames() as $prop) {
            if (str_contains((string) ($control->getProperty($prop) ?? ''), 'gblTheme')) {
                return true;
            }
        }
        foreach ($control->children as $child) {
            if ($this->controlTreeMentionsTheme($child)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ControlDocument> $documents */
    private function documentsMention(array $documents, string $needle): bool
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    if (str_contains((string) ($control->getProperty($prop) ?? ''), $needle)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     */
    private function buildI18nBlock(
        string $langVar,
        string $stringsVar,
        array $packs,
        string $existingLangVar,
        bool $langIsBoolean,
        bool $hasLangControl,
        bool $initKnob = false
    ): string {
        ksort($packs);
        $fields = [];
        foreach ($packs as $token => $pair) {
            $fr = $pair['fr'];
            if ($fr === $pair['en']) {
                $fr = $this->frenchFallback($pair['en']);
            }
            $fields[] = $token . ': { En: "' . $this->escapeFx($pair['en']) . '", Fr: "' . $this->escapeFx($fr) . '" }';
        }
        if ($fields === []) {
            $fields[] = 'Placeholder: { En: "", Fr: "" }';
        }

        $init = '';
        if ($langIsBoolean) {
            $init = 'Set(' . $existingLangVar . ', Coalesce(' . $existingLangVar . ', true));';
            if ($initKnob) {
                $init .= ' Set(' . self::KNOB_X . ', If(Coalesce(' . $existingLangVar . ', true), ' . self::KNOB_EN_X . ', ' . self::KNOB_FR_X . '));';
                $init .= ' Set(varPsLangRestored, false); Set(varPsLangLast, If(' . $existingLangVar . ', "en", "fr"));';
            }
        } elseif ($hasLangControl && $existingLangVar !== $langVar) {
            $init = 'Set(' . $langVar . ', If(Or(' . $existingLangVar . ' = false, ' . $existingLangVar . ' = "FR", ' . $existingLangVar . ' = "fr", ' . $existingLangVar . ' = "fr-ca"), "fr", "en"));';
        } else {
            $init = 'If(IsBlank(' . $langVar . '), Set(' . $langVar . ', "en"));';
        }

        return self::BLOCK_START
            . " /* En/Fr side-by-side — translators edit Fr */\n"
            . $init . "\n"
            . 'Set(' . $stringsVar . ', {' . "\n    " . implode(",\n    ", $fields) . "\n});\n"
            . self::BLOCK_END;
    }

    private function escapeFx(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '""'], $s);
    }

    /**
     * Keep an already-translated gblStrings pack when translate is re-run
     * (control Text is already If(varLang, gblStrings.X.En, …) so Pass 1 would drop it).
     *
     * @return array<string, array{en:string,fr:string}>
     */
    private function harvestExistingStringPack(string $onStart): array
    {
        $packs = [];
        if (!str_contains($onStart, self::BLOCK_START) || !str_contains($onStart, '{ En:')) {
            return $packs;
        }
        if (preg_match_all(
            '/(\b[A-Za-z_][A-Za-z0-9_]*)\s*:\s*\{\s*En:\s*"((?:[^"]|"")*)"\s*,\s*Fr:\s*"((?:[^"]|"")*)"\s*\}/s',
            $onStart,
            $m,
            PREG_SET_ORDER
        ) !== false) {
            foreach ($m as $row) {
                $packs[$row[1]] = [
                    'en' => str_replace('""', '"', $row[2]),
                    'fr' => str_replace('""', '"', $row[3]),
                ];
            }
        }

        return $packs;
    }

    /** Formula scraps and Studio auto-names are not UI copy. */
    private function isNonTranslatableFragment(string $en): bool
    {
        if (preg_match('/\b(LookUp|Filter|Switch|Set|Navigate|Patch|With)\s*\(/', $en) === 1) {
            return true;
        }
        if (str_contains($en, '://') || str_contains($en, '/*') || str_contains($en, '*/')) {
            return true;
        }
        if (preg_match('/^(image|icon|button canvas|fn)[\w\d ]*$/i', $en) === 1) {
            return true;
        }
        // Record literals and CSS, not ordinary punctuation (e.g. "Name; email").
        // Prose that mentions a {field} token (email-template help) is still copy.
        if (str_contains($en, '{') || str_contains($en, '}')) {
            $words = preg_match_all('/\p{L}{3,}/u', $en);
            if ($words < 6) {
                return true;
            }
        }
        if (str_contains($en, 'text-decoration') || str_contains($en, 'cursor:')) {
            return true;
        }

        return false;
    }

    /** Common hallway / settings chrome so EN/FR is visible before translators fill Fr. */
    private function frenchFallback(string $en): string
    {
        $trimmed = trim($en);
        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, 'var')) {
            return $en;
        }
        if ($this->isNonTranslatableFragment($trimmed)) {
            return $en;
        }
        $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $trimmed));
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ([
                POWER_SWEEPER_ROOT . '/config/french_ui_strings.php',
                POWER_SWEEPER_ROOT . '/config/french_ui_strings_vcr.php',
            ] as $file) {
                $loaded = is_file($file) ? require $file : [];
                if (is_array($loaded)) {
                    $map = array_merge($map, $loaded);
                }
            }
        }

        $fr = $map[$key] ?? $en;

        return $this->applyFrenchPunctuation($fr, $en);
    }

    /**
     * Canadian French punctuation: a non-breaking space before : ; ! ?
     * so the mark stays on the same line as the word. Concatenated prefixes
     * ("Debug mode: " & value) keep one normal space after the mark.
     */
    private function applyFrenchPunctuation(string $fr, string $en): string
    {
        $lead = '';
        $trail = '';
        if (preg_match('/^(\s+)/u', $en, $leadM) === 1) {
            $lead = $leadM[1];
        }
        if (preg_match('/(\s+)$/u', $en, $trailM) === 1) {
            $trail = $trailM[1];
        }
        $core = trim($fr);
        if ($core === '' || $this->isNonTranslatableFragment($core)) {
            return $fr;
        }
        $nbsp = "\u{00A0}";
        $core = preg_replace('/(?<=\p{L})[ \t]*([:;!?])(?!\d)/u', $nbsp . '$1', $core) ?? $core;
        if ($trail !== '' && preg_match('/[:;!?]$/u', $core) === 1) {
            $core .= ' ';
            $trail = '';
        }

        return $lead . $core . $trail;
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
            // Pack first so later OnStart formulas can read gblStrings.
            $body = $block . "\n" . $body;
        }

        $body = trim($body);

        return ($yamlEquals || $hadEquals) ? '=' . $body : $body;
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
            foreach (['settings', 'home', 'intro', 'welcome', 'start', 'landing', 'main', 'menu', 'topbar'] as $i => $needle) {
                if (str_contains($n, $needle)) {
                    $score += 100 - $i;
                }
            }
            foreach ($s->children as $child) {
                $cn = strtolower($child->name . ' ' . $child->type);
                if (str_contains($cn, 'lang') || str_contains($cn, 'setting') || str_contains($cn, 'radio')) {
                    $score += 20;
                }
            }

            return $score;
        };

        usort($screens, static fn(ControlNode $a, ControlNode $b): int => $score($b) <=> $score($a));

        return $screens[0];
    }

    private function looksLikeComponentDefinition(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'canvascomponent')
            || str_contains($t, 'component')
            || ($control->getProperty('DefinitionType') !== null);
    }
}
