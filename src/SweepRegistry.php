<?php

declare(strict_types=1);

namespace PowerSweeper;

use PowerSweeper\Sweeps\AccessibilityLabelsSweep;
use PowerSweeper\Sweeps\AccessibilityPolishSweep;
use PowerSweeper\Sweeps\AlignNearMissSweep;
use PowerSweeper\Sweeps\AnalyzeAppCheckerSweep;
use PowerSweeper\Sweeps\CleanDefaultChromeSweep;
use PowerSweeper\Sweeps\ConfigurePowerDocumentSweep;
use PowerSweeper\Sweeps\CorrelateSharePointSweep;
use PowerSweeper\Sweeps\EnableDarkModeSweep;
use PowerSweeper\Sweeps\EnableDarkThemeSweep;
use PowerSweeper\Sweeps\EnsureFocusVisibleSweep;
use PowerSweeper\Sweeps\EnsureTabIndexSweep;
use PowerSweeper\Sweeps\ExportToWebIrSweep;
use PowerSweeper\Sweeps\ExportWebAppSweep;
use PowerSweeper\Sweeps\FixControlNamesAndRefsSweep;
use PowerSweeper\Sweeps\FixFormulaErrorsSweep;
use PowerSweeper\Sweeps\SweepInterface;
use PowerSweeper\Sweeps\ImportFromWebIrSweep;
use PowerSweeper\Sweeps\ImportWebAppSweep;
use PowerSweeper\Sweeps\MeaningfulNamesSweep;
use PowerSweeper\Sweeps\NormalizeClassicButtonChromeSweep;
use PowerSweeper\Sweeps\NormalizeContainersSweep;
use PowerSweeper\Sweeps\PreferClassicThemeControlsSweep;
use PowerSweeper\Sweeps\RegenerateSarifSweep;
use PowerSweeper\Sweeps\RepairCheckedBooleansSweep;
use PowerSweeper\Sweeps\RepairConvergeFormulasSweep;
use PowerSweeper\Sweeps\RepairContextAwareRefsSweep;
use PowerSweeper\Sweeps\RepairControlRefsSweep;
use PowerSweeper\Sweeps\RepairDelegationSweep;
use PowerSweeper\Sweeps\RepairDoubleQualifiedRefsSweep;
use PowerSweeper\Sweeps\RepairGhostPatchFieldsSweep;
use PowerSweeper\Sweeps\RepairMaintainabilitySweep;
use PowerSweeper\Sweeps\RepairSharePointDataSweep;
use PowerSweeper\Sweeps\RepairSharePointFieldsSweep;
use PowerSweeper\Sweeps\RepairStudioSyntaxSweep;
use PowerSweeper\Sweeps\RepairVarCurrentPackageSweep;
use PowerSweeper\Sweeps\ScanStudioIssuesSweep;
use PowerSweeper\Sweeps\SetZipPathStyleSweep;
use PowerSweeper\Sweeps\StripDefaultFillSweep;
use PowerSweeper\Sweeps\TooltipFromLabelSweep;
use PowerSweeper\Sweeps\TranslateSweep;
use PowerSweeper\Sweeps\UnwhackLocaleFormulasSweep;

final class SweepRegistry
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

    /** @var array<string, class-string<SweepInterface>> */
    private array $sweeps = [];

    public function __construct()
    {
        foreach ([
            // Palette stages (order mirrored by PALETTE_IDS).
            FixControlNamesAndRefsSweep::class,
            FixFormulaErrorsSweep::class,
            RepairSharePointDataSweep::class,
            AccessibilityPolishSweep::class,
            CleanDefaultChromeSweep::class,
            EnableDarkThemeSweep::class,
            TranslateSweep::class,
            ExportToWebIrSweep::class,
            ImportFromWebIrSweep::class,
            // Internal sub-passes (composites / scripts; hidden from catalog()).
            NormalizeContainersSweep::class,
            AccessibilityLabelsSweep::class,
            MeaningfulNamesSweep::class,
            AlignNearMissSweep::class,
            StripDefaultFillSweep::class,
            NormalizeClassicButtonChromeSweep::class,
            TooltipFromLabelSweep::class,
            UnwhackLocaleFormulasSweep::class,
            RepairCheckedBooleansSweep::class,
            RepairControlRefsSweep::class,
            RepairContextAwareRefsSweep::class,
            RepairConvergeFormulasSweep::class,
            RepairDelegationSweep::class,
            RepairDoubleQualifiedRefsSweep::class,
            RepairGhostPatchFieldsSweep::class,
            RepairMaintainabilitySweep::class,
            RepairSharePointFieldsSweep::class,
            RepairVarCurrentPackageSweep::class,
            RepairStudioSyntaxSweep::class,
            EnsureFocusVisibleSweep::class,
            EnsureTabIndexSweep::class,
            ScanStudioIssuesSweep::class,
            RegenerateSarifSweep::class,
            AnalyzeAppCheckerSweep::class,
            PreferClassicThemeControlsSweep::class,
            EnableDarkModeSweep::class,
            CorrelateSharePointSweep::class,
            SetZipPathStyleSweep::class,
            ExportWebAppSweep::class,
            ImportWebAppSweep::class,
            ConfigurePowerDocumentSweep::class,
        ] as $class) {
            /** @var class-string<SweepInterface> $class */
            $this->sweeps[$class::id()] = $class;
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
            if (!isset($this->sweeps[$id])) {
                continue;
            }
            $class = $this->sweeps[$id];
            $out[] = [
                'id' => $id,
                'label' => $class::label(),
                'description' => $class::description(),
            ];
        }

        return $out;
    }

    public function make(string $id): SweepInterface
    {
        if (!isset($this->sweeps[$id])) {
            throw new \InvalidArgumentException('Unknown sweep: ' . $id);
        }
        $class = $this->sweeps[$id];
        return new $class();
    }

    public function has(string $id): bool
    {
        return isset($this->sweeps[$id]);
    }
}
