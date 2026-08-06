<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Live-checker-driven repair loop: re-scan after each fix pass and stop when
 * formula errors stop decreasing (or max rounds reached).
 */
final class FormulaRepairConverger
{
    /** @var list<string> */
    private const LOCALE_RULES = [
        'app-ErrOperatorExpected',
        'app-ErrBadArity',
        'app-ErrBadArityMinimum',
        'app-ErrInvalidArgs-Func',
    ];

    /** @var list<string> */
    private const REF_RULES = [
        'app-ErrInvalidName',
        'app-ErrInvalidDot',
        'app-formula-mangled-screen-ref',
    ];

    /**
     * @param list<ControlDocument> $documents
     * @return array{rounds:int,before:int,after:int,repairs:int}
     */
    public function converge(array $documents, array $options = []): array
    {
        $extractDir = is_string($options['extract_dir'] ?? null) ? $options['extract_dir'] : null;
        $maxRounds = max(1, (int) ($options['max_rounds'] ?? 3));
        $dataContext = AppDataContext::build($documents, $extractDir);

        $beforeCheck = StudioLiveChecker::check($documents, ['extract_dir' => $extractDir]);
        $beforeCount = self::formulaErrorCount($beforeCheck);
        if ($beforeCount === 0) {
            return ['rounds' => 0, 'before' => 0, 'after' => 0, 'repairs' => 0];
        }

        $totalRepairs = 0;

        for ($round = 0; $round < $maxRounds; $round++) {
            $check = StudioLiveChecker::check($documents, ['extract_dir' => $extractDir]);
            $errorCount = self::formulaErrorCount($check);
            if ($errorCount === 0) {
                break;
            }

            $roundRepairs = 0;
            $roundRepairs += $this->repairReferences($documents, $dataContext, $options);
            $roundRepairs += $this->repairLocale($documents, $dataContext, $extractDir, $check['findings']);
            $roundRepairs += $this->repairBooleans($documents, $dataContext, $extractDir, $check['findings']);
            $roundRepairs += $this->repairArityNoise($documents, $dataContext, $check['findings']);

            $totalRepairs += $roundRepairs;
            if ($roundRepairs === 0) {
                break;
            }
        }

        $afterCheck = StudioLiveChecker::check($documents, ['extract_dir' => $extractDir]);

        return [
            'rounds' => min($maxRounds, $round + 1),
            'before' => $beforeCount,
            'after' => self::formulaErrorCount($afterCheck),
            'repairs' => $totalRepairs,
        ];
    }

    /**
     * @param list<ControlDocument> $documents
     * @param array<string, mixed> $options
     */
    private function repairReferences(array $documents, AppDataContext $dataContext, array $options): int
    {
        $engine = new IterativeFormulaRepairer($dataContext);
        $stats = $engine->repair($documents, [
            'max_iterations' => (int) ($options['ref_iterations'] ?? 2),
        ]);

        return $stats['repairs'];
    }

    /**
     * @param list<ControlDocument> $documents
     * @param list<array<string,mixed>> $findings
     */
    private function repairLocale(
        array $documents,
        AppDataContext $dataContext,
        ?string $extractDir,
        array $findings,
    ): int {
        $needsLocale = false;
        foreach ($findings as $finding) {
            if (in_array($finding['ruleId'] ?? '', self::LOCALE_RULES, true)) {
                $needsLocale = true;
                break;
            }
        }
        if (!$needsLocale) {
            return 0;
        }

        $catalog = AppControlCatalog::build($documents);
        $checker = new PowerFxFormulaChecker($catalog, $dataContext);
        $repairs = 0;

        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc);
            if ($screen === null) {
                continue;
            }

