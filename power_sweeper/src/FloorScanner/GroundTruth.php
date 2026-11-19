<?php

declare(strict_types=1);

namespace PowerSweeper\FloorScanner;

/**
 * Extract labelled space ground truth from Synoptic Designer Filled SVGs
 * (and CubiCasa-style Space annotations).
 *
 * Synoptic exports use mid-blue fills (#0B95EE) on &lt;rect&gt;, &lt;path&gt;, and
 * &lt;polygon&gt; elements, each carrying id/title like title="D04.27".
 */
final class GroundTruth
{
    /** Default Synoptic annotation fill (case-insensitive hex). */
    public const ANNOTATION_FILL = '#0B95EE';

    /** Spaces at or below this viewBox area are treated as cubicle/module cells. */
    public const CELL_AREA = 40000.0;

    /**
     * @return array{
     *   viewBox: list<float>,
     *   count: int,
     *   spaces: list<array{x:float,y:float,w:float,h:float,label:string,type:string,shape:string,id:?string}>,
     *   rects: list<array{x:float,y:float,w:float,h:float,label?:string,type?:string}>
     * }
     */
    public static function extractFromFilledSvg(string $svg, ?string $fillFilter = self::ANNOTATION_FILL): array
    {
        $viewBox = self::parseViewBox($svg);
        $spaces = [];

        foreach (self::matchTags($svg, 'rect') as $attrs) {
            if ($fillFilter !== null && !self::fillMatches($attrs['fill'] ?? '', $fillFilter)) {
                continue;
            }
            $x = (float) ($attrs['x'] ?? 0);
            $y = (float) ($attrs['y'] ?? 0);
            $w = (float) ($attrs['width'] ?? 0);
            $h = (float) ($attrs['height'] ?? 0);
            if ($w <= 0.5 || $h <= 0.5) {
                continue;
            }
            $spaces[] = self::spaceFromBox($x, $y, $w, $h, $attrs, 'rect');
        }

        foreach (self::matchTags($svg, 'polygon') as $attrs) {
            if ($fillFilter !== null && !self::fillMatches($attrs['fill'] ?? '', $fillFilter)) {
                continue;
            }
            $box = self::bboxFromPoints($attrs['points'] ?? '');
            if ($box === null) {
                continue;
            }
            $spaces[] = self::spaceFromBox($box[0], $box[1], $box[2], $box[3], $attrs, 'polygon');
        }

        foreach (self::matchTags($svg, 'path') as $attrs) {
            if ($fillFilter !== null && !self::fillMatches($attrs['fill'] ?? '', $fillFilter)) {
                continue;
            }
            $box = self::bboxFromPath($attrs['d'] ?? '');
            if ($box === null) {
                continue;
            }
            $spaces[] = self::spaceFromBox($box[0], $box[1], $box[2], $box[3], $attrs, 'path');
        }

        usort(
            $spaces,
            static fn (array $a, array $b): int => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']
        );

        // Backward-compatible rects list for existing scorers.
        $rects = [];
        foreach ($spaces as $s) {
            $rects[] = [
                'x' => $s['x'],
                'y' => $s['y'],
                'w' => $s['w'],
                'h' => $s['h'],
                'label' => $s['label'],
                'type' => $s['type'],
            ];
        }

        return [
            'viewBox' => $viewBox,
            'count' => count($spaces),
            'spaces' => $spaces,
            'rects' => $rects,
            'source' => 'synoptic_filled_svg',
        ];
    }

