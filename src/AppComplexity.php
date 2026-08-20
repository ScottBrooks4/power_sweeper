<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Lightweight package metrics used for runtime estimates.
 */
final class AppComplexity
{
    /**
     * @param list<ControlDocument> $documents
     * @return array{
     *   file_bytes:int,
     *   document_count:int,
     *   control_count:int,
     *   property_count:int,
     *   formula_chars:int,
     *   screen_count:int,
     *   extract_bytes:int
     * }
     */
    public static function measure(string $msappPath, array $documents, ?string $extractDir = null): array
    {
        $controls = 0;
        $properties = 0;
        $formulaChars = 0;
        $screens = 0;
        foreach ($documents as $doc) {
            if ($doc->screenName() !== null) {
                $screens++;
            }
            foreach ($doc->controls() as $control) {
                $controls++;
                $names = $control->propertyNames();
                $properties += count($names);
                foreach ($names as $name) {
                    $val = $control->getProperty($name);
                    if (is_string($val) && $val !== '') {
                        $formulaChars += strlen($val);
                    }
                }
            }
        }

        $extractBytes = 0;
        if (is_string($extractDir) && $extractDir !== '' && is_dir($extractDir)) {
            $extractBytes = self::dirBytes($extractDir);
        }

        return [
            'file_bytes' => is_file($msappPath) ? (int) filesize($msappPath) : 0,
            'document_count' => count($documents),
            'control_count' => $controls,
            'property_count' => $properties,
            'formula_chars' => $formulaChars,
            'screen_count' => $screens,
            'extract_bytes' => $extractBytes,
        ];
    }

    private static function dirBytes(string $dir): int
    {
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $total += (int) $file->getSize();
            }
        }

        return $total;
    }
}
