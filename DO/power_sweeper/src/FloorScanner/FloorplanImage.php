<?php

declare(strict_types=1);

namespace PowerSweeper\FloorScanner;

/**
 * Load a floorplan from PNG/JPEG/GIF/WebP or SVG into GD.
 */
final class FloorplanImage
{
    /** Soft work-cap for detection. Shared hosts OOM/503 near 4000px. */
    private const MAX_EDGE = 2000;

    public static function maxEdge(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $bytes = self::iniBytes((string) ini_get('memory_limit'));
        // PHP int masks ≈ 16B/px × ~8 layers. Stay inside typical 256–512M live limits.
        if ($bytes > 0 && $bytes < 400 * 1024 * 1024) {
            return $cached = 1500;
        }
        if ($bytes > 0 && $bytes < 900 * 1024 * 1024) {
            return $cached = 1800;
        }

        return $cached = self::MAX_EDGE;
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $n = (float) $value;

        return (int) match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @return array{image: \GdImage, width: int, height: int, svg: ?string}
     */
    public static function loadBlob(string $blob, string $filename = ''): array
    {
        $blob = self::stripBom($blob);
        if ($blob === '') {
            throw new \RuntimeException('Floorplan is empty');
        }
        if (self::looksLikeSvg($blob, $filename)) {
            return self::loadSvg($blob);
        }
        $im = @imagecreatefromstring($blob);
        if (!$im instanceof \GdImage) {
            throw new \RuntimeException('Could not read floorplan (use PNG, JPEG, WebP, GIF, or SVG)');
        }

        return self::packImage($im, null);
    }

    public static function looksLikeSvg(string $blob, string $filename = ''): bool
    {
        if (preg_match('/\\.svgz?$/i', $filename) === 1) {
            return true;
        }
        $start = ltrim($blob);
        return str_starts_with($start, '<svg')
            || str_starts_with($start, '<?xml')
            || str_contains(substr($start, 0, 400), '<svg');
    }

    /**
     * @return array{image: \GdImage, width: int, height: int, svg: string}
     */
    private static function loadSvg(string $svg): array
    {
        $embedded = self::extractEmbeddedRaster($svg);
        if ($embedded instanceof \GdImage) {
            $embedded = self::normalizeEmbeddedToSvgSize($embedded, $svg);
            return self::packImage($embedded, $svg);
        }

        $via = self::rasterizeSvgExternal($svg);
        if ($via instanceof \GdImage) {
            return self::packImage($via, $svg);
        }

        $basic = self::rasterizeSvgShapes($svg);
        if ($basic instanceof \GdImage) {
            return self::packImage($basic, $svg);
        }

        throw new \RuntimeException('Could not read this SVG. Try a PNG, or an SVG with an embedded image.');
    }

    /**
     * Synoptic-style SVGs often embed a higher-res tracing WebP than their CSS size
     * (e.g. 1024px image in a 676px SVG). Mild oversample breaks residential door-sealing;
     * match the CSS size so uploads match the PNG sample. Heavy oversample (office CAD)
     * keeps detail up to MAX_EDGE.
     */
    private static function normalizeEmbeddedToSvgSize(\GdImage $im, string $svg): \GdImage
    {
        if (!preg_match('/<svg\b[^>]*>/i', $svg, $tag)) {
            return $im;
        }
        $head = $tag[0];
        $cssW = self::svgLength($head, 'width', 0);
        $cssH = self::svgLength($head, 'height', 0);
        if ($cssW < 80 || $cssH < 80) {
            return $im;
        }
        $embW = imagesx($im);
        $embH = imagesy($im);
        if ($embW < 80 || $embH < 80) {
            return $im;
        }
        $over = max($embW / $cssW, $embH / $cssH);
        if ($over <= 1.05) {
            return $im;
        }
        // Mild oversample: use the SVG's display size (same as typical PNG export).
        if ($over <= 2.25) {
            return self::resizeImage($im, $cssW, $cssH);
        }
        // Heavy tracing oversample: keep detail, still capped by packImage/MAX_EDGE.
        return $im;
    }

    private static function resizeImage(\GdImage $im, int $nw, int $nh): \GdImage
    {
        $nw = max(80, $nw);
        $nh = max(80, $nh);
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w === $nw && $h === $nh) {
            return $im;
        }
        $out = imagecreatetruecolor($nw, $nh);
        if (!$out instanceof \GdImage) {
            return $im;
        }
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);