    /**
     * Convert CubiCasa annotation SVG Space groups to labelled AABBs.
     *
     * @return array{
     *   viewBox: list<float>,
     *   count: int,
     *   spaces: list<array{x:float,y:float,w:float,h:float,label:string,type:string,shape:string,id:?string}>,
     *   rects: list<array{x:float,y:float,w:float,h:float,label?:string,type?:string}>,
     *   furniture: list<array{x:float,y:float,w:float,h:float,label:string}>
     * }
     */
    public static function extractFromCubiCasaSvg(string $svg): array
    {
        $viewBox = self::parseViewBox($svg);
        if ($viewBox[2] <= 0 || $viewBox[3] <= 0) {
            if (preg_match('/width=["\']?\s*([0-9.]+)/i', $svg, $wm)
                && preg_match('/height=["\']?\s*([0-9.]+)/i', $svg, $hm)
            ) {
                $viewBox = [0.0, 0.0, (float) $wm[1], (float) $hm[1]];
            }
        }

        $spaces = [];
        $furniture = [];

        // CubiCasa wraps spaces as <g class="Space Kitchen">…</g>
        if (preg_match_all(
            '/<g\b([^>]*)>(.*?)<\/g>/is',
            $svg,
            $groups,
            PREG_SET_ORDER
        )) {
            foreach ($groups as $g) {
                $attrs = self::parseAttrs($g[1]);
                $class = $attrs['class'] ?? '';
                $inner = $g[2];
                if (preg_match('/\bSpace\s+(\S+)/i', $class, $cm)) {
                    $roomType = $cm[1];
                    $box = self::bboxFromInnerGeometry($inner);
                    if ($box === null) {
                        continue;
                    }
                    $label = $roomType;
                    $spaces[] = [
                        'x' => $box[0],
                        'y' => $box[1],
                        'w' => $box[2],
                        'h' => $box[3],
                        'label' => $label,
                        'type' => 'room',
                        'shape' => 'aabb',
                        'id' => $attrs['id'] ?? null,
                    ];
                } elseif (preg_match('/\bFixedFurniture\s+(\S+)/i', $class, $fm)) {
                    $box = self::bboxFromInnerGeometry($inner);
                    if ($box === null) {
                        continue;
                    }
                    $furniture[] = [
                        'x' => $box[0],
                        'y' => $box[1],
                        'w' => $box[2],
                        'h' => $box[3],
                        'label' => $fm[1],
                    ];
                }
            }
        }

        usort(
            $spaces,
            static fn (array $a, array $b): int => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']
        );

        $rects = [];
        foreach ($spaces as $s) {
            $rects[] = [
                'x' => $s['x'],
                'y' => $s['y'],
                'w' => $s['w'],
                'h' => $s['h'],
                'label' => $s['label'],
                'type' => $s['type'],
            ];
        }

        return [
            'viewBox' => $viewBox,
            'count' => count($spaces),
            'spaces' => $spaces,
            'rects' => $rects,
            'furniture' => $furniture,
            'source' => 'cubicasa_svg',
        ];
    }

    /**
     * @param array<string, string> $attrs
     * @return array{x:float,y:float,w:float,h:float,label:string,type:string,shape:string,id:?string}
     */
    private static function spaceFromBox(
        float $x,
        float $y,
        float $w,
        float $h,
        array $attrs,
        string $shape
    ): array {
        $label = trim((string) ($attrs['title'] ?? ''));
        if ($label === '') {
            $id = (string) ($attrs['id'] ?? '');
            // Synoptic ids look like _5W.O.1.D04.27 — keep the trailing room code.
            if (preg_match('/([A-Z]\d{2}\.\d{2})$/i', $id, $m)) {
                $label = $m[1];
            } elseif ($id !== '') {
                $label = ltrim($id, '_');
            }
        }
        $area = $w * $h;
        $type = $area <= self::CELL_AREA ? 'cubicle' : 'room';
        // Named residential rooms (Kitchen, Storage, …) are never cubicles.
        if ($label !== '' && preg_match('/^(Kitchen|Living|Bed|Bath|Dining|Master|Storage|Garage|Hall|Entry|Closet|Laundry)/i', $label)) {
            $type = 'room';
        }

        return [
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'label' => $label,
            'type' => $type,
            'shape' => $shape,
            'id' => $attrs['id'] ?? null,
        ];
    }

