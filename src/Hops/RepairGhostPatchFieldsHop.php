<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Remove Patch record fields that reference controls which do not exist anywhere
 * in the app (legacy copy-paste from duplicated screens).
 */
final class RepairGhostPatchFieldsHop implements HopInterface
{
    /** @var list<string> */
    private const GHOST_CONTROLS = [
        'OneTimeVisit',
        'RecurringVisit',
        'EmergencyVisit',
        'AmendmentVisit',
        'GovernmentInitiave',
        'CommercialInitiave',
        'InitiatedByRequestingAgency',
        'ByInvitationOfFacility',
        'PertinentToEquipment',
        'PertinentToSales',
        'PertinentToProgramme',
        'PertinentToDefense',
        'PertinentToDefence',
        'PertinentToOther',
        'LevelUnclassified',
        'LevelRestricted',
        'LevelConfidential',
        'LevelSecret',
        'LevelTopSecret',
        'LevelOther',
        'LevelSpecify',
        'Subject',
        'EmerContactPostal',
        'EmerContactTelArea',
        'EmerContactTel',
        'EmerContactDayArea',
        'EmerContactDayTel',
        'EmerContactSignature',
        'EmerContactSignerName',
        'PertinenceSpecification',
        'PertinenceSpecification-',
    ];

    public static function id(): string
    {
        return 'repair_ghost_patch_fields';
    }

    public static function label(): string
    {
        return 'Repair ghost Patch record fields';
    }

    public static function description(): string
    {
        return 'Comment out Patch/record fields that reference controls removed from duplicated screens (OneTimeVisit, level checkboxes, etc.).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);
        $ghosts = [];
        foreach (self::GHOST_CONTROLS as $name) {
            if ($catalog->screensWith($name) === []) {
                $ghosts[$name] = true;
            }
        }
        if ($ghosts === []) {
            return;
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($ghosts, $report): string {
                $parts = PowerFxFormulaSegments::split($formula);
                $changed = false;
                $out = PowerFxFormulaSegments::mapCode($parts, static function (string $code) use ($ghosts, $report, $path, &$changed): string {
                    $new = $code;
                    foreach (array_keys($ghosts) as $ghost) {
                        $patterns = [
                            '/^[ \t]*' . preg_quote($ghost, '/') . '\s*:\s*' . preg_quote($ghost, '/') . '\.\w+\s*,?\s*\r?$/m',
                            '/^[ \t]*' . preg_quote($ghost, '/') . '\s*:\s*' . preg_quote($ghost, '/') . '\s*,?\s*\r?$/m',
                        ];
                        foreach ($patterns as $pattern) {
                            $replaced = preg_replace($pattern, '// $0', $new);
                            if ($replaced !== null && $replaced !== $new) {
                                $report->add(self::id(), $path, $ghost, '(ghost control)', '(commented)');
                                $new = $replaced;
                                $changed = true;
                            }
                        }
                        foreach (['Checked', 'Text', 'HtmlText', 'SelectedDate', 'Value'] as $prop) {
                            $bare = '/(?<![\w.])' . preg_quote($ghost, '/') . '\.' . $prop . '(?!\w)/';
                            $replaced = preg_replace($bare, 'false', $new);
                            if ($replaced !== null && $replaced !== $new) {
                                $report->add(self::id(), $path, $ghost . '.' . $prop, '(ghost)', 'false');
                                $new = $replaced;
                                $changed = true;
                            }
                        }
                    }

                    return $new;
                });

                return $changed ? $out : $formula;
            });
        }
    }
}
