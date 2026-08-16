<?php

declare(strict_types=1);

namespace PowerSweeper;

use PowerSweeper\Hops\AccessibilityLabelsHop;
use PowerSweeper\Hops\AccessibilityPolishHop;
use PowerSweeper\Hops\AlignNearMissHop;
use PowerSweeper\Hops\AnalyzeAppCheckerHop;
use PowerSweeper\Hops\CleanDefaultChromeHop;
use PowerSweeper\Hops\ConfigurePowerDocumentHop;
use PowerSweeper\Hops\CorrelateSharePointHop;
use PowerSweeper\Hops\EnableDarkModeHop;
use PowerSweeper\Hops\EnableDarkThemeHop;
use PowerSweeper\Hops\EnsureFocusVisibleHop;
use PowerSweeper\Hops\EnsureTabIndexHop;
use PowerSweeper\Hops\ExportToWebIrHop;
use PowerSweeper\Hops\ExportWebAppHop;
use PowerSweeper\Hops\FixControlNamesAndRefsHop;
use PowerSweeper\Hops\FixFormulaErrorsHop;
use PowerSweeper\Hops\HopInterface;
use PowerSweeper\Hops\ImportFromWebIrHop;
use PowerSweeper\Hops\ImportWebAppHop;
use PowerSweeper\Hops\MeaningfulNamesHop;
use PowerSweeper\Hops\NormalizeClassicButtonChromeHop;
use PowerSweeper\Hops\NormalizeContainersHop;
use PowerSweeper\Hops\PreferClassicThemeControlsHop;
use PowerSweeper\Hops\RegenerateSarifHop;
use PowerSweeper\Hops\RepairCheckedBooleansHop;
use PowerSweeper\Hops\RepairConvergeFormulasHop;
use PowerSweeper\Hops\RepairContextAwareRefsHop;
use PowerSweeper\Hops\RepairControlRefsHop;
use PowerSweeper\Hops\RepairDelegationHop;
use PowerSweeper\Hops\RepairDoubleQualifiedRefsHop;
use PowerSweeper\Hops\RepairGhostPatchFieldsHop;
use PowerSweeper\Hops\RepairMaintainabilityHop;
use PowerSweeper\Hops\RepairSharePointDataHop;
use PowerSweeper\Hops\RepairSharePointFieldsHop;
use PowerSweeper\Hops\RepairStudioSyntaxHop;
use PowerSweeper\Hops\RepairVarCurrentPackageHop;
use PowerSweeper\Hops\ScanStudioIssuesHop;
use PowerSweeper\Hops\SetZipPathStyleHop;
use PowerSweeper\Hops\StripDefaultFillHop;
use PowerSweeper\Hops\TooltipFromLabelHop;
use PowerSweeper\Hops\TranslateHop;
use PowerSweeper\Hops\UnwhackLocaleFormulasHop;

final class HopRegistry
{
    /**
     * User-facing palette only: safe stage composites + translate.
     * Sub-passes stay registered for composites / CLI but are not listed in the UI.
     *
     * @var list<string>
     */
    public const PALETTE_IDS = [
        'fix_control_names_and_refs',
        'fix_formula_errors',
        'repair_sharepoint_data',
        'accessibility_polish',
        'clean_default_chrome',
        'enable_dark_theme',
        'translate',
        'export_to_web_ir',
        'import_from_web_ir',
    ];

    /** @var array<string, class-string<HopInterface>> */
    private array $hops = [];

    public function __construct()
    {
        foreach ([
            // Palette stages (order mirrored by PALETTE_IDS).
            FixControlNamesAndRefsHop::class,
            FixFormulaErrorsHop::class,
            RepairSharePointDataHop::class,
            AccessibilityPolishHop::class,
            CleanDefaultChromeHop::class,
            EnableDarkThemeHop::class,
            TranslateHop::class,
            ExportToWebIrHop::class,
            ImportFromWebIrHop::class,
            // Internal sub-passes (composites / scripts; hidden from catalog()).
            NormalizeContainersHop::class,
            AccessibilityLabelsHop::class,
            MeaningfulNamesHop::class,
            AlignNearMissHop::class,
            StripDefaultFillHop::class,
            NormalizeClassicButtonChromeHop::class,
            TooltipFromLabelHop::class,
            UnwhackLocaleFormulasHop::class,
            RepairCheckedBooleansHop::class,
            RepairControlRefsHop::class,
            RepairContextAwareRefsHop::class,
            RepairConvergeFormulasHop::class,
            RepairDelegationHop::class,
            RepairDoubleQualifiedRefsHop::class,
            RepairGhostPatchFieldsHop::class,
            RepairMaintainabilityHop::class,
            RepairSharePointFieldsHop::class,
            RepairVarCurrentPackageHop::class,
            RepairStudioSyntaxHop::class,
            EnsureFocusVisibleHop::class,
            EnsureTabIndexHop::class,
            ScanStudioIssuesHop::class,
            RegenerateSarifHop::class,
            AnalyzeAppCheckerHop::class,
            PreferClassicThemeControlsHop::class,
            EnableDarkModeHop::class,
            CorrelateSharePointHop::class,
            SetZipPathStyleHop::class,
            ExportWebAppHop::class,
            ImportWebAppHop::class,
            ConfigurePowerDocumentHop::class,
        ] as $class) {
            /** @var class-string<HopInterface> $class */
            $this->hops[$class::id()] = $class;
        }
    }

    /**
     * Palette entries for the UI (composites + translate only).
     *
     * @return list<array{id:string,label:string,description:string}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach (self::PALETTE_IDS as $id) {
            if (!isset($this->hops[$id])) {
                continue;
            }
            $class = $this->hops[$id];
            $out[] = [
                'id' => $id,
                'label' => $class::label(),
                'description' => $class::description(),
            ];
        }

        return $out;
    }

    public function make(string $id): HopInterface
    {
        if (!isset($this->hops[$id])) {
            throw new \InvalidArgumentException('Unknown hop: ' . $id);
        }
        $class = $this->hops[$id];
        return new $class();
    }

    public function has(string $id): bool
    {
        return isset($this->hops[$id]);
    }
}
