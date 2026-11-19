<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Strip Power Apps canvas geometry so content flows with the device.
 *
 * Used for responsive and WET layouts: absolute left/top and fixed px
 * width/height are removed from inline styles and generated class rules.
 * Visual tokens (fill, type, borders, flex) stay.
 */
final class WebAppFlowLayout
{
    public static function isFlowLayout(string $layout): bool
    {
        return $layout === 'responsive' || $layout === 'wet';
    }

    public function apply(string $html): string
    {
        $html = preg_replace_callback(
            '/(<style\b[^>]*>)(.*?)(<\/style>)/is',
            function (array $m): string {
                return $m[1] . $this->stripCssBlock($m[2]) . $m[3];
            },
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '/\sstyle="([^"]*)"/i',
            function (array $m): string {
                $cleaned = $this->stripDeclarations($m[1]);
                if ($cleaned === '') {
                    return '';
                }

                return ' style="' . $cleaned . '"';
            },
            $html,
        ) ?? $html;

        return $html;
    }

    private function stripCssBlock(string $css): string
    {
        return preg_replace_callback(
            '/\{([^{}]*)\}/',
            function (array $m): string {
                return '{' . $this->stripDeclarations($m[1]) . '}';
            },
            $css,
        ) ?? $css;
    }

    private function stripDeclarations(string $decls): string
    {
        $parts = preg_split('/\s*;\s*/', trim($decls)) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^([a-z-]+)\s*:\s*(.+)$/i', $part, $m)) {
                $kept[] = $part;
                continue;
            }
            $prop = strtolower($m[1]);
            $value = trim($m[2]);
            if ($this->shouldDrop($prop, $value)) {
                continue;
            }
            $kept[] = $prop . ':' . $value;
        }

        return implode(';', $kept);
    }

    private function shouldDrop(string $prop, string $value): bool
    {
        if (in_array($prop, ['left', 'top', 'right', 'bottom'], true)) {
            return $this->isLength($value);
        }
        if ($prop === 'position') {
            return strcasecmp($value, 'absolute') === 0
                || strcasecmp($value, 'fixed') === 0;
        }
        if ($prop === 'width' || $prop === 'height' || $prop === 'min-width' || $prop === 'min-height'
            || $prop === 'max-width' || $prop === 'max-height') {
            // Keep fluid / zero-size anchors; drop Power Apps px canvas sizes.
            if ($this->isZero($value)) {
                return false;
            }
            if (preg_match('/^(auto|none|min-content|max-content|fit-content|0)$/i', $value) === 1) {
                return false;
            }
            if (str_contains($value, '%') || str_contains($value, 'vw') || str_contains($value, 'vh')
                || str_contains($value, 'fr') || str_starts_with(strtolower($value), 'min(')
                || str_starts_with(strtolower($value), 'max(') || str_starts_with(strtolower($value), 'clamp(')) {
                return false;
            }
            // Keep only tiny chrome (icons / crest marks ≤48px). Form field canvas
            // widths in the 100–200px band leave sparse, right-heavy rows in flow.
            if (preg_match('/^-?(\d+(?:\.\d+)?)px$/i', $value, $mm) === 1 && (float) $mm[1] <= 48.0) {
                return false;
            }

            return $this->isLength($value);
        }
        if ($prop === 'overflow' || $prop === 'overflow-x' || $prop === 'overflow-y') {
            return strcasecmp($value, 'hidden') === 0;
        }

        return false;
    }

    private function isLength(string $value): bool
    {
        return preg_match('/^-?\d+(\.\d+)?(px|em|rem|pt)$/i', $value) === 1;
    }

    private function isZero(string $value): bool
    {
        return preg_match('/^0+(\.0+)?(px|em|rem|pt)?$/i', $value) === 1;
    }
}
