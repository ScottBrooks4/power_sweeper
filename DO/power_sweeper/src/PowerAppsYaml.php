<?php

declare(strict_types=1);

namespace PowerSweeper;

use Symfony\Component\Yaml\Yaml;

/**
 * Serialize canvas-app YAML in a shape Studio will import.
 *
 * Symfony's Yaml::dump() alone breaks Power Apps import by:
 * - quoting Power Fx formulas (`Fill: '=…'` instead of `Fill: =…`)
 * - quoting YAML 1.1 ambiguous keys (`'Y':` instead of `Y:`)
 * - expanding Children list items onto two lines (`-\n  Name:` instead of `- Name:`)
 */
final class PowerAppsYaml
{
    /**
     * @param array<mixed> $data
     */
    public static function dump(array $data, string $headerComment = ''): string
    {
        // 2nd arg is the depth at which Symfony switches to { inline } maps — keep it huge
        // so canvas control trees stay in block form like Studio emits.
        $yaml = Yaml::dump(
            $data,
            1000,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );

        $yaml = self::collapseSequenceMaps($yaml);
        $yaml = self::unquoteKeys($yaml);
        $yaml = self::unquotePowerFxScalars($yaml);

        if ($headerComment !== '') {
            $header = rtrim($headerComment, "\r\n") . "\n";
            return $header . $yaml;
        }

        return $yaml;
    }

    /**
     * Capture leading `# …` comment block from an original .pa.yaml (Studio warning banner).
     */
    public static function extractHeaderComment(string $raw): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $header = [];
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                $header[] = $line;
                continue;
            }
            break;
        }
        while ($header !== [] && $header[array_key_last($header)] === '') {
            array_pop($header);
        }
        return $header === [] ? '' : implode("\n", $header);
    }

    /**
     * Symfony dumps single-key maps in sequences as:
     *   -
     *     Name:
     * Power Apps expects:
     *   - Name:
     */
    private static function collapseSequenceMaps(string $yaml): string
    {
        $prev = null;
        while ($prev !== $yaml) {
            $prev = $yaml;
            $yaml = preg_replace('/^(\s*)-\n\1  (\S.*)$/m', '$1- $2', $yaml) ?? $yaml;
        }
        return $yaml;
    }

    /**
     * Studio leaves screen/control names bare even with spaces/slashes
     * (`VCR Home Page:`, `VCR / VCN Form:`). Symfony quotes them.
     */
    private static function unquoteKeys(string $yaml): string
    {
        $unquote = static function (string $key): ?string {
            $key = str_replace("''", "'", $key);
            // Keep quoting only when a bare key would be ambiguous/broken.
            if ($key === '' || str_contains($key, "'") || str_contains($key, "\n") || str_contains($key, ':')) {
                return null;
            }
            return $key;
        };

        // Block map keys: 'Name With Spaces':
        $yaml = preg_replace_callback(
            "/^(\\s*)'((?:[^']|'')+)':/m",
            static function (array $m) use ($unquote): string {
                $key = $unquote($m[2]);
                return $key === null ? $m[0] : $m[1] . $key . ':';
            },
            $yaml
        ) ?? $yaml;

        // Sequence entries: - 'Control Name':
        $yaml = preg_replace_callback(
            "/^(\\s*-\\s*)'((?:[^']|'')+)':/m",
            static function (array $m) use ($unquote): string {
                $key = $unquote($m[2]);
                return $key === null ? $m[0] : $m[1] . $key . ':';
            },
            $yaml
        ) ?? $yaml;

        // Flow-map keys: ,'Y': or { 'Y':
        $yaml = preg_replace_callback(
            "/([\\s,{])'((?:[^']|'')+)':/",
            static function (array $m) use ($unquote): string {
                $key = $unquote($m[2]);
                return $key === null ? $m[0] : $m[1] . $key . ':';
            },
            $yaml
        ) ?? $yaml;

        return $yaml;
    }

    /**
     * Unquote Power Fx scalars in block and flow styles.
     */
    private static function unquotePowerFxScalars(string $yaml): string
    {
        // Block: Key: '=formula'  or  Key: "=formula"
        $yaml = preg_replace_callback(
            '/^(\\s*)([A-Za-z0-9_.\\/-]+):\\s*(["\'])(=.*)\\3$/m',
            static function (array $m): string {
                $indent = $m[1];
                $key = $m[2];
                $quote = $m[3];
                $val = $m[4];
                if ($quote === "'") {
                    $val = str_replace("''", "'", $val);
                } else {
                    $val = stripcslashes($val);
                }
                if (str_contains($val, "\n") || str_contains($val, "\r")) {
                    return $m[0];
                }
                // Colons in UpdateContext({x: 1}) break plain YAML scalars — use Studio block form.
                if (self::needsBlockScalarFx($val)) {
                    return $indent . $key . ": |-\n" . $indent . '  ' . $val;
                }
                // Values with leading quotes inside (Font.'Open Sans') are fine unquoted for PA
                // when the whole value starts with =.
                return $indent . $key . ': ' . $val;
            },
            $yaml
        ) ?? $yaml;

        // Flow / inline maps: BorderColor: '=gblTheme.Accent'  or  Image: "='new-form'"
        $yaml = preg_replace_callback(
            '/([\\s,{])([A-Za-z0-9_.\\/-]+):\\s*(["\'])(=.*?)\\3(?=\\s*[,}\\n])/s',
            static function (array $m): string {
                $prefix = $m[1];
                $key = $m[2];
                $quote = $m[3];
                $val = $m[4];
                if ($quote === "'") {
                    $val = str_replace("''", "'", $val);
                } else {
                    // Keep as double-quoted only if unquoting would be unsafe for the line
                    // (embedded comma + colon already bounded by lookahead to , or })
                    $val = str_replace(['\\"', '\\n', '\\r', '\\\\'], ['"', "\n", "\r", '\\'], $val);
                }
                if (str_contains($val, "\n") || str_contains($val, "\r") || self::needsBlockScalarFx($val)) {
                    // Multi-line / colon-bearing formulas must stay quoted in flow maps
                    return $m[0];
                }
                return $prefix . $key . ': ' . $val;
            },
            $yaml
        ) ?? $yaml;

        // Flow-map keys quoted as 'Y':
        $yaml = preg_replace("/([\\s,{])'([A-Za-z][A-Za-z0-9_]*)':/", '$1$2:', $yaml) ?? $yaml;

        return $yaml;
    }

    /**
     * Power Fx that is unsafe as a plain YAML scalar (Studio uses |- for these).
     */
    private static function needsBlockScalarFx(string $val): bool
    {
        return str_contains($val, ': ')
            || str_contains($val, ":\t")
            || str_contains($val, ' #')
            || str_starts_with(ltrim($val, '='), '#');
    }
}
