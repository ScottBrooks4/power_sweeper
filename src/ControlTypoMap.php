<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Shared identifier typo seeds used by control-ref and candidate generators.
 * Kept small — live catalog / token-stem heuristics cover novel misspellings.
 */
final class ControlTypoMap
{
    /** @var array<string, string> */
    public const MAP = [
        'GovernmentInitiave' => 'GovernmentInitiative',
        'CommercialInitiave' => 'CommercialInitiative',
        'PertinenceSpecification-' => 'PertinenceSpecification',
        '8_Pertinence-' => '8_Pertinence',
        'LeveLTopSecret' => 'LevelTopSecret',
        'Restricted0' => 'UnclassifiedRestricted',
    ];

    public static function fix(string $identifier): ?string
    {
        return self::MAP[$identifier] ?? null;
    }
}
