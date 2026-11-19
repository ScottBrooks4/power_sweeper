<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/** Resolve gblTheme.* tokens and RGBA() to CSS colors. */
final class WebAppTheme
{
    /** @var array<string, string> */
    private array $tokens = [];

    /** @var array<string, string> */
    private array $darkTokens = [];

    public function __construct()
    {
        $path = dirname(__DIR__, 2) . '/config/theme_defaults.php';
        if (is_file($path)) {
            $raw = include $path;
            if (is_array($raw)) {
                foreach ($raw as $name => $pair) {
                    if (!is_array($pair) || !isset($pair['light'])) {
                        continue;
                    }
                    $this->tokens[(string) $name] = $this->rgba($pair['light']);
                    if (isset($pair['dark']) && is_array($pair['dark'])) {
                        $this->darkTokens[(string) $name] = $this->rgba($pair['dark']);
                    }
                }
            }
        }
        $this->tokens += [
            'Page' => 'rgba(250,250,252,1)',
            'Surface' => 'rgba(255,255,255,1)',
            'Text' => 'rgba(15,23,42,1)',
            'Accent' => 'rgba(37,99,235,1)',
            'Warning' => 'rgba(202,138,4,1)',
            'InputFill' => 'rgba(255,255,255,1)',
            'InputBorder' => 'rgba(148,163,184,1)',
            'InputText' => 'rgba(15,23,42,1)',
            'TextOnAccent' => 'rgba(255,255,255,1)',
            'Border' => 'rgba(226,232,240,1)',
            'Rail' => 'rgba(31,41,55,1)',
            'Link' => 'rgba(29,78,216,1)',
            'CheckboxLabel' => 'rgba(30,41,59,1)',
            'CheckboxBorder' => 'rgba(100,116,139,1)',
        ];
        $this->darkTokens += [
            'Page' => 'rgba(12,12,14,1)',
            'Surface' => 'rgba(28,28,30,1)',
            'Text' => 'rgba(255,255,255,1)',
            'Accent' => 'rgba(30,64,175,1)',
            'Warning' => 'rgba(161,98,7,1)',
            'InputFill' => 'rgba(44,44,50,1)',
            'InputBorder' => 'rgba(82,82,90,1)',
            'InputText' => 'rgba(255,255,255,1)',
            'TextOnAccent' => 'rgba(255,255,255,1)',
            'Border' => 'rgba(56,56,64,1)',
            'Rail' => 'rgba(70,70,70,1)',
            'Link' => 'rgba(45,212,191,1)',
            'CheckboxLabel' => 'rgba(226,232,240,1)',
            'CheckboxBorder' => 'rgba(148,163,184,1)',
        ];
    }

    /** @param array{r?:int|float,g?:int|float,b?:int|float,a?:int|float} $c */
    private function rgba(array $c): string
    {
        return sprintf(
            'rgba(%d,%d,%d,%s)',
            (int) ($c['r'] ?? 0),
            (int) ($c['g'] ?? 0),
            (int) ($c['b'] ?? 0),
            (string) ($c['a'] ?? 1),
        );
    }

    /** @return array<string, string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /** @return array<string, string> */
    public function darkTokens(): array
    {
        return $this->darkTokens;
    }

    public function cssVars(): string
    {
        $out = ":root {\n";
        foreach ($this->tokens as $name => $css) {
            $out .= '  --theme-' . strtolower($name) . ': ' . $css . ";\n";
        }
        $out .= "}\n";
        $out .= "html.ps-dark, #app-root.ps-dark, [data-theme=\"dark\"] {\n";
        foreach ($this->darkTokens as $name => $css) {
            $out .= '  --theme-' . strtolower($name) . ': ' . $css . ";\n";
        }
        $out .= "}\n";

        return $out;
    }

    public function color(string $fx): string
    {
        $v = trim($fx);
        if (str_starts_with($v, '=')) {
            $v = trim(substr($v, 1));
        }
        // Comments often still contain gblTheme.Danger — strip before token pick.
        $v = preg_replace('/\/\*[\s\S]*?\*\//', '', $v) ?? $v;
        $v = preg_replace('/\/\/[^\n]*/', '', $v) ?? $v;
        $v = trim($v);
        if ($v === '' || strcasecmp($v, 'Color.Transparent') === 0) {
            return 'transparent';
        }
        // Idle paint: varShowValidation is false, so use the false-branch color
        // (last gblTheme.* / Transparent), never the Danger arm.
        if (
            preg_match('/\bIf\s*\(/i', $v) === 1
            && preg_match('/varShowValidation|gblTheme\.Danger|Parent\.Required/i', $v) === 1
        ) {
            if (preg_match_all('/gblTheme(?:Light|Dark)?\.([A-Za-z_]\w*)/', $v, $all) >= 1) {
                $idle = $all[1][count($all[1]) - 1];
                if (strcasecmp($idle, 'Danger') !== 0 && isset($this->tokens[$idle])) {
                    return $this->tokens[$idle];
                }
            }
            if (preg_match('/Color\.Transparent|\bTransparent\b/i', $v) === 1) {
                return 'transparent';
            }
            if (isset($this->tokens['InputBorder'])) {
                return $this->tokens['InputBorder'];
            }
        }
        if (preg_match('/gblTheme(?:Light|Dark)?\.([A-Za-z_]\w*)/', $v, $m)) {
            return $this->tokens[$m[1]] ?? 'transparent';
        }
        if (preg_match('/RGBA\s*\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*\)/i', $v, $m)) {
            return sprintf('rgba(%s,%s,%s,%s)', $m[1], $m[2], $m[3], $m[4]);
        }
        if (preg_match('/RGB\s*\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*\)/i', $v, $m)) {
            return sprintf('rgb(%s,%s,%s)', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/^#[0-9A-Fa-f]{3,8}$/', $v)) {
            return $v;
        }

        return 'transparent';
    }
}