        return $out;
    }

    /**
     * @return array{image: \GdImage, width: int, height: int, svg: ?string}
     */
    private static function packImage(\GdImage $im, ?string $svg): array
    {
        // Cap the longest edge. Synoptic SVGs embed ~6k tracing rasters; running
        // detection at native size blows past shared-host ~30s / memory → HTTP 503.
        $im = self::fitMaxEdge($im, self::maxEdge());

        return [
            'image' => $im,
            'width' => imagesx($im),
            'height' => imagesy($im),
            'svg' => $svg,
        ];
    }

    /**
     * Shrink so max(width, height) ≤ $maxEdge; no-op if already small enough.
     */
    public static function fitMaxEdge(\GdImage $im, int $maxEdge): \GdImage
    {
        $maxEdge = max(80, $maxEdge);
        $w = imagesx($im);
        $h = imagesy($im);
        $long = max($w, $h);
        if ($long <= $maxEdge) {
            return $im;
        }
        $scale = $maxEdge / $long;
        $nw = max(80, (int) round($w * $scale));
        $nh = max(80, (int) round($h * $scale));

        return self::resizeImage($im, $nw, $nh);
    }

    private static function extractEmbeddedRaster(string $svg): ?\GdImage
    {
        if (!preg_match('/href=["\']data:image\\/(png|jpeg|jpg|webp|gif);base64,([^"\']+)["\']/i', $svg, $m)) {
            return null;
        }
        $raw = base64_decode(preg_replace('/\\s+/', '', $m[2]) ?? '', true);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $im = @imagecreatefromstring($raw);
        return $im instanceof \GdImage ? $im : null;
    }

    /**
     * Native embedded tracing image (no CSS downscale) — used for OCR.
     */
    public static function extractEmbeddedImage(string $svg): ?\GdImage
    {
        return self::extractEmbeddedRaster($svg);
    }

    /**
     * Best-effort work size for ETA: viewBox / CSS / embed, capped like detection.
     *
     * @return array{width:int,height:int,source:string}
     */
    public static function probeWorkSize(string $blob, string $filename = ''): array
    {
        $blob = self::stripBom($blob);
        if (self::looksLikeSvg($blob, $filename)) {
            if (preg_match('/<svg\\b[^>]*>/i', $blob, $tag)) {
                $head = $tag[0];
                $cssW = self::svgLength($head, 'width', 0);
                $cssH = self::svgLength($head, 'height', 0);
                $vw = 0;
                $vh = 0;
                if (preg_match('/viewBox=["\']\\s*([0-9.]+)\\s+([0-9.]+)\\s+([0-9.]+)\\s+([0-9.]+)/i', $head, $vb)) {
                    $vw = max(1, (int) round((float) $vb[3]));
                    $vh = max(1, (int) round((float) $vb[4]));
                }
                $emb = self::extractEmbeddedRaster($blob);
                if ($emb instanceof \GdImage) {
                    $ew = imagesx($emb);
                    $eh = imagesy($emb);
                    imagedestroy($emb);
                    $w = $ew;
                    $h = $eh;
                    $src = 'embed';
                    // Mild CSS oversample uses CSS size in loadSvg; mirror that here.
                    if ($cssW >= 80 && $cssH >= 80) {
                        $over = max($ew / $cssW, $eh / $cssH);
                        if ($over > 1.05 && $over <= 2.25) {
                            $w = $cssW;
                            $h = $cssH;
                            $src = 'css';
                        }
                    } elseif ($vw > 0 && $vh > 0) {
                        $w = $vw;
                        $h = $vh;
                        $src = 'viewBox';
                    }
                } elseif ($vw > 0 && $vh > 0) {
                    $w = $vw;
                    $h = $vh;
                    $src = 'viewBox';
                } elseif ($cssW >= 80 && $cssH >= 80) {
                    $w = $cssW;
                    $h = $cssH;
                    $src = 'css';
                } else {
                    $w = 800;
                    $h = 600;
                    $src = 'default';
                }
                $cap = self::maxEdge();
                if (max($w, $h) > $cap) {
                    $scale = $cap / max($w, $h);
                    $w = max(1, (int) round($w * $scale));
                    $h = max(1, (int) round($h * $scale));
                    $src .= '+cap';
                }
                return ['width' => $w, 'height' => $h, 'source' => $src];
            }
        }
        $info = @getimagesizefromstring($blob);
        if (is_array($info)) {
            $w = max(1, (int) ($info[0] ?? 1));
            $h = max(1, (int) ($info[1] ?? 1));
            $cap = self::maxEdge();
            if (max($w, $h) > $cap) {
                $scale = $cap / max($w, $h);
                $w = max(1, (int) round($w * $scale));
                $h = max(1, (int) round($h * $scale));
            }

            return ['width' => $w, 'height' => $h, 'source' => 'raster'];
        }

        return ['width' => 800, 'height' => 600, 'source' => 'default'];
    }

    private static function rasterizeSvgExternal(string $svg): ?\GdImage
    {
        if (class_exists(\Imagick::class)) {
            try {
                $imk = new \Imagick();
                $imk->setBackgroundColor(new \ImagickPixel('white'));
                $imk->readImageBlob($svg);
                $imk->setImageFormat('png');
                $blob = $imk->getImageBlob();
                $imk->clear();
                $im = @imagecreatefromstring($blob);
                if ($im instanceof \GdImage) {
                    return $im;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $bins = ['rsvg-convert', 'inkscape', 'convert'];
        foreach ($bins as $bin) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($path === '') {
                continue;
            }
            $in = tempnam(sys_get_temp_dir(), 'fs_svg_');
            $out = $in . '.png';
            if ($in === false) {
                continue;
            }
            file_put_contents($in, $svg);
            $cmd = match ($bin) {
                'rsvg-convert' => escapeshellcmd($path) . ' -o ' . escapeshellarg($out) . ' ' . escapeshellarg($in),
                'inkscape' => escapeshellcmd($path) . ' --export-type=png --export-filename=' . escapeshellarg($out) . ' ' . escapeshellarg($in),
                default => escapeshellcmd($path) . ' ' . escapeshellarg($in) . ' ' . escapeshellarg($out),
            };
            @exec($cmd . ' 2>/dev/null', $ignored, $code);
            $im = null;
            if (is_file($out)) {
                $im = @imagecreatefrompng($out);
                @unlink($out);
            }
            @unlink($in);
            if ($im instanceof \GdImage) {
                return $im;
            }
        }
        return null;
    }

    /**
     * Minimal SVG shape rasterizer for rect/line/polygon floorplans.
     */
    private static function rasterizeSvgShapes(string $svg): ?\GdImage
    {
        if (!preg_match('/<svg\\b[^>]*>/i', $svg, $tag)) {
            return null;
        }
        $head = $tag[0];
        $w = self::svgLength($head, 'width', 800);
        $h = self::svgLength($head, 'height', 800);
        if (preg_match('/viewBox=["\']\\s*([0-9.]+)\\s+([0-9.]+)\\s+([0-9.]+)\\s+([0-9.]+)/i', $head, $vb)) {
            $w = max($w, (int) ceil((float) $vb[3]));
            $h = max($h, (int) ceil((float) $vb[4]));
        }
        $w = max(16, min(2000, $w));
        $h = max(16, min(2000, $h));
        $im = imagecreatetruecolor($w, $h);
        if (!$im instanceof \GdImage) {
            return null;
        }
        $white = imagecolorallocate($im, 248, 248, 248);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $white);

        $drew = false;
        if (preg_match_all('/<rect\\b([^>]*)>/i', $svg, $rects)) {
            foreach ($rects[1] as $attrs) {
                $x = (int) round(self::attr($attrs, 'x', 0));
                $y = (int) round(self::attr($attrs, 'y', 0));
                $rw = (int) round(self::attr($attrs, 'width', 0));
                $rh = (int) round(self::attr($attrs, 'height', 0));
                if ($rw < 1 || $rh < 1) {
                    continue;
                }
                $col = self::svgColor($im, $attrs, 136, 136, 136);
                imagefilledrectangle($im, $x, $y, $x + $rw - 1, $y + $rh - 1, $col);
                $drew = true;
            }
        }
        if (preg_match_all('/<(?:line)\\b([^>]*)>/i', $svg, $lines)) {
            foreach ($lines[1] as $attrs) {
                $x1 = (int) round(self::attr($attrs, 'x1', 0));
                $y1 = (int) round(self::attr($attrs, 'y1', 0));
                $x2 = (int) round(self::attr($attrs, 'x2', 0));
                $y2 = (int) round(self::attr($attrs, 'y2', 0));
                $col = self::svgColor($im, $attrs, 136, 136, 136);
                imagesetthickness($im, max(1, (int) round(self::attr($attrs, 'stroke-width', 4))));
                imageline($im, $x1, $y1, $x2, $y2, $col);
                $drew = true;
            }
        }
        return $drew ? $im : null;
    }

    private static function svgLength(string $tag, string $name, int $default): int
    {
        if (!preg_match('/' . $name . '=["\']([0-9.]+)/i', $tag, $m)) {
            return $default;
        }
        return max(1, (int) ceil((float) $m[1]));
    }

    private static function attr(string $attrs, string $name, float $default): float
    {
        if (!preg_match('/' . preg_quote($name, '/') . '=["\']([0-9.]+)/i', $attrs, $m)) {
            return $default;
        }
        return (float) $m[1];
    }

    private static function svgColor(\GdImage $im, string $attrs, int $dr, int $dg, int $db): int
    {
        $raw = '';
        if (preg_match('/(?:fill|stroke)=["\']([^"\']+)["\']/', $attrs, $m)) {
            $raw = strtolower(trim($m[1]));
        }
        if ($raw === '' || $raw === 'none') {
            return imagecolorallocate($im, $dr, $dg, $db) ?: 0;
        }
        if (preg_match('/^#([0-9a-f]{6})$/', $raw, $m)) {
            $n = hexdec($m[1]);
            return imagecolorallocate($im, ($n >> 16) & 255, ($n >> 8) & 255, $n & 255) ?: 0;
        }
        if (preg_match('/^#([0-9a-f]{3})$/', $raw, $m)) {
            $s = $m[1];
            return imagecolorallocate(
                $im,
                hexdec($s[0] . $s[0]),
                hexdec($s[1] . $s[1]),
                hexdec($s[2] . $s[2])
            ) ?: 0;
        }
        return imagecolorallocate($im, $dr, $dg, $db) ?: 0;
    }

    private static function stripBom(string $blob): string
    {
        if (str_starts_with($blob, "\xEF\xBB\xBF")) {
            return substr($blob, 3);
        }
        return $blob;
    }
}
