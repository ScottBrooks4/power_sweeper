<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

require_once dirname(__DIR__, 2) . '/templates/web_package/php/MailAllowlist.php';

/**
 * Build a self-contained web package from the conversion file.
 *
 * Language: php, python, or csharp.
 * Storage: xml or sql.
 * Layout: mvc, canvas, responsive, or wet (Web Experience Toolkit).
 */
final class WebAppPackageBuilder
{
    public function __construct(
        private readonly SweeperStyles $sweeper = new SweeperStyles(),
        private readonly WebAppPixelRenderer $renderer = new WebAppPixelRenderer(),
        private readonly WebAppFlowLayout $flowLayout = new WebAppFlowLayout(),
        private readonly WebAppLogicEmitter $logicEmitter = new WebAppLogicEmitter(),
    ) {
    }

    /**
     * @param array<string, mixed> $ir
     * @return array{ok:bool, path?:string, message:string, used_sweeper?:bool, inline_styles?:int}
     */
    public function build(array $ir, string $outputZip, ?string $extractDir = null): array
    {
        $export = is_array($ir['export'] ?? null) ? $ir['export'] : [];
        $runtime = WebAppIrBuilder::normalizeRuntime((string) ($export['runtime'] ?? 'php'));
        $storage = WebAppIrBuilder::normalizeStorage((string) ($export['storage'] ?? 'xml'));
        $layout = WebAppIrBuilder::normalizeLayout((string) ($export['layout'] ?? 'canvas'));
        $flow = WebAppFlowLayout::isFlowLayout($layout);

        $staging = sys_get_temp_dir() . '/ps_pkg_' . bin2hex(random_bytes(4));
        if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
            return ['ok' => false, 'message' => 'failed to create staging dir'];
        }

