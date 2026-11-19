<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Compile common Power Fx event bodies into native JS / PHP / Python / C#.
 *
 * Complex formulas fall back to a runtime Fx bridge (browser) or a documented
 * formula constant (server) so packages stay editable as real source files.
 */
final class WebAppFxCompiler
{
    /**
     * @return array{native:bool, js:string, php:string, python:string, csharp:string, fx:string}
     */
    public function compile(string $fx): array
    {
        $raw = $fx;
        $body = $this->normalize($fx);
        if ($body === '') {
            return $this->fallback($raw, '// no-op');
        }

        $stmts = $this->splitStatements($body);
        $jsParts = [];
        $phpParts = [];
        $pyParts = [];
        $csParts = [];
        foreach ($stmts as $stmt) {
            $compiled = $this->compileStatement($stmt);
            if ($compiled === null) {
                return $this->fallback($raw, $body);
            }
            $jsParts[] = $compiled['js'];
            $phpParts[] = $compiled['php'];
            $pyParts[] = $compiled['python'];
            $csParts[] = $compiled['csharp'];
        }

        $js = implode("\n    ", array_filter($jsParts, static fn ($s) => $s !== ''));
        $php = implode("\n        ", array_filter($phpParts, static fn ($s) => $s !== ''));
        $python = implode("\n    ", array_filter($pyParts, static fn ($s) => $s !== ''));
        $csharp = implode("\n        ", array_filter($csParts, static fn ($s) => $s !== ''));
        if ($php !== '' && !str_contains($php, 'return ')) {
            $php .= "\n        return [];";
        }
        if ($python !== '' && !str_contains($python, 'return ')) {
            $python .= "\n    return {}";
        }
        if ($csharp !== '' && !str_contains($csharp, 'return ')) {
            $csharp .= "\n        return null;";
        }

        return [
            'native' => true,
            'js' => $js !== '' ? $js : 'return undefined;',
            'php' => $php !== '' ? $php : 'return [];',
            'python' => $python !== '' ? $python : 'return {}',
            'csharp' => $csharp !== '' ? $csharp : 'return null;',
            'fx' => $raw,
        ];
    }

    /**
     * Emit a browser handler module: real async functions, not Fx JSON dumps.
     *
     * @param array<string, array<string, string>> $controls control => event => fx
     */
    public function emitJsModule(array $controls): string
    {
        ksort($controls);
        $lines = [
            '(function () {',
            '  "use strict";',
            '  var H = (window.PS_HANDLERS = window.PS_HANDLERS || {});',
        ];
        foreach ($controls as $control => $events) {
            if (!is_array($events) || $events === []) {
                continue;
            }
            ksort($events);
            $lines[] = '  H[' . $this->jsString($control) . '] = Object.assign(H[' . $this->jsString($control) . '] || {}, {';
            $eventLines = [];
            foreach ($events as $event => $fx) {
                if (!is_string($event) || !is_string($fx)) {
                    continue;
                }
                $compiled = $this->compile($fx);
                if ($compiled['native']) {
                    // Sync function so Select(Control) chains (OnStart → LoadAppSettings) still work.
                    $eventLines[] = '    ' . $this->jsKey($event) . ': function () {'
                        . "\n      " . $compiled['js']
                        . "\n    }";
                } else {
                    // Residual Power Fx string — runtime interpreter; keeps sync Select() working.
                    $eventLines[] = '    ' . $this->jsKey($event) . ': ' . $this->jsString($fx);
                }
            }
            $lines[] = implode(",\n", $eventLines);
            $lines[] = '  });';
        }
        $lines[] = '})();';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function normalize(string $fx): string
    {
        $s = trim($fx);
        $s = ltrim($s, '=');
        // Drop block comments then line comments.
        $s = preg_replace('/\/\*[\s\S]*?\*\//', '', $s) ?? $s;
        $lines = preg_split('/\R/', $s) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = preg_replace('/\/\/.*$/', '', $line) ?? $line;
            if (trim($trimmed) !== '') {
                $kept[] = $trimmed;
            }
        }

        return trim(implode("\n", $kept));
    }

