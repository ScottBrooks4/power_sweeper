<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\PowerFxFormulaSegments;
use PowerSweeper\Report;

/**
 * Mechanical syntax and bootstrap repairs that are safe and idempotent (code only).
 */
final class RepairStudioSyntaxHop implements HopInterface
{
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
        foreach ($documents as $doc) {
            $doc->transformFormulas(function (string $formula, string $path) use ($report): string {
                $new = $this->repairFormula($formula, $path, $report);
                if ($this->isAppOnStartPath($path)) {
                    $new = $this->repairAppOnStart($new, $path, $report);
                }

                return $new;
            });
        }

        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->isApp()) {
                    $before = (string) ($control->getProperty('OnStart') ?? '');
                    $after = $this->repairAppOnStart($before, $control->path . '.OnStart', $report);
                    if ($after !== $before) {
                        $control->setProperty('OnStart', $after);
                    }
                }
                if ($control->name !== 'VASC Template Control Screen' || !$control->isScreen()) {
                    continue;
                }
                $before = (string) ($control->getProperty('OnVisible') ?? '');
                $after = $this->ensureVascBootstrap($before, $control->path, $report);
                if ($after !== $before) {
                    $control->setProperty('OnVisible', $after);
                }
            }
        }
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
        $out = PowerFxFormulaSegments::transformCode($formula, static function (string $code) use ($report, $path, &$changed): string {
            $new = $code;

            $replaced = preg_replace('/""\s*,\s*\)/', '"")', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'Concatenate', 'trailing ,)', '(fixed)');
                $new = $replaced;
                $changed = true;
            }

            $replaced = preg_replace('/\)\s*,\s*\)/', '))', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'Concatenate', 'trailing ),)', '(fixed)');
                $new = $replaced;
                $changed = true;
            }

            $replaced = preg_replace('/\bvarNewRequest\b/', 'false', $new);
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'varNewRequest', '(undefined)', 'false');
                $new = $replaced;
                $changed = true;
            }

            // 'VCR / VCN Form'.Date(1900,1,1) — screen-qualified Date *function* call
            $replaced = preg_replace(
                "/'(?:[^']|'')+'\\.Date\\s*\\(/",
                'Date(',
                $new
            );
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'Date()', '(screen-qualified function)', '(fixed)');
                $new = $replaced;
                $changed = true;
            }

            // Record literal keys: 'Screen Name'.VIP: loadedRequest.VIP → VIP: loadedRequest.VIP
            $replaced = preg_replace(
                "/'(?:[^']|'')+'\\.([A-Za-z_][\\w]*)\\s*:/",
                '$1:',
                $new
            );
            if ($replaced !== null && $replaced !== $new) {
                $report->add(self::id(), $path, 'record key', '(screen-qualified)', '(fixed)');
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

    private function repairAppOnStart(string $formula, string $path, Report $report): string
    {
        $new = $formula;
        $changed = false;

        if (preg_match("/'VASC Template Control Screen'\\.comExternalFunctions\\.loadUser\\(\\)\\s*;?/", $new)) {
            $new = preg_replace(
                "/'VASC Template Control Screen'\\.comExternalFunctions\\.loadUser\\(\\)\\s*;?\s*/",
                "Set(varDeferredLoadUser, true);\n",
                $new
            ) ?? $new;
            $report->add(self::id(), $path, 'loadUser', '(cross-screen from App)', 'varDeferredLoadUser');
            $changed = true;
        }

        $loadPackagePattern = "/If\\(\\s*!IsBlank\\(Param\\(\"requestid\"\\)\\)\\s*,\\s*Set\\(varRequestID, Substitute\\(Param\\(\"requestid\"\\),\"-\", \" - \"\\)\\)\\s*;\\s*'VASC Template Control Screen'\\.comExternalFunctions\\.loadPackage\\(varRequestID\\)\\s*,\\s*Set\\(varRequestID,\"-1\"\\)\\s*\\)/s";
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
        }

        if ($changed) {
            $new = $this->ensureDeferredVarInits($new);
        }

        return $new !== $formula ? $new : $formula;
    }

    private function ensureDeferredVarInits(string $onStart): string
    {
        $new = $onStart;
        if (!str_contains($new, 'Set(varDeferredLoadUser,')) {
            $new = "Set(varDeferredLoadUser, false);\n" . $new;
        }
        if (!str_contains($new, 'Set(varDeferredLoadPackage,')) {
            $new = "Set(varDeferredLoadPackage, false);\n" . $new;
        }

        return $new;
    }

    private function ensureVascBootstrap(string $formula, string $path, Report $report): string
    {
        $marker = '/* ps-bootstrap:start */';
        if (str_contains($formula, $marker)) {
            return $formula;
        }

        $block = $marker . ' '
            . 'If(varDeferredLoadUser, comExternalFunctions.loadUser(); Set(varDeferredLoadUser, false)); '
            . 'If(varDeferredLoadPackage, comExternalFunctions.loadPackage(varRequestID); Set(varDeferredLoadPackage, false)) '
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
}
