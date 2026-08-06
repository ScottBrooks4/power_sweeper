<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\AppControlCatalog;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Remove Patch record fields in live code that reference controls which do not exist
 * anywhere in the app (legacy copy-paste from duplicated screens).
 *
 * Ghosts are discovered heuristically from `Field: Field.Prop` record lines whose
 * control is absent from the catalog; a small seed list covers known VCR leftovers.
 * Comment regions are never read or modified.
 */
final class RepairGhostPatchFieldsHop implements HopInterface
{
    /**
     * Seed names commonly left behind after VCR screen duplication.
     * Discovery adds any other `Name: Name[.Prop]` lines whose Name is absent.
     *
     * @var list<string>
     */
    private const GHOST_SEEDS = [
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
        return 'Discover and remove Patch/record fields in live code that reference controls absent from the app (seeds + Field: Field.Prop heuristics). Comments and strings are never modified.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $catalog = AppControlCatalog::build($documents);
        $ghosts = $this->discoverGhosts($documents, $catalog);
        if ($ghosts === []) {
            return;
        }

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($ghosts, $report): string {
                $changed = false;
                $out = PowerFxFormulaSegments::transformCode($formula, static function (string $code) use ($ghosts, $report, $path, &$changed): string {
                    $new = $code;
                    foreach (array_keys($ghosts) as $ghost) {
                        $linePatterns = [
                            '/^[ \t]*' . preg_quote($ghost, '/') . '\s*:\s*' . preg_quote($ghost, '/') . '\.\w+\s*,?[ \t]*\r?\n/m',
                            '/^[ \t]*' . preg_quote($ghost, '/') . '\s*:\s*' . preg_quote($ghost, '/') . '\s*,?[ \t]*\r?\n/m',
                            '/^[ \t]*' . preg_quote($ghost, '/') . '\s*:\s*' . preg_quote($ghost, '/') . '\.\w+\s*,?[ \t]*(?=\})/m',
                            '/^[ \t]*' . preg_quote($ghost, '/') . '\s*:\s*' . preg_quote($ghost, '/') . '\s*,?[ \t]*(?=\})/m',
                        ];
                        foreach ($linePatterns as $pattern) {
                            $replaced = preg_replace($pattern, '', $new);
                            if ($replaced !== null && $replaced !== $new) {
                                $report->add(self::id(), $path, $ghost, '(ghost control line)', '(removed)');
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

    /**
     * @param list<\PowerSweeper\ControlDocument> $documents
     * @return array<string, true>
     */
    private function discoverGhosts(array $documents, AppControlCatalog $catalog): array
    {
        $candidates = [];
        foreach (self::GHOST_SEEDS as $name) {
            $candidates[$name] = true;
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                foreach ($control->propertyNames() as $prop) {
                    $value = $control->getProperty($prop);
                    if ($value === null || trim($value) === '') {
                        continue;
                    }
                    PowerFxFormulaSegments::transformCode($value, static function (string $code) use (&$candidates): string {
                        if (preg_match_all(
                            '/^[ \t]*([A-Za-z_][\w\-]*)\s*:\s*\1(?:\.\w+)?\s*,?\s*$/m',
                            $code,
                            $m,
                        )) {
                            foreach ($m[1] as $name) {
                                $candidates[$name] = true;
                            }
                        }

                        return $code;
                    });
                }
            }
        }

        $ghosts = [];
        foreach (array_keys($candidates) as $name) {
            if ($catalog->isReserved($name)) {
                continue;
            }
            if ($catalog->isScreenName($name)) {
                continue;
            }
            if ($catalog->screensWith($name) !== []) {
                continue;
            }
            $ghosts[$name] = true;
        }

        return $ghosts;
    }
}
