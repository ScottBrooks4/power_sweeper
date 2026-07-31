<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Shared helpers for deciding whether a formula reference is bare, qualified,
 * or a non-control binding (With records, varCurrentPackage fields, etc.).
 */
final class FormulaRefContext
{
    public static function isRecordVariable(string $formula, string $name): bool
    {
        return preg_match('/\b' . preg_quote($name, '/') . '\s*:/', $formula) === 1;
    }

    /**
     * ForAll(collection As rec, …) and similar loop bindings.
     */
    public static function isLoopVariable(string $formula, string $name): bool
    {
        return preg_match('/\bAs\s+' . preg_quote($name, '/') . '\b/', $formula) === 1;
    }

    public static function isScopedBinding(string $formula, string $name): bool
    {
        return self::isRecordVariable($formula, $name) || self::isLoopVariable($formula, $name);
    }

    public static function isPackageFieldRef(string $formula, string $field): bool
    {
        return preg_match('/\bvarCurrentPackage\.' . preg_quote($field, '/') . '\b/', $formula) === 1;
    }

    public static function isQualifiedOnScreen(string $formula, string $id, string $otherScreen, AppControlCatalog $catalog): bool
    {
        $q = $catalog->quoteScreen($otherScreen);
        if (str_contains($formula, $q . '.' . $id)) {
            return true;
        }
        if (preg_match('/^[A-Za-z_][\w]*$/', $id)) {
            return str_contains($formula, $q . '.' . $id);
        }
        return str_contains($formula, $q . ".'" . str_replace("'", "''", $id) . "'");
    }

    /**
     * True when $id names a control on another screen and appears unqualified in $formula.
     */
    public static function hasBareCrossScreenControlRef(
        string $formula,
        string $id,
        string $screen,
        AppControlCatalog $catalog,
    ): bool {
        if ($catalog->hasOnScreen($screen, $id)) {
            return false;
        }
        if ($catalog->isReserved($id)) {
            return false;
        }
        if (preg_match('/^(var|col|gbl)/', $id)) {
            return false;
        }
        if (self::isRecordVariable($formula, $id)) {
            return false;
        }
        if (self::isPackageFieldRef($formula, $id)) {
            return false;
        }
        if ($catalog->isScreenName($id)) {
            $q = $catalog->quoteScreen($id);
            if (str_contains($formula, $q)) {
                return false;
            }
        }

        $others = array_values(array_filter(
            $catalog->screensWith($id),
            static fn(string $s): bool => $s !== $screen
        ));
        if ($others === []) {
            return false;
        }

        // Global component host on a hidden screen (comTranslations, comExternalFunctions, …).
        if ($catalog->isComponentInstance($id)) {
            return false;
        }

        foreach ($others as $other) {
            if (self::isQualifiedOnScreen($formula, $id, $other, $catalog)) {
                return false;
            }
            // Screen name used as a qualifier: 'Other Screen'.Foo
            if ($id === $other && str_contains($formula, $catalog->quoteScreen($other) . '.')) {
                return false;
            }
        }

        return preg_match('/(?<![\w.])' . preg_quote($id, '/') . '(?![\w])/', $formula) === 1;
    }
}
