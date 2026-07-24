<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;

/**
 * Repair checkbox/toggle Checked (and similar) values that App checker flags as
 * "Expecting a true or false value" — e.g. 1/0 or "true"/"false" after bad edits.
 *
 * Run after unwhack_locale_formulas so locale-broken If(...) formulas are fixed first.
 */
final class RepairCheckedBooleansHop implements HopInterface
{
    /** @var list<string> */
    private const PROPS = [
        'Checked',
        'Default',
        'Value',
        'Reset',
    ];

    public static function id(): string
    {
        return 'repair_checked_booleans';
    }

    public static function label(): string
    {
        return 'Repair checked booleans';
    }

    public static function description(): string
    {
        return 'Normalize checkbox/toggle Checked/Default values to true/false when they are 1/0 or quoted strings (fixes "Expecting a true or false value").';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $t = strtolower($control->type . ' ' . $control->name);
                $isChoice = str_contains($t, 'checkbox')
                    || str_contains($t, 'toggle')
                    || str_contains($t, 'radio')
                    || str_contains($t, 'switch');
                if (!$isChoice) {
                    continue;
                }

                foreach (self::PROPS as $prop) {
                    // Default/Value/Reset only on checkbox/toggle; skip radio Items noise
                    if ($prop !== 'Checked' && (str_contains($t, 'radio'))) {
                        continue;
                    }
                    $from = $control->getProperty($prop);
                    if ($from === null) {
                        continue;
                    }
                    $fixed = $this->toBoolLiteral($from, $control->format === 'yaml');
                    if ($fixed === null || $fixed === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $fixed);
                    $report->add(self::id(), $control->path, $prop, $from, $fixed);
                }
            }
        }
    }

    private function toBoolLiteral(string $value, bool $yamlEquals): ?string
    {
        $v = trim($value);
        $body = ltrim($v, '=');
        $body = trim($body);
        $lower = strtolower($body);

        $map = [
            '1' => 'true',
            '0' => 'false',
            '"true"' => 'true',
            '"false"' => 'false',
            "'true'" => 'true',
            "'false'" => 'false',
            'true' => null,  // already fine
            'false' => null,
        ];

        if (!array_key_exists($lower, $map)) {
            return null;
        }
        if ($map[$lower] === null) {
            return null;
        }

        $bool = $map[$lower];
        return $yamlEquals ? '=' . $bool : $bool;
    }
}
