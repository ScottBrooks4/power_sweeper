<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ControlNode;
use PowerSweeper\Report;

/**
 * Labels that mention an acronym become HTML text with <abbr> tags.
 *
 * The language toggle is not a second pass over HTML. fxT / If(varLang) already
 * picks one string, so each side is tagged on its own: OHS in the English
 * string, SST in the French string. The control Color stays gblTheme.Text
 * (or whatever it already was) so dark mode still inks the HTML.
 */
final class AcronymHtmlSweep implements SweepInterface
{
    /** @var array<string, string> token => expansion. Longer tokens first at runtime. */
    private const EN = [
        'WHMIS' => 'Workplace Hazardous Materials Information System',
        'WHVP' => 'Workplace Harassment and Violence Prevention',
        'AESP' => 'Ammunition and Explosives Safety Program',
        'RACI' => 'Responsible, Accountable, Consulted, Informed',
        'OHS' => 'Occupational Health and Safety',
        'OPI' => 'Office of Primary Interest',
        'PxS' => 'Probability times severity',
        'PPE' => 'Personal protective equipment',
        'GHS' => 'Globally Harmonized System',
        'CAF' => 'Canadian Armed Forces',
        'HQ' => 'Headquarters',
        'L1' => 'Level 1',
        'L2' => 'Level 2',
    ];

    /** @var array<string, string> */
    private const FR = [
        'SIMDUT' => 'Système d&#039;information sur les matières dangereuses utilisées au travail',
        'WHVP' => 'Prévention du harcèlement et de la violence dans le lieu de travail',
        'AESP' => 'Programme de sécurité des munitions et des explosifs',
        'RACI' => 'Réalisateur, Approbateur, Consulté, Informé',
        'SST' => 'Santé et sécurité au travail',
        'OPI' => 'Bureau de premier intérêt',
        'PxS' => 'Probabilité fois sévérité',
        'ÉPI' => 'Équipement de protection individuelle',
        'SGH' => 'Système général harmonisé',
        'CAF' => 'Forces armées canadiennes',
        'QG' => 'Quartier général',
        'N1' => 'Niveau 1',
        'N2' => 'Niveau 2',
    ];

    /** Properties an htmlViewer actually reads. Label chrome is dropped on convert. */
    private const HTML_PROPS = [
        'HtmlText', 'Fill', 'Color', 'Font', 'Size', 'AutoHeight',
        'PaddingTop', 'PaddingRight', 'PaddingBottom', 'PaddingLeft',
        'Align', 'VerticalAlign',
        'X', 'Y', 'Width', 'Height', 'Visible', 'ZIndex',
    ];

    public static function id(): string
    {
        return 'acronym_html';
    }

    public static function label(): string
    {
        return 'Acronym tooltips';
    }

