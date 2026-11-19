<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Extract EN/FR string pairs from App.Formulas LangFilter tables
 * (StatusTable, UserRoleTable, TransmittalRecommendationTable, …).
 */
final class LangTableHarvester
{
    /** @var list<string> */
    private const STRING_FIELDS = [
        'Text',
        'Title',
        'Name',
        'Message',
        'RecommendationText',
    ];

    /**
     * @return array<string, array{en:string,fr:string}>
     */
    public static function harvest(string $formulas): array
    {
        $body = trim($formulas);
        if (str_starts_with($body, '=')) {
            $body = substr($body, 1);
        }
        $body = trim($body);
        if ($body === '' || !str_contains($body, 'LangFilter')) {
            return [];
        }

        /** @var array<string, array{en:string,fr:string}> $packs */
        $packs = [];
        /** @var list<array{start:int,end:int}> $processed */
        $processed = [];
        $offset = 0;
        while ($offset < strlen($body)) {
            $langPos = stripos($body, 'LangFilter', $offset);
            if ($langPos === false) {
                break;
            }
            $skip = false;
            foreach ($processed as $range) {
                if ($langPos >= $range['start'] && $langPos < $range['end']) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                $offset = $langPos + 10;
                continue;
            }
            $tableName = self::tableNameBefore($body, $langPos);
            if ($tableName === null) {
                $offset = $langPos + 10;
                continue;
            }
            $tableStart = strrpos(substr($body, 0, $langPos), $tableName . ' =');
            if ($tableStart === false) {
                $offset = $langPos + 10;
                continue;
            }
            $tableEnd = self::findTableEnd($body, $langPos);
            if ($tableEnd === null) {
                $offset = $langPos + 10;
                continue;
            }
            $processed[] = ['start' => $tableStart, 'end' => $tableEnd];
            $chunk = substr($body, $tableStart, $tableEnd - $tableStart);
            $enBlock = self::langBlock($chunk, 'EN');
            $frBlock = self::langBlock($chunk, 'FR');
            if ($enBlock !== null && $frBlock !== null) {
                self::mergePairs($packs, $tableName, $enBlock, $frBlock);
            }
            $offset = $tableEnd;
        }

        return $packs;
    }

    private static function tableNameBefore(string $body, int $langPos): ?string
    {
        $window = substr($body, max(0, $langPos - 8000), min(8000, $langPos));
        if (preg_match('/(\w+)\s*=\s*\[(?:[^\[\]]|\[[^\]]*\])*$/s', $window, $m) !== 1) {
            return null;
        }
        $name = $m[1];
        if (!preg_match('/Table$|Labels$|Translations$/i', $name) && !str_contains($window, 'LangFilter')) {
            return null;
        }

        return $name;
    }

