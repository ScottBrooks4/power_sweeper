<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * English spelling variants for Power Apps UI text (string literals).
 *
 * Locales:
 * - us: American English
 * - ca: Canadian English (British -our/-re; American -ize, tire, curb, aluminum)
 * - uk: British English
 */
final class EnglishSpelling
{
    public const LOCALE_US = 'us';
    public const LOCALE_CA = 'ca';
    public const LOCALE_UK = 'uk';

    /** @var list<string> */
    public const LOCALES = [self::LOCALE_US, self::LOCALE_CA, self::LOCALE_UK];

    /**
     * Normalize UI / config aliases to us|ca|uk.
     */
    public static function normalizeLocale(string $locale): string
    {
        $l = strtolower(trim($locale));
        $l = str_replace(['_', ' '], '-', $l);
        return match ($l) {
            'us', 'en-us', 'en_us', 'american', 'usa', 'en' => self::LOCALE_US,
            'ca', 'en-ca', 'en_ca', 'canadian', 'canada' => self::LOCALE_CA,
            'uk', 'gb', 'en-gb', 'en_gb', 'en-uk', 'british', 'britain' => self::LOCALE_UK,
            default => self::LOCALE_CA,
        };
    }

    public static function localeLabel(string $locale): string
    {
        return match (self::normalizeLocale($locale)) {
            self::LOCALE_US => 'US English',
            self::LOCALE_UK => 'UK English',
            default => 'Canadian English',
        };
    }

    /**
     * Preferred spelling for each known variant under $locale.
     * Keys are lowercase US-ish lemmas used for lookup; values are the preferred form.
     *
     * @return array<string, string> lowercase preferred form keyed by lowercase US lemma
     */
    public static function preferredByLemma(string $locale): array
    {
        $locale = self::normalizeLocale($locale);
        $out = [];
        foreach (self::entries() as $row) {
            $pref = $row[$locale] ?? $row[self::LOCALE_US];
            $out[strtolower($row[self::LOCALE_US])] = $pref;
        }
        return $out;
    }

    /**
     * Map every known spelling variant → preferred spelling for $locale.
     * Longer keys first so "honoured" wins over "honour".
     *
     * @return array<string, string>
     */
    public static function rewriteMap(string $locale): array
    {
        $locale = self::normalizeLocale($locale);
        $map = [];
        foreach (self::entries() as $row) {
            $preferred = $row[$locale] ?? $row[self::LOCALE_US];
            foreach ([self::LOCALE_US, self::LOCALE_CA, self::LOCALE_UK] as $fromLoc) {
                $from = $row[$fromLoc] ?? null;
                if ($from === null || $from === '') {
                    continue;
                }
                if (strcasecmp($from, $preferred) === 0) {
                    continue;
                }
                $map[strtolower($from)] = $preferred;
            }
        }
        uksort($map, static function (string $a, string $b): int {
            $len = strlen($b) <=> strlen($a);
            return $len !== 0 ? $len : strcmp($a, $b);
        });
        return $map;
    }

    /**
     * Rewrite spelling variants inside plain text (preserves capitalization style of the match).
     */
    public static function rewriteText(string $text, string $locale): string
    {
        $map = self::rewriteMap($locale);
        if ($map === [] || $text === '') {
            return $text;
        }

        $pattern = '/\b(' . implode('|', array_map(
            static fn(string $w): string => preg_quote($w, '/'),
            array_keys($map)
        )) . ')\b/iu';

        return (string)preg_replace_callback($pattern, static function (array $m) use ($map): string {
            $found = $m[1];
            $key = strtolower($found);
            $preferred = $map[$key] ?? $found;
            return self::matchCase($found, $preferred);
        }, $text);
    }