    public static function description(): string
    {
        return 'Switch labels that contain acronyms to HTML text and wrap each acronym in an abbr tag with no underline. Hover still shows the expansion. English and French sides of the language toggle are tagged separately.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $this->convert($control, $report);
            }
        }
    }

    private function convert(ControlNode $control, Report $report): void
    {
        $type = strtolower($control->type);
        $isLabel = $type === 'label' || str_contains($type, 'label@');
        $isHtml = $type === 'htmlviewer' || str_contains($type, 'htmlviewer');
        if (!$isLabel && !$isHtml) {
            return;
        }
        if ($isLabel && $this->isClickTarget($control)) {
            return;
        }

        $sourceProp = $isHtml ? 'HtmlText' : 'Text';
        $text = $control->getProperty($sourceProp);
        if ($text === null || trim($text) === '') {
            return;
        }
        $rewritten = $this->rewriteFormula($this->unwrap($text));
        if ($rewritten === null) {
            return;
        }

        $stored = $control->format === 'yaml' ? '=' . $rewritten : $rewritten;
        if ($isLabel) {
            $control->setControlType('HtmlViewer@2.1.0', [
                'Id' => 'http://microsoft.com/appmagic/htmlViewer',
                'Name' => 'htmlViewer',
                'Version' => '2.1.0',
            ]);
            $control->setStyleName('defaultHtmlViewerStyle');
            $control->removeProperty('Text');
            foreach ($this->extraProperties($control) as $prop) {
                $control->removeProperty($prop);
            }
        }
        $control->setProperty('HtmlText', $stored, 'Data', 'User');
        $report->add(self::id(), $control->path, 'HtmlText', $text, $stored);
    }

    /** Labels that navigate stay labels so the click is not dropped with the template. */
    private function isClickTarget(ControlNode $control): bool
    {
        $on = trim($this->unwrap((string) ($control->getProperty('OnSelect') ?? '')));
        if ($on === '' || strcasecmp($on, 'false') === 0 || strcasecmp($on, 'blank()') === 0) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    private function extraProperties(ControlNode $control): array
    {
        $drop = [];
        // Known label-only chrome. Anything else the node still has is left alone
        // when we cannot list properties; the common set is enough to avoid a
        // red htmlViewer after import.
        foreach ([
            'Live', 'Role', 'LineHeight', 'Overflow', 'Wrap', 'IsErrorMessage',
            'DisabledColor', 'PressedColor', 'HoverColor', 'BorderColor',
            'DisabledBorderColor', 'PressedBorderColor', 'HoverBorderColor',
            'BorderStyle', 'BorderThickness', 'FocusedBorderColor', 'FocusedBorderThickness',
            'DisabledFill', 'PressedFill', 'HoverFill', 'Italic', 'Underline', 'Strikethrough',
            'DisplayMode', 'TabIndex', 'minimumWidth', 'minimumHeight', 'maximumWidth', 'maximumHeight',
            'OnSelect', 'Tooltip', 'ContentLanguage', 'FontWeight',
        ] as $prop) {
            if (!in_array($prop, self::HTML_PROPS, true) && $control->hasProperty($prop)) {
                $drop[] = $prop;
            }
        }

        return $drop;
    }

    private function unwrap(string $value): string
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = trim(substr($v, 1));
        }

        return $v;
    }

    /**
     * @return string|null rewritten formula, or null when no acronym was tagged
     */
    private function rewriteFormula(string $formula): ?string
    {
        $zones = $this->languageZones($formula);
        $changed = false;
        $out = '';
        $len = strlen($formula);
        $i = 0;
        while ($i < $len) {
            if ($formula[$i] !== '"') {
                $out .= $formula[$i];
                $i++;
                continue;
            }
            $start = $i;
            $i++;
            $literal = '';
            while ($i < $len) {
                if ($formula[$i] === '"') {
                    if ($i + 1 < $len && $formula[$i + 1] === '"') {
                        $literal .= '""';
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $literal .= $formula[$i];
                $i++;
            }
            $lang = $this->langAt($start, $zones, $literal);
            $wrapped = $this->wrapLiteral($literal, $lang);
            if ($wrapped !== $literal) {
                $changed = true;
            }
            $out .= '"' . $wrapped . '"';
        }

        return $changed ? $out : null;
    }

    /**
     * Character ranges that belong to one language because they sit in an
     * fxT argument or the matching branch of If(varLang, …).
     *
     * @return list<array{0:int,1:int,2:string}>
     */
    private function languageZones(string $formula): array
    {
        $zones = [];
        $len = strlen($formula);
        $i = 0;
        while ($i < $len) {
            if ($formula[$i] === '"') {
                $i++;
                while ($i < $len) {
                    if ($formula[$i] === '"') {
                        if ($i + 1 < $len && $formula[$i + 1] === '"') {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            $rest = substr($formula, $i);
            if (preg_match('/^fxT\s*\(/', $rest, $m)) {
                $open = $i + strlen($m[0]) - 1;
                $args = $this->argSpans($formula, $open);
                if (count($args) >= 2) {
                    $zones[] = [$args[0][0], $args[0][1], 'en'];
                    $zones[] = [$args[1][0], $args[1][1], 'fr'];
                }
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/^If\s*\(/', $rest, $m)) {
                $open = $i + strlen($m[0]) - 1;
                $args = $this->argSpans($formula, $open);
                if (count($args) >= 3) {
                    $cond = substr($formula, $args[0][0], $args[0][1] - $args[0][0]);
                    if (str_contains($cond, 'varLang')) {
                        $zones[] = [$args[1][0], $args[1][1], 'en'];
                        $zones[] = [$args[2][0], $args[2][1], 'fr'];
                    }
                }
                $i += strlen($m[0]);
                continue;
            }
            $i++;
        }

        return $zones;
    }

    /**
     * @param list<array{0:int,1:int,2:string}> $zones
     */
    private function langAt(int $pos, array $zones, string $literal): string
    {
        $lang = null;
        foreach ($zones as $zone) {
            if ($pos >= $zone[0] && $pos < $zone[1]) {
                $lang = $zone[2];
            }
        }
        if ($lang !== null) {
            return $lang;
        }
        if (preg_match('/[àâäéèêëïîôùûüçœÀÂÄÉÈÊËÏÎÔÙÛÜÇŒ]/u', $literal)) {
            return 'fr';
        }

        return 'en';
    }

    /**
     * Spans of the arguments inside the parens that open at $open (index of '(').
     *
     * @return list<array{0:int,1:int}>
     */
    private function argSpans(string $formula, int $open): array
    {
        $len = strlen($formula);
        $depth = 0;
        $quote = false;
        $spans = [];
        $argStart = $open + 1;
        for ($i = $open; $i < $len; $i++) {
            $ch = $formula[$i];
            if ($quote) {
                if ($ch === '"') {
                    if ($i + 1 < $len && $formula[$i + 1] === '"') {
                        $i++;
                        continue;
                    }
                    $quote = false;
                }
                continue;
            }
            if ($ch === '"') {
                $quote = true;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $spans[] = [$argStart, $i];
                    return $spans;
                }
                continue;
            }
            if ($ch === ',' && $depth === 1) {
                $spans[] = [$argStart, $i];
                $argStart = $i + 1;
            }
        }

        return $spans;
    }

    private function wrapLiteral(string $literal, string $lang): string
    {
        // Already tagged. Only strip the dotted underline; leave the title for hover.
        if (str_contains($literal, '<abbr')) {
            return $this->quietAbbr($literal);
        }
        $dict = $lang === 'fr' ? self::FR : self::EN;
        $tokens = array_keys($dict);
        usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        // Escape plain text only. Existing HTML (info popups, already-built markup)
        // must stay as tags. Leave the literal alone when no acronym is wrapped.
        $hasTags = str_contains($literal, '<');
        $work = $hasTags ? $literal : htmlspecialchars($literal, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
        $changed = false;
        foreach ($tokens as $token) {
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($token, '/') . '(?![\p{L}\p{N}_])/u';
            $title = $dict[$token];
            $next = preg_replace_callback(
                $pattern,
                static function (array $m) use ($title): string {
                    return "<abbr title='" . $title . "' style='text-decoration:none'>" . $m[0] . '</abbr>';
                },
                $work
            ) ?? $work;
            if ($next !== $work) {
                $changed = true;
                $work = $next;
            }
        }

        return $changed ? $work : $literal;
    }

    /** abbr draws a dotted underline unless text-decoration is turned off. The title stays. */
    private function quietAbbr(string $literal): string
    {
        $quiet = preg_replace(
            '/<abbr\b(?![^>]*\bstyle=)([^>]*)>/u',
            "<abbr$1 style='text-decoration:none'>",
            $literal
        );

        return $quiet ?? $literal;
    }
}