    /** @return list<string> */
    private function splitStatements(string $body): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote && ($i === 0 || $body[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === '(' || $ch === '{' || $ch === '[') {
                $depth++;
                $buf .= $ch;
                continue;
            }
            if ($ch === ')' || $ch === '}' || $ch === ']') {
                $depth = max(0, $depth - 1);
                $buf .= $ch;
                continue;
            }
            if ($ch === ';' && $depth === 0) {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $parts[] = $stmt;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $tail = trim($buf);
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return $parts;
    }

    /**
     * @return array{js:string,php:string,python:string,csharp:string}|null
     */
    private function compileStatement(string $stmt): ?array
    {
        $stmt = trim($stmt);
        if ($stmt === '') {
            return ['js' => '', 'php' => '', 'python' => '', 'csharp' => ''];
        }

        if (preg_match(
            '/^Navigate\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*ScreenTransition\.\w+\s*)?\)$/i',
            $stmt,
            $m,
        ) === 1) {
            $screen = $m[1];

            return [
                'js' => 'return window.PS.navigate(' . $this->jsString($screen) . ');',
                'php' => 'return ["navigate" => ' . var_export($screen, true) . '];',
                'python' => 'return {"navigate": ' . $this->pyString($screen) . '}',
                'csharp' => 'return new { navigate = ' . $this->csString($screen) . ' };',
            ];
        }

        if (preg_match('/^Reset\s*\(\s*([A-Za-z_][\w]*)\s*\)$/i', $stmt, $m) === 1) {
            $name = $m[1];

            return [
                'js' => 'window.PS.reset(' . $this->jsString($name) . ');',
                'php' => '// reset ' . $name,
                'python' => '# reset ' . $name,
                'csharp' => '// reset ' . $name,
            ];
        }

        if (preg_match('/^Select\s*\(\s*Parent\s*\)$/i', $stmt) === 1) {
            return [
                'js' => 'return window.PS.selectParent();',
                'php' => 'return ["selectParent" => true];',
                'python' => 'return {"select_parent": True}',
                'csharp' => 'return new { selectParent = true };',
            ];
        }

        if (preg_match('/^Select\s*\(\s*([A-Za-z_][\w]*)\s*\)$/i', $stmt, $m) === 1) {
            $name = $m[1];

            return [
                'js' => 'return window.PS.selectControl(' . $this->jsString($name) . ');',
                'php' => 'return ["select" => ' . var_export($name, true) . '];',
                'python' => 'return {"select": ' . $this->pyString($name) . '}',
                'csharp' => 'return new { select = ' . $this->csString($name) . ' };',
            ];
        }

        if (preg_match('/^Set\s*\(\s*([A-Za-z_][\w]*)\s*,\s*(.+)\s*\)$/is', $stmt, $m) === 1) {
            $var = $m[1];
            $value = trim($m[2]);
            $jsVal = $this->compileValueJs($value);
            if ($jsVal === null) {
                return null;
            }
            $phpVal = $this->compileValuePhp($value);
            $pyVal = $this->compileValuePython($value);
            $csVal = $this->compileValueCsharp($value);
            if ($phpVal === null || $pyVal === null || $csVal === null) {
                return null;
            }

            return [
                'js' => 'window.PS.set(' . $this->jsString($var) . ', ' . $jsVal . ');',
                'php' => '// Set(' . $var . ', …)',
                'python' => '# Set(' . $var . ', …)',
                'csharp' => '// Set(' . $var . ', …)',
            ];
        }

        if (preg_match('/^UpdateContext\s*\(\s*\{\s*([A-Za-z_][\w]*)\s*:\s*(.+?)\s*\}\s*\)$/is', $stmt, $m) === 1) {
            $var = $m[1];
            $jsVal = $this->compileValueJs(trim($m[2]));
            if ($jsVal === null) {
                return null;
            }

            return [
                'js' => 'window.PS.set(' . $this->jsString($var) . ', ' . $jsVal . ');',
                'php' => '// UpdateContext({' . $var . '})',
                'python' => '# UpdateContext({' . $var . '})',
                'csharp' => '// UpdateContext({' . $var . '})',
            ];
        }

        return null;
    }

    private function compileValueJs(string $value): ?string
    {
        $value = trim($value);
        if (strcasecmp($value, 'true') === 0) {
            return 'true';
        }
        if (strcasecmp($value, 'false') === 0) {
            return 'false';
        }
        if (strcasecmp($value, 'Blank()') === 0 || strcasecmp($value, 'blank') === 0) {
            return 'null';
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^"(?:\\\\.|[^"\\\\])*"$/', $value) === 1
            || preg_match("/^'(?:\\\\.|[^'\\\\])*'$/", $value) === 1) {
            $inner = substr($value, 1, -1);

            return $this->jsString(stripcslashes($inner));
        }
        // Toggle / simple var reference: !varX or varX
        if (preg_match('/^!\s*([A-Za-z_][\w]*)$/', $value, $m) === 1) {
            return '!window.PS.get(' . $this->jsString($m[1]) . ')';
        }
        if (preg_match('/^([A-Za-z_][\w]*)$/', $value, $m) === 1) {
            return 'window.PS.get(' . $this->jsString($m[1]) . ')';
        }
        if (preg_match('/^Today\s*\(\s*\)$/i', $value) === 1) {
            return 'new Date()';
        }

        return null;
    }

    private function compileValuePhp(string $value): ?string
    {
        return $this->compileValueJs($value) !== null ? 'null' : null;
    }

    private function compileValuePython(string $value): ?string
    {
        return $this->compileValueJs($value) !== null ? 'None' : null;
    }

    private function compileValueCsharp(string $value): ?string
    {
        return $this->compileValueJs($value) !== null ? 'null' : null;
    }

    /**
     * @return array{native:bool, js:string, php:string, python:string, csharp:string, fx:string}
     */
    private function fallback(string $raw, string $body): array
    {
        return [
            'native' => false,
            'js' => 'return fx(' . $this->jsString($raw) . ');',
            'php' => 'return ["fx" => ' . var_export($raw, true) . '];',
            'python' => 'return {"fx": ' . $this->pyString($raw) . '}',
            'csharp' => 'return new { fx = ' . $this->csString($raw) . ' };',
            'fx' => $raw,
        ];
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }

    private function jsKey(string $key): string
    {
        if (preg_match('/^[A-Za-z_][\w]*$/', $key) === 1) {
            return $key;
        }

        return $this->jsString($key);
    }

    private function pyString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }

    private function csString(string $value): string
    {
        return '"' . addcslashes($value, "\\\"\n\r\t") . '"';
    }
}
