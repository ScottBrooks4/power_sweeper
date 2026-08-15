<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNaming;
use PowerSweeper\ControlNode;
use PowerSweeper\HopOptions;
use PowerSweeper\Report;

/**
 * Centralize UI strings behind language packs (dark-mode analogue for text).
 *
 * App.OnStart defines:
 *   gblLang          — active language code ("en", "fr", …)
 *   gblStringsEn/Fr  — named string tokens
 *   gblStrings       — active pack (swapped by the language control)
 *
 * Literal Text / HintText / Tooltip / TrueText / FalseText become =gblStrings.Token.
 * Existing language radios / varLang toggles are detected and wired when present.
 */
final class TranslateHop implements HopInterface
{
    private const LANG = 'gblLang';
    private const STRINGS = 'gblStrings';
    private const STRINGS_EN = 'gblStringsEn';
    private const STRINGS_FR = 'gblStringsFr';
    private const RADIO_NAME = 'rdoPowerSweeperLanguage';
    private const BLOCK_START = '/* ps-i18n:start */';
    private const BLOCK_END = '/* ps-i18n:end */';

    /** @var list<string> */
    private const TEXT_PROPERTIES = [
        'Text',
        'HintText',
        'Tooltip',
        'TrueText',
        'FalseText',
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
        return 'Centralize label/button/text into gblStringsEn/gblStringsFr packs and bind controls to gblStrings.*; detect or inject a language control (ties into existing English/French / varLang settings like dark mode does for theme).';
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
        $force = HopOptions::force($options);

        /** @var array<string, array{en:string,fr:string}> $packs */
        $packs = [];
        /** @var array<string, string> $literalToToken */
        $literalToToken = [];
        /** @var list<array{control:ControlNode,property:string,kind:string,en:string,fr:?string}> $targets */
        $targets = [];

        $existingLangVar = $this->detectExistingLangVar($documents) ?? 'varLang';
        $langControl = $this->findLanguageControl($documents, $existingLangVar);

        // Pass 1 — harvest literals / simple bilingual If(varLang, …) strings.
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp() || $control->isScreen()) {
                    continue;
                }
                if (!$this->isTextualControl($control)) {
                    continue;
                }
                if ($langControl !== null && $control->path === $langControl->path) {
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
                    if ($this->alreadyBoundToStrings($raw, $stringsVar)) {
                        continue;
                    }
                    if ($this->alreadyBoundToTranslations($raw)) {
                        continue;
                    }

                    $bilingual = $this->parseBilingualIf($raw, $existingLangVar);
                    if ($bilingual !== null) {
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

                    if ($this->isDynamicExpression($raw) && !$force) {
                        continue;
                    }
                    if ($this->isDynamicExpression($raw) && $force) {
                        // force still skips rich formulas (LookUp/Concatenate/…) — only literals.
                        continue;
                    }

                    $literal = ControlNaming::unwrap($raw);
                    if ($literal === '' || $this->isIgnorableGlyph($literal)) {
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

        if ($packs === [] && $langControl === null && !$injectToggle) {
            return;
        }

        // Pass 2 — inject / refresh OnStart language packs.
        $apps = $this->findAllApps($documents);
        if ($apps !== []) {
            $block = $this->buildI18nBlock(
                $langVar,
                $stringsVar,
                $stringsEn,
                $stringsFr,
                $packs,
                $existingLangVar,
                $langControl !== null
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

        // Pass 3 — wire or inject language control.
        if ($langControl !== null) {
            $this->wireLanguageControl(
                $langControl,
                $langVar,
                $stringsVar,
                $stringsEn,
                $stringsFr,
                $existingLangVar,
                $report
            );
        } elseif ($injectToggle) {
            $screen = $this->pickIntroScreen($documents);
            if ($screen !== null) {
                $this->injectLanguageRadio(
                    $screen,
                    $langVar,
                    $stringsVar,
                    $stringsEn,
                    $stringsFr,
                    $report
                );
            }
        }

        // Pass 4 — rewrite harvested targets to gblStrings.Token.
        foreach ($targets as $target) {
            /** @var ControlNode $control */
            $control = $target['control'];
            $prop = (string) $target['property'];
            $token = (string) $target['token'];
            $before = (string) ($control->getProperty($prop) ?? '');
            $expr = $stringsVar . '.' . $token;
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
                    if (str_contains($val, $stringsVar) || str_contains($val, $langVar)) {
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

        return false;
    }

    private function alreadyBoundToStrings(string $raw, string $stringsVar): bool
    {
        return (bool) preg_match('/\b' . preg_quote($stringsVar, '/') . '\s*\./', $raw);
    }

    private function alreadyBoundToTranslations(string $raw): bool
    {
        return (bool) preg_match('/\bcomTranslations\s*\.\s*Labels\b|\bTranslationComponent\w*\s*\.\s*Labels\b/i', $raw);
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

    /** @param list<ControlDocument> $documents */
    private function findLanguageControl(array $documents, string $langVar): ?ControlNode
    {
        $best = null;
        $bestScore = 0;
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $type = strtolower($control->type);
                $isChooser = str_contains($type, 'radio')
                    || str_contains($type, 'dropdown')
                    || str_contains($type, 'combobox')
                    || $control->isToggle()
                    || str_contains(strtolower($control->name), 'toggle');
                if (!$isChooser) {
                    continue;
                }
                $blob = strtolower(
                    $control->name . ' '
                    . (string) ($control->getProperty('Text') ?? '')
                    . ' '
                    . (string) ($control->getProperty('AccessibleLabel') ?? '')
                    . ' '
                    . (string) ($control->getProperty('Items') ?? '')
                    . ' '
                    . (string) ($control->getProperty('OnChange') ?? '')
                    . ' '
                    . (string) ($control->getProperty('OnSelect') ?? '')
                    . ' '
                    . (string) ($control->getProperty('OnCheck') ?? '')
                );
                $score = 0;
                if (str_contains($blob, 'language') || str_contains($blob, 'langue')) {
                    $score += 50;
                }
                if (str_contains($blob, 'english') && str_contains($blob, 'french')) {
                    $score += 80;
                }
                if (str_contains($blob, 'english') || str_contains($blob, 'french')) {
                    $score += 30;
                }
                if (str_contains($blob, strtolower($langVar))) {
                    $score += 40;
                }
                if (preg_match('/\b(en|fr|en-ca|fr-ca)\b/', $blob)) {
                    $score += 10;
                }
                // Exclude dark-theme radios.
                if (
                    (str_contains($blob, 'dark') || str_contains($blob, 'light') || str_contains($blob, 'theme'))
                    && !str_contains($blob, 'english')
                    && !str_contains($blob, 'french')
                    && !str_contains($blob, 'language')
                ) {
                    $score -= 100;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $control;
                }
            }
        }

        return $bestScore >= 40 ? $best : null;
    }

    private function wireLanguageControl(
        ControlNode $control,
        string $langVar,
        string $stringsVar,
        string $stringsEn,
        string $stringsFr,
        string $existingLangVar,
        Report $report
    ): void {
        $type = strtolower($control->type);
        $swap = 'Set(' . $stringsVar . ', If(' . $langVar . ' = "fr", ' . $stringsFr . ', ' . $stringsEn . '))';

        if (str_contains($type, 'radio') || str_contains($type, 'dropdown') || str_contains($type, 'combobox')) {
            $onChangeProp = 'OnChange';
            $before = (string) ($control->getProperty($onChangeProp) ?? '');
            if (str_contains($before, $stringsVar)) {
                return;
            }
            $setLang = 'Set(' . $langVar . ', If(Or(Self.Selected.Value = "French", Self.Selected.Value = "Français", Self.Selected.Value = "fr", Self.Selected.Value = "FR"), "fr", "en"))';
            // Keep existing varLang wiring when present.
            $keepVarLang = '';
            if (str_contains($before, $existingLangVar) || $existingLangVar !== $langVar) {
                $keepVarLang = 'Set(' . $existingLangVar . ', ' . $langVar . ' = "en"); ';
                // THCEE uses boolean varLang (true=EN). VCR uses "EN"/"FR" strings — append without replacing.
                if ($before !== '' && !str_contains($before, $stringsVar)) {
                    $body = trim(ltrim(trim($before), '='));
                    if ($body !== '' && !str_ends_with($body, ';')) {
                        $body .= ';';
                    }
                    $body = ($body === '' ? '' : $body . ' ') . $setLang . '; ' . $swap;
                    $to = $control->format === 'yaml' ? '=' . $body : $body;
                    $control->setProperty($onChangeProp, $to);
                    $report->add(self::id(), $control->path, $onChangeProp, $before !== '' ? $before : '(empty)', $to);

                    return;
                }
            }
            $body = $setLang . '; ' . $keepVarLang . $swap;
            $to = $control->format === 'yaml' ? '=' . $body : $body;
            $control->setProperty($onChangeProp, $to);
            $report->add(self::id(), $control->path, $onChangeProp, $before !== '' ? $before : '(empty)', $to);

            return;
        }

        if ($control->isToggle() || str_contains(strtolower($control->name), 'toggle')) {
            $onCheck = 'Set(' . $langVar . ', "fr"); Set(' . $stringsVar . ', ' . $stringsFr . ')';
            $onUncheck = 'Set(' . $langVar . ', "en"); Set(' . $stringsVar . ', ' . $stringsEn . ')';
            foreach (['OnCheck' => $onCheck, 'OnUncheck' => $onUncheck] as $prop => $body) {
                $before = (string) ($control->getProperty($prop) ?? '');
                if (str_contains($before, $stringsVar)) {
                    continue;
                }
                $merged = $before === '' ? $body : (rtrim(trim(ltrim(trim($before), '=')), ';') . '; ' . $body);
                $to = $control->format === 'yaml' ? '=' . $merged : $merged;
                $control->setProperty($prop, $to);
                $report->add(self::id(), $control->path, $prop, $before !== '' ? $before : '(empty)', $to);
            }
        }
    }

    private function injectLanguageRadio(
        ControlNode $screen,
        string $langVar,
        string $stringsVar,
        string $stringsEn,
        string $stringsFr,
        Report $report
    ): void {
        $onChange = 'Set(' . $langVar . ', If(Self.Selected.Value = "French", "fr", "en")); '
            . 'Set(' . $stringsVar . ', If(' . $langVar . ' = "fr", ' . $stringsFr . ', ' . $stringsEn . '))';
        $screen->addYamlChild(self::RADIO_NAME, [
            'Control' => 'Classic/Radio@2.1.0',
            'Properties' => [
                'Items' => '=["English", "French"]',
                'Layout' => '=Layout.Horizontal',
                'AccessibleLabel' => '="Language"',
                'Tooltip' => '="Switch UI language — edit strings in App.OnStart gblStringsEn / gblStringsFr"',
                'DefaultSelectedItems' => '=If(' . $langVar . ' = "fr", ["French"], ["English"])',
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
     * @param array<string, array{en:string,fr:string}> $packs
     */
    private function buildI18nBlock(
        string $langVar,
        string $stringsVar,
        string $stringsEn,
        string $stringsFr,
        array $packs,
        string $existingLangVar,
        bool $hasLangControl
    ): string {
        ksort($packs);
        $enFields = [];
        $frFields = [];
        foreach ($packs as $token => $pair) {
            $enFields[] = $token . ': "' . $this->escapeFx($pair['en']) . '"';
            $frFields[] = $token . ': "' . $this->escapeFx($pair['fr']) . '"';
        }
        if ($enFields === []) {
            $enFields[] = 'Placeholder: ""';
            $frFields[] = 'Placeholder: ""';
        }

        $initLang = 'Set(' . $langVar . ', "en");';
        // Sync from existing varLang when apps already use it (THCEE boolean true=EN).
        if ($existingLangVar !== $langVar && $hasLangControl) {
            $initLang = 'Set(' . $langVar . ', If(Or(' . $existingLangVar . ' = false, ' . $existingLangVar . ' = "FR", ' . $existingLangVar . ' = "fr", ' . $existingLangVar . ' = "fr-ca"), "fr", "en"));';
        }

        return self::BLOCK_START
            . "\n" . $initLang . "\n"
            . 'Set(' . $stringsEn . ', {' . "\n    " . implode(",\n    ", $enFields) . "\n});\n"
            . 'Set(' . $stringsFr . ', {' . "\n    " . implode(",\n    ", $frFields) . "\n});\n"
            . 'Set(' . $stringsVar . ', If(' . $langVar . ' = "fr", ' . $stringsFr . ', ' . $stringsEn . "))\n"
            . self::BLOCK_END;
    }

    private function escapeFx(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '""'], $s);
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

    /** @param list<ControlDocument> $documents
     * @return list<ControlNode>
     */
    private function findAllApps(array $documents): array
    {
        $apps = [];
        $preferred = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isApp()) {
                    continue;
                }
                $path = strtolower($doc->relativePath);
                if (str_contains($path, 'src/app.') || str_ends_with($path, '/app.pa.yaml')) {
                    $preferred[] = $control;
                } else {
                    $apps[] = $control;
                }
            }
        }

        return $preferred !== [] ? $preferred : $apps;
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
