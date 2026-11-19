<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\Report;

/**
 * Repair values App checker flags as "Expecting a true or false value".
 *
 * Covers:
 *   - Checkbox/toggle/radio/switch Checked/Default/Value/Reset literals 1/0/"true"
 *   - If(cond, 1, 0) / If(cond, 0, 1) on those properties (common after locale unwhack)
 *   - Visible / Wrap / AutoHeight / AutoWidth / Underline / Strikethrough / Italic / Bold
 *     with 1/0/"true" on any control
 *
 * Run after unwhack_locale_formulas so locale-broken If(...) formulas are fixed first.
 */
final class RepairCheckedBooleansHop implements HopInterface
{
    /** @var list<string> */
    private const CHOICE_PROPS = [
        'Checked',
        'Default',
        'Value',
        'Reset',
    ];

    /** @var list<string> */
    private const BOOL_CHROME_PROPS = [
        'Visible',
        'Wrap',
        'AutoHeight',
        'AutoWidth',
        'Underline',
        'Strikethrough',
        'Italic',
        'Bold',
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
        return 'Normalize boolean properties (Checked/Default/Visible/…) from 1/0, "true"/"false", or If(cond, 1, 0) to true/false — fixes App checker "Expecting a true or false value".';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                $t = strtolower($control->type . ' ' . $control->name);
                $isCheckboxOrToggle = str_contains($t, 'checkbox')
                    || str_contains($t, 'toggle')
                    || str_contains($t, 'switch');
                $isRadio = str_contains($t, 'radio');

                $props = self::BOOL_CHROME_PROPS;
                if ($isCheckboxOrToggle) {
                    $props = [...self::CHOICE_PROPS, ...self::BOOL_CHROME_PROPS];
                } elseif ($isRadio) {
                    // Radios: Checked only (Default/Value often bind to Items)
                    $props = ['Checked', ...self::BOOL_CHROME_PROPS];
                }

                foreach ($props as $prop) {
                    $from = $control->getProperty($prop);
                    if ($from === null) {
                        continue;
                    }
                    $fixed = $this->toBoolFormula($from, $control->format === 'yaml');
                    if ($fixed === null || $fixed === $from) {
                        continue;
                    }
                    $control->setProperty($prop, $fixed);
                    $report->add(self::id(), $control->path, $prop, $from, $fixed);
                }
            }
        }
    }

    private function toBoolFormula(string $value, bool $yamlEquals): ?string
    {
        $body = trim(ltrim(trim($value), '='));
        $lower = strtolower($body);

        $map = [
            '1' => 'true',
            '0' => 'false',
            '"true"' => 'true',
            '"false"' => 'false',
            "'true'" => 'true',
            "'false'" => 'false',
        ];

        if (array_key_exists($lower, $map)) {
            $bool = $map[$lower];
            return $yamlEquals ? '=' . $bool : $bool;
        }

        if ($lower === 'true' || $lower === 'false') {
            return null;
        }

        $normalized = $this->rewriteIfNumericBool($body);
        if ($normalized === null || $normalized === $body) {
            return null;
        }

        return $yamlEquals ? '=' . $normalized : $normalized;
    }

    /**
     * Rewrite top-level If(..., 1, 0) / If(..., 0, 1) to true/false branches.
     */
    private function rewriteIfNumericBool(string $body): ?string
    {
        $body = trim($body);
        if (!preg_match('/^If\s*\(/i', $body) || !str_ends_with($body, ')')) {
            return null;
        }

        $inner = substr($body, (int) strpos($body, '(') + 1, -1);
        $args = $this->splitTopLevelArgs($inner);
        if (count($args) !== 3) {
            return null;
        }

        $a = strtolower(trim($args[1]));
        $b = strtolower(trim($args[2]));
        if (!(($a === '1' && $b === '0') || ($a === '0' && $b === '1'))) {
            return null;
        }

        $trueBranch = $a === '1' ? 'true' : 'false';
        $falseBranch = $b === '1' ? 'true' : 'false';

        return 'If(' . trim($args[0]) . ', ' . $trueBranch . ', ' . $falseBranch . ')';
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelArgs(string $inner): array
    {
        $args = [];
        $buf = '';
        $depth = 0;
        $inString = false;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($inString) {
                $buf .= $ch;
                if ($ch === '"' && ($inner[$i + 1] ?? '') === '"') {
                    $buf .= $inner[++$i];
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                $buf .= $ch;
                continue;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                $buf .= $ch;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
                $buf .= $ch;
                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $args[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '' || $args !== []) {
            $args[] = $buf;
        }
        return $args;
    }
}
