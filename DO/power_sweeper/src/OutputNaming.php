<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Build download filenames from the sweeps that ran.
 *
 * Example: App16.names+formulas+dark.msapp
 */
final class OutputNaming
{
    /** @var array<string, string> */
    private const SHORT = [
        'fix_control_names_and_refs' => 'names',
        'fix_formula_errors' => 'formulas',
        'repair_sharepoint_data' => 'sp',
        'accessibility_polish' => 'a11y',
        'clean_default_chrome' => 'chrome',
        'enable_dark_theme' => 'dark',
        'enable_dark_mode' => 'dark',
        'translate' => 'i18n',
        'spelling_consistency' => 'spelling',
        'export_to_web_ir' => 'toweb',
        'export_web_ir' => 'toweb',
        'import_from_web_ir' => 'fromweb',
        'import_web_ir' => 'fromweb',
        'import_web_package' => 'webpkg',
        'configure_power_document' => 'doc',
        'meaningful_names' => 'names',
        'prefer_classic_theme_controls' => 'classic',
        'normalize_containers' => 'containers',
        'strip_default_fill' => 'fill',
        'normalize_classic_button_chrome' => 'btn',
        'accessibility_labels' => 'labels',
        'ensure_focus_visible' => 'focus',
        'ensure_tab_index' => 'tab',
        'tooltip_from_label' => 'tips',
        'regenerate_sarif' => 'sarif',
        'apply_section_anchors' => 'anchors',
        'correlate_sharepoint' => 'spmap',
        'set_zip_path_style' => 'zip',
        'unwhack_locale_formulas' => 'locale',
        'repair_control_refs' => 'refs',
        'repair_context_aware_refs' => 'ctxrefs',
        'repair_converge_formulas' => 'converge',
        'repair_delegation' => 'deleg',
        'repair_double_qualified_refs' => 'dqrefs',
        'repair_ghost_patch_fields' => 'ghost',
        'repair_maintainability' => 'maint',
        'repair_sharepoint_fields' => 'spfields',
        'repair_var_current_package' => 'varpkg',
        'repair_studio_syntax' => 'syntax',
        'repair_checked_booleans' => 'bools',
        'analyze_app_checker' => 'checker',
        'scan_studio_issues' => 'scan',
    ];

    /**
     * @param list<array{id?:string,options?:array<string,mixed>}|string> $sweeps
     */
    public static function msappFilename(string $originalName, array $sweeps): string
    {
        $base = preg_replace('/\.(msapp|zip)$/i', '', $originalName) ?? 'app';
        $base = preg_replace('/[^\w.\-]+/', '_', $base) ?: 'app';
        $tags = self::tags($sweeps);
        if ($tags === []) {
            return $base . '.msapp';
        }
        $suffix = implode('+', $tags);
        $name = $base . '.' . $suffix . '.msapp';
        if (strlen($name) <= 120) {
            return $name;
        }
        // Keep as many leading tags as fit.
        $kept = [];
        $budget = 120 - strlen($base) - 1 - strlen('.msapp');
        foreach ($tags as $tag) {
            $next = $kept === [] ? $tag : implode('+', $kept) . '+' . $tag;
            if (strlen($next) > $budget) {
                break;
            }
            $kept[] = $tag;
        }
        if ($kept === []) {
            $kept[] = substr($tags[0], 0, max(1, $budget));
        }

        return $base . '.' . implode('+', $kept) . '.msapp';
    }

    /**
     * @param list<array{id?:string,options?:array<string,mixed>}|string> $sweeps
     * @return list<string>
     */
    public static function tags(array $sweeps): array
    {
        $out = [];
        $seen = [];
        foreach ($sweeps as $step) {
            $id = is_string($step) ? $step : (string) ($step['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $tag = self::SHORT[$id] ?? self::fallbackTag($id);
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $out[] = $tag;
        }

        return $out;
    }

    private static function fallbackTag(string $id): string
    {
        $parts = preg_split('/_+/', strtolower($id)) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
        if ($parts === []) {
            return 'sweep';
        }
        if (count($parts) === 1) {
            return substr($parts[0], 0, 12);
        }
        $compact = '';
        foreach ($parts as $part) {
            $compact .= substr($part, 0, 4);
            if (strlen($compact) >= 10) {
                break;
            }
        }

        return substr($compact, 0, 12);
    }
}