    private static function findTableEnd(string $body, int $langPos): ?int
    {
        $start = strrpos(substr($body, 0, $langPos), '=');
        if ($start === false) {
            return null;
        }
        $bracket = strpos($body, '[', $start);
        if ($bracket === false || $bracket > $langPos) {
            return null;
        }
        $depth = 0;
        $len = strlen($body);
        for ($i = $bracket; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    $semi = $i + 1;
                    while ($semi < $len && ctype_space($body[$semi])) {
                        $semi++;
                    }
                    if ($semi < $len && $body[$semi] === ';') {
                        return $semi + 1;
                    }

                    return $i + 1;
                }
            }
        }

        return null;
    }

    private static function langBlock(string $chunk, string $lang): ?string
    {
        $q = preg_quote($lang, '/');
        if (preg_match('/LangFilter:\s*"' . $q . '"\s*,\s*Values:\s*\[/s', $chunk, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $valuesInner = self::extractBracketContents($chunk, $start);
        if ($valuesInner === null) {
            return null;
        }

        return substr($valuesInner, 1, -1);
    }

    private static function extractBracketContents(string $body, int $openBracketPos): ?string
    {
        if (($body[$openBracketPos] ?? '') !== '[') {
            return null;
        }
        $depth = 0;
        $len = strlen($body);
        for ($i = $openBracketPos; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $openBracketPos, $i - $openBracketPos + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     */
    private static function mergePairs(array &$packs, string $tableName, string $enBlock, string $frBlock): void
    {
        $prefix = preg_replace('/Table$/i', '', $tableName) ?? $tableName;
        $prefix = self::pascal($prefix);
        if ($prefix === '') {
            $prefix = 'Lang';
        }

        $enRecords = self::extractRecords($enBlock);
        $frRecords = self::extractRecords($frBlock);
        $count = max(count($enRecords), count($frRecords));
        for ($i = 0; $i < $count; $i++) {
            $enRec = $enRecords[$i] ?? [];
            $frRec = $frRecords[$i] ?? [];
            $valueKey = $enRec['Value'] ?? $frRec['Value'] ?? (string) $i;
            $valueKey = preg_replace('/[^A-Za-z0-9_]+/', '', (string) $valueKey) ?? (string) $i;

            foreach (self::STRING_FIELDS as $field) {
                $en = $enRec[$field] ?? '';
                $fr = $frRec[$field] ?? '';
                if ($en === '' && $fr === '') {
                    continue;
                }
                if ($en === '') {
                    $en = $fr;
                }
                if ($fr === '') {
                    $fr = $en;
                }
                $base = $prefix . self::pascal($field) . self::pascal($valueKey);
                if ($field === 'Text' && $en !== '') {
                    $textToken = self::pascal($en);
                    if ($textToken !== '') {
                        $base = $prefix . $textToken;
                    }
                }
                if ($field === 'Text' && isset($packs[$base]) && $packs[$base]['en'] === $en) {
                    continue;
                }
                $token = self::uniqueToken($packs, $base, $en);
                $packs[$token] = ['en' => $en, 'fr' => $fr];
            }

            // Nested Text: [ { RecommendationText, Message }, … ] in transmittal tables.
            $nestedEn = $enRec['_nested'] ?? [];
            $nestedFr = $frRec['_nested'] ?? [];
            $nestedCount = max(count($nestedEn), count($nestedFr));
            for ($j = 0; $j < $nestedCount; $j++) {
                $nEn = $nestedEn[$j] ?? [];
                $nFr = $nestedFr[$j] ?? [];
                foreach (['RecommendationText', 'Message'] as $field) {
                    $en = $nEn[$field] ?? '';
                    $fr = $nFr[$field] ?? '';
                    if ($en === '' && $fr === '') {
                        continue;
                    }
                    if ($en === '') {
                        $en = $fr;
                    }
                    if ($fr === '') {
                        $fr = $en;
                    }
                    $titlePart = isset($enRec['Title']) ? self::pascal((string) $enRec['Title']) : $prefix;
                    $subKey = $nEn['Value'] ?? $nFr['Value'] ?? (string) $j;
                    $subKey = preg_replace('/[^A-Za-z0-9_]+/', '', (string) $subKey) ?? (string) $j;
                    $base = $titlePart . self::pascal($field) . self::pascal($subKey);
                    if ($field === 'RecommendationText' && $en !== '') {
                        $short = self::pascal($en);
                        if ($short !== '') {
                            $base = $titlePart . $short;
                        }
                    }
                    $token = self::uniqueToken($packs, $base, $en);
                    $packs[$token] = ['en' => $en, 'fr' => $fr];
                }
            }
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private static function extractRecords(string $valuesBlock): array
    {
        $records = [];
        $parts = self::splitTopLevelRecords($valuesBlock);
        foreach ($parts as $part) {
            $rec = [];
            if (preg_match('/Value:\s*([^,\n}]+)/', $part, $m) === 1) {
                $rec['Value'] = trim($m[1]);
            }
            foreach (self::STRING_FIELDS as $field) {
                if (preg_match('/' . $field . ':\s*"((?:[^"\\\\]|\\\\.)*)"/s', $part, $m) === 1) {
                    $rec[$field] = stripcslashes($m[1]);
                }
            }
            if (preg_match('/Text:\s*\[(.*)\]\s*(?:,|\})/s', $part, $m) === 1) {
                $rec['_nested'] = self::extractRecords($m[1]);
            }
            if ($rec !== []) {
                $records[] = $rec;
            }
        }

        return $records;
    }

    /** @return list<string> */
    private static function splitTopLevelRecords(string $block): array
    {
        $records = [];
        $depth = 0;
        $current = '';
        $len = strlen($block);
        for ($i = 0; $i < $len; $i++) {
            $ch = $block[$i];
            if ($ch === '{') {
                if ($depth === 0) {
                    $current = '{';
                } else {
                    $current .= $ch;
                }
                $depth++;
                continue;
            }
            if ($ch === '}') {
                $depth--;
                $current .= $ch;
                if ($depth === 0) {
                    $records[] = $current;
                    $current = '';
                }
                continue;
            }
            if ($depth > 0) {
                $current .= $ch;
            }
        }

        return $records;
    }

    /**
     * @param array<string, array{en:string,fr:string}> $packs
     */
    private static function uniqueToken(array $packs, string $base, string $en): string
    {
        $token = $base;
        if ($token === '' || !preg_match('/^[A-Za-z_]\w*$/', $token)) {
            $token = 'LangString' . (count($packs) + 1);
        }
        if (preg_match('/^\d/', $token)) {
            $token = 'T' . $token;
        }
        $token = substr($token, 0, 48);
        $n = 2;
        while (isset($packs[$token]) && $packs[$token]['en'] !== $en) {
            $token = substr($base, 0, 44) . $n;
            $n++;
        }

        return $token;
    }

    private static function pascal(string $text): string
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

        return substr($out, 0, 32);
    }
}
