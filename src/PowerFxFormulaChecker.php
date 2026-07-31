<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Live Power Fx formula analysis mirroring Studio App checker formula rules.
 *
 * @phpstan-type Finding array{
 *   ruleId:string,
 *   level:string,
 *   messageArgs:list<string|int>,
 *   location:string,
 *   screen:string,
 *   controlType:string,
 *   property:string,
 *   snippet:string,
 *   charOffset:int,
 *   charLength:int
 * }
 */
final class PowerFxFormulaChecker
{
    /** @var array<string, true> */
    private const DELEGATION_FUNCS = [
        'Lower' => true, 'Upper' => true, 'Trim' => true, 'Len' => true,
        'CountIf' => true, 'Find' => true, 'MatchAll' => true,
        'Mid' => true, 'Left' => true, 'Right' => true, 'Substitute' => true,
        'IsMatch' => true,
    ];

  public function __construct(
        private readonly AppControlCatalog $catalog,
        private readonly AppDataContext $dataContext,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function check(
        string $formula,
        string $screen,
        string $location,
        string $controlType,
        string $property,
        string $controlName,
        array $localNames,
    ): array {
        if (trim($formula) === '') {
            return [];
        }

        $findings = [];
        $body = ltrim(trim($formula), '=');

        $findings = array_merge($findings, $this->checkLocale($body, $location, $screen, $controlType, $property));
        $findings = array_merge($findings, $this->checkBooleans($body, $location, $screen, $controlType, $property, $controlName));

        $parts = PowerFxFormulaSegments::splitForStructure($body);
        $offset = 0;
        foreach ($parts as [$type, $text]) {
            if ($type === 'code') {
                $findings = array_merge($findings, $this->checkIdentifiers(
                    $text,
                    $screen,
                    $location,
                    $controlType,
                    $property,
                    $controlName,
                    $localNames,
                    $body,
                    $offset
                ));
            }
            $offset += strlen($text);
        }

        $findings = array_merge($findings, $this->checkDelegation($body, $location, $screen, $controlType, $property));
        $findings = array_merge($findings, $this->checkMangledScreenRefs($body, $location, $screen, $controlType, $property));
        $findings = array_merge($findings, $this->checkScreenQualifiedDateCalls($body, $location, $screen, $controlType, $property));

        return $findings;
    }

    /**
     * 'Screen Name'.Date(1900, 1, 1) — Date function call wrongly qualified through a screen
     * that also hosts a control named Date (Studio: app-ErrInvalidName | .Date).
     *
     * @return list<Finding>
     */
    private function checkScreenQualifiedDateCalls(
        string $body,
        string $location,
        string $screen,
        string $controlType,
        string $property,
    ): array {
        $findings = [];
        $parts = PowerFxFormulaSegments::splitForStructure($body);
        $offset = 0;
        foreach ($parts as [$type, $text]) {
            if ($type === 'code'
                && preg_match_all("/'(?:[^']|'')+'\\.Date\\s*\\(/", $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $findings[] = $this->makeFinding(
                        'app-ErrInvalidName',
                        'High',
                        ['.Date'],
                        $location,
                        $screen,
                        $controlType,
                        $property,
                        '.Date',
                        $offset + (int) $match[1] + strlen($match[0]) - strlen('Date('),
                        5
                    );
                }
            }
            $offset += strlen($text);
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkMangledScreenRefs(
        string $body,
        string $location,
        string $screen,
        string $controlType,
        string $property,
    ): array {
        $parts = PowerFxFormulaSegments::splitForStructure($body);
        foreach ($parts as [$type, $text]) {
            if ($type !== 'code') {
                continue;
            }
            if (str_contains($text, "'''") || preg_match("/'[^']+'\.Admin Screen/i", str_replace("''", "'", $text))) {
                return [[
                    'ruleId' => 'app-formula-mangled-screen-ref',
                    'level' => 'error',
                    'messageArgs' => ['Mangled screen reference (over-quoted or merged screen name)'],
                    'location' => $location,
                    'screen' => $screen,
                    'controlType' => $controlType,
                    'property' => $property,
                    'snippet' => StudioIssueScanner::preview($text),
                    'charOffset' => 0,
                    'charLength' => min(strlen($text), 120),
                ]];
            }
        }

        return [];
    }

    /**
     * @return list<Finding>
     */
    private function checkLocale(string $body, string $location, string $screen, string $controlType, string $property): array
    {
        if (!FormulaLocaleNormalizer::looksLocaleCorrupted('=' . $body)) {
            return [];
        }

        $findings = [];
        $masked = $this->maskProtected($body);

        // ;; chaining
        $offset = 0;
        while (($pos = strpos($masked, ';;', $offset)) !== false) {
            $findings[] = $this->makeFinding(
                'app-ErrOperatorExpected',
                'High',
                [],
                $location,
                $screen,
                $controlType,
                $property,
                ';',
                $pos,
                1
            );
            $offset = $pos + 2;
        }

        // Lone ; in code (locale list separator)
        $parts = FormulaReferenceExtractor::identifiers($body); // force split via reimplementation
        unset($parts);
        $offset = 0;
        while (($pos = strpos($masked, ';', $offset)) !== false) {
            $before = $pos > 0 ? $masked[$pos - 1] : '';
            $after = $masked[$pos + 1] ?? '';
            // Skip for(;;) style — already handled
            if ($before === ';' || $after === ';') {
                $offset = $pos + 1;
                continue;
            }
            $snippet = ';';
            $findings[] = $this->makeFinding(
                'app-ErrOperatorExpected',
                'High',
                [],
                $location,
                $screen,
                $controlType,
                $property,
                $snippet,
                $pos,
                1
            );
            $offset = $pos + 1;
        }

        // CountIf(...; Value ...) cascade
        if (preg_match_all('/\bCountIf\s*\([^)]*;\s*Value\b/i', $masked, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $match) {
                $start = (int) $match[1];
                $call = $match[0];
                $findings[] = $this->makeFinding(
                    'app-ErrBadArityMinimum',
                    'High',
                    [1, 2],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    $call,
                    $start,
                    strlen($call)
                );
                if (preg_match('/;\s*Value\b/i', $call, $vm, PREG_OFFSET_CAPTURE)) {
                    $vpos = $start + (int) $vm[0][1] + 1;
                    while ($vpos < strlen($body) && $body[$vpos] === ' ') {
                        $vpos++;
                    }
                    $findings[] = $this->makeFinding(
                        'app-ErrInvalidName',
                        'High',
                        ['Value'],
                        $location,
                        $screen,
                        $controlType,
                        $property,
                        'Value',
                        $vpos,
                        5
                    );
                }
            }
        }

        // If(…; …; …) invalid args
        if (preg_match_all('/\bIf\s*\([^)]*;/i', $masked, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $match) {
                $start = (int) $match[1];
                $findings[] = $this->makeFinding(
                    'app-ErrInvalidArgs-Func',
                    'High',
                    ['If'],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    'If',
                    $start,
                    2
                );
            }
        }

        // Coalesce / ParseJSON / LookUp with ;
        foreach (['Coalesce', 'ParseJSON', 'LookUp', 'Filter', 'Search', 'Notify', 'RGBA', 'DateTime', 'Patch'] as $fn) {
            if (preg_match_all('/\b' . preg_quote($fn, '/') . '\s*\([^)]*;/i', $masked, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $start = (int) $match[1];
                    $findings[] = $this->makeFinding(
                        'app-ErrInvalidArgs-Func',
                        'High',
                        [$fn],
                        $location,
                        $screen,
                        $controlType,
                        $property,
                        $fn,
                        $start,
                        strlen($fn)
                    );
                }
            }
        }

        // RGBA bad arity (5 args from locale alpha)
        if (preg_match_all('/\bRGBA\s*\([^)]*,\s*\d+\s*,\s*\d+\b/i', $masked, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $match) {
                $start = (int) $match[1];
                $snippet = substr($body, $start, min(40, strlen($body) - $start));
                if (preg_match('/RGBA\([^)]+\)/', $snippet, $full)) {
                    $snippet = $full[0];
                }
                $findings[] = $this->makeFinding(
                    'app-ErrBadArity',
                    'High',
                    [5, 4],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    $snippet,
                    $start,
                    strlen($snippet)
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkBooleans(string $body, string $location, string $screen, string $controlType, string $property, string $controlName): array
    {
        $propLower = strtolower($property);
        $boolProps = ['checked', 'default', 'value', 'reset', 'visible', 'wrap', 'autoheight', 'autowidth'];
        if (!in_array($propLower, $boolProps, true)) {
            return [];
        }

        $t = strtolower($controlType . ' ' . $controlName);
        $isChoice = str_contains($t, 'checkbox') || str_contains($t, 'toggle') || str_contains($t, 'radio') || str_contains($t, 'switch');
        if (!$isChoice && !in_array($propLower, ['visible', 'checked', 'default'], true)) {
            return [];
        }

        $trim = strtolower(trim($body));
        if (in_array($trim, ['1', '0', '"true"', '"false"', "'true'", "'false'"], true)) {
            return [
                $this->makeFinding(
                    'app-WarnBooleanExpected',
                    'Medium',
                    [],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    $trim,
                    0,
                    strlen($body)
                ),
            ];
        }

        if (preg_match('/^if\s*\(.*,\s*[01]\s*,\s*[01]\s*\)$/is', $trim)) {
            return [
                $this->makeFinding(
                    'app-WarnBooleanExpected',
                    'Medium',
                    [],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    'If',
                    0,
                    2
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, true> $localNames
     * @return list<Finding>
     */
    private function checkIdentifiers(
        string $segment,
        string $screen,
        string $location,
        string $controlType,
        string $property,
        string $controlName,
        array $localNames,
        string $fullBody,
        int $baseOffset = 0,
    ): array {
        $findings = [];
        $errorBindings = [];
        $masked = $this->maskProtected($segment);

        // varCurrentPackage.Field references
        if (preg_match_all('/\bvarCurrentPackage\.([A-Za-z_][\w]*)/', $segment, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => $fieldMatch) {
                $field = $fieldMatch[0];
                if ($this->dataContext->isPackageField($field)) {
                    continue;
                }
                $dotPos = (int) $m[0][$i][1];
                $findings[] = $this->makeFinding(
                    'app-ErrInvalidName',
                    'High',
                    [$field],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    '.' . $field,
                    $dotPos + strlen('varCurrentPackage') + $baseOffset,
                    strlen($field) + 1
                );
                $errorBindings['varCurrentPackage.' . $field] = true;
            }
        }

        // Member access chains: Identifier(.Identifier)*(.Property)
        if (preg_match_all(
            "/(?:'(?:[^']|'')+'|[A-Za-z_][\w]*)(?:\.(?:'(?:[^']|'')+'|[A-Za-z_][\w]*))+/",
            $masked,
            $chains,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($chains[0] as $chainMatch) {
                $chain = $chainMatch[0];
                $chainStart = (int) $chainMatch[1];
                if ($chainStart > 0 && in_array($segment[$chainStart - 1], [')', ']', '}'], true)) {
                    continue;
                }
                $parts = $this->splitMemberChain($chain);
                if ($parts === []) {
                    continue;
                }
                $root = $parts[0];
                $rootValid = $this->isValidRootReference($fullBody, $root, $screen, $controlName, $localNames);
                if ($rootValid) {
                    continue;
                }
                $rootOffset = $chainStart + $baseOffset;
                if (!isset($errorBindings[$root])) {
                    $findings[] = $this->makeFinding(
                        'app-ErrInvalidName',
                        'High',
                        [$this->unquote($root)],
                        $location,
                        $screen,
                        $controlType,
                        $property,
                        $this->unquote($root),
                        $rootOffset,
                        strlen($root)
                    );
                    $errorBindings[$root] = true;
                }
                // Cascade: .Property on error values
                $cursor = $rootOffset + strlen($root);
                for ($i = 1; $i < count($parts); $i++) {
                    $member = $parts[$i];
                    $propName = $this->unquote($member);
                    if (in_array($propName, ['Checked', 'Text', 'SelectedDate', 'Selected', 'Value', 'HtmlText', 'Email', 'DisplayName'], true)
                        || str_ends_with($propName, 'Checked')
                        || str_ends_with($propName, 'Text')
                    ) {
                        $findings[] = $this->makeFinding(
                            'app-ErrInvalidDot',
                            'High',
                            ['Error'],
                            $location,
                            $screen,
                            $controlType,
                            $property,
                            '.' . $propName,
                            $cursor,
                            strlen($member) + 1
                        );
                    }
                    $cursor += strlen($member) + 1;
                }
            }
        }

        // Bare identifiers — only flag likely control references (Studio is conservative here).
        foreach ($this->bareIdentifiers($segment) as $idInfo) {
            $id = $idInfo['name'];
            $offset = $idInfo['offset'];
            // With({ r: LookUp(...) }, r.Field) / { v: ThisRecord.Value }
            if (FormulaRefContext::isScopedBinding($fullBody, $id)) {
                continue;
            }
            if (strlen($id) <= 1) {
                continue;
            }
            if ($id === $controlName || $id === '_' || preg_match('/^_\d+$/', $id)) {
                continue;
            }
            if (isset($localNames[$id])) {
                continue;
            }
            if ($this->catalog->isReserved($id)) {
                continue;
            }
            if ($this->dataContext->isEnumOrBuiltin($id)) {
                continue;
            }
            if ($this->dataContext->isDataSource($id)) {
                continue;
            }
            if (preg_match('/^(var|col|gbl)[A-Z]/', $id)) {
                continue;
            }
            if ($this->catalog->hasOnScreen($screen, $id)) {
                continue;
            }
            if ($this->catalog->resolveIdentifier($screen, $id) !== null) {
                continue;
            }
            // Record field name: { FieldName: or , FieldName:
            if ($this->isRecordFieldName($fullBody, $offset + $baseOffset, $id)) {
                continue;
            }
            // Enum member access: EnumType.Member — EnumType is valid
            if ($this->isEnumMemberAccess($fullBody, $offset + $baseOffset, $id)) {
                continue;
            }
            // SharePoint / record field names (request_user, time_submitted, …)
            if (str_contains($id, '_') && !preg_match('/^_[0-9]+$/', $id) && !preg_match('/_[0-9]+$/', $id)) {
                continue;
            }
            if (str_starts_with($id, '@')) {
                continue;
            }

            $others = $this->catalog->screensWith($id);
            $isBad = false;
            if ($others !== [] && !in_array($screen, $others, true)) {
                if ($this->catalog->isComponentInstance($id)) {
                    continue;
                }
                $isBad = true; // bare cross-screen ref
            } elseif (preg_match('/_\d+$/', $id)) {
                $isBad = true; // stale suffixed control copy
            } elseif (str_ends_with($id, '-') || str_contains($id, 'Initiave')) {
                $isBad = true; // known typo patterns in this app class
            }
            if (!$isBad) {
                continue;
            }
            if (isset($errorBindings[$id])) {
                continue;
            }
            $findings[] = $this->makeFinding(
                'app-ErrInvalidName',
                'High',
                [$id],
                $location,
                $screen,
                $controlType,
                $property,
                $id,
                $offset + $baseOffset,
                strlen($id)
            );
            $errorBindings[$id] = true;

            // If followed by .Property in formula, add InvalidDot
            $after = substr($segment, $offset + strlen($id));
            if (preg_match('/^\.([A-Za-z_][\w]*)/', $after, $pm, PREG_OFFSET_CAPTURE)) {
                $prop = $pm[1][0];
                $findings[] = $this->makeFinding(
                    'app-ErrInvalidDot',
                    'High',
                    ['Error'],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    '.' . $prop,
                    $offset + strlen($id) + $baseOffset,
                    strlen($prop) + 1
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkDelegation(string $body, string $location, string $screen, string $controlType, string $property): array
    {
        $eventProps = ['onselect', 'onchange', 'onvisible', 'oncheck', 'onuncheck', 'items', 'default'];
        if (!in_array(strtolower($property), $eventProps, true)) {
            return [];
        }

        $predicates = DelegationPredicateExtractor::extract('=' . $body, $this->dataContext);
        if ($predicates === []) {
            return [];
        }

        $findings = [];
        foreach (array_keys(self::DELEGATION_FUNCS) as $fn) {
            if (!preg_match_all('/\b' . preg_quote($fn, '/') . '\s*\(/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $m) {
                $offset = (int) $m[1];
                if (!DelegationPredicateExtractor::offsetInSpan($offset, $predicates)) {
                    continue;
                }
                $rule = 'app-SuggestRemoteExecutionHint';
                if ($fn === 'CountIf' && preg_match('/\bCountIf\s*\(\s*\'[^\']+\'/i', $body)) {
                    $rule = 'app-SuggestRemoteExecutionHint-OpNotSupportedByService';
                }
                if ($fn === 'Find' && preg_match('/\bFind\s*\([^,]+,\s*[A-Za-z_]/', $body)) {
                    $rule = 'app-SuggestRemoteExecutionHint-StringMatchSecondParam';
                }
                $findings[] = $this->makeFinding(
                    $rule,
                    'Medium',
                    [$fn],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    $fn,
                    $offset,
                    strlen($fn)
                );
            }
        }

        foreach ($predicates as $pred) {
            $predicate = substr($body, $pred['start'], $pred['end'] - $pred['start']);
            if (!preg_match_all('/(?<![\w"\'])\b([A-Za-z_][\w]*)\s+in\s+([A-Za-z_][\w.]*)/i', $predicate, $inMatches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($inMatches[0] as $i => $m) {
                $lhs = $inMatches[1][$i][0];
                if (in_array(strtolower($lhs), ['true', 'false'], true)) {
                    continue;
                }
                $offset = $pred['start'] + (int) $m[1];
                $findings[] = $this->makeFinding(
                    'app-SuggestRemoteExecutionHint-InOpRhs',
                    'Medium',
                    ['in'],
                    $location,
                    $screen,
                    $controlType,
                    $property,
                    'in',
                    $offset,
                    2
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<string|int> $args
     * @return Finding
     */
    private function makeFinding(
        string $ruleId,
        string $level,
        array $args,
        string $location,
        string $screen,
        string $controlType,
        string $property,
        string $snippet,
        int $charOffset,
        int $charLength,
    ): array {
        return [
            'ruleId' => $ruleId,
            'level' => $level,
            'messageArgs' => $args,
            'location' => $location,
            'screen' => $screen,
            'controlType' => $controlType,
            'property' => $property,
            'snippet' => $snippet,
            'charOffset' => $charOffset,
            'charLength' => $charLength,
        ];
    }

    /**
     * @param array<string, true> $localNames
     */
    private function isValidRootReference(string $body, string $root, string $screen, string $controlName, array $localNames): bool
    {
        $name = $this->unquote($root);
        if (FormulaRefContext::isScopedBinding($body, $name)) {
            return true;
        }
        if ($name === $screen || $this->catalog->hasOnScreen($screen, $name)) {
            return true;
        }
        if (isset($localNames[$name])) {
            return true;
        }
        if ($this->catalog->isReserved($name)) {
            return true;
        }
        if ($this->dataContext->isEnumOrBuiltin($name) || $this->dataContext->isEnumType($name)) {
            return true;
        }
        if ($this->dataContext->isDataSource($name)) {
            return true;
        }
        if (preg_match('/^(var|col|gbl)[A-Z]/', $name)) {
            return true;
        }
        if ($this->catalog->resolveIdentifier($screen, $name) !== null) {
            return true;
        }
        if ($this->catalog->isComponentInstance($name)) {
            return true;
        }
        $others = $this->catalog->screensWith($name);
        return $others !== [] && in_array($screen, $others, true);
    }

    /**
     * @return list<string>
     */
    private function splitMemberChain(string $chain): array
    {
        $parts = [];
        $buf = '';
        $inQuote = false;
        $len = strlen($chain);
        for ($i = 0; $i < $len; $i++) {
            $ch = $chain[$i];
            if ($ch === "'" && !$inQuote) {
                $inQuote = true;
                $buf .= $ch;
                continue;
            }
            if ($inQuote) {
                $buf .= $ch;
                if ($ch === "'" && ($chain[$i + 1] ?? '') === "'") {
                    $buf .= $chain[++$i];
                    continue;
                }
                if ($ch === "'") {
                    $inQuote = false;
                }
                continue;
            }
            if ($ch === '.') {
                if ($buf !== '') {
                    $parts[] = $buf;
                    $buf = '';
                }
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $parts[] = $buf;
        }
        return $parts;
    }

  private function unquote(string $name): string
    {
        if (str_starts_with($name, "'") && str_ends_with($name, "'")) {
            return str_replace("''", "'", substr($name, 1, -1));
        }
        return $name;
    }

    /**
     * @return list<array{name:string,offset:int}>
     */
    private function bareIdentifiers(string $body): array
    {
        $masked = $this->maskProtected($body);
        $out = [];
        if (preg_match_all('/(?<![\w.])([A-Za-z_][\w]*)/', $masked, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $name = $match[0];
                $offset = (int) $match[1];
                // Skip if part of member chain after dot (handled separately)
                if ($offset > 0 && $body[$offset - 1] === '.') {
                    continue;
                }
                // Skip function names before (
                $after = substr($body, $offset + strlen($name));
                if (preg_match('/^\s*\(/', $after)) {
                    continue;
                }
                $out[] = ['name' => $name, 'offset' => $offset];
            }
        }
        return $out;
    }

    private function isRecordFieldName(string $body, int $offset, string $id): bool
    {
        $before = substr($body, 0, $offset);
        if (preg_match('/[{,]\s*$/', $before)) {
            $after = substr($body, $offset + strlen($id));
            return preg_match('/^\s*:/', $after) === 1;
        }
        return false;
    }

    private function isEnumMemberAccess(string $body, int $offset, string $id): bool
    {
        if (!$this->dataContext->isEnumType($id)) {
            return false;
        }
        $after = substr($body, $offset + strlen($id));
        return preg_match('/^\s*\./', $after) === 1;
    }

    private function maskProtected(string $s): string
    {
        $parts = [];
        $len = strlen($s);
        $i = 0;
        $buf = '';
        $flush = static function (string $type, string &$buf) use (&$parts): void {
            if ($buf !== '') {
                $parts[] = [$type, $buf];
                $buf = '';
            }
        };
        while ($i < $len) {
            if ($s[$i] === '/' && ($s[$i + 1] ?? '') === '/') {
                $flush('code', $buf);
                $j = $i;
                while ($j < $len && $s[$j] !== "\n") {
                    $j++;
                }
                $parts[] = ['comment', substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            if ($s[$i] === '"') {
                $flush('code', $buf);
                $j = $i + 1;
                while ($j < $len) {
                    if ($s[$j] === '"') {
                        if (($s[$j + 1] ?? '') === '"') {
                            $j += 2;
                            continue;
                        }
                        $j++;
                        break;
                    }
                    $j++;
                }
                $parts[] = ['string', substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            if ($s[$i] === "'") {
                $flush('code', $buf);
                $j = $i + 1;
                while ($j < $len) {
                    if ($s[$j] === "'") {
                        if (($s[$j + 1] ?? '') === "'") {
                            $j += 2;
                            continue;
                        }
                        $j++;
                        break;
                    }
                    $j++;
                }
                $parts[] = ['string', substr($s, $i, $j - $i)];
                $i = $j;
                continue;
            }
            $buf .= $s[$i];
            $i++;
        }
        $flush('code', $buf);
        $out = '';
        foreach ($parts as [$type, $text]) {
            $out .= $type === 'code' ? $text : str_repeat(' ', strlen($text));
        }
        return $out;
    }
}
