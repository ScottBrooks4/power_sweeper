<?php

declare(strict_types=1);

namespace PowerSweeper\FloorScanner;

/**
 * Bit-packed binary mask for native-scale floorplans.
 * 5907×2824 ≈ 2.1 MB packed vs ~130+ MB as list<int>.
 */
final class PackedMask
{
    private string $bits;
    private int $w;
    private int $h;
    private int $n;

    public function __construct(int $w, int $h, ?string $bits = null)
    {
        $this->w = max(1, $w);
        $this->h = max(1, $h);
        $this->n = $this->w * $this->h;
        $bytes = intdiv($this->n + 7, 8);
        if ($bits !== null && strlen($bits) === $bytes) {
            $this->bits = $bits;
        } else {
            $this->bits = str_repeat("\0", $bytes);
        }
    }

    public function width(): int
    {
        return $this->w;
    }

    public function height(): int
    {
        return $this->h;
    }

    public function size(): int
    {
        return $this->n;
    }

    public function get(int $i): int
    {
        if ($i < 0 || $i >= $this->n) {
            return 0;
        }
        $byte = ord($this->bits[$i >> 3]);

        return ($byte >> ($i & 7)) & 1;
    }

    public function getXY(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->w || $y >= $this->h) {
            return 0;
        }

        return $this->get($y * $this->w + $x);
    }

    public function set(int $i, int $v): void
    {
        if ($i < 0 || $i >= $this->n) {
            return;
        }
        $bi = $i >> 3;
        $bit = 1 << ($i & 7);
        $byte = ord($this->bits[$bi]);
        if ($v) {
            $byte |= $bit;
        } else {
            $byte &= ~$bit;
        }
        $this->bits[$bi] = chr($byte & 0xFF);
    }

    public function setXY(int $x, int $y, int $v): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->w || $y >= $this->h) {
            return;
        }
        $this->set($y * $this->w + $x, $v);
    }

    public function clone(): self
    {
        return new self($this->w, $this->h, $this->bits);
    }

    public function fill(int $v): void
    {
        $bytes = intdiv($this->n + 7, 8);
        $this->bits = str_repeat($v ? "\xFF" : "\0", $bytes);
    }

    public function orWith(self $other): void
    {
        if ($other->w !== $this->w || $other->h !== $this->h) {
            return;
        }
        $len = strlen($this->bits);
        $a = $this->bits;
        $b = $other->bits;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($a[$i]) | ord($b[$i]));
        }
        $this->bits = $out;
    }

    public function andNot(self $other): void
    {
        if ($other->w !== $this->w || $other->h !== $this->h) {
            return;
        }
        $len = strlen($this->bits);
        $a = $this->bits;
        $b = $other->bits;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($a[$i]) & ~ord($b[$i]));
        }
        $this->bits = $out;
    }

    /**
     * Mid-gray wall ink from a GD image (same band as RoomDetector::wallMask).
     */
    public static function fromWallInk(\GdImage $im, bool $soft = false): self
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $mask = new self($w, $h);
        $lo = $soft ? 78 : 88;
        $hi = $soft ? 192 : 182;
        $maxChroma = $soft ? 44 : 36;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = ($r + $g + $b) / 3.0;
                $chroma = max(abs($r - $g), abs($g - $b), abs($r - $b));
                if ($lum >= $lo && $lum <= $hi && $chroma <= $maxChroma) {
                    $mask->setXY($x, $y, 1);
                }
            }
        }

        return $mask;
    }

    /**
     * Dark ink (furniture / door arcs / bold strokes) — not mid-gray walls.
     */
    public static function fromDarkInk(\GdImage $im): self
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $mask = new self($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = ($r + $g + $b) / 3.0;
                $chroma = max(abs($r - $g), abs($g - $b), abs($r - $b));
                if ($lum < 88 && $chroma <= 48) {
                    $mask->setXY($x, $y, 1);
                }
            }
        }

        return $mask;
    }

    /**
     * Convert to list<int> for legacy RoomDetector helpers (use sparingly).
     *
     * @return list<int>
     */
    public function toIntList(): array
    {
        $out = array_fill(0, $this->n, 0);
        for ($i = 0; $i < $this->n; $i++) {
            if ($this->get($i) === 1) {
                $out[$i] = 1;
            }
        }

        return $out;
    }

    public static function fromIntList(array $list, int $w, int $h): self
    {
        $mask = new self($w, $h);
        $n = min(count($list), $w * $h);
        for ($i = 0; $i < $n; $i++) {
            if (($list[$i] ?? 0) === 1) {
                $mask->set($i, 1);
            }
        }

        return $mask;
    }

    /**
     * Remove isolated speckles (short in both H and V) so flood leaks over furniture/steps.
     * Long wall faces stay even when thin in the perpendicular direction.
     */
    public function dropShortRuns(int $minLen): void
    {
        $minLen = max(2, $minLen);
        $w = $this->w;
        $h = $this->h;
        $shortH = new self($w, $h);
        $shortV = new self($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $x = 0;
            while ($x < $w) {
                if ($this->getXY($x, $y) !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $this->getXY($x, $y) === 1) {
                    $x++;
                }
                if ($x - $x0 < $minLen) {
                    for ($xx = $x0; $xx < $x; $xx++) {
                        $shortH->setXY($xx, $y, 1);
                    }
                }
            }
        }
        for ($x = 0; $x < $w; $x++) {
            $y = 0;
            while ($y < $h) {
                if ($this->getXY($x, $y) !== 1) {
                    $y++;
                    continue;
                }
                $y0 = $y;
                while ($y < $h && $this->getXY($x, $y) === 1) {
                    $y++;
                }
                if ($y - $y0 < $minLen) {
                    for ($yy = $y0; $yy < $y; $yy++) {
                        $shortV->setXY($x, $yy, 1);
                    }
                }
            }
        }
        for ($i = 0; $i < $this->n; $i++) {
            if ($shortH->get($i) === 1 && $shortV->get($i) === 1) {
                $this->set($i, 0);
            }
        }
    }

    /**
     * Connected components of zero-pixels (free space), treating 1 as barrier.
     * Optionally stop at hallway pixels (treated as barrier).
     *
     * @return list<array{bbox: array{0:int,1:int,2:int,3:int}, area: int}>
     */
    public function freeComponents(int $minArea, ?self $hallwayBarrier = null): array
    {
        $seen = new self($this->w, $this->h);
        $out = [];
        $qx = [];
        $qy = [];
        for ($sy = 0; $sy < $this->h; $sy++) {
            for ($sx = 0; $sx < $this->w; $sx++) {
                $si = $sy * $this->w + $sx;
                if ($this->get($si) === 1 || $seen->get($si) === 1) {
                    continue;
                }
                if ($hallwayBarrier !== null && $hallwayBarrier->get($si) === 1) {
                    continue;
                }
                $qs = 0;
                $qi = 0;
                $qx = [$sx];
                $qy = [$sy];
                $qs = 1;
                $seen->set($si, 1);
                $minX = $sx;
                $maxX = $sx;
                $minY = $sy;
                $maxY = $sy;
                $area = 0;
                while ($qi < $qs) {
                    $x = $qx[$qi];
                    $y = $qy[$qi];
                    $qi++;
                    $area++;
                    if ($x < $minX) {
                        $minX = $x;
                    }
                    if ($x > $maxX) {
                        $maxX = $x;
                    }
                    if ($y < $minY) {
                        $minY = $y;
                    }
                    if ($y > $maxY) {
                        $maxY = $y;
                    }
                    foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        if ($nx < 0 || $ny < 0 || $nx >= $this->w || $ny >= $this->h) {
                            continue;
                        }
                        $ni = $ny * $this->w + $nx;
                        if ($seen->get($ni) === 1 || $this->get($ni) === 1) {
                            continue;
                        }
                        if ($hallwayBarrier !== null && $hallwayBarrier->get($ni) === 1) {
                            continue;
                        }
                        $seen->set($ni, 1);
                        $qx[] = $nx;
                        $qy[] = $ny;
                        $qs++;
                    }
                }
                if ($area >= $minArea) {
                    $out[] = [
                        'bbox' => [$minX, $minY, $maxX, $maxY],
                        'area' => $area,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Flood from border through free (0) pixels; returns visited exterior mask.
     */
    public function floodExterior(): self
    {
        $seen = $this->clone();
        $qx = [];
        $qy = [];
        $qs = 0;
        $enqueue = function (int $x, int $y) use (&$seen, &$qx, &$qy, &$qs): void {
            if ($x < 0 || $y < 0 || $x >= $this->w || $y >= $this->h) {
                return;
            }
            $i = $y * $this->w + $x;
            if ($seen->get($i) === 1) {
                return;
            }
            $seen->set($i, 1);
            $qx[] = $x;
            $qy[] = $y;
            $qs++;
        };
        for ($x = 0; $x < $this->w; $x++) {
            $enqueue($x, 0);
            $enqueue($x, $this->h - 1);
        }
        for ($y = 0; $y < $this->h; $y++) {
            $enqueue(0, $y);
            $enqueue($this->w - 1, $y);
        }
        $qi = 0;
        while ($qi < $qs) {
            $x = $qx[$qi];
            $y = $qy[$qi];
            $qi++;
            $enqueue($x - 1, $y);
            $enqueue($x + 1, $y);
            $enqueue($x, $y - 1);
            $enqueue($x, $y + 1);
        }

        return $seen;
    }

    public function countOnes(): int
    {
        $c = 0;
        for ($i = 0; $i < $this->n; $i++) {
            $c += $this->get($i);
        }

        return $c;
    }
}
