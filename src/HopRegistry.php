<?php

declare(strict_types=1);

namespace PowerSweeper;

use PowerSweeper\Hops\AccessibilityLabelsHop;
use PowerSweeper\Hops\AlignNearMissHop;
use PowerSweeper\Hops\AnalyzeAppCheckerHop;
use PowerSweeper\Hops\CorrelateSharePointHop;
use PowerSweeper\Hops\EnableDarkModeHop;
use PowerSweeper\Hops\EnsureFocusVisibleHop;
use PowerSweeper\Hops\EnsureTabIndexHop;
use PowerSweeper\Hops\HopInterface;
use PowerSweeper\Hops\NormalizeClassicButtonChromeHop;
use PowerSweeper\Hops\NormalizeContainersHop;
use PowerSweeper\Hops\RepairCheckedBooleansHop;
use PowerSweeper\Hops\RepairContextAwareRefsHop;
use PowerSweeper\Hops\RepairControlRefsHop;
use PowerSweeper\Hops\RepairDelegationHop;
use PowerSweeper\Hops\RepairDoubleQualifiedRefsHop;
use PowerSweeper\Hops\RepairGhostPatchFieldsHop;
use PowerSweeper\Hops\RepairMaintainabilityHop;
use PowerSweeper\Hops\RepairSharePointFieldsHop;
use PowerSweeper\Hops\RepairStudioSyntaxHop;
use PowerSweeper\Hops\RegenerateSarifHop;
use PowerSweeper\Hops\RepairVarCurrentPackageHop;
use PowerSweeper\Hops\ScanStudioIssuesHop;
use PowerSweeper\Hops\SetZipPathStyleHop;
use PowerSweeper\Hops\StripDefaultFillHop;
use PowerSweeper\Hops\TooltipFromLabelHop;
use PowerSweeper\Hops\UnwhackLocaleFormulasHop;

final class HopRegistry
{
    /** @var array<string, class-string<HopInterface>> */
    private array $hops = [];

    public function __construct()
    {
        foreach ([
            NormalizeContainersHop::class,
            AccessibilityLabelsHop::class,
            AlignNearMissHop::class,
            StripDefaultFillHop::class,
            NormalizeClassicButtonChromeHop::class,
            TooltipFromLabelHop::class,
            UnwhackLocaleFormulasHop::class,
            RepairCheckedBooleansHop::class,
            RepairControlRefsHop::class,
            RepairContextAwareRefsHop::class,
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
            EnableDarkModeHop::class,
            CorrelateSharePointHop::class,
            SetZipPathStyleHop::class,
        ] as $class) {
            /** @var class-string<HopInterface> $class */
            $this->hops[$class::id()] = $class;
        }
    }

    /** @return list<array{id:string,label:string,description:string}> */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->hops as $id => $class) {
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