            $localNames = [];
            foreach ($doc->controls() as $control) {
                $localNames[(string) $control->name] = true;
            }
            $screenRecordVars = FormulaRefContext::recordVariableNames(
                self::collectDocFormulas($doc)
            );

            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }
                    if (!FormulaLocaleNormalizer::looksLocaleCorrupted($value)) {
                        continue;
                    }

                    $location = $screen . '.' . $control->name . '.' . $prop;
                    $before = self::countFormulaErrors(
                        $checker,
                        $value,
                        $screen,
                        $location,
                        $control->type,
                        $prop,
                        $control->name,
                        $localNames,
                        $screenRecordVars,
                    );

                    $trial = FormulaLocaleNormalizer::toInvariant($value);
                    if ($trial === $value) {
                        continue;
                    }

                    $after = self::countFormulaErrors(
                        $checker,
                        $trial,
                        $screen,
                        $location,
                        $control->type,
                        $prop,
                        $control->name,
                        $localNames,
                        $screenRecordVars,
                    );

                    if ($after < $before) {
                        $control->setProperty($prop, $trial);
                        $repairs++;
                    }
                }
            }
        }

        return $repairs;
    }

    /**
     * @param list<ControlDocument> $documents
     * @param list<array<string,mixed>> $findings
     */
    private function repairBooleans(
        array $documents,
        AppDataContext $dataContext,
        ?string $extractDir,
        array $findings,
    ): int {
        $hasBooleanFindings = false;
        foreach ($findings as $finding) {
            if (($finding['ruleId'] ?? '') === 'app-WarnBooleanExpected') {
                $hasBooleanFindings = true;
                break;
            }
        }
        if (!$hasBooleanFindings) {
            return 0;
        }

        $catalog = AppControlCatalog::build($documents);
        $checker = new PowerFxFormulaChecker($catalog, $dataContext);
        $repairs = 0;

        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc);
            if ($screen === null) {
                continue;
            }

            $localNames = [];
            foreach ($doc->controls() as $control) {
                $localNames[(string) $control->name] = true;
            }
            $screenRecordVars = FormulaRefContext::recordVariableNames(
                self::collectDocFormulas($doc)
            );

            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }

                    $location = $screen . '.' . $control->name . '.' . $prop;
                    $currentFindings = $checker->check(
                        $value,
                        $screen,
                        $location,
                        $control->type,
                        $prop,
                        $control->name,
                        $localNames,
                        $screenRecordVars,
                    );
                    $hasWarn = false;
                    foreach ($currentFindings as $finding) {
                        if ($finding['ruleId'] === 'app-WarnBooleanExpected') {
                            $hasWarn = true;
                            break;
                        }
                    }
                    if (!$hasWarn) {
                        continue;
                    }

                    $trial = FormulaBooleanNormalizer::tryNormalize($value, $prop);
                    if ($trial === null || $trial === $value) {
                        continue;
                    }

                    $before = self::countFormulaErrors(
                        $checker,
                        $value,
                        $screen,
                        $location,
                        $control->type,
                        $prop,
                        $control->name,
                        $localNames,
                        $screenRecordVars,
                    );
                    $after = self::countFormulaErrors(
                        $checker,
                        $trial,
                        $screen,
                        $location,
                        $control->type,
                        $prop,
                        $control->name,
                        $localNames,
                        $screenRecordVars,
                    );

                    if ($after <= $before) {
                        $control->setProperty($prop, $trial);
                        $repairs++;
                    }
                }
            }
        }

        return $repairs;
    }

    /**
     * Checker-guided arity cleanup (trailing commas in Concatenate/argument lists).
     * Only applies a trial when live findings include ErrBadArity* for that formula.
     *
     * @param list<ControlDocument> $documents
     * @param list<array<string,mixed>> $findings
     */
    private function repairArityNoise(array $documents, AppDataContext $dataContext, array $findings): int
    {
        $arityLocations = [];
        foreach ($findings as $finding) {
            $rule = (string) ($finding['ruleId'] ?? '');
            if (!str_starts_with($rule, 'app-ErrBadArity')) {
                continue;
            }
            $loc = (string) ($finding['location'] ?? $finding['logicalLocation'] ?? '');
            if ($loc !== '') {
                $arityLocations[$loc] = true;
            }
        }
        if ($arityLocations === []) {
            // Still attempt global trailing-comma cleanup when any arity error exists without location.
            $anyArity = false;
            foreach ($findings as $finding) {
                if (str_starts_with((string) ($finding['ruleId'] ?? ''), 'app-ErrBadArity')) {
                    $anyArity = true;
                    break;
                }
            }
            if (!$anyArity) {
                return 0;
            }
        }

        $catalog = AppControlCatalog::build($documents);
        $checker = new PowerFxFormulaChecker($catalog, $dataContext);
        $repairs = 0;

        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc);
            if ($screen === null) {
                continue;
            }
            $localNames = [];
            foreach ($doc->controls() as $control) {
                $localNames[(string) $control->name] = true;
            }
            $screenRecordVars = FormulaRefContext::recordVariableNames(
                self::collectDocFormulas($doc)
            );

            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }
                    if (!preg_match('/,\s*\)/', $value)) {
                        continue;
                    }

                    $location = $screen . '.' . $control->name . '.' . $prop;
                    if ($arityLocations !== [] && !isset($arityLocations[$location])) {
                        // Location keys from SARIF vary; also accept path suffix match.
                        $hit = false;
                        foreach (array_keys($arityLocations) as $loc) {
                            if (str_ends_with($loc, $control->name . '.' . $prop) || str_contains($loc, $control->path)) {
                                $hit = true;
                                break;
                            }
                        }
                        if (!$hit) {
                            continue;
                        }
                    }

                    $trial = preg_replace('/""\s*,\s*\)/', '"")', $value) ?? $value;
                    $trial = preg_replace('/\)\s*,\s*\)/', '))', $trial) ?? $trial;
                    $trial = preg_replace('/,\s*\)/', ')', $trial) ?? $trial;
                    if ($trial === $value) {
                        continue;
                    }

                    $before = self::countFormulaErrors(
                        $checker,
                        $value,
                        $screen,
                        $location,
                        $control->type,
                        $prop,
                        $control->name,
                        $localNames,
                        $screenRecordVars,
                    );
                    $after = self::countFormulaErrors(
                        $checker,
                        $trial,
                        $screen,
                        $location,
                        $control->type,
                        $prop,
                        $control->name,
                        $localNames,
                        $screenRecordVars,
                    );
                    if ($after < $before) {
                        $control->setProperty($prop, $trial);
                        $repairs++;
                    }
                }
            }
        }

        return $repairs;
    }

    /**
     * @param array<string, mixed> $checkResult
     */
    private static function formulaErrorCount(array $checkResult): int
    {
        $count = 0;
        foreach ($checkResult['findings'] as $finding) {
            $rule = (string) ($finding['ruleId'] ?? '');
            if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, true> $localNames
     */
    private static function countFormulaErrors(
        PowerFxFormulaChecker $checker,
        string $formula,
        string $screen,
        string $location,
        string $controlType,
        string $property,
        string $controlName,
        array $localNames,
        array $screenRecordVars,
    ): int {
        $findings = $checker->check(
            $formula,
            $screen,
            $location,
            $controlType,
            $property,
            $controlName,
            $localNames,
            $screenRecordVars,
        );

        $count = 0;
        foreach ($findings as $finding) {
            $rule = $finding['ruleId'];
            if (str_starts_with($rule, 'app-Err') || $rule === 'app-formula-mangled-screen-ref' || $rule === 'app-WarnBooleanExpected') {
                $count++;
            }
        }

        return $count;
    }

    private static function collectDocFormulas(ControlDocument $doc): string
    {
        $parts = [];
        foreach ($doc->controls() as $control) {
            foreach ($control->propertyNames() as $prop) {
                $value = $control->getProperty($prop);
                if ($value !== null && trim($value) !== '') {
                    $parts[] = $value;
                }
            }
        }

        return implode("\n", $parts);
    }
}