    /**
     * @return list<float>
     */
    public static function parseViewBox(string $svg): array
    {
        if (preg_match(
            '/viewBox=["\']\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)/i',
            $svg,
            $m
        )) {
            return [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];
        }

        return [0.0, 0.0, 0.0, 0.0];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function matchTags(string $svg, string $tag): array
    {
        $out = [];
        if (!preg_match_all('/<' . $tag . '\b([^>]*)\/?>/i', $svg, $matches, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($matches as $m) {
            $out[] = self::parseAttrs($m[1]);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function parseAttrs(string $chunk): array
    {
        $attrs = [];
        if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*"([^"]*)"/', $chunk, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $attrs[strtolower($pair[1])] = $pair[2];
            }
        }
        if (preg_match_all("/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*'([^']*)'/", $chunk, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $attrs[strtolower($pair[1])] = $pair[2];
            }
        }

        return $attrs;
    }

    private static function fillMatches(string $fill, string $want): bool
    {
        $a = strtoupper(ltrim(trim($fill), '#'));
        $b = strtoupper(ltrim(trim($want), '#'));
        if ($a === '' || $b === '') {
            return false;
        }
        // Expand #RGB to #RRGGBB
        if (strlen($a) === 3) {
            $a = $a[0] . $a[0] . $a[1] . $a[1] . $a[2] . $a[2];
        }
        if (strlen($b) === 3) {
            $b = $b[0] . $b[0] . $b[1] . $b[1] . $b[2] . $b[2];
        }

        return $a === $b;
    }

    /**
     * Axis-aligned bbox from an SVG path `d` attribute.
     * Handles M/L/H/V/Z with comma or space separators, absolute and relative.
     *
     * @return array{0:float,1:float,2:float,3:float}|null  x,y,w,h
     */
    public static function bboxFromPath(string $d): ?array
    {
        $d = trim($d);
        if ($d === '') {
            return null;
        }
        // Insert spaces around command letters so "M1,2L3,4" tokenises cleanly.
        $d = preg_replace('/([MmLlHhVvZzCcSsQqTtAa])/', ' $1 ', $d) ?? $d;
        $d = str_replace(',', ' ', $d);
        $tokens = preg_split('/\s+/', trim($d)) ?: [];
        $cmd = '';
        $cx = 0.0;
        $cy = 0.0;
        $xs = [];
        $ys = [];
        $i = 0;
        $n = count($tokens);
        while ($i < $n) {
            $tok = $tokens[$i];
            if ($tok === '') {
                $i++;
                continue;
            }
            if (preg_match('/^[MmLlHhVvZzCcSsQqTtAa]$/', $tok) === 1) {
                $cmd = $tok;
                $i++;
                if ($cmd === 'Z' || $cmd === 'z') {
                    continue;
                }
            } elseif ($cmd === '') {
                $cmd = 'M';
            }

            $abs = $cmd === strtoupper($cmd);
            $c = strtoupper($cmd);

            if ($c === 'H') {
                if (!isset($tokens[$i]) || !is_numeric($tokens[$i])) {
                    break;
                }
                $x = (float) $tokens[$i++];
                $cx = $abs ? $x : $cx + $x;
                $xs[] = $cx;
                $ys[] = $cy;
                continue;
            }
            if ($c === 'V') {
                if (!isset($tokens[$i]) || !is_numeric($tokens[$i])) {
                    break;
                }
                $y = (float) $tokens[$i++];
                $cy = $abs ? $y : $cy + $y;
                $xs[] = $cx;
                $ys[] = $cy;
                continue;
            }
            if ($c === 'M' || $c === 'L' || $c === 'T') {
                if (!isset($tokens[$i + 1]) || !is_numeric($tokens[$i]) || !is_numeric($tokens[$i + 1])) {
                    break;
                }
                $x = (float) $tokens[$i++];
                $y = (float) $tokens[$i++];
                if ($abs) {
                    $cx = $x;
                    $cy = $y;
                } else {
                    $cx += $x;
                    $cy += $y;
                }
                $xs[] = $cx;
                $ys[] = $cy;
                if ($c === 'M') {
                    $cmd = $abs ? 'L' : 'l';
                }
                continue;
            }
            if ($c === 'C') {
                if ($i + 5 >= $n) {
                    break;
                }
                $pts = [];
                for ($k = 0; $k < 6; $k++) {
                    $pts[] = (float) $tokens[$i++];
                }
                if ($abs) {
                    $cx = $pts[4];
                    $cy = $pts[5];
                } else {
                    $cx += $pts[4];
                    $cy += $pts[5];
                }
                $xs[] = $cx;
                $ys[] = $cy;
                continue;
            }
            if ($c === 'S' || $c === 'Q') {
                if ($i + 3 >= $n) {
                    break;
                }
                $pts = [];
                for ($k = 0; $k < 4; $k++) {
                    $pts[] = (float) $tokens[$i++];
                }
                if ($abs) {
                    $cx = $pts[2];
                    $cy = $pts[3];
                } else {
                    $cx += $pts[2];
                    $cy += $pts[3];
                }
                $xs[] = $cx;
                $ys[] = $cy;
                continue;
            }
            if ($c === 'A') {
                if ($i + 6 >= $n) {
                    break;
                }
                $i += 5;
                $x = (float) $tokens[$i++];
                $y = (float) $tokens[$i++];
                if ($abs) {
                    $cx = $x;
                    $cy = $y;
                } else {
                    $cx += $x;
                    $cy += $y;
                }
                $xs[] = $cx;
                $ys[] = $cy;
                continue;
            }
            $i++;
        }

        if ($xs === [] || $ys === []) {
            return null;
        }
        $x0 = min($xs);
        $y0 = min($ys);
        $x1 = max($xs);
        $y1 = max($ys);
        $w = $x1 - $x0;
        $h = $y1 - $y0;
        if ($w <= 0.5 || $h <= 0.5) {
            return null;
        }

        return [$x0, $y0, $w, $h];
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public static function bboxFromPoints(string $points): ?array
    {
        $xs = [];
        $ys = [];
        if (preg_match_all('/(-?[\d.]+)\s*[,\s]\s*(-?[\d.]+)/', $points, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $xs[] = (float) $pair[1];
                $ys[] = (float) $pair[2];
            }
        }
        if ($xs === []) {
            return null;
        }
        $x0 = min($xs);
        $y0 = min($ys);
        $w = max($xs) - $x0;
        $h = max($ys) - $y0;
        if ($w <= 0.5 || $h <= 0.5) {
            return null;
        }

        return [$x0, $y0, $w, $h];
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    private static function bboxFromInnerGeometry(string $inner): ?array
    {
        $xs = [];
        $ys = [];
        foreach (self::matchTags($inner, 'polygon') as $attrs) {
            $box = self::bboxFromPoints($attrs['points'] ?? '');
            if ($box !== null) {
                $xs[] = $box[0];
                $xs[] = $box[0] + $box[2];
                $ys[] = $box[1];
                $ys[] = $box[1] + $box[3];
            }
        }
        foreach (self::matchTags($inner, 'path') as $attrs) {
            $box = self::bboxFromPath($attrs['d'] ?? '');
            if ($box !== null) {
                $xs[] = $box[0];
                $xs[] = $box[0] + $box[2];
                $ys[] = $box[1];
                $ys[] = $box[1] + $box[3];
            }
        }
        foreach (self::matchTags($inner, 'rect') as $attrs) {
            $x = (float) ($attrs['x'] ?? 0);
            $y = (float) ($attrs['y'] ?? 0);
            $w = (float) ($attrs['width'] ?? 0);
            $h = (float) ($attrs['height'] ?? 0);
            if ($w > 0 && $h > 0) {
                $xs[] = $x;
                $xs[] = $x + $w;
                $ys[] = $y;
                $ys[] = $y + $h;
            }
        }
        if ($xs === []) {
            return null;
        }
        $x0 = min($xs);
        $y0 = min($ys);

        return [$x0, $y0, max($xs) - $x0, max($ys) - $y0];
    }

    /**
     * Normalise a space label for comparison (D04.27, d04.27, _5W.O.1.D04.27).
     */
    public static function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        if (preg_match('/([A-Za-z]\d{2}\.\d{2})$/', $label, $m)) {
            return strtoupper($m[1]);
        }

        return strtoupper(preg_replace('/\s+/', ' ', $label) ?? $label);
    }
}
