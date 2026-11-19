<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Canonical nav-tab ↔ section ↔ SetFocus anchor mapping for scrollable forms.
 *
 * Power Apps often parks 1×1 SetFocus buttons mid-section (and sometimes in the
 * wrong section). Web export should expose real top-of-section HTML anchors; the
 * reverse / apply_section_anchors sweep should place SetFocus targets at
 * `{ScrollParent}.Height / 2` inside the correct section.
 */
final class SectionAnchors
{
    /**
     * AnchorName => section name patterns (first match wins).
     *
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        // Prefer numbered / annex section containers — not leaf controls that share
        // a short name (e.g. Destination dropdown vs 1_Destination section).
        return [
            'Anchor1' => ['1_Destination'],
            'Anchor2' => ['2_Requesting', '2_Organization', '2_Org'],
            'Anchor3' => ['3_TypeOfVCR', '3_Visit'],
            'Anchor4' => ['4_SecurityClassification', '4_Class'],
            'Anchor5' => ['5_Summary', '5_Summary_Contents'],
            'Anchor6' => ['6_Date', '6_Dates'],
            'Anchor7' => ['7_Initiative'],
            'Anchor8' => ['8_Pertinence'],
            'Anchor9' => ['9_SecurityOfficer'],
            'Anchor10' => ['10_Remarks'],
            'AnchorSites' => ['Annex1_Sites'],
            'AnchorVisitors' => ['Annex2_Visitors'],
            'AnchorEmer' => ['EmergencyContact', 'EmerScrollableForm'],
        ];
    }

    public static function isSectionAnchorName(string $name): bool
    {
        return isset(self::map()[$name])
            || preg_match('/^Anchor(\d+|Sites|Visitors|Emer|Park)$/i', $name) === 1;
    }

    public static function isParkName(string $name): bool
    {
        return strcasecmp($name, 'AnchorPark') === 0
            || strcasecmp($name, 'AnchorParkContainer') === 0;
    }

    /**
     * Which Anchor* belongs at the top of this section control name.
     */
    public static function forSectionName(string $sectionName): ?string
    {
        $sectionName = trim($sectionName);
        if ($sectionName === '') {
            return null;
        }
        foreach (self::map() as $anchor => $patterns) {
            foreach ($patterns as $pattern) {
                if (strcasecmp($sectionName, $pattern) === 0) {
                    return $anchor;
                }
            }
        }
        // Numbered form *sections* only (not 2_Checkboxes, 9_Telephone, headers, …).
        if (preg_match(
            '/^(\d+)_(Destination|Requesting|TypeOfVCR|SecurityClassification|Summary|Summary_Contents|Date|Dates|Initiative|Pertinence|SecurityOfficer|Remarks)\b/i',
            $sectionName,
            $m
        ) === 1) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 10) {
                return 'Anchor' . $n;
            }
        }
        // Annex hosts only — avoid SitesContainer / galSites leaf chrome.
        if (preg_match('/^Annex\s*1|_Sites$/i', $sectionName) === 1 && !preg_match('/visitor/i', $sectionName)) {
            return 'AnchorSites';
        }
        if (preg_match('/^Annex\s*2|_Visitors$/i', $sectionName) === 1) {
            return 'AnchorVisitors';
        }
        if (preg_match('/^EmergencyContact$|^EmerScrollableForm$/i', $sectionName) === 1) {
            return 'AnchorEmer';
        }

        return null;
    }

    /**
     * Preferred section container name for an anchor (primary pattern).
     */
    public static function primarySection(string $anchorName): ?string
    {
        $patterns = self::map()[$anchorName] ?? null;
        if ($patterns === null || $patterns === []) {
            if (preg_match('/^Anchor(\d+)$/i', $anchorName, $m) === 1) {
                return null;
            }

            return null;
        }

        return $patterns[0];
    }

    /**
     * @return list<string>
     */
    public static function sectionPatterns(string $anchorName): array
    {
        return self::map()[$anchorName] ?? [];
    }

    public static function midViewportY(string $scrollName): string
    {
        $safe = preg_match('/^[A-Za-z_][\w]*$/', $scrollName) === 1
            ? $scrollName
            : "'" . str_replace("'", "''", $scrollName) . "'";

        return '=' . $safe . '.Height / 2';
    }
}
