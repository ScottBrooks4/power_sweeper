<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\Report;

/**
 * Collapse accidental double screen qualification introduced when cross-screen
 * refs are repaired more than once: 'Screen'.'Screen'.Control -> 'Screen'.Control
 */
final class RepairDoubleQualifiedRefsHop implements HopInterface
{
    public static function id(): string
    {
        return 'repair_double_qualified_refs';
    }

    public static function label(): string
    {
        return 'Repair double-qualified screen refs';
    }

    public static function description(): string
    {
        return "Collapse 'Screen'.'Screen'.Control chains back to a single screen qualifier.";
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);
        $screens = [];
        foreach ($documents as $doc) {
            $screen = $catalog->screenForDocument($doc);
            if ($screen !== null && $screen !== '') {
                $screens[$screen] = true;
            }
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($screens, $report): string {
                $new = self::collapse($formula, array_keys($screens));
                if ($new !== $formula) {
                    $report->add(self::id(), $path, 'formula', '(double-qualified)', '(collapsed)');
                }
                return $new;
            });
        }
    }

    /**
     * @param list<string> $screens
     */
    private static function collapse(string $formula, array $screens): string
    {
        $new = $formula;
        foreach ($screens as $screen) {
            if (!str_contains($new, $screen)) {
                continue;
            }
            $quoted = "'" . str_replace("'", "''", $screen) . "'";
            $bad = $quoted . '.' . $quoted . '.';
            while (str_contains($new, $bad)) {
                $new = str_replace($bad, $quoted . '.', $new);
            }
            // Default-value corruption: 'Screen'.'Screen' without trailing control
            $dupOnly = $quoted . '.' . $quoted;
            while (str_contains($new, $dupOnly)) {
                $new = str_replace($dupOnly, $quoted, $new);
            }
            // Unquoted screen names (rare): Screen.Screen.Control
            if (preg_match('/^[A-Za-z_][\w]*$/', $screen)) {
                $badBare = $screen . '.' . $screen . '.';
                while (str_contains($new, $badBare)) {
                    $new = str_replace($badBare, $screen . '.', $new);
                }
            }
        }
        return $new;
    }
}
