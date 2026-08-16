<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Mechanical syntax and bootstrap repairs that are safe and idempotent (code only).
 *
 * App.OnStart must not call component methods on another screen; those calls are
 * deferred onto the host screen's OnVisible. The host screen is discovered by
 * locating the screen that owns `comExternalFunctions` (not a hardcoded name).
 */
final class RepairStudioSyntaxHop implements HopInterface
{
    private const HOST_COMPONENT = 'comExternalFunctions';

    /** @var array<string, string> LookUp / record field aliases for VCR list schema drift. */
    private const RECORD_FIELD_ALIASES = [
        'Unclassified' => 'UnclassifiedRestricted',
    ];

    /** @var list<string> Patch record fields typed as DateTime — empty string is invalid. */
    private const PATCH_DATETIME_FIELDS = [
        'SignedDate',
    ];

    public static function id(): string
    {
        return 'repair_studio_syntax';
    }

    public static function label(): string
    {
        return 'Repair Studio syntax issues';
    }

    public static function description(): string
    {
        return 'Fix trailing Concatenate commas, undefined flow vars, Patch DateTime blanks, and App bootstrap cross-screen component calls (code only).';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $hostScreens = $this->discoverHostScreens($documents);

        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($report, $hostScreens): string {
                $new = $this->repairFormula($formula, $path, $report);
                if ($this->isAppOnStartPath($path)) {
                    $new = $this->repairAppOnStart($new, $path, $report, $hostScreens);
                }

                return $new;
            });
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    $before = (string) ($control->getProperty('OnStart') ?? '');
                    $after = $this->repairAppOnStart($before, $control->path . '.OnStart', $report, $hostScreens);
                    if ($after !== $before) {
                        $control->setProperty('OnStart', $after);
                    }
                    $formulas = (string) ($control->getProperty('Formulas') ?? '');
                    if ($formulas !== '') {
                        $quoted = $this->quoteSpacedNamedFormulas($formulas, $control->path . '.Formulas', $report);
                        if ($quoted !== $formulas) {
                            $control->setProperty('Formulas', $quoted);
                        }
                    }
                }
                $this->dropUnsupportedToggleTextPosition($control, $report);
                if (!$control->isScreen() || !isset($hostScreens[$control->name])) {
                    continue;
                }
                $before = (string) ($control->getProperty('OnVisible') ?? '');
                $after = $this->ensureHostBootstrap($before, $control->path, $report);
                if ($after !== $before) {
                    $control->setProperty('OnVisible', $after);
                }
            }
        }
    }

    /**
     * Modern/classic toggle controls do not accept TextPosition (Studio: ErrInvalidName).
     */
    private function dropUnsupportedToggleTextPosition(ControlNode $control, Report $report): void
    {
        $type = strtolower($control->type);
        if (!str_contains($type, 'toggle')) {
            return;
        }
        if ($control->getProperty('TextPosition') === null) {
            return;
        }
        $from = (string) $control->getProperty('TextPosition');
        $control->removeProperty('TextPosition');
        $report->add(self::id(), $control->path, 'TextPosition', $from, '(removed unsupported)');
    }

    /**
     * App.Formulas named bindings with spaces must be quoted:
     * TDR Trips_ TopMenu_1 = […] → 'TDR Trips_ TopMenu_1' = […]
     */
    private function quoteSpacedNamedFormulas(string $formulas, string $path, Report $report): string
    {
        $changed = false;
        $out = PowerFxFormulaSegments::transformCode($formulas, function (string $code) use ($report, $path, &$changed): string {
            $replaced = preg_replace_callback(
                '/^([A-Za-z_][\w]*(?:\s+[A-Za-z_][\w]*)+)\s*=/m',
                function (array $m) use ($report, $path, &$changed): string {
                    $name = $m[1];
                    $quoted = "'" . str_replace("'", "''", $name) . "' =";
                    $report->add(self::id(), $path, 'named formula', $name, "'" . $name . "'");
                    $changed = true;

                    return $quoted;
                },
                $code,
            );

            return is_string($replaced) ? $replaced : $code;
        });

        return $changed ? $out : $formulas;
    }

    /**
     * Screens that own the global external-functions component instance.
     * YAML walk order lists children before the screen root, so we inspect
     * each screen's child tree rather than relying on flat list order.
     *
     * @param list<ControlDocument> $documents
     * @return array<string, true>
     */
    private function discoverHostScreens(array $documents): array
    {
        $hosts = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if (!$control->isScreen()) {
                    continue;
                }
                if ($this->subtreeHasHostComponent($control)) {
                    $hosts[$control->name] = true;
                }
            }
        }

        return $hosts;
    }

    private function subtreeHasHostComponent(ControlNode $node): bool
    {
        if ($this->isHostComponent($node)) {
            return true;
        }
        foreach ($node->children as $child) {
            if ($this->subtreeHasHostComponent($child)) {
                return true;
            }
        }

        return false;
    }

    private function isHostComponent(ControlNode $control): bool
    {
        // Instance suffixes: comExternalFunctions_1
        return (bool) preg_match(
            '/^' . preg_quote(self::HOST_COMPONENT, '/') . '(_\d+)?$/',
            $control->name
        );
    }

    private function isAppOnStartPath(string $path): bool
    {
        return str_contains($path, 'OnStart')
            || str_contains($path, 'Properties.OnStart')
            || str_contains($path, '/App/');
    }

    private function repairFormula(string $formula, string $path, Report $report): string
    {
        $changed = false;

        // Structural pass: screen-qualified Date()/record keys span single-quoted screen names.
        $structural = PowerFxFormulaSegments::mapCode(
            PowerFxFormulaSegments::splitForStructure($formula),
            function (string $code) use ($report, $path, &$changed): string {
                $new = $code;

                $replaced = preg_replace(
                    "/'(?:[^']|'')+'\\.Date\\s*\\(/",
                    'Date(',
                    $new
                );
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, 'Date()', self::preview($new), self::preview($replaced));
                    $new = $replaced;
                    $changed = true;
                }

                $replaced = preg_replace(
                    "/'(?:[^']|'')+'\\.([A-Za-z_][\\w]*)\\s*:/",
                    '$1:',
                    $new
                );
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, 'record key', self::preview($new), self::preview($replaced));
                    $new = $replaced;
                    $changed = true;
                }

                return $new;
            }
        );
        $formula = $changed ? $structural : $formula;

        $out = PowerFxFormulaSegments::transformCode($formula, static function (string $code) use ($report, $path, &$changed): string {
            $new = $code;

            $replaced = preg_replace('/""\s*,\s*\)/', '"")', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'Concatenate', self::preview($new), self::preview($replaced));
                $new = $replaced;
                $changed = true;
            }

            $replaced = preg_replace('/\)\s*,\s*\)/', '))', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'Concatenate', self::preview($new), self::preview($replaced));
                $new = $replaced;
                $changed = true;
            }

            $replaced = preg_replace('/\bvarNewRequest\b/', 'false', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'varNewRequest', '(undefined)', 'false');
                $new = $replaced;
                $changed = true;
            }

            foreach (self::RECORD_FIELD_ALIASES as $old => $alias) {
                $pattern = '/\b([A-Za-z_][\w]*)\.' . preg_quote($old, '/') . '\b/';
                $replaced = preg_replace($pattern, '$1.' . $alias, $new);
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, 'record field', $old, $alias);
                    $new = $replaced;
                    $changed = true;
                }
            }

            foreach (self::PATCH_DATETIME_FIELDS as $field) {
                $pattern = '/\b' . preg_quote($field, '/') . '\s*:\s*""\s*,?/';
                $replaced = preg_replace($pattern, $field . ': Blank(),', $new);
                if ($replaced !== null && $replaced !== $new) {
                    $report->add(self::id(), $path, $field, '""', 'Blank()');
                    $new = $replaced;
                    $changed = true;
                }
            }

            return $new;
        });

        return $changed ? $out : $formula;
    }

    /**
     * @param array<string, true> $hostScreens
     */
    private function repairAppOnStart(string $formula, string $path, Report $report, array $hostScreens): string
    {
        $new = $formula;
        $changed = false;

        // Any "'Screen Name'.comExternalFunctions.loadUser()" → deferred flag
        $loadUserPattern = "/(?:'(?:[^']|'')+'|[A-Za-z_][\\w]*)\\."
            . preg_quote(self::HOST_COMPONENT, '/')
            . "(?:_\\d+)?\\.loadUser\\(\\)\\s*;?/";
        if (preg_match($loadUserPattern, $new)) {
            $new = preg_replace($loadUserPattern, "Set(varDeferredLoadUser, true);\n", $new) ?? $new;
            // Collapse accidental double newlines from ;? optional
            $new = preg_replace("/Set\\(varDeferredLoadUser, true\\);\\s*\n+/", "Set(varDeferredLoadUser, true);\n", $new) ?? $new;
            $report->add(self::id(), $path, 'loadUser', '(cross-screen from App)', 'varDeferredLoadUser');
            $changed = true;
        }

        // Param("requestid") → Set(varRequestID); host.loadPackage(varRequestID) → deferred
        $loadPackagePattern = "/If\\(\\s*!IsBlank\\(Param\\(\"requestid\"\\)\\)\\s*,\\s*"
            . "Set\\(varRequestID, Substitute\\(Param\\(\"requestid\"\\),\"-\", \" - \"\\)\\)\\s*;\\s*"
            . "(?:'(?:[^']|'')+'|[A-Za-z_][\\w]*)\\."
            . preg_quote(self::HOST_COMPONENT, '/')
            . "(?:_\\d+)?\\.loadPackage\\(varRequestID\\)\\s*,\\s*"
            . "Set\\(varRequestID,\"-1\"\\)\\s*\\)/s";
        $loadPackageReplacement = 'If(
    !IsBlank(Param("requestid")),
    Set(varRequestID, Substitute(Param("requestid"),"-", " - "));
    Set(varDeferredLoadPackage, true),
    Set(varRequestID,"-1")
)';
        $replaced = preg_replace($loadPackagePattern, $loadPackageReplacement, $new);
        if ($replaced !== null && $replaced !== $new) {
            $new = $replaced;
            $report->add(self::id(), $path, 'loadPackage', '(cross-screen from App)', 'varDeferredLoadPackage');
            $changed = true;
        } else {
            // Broader: any remaining host.loadPackage(...) from App → defer
            $broadPackage = "/(?:'(?:[^']|'')+'|[A-Za-z_][\\w]*)\\."
                . preg_quote(self::HOST_COMPONENT, '/')
                . "(?:_\\d+)?\\.loadPackage\\(([^)]*)\\)/";
            if (preg_match($broadPackage, $new)) {
                $new = preg_replace($broadPackage, 'Set(varDeferredLoadPackage, true)', $new) ?? $new;
                $report->add(self::id(), $path, 'loadPackage', '(cross-screen from App)', 'varDeferredLoadPackage');
                $changed = true;
            }
        }

        // If hosts were discovered but App still lacks deferred flags after a prior partial edit,
        // ensure inits exist whenever we changed anything (or when host screens exist and flags already referenced).
        if ($changed || ($hostScreens !== [] && (
            str_contains($new, 'varDeferredLoadUser') || str_contains($new, 'varDeferredLoadPackage')
        ))) {
            $new = $this->ensureDeferredVarInits($new);
        }

        return $new !== $formula ? $new : $formula;
    }

    private function ensureDeferredVarInits(string $onStart): string
    {
        $new = $onStart;
        // Match the false initializer specifically — Set(..., true) must not skip init.
        if (!preg_match('/Set\s*\(\s*varDeferredLoadUser\s*,\s*false\s*\)/', $new)) {
            $new = "Set(varDeferredLoadUser, false);\n" . $new;
        }
        if (!preg_match('/Set\s*\(\s*varDeferredLoadPackage\s*,\s*false\s*\)/', $new)) {
            $new = "Set(varDeferredLoadPackage, false);\n" . $new;
        }

        return $new;
    }

    private function ensureHostBootstrap(string $formula, string $path, Report $report): string
    {
        $marker = '/* ps-bootstrap:start */';
        if (str_contains($formula, $marker)) {
            return $formula;
        }

        $comp = self::HOST_COMPONENT;
        $block = $marker . ' '
            . "If(varDeferredLoadUser, {$comp}.loadUser(); Set(varDeferredLoadUser, false)); "
            . "If(varDeferredLoadPackage, {$comp}.loadPackage(varRequestID); Set(varDeferredLoadPackage, false)) "
            . '/* ps-bootstrap:end */';

        $trim = trim($formula);
        if ($trim === '' || $trim === '=') {
            $body = $block;
        } else {
            $body = str_starts_with($trim, '=') ? substr($trim, 1) : $trim;
            $body = rtrim($body, ';') . '; ' . $block;
        }

        $report->add(self::id(), $path, 'OnVisible', '(empty or existing)', 'deferred App bootstrap');
        return str_starts_with(trim($formula), '=') ? '=' . $body : $body;
    }

    private static function preview(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        return strlen($s) > 160 ? substr($s, 0, 157) . '...' : $s;
    }
}
