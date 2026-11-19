<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Run sweeper's styles_to_classes_full profile so packaged pages use classes, not inline styles.
 */
final class SweeperStyles
{
    public function __construct(private readonly ?string $sweeperHome = null)
    {
    }

    public function home(): string
    {
        $env = getenv('SWEEPER_HOME');
        if (is_string($env) && $env !== '' && is_dir($env)) {
            return rtrim($env, '/\\');
        }
        if ($this->sweeperHome !== null && $this->sweeperHome !== '' && is_dir($this->sweeperHome)) {
            return rtrim($this->sweeperHome, '/\\');
        }

        return '/srv/http/sweeper';
    }

    public function available(): bool
    {
        $home = $this->home();

        return is_file($home . '/retidy.php')
            && is_file($home . '/profiles/styles_to_classes_full.php');
    }

    /**
     * Convert inline styles in HTML into class attributes + stylesheet rules.
     *
     * Falls back to a built-in deduplicating pass when sweeper is unavailable.
     *
     * @return array{html:string, used_sweeper:bool, class_count:int}
     */
    public function convert(string $html): array
    {
        if ($this->available()) {
            $result = $this->runSweeper($html);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->fallbackConvert($html);
    }

    /** @return array{html:string, used_sweeper:bool, class_count:int}|null */
    private function runSweeper(string $html): ?array
    {
        $home = $this->home();
        $tmp = sys_get_temp_dir() . '/ps_sweeper_' . bin2hex(random_bytes(4));
        if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
            return null;
        }
        $src = $tmp . '/in.html';
        $dst = $tmp . '/out.html';
        file_put_contents($src, $html);

        $script = <<<'PHP'
<?php
$home = $argv[1];
$src = $argv[2];
$dst = $argv[3];
chdir($home);
include $home . '/retidy.php';
$profile = include $home . '/profiles/styles_to_classes_full.php';
$code = file_get_contents($src);
if ($code === false) {
    fwrite(STDERR, "unreadable\n");
    exit(2);
}
$O = new ReTidy();
$O->set_config($profile);
$O->code = $code;
$O->saved_template = $code;
foreach ($profile['macro'] as $step) {
    if (!is_string($step) || $step === '') {
        continue;
    }
    if (!method_exists($O, $step)) {
        fwrite(STDERR, "missing method $step\n");
        exit(3);
    }
    $O->{$step}();
}
if (!empty($profile['make_new_classes'])) {
    if (method_exists($O, 'exhaustive_DOM_styles_to_classes_full')) {
        // Already covered when profile uses DOM_styles_to_classes_full + make_new via post steps.
    }
    if (method_exists($O, 'DOM_make_new_classes')) {
        if (method_exists($O, 'dom_init')) {
            $O->dom_init();
        }
        $O->DOM_make_new_classes();
        if (method_exists($O, 'dom_save')) {
            $O->dom_save();
        }
        if (method_exists($O, 'post_dom_stripme')) {
            $O->post_dom_stripme();
        } elseif (method_exists($O, 'post_dom')) {
            $O->post_dom();
        }
    }
}
file_put_contents($dst, $O->code);
PHP;
        $runner = $tmp . '/run.php';
        file_put_contents($runner, $script);
        $cmd = 'php ' . escapeshellarg($runner) . ' '
            . escapeshellarg($home) . ' '
            . escapeshellarg($src) . ' '
            . escapeshellarg($dst) . ' 2>&1';
        $out = shell_exec($cmd);
        $converted = is_file($dst) ? (string) file_get_contents($dst) : '';
        $this->rmTree($tmp);
        if ($converted === '' || !str_contains($converted, '<')) {
            return null;
        }
        // Strip leftover stripme markers if post_dom did not run.
        $converted = preg_replace('/\sstyle="XXX9o9stripme9o9XXX"/i', '', $converted) ?? $converted;
        $converted = preg_replace('/\sstyle="stripme"/i', '', $converted) ?? $converted;
        $converted = $this->mergeClassAttributes($converted);
        preg_match_all('/\bclass="/i', $converted, $m);

        return [
            'html' => $converted,
            'used_sweeper' => true,
            'class_count' => count($m[0] ?? []),
        ];
    }

    /**
     * Built-in: collect identical style= blobs into .ps_cN classes.
     *
     * @return array{html:string, used_sweeper:bool, class_count:int}
     */
    public function fallbackConvert(string $html): array
    {
        $styles = [];
        $html2 = preg_replace_callback(
            '/\sstyle="([^"]*)"/i',
            static function (array $m) use (&$styles): string {
                $css = trim($m[1]);
                if ($css === '') {
                    return '';
                }
                if (!isset($styles[$css])) {
                    $styles[$css] = 'ps_c' . (count($styles) + 1);
                }

                return ' class="' . $styles[$css] . '"';
            },
            $html,
        );
        if (!is_string($html2)) {
            $html2 = $html;
        }

        $html2 = $this->mergeClassAttributes(is_string($html2) ? $html2 : $html);

        $rules = '';
        foreach ($styles as $css => $className) {
            $rules .= '.' . $className . ' { ' . rtrim($css, ';') . '; }' . "\n";
        }
        if ($rules !== '') {
            if (str_contains($html2, '</head>')) {
                $html2 = str_replace('</head>', "<style id=\"ps-generated\">\n{$rules}</style>\n</head>", $html2);
            } else {
                $html2 = "<style id=\"ps-generated\">\n{$rules}</style>\n" . $html2;
            }
        }

        return [
            'html' => $html2,
            'used_sweeper' => false,
            'class_count' => count($styles),
        ];
    }

    /**
     * Sweeper (and the fallback) may emit a second class= on the same tag.
     * HTML keeps only one class attribute, so geometry classes must be merged.
     */
    public function mergeClassAttributes(string $html): string
    {
        $merged = preg_replace_callback(
            '/<[^>]+>/',
            static function (array $m): string {
                $tag = $m[0];
                if (preg_match_all('/\sclass="([^"]*)"/i', $tag, $cm) < 2) {
                    return $tag;
                }
                $classes = trim(implode(' ', $cm[1]));
                $tag = preg_replace('/\sclass="[^"]*"/i', '', $tag) ?? $tag;

                return preg_replace('/\s*(\/?)>$/', ' class="' . $classes . '"$1>', $tag) ?? $tag;
            },
            $html,
        );

        return is_string($merged) ? $merged : $html;
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