    /**
     * Rewrite only double-quoted string literals in a Power Fx formula.
     * Single-quoted names (screens/controls) are left alone.
     */
    public static function rewriteFormulaStrings(string $formula, string $locale): string
    {
        if ($formula === '' || !str_contains($formula, '"')) {
            return $formula;
        }

        $out = '';
        $len = strlen($formula);
        $i = 0;
        while ($i < $len) {
            $ch = $formula[$i];
            if ($ch === '"') {
                $j = $i + 1;
                $lit = '';
                while ($j < $len) {
                    $c = $formula[$j];
                    if ($c === '"') {
                        if ($j + 1 < $len && $formula[$j + 1] === '"') {
                            $lit .= '""';
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $lit .= $c;
                    $j++;
                }
                $out .= '"' . self::rewriteText($lit, $locale) . '"';
                $i = ($j < $len && $formula[$j] === '"') ? $j + 1 : $j;
                continue;
            }
            $out .= $ch;
            $i++;
        }
        return $out;
    }

    private static function matchCase(string $original, string $replacement): string
    {
        if ($original === strtoupper($original) && strlen($original) > 1) {
            return strtoupper($replacement);
        }
        if ($original !== '' && ctype_upper($original[0])) {
            return mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1);
        }
        return $replacement;
    }

    /**
     * @return list<array{us:string,ca:string,uk:string}>
     */
    private static function entries(): array
    {
        // us / ca / uk preferred forms. Canadian: British -our/-re; American -ize, tire, curb, aluminum.
        return [
            // -our / -or
            ['us' => 'color', 'ca' => 'colour', 'uk' => 'colour'],
            ['us' => 'colors', 'ca' => 'colours', 'uk' => 'colours'],
            ['us' => 'colored', 'ca' => 'coloured', 'uk' => 'coloured'],
            ['us' => 'coloring', 'ca' => 'colouring', 'uk' => 'colouring'],
            ['us' => 'colorful', 'ca' => 'colourful', 'uk' => 'colourful'],
            ['us' => 'honor', 'ca' => 'honour', 'uk' => 'honour'],
            ['us' => 'honors', 'ca' => 'honours', 'uk' => 'honours'],
            ['us' => 'honored', 'ca' => 'honoured', 'uk' => 'honoured'],
            ['us' => 'honoring', 'ca' => 'honouring', 'uk' => 'honouring'],
            ['us' => 'honorable', 'ca' => 'honourable', 'uk' => 'honourable'],
            ['us' => 'favor', 'ca' => 'favour', 'uk' => 'favour'],
            ['us' => 'favors', 'ca' => 'favours', 'uk' => 'favours'],
            ['us' => 'favored', 'ca' => 'favoured', 'uk' => 'favoured'],
            ['us' => 'favoring', 'ca' => 'favouring', 'uk' => 'favouring'],
            ['us' => 'favorite', 'ca' => 'favourite', 'uk' => 'favourite'],
            ['us' => 'favorites', 'ca' => 'favourites', 'uk' => 'favourites'],
            ['us' => 'behavior', 'ca' => 'behaviour', 'uk' => 'behaviour'],
            ['us' => 'behaviors', 'ca' => 'behaviours', 'uk' => 'behaviours'],
            ['us' => 'labor', 'ca' => 'labour', 'uk' => 'labour'],
            ['us' => 'neighbor', 'ca' => 'neighbour', 'uk' => 'neighbour'],
            ['us' => 'neighbors', 'ca' => 'neighbours', 'uk' => 'neighbours'],
            ['us' => 'neighborhood', 'ca' => 'neighbourhood', 'uk' => 'neighbourhood'],
            ['us' => 'rumor', 'ca' => 'rumour', 'uk' => 'rumour'],
            ['us' => 'humor', 'ca' => 'humour', 'uk' => 'humour'],
            ['us' => 'vapor', 'ca' => 'vapour', 'uk' => 'vapour'],
            ['us' => 'tumor', 'ca' => 'tumour', 'uk' => 'tumour'],
            ['us' => 'flavor', 'ca' => 'flavour', 'uk' => 'flavour'],
            ['us' => 'flavors', 'ca' => 'flavours', 'uk' => 'flavours'],

            // -re / -er
            ['us' => 'center', 'ca' => 'centre', 'uk' => 'centre'],
            ['us' => 'centers', 'ca' => 'centres', 'uk' => 'centres'],
            ['us' => 'centered', 'ca' => 'centred', 'uk' => 'centred'],
            ['us' => 'meter', 'ca' => 'metre', 'uk' => 'metre'],
            ['us' => 'meters', 'ca' => 'metres', 'uk' => 'metres'],
            ['us' => 'liter', 'ca' => 'litre', 'uk' => 'litre'],
            ['us' => 'liters', 'ca' => 'litres', 'uk' => 'litres'],
            ['us' => 'theater', 'ca' => 'theatre', 'uk' => 'theatre'],
            ['us' => 'fiber', 'ca' => 'fibre', 'uk' => 'fibre'],
            ['us' => 'fibers', 'ca' => 'fibres', 'uk' => 'fibres'],

            // -ise / -ize (CA follows US -ize; UK -ise)
            ['us' => 'organize', 'ca' => 'organize', 'uk' => 'organise'],
            ['us' => 'organizes', 'ca' => 'organizes', 'uk' => 'organises'],
            ['us' => 'organized', 'ca' => 'organized', 'uk' => 'organised'],
            ['us' => 'organizing', 'ca' => 'organizing', 'uk' => 'organising'],
            ['us' => 'organization', 'ca' => 'organization', 'uk' => 'organisation'],
            ['us' => 'organizations', 'ca' => 'organizations', 'uk' => 'organisations'],
            ['us' => 'recognize', 'ca' => 'recognize', 'uk' => 'recognise'],
            ['us' => 'recognizes', 'ca' => 'recognizes', 'uk' => 'recognises'],
            ['us' => 'recognized', 'ca' => 'recognized', 'uk' => 'recognised'],
            ['us' => 'recognizing', 'ca' => 'recognizing', 'uk' => 'recognising'],
            ['us' => 'analyze', 'ca' => 'analyze', 'uk' => 'analyse'],
            ['us' => 'analyzes', 'ca' => 'analyzes', 'uk' => 'analyses'],
            ['us' => 'analyzed', 'ca' => 'analyzed', 'uk' => 'analysed'],
            ['us' => 'analyzing', 'ca' => 'analyzing', 'uk' => 'analysing'],
            ['us' => 'authorize', 'ca' => 'authorize', 'uk' => 'authorise'],
            ['us' => 'authorized', 'ca' => 'authorized', 'uk' => 'authorised'],
            ['us' => 'authorization', 'ca' => 'authorization', 'uk' => 'authorisation'],
            ['us' => 'realize', 'ca' => 'realize', 'uk' => 'realise'],
            ['us' => 'realized', 'ca' => 'realized', 'uk' => 'realised'],
            ['us' => 'minimize', 'ca' => 'minimize', 'uk' => 'minimise'],
            ['us' => 'minimized', 'ca' => 'minimized', 'uk' => 'minimised'],
            ['us' => 'maximize', 'ca' => 'maximize', 'uk' => 'maximise'],
            ['us' => 'maximized', 'ca' => 'maximized', 'uk' => 'maximised'],
            ['us' => 'customize', 'ca' => 'customize', 'uk' => 'customise'],
            ['us' => 'customized', 'ca' => 'customized', 'uk' => 'customised'],
            ['us' => 'initialize', 'ca' => 'initialize', 'uk' => 'initialise'],
            ['us' => 'initialized', 'ca' => 'initialized', 'uk' => 'initialised'],

            // -yse (UK) — already covered via analyze

            // -ence / -ense
            ['us' => 'defense', 'ca' => 'defence', 'uk' => 'defence'],
            ['us' => 'offense', 'ca' => 'offence', 'uk' => 'offence'],
            ['us' => 'license', 'ca' => 'licence', 'uk' => 'licence'], // noun; verb stays license in CA/UK often — keep simple

            // misc CA≈US vs UK
            ['us' => 'tire', 'ca' => 'tire', 'uk' => 'tyre'],
            ['us' => 'tires', 'ca' => 'tires', 'uk' => 'tyres'],
            ['us' => 'curb', 'ca' => 'curb', 'uk' => 'kerb'],
            ['us' => 'aluminum', 'ca' => 'aluminum', 'uk' => 'aluminium'],
            ['us' => 'catalog', 'ca' => 'catalogue', 'uk' => 'catalogue'],
            ['us' => 'dialog', 'ca' => 'dialogue', 'uk' => 'dialogue'],
            ['us' => 'program', 'ca' => 'program', 'uk' => 'programme'], // CA: program (computer/TV often program)
            ['us' => 'traveling', 'ca' => 'travelling', 'uk' => 'travelling'],
            ['us' => 'traveled', 'ca' => 'travelled', 'uk' => 'travelled'],
            ['us' => 'canceled', 'ca' => 'cancelled', 'uk' => 'cancelled'],
            ['us' => 'canceling', 'ca' => 'cancelling', 'uk' => 'cancelling'],
            ['us' => 'labeled', 'ca' => 'labelled', 'uk' => 'labelled'],
            ['us' => 'labeling', 'ca' => 'labelling', 'uk' => 'labelling'],
            ['us' => 'modeling', 'ca' => 'modelling', 'uk' => 'modelling'],
            ['us' => 'modeled', 'ca' => 'modelled', 'uk' => 'modelled'],
            ['us' => 'gray', 'ca' => 'grey', 'uk' => 'grey'],
            ['us' => 'grays', 'ca' => 'greys', 'uk' => 'greys'],
            ['us' => 'mold', 'ca' => 'mould', 'uk' => 'mould'],
            ['us' => 'jewelry', 'ca' => 'jewellery', 'uk' => 'jewellery'],
            ['us' => 'aging', 'ca' => 'ageing', 'uk' => 'ageing'],
        ];
    }
}
