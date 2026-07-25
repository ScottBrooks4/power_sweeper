<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\MsappArchive;
use PowerSweeper\Report;
use PowerSweeper\ZipTool;

/**
 * Explicitly set zip entry path separators for the packed .msapp.
 * By default Power Sweeper preserves the source archive style (almost always Windows `\`).
 */
final class SetZipPathStyleHop implements HopInterface
{
    public static function id(): string
    {
        return 'set_zip_path_style';
    }

    public static function label(): string
    {
        return 'Set zip path style';
    }

    public static function description(): string
    {
        return 'Force .msapp zip entry separators to windows (\\) or posix (/). Default behaviour already preserves the source style (usually Windows).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $archive = $options['_msapp_archive'] ?? null;
        if (!$archive instanceof MsappArchive) {
            throw new \RuntimeException('set_zip_path_style requires the pipeline archive context');
        }

        $requested = $options['style'] ?? ZipTool::STYLE_POSIX;
        if (!is_string($requested) || $requested === '') {
            $requested = ZipTool::STYLE_POSIX;
        }

        $from = $archive->entryStyle();
        $to = ZipTool::normalizeStyle($requested);
        if ($from === $to) {
            return;
        }

        $archive->setEntryStyle($to);
        $report->add(
            self::id(),
            '(package)',
            'zip_entry_style',
            $from,
            $to
        );
    }
}
