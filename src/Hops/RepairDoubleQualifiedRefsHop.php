<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\Report;
use PowerSweeper\ScreenReferenceNormalizer;

/**
 * Idempotent normalization of canvas screen references (Navigate, StartScreen,
 * cross-screen member chains, merge artifacts). Delegates to ScreenReferenceNormalizer.
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
        return 'Normalize screen references to canonical form (single quoted screen, no Screen.Screen chains). Safe to run multiple times.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);
        $screens = $catalog->screenNames();

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($screens, $report): string {
                $new = ScreenReferenceNormalizer::normalize($formula, $screens);
                if ($new !== $formula) {
                    $report->add(self::id(), $path, 'formula', '(screen ref)', '(normalized)');
                }

                return $new;
            });
        }
    }
}
