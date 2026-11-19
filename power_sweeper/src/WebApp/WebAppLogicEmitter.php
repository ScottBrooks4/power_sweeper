<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Emit maintainable native runtime + server modules from the conversion IR.
 *
 * Build-time IR is an input only. Shipped packages boot from HTML/CSS plus
 * `public/assets/app-logic.js`, native `handlers/<Screen>.js` functions, and
 * language modules under `server/generated/` — not a multi-megabyte JSON blob.
 */
final class WebAppLogicEmitter
{
    public function __construct(
        private readonly WebAppFxCompiler $fxCompiler = new WebAppFxCompiler(),
    ) {
    }

    /**
     * @param array<string, mixed> $ir
     * @return array<string, string> relative path => contents
     */
    public function emit(array $ir, string $runtime): array
    {
        $logic = $this->slimLogic($ir);
        // Handler / navigation emitters need the full screen trees + nav edges.
        $source = [
            'app' => is_array($ir['app'] ?? null) ? $ir['app'] : null,
            'screens' => is_array($ir['screens'] ?? null) ? $ir['screens'] : [],
            'navigation' => is_array($ir['navigation'] ?? null) ? $ir['navigation'] : [],
            'document' => is_array($ir['document'] ?? null) ? $ir['document'] : [],
        ];
        $collections = is_array($logic['collections'] ?? null) ? $logic['collections'] : [];
        $browserHandlers = $this->extractBrowserHandlers($logic);
        $collectionsJson = json_encode(
            $collections,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!is_string($collectionsJson)) {
            $collectionsJson = "{}";
        }
        // Browser app-logic: bindings only (collections + On* live in sibling assets).
        $browserLogic = $logic;
        $browserLogic['collections'] = new \stdClass();
        $json = json_encode($browserLogic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }
        // Language modules still see collection seed.
        $logic['collections'] = $collections;

        $handlerScreens = $browserHandlers['by_screen'];
        ksort($handlerScreens);
        $screenListJson = json_encode(array_keys($handlerScreens), JSON_UNESCAPED_SLASHES);
        if (!is_string($screenListJson)) {
            $screenListJson = '[]';
        }

        $files = [
            'public/assets/app-logic.json' => $json . "\n",
            'public/assets/app-logic.js' => "window.PS_APP = {$json};\n",
            // Thin index — per-screen modules live under public/assets/handlers/.
            'public/assets/handlers.js' => "window.PS_HANDLERS = window.PS_HANDLERS || {};\n"
                . "window.PS_HANDLER_SCREENS = {$screenListJson};\n",
            'public/assets/collections.json' => $collectionsJson . "\n",
            // Editable collection seed (language-neutral) — prefer this over digging in IR.
            'data/collections.json' => $collectionsJson . "\n",
        ];
        foreach ($handlerScreens as $stem => $controls) {
            $files['public/assets/handlers/' . $stem . '.js'] =
                $this->fxCompiler->emitJsModule(is_array($controls) ? $controls : []);
        }

        $runtime = WebAppIrBuilder::normalizeRuntime($runtime);
        if ($runtime === 'python') {
            $files['server/generated/app_data.py'] = $this->emitPython($logic);
            foreach ($this->emitPythonHandlerScreens($source) as $rel => $body) {
                $files[$rel] = $body;
            }
        } elseif ($runtime === 'csharp') {
            $files['Models/GeneratedAppData.cs'] = $this->emitCsharp($logic);
            $files['Models/GeneratedHandlers.cs'] = $this->emitCsharpHandlers($source);
            foreach ($this->emitCsharpHandlerScreens($source) as $rel => $body) {
                $files[$rel] = $body;
            }
        } else {
            $files['server/generated/AppData.php'] = $this->emitPhp($logic);
            foreach ($this->emitPhpHandlerScreens($source) as $rel => $body) {
                $files[$rel] = $body;
            }
            $files['server/generated/Handlers.php'] = $this->emitPhpHandlers($source);
            $files['server/generated/Navigation.php'] = $this->emitPhpNavigation($source + [
                'screens' => $logic['screens'] ?? [],
                'app' => $logic['app'] ?? null,
            ]);
        }

        $files['server/generated/README.md'] = $this->readme($runtime);

        return $files;
    }