        try {
            $this->writeTree($staging, $ir, $runtime, $storage, $layout, $extractDir);
            $intermediate = $this->renderer->renderIntermediate($ir, $flow);
            $converted = $this->sweeper->convert($intermediate);
            $html = $converted['html'];
            $inline = preg_match_all('/\sstyle="/i', $html) ?: 0;
            if ($inline > 0) {
                $forced = $this->sweeper->fallbackConvert($html);
                $html = $forced['html'];
                $inline = preg_match_all('/\sstyle="/i', $html) ?: 0;
            }
            if ($flow) {
                $html = $this->flowLayout->apply($html);
            }
            $html = $this->ensureAppLogicScript($html);
            if ($layout === 'wet') {
                $html = $this->wrapWetShell($html, $ir);
            } else {
                $html = $this->tagLayout($html, $layout);
            }
            // Pull geometry + theme <style> blocks into one editable CSS file.
            $extracted = $this->extractGeneratedCss($html, $layout);
            $html = $extracted['html'];
            if ($layout === 'mvc') {
                // Keep the painted canvas HTML as the document — MVC is structure + M365 backend,
                // not a bare link list.
                $this->writeMvc($staging, $html, $runtime, $ir);
            }

            $public = $staging . '/public';
            if (!is_dir($public)) {
                mkdir($public, 0775, true);
            }
            file_put_contents($public . '/index.html', $html);
            file_put_contents($public . '/assets/generated.css', $extracted['css']);
            $this->copySharedAssets($public);
            $this->seedData($staging, $ir, $storage);

            $dir = dirname($outputZip);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $this->zipDir($staging, $outputZip);

            // Also mirror into the .msapp WebApp/ folder when unpacking in-place.
            if ($extractDir !== null && $extractDir !== '' && is_dir($extractDir)) {
                $webDir = $extractDir . '/WebApp';
                if (!is_dir($webDir)) {
                    mkdir($webDir, 0775, true);
                }
                $pkgCopy = $webDir . '/package.zip';
                copy($outputZip, $pkgCopy);
                file_put_contents($webDir . '/package_meta.json', json_encode([
                    'runtime' => $runtime,
                    'storage' => $storage,
                    'layout' => $layout,
                    'generated_at' => gmdate('c'),
                ], JSON_PRETTY_PRINT) . "\n");
            }

            return [
                'ok' => true,
                'path' => $outputZip,
                'message' => sprintf(
                    'package %s/%s layout=%s (%d classes, inline=%d)',
                    $runtime,
                    $storage,
                    $layout,
                    (int) ($converted['class_count'] ?? 0),
                    $inline,
                ),
                'used_sweeper' => (bool) ($converted['used_sweeper'] ?? false),
                'inline_styles' => $inline,
            ];
        } finally {
            $this->rmTree($staging);
        }
    }

    /**
     * @param array<string, mixed> $ir
     */
    private function writeTree(string $staging, array $ir, string $runtime, string $storage, string $layout, ?string $extractDir): void
    {
        foreach (['public/assets/media', 'import', 'data', 'azure', 'server'] as $rel) {
            $path = $staging . '/' . $rel;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        // Build artifact for Import web package → .msapp only. The running app
        // never loads this — maintainers edit HTML/CSS/handlers/server code.
        file_put_contents(
            $staging . '/import/power_sweeper_ir.json',
            json_encode($ir, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );

        $templates = dirname(__DIR__, 2) . '/templates/web_package';
        $this->writeFile($staging . '/public/assets/app.css', (string) file_get_contents($templates . '/assets/app.css'));
        $this->writeFile($staging . '/public/assets/runtime.js', (string) file_get_contents($templates . '/assets/runtime.js'));
        $this->writeFile($staging . '/azure/startup.sh', (string) file_get_contents($templates . '/azure/startup.sh'));
        chmod($staging . '/azure/startup.sh', 0755);

        $config = [
            'runtime' => $runtime,
            'storage' => $storage,
            'layout' => $layout,
            'lom_home' => getenv('LOM_HOME') ?: '/srv/http/LOM',
            'sql_dsn' => getenv('SQL_DSN') ?: '',
            'mail_delivery' => getenv('MAIL_DELIVERY') ?: 'outbox',
            'mail_unlisted' => getenv('MAIL_UNLISTED') ?: 'rewrite',
            'mail_allowlist' => \MailAllowlist::defaultAddresses(),
        ];
        if ($layout === 'mvc') {
            $config['auth'] = [
                'mode' => getenv('AAD_AUTH_MODE') ?: 'demo',
                'tenant' => getenv('AAD_TENANT') ?: 'common',
                'client_id' => getenv('AAD_CLIENT_ID') ?: '',
            ];
            $config['graph'] = [
                'mode' => getenv('GRAPH_MODE') ?: 'outbox',
                'scopes' => [
                    'User.Read',
                    'Mail.Send',
                    'Calendars.ReadWrite',
                    'Files.ReadWrite.All',
                    'Sites.ReadWrite.All',
                ],
                'service_account' => [
                    'enabled' => true,
                    'mode' => 'app_only',
                    'resource' => 'https://graph.microsoft.com',
                ],
            ];
        }
        file_put_contents(
            $staging . '/server/config.json',
            json_encode($config, JSON_PRETTY_PRINT) . "\n",
        );

        if ($runtime === 'php') {
            $this->writeFile($staging . '/index.php', (string) file_get_contents($templates . '/php/index.php'));
            $this->writeFile($staging . '/router.php', (string) file_get_contents($templates . '/php/router.php'));
            $this->writeFile($staging . '/server/api.php', (string) file_get_contents($templates . '/php/api.php'));
            $this->writeFile($staging . '/server/LomStore.php', (string) file_get_contents($templates . '/php/LomStore.php'));
            $this->writeFile($staging . '/server/SqlStore.php', (string) file_get_contents($templates . '/php/SqlStore.php'));
            $this->writeFile($staging . '/server/TableMap.php', (string) file_get_contents($templates . '/php/TableMap.php'));
            $this->writeFile($staging . '/server/MailAllowlist.php', (string) file_get_contents($templates . '/php/MailAllowlist.php'));
            $this->writeFile($staging . '/server/MailGateway.php', (string) file_get_contents($templates . '/php/MailGateway.php'));
            $this->writeFile($staging . '/server/StoreSeed.php', (string) file_get_contents($templates . '/php/StoreSeed.php'));
            $this->writeFile($staging . '/server/XmlStore.php', (string) file_get_contents($templates . '/php/XmlStore.php'));
            $this->writeFile($staging . '/server/GraphGateway.php', (string) file_get_contents($templates . '/php/GraphGateway.php'));
        } elseif ($runtime === 'python') {
            $this->writeFile($staging . '/app.py', (string) file_get_contents($templates . '/python/app.py'));
            $this->writeFile($staging . '/server/store.py', (string) file_get_contents($templates . '/python/store.py'));
            $this->writeFile($staging . '/requirements.txt', "No external packages required for the bundled local SQL path.\n");
        } else {
            $csharp = $templates . '/csharp';
            $this->writeFile($staging . '/Program.cs', (string) file_get_contents($csharp . '/Program.cs'));
            $this->writeFile($staging . '/Store.cs', (string) file_get_contents($csharp . '/Store.cs'));
            $this->writeFile($staging . '/App.csproj', (string) file_get_contents($csharp . '/App.csproj'));
            $this->writeFile($staging . '/Controllers/ScreenController.cs', (string) file_get_contents($csharp . '/Controllers/ScreenController.cs'));
            $this->writeFile($staging . '/Controllers/AuthController.cs', (string) file_get_contents($csharp . '/Controllers/AuthController.cs'));
            $this->writeFile($staging . '/Controllers/GraphController.cs', (string) file_get_contents($csharp . '/Controllers/GraphController.cs'));
            $this->writeFile($staging . '/Models/AppModel.cs', (string) file_get_contents($csharp . '/Models/AppModel.cs'));
            $this->writeFile($staging . '/Models/GraphModel.cs', (string) file_get_contents($csharp . '/Models/GraphModel.cs'));
        }

        file_put_contents($staging . '/README.md', $this->readme($runtime, $storage, $layout));
        foreach ($this->logicEmitter->emit($ir, $runtime) as $rel => $contents) {
            $this->writeFile($staging . '/' . $rel, $contents);
        }
        $this->copyMedia($staging, $ir, $extractDir);
    }

    /** Ensure the browser loads generated app-logic + handlers before runtime.js. */
    private function ensureAppLogicScript(string $html): string
    {
        $tags = '<script src="assets/app-logic.js"></script>'
            . "\n  <script src=\"assets/handlers.js\"></script>";
        if (str_contains($html, 'assets/handlers.js') && str_contains($html, 'assets/app-logic.js')) {
            return $html;
        }
        if (str_contains($html, 'assets/app-logic.js') && !str_contains($html, 'assets/handlers.js')) {
            return str_replace(
                '<script src="assets/app-logic.js"></script>',
                $tags,
                $html,
            );
        }
        if (str_contains($html, 'assets/runtime.js')) {
            return str_replace(
                '<script src="assets/runtime.js"></script>',
                $tags . "\n  <script src=\"assets/runtime.js\"></script>",
                $html,
            );
        }
        if (str_contains($html, '</body>')) {
            return str_replace('</body>', $tags . "\n</body>", $html);
        }

        return $html . "\n" . $tags;
    }

    /**
     * @param array<string, mixed> $ir
     */
    private function copyMedia(string $staging, array $ir, ?string $extractDir): void
    {
        if ($extractDir === null || $extractDir === '' || !is_dir($extractDir)) {
            return;
        }
        $dest = $staging . '/public/assets/media';
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        foreach (is_array($ir['media'] ?? null) ? $ir['media'] : [] as $res) {
            if (!is_array($res)) {
                continue;
            }
            $rel = str_replace('\\', '/', (string) ($res['path'] ?? ''));
            $src = $extractDir . '/' . $rel;
            if (!is_file($src)) {
                $file = (string) ($res['file'] ?? '');
                $alt = $extractDir . '/Assets/Images/' . $file;
                $src = is_file($alt) ? $alt : '';
            }
            if ($src === '' || !is_file($src)) {
                continue;
            }
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION) ?: 'png');
            $safe = preg_replace('/[^\w.-]+/', '_', (string) ($res['name'] ?? 'media')) ?: 'media';
            copy($src, $dest . '/' . $safe . '.' . $ext);
        }
    }

    private function copySharedAssets(string $public): void
    {
        $assets = $public . '/assets';
        if (!is_dir($assets)) {
            mkdir($assets, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $ir
     */
    private function seedData(string $staging, array $ir, string $storage): void
    {
        $tables = is_array($ir['tables'] ?? null) ? $ir['tables'] : [];
        if ($storage !== 'sql') {
            $xml = "<app>\n";
            foreach ($tables as $table) {
                if (!is_array($table)) {
                    continue;
                }
                $tName = htmlspecialchars((string) ($table['safe_name'] ?? $table['name'] ?? 'Items'), ENT_QUOTES);
                $xml .= "  <{$tName}>\n";
                foreach ($table['rows'] ?? [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $xml .= "    <row";
                    foreach ($row as $k => $v) {
                        $xml .= ' ' . preg_replace('/\W+/', '_', (string) $k)
                            . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"';
                    }
                    $xml .= ">";
                    $title = (string) ($row['Title'] ?? $row['title'] ?? '');
                    $xml .= htmlspecialchars($title, ENT_QUOTES) . "</row>\n";
                }
                $xml .= "  </{$tName}>\n";
            }
            $xml .= "</app>\n";
            file_put_contents($staging . '/data/app.xml', $xml);
            return;
        }

        $schema = "-- Generated by Power Sweeper\n";
        foreach ($tables as $table) {
            if (!is_array($table)) {
                continue;
            }
            $tName = preg_replace('/\W+/', '_', (string) ($table['safe_name'] ?? $table['name'] ?? 'Items')) ?: 'Items';
            $cols = is_array($table['columns'] ?? null) ? $table['columns'] : ['Id', 'Title', 'Value'];
            $colDefs = [];
            $seenCols = [];
            $insertCols = [];
            foreach ($cols as $col) {
                $c = preg_replace('/\W+/', '_', (string) $col) ?: 'col';
                $key = strtolower($c);
                if (isset($seenCols[$key])) {
                    continue;
                }
                $seenCols[$key] = true;
                $insertCols[] = [$col, $c];
                $colDefs[] = $key === 'id' ? 'Id INTEGER PRIMARY KEY' : $c . ' TEXT';
            }
            $schema .= 'CREATE TABLE IF NOT EXISTS ' . $tName . ' (' . implode(', ', $colDefs) . ");\n";
            foreach ($table['rows'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $names = [];
                $vals = [];
                foreach ($insertCols as [$col, $c]) {
                    $names[] = $c;
                    $v = $row[$col] ?? $row[$c] ?? '';
                    $vals[] = "'" . str_replace("'", "''", (string) $v) . "'";
                }
                $schema .= 'INSERT INTO ' . $tName . ' (' . implode(', ', $names) . ') VALUES ('
                    . implode(', ', $vals) . ");\n";
            }
        }
        file_put_contents($staging . '/data/schema.sql', $schema);

        $dbPath = $staging . '/data/app.sqlite';
        if (class_exists(\PDO::class)) {
            try {
                $pdo = new \PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->exec($schema);
            } catch (\Throwable) {
                // schema.sql alone is enough for Python sqlite3 to apply on first boot
            }
        }
    }

    private function readme(string $runtime, string $storage, string $layout): string
    {
        $run = match ($runtime) {
            'python' => "python3 app.py\n",
            'csharp' => "dotnet run\n",
            default => "php -S localhost:8080 index.php\n",
        };
        $data = $storage === 'sql'
            ? "Uses local SQL (`data/app.sqlite`). Set `SQL_DSN` for another SQL engine.\n"
            : "Uses an XML document (`data/app.xml`).\n";
        $pages = match ($layout) {
            'mvc' => "MVC: same painted screens as canvas, organized as Controllers / Models / Views with Azure AD + Microsoft Graph hooks (`/auth/*`, `/api/graph/*`).\n",
            'responsive' => "Responsive web: Power Apps X/Y/Width/Height are removed so controls flow with the device width.\n",
            'wet' => "WET (Web Experience Toolkit / GCWeb): Canada.ca static header/footer + WET-BOEW CDN assets; Power Apps fixed geometry is stripped so content flows in the main column.\n",
            default => "Canvas: controls keep their Power Apps X/Y/Width/Height.\n",
        };

        $m365 = $layout === 'mvc'
            ? "\n## M365 / Graph\n\n"
                . "- `GET /auth/login` — Azure AD / OpenID Connect (demo mode signs in a local M365 user)\n"
                . "- `GET /api/graph/status` — tenant, scopes, signed-in user\n"
                . "- `POST /api/graph/mail` — send mail via Graph (or allowlisted outbox in demo mode)\n"
                . "- `GET /api/graph/calendar` / `files` — Graph calendar and drive stubs\n"
                . "- `POST /api/graph/serviceAccount` — app-only token path for SharePoint / CRM automation\n"
                . "- Set `auth.mode=live` + `auth.client_id` / `auth.tenant`, and `graph.mode=live` + `GRAPH_ACCESS_TOKEN` for a real tenant\n"
            : '';

        return "# Power Sweeper Web Package\n\n"
            . "Language: **{$runtime}**  \n"
            . "Storage: **{$storage}**  \n"
            . "Layout: **{$layout}**\n\n"
            . "## Run\n\n```bash\n{$run}```\n\n"
            . $data
            . $pages
            . $m365
            . "\n## Maintainable sources (native code — not IR)\n\n"
            . "- `public/assets/app.css` — shared chrome / flow / WET styles\n"
            . "- `public/assets/generated.css` — per-app geometry + layout flavor\n"
            . "- `public/assets/app-logic.js` — bindings (Visible/Items/Default)\n"
            . "- `public/assets/handlers/<Screen>.js` — native OnSelect/OnChange/… (browser)\n"
            . "- `server/generated/handlers/` — same events as editable language source\n"
            . "- `data/collections.json` + `data/app.xml` or `data/app.sqlite` — editable data\n"
            . "- `import/power_sweeper_ir.json` — build artifact for Import → `.msapp` only\n"
            . "- PHP: `GET /api/data?action=meta` — screens, handler modules, data files\n";
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $contents);
    }

    private function tagLayout(string $html, string $layout): string
    {
        $class = 'layout-' . $layout;
        if (preg_match('/<html\b[^>]*\bclass="/i', $html) === 1) {
            $html = preg_replace(
                '/(<html\b[^>]*\bclass=")([^"]*)(")/i',
                '$1$2 ' . $class . '$3',
                $html,
                1,
            ) ?? $html;
        } else {
            $html = preg_replace(
                '/<html\b([^>]*)>/',
                '<html$1 class="' . $class . '">',
                $html,
                1,
            ) ?? $html;
        }
        if (!str_contains($html, 'data-layout=')) {
            $html = preg_replace(
                '/<html\b/',
                '<html data-layout="' . $layout . '"',
                $html,
                1,
            ) ?? $html;
        }
        // generated.css (geometry + layout flavor) is linked by extractGeneratedCss().
        return $html;
    }

    /**
     * Wrap painted app content in the GCWeb / WET-BOEW static shell (CDN assets).
     *
     * @param array<string, mixed> $ir
     */
    private function wrapWetShell(string $html, array $ir): string
    {
        $shellPath = dirname(__DIR__, 2) . '/templates/web_package/wet/shell.html';
        $shell = (string) file_get_contents($shellPath);
        $title = (string) (($ir['document']['name'] ?? null) ?: 'Power Sweeper Web App');
        if (preg_match('/<title>([^<]*)<\/title>/i', $html, $tm)) {
            $fromHtml = trim(html_entity_decode($tm[1], ENT_QUOTES));
            if ($fromHtml !== '') {
                $title = $fromHtml;
            }
        }
        $headExtras = '';
        if (preg_match_all('/<style\b[^>]*>.*?<\/style>/is', $html, $styles)) {
            $headExtras .= implode("\n", $styles[0]);
        }
        $appRoot = $this->extractAppRoot($html);
        if ($appRoot === '') {
            $appRoot = '<div id="app-root" class="app-root"></div>';
        }
        $bodyScripts = '';
        if (preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $html, $scripts)) {
            foreach ($scripts[0] as $script) {
                if (str_contains($script, 'wet-boew') || str_contains($script, 'jquery')
                    || str_contains($script, 'theme.min.js')) {
                    continue;
                }
                $bodyScripts .= $script . "\n";
            }
        }
        $date = gmdate('Y-m-d');

        return str_replace(
            [
                '{{TITLE}}',
                '{{DESCRIPTION}}',
                '{{HEAD_EXTRAS}}',
                '{{APP_ROOT}}',
                '{{DATE_MODIFIED}}',
                '{{BODY_SCRIPTS}}',
            ],
            [
                htmlspecialchars($title, ENT_QUOTES),
                htmlspecialchars($title . ' — converted with Power Sweeper (WET / GCWeb)', ENT_QUOTES),
                $headExtras,
                $appRoot,
                $date,
                $bodyScripts,
            ],
            $shell,
        );
    }

    /**
     * Move inline <style> blocks into public/assets/generated.css and link it once.
     * Layout flavor rules append here so look-and-feel lives in CSS files, not HTML.
     *
     * @return array{html:string,css:string}
     */
    private function extractGeneratedCss(string $html, string $layout): array
    {
        $chunks = [];
        $html = preg_replace_callback(
            '/<style\b[^>]*>.*?<\/style>/is',
            static function (array $m) use (&$chunks): string {
                $inner = preg_replace('/^<style\b[^>]*>|<\/style>$/i', '', $m[0]) ?? '';
                $inner = trim($inner);
                if ($inner !== '') {
                    $chunks[] = $inner;
                }

                return '';
            },
            $html,
        ) ?? $html;

        $layoutCss = trim($this->layoutCss($layout));
        $css = "/* Per-app generated styles + layout={$layout}. Edit freely; rebuild overwrites. */\n\n";
        if ($chunks !== []) {
            $css .= "/* --- from canvas / sweeper --- */\n" . implode("\n\n", $chunks) . "\n\n";
        }
        if ($layoutCss !== '') {
            $css .= "/* --- layout flavor --- */\n" . $layoutCss . "\n";
        }

        $link = '<link rel="stylesheet" href="assets/generated.css">';
        if (!str_contains($html, 'assets/generated.css')) {
            if (str_contains($html, 'assets/app.css')) {
                $html = preg_replace(
                    '/(<link\b[^>]*href="assets\/app\.css"[^>]*>)/i',
                    '$1' . "\n  " . $link,
                    $html,
                    1,
                ) ?? ($html . $link);
            } elseif (str_contains($html, '</head>')) {
                $html = str_replace('</head>', "  {$link}\n</head>", $html);
            } else {
                $html = $link . "\n" . $html;
            }
        }
        // Drop obsolete layout.css links from older templates.
        $html = preg_replace('/\s*<link\b[^>]*href="assets\/layout\.css"[^>]*>\s*/i', "\n", $html) ?? $html;

        return ['html' => $html, 'css' => $css];
    }

    private function extractAppRoot(string $html): string
    {
        if (preg_match('/<div\b[^>]*\bid="app-root"[^>]*>/i', $html, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $start = (int) $open[0][1];
        $pos = $start + strlen($open[0][0]);
        $depth = 1;
        $len = strlen($html);
        while ($pos < $len && $depth > 0) {
            $nextOpen = stripos($html, '<div', $pos);
            $nextClose = stripos($html, '</div>', $pos);
            if ($nextClose === false) {
                return '';
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $pos = $nextOpen + 4;
                continue;
            }
            $depth--;
            $pos = $nextClose + 6;
            if ($depth === 0) {
                return substr($html, $start, $pos - $start);
            }
        }

        return '';
    }

    private function layoutCss(string $layout): string
    {
        if ($layout === 'responsive') {
            return <<<'CSS'
/* Responsive web: flow layout — Power Apps geometry already stripped at export.
 * Keep the scrollport on html only so position:sticky (Submit / tabs) can pin. */
html.layout-responsive {
  overflow-x: hidden !important;
  overflow-y: scroll !important;
  height: auto !important;
  background: #e8eaee;
}
html.layout-responsive body {
  overflow: visible !important;
  height: auto !important;
  background: #e8eaee;
}
html.layout-responsive .app-root,
html.layout-responsive .screen {
  width: 100%;
  max-width: 100%;
  height: auto !important;
  min-height: 0;
  overflow: visible !important;
  position: relative;
  box-shadow: none;
}
html.layout-responsive .screen {
  display: none;
  padding: 0.75rem 1rem 2rem;
}
html.layout-responsive .screen.screen--active,
html.layout-responsive .screen.active {
  display: block;
}
html.layout-responsive .ctrl {
  max-width: 100%;
  height: auto;
}
html.layout-responsive .ctrl--label,
html.layout-responsive .ctrl--html {
  white-space: normal;
}
html.layout-responsive img,
html.layout-responsive svg {
  max-width: 100%;
  height: auto;
}
html.layout-responsive .ctrl--media {
  flex: 0 0 auto;
  max-width: 100%;
}
html.layout-responsive .ctrl--media img {
  width: auto;
  height: auto;
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
/* Compact flow chrome: sidebar icons as a horizontal rail, not a tall stack. */
html.layout-responsive [data-control="SidebarMenu_1"],
html.layout-responsive [data-control="SidebarCont"] {
  width: auto !important;
  max-width: 100%;
  height: auto !important;
  min-height: 0 !important;
  margin: 0.25rem 0 !important;
}
html.layout-responsive [data-control="SidebarGallery"] {
  display: flex !important;
  flex-direction: row !important;
  flex-wrap: wrap;
  gap: 0.5rem;
  width: auto !important;
  max-width: 100%;
  height: auto !important;
  min-height: 0 !important;
}
html.layout-responsive [data-control="SidebarGallery"] > * {
  width: 2.5rem !important;
  height: 2.5rem !important;
  max-width: 2.5rem !important;
  flex: 0 0 2.5rem;
}
html.layout-responsive [data-control="HamburgerSpacer"],
html.layout-responsive [data-control="HamburgerSpacerInner"],
html.layout-responsive [data-control="SidebarBottomLine"] {
  display: none !important;
}
html.layout-responsive [data-control="NavTabsCont"],
html.layout-responsive [data-control="FormNavTabs"] {
  position: sticky;
  top: 3.75rem; /* under sticky Submit bar */
  z-index: 20;
  background: #fff;
}
html.layout-responsive [data-control="ScrollableForm"],
html.layout-responsive [data-control="EmerScrollableForm"] {
  height: auto !important;
  max-height: none !important;
  min-height: 0 !important;
  overflow: visible !important;
}
html.layout-responsive [data-control="1_Destination"],
html.layout-responsive [data-control="2_Requesting"],
html.layout-responsive [data-control="3_TypeOfVCR"],
html.layout-responsive [data-control="4_SecurityClassification"],
html.layout-responsive [data-control="5_Summary"],
html.layout-responsive [data-control="6_Date"],
html.layout-responsive [data-control="6_Dates"],
html.layout-responsive [data-control="7_Initiative"],
html.layout-responsive [data-control="8_Pertinence"],
html.layout-responsive [data-control="9_SecurityOfficer"],
html.layout-responsive [data-control="10_Remarks"],
html.layout-responsive [data-control="Annex1_Sites"],
html.layout-responsive [data-control="Annex2_Visitors"] {
  height: auto !important;
  min-height: 0 !important;
  margin: 0 0 1.25rem !important;
  padding: 0.75rem 0 !important;
}
html.layout-responsive [data-control="1_Destination"] {
  min-height: 0 !important;
  padding: 1rem !important;
}
CSS;
        }
        if ($layout === 'wet') {
            return <<<'CSS'
/* WET / GCWeb: app content flows inside the Canada.ca main column.
 * Scrollport must stay on html — body overflow:auto traps position:sticky. */
html.layout-wet {
  overflow-x: hidden !important;
  overflow-y: scroll !important;
  height: auto !important;
}
html.layout-wet body {
  overflow: visible !important;
  height: auto !important;
}
html.layout-wet .app-root,
html.layout-wet .screen {
  width: 100%;
  max-width: 100%;
  height: auto !important;
  min-height: 0;
  margin: 0;
  overflow: visible !important;
  position: relative;
  box-shadow: none;
  background: transparent;
}
html.layout-wet .screen {
  display: none;
  padding: 0 0 1.5rem;
}
html.layout-wet .screen.screen--active,
html.layout-wet .screen.active {
  display: block;
}
html.layout-wet .ctrl {
  max-width: 100%;
  height: auto;
}
html.layout-wet .ctrl--label,
html.layout-wet .ctrl--html {
  white-space: normal;
}
html.layout-wet .ctrl--button,
html.layout-wet button.ctrl {
  border-radius: 0;
}
html.layout-wet img,
html.layout-wet svg {
  max-width: 100%;
  height: auto;
}
html.layout-wet .ctrl--section-anchor {
  scroll-margin-top: 4.5rem;
}
html.layout-wet .ctrl--media {
  flex: 0 0 auto;
  max-width: 100%;
}
html.layout-wet .ctrl--media img {
  width: auto;
  height: auto;
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
html.layout-wet [data-control="SidebarMenu_1"],
html.layout-wet [data-control="SidebarCont"] {
  width: auto !important;
  max-width: 100%;
  height: auto !important;
  min-height: 0 !important;
  margin: 0.25rem 0 !important;
}
html.layout-wet [data-control="SidebarGallery"] {
  display: flex !important;
  flex-direction: row !important;
  flex-wrap: wrap;
  gap: 0.5rem;
  width: auto !important;
  max-width: 100%;
  height: auto !important;
  min-height: 0 !important;
}
html.layout-wet [data-control="SidebarGallery"] > * {
  width: 2.5rem !important;
  height: 2.5rem !important;
  max-width: 2.5rem !important;
  flex: 0 0 2.5rem;
}
html.layout-wet [data-control="HamburgerSpacer"],
html.layout-wet [data-control="HamburgerSpacerInner"],
html.layout-wet [data-control="SidebarBottomLine"] {
  display: none !important;
}
html.layout-wet [data-control="NavTabsCont"],
html.layout-wet [data-control="FormNavTabs"] {
  position: sticky;
  top: 3.75rem; /* under sticky Submit bar */
  z-index: 20;
  background: #fff;
}
html.layout-wet [data-control="ScrollableForm"],
html.layout-wet [data-control="EmerScrollableForm"] {
  height: auto !important;
  max-height: none !important;
  min-height: 0 !important;
  overflow: visible !important;
}
html.layout-wet [data-control="1_Destination"],
html.layout-wet [data-control="2_Requesting"],
html.layout-wet [data-control="3_TypeOfVCR"],
html.layout-wet [data-control="4_SecurityClassification"],
html.layout-wet [data-control="5_Summary"],
html.layout-wet [data-control="6_Date"],
html.layout-wet [data-control="6_Dates"],
html.layout-wet [data-control="7_Initiative"],
html.layout-wet [data-control="8_Pertinence"],
html.layout-wet [data-control="9_SecurityOfficer"],
html.layout-wet [data-control="10_Remarks"],
html.layout-wet [data-control="Annex1_Sites"],
html.layout-wet [data-control="Annex2_Visitors"] {
  height: auto !important;
  min-height: 0 !important;
  margin: 0 0 1.25rem !important;
  padding: 0.75rem 0 !important;
}
html.layout-wet [data-control="1_Destination"] {
  min-height: 0 !important;
  padding: 1rem !important;
}
html.layout-wet [data-control="VCRHoldingContainer"],
html.layout-wet [data-control="HoldingContainer_6"] {
  height: auto !important;
  min-height: 0 !important;
  overflow: visible !important;
}
CSS;
        }
        if ($layout === 'mvc') {
            return <<<'CSS'
/* MVC uses the same painted geometry as canvas; chrome is auth / Graph only. */
html.layout-mvc .app-root { margin: 0 auto; }
html.layout-mvc .mvc-auth-chip {
  position: fixed;
  top: 0.65rem;
  right: 0.75rem;
  z-index: 10000;
  font: 0.82rem/1.2 system-ui, sans-serif;
  background: color-mix(in srgb, #0b1f33 88%, transparent);
  color: #f4f7fb;
  padding: 0.35rem 0.7rem;
  border-radius: 999px;
  text-decoration: none;
  box-shadow: 0 2px 10px rgba(0,0,0,0.18);
}
html.layout-mvc .mvc-auth-chip a { color: #9ec5ff; }
html.layout-mvc .mvc-auth-chip--in { background: color-mix(in srgb, #0f3d2e 90%, transparent); }
CSS;
        }

        return "/* Canvas: design X/Y stay on the page. */\nhtml.layout-canvas .app-root { margin: 0 auto; }\n";
    }

    /**
     * @param array<string, mixed> $ir
     */
    private function writeMvc(string $staging, string $html, string $runtime, array $ir): void
    {
        foreach (['views', 'controllers', 'models'] as $rel) {
            $dir = $staging . '/' . $rel;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $start = '';
        if (preg_match('/data-start-screen="([^"]+)"/', $html, $sm)) {
            $start = $sm[1];
        }

        if (preg_match_all('/<section\b[^>]*\bdata-screen="([^"]+)"[^>]*>.*?<\/section>/s', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $screenName = $match[1];
                $fileStem = preg_replace('/[^\w.-]+/', '_', $screenName) ?: 'Screen';
                $active = ($start !== '' && $screenName === $start) || ($start === '' && $match === $matches[0]);
                $section = $match[0];
                if ($active && !str_contains($section, 'screen--active')) {
                    $section = preg_replace('/\bclass="/', 'class="screen--active ', $section, 1) ?? $section;
                }
                $title = htmlspecialchars($screenName, ENT_QUOTES);
                $view = <<<HTML
<!DOCTYPE html>
<html lang="en" class="layout-mvc" data-layout="mvc">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="assets/generated.css">
</head>
<body>
  <div id="app-root" class="app-root" data-start-screen="{$title}">
    {$section}
  </div>
  <script src="assets/app-logic.js"></script>
  <script src="assets/handlers.js"></script>
  <script src="assets/runtime.js"></script>
</body>
</html>
HTML;
                file_put_contents($staging . '/views/' . $fileStem . '.html', $view);
            }
        }

        $nav = '';
        foreach (is_array($ir['screens'] ?? null) ? $ir['screens'] : [] as $screen) {
            if (!is_array($screen)) {
                continue;
            }
            $name = (string) ($screen['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $nav .= '- [' . $name . '](?screen=' . rawurlencode($name) . ")\n";
        }
        file_put_contents(
            $staging . '/views/README.md',
            "# Views\n\nOne HTML view per screen. The live app serves `public/index.html` through\n"
            . "`controllers/screen.php` so layout, Patch/save, and Navigate match canvas.\n\n"
            . "Screens:\n{$nav}",
        );

        $templates = dirname(__DIR__, 2) . '/templates/web_package';
        if ($runtime === 'python') {
            $this->writeFile($staging . '/controllers/screen_controller.py', (string) file_get_contents($templates . '/python/screen_controller.py'));
            $this->writeFile($staging . '/controllers/auth_controller.py', (string) file_get_contents($templates . '/python/auth_controller.py'));
            $this->writeFile($staging . '/controllers/graph_controller.py', (string) file_get_contents($templates . '/python/graph_controller.py'));
            $this->writeFile($staging . '/models/app_model.py', (string) file_get_contents($templates . '/python/app_model.py'));
            $this->writeFile($staging . '/models/graph_model.py', (string) file_get_contents($templates . '/python/graph_model.py'));
            $this->writeFile($staging . '/server/graph_gateway.py', (string) file_get_contents($templates . '/python/graph_gateway.py'));
        } elseif ($runtime === 'csharp') {
            $this->writeFile($staging . '/Controllers/AuthController.cs', (string) file_get_contents($templates . '/csharp/Controllers/AuthController.cs'));
            $this->writeFile($staging . '/Controllers/GraphController.cs', (string) file_get_contents($templates . '/csharp/Controllers/GraphController.cs'));
            $this->writeFile($staging . '/Models/GraphModel.cs', (string) file_get_contents($templates . '/csharp/Models/GraphModel.cs'));
            // ScreenController.cs and AppModel.cs are copied for every C# package.
        } else {
            $this->writeFile($staging . '/controllers/screen.php', (string) file_get_contents($templates . '/php/screen.php'));
            $this->writeFile($staging . '/controllers/AuthController.php', (string) file_get_contents($templates . '/php/AuthController.php'));
            $this->writeFile($staging . '/controllers/GraphController.php', (string) file_get_contents($templates . '/php/GraphController.php'));
            $this->writeFile($staging . '/models/AppModel.php', (string) file_get_contents($templates . '/php/AppModel.php'));
            $this->writeFile($staging . '/models/GraphModel.php', (string) file_get_contents($templates . '/php/GraphModel.php'));
        }
    }

    private function zipDir(string $dir, string $zipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip: ' . $zipPath);
        }
        $dir = realpath($dir) ?: $dir;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $local = ltrim(str_replace($dir, '', $full), '/\\');
            $zip->addFile($full, str_replace('\\', '/', $local));
        }
        $zip->close();
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
