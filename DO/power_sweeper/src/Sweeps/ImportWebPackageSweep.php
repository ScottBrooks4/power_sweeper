<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ControlDocument;
use PowerSweeper\MsappArchive;
use PowerSweeper\Report;
use PowerSweeper\WebApp\PowerAppMaterializer;
use PowerSweeper\WebApp\WebAppIrApplier;
use PowerSweeper\WebApp\WebAppPackageReader;
use PowerSweeper\ZipTool;

/**
 * Read a web package (the export's zip or unpacked tree) and write a canvas app.
 *
 * PHP, Python, and C Sharp packages are accepted, with XML or SQL data and an
 * MVC, canvas, or responsive page. The conversion file inside the package is
 * what comes back as Power Apps YAML.
 */
final class ImportWebPackageSweep implements SweepInterface
{
    public static function id(): string
    {
        return 'import_web_package';
    }

    public static function label(): string
    {
        return 'Import web package';
    }

    public static function description(): string
    {
        return 'Read a web package from Export to web (PHP, Python, or C Sharp; XML or SQL; MVC, canvas, responsive web, or WET) and write the canvas app back.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $extractDir = is_string($options['_extract_dir'] ?? null) ? (string) $options['_extract_dir'] : '';
        $package = $this->locate($extractDir, $options);
        if ($package === null) {
            $report->add(
                self::id(),
                'WebApp/package.zip',
                'import',
                '(missing package)',
                'skipped — export a web package first or pass package_path',
            );
            return;
        }

        try {
            $read = (new WebAppPackageReader())->read($package);
        } catch (\Throwable $e) {
            $report->add(self::id(), $package, 'import', '(unreadable)', $e->getMessage());
            return;
        }

        $shape = sprintf('%s / %s / %s', $read['runtime'], $read['storage'], $read['layout']);
        $notes = $read['notes'] !== [] ? ' (' . implode('; ', $read['notes']) . ')' : '';

        if ($this->hasScreens($documents)) {
            $result = (new WebAppIrApplier())->apply($documents, $read['ir'], $report, $extractDir !== '' ? $extractDir : null);
            $detail = $result['notes'] !== [] ? implode('; ', $result['notes']) : 'no structural deltas';
            $report->add(
                self::id(),
                $package,
                'import',
                $shape,
                $result['changes'] . ' change(s); ' . $detail . $notes,
            );
            // Web section-top anchors → Power Apps mid-pane SetFocus in the right sections.
            (new ApplySectionAnchorsSweep())->apply($documents, $report, [
                'relocate' => true,
                'force_y' => true,
            ]);
            return;
        }

        if ($extractDir === '' || !is_dir($extractDir)) {
            $report->add(self::id(), $package, 'import', $shape, 'skipped — no extract directory to write the canvas app into');
            return;
        }

        $materializer = new PowerAppMaterializer();
        $written = $materializer->writeSources($read['ir'], $extractDir);
        $stripped = false;
        if (
            is_file($extractDir . '/import/power_sweeper_ir.json')
            || is_file($extractDir . '/ir/power_sweeper_ir.json')
        ) {
            $materializer->stripWebTree($extractDir);
            $stripped = true;
            $archive = $options['_msapp_archive'] ?? null;
            if ($archive instanceof MsappArchive) {
                $archive->setEntryStyle(ZipTool::STYLE_WINDOWS);
            }
        }
        $screens = is_array($read['ir']['screens'] ?? null) ? count($read['ir']['screens']) : 0;
        $report->add(
            self::id(),
            $package,
            'import',
            $shape,
            sprintf(
                'wrote %d canvas file(s), %d screen(s)%s%s',
                count($written),
                $screens,
                $stripped ? '; packed as a canvas app' : '',
                $notes,
            ),
        );
    }

    /**
     * @param list<ControlDocument> $documents
     */
    private function hasScreens(array $documents): bool
    {
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isScreen()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $options */
    private function locate(string $extractDir, array $options): ?string
    {
        $given = $options['package_path'] ?? null;
        if (is_string($given) && $given !== '' && (is_file($given) || is_dir($given))) {
            return $given;
        }
        if ($extractDir === '') {
            return null;
        }
        $zip = $extractDir . DIRECTORY_SEPARATOR . 'WebApp' . DIRECTORY_SEPARATOR . 'package.zip';
        if (is_file($zip)) {
            return $zip;
        }
        if (
            is_file($extractDir . '/import/power_sweeper_ir.json')
            || is_file($extractDir . '/ir/power_sweeper_ir.json')
        ) {
            return $extractDir;
        }
        $sidecar = $extractDir . '/WebApp/power_sweeper_ir.json';
        if (is_file($sidecar)) {
            return $sidecar;
        }

        return null;
    }
}