    /**
     * @param array<string, mixed> $ir
     * @return array<string, mixed>
     */
    public function slimLogic(array $ir): array
    {
        $controls = [];
        $components = [];
        foreach (is_array($ir['components'] ?? null) ? $ir['components'] : [] as $name => $comp) {
            if (!is_array($comp)) {
                continue;
            }
            $compName = is_string($name) && $name !== '' ? $name : (string) ($comp['name'] ?? '');
            $this->collectControls($comp, $compName !== '' ? $compName : 'Component', $controls);
            // Component roots only — formulas like ExternalFunctions.refreshAppsSettings.
            $components[$compName] = [
                'name' => (string) ($comp['name'] ?? $compName),
                'role' => (string) ($comp['role'] ?? ''),
                'type' => (string) ($comp['type'] ?? ''),
                'properties' => $this->slimProperties(
                    is_array($comp['properties'] ?? null) ? $comp['properties'] : [],
                    (string) ($comp['name'] ?? $compName),
                    (string) ($comp['role'] ?? ''),
                ),
            ];
        }
        $screens = [];
        foreach (is_array($ir['screens'] ?? null) ? $ir['screens'] : [] as $screen) {
            if (!is_array($screen)) {
                continue;
            }
            $screenName = (string) ($screen['name'] ?? 'Screen');
            $this->collectControls($screen, $screenName, $controls);
            $screens[] = [
                'name' => $screenName,
                'role' => (string) ($screen['role'] ?? 'screen'),
                'type' => (string) ($screen['type'] ?? ''),
                'properties' => $this->slimProperties(
                    is_array($screen['properties'] ?? null) ? $screen['properties'] : [],
                    $screenName,
                    (string) ($screen['role'] ?? 'screen'),
                ),
            ];
        }
        $tables = [];
        foreach (is_array($ir['tables'] ?? null) ? $ir['tables'] : [] as $table) {
            if (!is_array($table)) {
                continue;
            }
            $tables[] = [
                'name' => $table['name'] ?? '',
                'safe_name' => $table['safe_name'] ?? '',
                'columns' => $table['columns'] ?? [],
            ];
        }
        $app = null;
        if (is_array($ir['app'] ?? null)) {
            $this->collectControls($ir['app'], 'App', $controls);
            $app = [
                'name' => (string) ($ir['app']['name'] ?? 'App'),
                'role' => (string) ($ir['app']['role'] ?? ''),
                'type' => (string) ($ir['app']['type'] ?? ''),
                'properties' => $this->slimProperties(
                    is_array($ir['app']['properties'] ?? null) ? $ir['app']['properties'] : [],
                    (string) ($ir['app']['name'] ?? 'App'),
                    (string) ($ir['app']['role'] ?? ''),
                ),
            ];
        }
        ksort($controls);

        return [
            'format' => 'power_sweeper_app_logic',
            // v2: flat controls map — HTML owns structure; JSON owns formulas only.
            'version' => 2,
            'document' => [
                'name' => (string) (($ir['document']['name'] ?? null) ?: 'Power Sweeper Web App'),
            ],
            'export' => is_array($ir['export'] ?? null) ? $ir['export'] : [],
            'app' => $app,
            'screens' => $screens,
            'components' => $components,
            'controls' => $controls,
            'collections' => is_array($ir['collections'] ?? null) ? $ir['collections'] : [],
            'tables' => $tables,
            // Navigation edges stay in server/generated/Navigation.php — not the browser bundle.
            'theme_tokens' => is_array($ir['theme_tokens'] ?? null) ? $ir['theme_tokens'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $controls
     */
    private function collectControls(array $node, string $screen, array &$controls): void
    {
        $name = (string) ($node['name'] ?? '');
        $props = $this->slimProperties(
            is_array($node['properties'] ?? null) ? $node['properties'] : [],
            $name,
            (string) ($node['role'] ?? ''),
        );
        if ($name !== '' && $props !== []) {
            $entry = [
                'properties' => $props,
                'role' => (string) ($node['role'] ?? ''),
                'type' => (string) ($node['type'] ?? ''),
                'screen' => $screen,
            ];
            if (isset($node['component']) && is_string($node['component']) && $node['component'] !== '') {
                $entry['component'] = $node['component'];
            }
            $controls[$name] = $entry;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $this->collectControls($child, $screen, $controls);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function slimNode(array $node): array
    {
        $out = [
            'name' => (string) ($node['name'] ?? ''),
            'role' => (string) ($node['role'] ?? ''),
            'type' => (string) ($node['type'] ?? ''),
            'properties' => $this->slimProperties(
                is_array($node['properties'] ?? null) ? $node['properties'] : [],
                (string) ($node['name'] ?? ''),
                (string) ($node['role'] ?? ''),
            ),
        ];
        if (isset($node['kind'])) {
            $out['kind'] = $node['kind'];
        }
        $kids = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $kids[] = $this->slimNode($child);
            }
        }
        if ($kids !== []) {
            $out['children'] = $kids;
        }

        return $out;
    }

    /**
     * Move On* formulas into per-screen handlers/*.js (and language modules).
     * App.OnStart stays on app so boot can run it without a second lookup.
     *
     * @param array<string, mixed> $logic
     * @return array{by_control: array<string, array<string, string>>, by_screen: array<string, array<string, array<string, string>>>}
     */
    private function extractBrowserHandlers(array &$logic): array
    {
        $byControl = [];
        $byScreen = [];
        $pull = function (array &$props, string $name, string $screen) use (&$byControl, &$byScreen): void {
            $stem = $this->screenFileStem($screen !== '' ? $screen : 'App');
            foreach (array_keys($props) as $key) {
                if (!is_string($key) || preg_match('/^On[A-Z]/', $key) !== 1) {
                    continue;
                }
                if ($name === 'App' && $key === 'OnStart') {
                    continue;
                }
                $fx = $props[$key];
                if (!is_string($fx) || trim($fx) === '' || trim($fx) === '=') {
                    unset($props[$key]);
                    continue;
                }
                $byControl[$name][$key] = $fx;
                $byScreen[$stem][$name][$key] = $fx;
                unset($props[$key]);
            }
        };

        if (is_array($logic['app']['properties'] ?? null)) {
            $pull($logic['app']['properties'], 'App', 'App');
        }
        foreach ($logic['screens'] ?? [] as $i => $screen) {
            if (!is_array($screen)) {
                continue;
            }
            $name = (string) ($screen['name'] ?? '');
            if ($name !== '' && is_array($logic['screens'][$i]['properties'] ?? null)) {
                $pull($logic['screens'][$i]['properties'], $name, $name);
            }
        }
        foreach ($logic['controls'] ?? [] as $name => $control) {
            if (!is_array($control) || !is_array($control['properties'] ?? null)) {
                continue;
            }
            $screen = (string) ($control['screen'] ?? 'App');
            $pull($logic['controls'][$name]['properties'], (string) $name, $screen);
        }
        ksort($byControl);
        ksort($byScreen);

        return ['by_control' => $byControl, 'by_screen' => $byScreen];
    }

    /**
     * Keep only runtime-behavioral properties. Geometry / paint tokens already live
     * in the generated HTML/CSS — shipping them again makes app-logic.js huge and
     * invites maintainers to edit the wrong artifact.
     *
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function slimProperties(array $properties, string $name, string $role): array
    {
        static $keep = null;
        if ($keep === null) {
            $keep = array_fill_keys([
                'OnSelect', 'OnChange', 'OnCheck', 'OnUncheck', 'OnVisible', 'OnStart',
                'OnTimerEnd', 'OnFailure', 'OnSuccess', 'OnHide',
                'Text', 'HtmlText', 'Tooltip', 'AccessibleLabel',
                'Visible', 'DisplayMode', 'Disabled', 'Checked', 'Default', 'DefaultDate',
                'DefaultSelectedItems', 'Selected', 'SelectedItems', 'Value',
                'Items', 'Item', 'Fields', 'DataSource',
                'Navigate', 'Transition',
                'MaxLength', 'Format', 'InputMode', 'HintText', 'Placeholder',
                'Minimum', 'Maximum', 'Step',
                'StartDate', 'EndDate', 'DateTimeZone',
                'ContentLanguage', 'TabIndex',
                'RadioValue', 'CheckboxValue',
                'Reset', 'Require', 'Required', 'IsRequired',
                'ValidationState', 'Valid',
            ], true);
        }

        $isAnchor = SectionAnchors::isSectionAnchorName($name)
            || strcasecmp($role, 'anchor') === 0
            || strcasecmp($role, 'section-anchor') === 0
            || preg_match('/^Anchor(Park|ParkContainer)?$/i', $name) === 1;

        $out = [];
        foreach ($properties as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            // Anchors are focus targets only — Text balloons flex gaps in flow/WET.
            if ($isAnchor && strcasecmp($key, 'Text') === 0) {
                continue;
            }
            if (!isset($keep[$key])) {
                // Keep unknown On* handlers and formula-looking strings.
                if (preg_match('/^On[A-Z]/', $key) === 1) {
                    $out[$key] = $value;
                }
                continue;
            }
            if ($value === null || $value === '' || $value === '=') {
                continue;
            }
            // Static labels / placeholders are already in the generated HTML.
            if (
                in_array($key, ['Text', 'HtmlText', 'Tooltip', 'AccessibleLabel', 'HintText', 'Placeholder'], true)
                && $this->isStaticFxString($value)
            ) {
                continue;
            }
            if ($key === 'TabIndex' && $this->isStaticFxNumber($value, -1)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function isStaticFxString(mixed $value): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }
        $s = ltrim(trim((string) $value), '=');
        if ($s === '') {
            return true;
        }

        return preg_match('/^"(?:\\\\.|[^"\\\\])*"$/', $s) === 1
            || preg_match("/^'(?:\\\\.|[^'\\\\])*'$/", $s) === 1;
    }

    private function isStaticFxNumber(mixed $value, int $want): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }
        $s = ltrim(trim((string) $value), '=');

        return is_numeric($s) && (int) $s === $want;
    }

    /** @param array<string, mixed> $logic */
    private function emitPhp(array $logic): string
    {
        $tables = var_export($logic['tables'] ?? [], true);
        $screens = [];
        foreach ($logic['screens'] ?? [] as $screen) {
            if (is_array($screen) && ($screen['name'] ?? '') !== '') {
                $screens[] = (string) $screen['name'];
            }
        }
        $screenExport = var_export($screens, true);
        $title = var_export((string) (($logic['document']['name'] ?? null) ?: 'App'), true);
        $fallbackCollections = var_export($logic['collections'] ?? [], true);

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Generated app data — prefer editing data/collections.json for collection seed.
 * Power Sweeper rewrites this file on the next Export to web.
 */
final class GeneratedAppData
{
    public static function title(): string
    {
        return {$title};
    }

    /** @return list<string> */
    public static function screens(): array
    {
        return {$screenExport};
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function collections(): array
    {
        \$path = dirname(__DIR__, 2) . '/data/collections.json';
        if (is_file(\$path)) {
            \$decoded = json_decode((string) file_get_contents(\$path), true);
            if (is_array(\$decoded)) {
                return \$decoded;
            }
        }

        return {$fallbackCollections};
    }

    /** @return list<array<string, mixed>> */
    public static function tables(): array
    {
        return {$tables};
    }
}

PHP;
    }

    /** @param array<string, mixed> $logic */
    private function emitPhpHandlers(array $logic): string
    {
        $byScreen = $this->handlersByScreen($logic);
        $requires = '';
        foreach (array_keys($byScreen) as $screen) {
            $file = $this->screenFileStem($screen);
            $requires .= "require_once __DIR__ . '/handlers/{$file}.php';\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Aggregates per-screen native handler modules under server/generated/handlers/.
 * Edit the screen file for the control you care about — not conversion JSON.
 */
{$requires}
final class GeneratedHandlers
{
    /** @return array<string, callable> */
    public static function all(): array
    {
        \$out = [];
        foreach (glob(__DIR__ . '/handlers/*.php') ?: [] as \$file) {
            \$class = 'GeneratedHandlers_' . basename(\$file, '.php');
            if (!class_exists(\$class, false)) {
                continue;
            }
            \$ref = new \\ReflectionClass(\$class);
            foreach (\$ref->getMethods(\\ReflectionMethod::IS_STATIC | \\ReflectionMethod::IS_PUBLIC) as \$method) {
                if (\$method->getDeclaringClass()->getName() !== \$class) {
                    continue;
                }
                \$out[\$class . '::' . \$method->getName()] = [\$class, \$method->getName()];
            }
        }

        return \$out;
    }
}

PHP;
    }

    /**
     * @param array<string, mixed> $logic
     * @return array<string, string> relative path => contents
     */
    private function emitPhpHandlerScreens(array $logic): array
    {
        $files = [];
        foreach ($this->handlersByScreen($logic) as $screen => $handlers) {
            $stem = $this->screenFileStem($screen);
            $class = 'GeneratedHandlers_' . $stem;
            $body = '';
            foreach ($handlers as $key => $fx) {
                if (!is_string($fx)) {
                    continue;
                }
                $method = $this->phpConstName($key);
                $compiled = $this->fxCompiler->compile($fx);
                $body .= "    /** {$key}"
                    . ($compiled['native'] ? '' : ' (residual Power Fx — prefer editing public/assets/handlers/)')
                    . " */\n"
                    . '    public static function ' . $method . "(): array\n"
                    . "    {\n"
                    . '        ' . $compiled['php'] . "\n"
                    . "    }\n\n";
            }
            $screenLit = var_export($screen, true);
            $files['server/generated/handlers/' . $stem . '.php'] = <<<PHP
<?php

declare(strict_types=1);

/**
 * Native handlers for screen {$screenLit}.
 * Mirror of public/assets/handlers/{$stem}.js — edit either; rebuild keeps them aligned.
 */
final class {$class}
{
{$body}}

PHP;
        }

        return $files;
    }

    /** @param array<string, mixed> $logic */
    private function emitPhpNavigation(array $logic): string
    {
        $nav = is_array($logic['navigation'] ?? null) ? $logic['navigation'] : [];
        $export = var_export($nav, true);
        $start = $this->resolveStartScreen($logic);

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Screen navigation map from the conversion (editable).
 */
final class GeneratedNavigation
{
    public static function startScreen(): string
    {
        return {$start};
    }

    /** @return list<array<string, mixed>>|array<string, mixed> */
    public static function map(): array
    {
        return {$export};
    }
}

PHP;
    }

    /** @param array<string, mixed> $logic */
    private function resolveStartScreen(array $logic): string
    {
        $appProps = is_array($logic['app']['properties'] ?? null) ? $logic['app']['properties'] : [];
        $raw = trim((string) ($appProps['StartScreen'] ?? ''));
        $raw = ltrim($raw, '=');
        if (preg_match('/^[\'"]([^\'"]+)[\'"]$/', $raw, $m) === 1
            && preg_match('/loading|not found|template/i', $m[1]) !== 1
        ) {
            return var_export($m[1], true);
        }
        // Web packages prefer a named home / form screen over Loading chrome.
        $screens = [];
        foreach ($logic['screens'] ?? [] as $screen) {
            if (is_array($screen) && ($screen['name'] ?? '') !== '') {
                $screens[] = (string) $screen['name'];
            }
        }
        foreach ($screens as $name) {
            if (preg_match('/home/i', $name) === 1) {
                return var_export($name, true);
            }
        }
        foreach ($screens as $name) {
            if (preg_match('/form/i', $name) === 1 && preg_match('/valid|template|loading/i', $name) !== 1) {
                return var_export($name, true);
            }
        }
        foreach ($screens as $name) {
            if (preg_match('/loading|emergency|not found|template/i', $name) !== 1) {
                return var_export($name, true);
            }
        }

        return var_export($screens[0] ?? '', true);
    }

    /** @param array<string, mixed> $logic */
    private function emitPython(array $logic): string
    {
        $tables = $this->pyLiteral($logic['tables'] ?? []);
        $screens = [];
        foreach ($logic['screens'] ?? [] as $screen) {
            if (is_array($screen) && ($screen['name'] ?? '') !== '') {
                $screens[] = (string) $screen['name'];
            }
        }
        $screenLit = $this->pyLiteral($screens);
        $title = $this->pyLiteral((string) (($logic['document']['name'] ?? null) ?: 'App'));
        $fallback = $this->pyLiteral($logic['collections'] ?? []);

        return <<<PY
"""Generated app data — prefer editing data/collections.json."""

from __future__ import annotations

import json
from pathlib import Path

TITLE = {$title}
SCREENS = {$screenLit}
TABLES = {$tables}
_FALLBACK_COLLECTIONS = {$fallback}


def collections() -> dict:
    path = Path(__file__).resolve().parents[2] / "data" / "collections.json"
    if path.is_file():
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            return data
    return _FALLBACK_COLLECTIONS


COLLECTIONS = collections()

PY;
    }

    /**
     * @param array<string, mixed> $logic
     * @return array<string, string>
     */
    private function emitPythonHandlerScreens(array $logic): array
    {
        $files = [];
        $all = [];
        foreach ($this->handlersByScreen($logic) as $screen => $handlers) {
            $stem = $this->screenFileStem($screen);
            $all[] = $stem;
            $lines = [
                '"""Native handlers for screen ' . $screen . '."""',
                '',
                'from __future__ import annotations',
                '',
                'from typing import Any',
                '',
            ];
            foreach ($handlers as $key => $fx) {
                if (!is_string($fx)) {
                    continue;
                }
                $fn = preg_replace('/\W+/', '_', $key) ?: 'handler';
                if ($fn !== '' && $fn[0] >= '0' && $fn[0] <= '9') {
                    $fn = 'h_' . $fn;
                }
                $compiled = $this->fxCompiler->compile($fx);
                $lines[] = 'def ' . $fn . '() -> dict[str, Any]:';
                $lines[] = '    """' . $key . '."""';
                $lines[] = '    ' . $compiled['python'];
                $lines[] = '';
            }
            $files['server/generated/handlers/' . $stem . '.py'] = implode("\n", $lines);
        }
        $init = [
            '"""Per-screen native handler modules. Edit the screen file, not conversion JSON."""',
            '',
            'from __future__ import annotations',
            '',
            'from importlib import import_module',
            '',
            'MODULES = [',
        ];
        foreach ($all as $stem) {
            $init[] = '    ' . $this->pyLiteral($stem) . ',';
        }
        $init[] = ']';
        $init[] = '';
        $init[] = 'for _name in MODULES:';
        $init[] = '    import_module("." + _name, __name__)';
        $init[] = '';
        $init[] = '__all__ = ["MODULES"]';
        $init[] = '';
        $files['server/generated/handlers/__init__.py'] = implode("\n", $init);

        return $files;
    }

    /** @param array<string, mixed> $logic */
    private function emitCsharp(array $logic): string
    {
        $title = $this->csString((string) (($logic['document']['name'] ?? null) ?: 'App'));
        $screenLines = '';
        foreach ($logic['screens'] ?? [] as $screen) {
            if (is_array($screen) && ($screen['name'] ?? '') !== '') {
                $screenLines .= '        ' . $this->csString((string) $screen['name']) . ",\n";
            }
        }

        return <<<CS
// Generated app data — edit screen list here. Collections live in data/ + store.
namespace PowerSweeper.WebApp.Generated;

public static class GeneratedAppData
{
    public const string Title = {$title};

    public static readonly string[] Screens = new[]
    {
{$screenLines}    };
}

CS;
    }

    /** @param array<string, mixed> $logic */
    private function emitCsharpHandlers(array $logic): string
    {
        $byScreen = $this->handlersByScreen($logic);
        $partials = '';
        foreach (array_keys($byScreen) as $screen) {
            $stem = $this->screenFileStem($screen);
            $partials .= "    // {$screen} → Handlers/{$stem}.cs\n";
        }

        return <<<CS
// Aggregates per-screen native handler modules under Models/Handlers/.
// Edit the screen file for the control you care about — not conversion JSON.
namespace PowerSweeper.WebApp.Generated;

public static partial class GeneratedHandlers
{
{$partials}}

CS;
    }

    /**
     * @param array<string, mixed> $logic
     * @return array<string, string>
     */
    private function emitCsharpHandlerScreens(array $logic): array
    {
        $files = [];
        foreach ($this->handlersByScreen($logic) as $screen => $handlers) {
            $stem = $this->screenFileStem($screen);
            $lines = [];
            foreach ($handlers as $key => $fx) {
                if (!is_string($fx)) {
                    continue;
                }
                $name = preg_replace('/\W+/', '_', $key) ?: 'Handler';
                if ($name !== '' && $name[0] >= '0' && $name[0] <= '9') {
                    $name = 'H_' . $name;
                }
                $compiled = $this->fxCompiler->compile($fx);
                $lines[] = '    // ' . $key;
                $lines[] = '    public static object ' . $name . '()';
                $lines[] = '    {';
                $lines[] = '        ' . $compiled['csharp'];
                $lines[] = '    }';
                $lines[] = '';
            }
            $body = implode("\n", $lines);
            $screenLit = $this->csString($screen);
            $files['Models/Handlers/' . $stem . '.cs'] = <<<CS
// Native handlers for screen {$screenLit}.
namespace PowerSweeper.WebApp.Generated;

public static partial class GeneratedHandlers
{
{$body}}

CS;
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $logic
     * @return array<string, string> "Screen.Control.OnSelect" => formula
     */
    private function collectHandlers(array $logic): array
    {
        $out = [];
        foreach ($this->handlersByScreen($logic) as $handlers) {
            foreach ($handlers as $key => $fx) {
                $out[$key] = $fx;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $logic
     * @return array<string, array<string, string>> screen => (key => formula)
     */
    private function handlersByScreen(array $logic): array
    {
        $by = [];
        $walk = function (array $node, string $screen) use (&$walk, &$by): void {
            $name = (string) ($node['name'] ?? '');
            $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            foreach (['OnSelect', 'OnChange', 'OnCheck', 'OnUncheck', 'OnVisible', 'OnStart'] as $evt) {
                $fx = trim((string) ($props[$evt] ?? ''));
                if ($fx === '' || $fx === '=') {
                    continue;
                }
                $key = ($screen !== '' ? $screen . '.' : '') . ($name !== '' ? $name : 'Control') . '.' . $evt;
                $by[$screen][$key] = $fx;
            }
            foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child, $screen);
                }
            }
        };
        if (is_array($logic['app'] ?? null)) {
            $walk($logic['app'], 'App');
        }
        foreach ($logic['screens'] ?? [] as $screen) {
            if (is_array($screen)) {
                $walk($screen, (string) ($screen['name'] ?? 'Screen'));
            }
        }
        ksort($by);

        return $by;
    }

    private function screenFileStem(string $screen): string
    {
        $stem = preg_replace('/\W+/', '_', $screen) ?: 'Screen';
        $stem = trim($stem, '_');
        if ($stem === '') {
            $stem = 'Screen';
        }
        if ($stem[0] >= '0' && $stem[0] <= '9') {
            $stem = 'S_' . $stem;
        }

        return $stem;
    }

    private function phpConstName(string $key): string
    {
        $s = strtoupper(preg_replace('/\W+/', '_', $key) ?: 'HANDLER');
        if ($s !== '' && $s[0] >= '0' && $s[0] <= '9') {
            $s = 'H_' . $s;
        }

        return $s;
    }

    private function pyLiteral(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return 'None';
        }
        // Prefer Python literals for null/bool/lists via json which is close enough.
        $json = str_replace(['null', 'true', 'false'], ['None', 'True', 'False'], $json);

        return $json;
    }

    private function csString(string $value): string
    {
        return '"' . addcslashes($value, "\\\"\n\r\t") . '"';
    }

    private function readme(string $runtime): string
    {
        $lang = match ($runtime) {
            'python' => 'Python (`app_data.py`, `handlers/*.py`)',
            'csharp' => 'C# (`GeneratedAppData.cs`, `GeneratedHandlers.cs`)',
            default => 'PHP (`AppData.php`, `handlers/*.php`, `Navigation.php`)',
        };

        return <<<MD
# Generated app modules

These files are the maintainable conversion output — native source, not an IR dump.

- **{$lang}** — per-screen native handler methods + screen list
- `public/assets/app.css` — shared chrome / WET / flow styles (one place for look-and-feel)
- `public/assets/generated.css` — per-app geometry + layout flavor (built from the canvas)
- `public/assets/app-logic.js` — binding props (Visible/Items/Default; HTML owns structure)
- `public/assets/handlers/<Screen>.js` — native `async` OnSelect/OnChange/… (mirrors server handlers)
- `public/assets/handlers.js` — screen list loader index
- `public/assets/collections.json` + `data/collections.json` — editable collection seed
- Data rows live in `data/app.xml` or `data/app.sqlite` / `data/schema.sql`
- PHP: `GET /api/data?action=meta` lists screens, handler modules, and data files

Import back to `.msapp` uses `import/power_sweeper_ir.json` when present (build artifact only).
MD;
    }
}
