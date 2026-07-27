<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Encode control JSON the way Power Apps Studio exports it:
 * CRLF newlines and 2-space indentation (not PHP's default LF + 4 spaces).
 */
final class StudioJson
{
    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data, string $newline = "\r\n", int $indentSize = 2): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new \RuntimeException('Unable to encode control JSON');
        }

        // json_encode pretty-print uses 4 spaces per level.
        if ($indentSize !== 4) {
            $json = preg_replace_callback(
                '/^((?:    )+)/m',
                static function (array $m) use ($indentSize): string {
                    $levels = (int) (strlen($m[1]) / 4);
                    return str_repeat(str_repeat(' ', $indentSize), $levels);
                },
                $json
            ) ?? $json;
        }

        // Normalize to LF first, then to the desired newline.
        $json = str_replace(["\r\n", "\r"], "\n", $json);
        if ($newline !== "\n") {
            $json = str_replace("\n", $newline, $json);
        }

        if (!str_ends_with($json, $newline)) {
            $json .= $newline;
        }

        return $json;
    }

    /**
     * Detect newline + indent size from an existing Studio JSON document.
     *
     * @return array{newline: string, indent: int}
     */
    public static function detectStyle(string $raw): array
    {
        $newline = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $indent = 2;
        if (preg_match('/\{[\r\n]+( +)"/', $raw, $m) === 1) {
            $indent = max(1, strlen($m[1]));
        }
        return ['newline' => $newline, 'indent' => $indent];
    }
}
