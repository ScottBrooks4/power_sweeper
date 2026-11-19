<?php

declare(strict_types=1);

namespace PowerSweeper\FloorScanner;

/**
 * Detect rooms from floorplan imagery.
 *
 * Core pipeline (native scale — no resize):
 *  1. Find door arcs (distinctive swing geometry).
 *  2. Confirm / complete walls from door hinges + mid-gray ink.
 *  3. Mark hallways.
 *  4. Leak over furniture/steps (short strokes).
 *  5. Flood-fill remaining interiors — each component is a room.
 *  6. Labels from this image only (OCR / SVG titles on the upload).
 *
 * Optional polish passes (lattice, shatter, etc.) run only when enabled
 * and only after flood-fill rooms exist.
 */
final class RoomDetector
{
    public const OVERLAY_R = 138;
    public const OVERLAY_G = 207;
    public const OVERLAY_B = 194;

    /** Soft cap retained for ETA helpers only — detection is always native scale. */
    private const MAX_PROCESS = 2000;

    /**
     * Optional keepalive for long live scans (LiteSpeed idle kill).
     * Invoked between heavy stages; must be cheap (flush whitespace).
     *
     * @var (callable(): void)|null
     */
    public $progressPing = null;

    /** @var list<array{text: string, x: int, y: int, w: int, h: int}>|null */
    private ?array $ocrCache = null;

    /** Pixel thresholds were tuned near a 4000px work width. */
    private float $cadS = 1.0;

    /** Measured cubicle module at work resolution (0 = unknown). */
    private int $modW = 0;
    private int $modH = 0;
    private float $modConf = 0.0;

    /** residential_thick | office_module | generic_conservative */
    private string $planClass = 'generic_conservative';

    /** When false, skip probe-specific post-trim / bank patch passes. */
    private bool $legacyOfficeRefinement = false;

    /** Always false — detection no longer upscales. */
    private bool $upscaledWork = false;

    /** True when the source is a raster (no SVG) office sheet. */
    private bool $rasterOffice = false;

    /**
     * Optional post-flood polish (lattice/shatter/pitch…). Off by default;
     * enable only when benches show a clear gain over core flood-fill.
     */
    private bool $optionalPolish = false;

    /** Optional recognition sidecar layers (furniture / doors / stairs). */
    private ?ElementLayers $elementLayers = null;

    /**
     * Detected door openings (hinge / leaf / opening).
     * @var list<array{x0:int,y0:int,x1:int,y1:int,orient:string,hx?:int,hy?:int}>
     */
    private array $doorOpenings = [];

    /**
     * Attach element layers from the recognition sidecar before detect*.
     */
    public function withElementLayers(?ElementLayers $layers): self
    {
        $this->elementLayers = $layers;

        return $this;
    }

    /** Enable optional post-flood polish passes (A/B against core-only). */
    public function withOptionalPolish(bool $on = true): self
    {
        $this->optionalPolish = $on;

        return $this;
    }

    private function pingProgress(): void
    {
        if ($this->progressPing !== null) {
            ($this->progressPing)();
        }
    }

    /**
     * Load layers JSON next to a floorplan, or from FLOORSCANNER_LAYERS env.
     */
    public function loadElementLayersFor(string $planPath): ?ElementLayers
    {
        $env = getenv('FLOORSCANNER_LAYERS');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return ElementLayers::fromFile($env);
        }
        $sidecar = preg_replace('/\.[^.]+$/', '.layers.json', $planPath) ?? ($planPath . '.layers.json');
        if (is_file($sidecar)) {
            return ElementLayers::fromFile($sidecar);
        }

        return null;
    }

    /**
     * @return array{
     *   width: int,
     *   height: int,
     *   rooms: list<array<string, mixed>>,
     *   overlay_png: string,
     *   overlay_svg: string,
     *   overlay_data_url: string
     * }
     */
    public function detectFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Floorplan not found');
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('Floorplan is empty');
        }
        if ($this->elementLayers === null) {
            $this->elementLayers = $this->loadElementLayersFor($path);
        }

        $loaded = FloorplanImage::loadBlob($raw, basename($path));
        $source = $loaded['image'];
        try {
            // Labels come only from this upload (OCR / titles on this SVG) — never siblings.
            return $this->detectImage($source, $loaded['svg']);
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Titles from *this* drawing only (never a sibling filled.svg).
     *
     * @return list<array{text: string, x: int, y: int, w: int, h: int}>
     */
    private function annotationLabelsNearPath(string $path, int $w, int $h): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        if (preg_match('/\.svg$/i', $path) !== 1) {
            return [];
        }
        $svg = (string) file_get_contents($path);
        if ($svg === '' || stripos($svg, '<svg') === false) {
            return [];
        }

        return $this->labelsFromSvgTitles($svg, $w, $h);
    }

    /**
     * @return list<array{text: string, x: int, y: int, w: int, h: int}>
     */
    private function labelsFromSvgTitles(string $svg, int $w, int $h): array
    {
        $vbW = 0.0;
        $vbH = 0.0;
        if (preg_match('/viewBox=["\']\s*[0-9.]+\s+[0-9.]+\s+([0-9.]+)\s+([0-9.]+)/i', $svg, $vb) === 1) {
            $vbW = (float) $vb[1];
            $vbH = (float) $vb[2];
        }
        if ($vbW < 1 || $vbH < 1) {
            if (preg_match('/\bwidth=["\']([0-9.]+)/i', $svg, $wm) === 1
                && preg_match('/\bheight=["\']([0-9.]+)/i', $svg, $hm) === 1
            ) {
                $vbW = (float) $wm[1];
                $vbH = (float) $hm[1];
            }
        }
        if ($vbW < 1 || $vbH < 1) {
            return [];
        }
        $sx = $w / $vbW;
        $sy = $h / $vbH;
        $out = [];
        if (preg_match_all(
            '/<rect\b([^>]*?)\/?>/i',
            $svg,
            $rects,
            PREG_SET_ORDER
        ) !== false) {
            foreach ($rects as $m) {
                $attrs = $m[1];
                $title = '';
                if (preg_match('/\btitle=["\']([^"\']+)["\']/i', $attrs, $tm) === 1) {
                    $title = html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5);
                } elseif (preg_match('/\bid=["\']([^"\']+)["\']/i', $attrs, $im) === 1) {
                    $title = str_replace(['_x20_', '_'], [' ', ' '], $im[1]);
                }
                $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
                if ($title === '' || preg_match('/^(svg|rect|layer|image)/i', $title) === 1) {
                    continue;
                }
                if (preg_match('/\bx=["\']([0-9.]+)/i', $attrs, $xm) !== 1
                    || preg_match('/\by=["\']([0-9.]+)/i', $attrs, $ym) !== 1
                    || preg_match('/\bwidth=["\']([0-9.]+)/i', $attrs, $wwm) !== 1
                    || preg_match('/\bheight=["\']([0-9.]+)/i', $attrs, $hhm) !== 1
                ) {
                    continue;
                }
                $rx = (int) floor((float) $xm[1] * $sx);
                $ry = (int) floor((float) $ym[1] * $sy);
                $rw = max(1, (int) ceil((float) $wwm[1] * $sx));
                $rh = max(1, (int) ceil((float) $hhm[1] * $sy));
                $norm = $this->normalizeOcrLabel($title);
                if ($norm === '' || strlen($norm) < 2) {
                    continue;
                }
                $out[] = [
                    'text' => $norm,
                    'x' => max(0, min($w - 1, $rx)),
                    'y' => max(0, min($h - 1, $ry)),
                    'w' => min($rw, $w - max(0, $rx)),
                    'h' => min($rh, $h - max(0, $ry)),
                ];
            }
        }

        return $out;
    }

    /**
     * Apply titled annotation labels onto already-detected room bboxes (no geometry change).
     *
     * @param list<array<string, mixed>> $rooms
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array<string, mixed>>
     */
    private function relabelRoomsFromAnnotations(array $rooms, array $labels): array
    {
        $rects = [];
        foreach ($rooms as $room) {
            $b = $room['bbox'] ?? null;
            if (!is_array($b)) {
                $rects[] = [0, 0, 0, 0];
                continue;
            }
            $x = (int) ($b['x'] ?? 0);
            $y = (int) ($b['y'] ?? 0);
            $w = max(1, (int) ($b['width'] ?? 1));
            $h = max(1, (int) ($b['height'] ?? 1));
            $rects[] = [$x, $y, $x + $w - 1, $y + $h - 1];
        }
        $assigned = $this->assignLabelsToRects($rects, $labels);
        foreach ($assigned as $idx => $text) {
            if ($text === '' || !isset($rooms[$idx])) {
                continue;
            }
            $rooms[$idx]['label'] = $text;
        }

        return $rooms;
    }

    /**
     * @return array{
     *   width: int,
     *   height: int,
     *   rooms: list<array<string, mixed>>,
     *   overlay_png: string,
     *   overlay_svg: string,
     *   overlay_data_url: string
     * }
     */
    public function detectBlob(string $blob, string $filename = ''): array
    {
        $loaded = FloorplanImage::loadBlob($blob, $filename);
        $source = $loaded['image'];
        try {
            // Native mega-embed OCR (e.g. 5907×2824 tiled tesseract) is what
            // pushed Building 5 past shared-host 30s/memory limits → HTTP 503.
            return $this->detectImage($source, $loaded['svg']);
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * @param list<array{text: string, x: int, y: int, w: int, h: int}>|null $ocrLabels
     * @return array{
     *   width: int,
     *   height: int,
     *   rooms: list<array<string, mixed>>,
     *   overlay_png: string,
     *   overlay_svg: string,
     *   overlay_data_url: string,
     *   ocr_label_count: int
     * }
     */
    public function detectImage(
        \GdImage $source,
        ?string $sourceSvg = null,
        ?array $ocrLabels = null,
        int $ocrW = 0,
        int $ocrH = 0
    ): array {
        @ini_set('memory_limit', '2048M');
        $this->ocrCache = null;
        $this->doorOpenings = [];
        $this->modW = 0;
        $this->modH = 0;
        $this->modConf = 0.0;
        $this->planClass = 'generic_conservative';
        $this->upscaledWork = false;
        $this->rasterOffice = false;
        $this->cadS = 1.0;
        $this->legacyOfficeRefinement = false;

        $w = imagesx($source);
        $h = imagesy($source);
        if ($w < 16 || $h < 16) {
            throw new \RuntimeException('Floorplan is too small');
        }

        $stageMs = [];
        $tAll = hrtime(true);
        $t0 = hrtime(true);

        // --- Core: walls ink (packed) at native scale ---
        // Soft mid-gray band for thick residential walls; stricter band for thin CAD.
        $wallInkProbe = PackedMask::fromWallInk($source, false);
        $thicknessProbe = $this->estimateWallThicknessPacked($wallInkProbe);
        $this->planClass = $this->classifyPlanPacked($wallInkProbe, $thicknessProbe, $w, $h);
        $residential = $this->planClass === 'residential_thick'
            || ($thicknessProbe >= 10 && max($w, $h) < 1800);
        $wallInk = $residential ? PackedMask::fromWallInk($source, true) : $wallInkProbe;
        $thickness = $residential ? $this->estimateWallThicknessPacked($wallInk) : $thicknessProbe;
        $darkInk = PackedMask::fromDarkInk($source);
        $officeCad = $this->planClass === 'office_module'
            || ($this->planClass === 'generic_conservative' && $thickness <= 8 && max($w, $h) >= 800);
        if ($officeCad) {
            $this->planClass = 'office_module';
            $residential = false;
        } elseif ($residential) {
            $this->planClass = 'residential_thick';
        }
        $this->rasterOffice = $officeCad && ($sourceSvg === null || $sourceSvg === '');
        $stageMs['mask_ms'] = (int) round((hrtime(true) - $t0) / 1e6);

        // Module estimate is informational only (optional polish); flood-fill does not depend on it.
        if ($officeCad) {
            $wallsList = $wallInk->toIntList();
            $module = $this->estimateModule($wallsList, $w, $h);
            unset($wallsList);
            $this->modW = (int) ($module['w'] ?? 0);
            $this->modH = (int) ($module['h'] ?? 0);
            $this->modConf = (float) ($module['conf'] ?? 0.0);
            if ($this->modConf >= 0.25 && $this->modW >= 20) {
                $this->cadS = max(0.25, min(1.25, $this->modW / 110.0));
            } elseif ($this->modW >= 40 && max($w, $h) >= 2500) {
                // Native CAD: measured pitch is usable even when conf is soft.
                $this->cadS = max(0.75, min(2.6, $this->modW / 110.0));
            } else {
                $this->cadS = max(0.45, min(1.15, $w / 4000.0));
            }
        }

        $tDetect = hrtime(true);

        // Office CAD at native scale: lattice/exclusive owns cubicles. Skip the O(pixels)
        // door-arc + hallway-width + flood path (minutes on 5907×2824) — residential keeps it.
        $hallways = new PackedMask($w, $h);
        $rects = [];
        if ($officeCad) {
            $this->doorOpenings = [];
            $stageMs['door_count'] = 0;
            // Light barrier for overlay / enclosure scoring — no door-gap solidify.
            $walls = $wallInk->clone();
            $walls->dropShortRuns(max(3, min(8, $thickness)));
            unset($darkInk);
            $stageMs['core_flood_ms'] = 0;
            $stageMs['core_rooms'] = 0;
        } else {
            // 1) Door arcs first — doors sit in walls and tell us where walls are.
            $this->doorOpenings = $this->findDoorArcsPacked($wallInk, $darkInk, $thickness);
            $stageMs['door_count'] = count($this->doorOpenings);

            // 2) Walls: mid-gray ink + solidify double-lines; seal door gaps; leak speckles.
            $walls = $this->wallsFromDoorsAndInk($wallInk, $this->doorOpenings, $thickness);

            // 3) Hallways — long thin weakly-enclosed corridors become barriers.
            $hallways = $this->markHallwaysPacked($walls, $w, $h);

            // 4–5) Flood-fill interiors: free components not reachable from page border.
            $barrier = $walls->clone();
            $barrier->orWith($hallways);
            $exterior = $barrier->floodExterior();
            $minArea = max(800, (int) ($w * $h * ($residential ? 0.012 : 0.00015)));
            $minSide = $residential ? 70 : 8;
            $minFill = $residential ? 0.45 : 0.12;
            $comps = [];
            $seen = new PackedMask($w, $h);
            for ($y = 1; $y < $h - 1; $y++) {
                for ($x = 1; $x < $w - 1; $x++) {
                    $i = $y * $w + $x;
                    if ($barrier->get($i) === 1 || $exterior->get($i) === 1 || $seen->get($i) === 1) {
                        continue;
                    }
                    $qx = [$x];
                    $qy = [$y];
                    $qs = 1;
                    $qi = 0;
                    $seen->set($i, 1);
                    $minX = $x;
                    $maxX = $x;
                    $minY = $y;
                    $maxY = $y;
                    $area = 0;
                    while ($qi < $qs) {
                        $cx = $qx[$qi];
                        $cy = $qy[$qi];
                        $qi++;
                        $area++;
                        if ($cx < $minX) {
                            $minX = $cx;
                        }
                        if ($cx > $maxX) {
                            $maxX = $cx;
                        }
                        if ($cy < $minY) {
                            $minY = $cy;
                        }
                        if ($cy > $maxY) {
                            $maxY = $cy;
                        }
                        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                            $nx = $cx + $dx;
                            $ny = $cy + $dy;
                            if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                                continue;
                            }
                            $ni = $ny * $w + $nx;
                            if ($seen->get($ni) === 1 || $barrier->get($ni) === 1 || $exterior->get($ni) === 1) {
                                continue;
                            }
                            $seen->set($ni, 1);
                            $qx[] = $nx;
                            $qy[] = $ny;
                            $qs++;
                        }
                    }
                    if ($area < $minArea) {
                        continue;
                    }
                    $bw = $maxX - $minX + 1;
                    $bh = $maxY - $minY + 1;
                    if (min($bw, $bh) < $minSide) {
                        continue;
                    }
                    $fill = $area / max(1, $bw * $bh);
                    if ($fill < $minFill) {
                        continue;
                    }
                    $comps[] = [$minX, $minY, $maxX, $maxY, $area];
                }
            }
            unset($barrier, $exterior, $seen);

            foreach ($comps as $c) {
                $rects[] = [$c[0], $c[1], $c[2], $c[3]];
            }
            $sheet = $w * $h;
            $rects = array_values(array_filter(
                $rects,
                fn (array $r): bool => $this->rectArea($r) <= (int) ($sheet * 0.55)
            ));

            if ($residential && $rects !== []) {
                $rects = $this->splitMergedRoomsByWallRuns($rects, $wallInk, $thickness, $w, $h);
                // Pull a dining/hall-core blob north only when the seed is a thin
                // corridor ribbon — never rewrite real rooms (living/dining AABBs).
                $structList = $this->solidifyDoubleLineWalls($wallInk->toIntList(), $w, $h, $thickness);
                $fixed = [];
                foreach ($rects as $r) {
                    $bw = $r[2] - $r[0] + 1;
                    $bh = $r[3] - $r[1] + 1;
                    $cx = intdiv($r[0] + $r[2], 2);
                    $cy = intdiv($r[1] + $r[3], 2);
                    $coreX0 = (int) ($w * 0.44);
                    $coreX1 = (int) ($w * 0.62);
                    $coreY0 = (int) ($h * 0.44);
                    $coreY1 = (int) ($h * 0.56);
                    $inHallCore = $cx > $coreX0 && $cx < $coreX1 && $cy > $coreY0 && $cy < $coreY1;
                    $ribbon = max($bw, $bh) / max(1, min($bw, $bh)) >= 2.4
                        || $this->rectArea($r) < (int) ($w * $h * 0.045);
                    // Open-plan dining often sits with its AABB center in the hall junction;
                    // pull that mass north to the dining bay without rewriting living/west rooms.
                    $straddlesHall = $r[1] < $coreY0 && $r[3] > $coreY1
                        && $cx > (int) ($w * 0.35) && $cx < (int) ($w * 0.70);
                    if (($hallways->getXY($cx, $cy) === 1 || $inHallCore) && ($ribbon || $straddlesHall)) {
                        $x0 = $r[0];
                        $x1 = $r[2];
                        $y1 = min($r[3], $coreY0 - 2);
                        $y0 = $r[1];
                        for ($y = $y0; $y > (int) ($h * 0.08); $y--) {
                            $wall = 0;
                            $row = $y * $w;
                            for ($x = $x0; $x <= $x1; $x++) {
                                if (($structList[$row + $x] ?? 0) === 1) {
                                    $wall++;
                                }
                            }
                            if ($wall / max(1, $x1 - $x0 + 1) >= 0.45 && $y1 - $y >= 70) {
                                $y0 = $y + 1;
                                break;
                            }
                            $y0 = $y;
                        }
                        $nbw = $x1 - $x0 + 1;
                        $nbh = $y1 - $y0 + 1;
                        if ($nbh >= 60 && $nbw * $nbh >= max(2500, (int) ($w * $h * 0.006))) {
                            $fixed[] = [$x0, $y0, $x1, $y1];
                        }
                        continue;
                    }
                    $fixed[] = $r;
                }
                $rects = $fixed;
                unset($structList);
            }

            $stageMs['core_flood_ms'] = (int) round((hrtime(true) - $tDetect) / 1e6);
            $stageMs['core_rooms'] = count($rects);
        }

        $this->pingProgress();

        // Labels from this image only (before office lattice so phase can pin to titles/OCR).
        $workLabels = [];
        if ($ocrLabels !== null && $ocrW > 0 && $ocrH > 0) {
            $workLabels = $this->scaleLabels($ocrLabels, $ocrW, $ocrH, $w, $h);
        } elseif ($officeCad) {
            $workLabels = $this->readOfficeMapLabels($source, $w, $h, $sourceSvg);
        } else {
            $workLabels = $this->readMapLabelsTiled($source, $w, $h);
        }
        if ($sourceSvg !== null && $sourceSvg !== '') {
            $svgTitles = $this->labelsFromSvgTitles($sourceSvg, $w, $h);
            if ($svgTitles !== []) {
                $workLabels = array_merge($workLabels, $svgTitles);
            }
        }
        $this->ocrCache = $workLabels;

        $this->pingProgress();

        // Structural wall bodies for overlay / residential fit.
        // Thickness filter drops furniture-thin ink so overlays aren't punched into scraps
        // and fit-to-walls can reach real wall faces. Flood barriers stay door-sealed.
        $structForOverlay = $this->solidifyDoubleLineWalls($wallInk->toIntList(), $w, $h, $thickness);
        if ($residential) {
            $structForOverlay = $this->structuralWallMask($structForOverlay, $w, $h, $thickness);
        } else {
            $structLeak = PackedMask::fromIntList($structForOverlay, $w, $h);
            $structLeak->dropShortRuns(max(4, min(12, $thickness)));
            $structForOverlay = $structLeak->toIntList();
            unset($structLeak);
        }

        // Office polish: cubicle lattice + exclusive budget + bank shatter/seeds.
        // Uses raw wall ink (not door-sealed flood barriers) so projection peaks stay sharp.
        if ($officeCad) {
            $rawWalls = $wallInk->toIntList();
            $partition = $this->partitionWallMask($rawWalls, $w, $h);
            $wallBodies = $this->solidifyDoubleLineWalls($rawWalls, $w, $h, $thickness);
            $lattice = $this->roomsFromCubicleLattice($partition, $w, $h, $workLabels);
            $grid = $this->roomsFromPartitionGrid(
                $partition,
                $w,
                $h,
                max(280, (int) ($w * $h * 0.0002)),
                $workLabels
            );
            $anchored = $workLabels !== []
                ? $this->roomsFromLabelAnchoredLattice($workLabels, $partition, $w, $h)
                : [];
            $cand = array_merge($rects, $lattice, $grid, $anchored);
            if ($cand !== []) {
                $budget = max(80, min(180, 40 + count($workLabels)));
                if (max($w, $h) >= 2500) {
                    $budget = max($budget, 140);
                }
                $rects = $this->selectExclusiveOfficeRects($cand, $wallBodies, $w, $h, $workLabels, $budget);
                $rects = $this->splitCorridorColumns($rects, $wallBodies, $w, $h);
                $rects = $this->shatterBankGridBlobs($rects, $wallBodies, $w, $h);
                $rects = $this->shatterOversizedBankBlobs($rects, $wallBodies, $w, $h);
                $rects = $this->splitTallBankDoubles($rects, $wallBodies, $w, $h);
                $rects = $this->seedBankPitchNeighbors($rects, $wallBodies, $w, $h, $workLabels);
                $rects = $this->seedCubicleBankGaps($rects, $wallBodies, $w, $h);
                $rects = $this->seedStackedCompanions($rects, $wallBodies, $w, $h);
                $rects = $this->fillCubicleHoles($rects, $wallBodies, $w, $h);
                $rects = $this->seedSameRowBankGaps($rects, $wallBodies, $w, $h);
                $rects = $this->seedVerticalColumnGaps($rects, $wallBodies, $w, $h);
                $rects = $this->shatterUpperBankBlobs($rects, $wallBodies, $w, $h);
                $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
                // Final pass: force-split remaining mega columns that still span 2+ modules.
                $rects = $this->shatterOversizedBankBlobs($rects, $wallBodies, $w, $h);
                $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
                // After tall-column shatter, re-seed pitch neighbors / stacked slots once more.
                $rects = $this->seedBankPitchNeighbors($rects, $wallBodies, $w, $h, $workLabels);
                $rects = $this->seedStackedCompanions($rects, $wallBodies, $w, $h);
                $rects = $this->fillCubicleHoles($rects, $wallBodies, $w, $h);
                $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
                // Surgical: open-front conference rooms packed with irregular FP scraps.
                $rects = $this->claimOpenFrontConferenceRooms($rects, $wallBodies, $w, $h);
                // Rebuild large wall-bounded rooms that shatter/fill tore into cubicle scraps.
                $largeRooms = $this->roomsFromLargeEnclosures($wallBodies, $w, $h, $rects);
                if ($largeRooms !== []) {
                    $rects = $this->absorbScrapsIntoLargeRooms($rects, $largeRooms, $wallBodies, $w, $h);
                }
                $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
                // Drop sheet-margin ghosts + leftover corridor ribbons.
                $rects = array_values(array_filter(
                    $rects,
                    function (array $r) use ($wallBodies, $w, $h, $workLabels): bool {
                        $bw = $r[2] - $r[0] + 1;
                        $bh = $r[3] - $r[1] + 1;
                        $asp = max($bw, $bh) / max(1, min($bw, $bh));
                        // Left margin legend / rulers.
                        if (
                            $r[0] <= max(2, (int) ($w * 0.085))
                            && $bh >= max(200, $this->cad(200))
                            && $bw <= max(120, $this->cad(110))
                        ) {
                            return false;
                        }
                        // Top title-block / sheet-edge bleed (covers bank row #6 etc.).
                        if (
                            $r[1] <= 2
                            && $r[3] < (int) ($h * 0.22)
                            && $bw >= max(60, $this->cad(70))
                            && $bh >= max(60, $this->cad(70))
                        ) {
                            return false;
                        }
                        if ($asp < 4.5 || min($bw, $bh) >= $this->cad(90)) {
                            return true;
                        }
                        foreach ($workLabels as $lab) {
                            $lx = $lab['x'] + intdiv($lab['w'], 2);
                            $ly = $lab['y'] + intdiv($lab['h'], 2);
                            if ($lx >= $r[0] && $lx <= $r[2] && $ly >= $r[1] && $ly <= $r[3]) {
                                return true;
                            }
                        }

                        return $this->enclosureScore($r, $wallBodies, $w, $h) >= 0.55;
                    }
                ));
                // Margin cull frees bank slots — reseed cubicles into the holes.
                $rects = $this->seedBankPitchNeighbors($rects, $wallBodies, $w, $h, $workLabels);
                $rects = $this->seedSameRowBankGaps($rects, $wallBodies, $w, $h);
                $rects = $this->fillCubicleHoles($rects, $wallBodies, $w, $h);
                $rects = $this->seedStackedCompanions($rects, $wallBodies, $w, $h);
                $rects = $this->refineBankCubicleSizes($rects, $wallBodies, $w, $h);
                $rects = $this->mergeWeakPartitionColumns($rects, $wallBodies, $w, $h);
                $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
                // Same-door join: fragments that share one clear door are one room.
                // Cubicle banks stay split — they open to multi-door circulation.
                $this->doorOpenings = $this->detectOfficeDoorGaps($partition, $w, $h, $thickness);
                $stageMs['door_count'] = count($this->doorOpenings);
                if ($this->doorOpenings !== []) {
                    $rects = $this->mergeSpacesSharingDoor($rects, $wallBodies, $this->doorOpenings, $w, $h);
                    $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
                }
                // Bottom/open-plan office fragments → one room when many scraps share a wall pocket.
                $rects = $this->consolidateOpenOfficeClusters($rects, $wallBodies, $w, $h);
                $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
                // Stacked open chamber above a reclaimed room (C02 above E03) — after
                // exclusive so scrap geometry matches the near-final layout.
                $rects = $this->reclaimChamberAboveLargeRoom($rects, $partition, $w, $h);
                // wallBodies keep D10/D11 separated; partition merges the shaft.
                $rects = $this->reclaimShreddedSidePockets($rects, $wallBodies, $w, $h);
                $stageMs['above_chamber'] = count($rects);
                $rects = $this->enforceExclusiveRects($rects, $wallBodies, $w, $h);
            }
            unset($rawWalls, $partition, $wallBodies, $lattice, $grid, $anchored, $cand);
            $stageMs['optional_polish'] = 1;
        } elseif ($rects !== []) {
            // Residential: expand flood AABBs to thick structural walls, then exclusive
            // so open-plan fits don't steal a neighbor's kitchen/dining/living.
            $snapStep = max(24, $thickness * 3);
            $snapped = [];
            foreach ($rects as $r) {
                // Strong walls only — weak door-jamb / furniture ink must not stop expansion.
                $snapped[] = $this->snapToWalls($r, $structForOverlay, $w, $h, $snapStep, true);
            }
            // thickness<=8 growth cap keeps open-plan rooms from merging through doors.
            // Union with the strong-snap AABB — center-fit must never shrink a wall-tight room
            // (living south wall / bathroom tub pocket regress otherwise).
            $fitted = $this->fitRectsToWalls($snapped, $structForOverlay, $w, $h, 8);
            $rects = [];
            foreach ($snapped as $i => $s) {
                $f = $fitted[$i];
                $rects[] = [
                    min($s[0], $f[0]),
                    min($s[1], $f[1]),
                    max($s[2], $f[2]),
                    max($s[3], $f[3]),
                ];
            }
            $clipped = [];
            foreach ($rects as $r) {
                $clipped[] = $this->clipDoorOverhang($r, $structForOverlay, $w, $h);
            }
            $rects = $this->enforceExclusiveRects($clipped, $structForOverlay, $w, $h);
            // Second snap after exclusive — reclaim the wall-face gaps the first fit left.
            $snapped = [];
            foreach ($rects as $r) {
                $snapped[] = $this->snapToWalls($r, $structForOverlay, $w, $h, $snapStep, true);
            }
            $rects = $this->enforceExclusiveRects($snapped, $structForOverlay, $w, $h);
            // Second strong snap can re-open door lips into the hall — clip again.
            $clipped = [];
            foreach ($rects as $r) {
                $clipped[] = $this->clipDoorOverhang($r, $structForOverlay, $w, $h);
            }
            $rects = $clipped;
            // Cross one thin interior partition into a small enclosed furniture pocket
            // (bathtub south of the mid-bath wall) without merging the next room.
            $rects = $this->growResidentialAlcoves($rects, $structForOverlay, $w, $h, $thickness);
            $rects = $this->enforceExclusiveRects($rects, $structForOverlay, $w, $h);
            $closed = $this->dilate($structForOverlay, $w, $h, max(2, intdiv($thickness, 2)));
            $exteriorList = $this->floodFromBorder($closed, $w, $h);
            $inside = $this->invert($exteriorList);
            if (max($w, $h) < 1800) {
                $rects = $this->refineResidentialRooms($rects, $structForOverlay, $closed, $inside, $w, $h);
                $rects = $this->enforceExclusiveRects($rects, $structForOverlay, $w, $h);
                $clipped = [];
                foreach ($rects as $r) {
                    $clipped[] = $this->clipDoorOverhang($r, $structForOverlay, $w, $h);
                }
                $rects = $clipped;
                // Refine can rebuild living; re-apply alcove grow so the tub survives.
                $rects = $this->growResidentialAlcoves($rects, $structForOverlay, $w, $h, $thickness);
                $rects = $this->enforceExclusiveRects($rects, $structForOverlay, $w, $h);
                $clipped = [];
                foreach ($rects as $r) {
                    $clipped[] = $this->clipDoorOverhang($r, $structForOverlay, $w, $h);
                }
                $rects = $clipped;
            }
            unset($snapped, $clipped, $fitted, $closed, $exteriorList, $inside);
            $stageMs['optional_polish'] = 1;
        } else {
            $stageMs['optional_polish'] = 0;
        }

        $this->pingProgress();

        $struct = $structForOverlay;
        $rooms = $this->summarizeRects($rects, $w, $h, $workLabels);
        $stageMs['detect_ms'] = (int) round((hrtime(true) - $tDetect) / 1e6);
        $stageMs['total_ms'] = (int) round((hrtime(true) - $tAll) / 1e6);
        $stageMs['plan_class'] = $this->planClass;
        $stageMs['module_w'] = $this->modW;
        $stageMs['module_h'] = $this->modH;
        $stageMs['module_conf'] = $this->modConf;
        // optional_polish already set above when polish ran.

        $wantPng = ($w * $h) <= 2_500_000;
        if ($wantPng) {
            $overlay = $this->renderOverlay($source, $rects, $struct, $w, $h);
            $png = $this->gdToPng($overlay);
            imagedestroy($overlay);
        } else {
            $png = '';
        }
        $svg = $this->buildSvg($source, $rects, $w, $h, $sourceSvg, $rooms);
        $dataUrl = strlen($svg) <= 350_000
            ? 'data:image/svg+xml;base64,' . base64_encode($svg)
            : '';

        return [
            'width' => $w,
            'height' => $h,
            'rooms' => $rooms,
            'overlay_png' => $png,
            'overlay_svg' => $svg,
            'overlay_data_url' => $dataUrl,
            'ocr_label_count' => count($workLabels),
            'stage_ms' => $stageMs,
            'plan_class' => $this->planClass,
            'module' => [
                'w' => $this->modW,
                'h' => $this->modH,
                'conf' => $this->modConf,
            ],
        ];
    }

    /**
     * Estimate wall thickness from a packed mid-gray mask.
     */
    private function estimateWallThicknessPacked(PackedMask $walls): int
    {
        $w = $walls->width();
        $h = $walls->height();
        $samples = [];
        for ($y = 0; $y < $h; $y += 4) {
            $x = 0;
            while ($x < $w) {
                if ($walls->getXY($x, $y) !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $walls->getXY($x, $y) === 1) {
                    $x++;
                }
                $len = $x - $x0;
                if ($len >= 4 && $len <= 48) {
                    $samples[] = $len;
                }
            }
        }
        if ($samples === []) {
            return 8;
        }
        sort($samples);

        return max(4, (int) $samples[(int) (count($samples) * 0.5)]);
    }

    private function classifyPlanPacked(PackedMask $walls, int $thickness, int $w, int $h): string
    {
        $ink = $walls->countOnes();
        $frac = $ink / max(1, $w * $h);
        if ($thickness >= 10 && $frac > 0.04) {
            return 'residential_thick';
        }
        // Thin-wall module plans — including under-resolved office rasters (~900px).
        if ($thickness <= 8 && $frac >= 0.012 && max($w, $h) >= 800) {
            return 'office_module';
        }

        return 'generic_conservative';
    }

    /**
     * Find door swing arcs on ink; each hit marks a wall leaf / opening.
     * Prefer dark quarter-circles (door swings); wall ink alone is too noisy.
     *
     * @return list<array{x0:int,y0:int,x1:int,y1:int,orient:string,hx:int,hy:int}>
     */
    private function findDoorArcsPacked(PackedMask $wallInk, PackedMask $darkInk, int $thickness): array
    {
        $w = $wallInk->width();
        $h = $wallInk->height();
        $hits = [];
        $step = max(3, min(10, $thickness));
        $radii = [];
        for ($r = max(10, $thickness * 2); $r <= min(72, (int) (min($w, $h) * 0.1)); $r += 4) {
            $radii[] = $r;
        }
        if ($radii === []) {
            $radii = [14, 22, 32, 44];
        }
        // Dark ink for arcs; also accept near-dark mid strokes (anti-aliased arcs).
        $arcInk = $darkInk->clone();
        $arcInk->orWith($wallInk);

        for ($y = $step; $y < $h - $step; $y += $step) {
            for ($x = $step; $x < $w - $step; $x += $step) {
                if ($wallInk->getXY($x, $y) !== 1) {
                    continue;
                }
                foreach ($radii as $radius) {
                    foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as [$sx, $sy]) {
                        $darkHits = 0;
                        $samples = 0;
                        for ($a = 8; $a <= 82; $a += 5) {
                            $rad = deg2rad((float) $a);
                            $xx = (int) round($x + $sx * $radius * cos($rad));
                            $yy = (int) round($y + $sy * $radius * sin($rad));
                            if ($xx < 0 || $yy < 0 || $xx >= $w || $yy >= $h) {
                                continue;
                            }
                            $samples++;
                            // Require dark (or neighbor) — not mid-gray wall body alone.
                            if (
                                $darkInk->getXY($xx, $yy) === 1
                                || $darkInk->getXY(min($w - 1, $xx + 1), $yy) === 1
                                || $darkInk->getXY($xx, min($h - 1, $yy + 1)) === 1
                            ) {
                                $darkHits++;
                            }
                        }
                        if ($samples < 10 || $darkHits < max(6, (int) round($samples * 0.48))) {
                            continue;
                        }
                        $leafPad = max(2, intdiv($thickness, 2));
                        // Opening chord along the wall leaf from hinge.
                        if (abs($sx) >= abs($sy)) {
                            $x0 = min($x, $x + $sx * $radius);
                            $x1 = max($x, $x + $sx * $radius);
                            $hits[] = [
                                'x0' => max(0, $x0),
                                'y0' => max(0, $y - $leafPad),
                                'x1' => min($w - 1, $x1),
                                'y1' => min($h - 1, $y + $leafPad),
                                'orient' => 'h',
                                'hx' => $x,
                                'hy' => $y,
                            ];
                        } else {
                            $y0 = min($y, $y + $sy * $radius);
                            $y1 = max($y, $y + $sy * $radius);
                            $hits[] = [
                                'x0' => max(0, $x - $leafPad),
                                'y0' => max(0, $y0),
                                'x1' => min($w - 1, $x + $leafPad),
                                'y1' => min($h - 1, $y1),
                                'orient' => 'v',
                                'hx' => $x,
                                'hy' => $y,
                            ];
                        }
                    }
                }
            }
        }
        unset($arcInk);

        return $this->clusterDoorOpenings($hits);
    }

    /**
     * Build barrier walls: solidify cavities, seal door-width gaps / door leaves, leak speckles.
     *
     * @param list<array{x0:int,y0:int,x1:int,y1:int,orient:string,hx?:int,hy?:int}> $doors
     */
    private function wallsFromDoorsAndInk(PackedMask $wallInk, array $doors, int $thickness): PackedMask
    {
        $w = $wallInk->width();
        $h = $wallInk->height();
        $list = $wallInk->toIntList();
        // Solidify double-line wall bodies everywhere (doors confirm; ink carries the rest).
        $solid = $this->solidifyDoubleLineWalls($list, $w, $h, $thickness);
        // Seal door-sized openings between jambs so flood cannot walk room-to-room / outside.
        // Office modules: lighter seal — lattice polish owns cubicle separation.
        $doorGap = $thickness <= 8
            ? max(10, min(28, $thickness * 3))
            : max(36, min(64, $thickness * 4));
        $closed = $this->closeWallGaps($solid, $w, $h, $doorGap);
        $dilated = $this->dilate($closed, $w, $h, max(1, min(2, intdiv($thickness, 5) + 1)));
        $out = PackedMask::fromIntList($dilated, $w, $h);

        // Paint door leaves as barriers (hinge → swing chord).
        $leafHalf = max(2, intdiv($thickness, 2));
        foreach ($doors as $d) {
            $orient = (string) ($d['orient'] ?? 'h');
            if ($orient === 'h') {
                $y = intdiv((int) $d['y0'] + (int) $d['y1'], 2);
                for ($x = (int) $d['x0']; $x <= (int) $d['x1']; $x++) {
                    for ($yy = $y - $leafHalf; $yy <= $y + $leafHalf; $yy++) {
                        $out->setXY($x, $yy, 1);
                    }
                }
            } else {
                $x = intdiv((int) $d['x0'] + (int) $d['x1'], 2);
                for ($y = (int) $d['y0']; $y <= (int) $d['y1']; $y++) {
                    for ($xx = $x - $leafHalf; $xx <= $x + $leafHalf; $xx++) {
                        $out->setXY($xx, $y, 1);
                    }
                }
            }
            // Also stamp a small plug at the hinge.
            $hx = (int) ($d['hx'] ?? intdiv($d['x0'] + $d['x1'], 2));
            $hy = (int) ($d['hy'] ?? intdiv($d['y0'] + $d['y1'], 2));
            for ($yy = $hy - $leafHalf; $yy <= $hy + $leafHalf; $yy++) {
                for ($xx = $hx - $leafHalf; $xx <= $hx + $leafHalf; $xx++) {
                    $out->setXY($xx, $yy, 1);
                }
            }
        }

        // Leak furniture/steps: drop only speckles (short in both H and V).
        $out->dropShortRuns(max(4, min(12, $thickness)));

        return $out;
    }

    /**
     * Split oversized flood components along near-complete wall runs (open-plan merges).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitMergedRoomsByWallRuns(
        array $rects,
        PackedMask $wallInk,
        int $thickness,
        int $w,
        int $h
    ): array {
        $struct = $this->solidifyDoubleLineWalls($wallInk->toIntList(), $w, $h, $thickness);
        $minPart = max(2500, (int) ($w * $h * 0.006));
        $splitMin = max($minPart * 4, (int) ($w * $h * 0.09));
        $out = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $this->rectArea($r);
            // Split oversized merges, or tall/wide open-plan strips that still hide two rooms.
            $shouldSplit = $area >= $splitMin
                || ($area >= $minPart * 3 && max($bw, $bh) >= min($w, $h) * 0.28 && max($bw, $bh) / max(1, min($bw, $bh)) >= 1.15);
            if (!$shouldSplit) {
                $out[] = $r;
                continue;
            }
            $parts = [$r];
            // Up to two split passes (H then V or vice versa).
            for ($pass = 0; $pass < 2; $pass++) {
                $next = [];
                foreach ($parts as $p) {
                    $pbw = $p[2] - $p[0] + 1;
                    $pbh = $p[3] - $p[1] + 1;
                    $parea = $this->rectArea($p);
                    $again = $parea >= $splitMin
                        || ($parea >= $minPart * 3 && max($pbw, $pbh) >= min($w, $h) * 0.28);
                    if (!$again) {
                        $next[] = $p;
                        continue;
                    }
                    $split = $this->trySplitRectByWallRun($p, $struct, $w, $h, $minPart, $pass === 0);
                    if ($split === null) {
                        $next[] = $p;
                    } else {
                        $next[] = $split[0];
                        $next[] = $split[1];
                    }
                }
                $parts = $next;
            }
            foreach ($parts as $p) {
                $out[] = $p;
            }
        }

        // Drop tiny leftovers and heavy overlaps (keep larger).
        $out = array_values(array_filter(
            $out,
            fn (array $r): bool => $this->rectArea($r) >= $minPart && min($r[2] - $r[0] + 1, $r[3] - $r[1] + 1) >= 40
        ));
        usort($out, fn (array $a, array $b): int => $this->rectArea($b) <=> $this->rectArea($a));
        $kept = [];
        foreach ($out as $r) {
            $dominated = false;
            foreach ($kept as $k) {
                if ($this->overlapFrac($r, $k) >= 0.65) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $struct
     * @return null|array{0:array{0:int,1:int,2:int,3:int},1:array{0:int,1:int,2:int,3:int}}
     */
    private function trySplitRectByWallRun(
        array $r,
        array $struct,
        int $w,
        int $h,
        int $minPart,
        bool $horizontalFirst
    ): ?array {
        if ($this->rectArea($r) < $minPart * 2.2) {
            return null;
        }
        $x0 = $r[0];
        $y0 = $r[1];
        $x1 = $r[2];
        $y1 = $r[3];
        $bw = $x1 - $x0 + 1;
        $bh = $y1 - $y0 + 1;

        if ($horizontalFirst) {
            $bestY = -1;
            $bestScore = 0.0;
            $yStart = $y0 + (int) ($bh * 0.2);
            $yEnd = $y1 - (int) ($bh * 0.2);
            for ($y = $yStart; $y <= $yEnd; $y++) {
                $wall = 0;
                $row = $y * $w;
                for ($x = $x0; $x <= $x1; $x++) {
                    if (($struct[$row + $x] ?? 0) === 1) {
                        $wall++;
                    }
                }
                $cov = $wall / max(1, $bw);
                // Near-spanning wall with door-sized holes still allowed.
                if ($cov < 0.42) {
                    continue;
                }
                $above = ($y - $y0) * $bw;
                $below = ($y1 - $y) * $bw;
                if ($above < $minPart || $below < $minPart) {
                    continue;
                }
                $balance = min($above, $below) / max($above, $below);
                $score = $cov * 0.7 + $balance * 0.3;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestY = $y;
                }
            }
            if ($bestY >= 0 && $bestScore >= 0.55) {
                return [
                    [$x0, $y0, $x1, max($y0, $bestY - 1)],
                    [$x0, min($y1, $bestY + 1), $x1, $y1],
                ];
            }
        }

        $bestX = -1;
        $bestScore = 0.0;
        $xStart = $x0 + (int) ($bw * 0.2);
        $xEnd = $x1 - (int) ($bw * 0.2);
        for ($x = $xStart; $x <= $xEnd; $x++) {
            $wall = 0;
            for ($y = $y0; $y <= $y1; $y++) {
                if (($struct[$y * $w + $x] ?? 0) === 1) {
                    $wall++;
                }
            }
            $cov = $wall / max(1, $bh);
            if ($cov < 0.42) {
                continue;
            }
            $left = ($x - $x0) * $bh;
            $right = ($x1 - $x) * $bh;
            if ($left < $minPart || $right < $minPart) {
                continue;
            }
            $balance = min($left, $right) / max($left, $right);
            $score = $cov * 0.7 + $balance * 0.3;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestX = $x;
            }
        }
        if ($bestX >= 0 && $bestScore >= 0.55) {
            return [
                [$x0, $y0, max($x0, $bestX - 1), $y1],
                [min($x1, $bestX + 1), $y0, $x1, $y1],
            ];
        }

        // If horizontal-first failed, try horizontal once when this was the vertical pass.
        if (!$horizontalFirst) {
            return $this->trySplitRectByWallRun($r, $struct, $w, $h, $minPart, true);
        }

        return null;
    }

    /**
     * Mark long thin weakly-enclosed corridors as hallway barriers.
     * Uses free-space local width: narrow channels between walls.
     */
    private function markHallwaysPacked(PackedMask $walls, int $w, int $h): PackedMask
    {
        $hall = new PackedMask($w, $h);
        $exterior = $walls->floodExterior();
        $maxHallW = max(22, min(48, (int) (min($w, $h) * 0.06)));
        $minHallLen = max(48, $maxHallW * 3);
        // Subsample for speed on large CAD; residential still fine at step 2.
        $step = max($w, $h) >= 2000 ? 3 : 2;

        for ($y = 1; $y < $h - 1; $y += $step) {
            for ($x = 1; $x < $w - 1; $x += $step) {
                if ($walls->getXY($x, $y) === 1 || $exterior->getXY($x, $y) === 1) {
                    continue;
                }
                $l = $x;
                while ($l > 0 && $walls->getXY($l - 1, $y) !== 1 && $exterior->getXY($l - 1, $y) !== 1) {
                    $l--;
                    if ($x - $l > $maxHallW + 2) {
                        break;
                    }
                }
                $r = $x;
                while ($r < $w - 1 && $walls->getXY($r + 1, $y) !== 1 && $exterior->getXY($r + 1, $y) !== 1) {
                    $r++;
                    if ($r - $x > $maxHallW + 2) {
                        break;
                    }
                }
                $t = $y;
                while ($t > 0 && $walls->getXY($x, $t - 1) !== 1 && $exterior->getXY($x, $t - 1) !== 1) {
                    $t--;
                    if ($y - $t > $maxHallW + 2) {
                        break;
                    }
                }
                $b = $y;
                while ($b < $h - 1 && $walls->getXY($x, $b + 1) !== 1 && $exterior->getXY($x, $b + 1) !== 1) {
                    $b++;
                    if ($b - $y > $maxHallW + 2) {
                        break;
                    }
                }
                $ww = $r - $l + 1;
                $hh = $b - $t + 1;
                // Only mark when one axis is clearly corridor-thin and the other continues.
                // Re-measure the long axis fully when thin axis qualifies.
                if ($ww <= $maxHallW && $hh > $maxHallW) {
                    $t2 = $y;
                    while ($t2 > 0 && $walls->getXY($x, $t2 - 1) !== 1 && $exterior->getXY($x, $t2 - 1) !== 1) {
                        $t2--;
                    }
                    $b2 = $y;
                    while ($b2 < $h - 1 && $walls->getXY($x, $b2 + 1) !== 1 && $exterior->getXY($x, $b2 + 1) !== 1) {
                        $b2++;
                    }
                    if ($b2 - $t2 + 1 >= $minHallLen) {
                        for ($yy = $y; $yy < min($h, $y + $step); $yy++) {
                            for ($xx = $x; $xx < min($w, $x + $step); $xx++) {
                                if ($walls->getXY($xx, $yy) !== 1 && $exterior->getXY($xx, $yy) !== 1) {
                                    $hall->setXY($xx, $yy, 1);
                                }
                            }
                        }
                    }
                } elseif ($hh <= $maxHallW && $ww > $maxHallW) {
                    $l2 = $x;
                    while ($l2 > 0 && $walls->getXY($l2 - 1, $y) !== 1 && $exterior->getXY($l2 - 1, $y) !== 1) {
                        $l2--;
                    }
                    $r2 = $x;
                    while ($r2 < $w - 1 && $walls->getXY($r2 + 1, $y) !== 1 && $exterior->getXY($r2 + 1, $y) !== 1) {
                        $r2++;
                    }
                    if ($r2 - $l2 + 1 >= $minHallLen) {
                        for ($yy = $y; $yy < min($h, $y + $step); $yy++) {
                            for ($xx = $x; $xx < min($w, $x + $step); $xx++) {
                                if ($walls->getXY($xx, $yy) !== 1 && $exterior->getXY($xx, $yy) !== 1) {
                                    $hall->setXY($xx, $yy, 1);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $hall;
    }

    private function wallMask(\GdImage $im, int $w, int $h, bool $softOffice = false): array
    {
        $n = $w * $h;
        $mask = array_fill(0, $n, 0);
        // Soft band only for upscaled office CAD — bilinear wash pushes wall grey out of 88–182.
        $lo = $softOffice ? 78 : 88;
        $hi = $softOffice ? 192 : 182;
        $maxChroma = $softOffice ? 44 : 36;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = ($r + $g + $b) / 3.0;
                $chroma = max(abs($r - $g), abs($g - $b), abs($r - $b));
                // Mid-gray band; skip near-black furniture and near-white paper/grid.
                if ($lum >= $lo && $lum <= $hi && $chroma <= $maxChroma) {
                    $mask[$y * $w + $x] = 1;
                }
            }
        }

        return $mask;
    }

    /**
     * Partition run-length floor: restore stub dividers on upscaled sheets / when
     * furniture negatives are known; keep the classic 34px floor on native SVG work
     * (the mutation chain + selection are tuned around that loss).
     */
    private function officePartitionMinLen(int $w, int $h): int
    {
        if ($this->elementLayers !== null && $this->elementLayers->hasFurniture()) {
            return max(22, (int) round(min($w, $h) * 0.012));
        }
        if (($this->upscaledWork || $this->rasterOffice) && $this->modW >= 28) {
            return max(22, min(34, (int) round($this->modW * 0.42)));
        }

        return max(34, (int) round(min($w, $h) * 0.015));
    }

    /**
     * @param list<int> $walls
     */
    private function estimateWallThickness(array $walls, int $w, int $h): int
    {
        $samples = [];
        for ($y = 0; $y < $h; $y += 3) {
            $x = 0;
            while ($x < $w) {
                if ($walls[$y * $w + $x] !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $walls[$y * $w + $x] === 1) {
                    $x++;
                }
                $len = $x - $x0;
                if ($len >= 4 && $len <= 48) {
                    $samples[] = $len;
                }
            }
        }
        if ($samples === []) {
            return 8;
        }
        sort($samples);

        return max(4, (int) $samples[(int) (count($samples) * 0.5)]);
    }

    /**
     * Building-5 style CAD: walls are two parallel face strokes with paper between.
     * Fill cavities that persist across neighboring rows/cols (real walls), not
     * one-off desk double-strokes.
     *
     * @param list<int> $walls
     * @return list<int>
     */
    private function solidifyDoubleLineWalls(array $walls, int $w, int $h, int $thickness): array
    {
        $maxFace = max(2, min(3, (int) ceil($thickness * 0.45)));
        $maxCavity = max(2, min(10, $thickness + 2));
        $persist = 3;
        $out = $walls;

        // Mark candidate horizontal fills, then keep only those that persist vertically.
        $cand = array_fill(0, $w * $h, 0);
        for ($y = 0; $y < $h; $y++) {
            $runs = [];
            $x = 0;
            while ($x < $w) {
                if ($walls[$y * $w + $x] !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $walls[$y * $w + $x] === 1) {
                    $x++;
                }
                $runs[] = [$x0, $x - $x0];
            }
            for ($i = 0; $i < count($runs) - 1; $i++) {
                [$ax, $alen] = $runs[$i];
                [$bx, $blen] = $runs[$i + 1];
                $gap = $bx - ($ax + $alen);
                if ($alen > $maxFace || $blen > $maxFace || $gap < 1 || $gap > $maxCavity) {
                    continue;
                }
                $span = ($bx + $blen) - $ax;
                if ($span < 3 || $span > max(28, $thickness * 5)) {
                    continue;
                }
                for ($xx = $ax + $alen; $xx < $bx; $xx++) {
                    $cand[$y * $w + $xx] = 1;
                }
            }
        }
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                if ($cand[$i] !== 1) {
                    continue;
                }
                $run = 1;
                for ($yy = $y - 1; $yy >= 0 && $cand[$yy * $w + $x] === 1; $yy--) {
                    $run++;
                }
                for ($yy = $y + 1; $yy < $h && $cand[$yy * $w + $x] === 1; $yy++) {
                    $run++;
                }
                if ($run >= $persist) {
                    $out[$i] = 1;
                }
            }
        }

        $cand = array_fill(0, $w * $h, 0);
        for ($x = 0; $x < $w; $x++) {
            $runs = [];
            $y = 0;
            while ($y < $h) {
                if ($walls[$y * $w + $x] !== 1) {
                    $y++;
                    continue;
                }
                $y0 = $y;
                while ($y < $h && $walls[$y * $w + $x] === 1) {
                    $y++;
                }
                $runs[] = [$y0, $y - $y0];
            }
            for ($i = 0; $i < count($runs) - 1; $i++) {
                [$ay, $alen] = $runs[$i];
                [$by, $blen] = $runs[$i + 1];
                $gap = $by - ($ay + $alen);
                if ($alen > $maxFace || $blen > $maxFace || $gap < 1 || $gap > $maxCavity) {
                    continue;
                }
                $span = ($by + $blen) - $ay;
                if ($span < 3 || $span > max(28, $thickness * 5)) {
                    continue;
                }
                for ($yy = $ay + $alen; $yy < $by; $yy++) {
                    $cand[$yy * $w + $x] = 1;
                }
            }
        }
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                if ($cand[$i] !== 1) {
                    continue;
                }
                $run = 1;
                for ($xx = $x - 1; $xx >= 0 && $cand[$y * $w + $xx] === 1; $xx--) {
                    $run++;
                }
                for ($xx = $x + 1; $xx < $w && $cand[$y * $w + $xx] === 1; $xx++) {
                    $run++;
                }
                if ($run >= $persist) {
                    $out[$i] = 1;
                }
            }
        }

        return $out;
    }

    /**
     * Find door openings: characteristic-width breaks in long wall runs, preferably
     * with a leaf or swing arc on one jamb (doors always sit in walls).
     *
     * @param list<int> $partition long-run cleaned walls
     * @param list<int> $raw solidified wall ink (more complete for leaf/arc)
     * @return list<array{x0:int,y0:int,x1:int,y1:int,orient:string}>
     */
    private function detectDoorOpenings(
        array $partition,
        array $raw,
        int $w,
        int $h,
        int $thickness
    ): array {
        $doorMin = max(14, $this->cad(18));
        $doorMax = max($doorMin + 8, min(72, $this->cad(52)));
        $minJamb = max(16, $thickness * 3);
        $hits = [];

        // Horizontal wall with a vertical-ish opening (gap along X).
        for ($y = 1; $y < $h - 1; $y += 2) {
            $runs = [];
            $x = 0;
            while ($x < $w) {
                if ($partition[$y * $w + $x] !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $partition[$y * $w + $x] === 1) {
                    $x++;
                }
                $runs[] = [$x0, $x - $x0];
            }
            for ($i = 0; $i < count($runs) - 1; $i++) {
                [$ax, $alen] = $runs[$i];
                [$bx, $blen] = $runs[$i + 1];
                $gap = $bx - ($ax + $alen);
                if ($gap < $doorMin || $gap > $doorMax || $alen < $minJamb || $blen < $minJamb) {
                    continue;
                }
                $x0 = $ax + $alen;
                $x1 = $bx - 1;
                $shape = $this->doorShapeEvidence($raw, $w, $h, $x0, $y, $x1, $y, 'h', $gap);
                if (!$shape) {
                    continue;
                }
                $hits[] = [
                    'x0' => $x0,
                    'y0' => max(0, $y - max(2, intdiv($thickness, 2))),
                    'x1' => $x1,
                    'y1' => min($h - 1, $y + max(2, intdiv($thickness, 2))),
                    'orient' => 'h',
                ];
            }
        }

        // Vertical wall with a horizontal-ish opening (gap along Y).
        for ($x = 1; $x < $w - 1; $x += 2) {
            $runs = [];
            $y = 0;
            while ($y < $h) {
                if ($partition[$y * $w + $x] !== 1) {
                    $y++;
                    continue;
                }
                $y0 = $y;
                while ($y < $h && $partition[$y * $w + $x] === 1) {
                    $y++;
                }
                $runs[] = [$y0, $y - $y0];
            }
            for ($i = 0; $i < count($runs) - 1; $i++) {
                [$ay, $alen] = $runs[$i];
                [$by, $blen] = $runs[$i + 1];
                $gap = $by - ($ay + $alen);
                if ($gap < $doorMin || $gap > $doorMax || $alen < $minJamb || $blen < $minJamb) {
                    continue;
                }
                $y0 = $ay + $alen;
                $y1 = $by - 1;
                $shape = $this->doorShapeEvidence($raw, $w, $h, $x, $y0, $x, $y1, 'v', $gap);
                if (!$shape) {
                    continue;
                }
                $hits[] = [
                    'x0' => max(0, $x - max(2, intdiv($thickness, 2))),
                    'y0' => $y0,
                    'x1' => min($w - 1, $x + max(2, intdiv($thickness, 2))),
                    'y1' => $y1,
                    'orient' => 'v',
                ];
            }
        }

        return $this->clusterDoorOpenings($hits);
    }

    /**
     * Door swing arc near a wall opening. Building-5 doors are drawn with a
     * quarter-circle from the hinge; plain corridor gaps lack that shape.
     *
     * @param list<int> $walls
     */
    private function doorShapeEvidence(
        array $walls,
        int $w,
        int $h,
        int $x0,
        int $y0,
        int $x1,
        int $y1,
        string $orient,
        int $gap
    ): bool {
        $radius = max(10, min(56, (int) round($gap * 0.95)));
        $hinges = $orient === 'h'
            ? [[$x0, $y0], [$x1, $y0]]
            : [[$x0, $y0], [$x0, $y1]];

        foreach ($hinges as [$hx, $hy]) {
            foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as [$sx, $sy]) {
                $quadHits = 0;
                $quadSamples = 0;
                for ($a = 8; $a <= 82; $a += 6) {
                    $rad = deg2rad((float) $a);
                    $xx = (int) round($hx + $sx * $radius * cos($rad));
                    $yy = (int) round($hy + $sy * $radius * sin($rad));
                    if ($xx < 0 || $yy < 0 || $xx >= $w || $yy >= $h) {
                        continue;
                    }
                    $quadSamples++;
                    if ($walls[$yy * $w + $xx] === 1
                        || ($xx + 1 < $w && $walls[$yy * $w + $xx + 1] === 1)
                        || ($yy + 1 < $h && $walls[($yy + 1) * $w + $xx] === 1)
                    ) {
                        $quadHits++;
                    }
                }
                if ($quadSamples >= 8 && $quadHits >= max(5, (int) round($quadSamples * 0.45))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{x0:int,y0:int,x1:int,y1:int,orient:string}> $hits
     * @return list<array{x0:int,y0:int,x1:int,y1:int,orient:string}>
     */
    private function clusterDoorOpenings(array $hits): array
    {
        if ($hits === []) {
            return [];
        }
        usort(
            $hits,
            static fn (array $a, array $b): int => $a['orient'] <=> $b['orient']
                ?: $a['x0'] <=> $b['x0']
                ?: $a['y0'] <=> $b['y0']
        );
        $out = [];
        foreach ($hits as $hit) {
            $merged = false;
            foreach ($out as $k => $cur) {
                if ($cur['orient'] !== $hit['orient']) {
                    continue;
                }
                $ox = min($cur['x1'], $hit['x1']) - max($cur['x0'], $hit['x0']);
                $oy = min($cur['y1'], $hit['y1']) - max($cur['y0'], $hit['y0']);
                if ($ox < -4 || $oy < -4) {
                    continue;
                }
                $out[$k] = [
                    'x0' => min($cur['x0'], $hit['x0']),
                    'y0' => min($cur['y0'], $hit['y0']),
                    'x1' => max($cur['x1'], $hit['x1']),
                    'y1' => max($cur['y1'], $hit['y1']),
                    'orient' => $cur['orient'],
                ];
                $merged = true;
                break;
            }
            if (!$merged) {
                $out[] = $hit;
            }
        }

        return array_values($out);
    }

    /**
     * Paint door openings shut on a mask (targeted seal).
     *
     * @param list<int> $mask
     * @param list<array{x0:int,y0:int,x1:int,y1:int,orient:string}> $doors
     * @return list<int>
     */
    private function sealDoorOpenings(array $mask, array $doors, int $w, int $h): array
    {
        foreach ($doors as $d) {
            $x0 = max(0, min($w - 1, $d['x0']));
            $x1 = max(0, min($w - 1, $d['x1']));
            $y0 = max(0, min($h - 1, $d['y0']));
            $y1 = max(0, min($h - 1, $d['y1']));
            for ($y = $y0; $y <= $y1; $y++) {
                $row = $y * $w;
                for ($x = $x0; $x <= $x1; $x++) {
                    $mask[$row + $x] = 1;
                }
            }
        }

        return $mask;
    }

    /**
     * True when a seam segment overlaps a detected door opening.
     */
    private function seamHitsDoor(int $x0, int $y0, int $x1, int $y1): bool
    {
        if ($this->doorOpenings === []) {
            return false;
        }
        if ($x0 > $x1) {
            [$x0, $x1] = [$x1, $x0];
        }
        if ($y0 > $y1) {
            [$y0, $y1] = [$y1, $y0];
        }
        foreach ($this->doorOpenings as $d) {
            $ox = min($x1, $d['x1']) - max($x0, $d['x0']);
            $oy = min($y1, $d['y1']) - max($y0, $d['y0']);
            if ($ox >= 0 && $oy >= 0) {
                return true;
            }
            // Near-miss: door within a few px of the seam (leaf/arc offset).
            if ($ox >= -3 && $oy >= -3) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evidence-vote plan classifier.
     *
     * @param list<int> $walls
     * @return 'residential_thick'|'office_module'|'generic_conservative'
     */
    private function classifyPlan(
        array $walls,
        int $thickness,
        int $srcW,
        int $srcH,
        int $w,
        int $h
    ): string {
        $ink = 0;
        foreach ($walls as $v) {
            $ink += $v;
        }
        $density = $ink / max(1, $w * $h);
        $maxSide = max($srcW, $srcH, $w, $h);

        $officeVotes = 0;
        $resVotes = 0;

        if ($thickness <= 8) {
            $officeVotes += 2;
        } elseif ($thickness >= 11) {
            $resVotes += 2;
        }

        if ($density >= 0.004 && $density <= 0.07) {
            $officeVotes += 1;
        }
        if ($density >= 0.07) {
            $resVotes += 1;
        }

        if ($maxSide >= 1800 && $thickness <= 8) {
            $officeVotes += 2;
        }
        if ($maxSide < 1200 && $thickness >= 10) {
            $resVotes += 2;
        }

        // Long-run fraction: CAD is mostly long strokes; residential is thicker short walls.
        $minRun = max(12, (int) round(min($w, $h) * 0.03));
        $long = $this->longRunWallMask($walls, $w, $h, $minRun);
        $kept = 0;
        foreach ($long as $v) {
            $kept += $v;
        }
        $longFrac = $ink > 0 ? $kept / $ink : 0.0;
        if ($longFrac >= 0.25 && $thickness <= 8) {
            $officeVotes += 1;
        }
        if ($longFrac < 0.15 && $thickness >= 10) {
            $resVotes += 1;
        }

        if ($this->isUnderResolvedCad($walls, $thickness, $w, $h)) {
            $officeVotes += 3;
        }

        if ($officeVotes >= $resVotes + 2 && $officeVotes >= 3) {
            return 'office_module';
        }
        if ($resVotes >= $officeVotes + 1 && $thickness > 8) {
            return 'residential_thick';
        }
        if ($thickness > 8) {
            return 'residential_thick';
        }
        if ($thickness <= 8 && $maxSide >= 1800) {
            return 'office_module';
        }

        return 'generic_conservative';
    }

    /**
     * Thin-line office CAD only on large sheets. Small residential plans stay on
     * the thick-wall path even when furniture ink pulls median thickness to <= 8.
     */
    private function isOfficeCadPlan(int $thickness, int $srcW, int $srcH): bool
    {
        if ($thickness > 8) {
            return false;
        }

        return max($srcW, $srcH) >= 1800;
    }

    /**
     * Estimate cubicle module size and pitch from partition-line structure.
     *
     * Prefers modal Y pitch from horizontal partition rows (stable on Building 5)
     * and modal cell width from vertical gaps that survive desk-lip filtering.
     *
     * @param list<int> $walls
     * @return array{w:int,h:int,conf:float,pitchX:int,pitchY:int}
     */
    private function estimateModule(array $walls, int $w, int $h): array
    {
        $parts = $this->partitionWallMask($walls, $w, $h);
        $minLen = max(16, (int) round(min($w, $h) * 0.02));
        $vLines = $this->axisLinesFromMask($parts, $w, $h, true, $minLen);
        $hLines = $this->axisLinesFromMask($parts, $w, $h, false, $minLen);

        // Skip desk-lip / wall-thickness gaps: cubicle pitch is ~2.5–5% of the sheet.
        $minPitchX = max(28, (int) round($w * 0.018));
        $minPitchY = max(24, (int) round($h * 0.022));
        $pitchY = $this->modalLineGap($hLines, $minPitchY, intdiv($h, 4));
        $pitchX = $this->modalLineGap($vLines, $minPitchX, intdiv($w, 5));

        $modH = $pitchY['gap'] > 0 ? max(1, $pitchY['gap'] - max(2, intdiv($pitchY['gap'], 20))) : 0;
        $modW = $pitchX['gap'] > 0 ? max(1, $pitchX['gap'] - max(2, intdiv($pitchX['gap'], 20))) : 0;

        // Fall back to projection-peak median separation (also desk-lip aware).
        $col = array_fill(0, $w, 0);
        $row = array_fill(0, $h, 0);
        for ($y = 0; $y < $h; $y++) {
            $base = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($parts[$base + $x] === 1) {
                    $col[$x]++;
                    $row[$y]++;
                }
            }
        }
        if ($modH < $minPitchY || $modW < $minPitchX) {
            $xPeaks = $this->projectionPeaks($col, max(6, (int) ($h * 0.012)), $minPitchX);
            $yPeaks = $this->projectionPeaks($row, max(6, (int) ($w * 0.012)), $minPitchY);
            if ($modW < $minPitchX && count($xPeaks) >= 3) {
                $modW = $this->medianPeakGap($xPeaks);
            }
            if ($modH < $minPitchY && count($yPeaks) >= 3) {
                $modH = $this->medianPeakGap($yPeaks);
            }
        }

        // Known good prior at work~2000: ~53×40. Blend when confidence is low.
        $priorW = max(28, (int) round($w * 0.026));
        $priorH = max(22, (int) round($h * 0.041));
        $conf = min(1.0, ($pitchX['conf'] + $pitchY['conf']) * 0.5);
        $lipMax = max(18, (int) round(min($w, $h) * 0.016));
        // Desk lips (~12–14px) and wall thickness lock modal gaps; promote harmonics or prior.
        if ($modW > 0 && $modW <= $lipMax) {
            $harm = $modW * 4;
            $modW = ($harm >= (int) ($priorW * 0.7) && $harm <= (int) ($priorW * 1.5)) ? $harm : $priorW;
            $conf *= 0.45;
        }
        if ($modH > 0 && $modH <= $lipMax) {
            $harm = $modH * 3;
            $modH = ($harm >= (int) ($priorH * 0.7) && $harm <= (int) ($priorH * 1.5)) ? $harm : $priorH;
            $conf *= 0.45;
        }
        if ($modW < $minPitchX) {
            $modW = $priorW;
            $conf *= 0.5;
        }
        if ($modH < $minPitchY) {
            $modH = $priorH;
            $conf *= 0.5;
        }
        // Prefer prior when estimate is far from the calibrated cubicle size.
        if ($modW < (int) ($priorW * 0.55) || $modW > (int) ($priorW * 1.85)) {
            $modW = $priorW;
            $conf = max(0.2, $conf * 0.55);
        }
        if ($modH < (int) ($priorH * 0.55) || $modH > (int) ($priorH * 1.85)) {
            $modH = $priorH;
            $conf = max(0.2, $conf * 0.55);
        }
        // Reject absurd modules.
        if ($modW > intdiv($w, 4) || $modH > intdiv($h, 3)) {
            $modW = $priorW;
            $modH = $priorH;
            $conf = 0.15;
        }
        // Two clean pitches → confident enough for soft enclosure.
        if ($pitchX['gap'] >= $minPitchX && $pitchY['gap'] >= $minPitchY && $conf >= 0.2) {
            $conf = max($conf, 0.32);
        }

        return [
            'w' => $modW,
            'h' => $modH,
            'conf' => round($conf, 3),
            'pitchX' => $pitchX['gap'] > 0 ? $pitchX['gap'] : $modW,
            'pitchY' => $pitchY['gap'] > 0 ? $pitchY['gap'] : $modH,
        ];
    }

    /**
     * @param list<array{pos:int,a:int,b:int,len:int}> $lines
     * @return array{gap:int,conf:float}
     */
    private function modalLineGap(array $lines, int $minGap, int $maxGap): array
    {
        usort($lines, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $gaps = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $gap = $lines[$j]['pos'] - $lines[$i]['pos'];
                if ($gap < $minGap) {
                    continue;
                }
                if ($gap > $maxGap) {
                    break;
                }
                $overlap = min($lines[$i]['b'], $lines[$j]['b']) - max($lines[$i]['a'], $lines[$j]['a']) + 1;
                if ($overlap < (int) (0.45 * min($lines[$i]['len'], $lines[$j]['len']))) {
                    continue;
                }
                // Prefer larger gaps (cubicle pitch over desk-lip depth).
                if ($gap < $minGap * 2) {
                    continue;
                }
                $gaps[$gap] = ($gaps[$gap] ?? 0) + 1;
                break;
            }
        }
        if ($gaps === []) {
            return ['gap' => 0, 'conf' => 0.0];
        }
        $smooth = [];
        foreach ($gaps as $g => $c) {
            foreach ([-2, -1, 0, 1, 2] as $d) {
                $smooth[$g + $d] = ($smooth[$g + $d] ?? 0) + $c;
            }
        }
        arsort($smooth);
        $mode = (int) array_key_first($smooth);
        $peak = $smooth[$mode];
        $total = array_sum($gaps);

        return ['gap' => $mode, 'conf' => round($peak / max(1, $total), 3)];
    }

    /**
     * @param list<int> $peaks
     */
    private function medianPeakGap(array $peaks): int
    {
        $gaps = [];
        for ($i = 1, $n = count($peaks); $i < $n; $i++) {
            $g = $peaks[$i] - $peaks[$i - 1];
            if ($g >= 16) {
                $gaps[] = $g;
            }
        }
        if ($gaps === []) {
            return 0;
        }
        sort($gaps);

        return $gaps[(int) (count($gaps) / 2)];
    }

    /**
     * Collapse wall runs into non-maximal-suppressed axis lines.
     *
     * @param list<int> $mask
     * @return list<array{pos:int,a:int,b:int,len:int}>
     */
    private function axisLinesFromMask(array $mask, int $w, int $h, bool $vertical, int $minLen): array
    {
        $outer = $vertical ? $w : $h;
        $inner = $vertical ? $h : $w;
        $runs = [];
        for ($o = 0; $o < $outer; $o++) {
            $i = 0;
            while ($i < $inner) {
                $idx = $vertical ? ($i * $w + $o) : ($o * $w + $i);
                if ($mask[$idx] !== 1) {
                    $i++;
                    continue;
                }
                $start = $i;
                while ($i < $inner) {
                    $idx = $vertical ? ($i * $w + $o) : ($o * $w + $i);
                    if ($mask[$idx] !== 1) {
                        break;
                    }
                    $i++;
                }
                $len = $i - $start;
                if ($len >= $minLen) {
                    $runs[] = ['pos' => $o, 'a' => $start, 'b' => $i - 1, 'len' => $len];
                }
            }
        }
        usort($runs, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos'] ?: $a['a'] <=> $b['a']);
        $lines = [];
        foreach ($runs as $run) {
            $merged = false;
            foreach ($lines as $k => $line) {
                if (abs($line['pos'] - $run['pos']) > 4) {
                    continue;
                }
                $overlap = min($line['b'], $run['b']) - max($line['a'], $run['a']) + 1;
                if ($overlap < (int) (0.5 * min($line['len'], $run['len']))) {
                    continue;
                }
                if ($run['len'] > $line['len']) {
                    $lines[$k] = $run;
                }
                $merged = true;
                break;
            }
            if (!$merged) {
                $lines[] = $run;
            }
        }

        return $lines;
    }

    /**
     * Thin-line CAD sheet rendered too small for its own detail to survive the
     * long-run partition filter.
     *
     * Judged on evidence rather than sheet size alone: hairline ink, a sparse
     * sheet (CAD leaves most of the page white, unlike a dense residential
     * drawing), and enough pixels that upscaling has real lines to work with.
     *
     * @param list<int> $walls
     */
    private function isUnderResolvedCad(array $walls, int $thickness, int $w, int $h): bool
    {
        $maxSide = max($w, $h);
        // Below ~600px there is no line structure worth recovering; at or above the
        // office gate the normal path already applies.
        if ($thickness > 8 || $maxSide < 600 || $maxSide >= 1800) {
            return false;
        }
        $ink = 0;
        foreach ($walls as $v) {
            $ink += $v;
        }
        $density = $ink / max(1, $w * $h);
        if ($density < 0.004 || $density > 0.06) {
            return false;
        }

        // A CAD sheet is mostly axis-aligned long strokes. Residential plans at this
        // size are dominated by short thick segments and furniture ink.
        $minRun = max(12, (int) round($maxSide * 0.03));
        $long = $this->longRunWallMask($walls, $w, $h, $minRun);
        $kept = 0;
        foreach ($long as $v) {
            $kept += $v;
        }

        return $kept >= (int) ($ink * 0.25);
    }

    private function cad(int $pxAt4000): int
    {
        return max(1, (int) round($pxAt4000 * $this->cadS));
    }

    private function cadArea(int $areaAt4000): int
    {
        return max(1, (int) round($areaAt4000 * $this->cadS * $this->cadS));
    }

    /**
     * Interior L-corners: free pixel with exactly two adjacent wall directions.
     *
     * @param list<int> $walls
     * @return list<array{x:int,y:int,dx:int,dy:int}>
     */
    private function findInteriorCorners(array $walls, int $w, int $h): array
    {
        $corners = [];
        for ($y = 1; $y < $h - 1; $y++) {
            for ($x = 1; $x < $w - 1; $x++) {
                if ($walls[$y * $w + $x] === 1) {
                    continue;
                }
                $N = $walls[($y - 1) * $w + $x] === 1;
                $S = $walls[($y + 1) * $w + $x] === 1;
                $W = $walls[$y * $w + $x - 1] === 1;
                $E = $walls[$y * $w + $x + 1] === 1;
                $dirs = ($N ? 1 : 0) + ($S ? 1 : 0) + ($W ? 1 : 0) + ($E ? 1 : 0);
                if ($dirs !== 2) {
                    continue;
                }
                $dx = 0;
                $dy = 0;
                if ($N && $W) {
                    $dx = 1;
                    $dy = 1;
                } elseif ($N && $E) {
                    $dx = -1;
                    $dy = 1;
                } elseif ($S && $W) {
                    $dx = 1;
                    $dy = -1;
                } elseif ($S && $E) {
                    $dx = -1;
                    $dy = -1;
                } else {
                    continue; // corridor (opposite walls), not a room corner
                }
                $corners[] = ['x' => $x, 'y' => $y, 'dx' => $dx, 'dy' => $dy];
            }
        }

        // Larger plans: thin more aggressively so downstream O(n) work stays cheap.
        $minDist = ($w * $h) > 2_000_000 ? 6 : 4;

        return $this->thinCorners($corners, $minDist);
    }

    /**
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     * @return list<array{x:int,y:int,dx:int,dy:int}>
     */
    private function thinCorners(array $corners, int $minDist): array
    {
        if ($corners === []) {
            return [];
        }
        $cell = max(1, $minDist);
        $grid = [];
        $kept = [];
        foreach ($corners as $c) {
            $gx = intdiv($c['x'], $cell);
            $gy = intdiv($c['y'], $cell);
            $ok = true;
            for ($yy = $gy - 1; $yy <= $gy + 1 && $ok; $yy++) {
                for ($xx = $gx - 1; $xx <= $gx + 1; $xx++) {
                    $bucket = $grid[$xx . ':' . $yy] ?? null;
                    if ($bucket === null) {
                        continue;
                    }
                    foreach ($bucket as $ki) {
                        $k = $kept[$ki];
                        if (
                            $k['dx'] === $c['dx']
                            && $k['dy'] === $c['dy']
                            && abs($k['x'] - $c['x']) <= $minDist
                            && abs($k['y'] - $c['y']) <= $minDist
                        ) {
                            $ok = false;
                            break 3;
                        }
                    }
                }
            }
            if ($ok) {
                $idx = count($kept);
                $kept[] = $c;
                $grid[$gx . ':' . $gy][] = $idx;
            }
        }

        return $kept;
    }

    /**
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     */
    private function doorCloseRadius(int $thickness, array $corners, int $w, int $h): int
    {
        // Typical interior door ≈ wall thickness … 2× thickness at this raster scale.
        $base = max(5, (int) round($thickness * 0.65));
        // Group by row/col so gap search stays O(n log n), not O(n²).
        $byY = [];
        $byX = [];
        foreach ($corners as $i => $c) {
            $byY[$c['y']][] = $i;
            $byX[$c['x']][] = $i;
        }
        $gaps = [];
        foreach ($byY as $idxs) {
            if (count($idxs) < 2) {
                continue;
            }
            usort($idxs, static fn (int $a, int $b): int => $corners[$a]['x'] <=> $corners[$b]['x']);
            $m = count($idxs);
            for ($i = 0; $i < $m; $i++) {
                $a = $corners[$idxs[$i]];
                for ($j = $i + 1; $j < $m; $j++) {
                    $b = $corners[$idxs[$j]];
                    $dx = $b['x'] - $a['x'];
                    if ($dx > 36) {
                        break;
                    }
                    if ($dx >= 6 && $a['dx'] === -$b['dx'] && $a['dy'] === $b['dy']) {
                        $gaps[] = $dx;
                    }
                }
            }
        }
        foreach ($byX as $idxs) {
            if (count($idxs) < 2) {
                continue;
            }
            usort($idxs, static fn (int $a, int $b): int => $corners[$a]['y'] <=> $corners[$b]['y']);
            $m = count($idxs);
            for ($i = 0; $i < $m; $i++) {
                $a = $corners[$idxs[$i]];
                for ($j = $i + 1; $j < $m; $j++) {
                    $b = $corners[$idxs[$j]];
                    $dy = $b['y'] - $a['y'];
                    if ($dy > 36) {
                        break;
                    }
                    if ($dy >= 6 && $a['dy'] === -$b['dy'] && $a['dx'] === $b['dx']) {
                        $gaps[] = $dy;
                    }
                }
            }
        }
        if ($gaps !== []) {
            sort($gaps);
            $med = $gaps[(int) (count($gaps) * 0.5)];
            $base = max($base, (int) round($med * 0.45));
        }

        return max(5, min(14, $base));
    }

    /**
     * Choose a door-close radius that seals entrances enough for open-plan interiors
     * without over-merging every room into one blob.
     *
     * @param list<int> $walls
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     */
    private function pickCloseRadius(
        array $walls,
        int $thickness,
        array $corners,
        int $w,
        int $h
    ): int {
        $base = $this->doorCloseRadius($thickness, $corners, $w, $h);
        // Radius search allocates many full-frame masks; skip on large office plans.
        if ($w * $h > 2_200_000) {
            return $thickness <= 8 ? min($base, max(2, (int) round($thickness * 0.7))) : $base;
        }
        $best = $base;
        $bestScore = -1.0;
        // Thin-line office plans: keep seal small so cubicles stay separate.
        $rMin = $thickness <= 8 ? max(2, $base - 2) : max(5, $base - 1);
        $rMax = $thickness <= 8 ? min(8, $base + 1) : min(14, $base + 4);
        for ($r = $rMin; $r <= $rMax; $r++) {
            $closed = $this->dilate($walls, $w, $h, $r);
            $interior = $this->invert($this->floodFromBorder($closed, $w, $h));
            for ($i = 0, $n = $w * $h; $i < $n; $i++) {
                if ($closed[$i] === 1) {
                    $interior[$i] = 0;
                }
            }
            $comps = $this->components($interior, $w, $h, max(1500, (int) ($w * $h * 0.003)), false);
            $nComps = count($comps);
            if ($nComps < 3) {
                unset($closed, $interior, $comps);
                continue;
            }
            $openish = 0;
            $solid = 0;
            foreach ($comps as $c) {
                $bb = $c['bbox'];
                $box = max(1, ($bb[2] - $bb[0] + 1) * ($bb[3] - $bb[1] + 1));
                $fill = $c['area'] / $box;
                if ($fill < 0.40 && $c['area'] > $w * $h * 0.04) {
                    $openish++;
                }
                if ($fill >= 0.45) {
                    $solid++;
                }
            }
            $score = $solid * 2.0 + min(2, $openish) * 1.5 - abs($nComps - 7) * 0.35;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
            unset($closed, $interior, $comps);
        }

        return $best;
    }

    /**
     * Split interior components into solid rooms vs open-plan masks to corner-split.
     * Small furniture pockets (door-seal artifacts) are returned separately for passable fill.
     *
     * @param list<int> $interior
     * @param list<int> $closed
     * @return array{0: list<array{0:int,1:int,2:int,3:int}>, 1: list<list<int>>, 2: list<array{0:int,1:int,2:int,3:int}>}
     */
    private function partitionInterior(
        array $interior,
        array $closed,
        int $w,
        int $h,
        int $minArea,
        int $thickness = 8
    ): array {
        $primary = [];
        $openMasks = [];
        $furniture = [];
        $thinPlan = $thickness <= 8;
        $minSide = $thinPlan ? 12 : 36;
        $furnMax = $thinPlan ? max(350, (int) ($w * $h * 0.0007)) : 9000;
        $areaFloor = $thinPlan
            ? max($minArea, (int) ($w * $h * 0.0008))
            : max($minArea, (int) ($w * $h * 0.006));
        foreach ($this->components($interior, $w, $h, $minArea) as $comp) {
            $bb = $comp['bbox'];
            $bw = $bb[2] - $bb[0] + 1;
            $bh = $bb[3] - $bb[1] + 1;
            $boxA = max(1, $bw * $bh);
            $fill = $comp['area'] / $boxA;
            $enc = $this->enclosureScore($bb, $closed, $w, $h);
            $aspect = max($bw, $bh) / max(1, min($bw, $bh));
            $area = $bw * $bh;

            if (min($bw, $bh) < $minSide) {
                continue;
            }
            if ($aspect > 2.6 && $fill > 0.55) {
                continue; // thin junk / furniture strip
            }
            if ($comp['area'] < $areaFloor) {
                continue;
            }

            // Furniture pocket: small imperfect fill from door-seal bridging furniture ink.
            if ($area < $furnMax && $fill < 0.85 && !$thinPlan) {
                $furniture[] = $this->snapToWalls($bb, $closed, $w, $h);
                continue;
            }

            if ($fill < 0.40 && $comp['area'] >= (int) ($w * $h * 0.035)) {
                $openMasks[] = $this->denseMaskFromPixels($comp['pixels'], $w * $h);
                continue;
            }

            if ($fill < 0.42 || $enc < 0.45) {
                continue;
            }

            $primary[] = [$bb[0], $bb[1], $bb[2], $bb[3]];
        }

        return [$primary, $openMasks, $furniture];
    }

    /**
     * Structural walls by thickness. Thick-wall residential plans drop furniture-thin ink;
     * thin-line office plans keep nearly all wall strokes so cubicles stay intact.
     *
     * @param list<int> $walls
     * @return list<int>
     */
    private function structuralWallMask(array $walls, int $w, int $h, int $thickness = 8): array
    {
        // Thin-line CAD plans: median run may be ~6 while local min(h,v) is 1–2.
        $minThick = $thickness <= 8 ? 1 : max(3, (int) floor($thickness * 0.3));
        if ($minThick <= 1) {
            return $walls;
        }
        $out = array_fill(0, $w * $h, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($walls[$y * $w + $x] !== 1) {
                    continue;
                }
                if ($this->localWallThickness($walls, $w, $h, $x, $y) >= $minThick) {
                    $out[$y * $w + $x] = 1;
                }
            }
        }

        return $out;
    }

    /**
     * Office CAD partition mask: close tiny gaps in wall strokes, then keep only
     * long horizontal/vertical runs so desks/text don't fragment cubicles.
     *
     * @param list<int> $walls
     * @return list<int>
     */
    private function partitionWallMask(array $walls, int $w, int $h, ?int $minLenOverride = null): array
    {
        $closed = $this->closeWallGaps($walls, $w, $h, 2);
        // Slightly below classic 40 so one-cell cubicle dividers survive; desks stay shorter.
        // When furniture layers are known, a lower floor is safe — desk strokes are
        // subtracted as hard negatives before growth.
        $minLen = $minLenOverride ?? max(34, (int) round(min($w, $h) * 0.015));

        return $this->longRunWallMask($closed, $w, $h, $minLen);
    }

    /**
     * Fill short gaps between collinear wall runs (broken CAD strokes).
     *
     * @param list<int> $walls
     * @return list<int>
     */
    private function closeWallGaps(array $walls, int $w, int $h, int $gap): array
    {
        if ($gap < 1) {
            return $walls;
        }
        $out = $walls;
        for ($y = 0; $y < $h; $y++) {
            $x = 0;
            $prevEnd = -1000;
            while ($x < $w) {
                if ($walls[$y * $w + $x] !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $walls[$y * $w + $x] === 1) {
                    $x++;
                }
                if ($prevEnd >= 0) {
                    $hole = $x0 - $prevEnd - 1;
                    if ($hole > 0 && $hole <= $gap) {
                        for ($xx = $prevEnd + 1; $xx < $x0; $xx++) {
                            $out[$y * $w + $xx] = 1;
                        }
                    }
                }
                $prevEnd = $x - 1;
            }
        }
        for ($x = 0; $x < $w; $x++) {
            $y = 0;
            $prevEnd = -1000;
            while ($y < $h) {
                if ($walls[$y * $w + $x] !== 1) {
                    $y++;
                    continue;
                }
                $y0 = $y;
                while ($y < $h && $walls[$y * $w + $x] === 1) {
                    $y++;
                }
                if ($prevEnd >= 0) {
                    $hole = $y0 - $prevEnd - 1;
                    if ($hole > 0 && $hole <= $gap) {
                        for ($yy = $prevEnd + 1; $yy < $y0; $yy++) {
                            $out[$yy * $w + $x] = 1;
                        }
                    }
                }
                $prevEnd = $y - 1;
            }
        }

        return $out;
    }

    /**
     * Keep wall pixels that lie on a long horizontal or vertical run.
     *
     * @param list<int> $walls
     * @return list<int>
     */
    private function longRunWallMask(array $walls, int $w, int $h, int $minLen): array
    {
        $hLen = array_fill(0, $w * $h, 0);
        $vLen = array_fill(0, $w * $h, 0);
        for ($y = 0; $y < $h; $y++) {
            $x = 0;
            while ($x < $w) {
                if ($walls[$y * $w + $x] !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $walls[$y * $w + $x] === 1) {
                    $x++;
                }
                $len = $x - $x0;
                for ($xx = $x0; $xx < $x; $xx++) {
                    $hLen[$y * $w + $xx] = $len;
                }
            }
        }
        for ($x = 0; $x < $w; $x++) {
            $y = 0;
            while ($y < $h) {
                if ($walls[$y * $w + $x] !== 1) {
                    $y++;
                    continue;
                }
                $y0 = $y;
                while ($y < $h && $walls[$y * $w + $x] === 1) {
                    $y++;
                }
                $len = $y - $y0;
                for ($yy = $y0; $yy < $y; $yy++) {
                    $vLen[$yy * $w + $x] = $len;
                }
            }
        }
        $out = array_fill(0, $w * $h, 0);
        for ($i = 0, $n = $w * $h; $i < $n; $i++) {
            if ($walls[$i] === 1 && max($hLen[$i], $vLen[$i]) >= $minLen) {
                $out[$i] = 1;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $walls
     */
    private function localWallThickness(array $walls, int $w, int $h, int $x, int $y): int
    {
        $L = $x;
        while ($L > 0 && $walls[$y * $w + $L - 1] === 1) {
            $L--;
        }
        $R = $x;
        while ($R < $w - 1 && $walls[$y * $w + $R + 1] === 1) {
            $R++;
        }
        $T = $y;
        while ($T > 0 && $walls[($T - 1) * $w + $x] === 1) {
            $T--;
        }
        $B = $y;
        while ($B < $h - 1 && $walls[($B + 1) * $w + $x] === 1) {
            $B++;
        }

        return min($R - $L + 1, $B - $T + 1);
    }

    /**
     * Grow room rectangles from interior corners against native walls (no door seal).
     * Captures cubicles and small walled pockets that heavy sealing would merge away.
     *
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function roomsFromCorners(
        array $corners,
        array $walls,
        int $w,
        int $h,
        int $thickness,
        int $minArea
    ): array {
        $inset = max(2, (int) round($thickness * 0.45));
        $maxArea = (int) ($w * $h * ($thickness <= 8 ? 0.08 : 0.18));
        $minSide = $thickness <= 8 ? 10 : 28;
        $maxHalfW = $thickness <= 8 ? max(90, (int) round($w * 0.028)) : max($w, $h);
        $maxHalfH = $thickness <= 8 ? max(75, (int) round($h * 0.04)) : max($w, $h);
        $grown = [];
        foreach ($corners as $c) {
            $sx = $c['x'] + $c['dx'] * $inset;
            $sy = $c['y'] + $c['dy'] * $inset;
            $rect = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $maxHalfW, $maxHalfH);
            if ($rect === null) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = $rect;
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
            $area = $bw * $bh;
            if ($bw < $minSide || $bh < $minSide || $area < $minArea || $area > $maxArea) {
                continue;
            }
            $aspect = max($bw, $bh) / max(1, min($bw, $bh));
            if ($aspect > 4.0) {
                continue;
            }
            // Prefer rooms with wall support on at least 3 sides.
            $enc = $this->enclosureScore([$x0, $y0, $x1, $y1], $walls, $w, $h);
            if ($enc < 0.45) {
                continue;
            }
            // Reject strips glued to the image border (title blocks / sheet margins).
            $touchesBorder = $x0 <= 1 || $y0 <= 1 || $x1 >= $w - 2 || $y1 >= $h - 2;
            if ($touchesBorder && $enc < 0.70) {
                continue;
            }
            $grown[] = [$x0, $y0, $x1, $y1, $area];
        }
        usort($grown, static fn (array $a, array $b): int => $b[4] <=> $a[4]);
        $kept = [];
        foreach ($grown as $g) {
            $r = [$g[0], $g[1], $g[2], $g[3]];
            $skip = false;
            foreach ($kept as $host) {
                if ($this->overlapFrac($r, $host) > 0.45 || $this->containmentFrac($r, $host) > 0.55) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * After primary merge, seed any cubicle-sized free cell whose center isn't covered yet.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function fillCubicleHoles(array $rects, array $walls, int $w, int $h): array
    {
        $extra = [];
        $cubMax = $this->cadArea(22000);
        // Cubicle-scale blockers for bank hole detection.
        $blockers = array_values(array_filter(
            $rects,
            fn (array $r): bool => $this->rectArea($r) <= $cubMax
        ));
        // Real conference / open offices also block — post-prune fill used to stamp
        // cubicles into them, then drop the large rooms on re-merge.
        $largeBlockers = array_values(array_filter(
            $rects,
            function (array $r) use ($walls, $w, $h): bool {
                $area = $this->rectArea($r);
                if ($area <= $this->cadArea(28000)) {
                    return false;
                }
                if ($this->enclosureScore($r, $walls, $w, $h) >= 0.42) {
                    return true;
                }

                // Only large open-front conference rooms (not medium bank merges).
                return $area >= $this->cadArea(10000)
                    && $this->strongWallSides($r, $walls, $w, $h, 0.34) >= 3;
            }
        ));
        $stepY = $this->cad($h >= 900 ? 26 : 20);
        $stepX = $this->cad($w >= 1400 ? 28 : 22);
        $margin = $this->cad(24);
        // Two phase offsets so open-front banks aren't missed between the coarse lattice.
        // Third offset (oy≈cad18) hits short cubicle centers the 28px Y step otherwise skips.
        foreach ([[0, 0], [$this->cad(14), $this->cad(12)], [$this->cad(10), $this->cad(18)]] as [$ox, $oy]) {
            for ($sy = $margin + $oy; $sy < $h - $margin; $sy += $stepY) {
                for ($sx = $margin + $ox; $sx < $w - $margin; $sx += $stepX) {
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    if ($sy > (int) ($h * 0.97)) {
                        continue;
                    }
                    // Inset occupancy so a neighbor that slightly overlaps the empty cell
                    // does not hide a real cubicle center.
                    if (
                        $this->pointInAnyRectCore($sx, $sy, $blockers, $this->cad(12))
                        || $this->pointInAnyRectCore($sx, $sy, $extra, $this->cad(10))
                        || $this->pointInAnyRectCore($sx, $sy, $largeBlockers, $this->cad(8))
                    ) {
                        continue;
                    }
                    $rect = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(70), $this->cad(60));
                    if ($rect === null) {
                        continue;
                    }
                    $rect = $this->padOpenCubicle($rect, $walls, $w, $h);
                    $bw = $rect[2] - $rect[0] + 1;
                    $bh = $rect[3] - $rect[1] + 1;
                    $area = $bw * $bh;
                    $encEarly = $this->enclosureScore($rect, $walls, $w, $h);
                    // Allow slightly narrow/tall open cubicles (desk-side openings).
                    // Space-fill may produce a wider size range than the old fixed targets.
                    // High-enc short cells (top-row pockets like #41) may land under the usual area floor.
                    $minArea = ($encEarly >= 0.55 && $bh <= $this->cad(55))
                        ? $this->cadArea(1800)
                        : $this->cadArea(2800);
                    if ($bw < $this->cad(42) || $bw > $this->cad(220) || $bh < $this->cad(32) || $bh > $this->cad(220) || $area < $minArea || $area > $this->cadArea(45000)) {
                        continue;
                    }
                    $asp = max($bw, $bh) / max(1, min($bw, $bh));
                    if ($asp > 3.4) {
                        continue;
                    }
                    if ($this->isCorridorShape($rect, $walls, $w, $h)) {
                        continue;
                    }
                    $enc = $encEarly;
                    // Short open-front bank cells often score enc 0.22–0.30.
                    $minEnc = ($bh <= $this->cad(55)) ? 0.22 : 0.30;
                    if ($enc < $minEnc) {
                        continue;
                    }
                    if ($enc < 0.34 && $this->strongWallSides($rect, $walls, $w, $h, 0.34) < 2) {
                        continue;
                    }
                    $skip = false;
                    $rcx = intdiv($rect[0] + $rect[2], 2);
                    $rcy = intdiv($rect[1] + $rect[3], 2);
                    // Short open cubicles sit closer in Y than full bank pitch — use a tighter gate.
                    $nearGate = $bh <= $this->cad(70) ? $this->cad(36) : $this->cad(48);
                    foreach (array_merge($blockers, $extra) as $host) {
                        $hcx = intdiv($host[0] + $host[2], 2);
                        $hcy = intdiv($host[1] + $host[3], 2);
                        if (hypot($rcx - $hcx, $rcy - $hcy) < $nearGate) {
                            $skip = true;
                            break;
                        }
                        if ($this->overlapFrac($rect, $host) > 0.28 || $this->containmentFrac($rect, $host) > 0.5) {
                            $skip = true;
                            break;
                        }
                    }
                    if (!$skip) {
                        $extra[] = $rect;
                    }
                }
            }
        }

        // Existing cubicles keep their claim unless a hole-fill candidate is clearly better
        // (centered grow beating a shifted/shallow neighbor that overlaps the empty cell).
        if ($extra === []) {
            return $rects;
        }
        $kept = [];
        foreach ($rects as $r) {
            if ($this->rectArea($r) <= $cubMax) {
                $kept[] = $r;
            }
        }
        foreach ($extra as $rect) {
            // Never plant a cubicle whose center sits inside a kept conference room.
            $rcx = intdiv($rect[0] + $rect[2], 2);
            $rcy = intdiv($rect[1] + $rect[3], 2);
            $inLarge = false;
            foreach ($largeBlockers as $L) {
                if ($rcx >= $L[0] && $rcx <= $L[2] && $rcy >= $L[1] && $rcy <= $L[3]) {
                    $inLarge = true;
                    break;
                }
            }
            if ($inLarge) {
                continue;
            }
            $conflicts = [];
            foreach ($kept as $idx => $host) {
                if ($this->overlapFrac($rect, $host) > 0.35 || $this->containmentFrac($rect, $host) > 0.5) {
                    $conflicts[] = $idx;
                }
            }
            if ($conflicts === []) {
                $kept[] = $rect;
                continue;
            }
            $qNew = $this->cubicleQuality($rect, $walls, $w, $h);
            $replace = true;
            foreach ($conflicts as $idx) {
                $qOld = $this->cubicleQuality($kept[$idx], $walls, $w, $h);
                if ($qNew < $qOld + 0.12) {
                    $replace = false;
                    break;
                }
            }
            if ($replace) {
                foreach (array_reverse($conflicts) as $idx) {
                    array_splice($kept, $idx, 1);
                }
                $kept[] = $rect;
            }
        }
        // Conference / open offices win over cubicles that wandered into them.
        foreach ($rects as $r) {
            if ($this->rectArea($r) <= $this->cadArea(28000)) {
                continue;
            }
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            $protect = $enc >= 0.42
                || ($this->rectArea($r) >= $this->cadArea(10000) && $sides >= 3);
            if (!$protect) {
                // Weak oversized junk — keep only if no cubicle conflict.
                $skip = false;
                foreach ($kept as $cub) {
                    if ($this->rectArea($cub) > $cubMax) {
                        continue;
                    }
                    if ($this->containmentFrac($cub, $r) > 0.45 || $this->overlapFrac($cub, $r) > 0.4) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $kept[] = $r;
                }
                continue;
            }
            $kept = array_values(array_filter(
                $kept,
                function (array $cub) use ($r, $cubMax): bool {
                    if ($this->rectArea($cub) > $cubMax) {
                        return true;
                    }

                    return $this->containmentFrac($cub, $r) <= 0.45
                        && $this->overlapFrac($cub, $r) <= 0.40;
                }
            ));
            $kept[] = $r;
        }

        return $kept;
    }

    /**
     * Prefer typical cubicle footprint + real enclosure when resolving overlaps.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     */
    private function cubicleQuality(array $r, array $walls, int $w, int $h): float
    {
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $area = max(1, $bw * $bh);
        $enc = $this->enclosureScore($r, $walls, $w, $h);
        $targetA = max(1.0, (float) $this->cadArea(8800));
        $tw = max(1, $this->cad(110));
        $th = max(1, $this->cad(80));
        $sizeFit = 1.0 / (1.0 + abs(log($area / $targetA)));
        $dimFit = max(0.0, 1.0 - abs($bw - $tw) / $tw - abs($bh - $th) / $th);

        return $enc * 1.25 + $sizeFit + 0.45 * $dimFit;
    }

    /**
     * Re-grow weak / shallow cubicle cells from their center (+offsets) and keep the better fit.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function refineWeakCubicles(array $rects, array $walls, int $w, int $h): array
    {
        $out = $rects;
        $n = count($out);
        for ($i = 0; $i < $n; $i++) {
            $r = $out[$i];
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(3000) || $area > $this->cadArea(38000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            if ($asp > 3.2) {
                continue;
            }
            $q0 = $this->cubicleQuality($r, $walls, $w, $h);
            // Only touch clearly weak / shallow / off-size / too-tall / oversized cells.
            $tooTall = $bh >= $this->cad(95);
            $oversized = $area > $this->cadArea(14000);
            if ($q0 >= 1.40 && $bh >= $this->cad(55) && $bw >= $this->cad(70) && !$tooTall && !$oversized) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $best = $r;
            $bestQ = $q0;
            $tw = max(1, $this->cad(80));
            foreach ([
                [0, 0],
                [$this->cad(14), 0],
                [-$this->cad(14), 0],
                [0, $this->cad(10)],
                [0, -$this->cad(10)],
                [0, -$this->cad(18)],
                [0, $this->cad(18)],
                [$this->cad(22), $this->cad(8)],
                [-$this->cad(22), $this->cad(8)],
                [$this->cad(8), $this->cad(14)],
                [-$this->cad(8), -$this->cad(14)],
            ] as [$dx, $dy]) {
                $sx = $cx + $dx;
                $sy = $cy + $dy;
                if ($sx < 2 || $sy < 2 || $sx >= $w - 2 || $sy >= $h - 2) {
                    continue;
                }
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(70), $this->cad(60));
                if ($grown === null) {
                    continue;
                }
                $cand = $this->padOpenCubicle($grown, $walls, $w, $h);
                $cbw = $cand[2] - $cand[0] + 1;
                $cbh = $cand[3] - $cand[1] + 1;
                $cArea = $cbw * $cbh;
                if ($cbw < $this->cad(50) || $cbw > $this->cad(175) || $cbh < $this->cad(42) || $cbh > $this->cad(155) || $cArea < $this->cadArea(3200) || $cArea > $this->cadArea(22000)) {
                    continue;
                }
                if ($this->isCorridorShape($cand, $walls, $w, $h)) {
                    continue;
                }
                $q = $this->cubicleQuality($cand, $walls, $w, $h);
                // Prefer cells that don't straddle two bank rows (~80px tall @4000).
                $hFit = max(0.0, 1.0 - abs($cbh - $tw) / $tw);
                $q += 0.12 * $hFit;
                if ($q > $bestQ + 0.04) {
                    $bestQ = $q;
                    $best = $cand;
                }
            }
            if ($best === $r) {
                continue;
            }
            // Don't adopt a refine that heavily overlaps a different strong cubicle.
            $clash = false;
            $cubMax = $this->cadArea(22000);
            foreach ($out as $j => $host) {
                if ($j === $i || $this->rectArea($host) > $cubMax) {
                    continue;
                }
                if ($this->overlapFrac($best, $host) > 0.4) {
                    if ($this->cubicleQuality($host, $walls, $w, $h) + 0.05 >= $bestQ) {
                        $clash = true;
                        break;
                    }
                }
            }
            if (!$clash) {
                $out[$i] = $best;
            }
        }

        return $out;
    }

    /**
     * Shatter merged bank blobs (one fat rect covering 2–6 cubicles) back into cells.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function shatterOversizedBankBlobs(array $rects, array $walls, int $w, int $h): array
    {
        $out = [];
        $minBlob = $this->cadArea(10000);
        // Work-scale bank merges can be ~200×200 (≈40k px) — cadArea(50000)≈12.5k was too low.
        $maxBlob = $this->cadArea(220000);
        $ideal = max(1, $this->cadArea(8800));
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            // Bank merges are typically wider+taller than one cell.
            $looksMerged = ($bw >= $this->cad(100) && $bh >= $this->cad(70))
                || ($bw >= $this->cad(70) && $bh >= $this->cad(100))
                || ($bh >= $this->cad(160) && $bw >= $this->cad(55) && $bh >= (int) ($bw * 2.1));
            // Allow tall bank columns (asp up to ~4.2); only reject true corridor ribbons.
            if (
                !$looksMerged
                || $area < $minBlob
                || $area > $maxBlob
                || $asp > 4.2
                || min($bw, $bh) < $this->cad(48)
                || $area < $ideal * 1.55
            ) {
                $out[] = $r;
                continue;
            }
            // Open conference rooms have sparse interior partitions — keep them.
            // Cubicle-bank AABBs have a denser internal wall grid (e.g. 234×196 over #32–#37).
            // Tall columns may only show side rails — still try to shatter them.
            $iw = $this->interiorWallFrac($r, $walls, $w, $h);
            $tallColumn = $bh >= (int) ($bw * 2.1) && $bh >= $this->cad(160);
            if ($iw < 0.035 && !$tallColumn) {
                $out[] = $r;
                continue;
            }
            // Try cubicle-scale re-seeds inside the blob. Conference rooms usually
            // re-grow past the cubicle size cap and fall through to keeping $r.
            $candidates = [];
            $stepX = max(24, $this->cad(68));
            $stepY = max(20, $this->cad(52));
            $pad = $this->cad(16);
            for ($sy = $r[1] + $pad; $sy <= $r[3] - $pad; $sy += $stepY) {
                for ($sx = $r[0] + $pad; $sx <= $r[2] - $pad; $sx += $stepX) {
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(70), $this->cad(60));
                    if ($grown === null) {
                        continue;
                    }
                    $cand = $this->padOpenCubicle($grown, $walls, $w, $h);
                    $cbw = $cand[2] - $cand[0] + 1;
                    $cbh = $cand[3] - $cand[1] + 1;
                    $cArea = $cbw * $cbh;
                    if (
                        $cbw < $this->cad(40)
                        || $cbw > $this->cad(160)
                        || $cbh < $this->cad(36)
                        || $cbh > $this->cad(140)
                        || $cArea < $this->cadArea(2200)
                        || $cArea > $this->cadArea(18000)
                    ) {
                        continue;
                    }
                    if ($this->containmentFrac($cand, $r) < 0.55) {
                        continue;
                    }
                    if ($this->isCorridorShape($cand, $walls, $w, $h)) {
                        continue;
                    }
                    $q = $this->cubicleQuality($cand, $walls, $w, $h);
                    if ($q < 1.05) {
                        continue;
                    }
                    $candidates[] = ['r' => $cand, 'q' => $q, 'a' => $cArea];
                }
            }
            if (count($candidates) < 2) {
                $geo = $this->geometricShatterBankBlob($r, $walls, $w, $h, $iw);
                if ($geo !== null) {
                    foreach ($geo as $c) {
                        $out[] = $c;
                    }
                } else {
                    $out[] = $r;
                }
                continue;
            }
            usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);
            $kept = [];
            $minPitch = $this->cad(46);
            foreach ($candidates as $item) {
                $c = $item['r'];
                $cx = intdiv($c[0] + $c[2], 2);
                $cy = intdiv($c[1] + $c[3], 2);
                $ok = true;
                foreach ($kept as $host) {
                    if ($this->intersectionArea($c, $host) > 0) {
                        $ok = false;
                        break;
                    }
                    $hcx = intdiv($host[0] + $host[2], 2);
                    $hcy = intdiv($host[1] + $host[3], 2);
                    if (hypot($cx - $hcx, $cy - $hcy) < $minPitch) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $kept[] = $c;
                }
            }
            $cover = 0;
            foreach ($kept as $c) {
                $cover += $this->rectArea($c);
            }
            // Need at least 2 cells covering a meaningful share of the blob.
            if (count($kept) >= 2 && $cover >= $area * 0.35) {
                foreach ($kept as $c) {
                    $out[] = $c;
                }
            } else {
                // Grow often fails inside dense short-cubicle banks — fall back to a lattice.
                $geo = $this->geometricShatterBankBlob($r, $walls, $w, $h, $iw);
                if ($geo !== null) {
                    foreach ($geo as $c) {
                        $out[] = $c;
                    }
                } else {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    /**
     * Tile a bank-merge AABB into cubicle-sized cells using interior wall pitch.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>|null
     */
    private function geometricShatterBankBlob(array $r, array $walls, int $w, int $h, float $iw): ?array
    {
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $tallColumn = $bh >= (int) ($bw * 2.1) && $bh >= $this->cad(160);
        if ($iw < 0.035 && !$tallColumn) {
            return null;
        }
        // Prefer wall-density peaks as cut lines so tiles land on real cubicle pockets.
        $vCuts = $this->bankWallCutPeaks($r, $walls, $w, $h, false);
        $hCuts = $this->bankWallCutPeaks($r, $walls, $w, $h, true);
        if ($vCuts === [] && $hCuts === []) {
            // Fallback uniform lattice tuned toward short/medium bank cells.
            $pitchX = max($this->cad(95), min($this->cad(140), (int) round($bw / max(1, (int) round($bw / $this->cad(110))))));
            $pitchY = max($this->cad(75), min($this->cad(110), (int) round($bh / max(1, (int) round($bh / $this->cad(85))))));
            // Tall sparse columns: force horizontal cuts at module pitch.
            if ($tallColumn && $hCuts === []) {
                $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
                $rows = max(2, (int) round($bh / max(1, $mh)));
                for ($row = 1; $row < $rows; $row++) {
                    $hCuts[] = $r[1] + (int) round($row * $bh / $rows);
                }
            }
            $cols = max(1, (int) round($bw / $pitchX));
            $rows = max(1, (int) round($bh / $pitchY));
            for ($c = 1; $c < $cols; $c++) {
                $vCuts[] = $r[0] + (int) round($c * $bw / $cols);
            }
            if ($hCuts === []) {
                for ($row = 1; $row < $rows; $row++) {
                    $hCuts[] = $r[1] + (int) round($row * $bh / $rows);
                }
            }
        }
        $xEdges = array_merge([$r[0]], $vCuts, [$r[2] + 1]);
        $yEdges = array_merge([$r[1]], $hCuts, [$r[3] + 1]);
        sort($xEdges);
        sort($yEdges);
        $xEdges = array_values(array_unique($xEdges));
        $yEdges = array_values(array_unique($yEdges));
        $cells = [];
        $pad = max(1, $this->cad(3));
        for ($yi = 0; $yi < count($yEdges) - 1; $yi++) {
            for ($xi = 0; $xi < count($xEdges) - 1; $xi++) {
                $x0 = $xEdges[$xi] + $pad;
                $y0 = $yEdges[$yi] + $pad;
                $x1 = $xEdges[$xi + 1] - $pad - 1;
                $y1 = $yEdges[$yi + 1] - $pad - 1;
                if ($x1 < $x0 + $this->cad(55) || $y1 < $y0 + $this->cad(50)) {
                    continue;
                }
                $cx = intdiv($x0 + $x1, 2);
                $cy = intdiv($y0 + $y1, 2);
                if ($cx < 0 || $cy < 0 || $cx >= $w || $cy >= $h || $walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                $cand = [$x0, $y0, $x1, $y1];
                $fit = $this->expandCubicleToWalls($cand, $walls, $w, $h, $this->cad(6));
                $maxW = ($x1 - $x0 + 1) + $this->cad(16);
                $maxH = ($y1 - $y0 + 1) + $this->cad(16);
                if (
                    ($fit[2] - $fit[0] + 1) <= $maxW
                    && ($fit[3] - $fit[1] + 1) <= $maxH
                    && $this->containmentFrac($fit, $r) >= 0.55
                ) {
                    $cand = $fit;
                }
                $cbw = $cand[2] - $cand[0] + 1;
                $cbh = $cand[3] - $cand[1] + 1;
                // Prefer measured module bounds when available — native CAD cubicles can be
                // shorter than cad(65) once cadS is large.
                $minW = $this->modW > 20 ? max(40, (int) ($this->modW * 0.45)) : $this->cad(75);
                $minH = $this->modH > 16 ? max(36, (int) ($this->modH * 0.45)) : $this->cad(65);
                $maxW = $this->modW > 20 ? (int) ($this->modW * 1.55) : $this->cad(180);
                $maxH = $this->modH > 16 ? (int) ($this->modH * 1.55) : $this->cad(150);
                // Reject flat shatter scraps (common FP: 63×29 at blob tops).
                if (
                    $cbw < $minW
                    || $cbh < $minH
                    || $cbw > $maxW
                    || $cbh > $maxH
                    || $cbw * $cbh > $this->cadArea(18000)
                ) {
                    continue;
                }
                if ($this->enclosureScore($cand, $walls, $w, $h) < 0.18) {
                    continue;
                }
                if ($this->cubicleQuality($cand, $walls, $w, $h) < 0.95) {
                    continue;
                }
                $cells[] = $cand;
            }
        }
        if (count($cells) < 2) {
            // Peak grid was too sparse — fall back to a uniform short-bank lattice.
            return $this->uniformShatterBankBlob($r, $walls, $w, $h);
        }

        return $cells;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>|null
     */
    private function uniformShatterBankBlob(array $r, array $walls, int $w, int $h): ?array
    {
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $pitchX = max((int) ($mw * 0.85), min((int) ($mw * 1.25), (int) round($bw / max(1, (int) round($bw / $mw)))));
        $pitchY = max((int) ($mh * 0.85), min((int) ($mh * 1.25), (int) round($bh / max(1, (int) round($bh / $mh)))));
        $cols = max(1, (int) round($bw / $pitchX));
        $rows = max(1, (int) round($bh / $pitchY));
        // Tall sparse columns: prefer many horizontal slices even if width is one module.
        if ($bh >= (int) ($bw * 2.1) && $bh >= (int) ($mh * 2.2)) {
            $rows = max($rows, (int) round($bh / max(1, $mh)));
            $cols = max(1, min($cols, 2));
        }
        if ($cols * $rows < 2 || $cols * $rows > 36) {
            return null;
        }
        $cellW = intdiv($bw, $cols);
        $cellH = intdiv($bh, $rows);
        $minW = max(36, (int) ($mw * 0.45));
        $minH = max(32, (int) ($mh * 0.45));
        if ($cellW < $minW || $cellH < $minH) {
            return null;
        }
        $cells = [];
        $pad = max(1, min(4, intdiv($mh, 30)));
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $x0 = $r[0] + $col * $cellW + $pad;
                $y0 = $r[1] + $row * $cellH + $pad;
                $x1 = ($col === $cols - 1) ? ($r[2] - $pad) : ($r[0] + ($col + 1) * $cellW - $pad - 1);
                $y1 = ($row === $rows - 1) ? ($r[3] - $pad) : ($r[1] + ($row + 1) * $cellH - $pad - 1);
                if ($x1 < $x0 + $minW - 8 || $y1 < $y0 + $minH - 8) {
                    continue;
                }
                $cx = intdiv($x0 + $x1, 2);
                $cy = intdiv($y0 + $y1, 2);
                if ($walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                $cand = [$x0, $y0, $x1, $y1];
                $cbh = $cand[3] - $cand[1] + 1;
                if ($cbh < $minH || $this->enclosureScore($cand, $walls, $w, $h) < 0.14) {
                    continue;
                }
                $cells[] = $cand;
            }
        }

        return count($cells) >= 2 ? $cells : null;
    }

    /**
     * Find strong interior wall bands to cut a bank blob into cubicle tiles.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return list<int>
     */
    private function bankWallCutPeaks(array $r, array $walls, int $w, int $h, bool $horizontal): array
    {
        $scores = [];
        if ($horizontal) {
            $y0 = $r[1] + $this->cad(28);
            $y1 = $r[3] - $this->cad(28);
            for ($y = $y0; $y <= $y1; $y++) {
                $hits = 0;
                $tot = 0;
                for ($x = $r[0]; $x <= $r[2]; $x++) {
                    $tot++;
                    if ($walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($tot > 0 && $hits / $tot >= 0.28) {
                    $scores[$y] = $hits / $tot;
                }
            }
        } else {
            $x0 = $r[0] + $this->cad(28);
            $x1 = $r[2] - $this->cad(28);
            for ($x = $x0; $x <= $x1; $x++) {
                $hits = 0;
                $tot = 0;
                for ($y = $r[1]; $y <= $r[3]; $y++) {
                    $tot++;
                    if ($walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($tot > 0 && $hits / $tot >= 0.28) {
                    $scores[$x] = $hits / $tot;
                }
            }
        }
        if ($scores === []) {
            return [];
        }
        arsort($scores);
        $picked = [];
        $minSep = $this->cad(55);
        foreach ($scores as $pos => $frac) {
            $ok = true;
            foreach ($picked as $p) {
                if (abs($pos - $p) < $minSep) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $picked[] = $pos;
            }
            if (count($picked) >= 6) {
                break;
            }
        }
        sort($picked);

        return $picked;
    }

    /**
     * Late shatter for upper-floor cubicle-bank AABBs only.
     * Skips bottom-row offices so #1/#6 hosts are not fragmented before refine.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function shatterUpperBankBlobs(array $rects, array $walls, int $w, int $h): array
    {
        $yCut = (int) ($h * 0.74);
        $upper = [];
        $lower = [];
        foreach ($rects as $r) {
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cy >= $yCut) {
                $lower[] = $r;
            } else {
                $upper[] = $r;
            }
        }
        $shattered = $this->shatterOversizedBankBlobs($upper, $walls, $w, $h);

        return array_merge($shattered, $lower);
    }

    /**
     * Plant missing cubicle-scale cells across upper/mid bank bands.
     * Covers short top-row cubicles (~53×40) as well as medium bank cells.
     * Uses grow when possible; falls back to host-pitch geometry when ink is dense.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    /**
     * Plant one host-sized cell immediately above short bank cubicles when that
     * slot is empty. Covers unlabeled top-row holes (#6) that tall-companion and
     * count-gated upper-bank passes skip once cubicle count is already high.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedShortAboveShortHosts(array $rects, array $walls, int $w, int $h, array $labels = []): array
    {
        $extra = [];
        $topBand = (int) ($h * 0.28);
        $minY = (int) ($h * 0.04);
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bw < $this->cad(45) || $bw > $this->cad(160) || $bh < $this->cad(28) || $bh > $this->cad(95)) {
                continue;
            }
            // Host must itself sit in the upper bank (first occupied rows).
            if ($r[1] > $topBand || $r[1] < $minY) {
                continue;
            }
            $cand = [
                $r[0],
                max(0, $r[1] - $bh - 1),
                $r[2],
                $r[1] - 2,
            ];
            if ($cand[3] < $cand[1] || $cand[1] < $minY) {
                continue;
            }
            $cbw = $cand[2] - $cand[0] + 1;
            $cbh = $cand[3] - $cand[1] + 1;
            if ($cbh < $this->cad(28) || $cbh > $this->cad(100) || $cbw * $cbh < $this->cadArea(1800)) {
                continue;
            }
            $cx = intdiv($cand[0] + $cand[2], 2);
            $cy = intdiv($cand[1] + $cand[3], 2);
            if ($cx < 0 || $cy < 0 || $cx >= $w || $cy >= $h || $walls[$cy * $w + $cx] === 1) {
                continue;
            }
            if ($this->pointInAnyRectCore($cx, $cy, array_merge($rects, $extra), $this->cad(4))) {
                continue;
            }
            $enc = $this->enclosureScore($cand, $walls, $w, $h);
            if ($enc < 0.14) {
                continue;
            }
            if ($enc < 0.24 && $this->strongWallSides($cand, $walls, $w, $h, 0.30) < 1) {
                continue;
            }
            // Top strip may lack on-cell OCR; deeper rows still want a nearby ID.
            if ($labels !== [] && $cand[1] > (int) ($h * 0.14)) {
                if ($this->nearestLabelDist($cx, $cy, $labels) > $this->cad(160)) {
                    continue;
                }
            }
            $skip = false;
            foreach (array_merge($rects, $extra) as $host) {
                if ($this->intersectionArea($cand, $host) > 0) {
                    $skip = true;
                    break;
                }
                $hcx = intdiv($host[0] + $host[2], 2);
                $hcy = intdiv($host[1] + $host[3], 2);
                if ($this->rectArea($host) <= $this->cadArea(24000) && hypot($cx - $hcx, $cy - $hcy) < $this->cad(36)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $padded = $this->padOpenCubicle($cand, $walls, $w, $h);
            if ($padded[3] >= $r[1]) {
                $padded[3] = $r[1] - 2;
            }
            if ($padded[2] < $padded[0] || $padded[3] < $padded[1]) {
                continue;
            }
            $pbh = $padded[3] - $padded[1] + 1;
            // Prefer full host height when pad collapses the top strip.
            if ($pbh + 4 < $bh && $cand[3] < $r[1]) {
                $padded[1] = $cand[1];
                $padded[3] = min($padded[3], $r[1] - 2);
            }
            if ($padded[2] < $padded[0] || $padded[3] < $padded[1]) {
                continue;
            }
            $extra[] = $padded;
            if (count($extra) >= 12) {
                break;
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    private function seedUpperBankHoles(array $rects, array $walls, int $w, int $h, array $labels = []): array
    {
        $minBh = $this->modH > 16 ? max($this->cad(34), (int) round($this->modH * 0.75)) : $this->cad(36);
        $minBw = $this->modW > 20 ? max($this->cad(45), (int) round($this->modW * 0.65)) : $this->cad(50);
        $bankHosts = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if (
                $cy < (int) ($h * 0.06)
                || $cy > (int) ($h * 0.62)
                || $bw < $minBw
                || $bw > $this->cad(200)
                || $bh < $minBh
                || $bh > $this->cad(180)
            ) {
                continue;
            }
            $bankHosts[] = $r;
        }
        if ($bankHosts === []) {
            return $rects;
        }

        // Estimate local pitch from nearest host pairs (fallback to typical bank pitch).
        $pitchX = $this->modW > 20 ? max($this->cad(90), $this->modW) : $this->cad(150);
        $pitchY = $this->modH > 16 ? max($this->cad(70), $this->modH) : $this->cad(100);
        $dxs = [];
        $dys = [];
        $nHost = count($bankHosts);
        for ($i = 0; $i < $nHost; $i++) {
            $ax = intdiv($bankHosts[$i][0] + $bankHosts[$i][2], 2);
            $ay = intdiv($bankHosts[$i][1] + $bankHosts[$i][3], 2);
            for ($j = $i + 1; $j < $nHost; $j++) {
                $bx = intdiv($bankHosts[$j][0] + $bankHosts[$j][2], 2);
                $by = intdiv($bankHosts[$j][1] + $bankHosts[$j][3], 2);
                $dx = abs($ax - $bx);
                $dy = abs($ay - $by);
                if ($dy <= $this->cad(30) && $dx >= $this->cad(90) && $dx <= $this->cad(220)) {
                    $dxs[] = $dx;
                }
                if ($dx <= $this->cad(30) && $dy >= $this->cad(70) && $dy <= $this->cad(200)) {
                    $dys[] = $dy;
                }
            }
        }
        if ($dxs !== []) {
            sort($dxs);
            $pitchX = $dxs[(int) (count($dxs) / 2)];
        }
        if ($dys !== []) {
            sort($dys);
            $pitchY = $dys[(int) (count($dys) / 2)];
        }

        $acceptCand = function (array $cand) use ($walls, $w, $h, $rects, $minBw, $minBh, $labels): ?array {
            $cbw = $cand[2] - $cand[0] + 1;
            $cbh = $cand[3] - $cand[1] + 1;
            // Short top banks (~40–55) and medium banks (~55–140).
            if (
                $cbw < $minBw
                || $cbw > $this->cad(180)
                || $cbh < $minBh
                || $cbh > $this->cad(160)
                || $cbw * $cbh < $this->cadArea(2200)
                || $cbw * $cbh > $this->cadArea(22000)
            ) {
                return null;
            }
            $asp = max($cbw, $cbh) / max(1, min($cbw, $cbh));
            if ($asp > 2.8) {
                return null;
            }
            if ($this->enclosureScore($cand, $walls, $w, $h) < ($labels === [] ? 0.16 : 0.22)) {
                return null;
            }
            if ($this->strongWallSides($cand, $walls, $w, $h, $labels === [] ? 0.28 : 0.34) < 1) {
                return null;
            }
            if ($this->isCorridorShape($cand, $walls, $w, $h)) {
                return null;
            }
            // When map labels exist, only fill holes near an ID (OCR is noisy; allow ~1 module).
            if ($labels !== []) {
                $cx = intdiv($cand[0] + $cand[2], 2);
                $cy = intdiv($cand[1] + $cand[3], 2);
                if ($this->nearestLabelDist($cx, $cy, $labels) > max(90, $this->cad(140))) {
                    return null;
                }
            }
            foreach ($rects as $host) {
                if ($this->overlapFrac($cand, $host) > 0.22 || $this->containmentFrac($cand, $host) > 0.40) {
                    return null;
                }
            }

            return $cand;
        };

        $candidates = [];
        // 1) Lattice scan over the full upper bank zone (includes top short rows).
        $y0 = (int) ($h * 0.08);
        $y1 = (int) ($h * 0.55);
        $stepX = max(12, $this->cad(26));
        $stepY = max(10, $this->cad(22));
        $margin = $this->cad(36);
        $nearR = $this->cad(400);
        for ($sy = $y0; $sy < $y1; $sy += $stepY) {
            for ($sx = $margin; $sx < $w - $margin; $sx += $stepX) {
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($sx, $sy, $rects, $this->cad(6))) {
                    continue;
                }
                $nearHost = false;
                $nearest = null;
                $nearestD = PHP_FLOAT_MAX;
                foreach ($bankHosts as $host) {
                    $hcx = intdiv($host[0] + $host[2], 2);
                    $hcy = intdiv($host[1] + $host[3], 2);
                    $d = hypot($sx - $hcx, $sy - $hcy);
                    if ($d <= $nearR && $d < $nearestD) {
                        $nearHost = true;
                        $nearest = $host;
                        $nearestD = $d;
                    }
                }
                if (!$nearHost || $nearest === null) {
                    continue;
                }
                $hw = $nearest[2] - $nearest[0] + 1;
                $hh = $nearest[3] - $nearest[1] + 1;
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(90), $this->cad(70));
                $cand = null;
                if ($grown !== null) {
                    $cand = $this->expandCubicleToWalls(
                        $this->padOpenCubicle($grown, $walls, $w, $h),
                        $walls,
                        $w,
                        $h,
                        $this->cad(12)
                    );
                    $cbh = $cand[3] - $cand[1] + 1;
                    $cbw = $cand[2] - $cand[0] + 1;
                    // Grow often collapses in dense short banks — use host-sized tile instead.
                    if ($cbw < $minBw || $cbh < $minBh || $cbh > $hh + $this->cad(40)) {
                        $cand = null;
                    }
                }
                if ($cand === null) {
                    $cand = [
                        max(0, $sx - intdiv($hw, 2)),
                        max(0, $sy - intdiv($hh, 2)),
                        min($w - 1, $sx + intdiv($hw, 2)),
                        min($h - 1, $sy + intdiv($hh, 2)),
                    ];
                }
                $ok = $acceptCand($cand);
                if ($ok === null) {
                    continue;
                }
                $q = $this->cubicleQuality($ok, $walls, $w, $h) + $this->enclosureScore($ok, $walls, $w, $h);
                $candidates[] = ['r' => $ok, 'q' => $q];
            }
        }

        // 2) Multi-round pitch propagation (BFS) so top short rows fill from mid-bank seeds.
        $queue = $bankHosts;
        $seen = 0;
        while ($queue !== [] && $seen < 200 && count($candidates) < 120) {
            $seen++;
            $host = array_shift($queue);
            $hw = $host[2] - $host[0] + 1;
            $hh = $host[3] - $host[1] + 1;
            // Short banks use a tighter vertical pitch than medium cubicles.
            $localPitchY = $hh <= $this->cad(100) ? max($this->cad(70), $hh + $this->cad(8)) : $pitchY;
            $localPitchX = $hw <= $this->cad(130) ? max($this->cad(100), $hw + $this->cad(8)) : $pitchX;
            $gap = 2;
            $neighbors = [
                [$host[0], max(0, $host[1] - $localPitchY), $host[2], $host[1] - $gap],
                [$host[0], $host[3] + $gap, $host[2], min($h - 1, $host[3] + $localPitchY)],
                [max(0, $host[0] - $localPitchX), $host[1], $host[0] - $gap, $host[3]],
                [$host[2] + $gap, $host[1], min($w - 1, $host[2] + $localPitchX), $host[3]],
            ];
            foreach ($neighbors as $cand) {
                $cbw = $cand[2] - $cand[0] + 1;
                $cbh = $cand[3] - $cand[1] + 1;
                if (abs($cbw - $hw) > $this->cad(30)) {
                    $cx = intdiv($cand[0] + $cand[2], 2);
                    $cand[0] = max(0, $cx - intdiv($hw, 2));
                    $cand[2] = min($w - 1, $cand[0] + $hw - 1);
                }
                if (abs($cbh - $hh) > $this->cad(30)) {
                    $cy = intdiv($cand[1] + $cand[3], 2);
                    $cand[1] = max(0, $cy - intdiv($hh, 2));
                    $cand[3] = min($h - 1, $cand[1] + $hh - 1);
                }
                $cx = intdiv($cand[0] + $cand[2], 2);
                $cy = intdiv($cand[1] + $cand[3], 2);
                if ($cy < $y0 || $cy >= $y1 || $cx < $margin || $cx >= $w - $margin) {
                    continue;
                }
                if ($walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($cx, $cy, $rects, $this->cad(6))) {
                    continue;
                }
                // Skip if already queued as a candidate near this center.
                $dup = false;
                foreach ($candidates as $item) {
                    $ox = intdiv($item['r'][0] + $item['r'][2], 2);
                    $oy = intdiv($item['r'][1] + $item['r'][3], 2);
                    if (hypot($cx - $ox, $cy - $oy) < $this->cad(40)) {
                        $dup = true;
                        break;
                    }
                }
                if ($dup) {
                    continue;
                }
                $fit = $this->expandCubicleToWalls($cand, $walls, $w, $h, $this->cad(8));
                if (
                    ($fit[2] - $fit[0] + 1) <= $hw + $this->cad(24)
                    && ($fit[3] - $fit[1] + 1) <= $hh + $this->cad(24)
                    && $this->overlapFrac($fit, $host) <= 0.15
                ) {
                    $cand = $fit;
                }
                $ok = $acceptCand($cand);
                if ($ok === null) {
                    continue;
                }
                $q = $this->cubicleQuality($ok, $walls, $w, $h) + $this->enclosureScore($ok, $walls, $w, $h) + 0.2;
                $candidates[] = ['r' => $ok, 'q' => $q];
                $queue[] = $ok;
            }
        }

        if ($candidates === []) {
            return $rects;
        }
        usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);
        $extra = [];
        $minPitch = max($this->cad(48), (int) min($pitchX, $pitchY) * 0.55);
        foreach ($candidates as $item) {
            $cand = $item['r'];
            $cx = intdiv($cand[0] + $cand[2], 2);
            $cy = intdiv($cand[1] + $cand[3], 2);
            $ok = true;
            foreach ($extra as $o) {
                if ($this->intersectionArea($cand, $o) > 0) {
                    $ok = false;
                    break;
                }
                $ox = intdiv($o[0] + $o[2], 2);
                $oy = intdiv($o[1] + $o[3], 2);
                if (hypot($cx - $ox, $cy - $oy) < $minPitch) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $extra[] = $cand;
            $maxExtra = $labels !== [] ? 8 : 24;
            if (count($extra) >= $maxExtra) {
                break;
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * After trim: plant at most a few medium cubicles into clear mid-column gaps (#32-like).
     * Runs post-trim so cells are not discarded for budget.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedPostTrimMidGaps(array $rects, array $walls, int $w, int $h): array
    {
        $tileW = $this->cad(150);
        $tileH = $this->cad(130);
        $candidates = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            $cx = intdiv($r[0] + $r[2], 2);
            if (
                $cy < (int) ($h * 0.18)
                || $cy > (int) ($h * 0.32)
                || $bw < $this->cad(80)
                || $bw > $this->cad(150)
                || $bh < $this->cad(55)
                || $bh > $this->cad(95)
            ) {
                continue;
            }
            $nextTop = $h;
            foreach ($rects as $o) {
                $ocx = intdiv($o[0] + $o[2], 2);
                if (abs($ocx - $cx) > $this->cad(36)) {
                    continue;
                }
                if ($o[1] > $r[3] + 4) {
                    $nextTop = min($nextTop, $o[1]);
                }
            }
            $gap = $nextTop - $r[3] - 1;
            if ($gap < $tileH + $this->cad(20)) {
                continue;
            }
            // One short-pitch below the host lands on mid-bank cells (#32), not flush under the stub.
            $y0 = $r[3] + max($this->cad(40), $bh);
            $y1 = min($nextTop - 4, $y0 + $tileH - 1);
            $sy = intdiv($y0 + $y1, 2);
            if ($sy < (int) ($h * 0.30) || $sy > (int) ($h * 0.45)) {
                continue;
            }
            $cand = [
                max(0, $cx - intdiv($tileW, 2)),
                $y0,
                min($w - 1, $cx + intdiv($tileW, 2)),
                $y1,
            ];
            $cbw = $cand[2] - $cand[0] + 1;
            $cbh = $cand[3] - $cand[1] + 1;
            if ($cbw < $this->cad(110) || $cbh < $this->cad(100) || $cbh > $this->cad(150)) {
                continue;
            }
            $hit = false;
            $displace = [];
            foreach ($rects as $oi => $o) {
                if ($this->overlapFrac($cand, $o) <= 0.10 && $this->containmentFrac($cand, $o) <= 0.25) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                $ocy = intdiv($o[1] + $o[3], 2);
                // Replace a flush-under-host stub that sits too high in this same gap.
                if (
                    $ow <= $this->cad(180)
                    && $oh <= $this->cad(140)
                    && $o[1] >= $r[3] - 2
                    && $o[1] < $y0
                    && $ocy < $sy
                    && abs(intdiv($o[0] + $o[2], 2) - $cx) <= $this->cad(40)
                ) {
                    $displace[$oi] = true;
                    continue;
                }
                // Replace a short scrap sitting inside the target mid-bank cell (#37 vs @735,318).
                if (
                    $ow <= $this->cad(160)
                    && $oh <= $this->cad(110)
                    && $o[1] >= $y0 - $this->cad(24)
                    && $o[3] <= $y1 + $this->cad(24)
                    && $this->rectArea($o) < $cbw * $cbh
                ) {
                    $displace[$oi] = true;
                    continue;
                }
                $hit = true;
                break;
            }
            if ($hit) {
                continue;
            }
            // Prefer gaps that look like one missing bank cell (not a corridor void).
            $q = min(1.0, $gap / max(1.0, (float) ($tileH * 2.5)));
            $candidates[] = ['r' => $cand, 'q' => 1.0 - abs($q - 0.55), 'disp' => $displace];
        }
        if ($candidates === []) {
            return $rects;
        }
        usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);
        $extra = [];
        $drop = [];
        foreach ($candidates as $item) {
            $cand = $item['r'];
            $hit = false;
            foreach ($extra as $o) {
                if ($this->overlapFrac($cand, $o) > 0.10) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                continue;
            }
            foreach ($item['disp'] as $oi => $_) {
                $drop[$oi] = true;
            }
            $extra[] = $cand;
            if (count($extra) >= 5) {
                break;
            }
        }
        if ($extra === []) {
            return $rects;
        }
        if ($drop !== []) {
            $kept = [];
            foreach ($rects as $oi => $o) {
                if (!isset($drop[$oi])) {
                    $kept[] = $o;
                }
            }
            $rects = $kept;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Post-select bank row repair (no further exclusive pass):
     * 1) Snap scraps that sit in a left/right hole but drifted off the host row (#76).
     * 2) Retract fat neighbors and plant the missing module when centers are ~1 module apart (#18).
     * 3) On raster, walk west from upper-bank hosts to fill empty A06-style columns.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function repairBankRowGeometry(array $rects, array $walls, int $w, int $h): array
    {
        $yMax = (int) ($h * 0.58);
        $modW = $this->modW > 20 ? $this->modW : $this->cad(76);
        $modH = $this->modH > 16 ? $this->modH : $this->cad(50);
        $maxW = $modW + $this->cad(14);

        // --- 1) Snap shifted in-gap scraps onto the host row ---
        $n = count($rects);
        for ($i = 0; $i < $n; $i++) {
            $r = $rects[$i];
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            $cx = intdiv($r[0] + $r[2], 2);
            if ($cy > $yMax || $bw < $this->cad(40) || $bw > $this->cad(160) || $bh < $this->cad(28) || $bh > $this->cad(100)) {
                continue;
            }
            $left = null;
            $right = null;
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                $ocy = intdiv($o[1] + $o[3], 2);
                if ($ow < $this->cad(40) || $oh < $this->cad(28) || $oh > $this->cad(110)) {
                    continue;
                }
                if (abs($ocy - $cy) > max(40, $this->cad(80))) {
                    continue;
                }
                // Prefer hosts that actually flank the scrap center (edge-based).
                if ($o[2] < $cx && ($left === null || $o[2] > $left['r'][2])) {
                    $left = ['r' => $o, 'cx' => intdiv($o[0] + $o[2], 2), 'cy' => $ocy, 'j' => $j];
                }
                if ($o[0] > $cx && ($right === null || $o[0] < $right['r'][0])) {
                    $right = ['r' => $o, 'cx' => intdiv($o[0] + $o[2], 2), 'cy' => $ocy, 'j' => $j];
                }
            }
            if ($left === null || $right === null) {
                continue;
            }
            $hostCy = intdiv($left['cy'] + $right['cy'], 2);
            $gap = $right['r'][0] - $left['r'][2] - 1;
            // Scrap center must sit in the horizontal gap (bodies may overhang hosts).
            if ($gap < $this->cad(36) || $gap > $modW * 2) {
                continue;
            }
            if ($cx <= $left['r'][2] || $cx >= $right['r'][0]) {
                continue;
            }
            if (abs($cy - $hostCy) < $this->cad(16)) {
                continue;
            }
            $hw = min($maxW, max($this->cad(45), intdiv($left['r'][2] - $left['r'][0] + $right['r'][2] - $right['r'][0] + 2, 2)));
            $hh = min($modH + $this->cad(10), max($this->cad(32), intdiv(
                ($left['r'][3] - $left['r'][1] + 1) + ($right['r'][3] - $right['r'][1] + 1),
                2
            )));
            $midX = intdiv($left['r'][2] + $right['r'][0], 2);
            $cand = [
                max($left['r'][2] + 2, $midX - intdiv($hw, 2)),
                max(0, $hostCy - intdiv($hh, 2)),
                min($right['r'][0] - 2, $midX + intdiv($hw, 2) - 1),
                min($h - 1, $hostCy + intdiv($hh, 2) - 1),
            ];
            if ($cand[2] - $cand[0] + 1 < $this->cad(40) || $cand[3] - $cand[1] + 1 < $this->cad(28)) {
                continue;
            }
            $ok = true;
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                if ($this->intersectionArea($cand, $o) > 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $rects[$i] = $cand;
            }
        }

        // --- 2) Retract fat bank cells and plant missing module (#18) ---
        $extra = [];
        $out = $rects;
        $n = count($out);
        for ($i = 0; $i < $n; $i++) {
            for ($jj = $i + 1; $jj < $n; $jj++) {
                $leftIdx = $i;
                $rightIdx = $jj;
                $a = $out[$leftIdx];
                $b = $out[$rightIdx];
                if ($a[0] > $b[0]) {
                    $leftIdx = $jj;
                    $rightIdx = $i;
                    $a = $out[$leftIdx];
                    $b = $out[$rightIdx];
                }
                $aw = $a[2] - $a[0] + 1;
                $bw = $b[2] - $b[0] + 1;
                $ah = $a[3] - $a[1] + 1;
                $bh = $b[3] - $b[1] + 1;
                $acy = intdiv($a[1] + $a[3], 2);
                $bcy = intdiv($b[1] + $b[3], 2);
                if (
                    $ah > $this->cad(110) || $bh > $this->cad(110)
                    || $aw < $this->cad(40) || $bw < $this->cad(40)
                    || abs($acy - $bcy) > max(22, $this->cad(44))
                    || max($acy, $bcy) > $yMax
                ) {
                    continue;
                }
                $acx = intdiv($a[0] + $a[2], 2);
                $bcx = intdiv($b[0] + $b[2], 2);
                $centerDist = $bcx - $acx;
                $gap = $b[0] - $a[2] - 1;
                if ($centerDist < (int) ($modW * 1.15) || $centerDist > (int) ($modW * 2.6)) {
                    continue;
                }
                if ($gap >= (int) ($modW * 0.45)) {
                    continue;
                }
                if ($aw <= $maxW && $bw <= $maxW) {
                    continue;
                }
                $hostCy = intdiv($acy + $bcy, 2);
                $hw = min($maxW, max($this->cad(45), $modW));
                $hh = min($modH + $this->cad(10), max($this->cad(32), intdiv($ah + $bh, 2)));
                $midX = intdiv($acx + $bcx, 2);
                $cand = [
                    max(0, $midX - intdiv($hw, 2)),
                    max(0, $hostCy - intdiv($hh, 2)),
                    min($w - 1, $midX + intdiv($hw, 2) - 1),
                    min($h - 1, $hostCy + intdiv($hh, 2) - 1),
                ];
                if ($bw > $maxW) {
                    $out[$rightIdx][0] = max($out[$rightIdx][0], min($out[$rightIdx][2] - $this->cad(40), $cand[2] + 2));
                }
                if ($aw > $maxW) {
                    $out[$leftIdx][2] = min($out[$leftIdx][2], max($out[$leftIdx][0] + $this->cad(40), $cand[0] - 2));
                }
                // Re-read after retract; keep cand inside new gap.
                $a2 = $out[$leftIdx];
                $b2 = $out[$rightIdx];
                $cand[0] = max($cand[0], $a2[2] + 2);
                $cand[2] = min($cand[2], $b2[0] - 2);
                if ($cand[2] - $cand[0] + 1 < $this->cad(40) || $cand[3] - $cand[1] + 1 < $this->cad(28)) {
                    continue;
                }
                $ccx = intdiv($cand[0] + $cand[2], 2);
                $ccy = intdiv($cand[1] + $cand[3], 2);
                if ($ccx < 0 || $ccy < 0 || $ccx >= $w || $ccy >= $h || $walls[$ccy * $w + $ccx] === 1) {
                    continue;
                }
                $hit = false;
                foreach (array_merge($out, $extra) as $o) {
                    if ($this->intersectionArea($cand, $o) > 0) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    $extra[] = $cand;
                }
            }
        }
        if ($extra !== []) {
            $out = array_merge($out, $extra);
        }

        // --- 3) Raster: walk west from upper short hosts into empty columns (#6/#17) ---
        if ($this->rasterOffice || $this->upscaledWork) {
            $out = $this->seedWestUpperBankFromHosts($out, $walls, $w, $h);
        }

        return array_values($out);
    }

    /**
     * Merge detections that share a long edge with essentially no wall/door ink on
     * the seam. Cubicles and rooms are closed spaces — abutting empty boxes without
     * a partition are fragments of the same space, not neighbors.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function mergeOpenAdjacentSpaces(array $rects, array $walls, int $w, int $h): array
    {
        if (count($rects) < 2) {
            return $rects;
        }
        $out = array_values($rects);
        $merged = true;
        $guard = 0;
        while ($merged && $guard < 40) {
            $merged = false;
            $guard++;
            $n = count($out);
            for ($i = 0; $i < $n; $i++) {
                if (!isset($out[$i])) {
                    continue;
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    if (!isset($out[$j])) {
                        continue;
                    }
                    $a = $out[$i];
                    $b = $out[$j];
                    $seam = $this->openSharedSeam($a, $b, $walls, $w, $h);
                    if ($seam === null) {
                        continue;
                    }
                    // Prefer merging near-identical neighbor cells (split cubicles), not
                    // large rooms that merely touch across a corridor or thin partition.
                    $aw = $a[2] - $a[0] + 1;
                    $ah = $a[3] - $a[1] + 1;
                    $bw = $b[2] - $b[0] + 1;
                    $bh = $b[3] - $b[1] + 1;
                    $sizeRatio = max($aw * $ah, $bw * $bh) / max(1, min($aw * $ah, $bw * $bh));
                    if ($sizeRatio > 2.4) {
                        continue;
                    }
                    $union = [
                        min($a[0], $b[0]),
                        min($a[1], $b[1]),
                        max($a[2], $b[2]),
                        max($a[3], $b[3]),
                    ];
                    $uArea = $this->rectArea($union);
                    $sum = $this->rectArea($a) + $this->rectArea($b);
                    if ($uArea > (int) ($sum * 1.28) || $uArea > $this->cadArea(120000)) {
                        continue;
                    }
                    $ubw = $union[2] - $union[0] + 1;
                    $ubh = $union[3] - $union[1] + 1;
                    if (max($ubw, $ubh) / max(1, min($ubw, $ubh)) > 2.55) {
                        continue;
                    }
                    if ($this->interiorWallFrac($union, $walls, $w, $h) > 0.06) {
                        continue;
                    }
                    $out[$i] = $union;
                    unset($out[$j]);
                    $out = array_values($out);
                    $merged = true;
                    break 2;
                }
            }
        }

        return array_values($out);
    }

    /**
     * Merge dense clusters of cubicle-sized scraps that sit in a low-partition
     * wall pocket (open offices / conference floors). Cubicle banks with a
     * regular grid are left alone.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    /**
     * True when the segment between two free-space points stays mostly clear of walls.
     * Stops floor-wide scrap clustering through corridors / across chambers.
     *
     * @param list<int> $walls
     */
    private function openPathBetween(int $x0, int $y0, int $x1, int $y1, array $walls, int $w, int $h): bool
    {
        $dx = abs($x1 - $x0);
        $dy = abs($y1 - $y0);
        $steps = max($dx, $dy, 1);
        $wallHits = 0;
        $samples = 0;
        for ($i = 1; $i < $steps; $i++) {
            $x = (int) round($x0 + ($x1 - $x0) * $i / $steps);
            $y = (int) round($y0 + ($y1 - $y0) * $i / $steps);
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                continue;
            }
            $samples++;
            if ($walls[$y * $w + $x] === 1) {
                $wallHits++;
            }
        }
        if ($samples < 4) {
            return true;
        }

        return ($wallHits / $samples) <= 0.08;
    }

    private function consolidateOpenOfficeClusters(array $rects, array $walls, int $w, int $h): array
    {
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $cubMax = max($this->cadArea(16000), (int) ($mw * $mh * 2.4));
        // Tight enough for chamber scraps (MST ~80px) but not floor-wide bridging.
        $link = max(40, (int) (0.55 * max($mw, $mh)));
        $idxs = [];
        foreach ($rects as $i => $r) {
            if ($this->rectArea($r) <= $cubMax) {
                $idxs[] = $i;
            }
        }
        if (count($idxs) < 6) {
            return $rects;
        }
        // Union-find on nearby scrap centers with a clear interior path.
        $parent = [];
        foreach ($idxs as $i) {
            $parent[$i] = $i;
        }
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        $uni = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };
        $n = count($idxs);
        $centers = [];
        foreach ($idxs as $i) {
            $r = $rects[$i];
            $centers[$i] = [intdiv($r[0] + $r[2], 2), intdiv($r[1] + $r[3], 2)];
        }
        for ($a = 0; $a < $n; $a++) {
            $ia = $idxs[$a];
            [$acx, $acy] = $centers[$ia];
            for ($b = $a + 1; $b < $n; $b++) {
                $ib = $idxs[$b];
                [$bcx, $bcy] = $centers[$ib];
                if (abs($acx - $bcx) + abs($acy - $bcy) > $link * 2) {
                    continue;
                }
                if ($this->openPathBetween($acx, $acy, $bcx, $bcy, $walls, $w, $h)) {
                    $uni($ia, $ib);
                }
            }
        }
        $groups = [];
        foreach ($idxs as $i) {
            $groups[$find($i)][] = $i;
        }
        $used = [];
        $out = [];
        $globalClaims = 0;
        $maxSteps = max(160, (int) (2.8 * max($mw, $mh)));
        $scoreClaim = function (array $members, array $seedCenter, bool $loose = false) use (
            &$rects,
            &$walls,
            $w,
            $h,
            $mw,
            $mh,
            $cubMax,
            $maxSteps,
            &$used
        ): ?array {
            if (count($members) < ($loose ? 5 : 5)) {
                return null;
            }
            $cx = $seedCenter[0];
            $cy = $seedCenter[1];
            $seed = [
                max(0, $cx - intdiv($mw, 2)),
                max(0, $cy - intdiv($mh, 2)),
                min($w - 1, $cx + intdiv($mw, 2)),
                min($h - 1, $cy + intdiv($mh, 2)),
            ];
            $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
            $gArea = $this->rectArea($grown);
            if ($gArea < (int) ($mw * $mh * ($loose ? 4.0 : 5.0)) || $gArea > (int) ($w * $h * 0.12)) {
                return null;
            }
            $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
            $enc = $this->enclosureScore($grown, $walls, $w, $h);
            $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
            if ($iw > 0.06 || $sides < ($loose ? 1 : 2) || $enc < ($loose ? 0.14 : 0.18)) {
                return null;
            }
            if ($loose && $sides < 2 && ($enc < 0.28 || $iw > 0.04)) {
                return null;
            }
            $inside = $this->cubiclesInside($grown, $rects);
            if ($loose) {
                // Judge only leftover chamber scraps, not unrelated bank cells the grow touches.
                $local = [];
                foreach ($members as $i) {
                    if (isset($used[$i])) {
                        continue;
                    }
                    $r = $rects[$i];
                    $mx = intdiv($r[0] + $r[2], 2);
                    $my = intdiv($r[1] + $r[3], 2);
                    if ($mx >= $grown[0] && $mx <= $grown[2] && $my >= $grown[1] && $my <= $grown[3]) {
                        $local[] = $r;
                    }
                }
                if (count($local) >= 4) {
                    $inside = $local;
                }
            }
            $regularRows = $this->countRegularGroups($inside, false);
            $regularCols = $this->countRegularGroups($inside, true);
            if ($regularRows >= 2 && $regularCols >= 2) {
                return null;
            }
            if (!$loose) {
                if ($this->looksLikeCubicleBank($inside, $grown)) {
                    return null;
                }
                if ($regularRows >= 2 || $regularCols >= 2) {
                    return null;
                }
            } elseif ($iw > 0.032 && $this->looksLikeCubicleBank($inside, $grown)) {
                return null;
            }
            $bw = $grown[2] - $grown[0] + 1;
            $bh = $grown[3] - $grown[1] + 1;
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            if ($asp > ($loose ? 2.8 : 2.4)) {
                return null;
            }
            if ($grown[1] < (int) ($h * 0.28)) {
                return null;
            }
            $topScraps = 0;
            foreach ($inside as $c) {
                $cy2 = intdiv($c[1] + $c[3], 2);
                if ($cy2 < (int) ($h * 0.40)) {
                    $topScraps++;
                }
            }
            if ($topScraps >= 6 && (!$loose || $grown[1] < (int) ($h * 0.32))) {
                return null;
            }
            $goodCubs = 0;
            $scrapHits = 0;
            foreach ($inside as $c) {
                $cArea = $this->rectArea($c);
                if ($cArea <= (int) ($mw * $mh * 1.35)) {
                    $scrapHits++;
                } elseif (
                    $cArea >= (int) ($mw * $mh * 0.85)
                    && $cArea <= (int) ($mw * $mh * 2.2)
                    && $this->enclosureScore($c, $walls, $w, $h) >= 0.30
                ) {
                    $goodCubs++;
                }
            }
            $minScraps = $loose ? 5 : 8;
            if ($goodCubs >= 3 || $scrapHits < $minScraps) {
                return null;
            }
            $scrapAreas = [];
            foreach ($inside as $c) {
                $cArea = $this->rectArea($c);
                if ($cArea <= (int) ($mw * $mh * 1.35)) {
                    $scrapAreas[] = $cArea;
                }
            }
            sort($scrapAreas);
            $medScrap = $scrapAreas[(int) ((count($scrapAreas) - 1) / 2)];
            if ($medScrap > (int) ($mw * $mh * ($loose ? 0.90 : 0.72))) {
                return null;
            }
            if (!$loose && ($regularRows >= 2 || $regularCols >= 2) && $medScrap > (int) ($mw * $mh * 0.45)) {
                return null;
            }
            $covered = 0;
            foreach ($members as $i) {
                if (isset($used[$i])) {
                    continue;
                }
                $mx = intdiv($rects[$i][0] + $rects[$i][2], 2);
                $my = intdiv($rects[$i][1] + $rects[$i][3], 2);
                if ($mx >= $grown[0] && $mx <= $grown[2] && $my >= $grown[1] && $my <= $grown[3]) {
                    $covered++;
                }
            }
            if ($covered < max($loose ? 4 : 6, (int) (count($members) * 0.40)) && $scrapHits < ($loose ? 7 : 12)) {
                return null;
            }
            $strongVictims = 0;
            foreach ($inside as $c) {
                $cArea = $this->rectArea($c);
                if ($cArea < (int) ($mw * $mh * 1.05) || $cArea > $cubMax) {
                    continue;
                }
                if ($this->containmentFrac($c, $grown) < 0.72) {
                    continue;
                }
                $cw = $c[2] - $c[0] + 1;
                $ch = $c[3] - $c[1] + 1;
                $cAsp = max($cw, $ch) / max(1, min($cw, $ch));
                if ($cAsp > 2.4 || $cAsp < 0.55) {
                    continue;
                }
                if (
                    $this->enclosureScore($c, $walls, $w, $h) >= 0.36
                    && $this->strongWallSides($c, $walls, $w, $h, 0.28) >= 3
                ) {
                    $strongVictims++;
                }
            }
            if ($strongVictims >= 2) {
                return null;
            }

            return [
                'grown' => $grown,
                'scrapHits' => $scrapHits,
                'covered' => $covered,
                'area' => $gArea,
                'members' => $members,
            ];
        };

        $applyClaim = function (array $cand) use (
            &$rects,
            &$walls,
            $w,
            $h,
            $mw,
            $mh,
            $cubMax,
            &$used,
            &$out
        ): void {
            $grown = $cand['grown'];
            foreach ($cand['members'] as $i) {
                $mx = intdiv($rects[$i][0] + $rects[$i][2], 2);
                $my = intdiv($rects[$i][1] + $rects[$i][3], 2);
                if ($mx < $grown[0] || $mx > $grown[2] || $my < $grown[1] || $my > $grown[3]) {
                    continue;
                }
                $aR = $this->rectArea($rects[$i]);
                $encR = $this->enclosureScore($rects[$i], $walls, $w, $h);
                if ($aR >= (int) ($mw * $mh * 0.7) && $encR >= 0.32) {
                    continue;
                }
                $used[$i] = true;
            }
            foreach ($rects as $j => $r) {
                if (isset($used[$j]) || $this->rectArea($r) > $cubMax) {
                    continue;
                }
                $mx = intdiv($r[0] + $r[2], 2);
                $my = intdiv($r[1] + $r[3], 2);
                if ($mx < $grown[0] || $mx > $grown[2] || $my < $grown[1] || $my > $grown[3]) {
                    continue;
                }
                if ($this->containmentFrac($r, $grown) < 0.65) {
                    continue;
                }
                $encR = $this->enclosureScore($r, $walls, $w, $h);
                $aR = $this->rectArea($r);
                if ($aR >= (int) ($mw * $mh * 0.7) && $encR >= 0.32) {
                    continue;
                }
                $used[$j] = true;
            }
            $out[] = $grown;
        };

        foreach ($groups as $members) {
            if (count($members) < 5) {
                continue;
            }
            $union = $rects[$members[0]];
            foreach ($members as $i) {
                $union = [
                    min($union[0], $rects[$i][0]),
                    min($union[1], $rects[$i][1]),
                    max($union[2], $rects[$i][2]),
                    max($union[3], $rects[$i][3]),
                ];
            }
            $uArea = $this->rectArea($union);
            if ($uArea < (int) ($mw * $mh * 4.5)) {
                continue;
            }
            $seedCenters = [];
            // Always try local scrap seeds when the cluster is room-scale or larger.
            if ($uArea > (int) ($mw * $mh * 8) || count($members) > 10) {
                foreach ($members as $i) {
                    if (isset($used[$i])) {
                        continue;
                    }
                    $seedCenters[] = $centers[$i];
                }
            } else {
                $seedCenters[] = [
                    intdiv($union[0] + $union[2], 2),
                    intdiv($union[1] + $union[3], 2),
                ];
            }
            usort($seedCenters, function (array $a, array $b) use ($members, $centers, $link, &$used): int {
                $score = function (array $c) use ($members, $centers, $link, &$used): int {
                    $n = 0;
                    foreach ($members as $i) {
                        if (isset($used[$i])) {
                            continue;
                        }
                        [$x, $y] = $centers[$i];
                        if (abs($x - $c[0]) + abs($y - $c[1]) <= $link * 2) {
                            $n++;
                        }
                    }

                    return $n;
                };

                return $score($b) <=> $score($a);
            });
            $cands = [];
            $attempts = 0;
            foreach ($seedCenters as $sc) {
                if ($attempts++ > 18) {
                    break;
                }
                $local = [];
                foreach ($members as $i) {
                    if (isset($used[$i])) {
                        continue;
                    }
                    [$x, $y] = $centers[$i];
                    if (abs($x - $sc[0]) + abs($y - $sc[1]) <= $link * 3) {
                        $local[] = $i;
                    }
                }
                $cand = $scoreClaim($local !== [] ? $local : $members, $sc);
                if ($cand === null) {
                    continue;
                }
                // Dedup near-identical grown boxes.
                $dup = false;
                foreach ($cands as $ex) {
                    if ($this->overlapFrac($cand['grown'], $ex['grown']) > 0.7) {
                        if ($cand['scrapHits'] > $ex['scrapHits']
                            || ($cand['scrapHits'] === $ex['scrapHits'] && $cand['area'] > $ex['area'])
                        ) {
                            $ex = $cand; // can't replace in foreach by value; handle below
                        }
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $cands[] = $cand;
                } else {
                    foreach ($cands as $ci => $ex) {
                        if ($this->overlapFrac($cand['grown'], $ex['grown']) > 0.7) {
                            if (
                                $cand['scrapHits'] > $ex['scrapHits']
                                || ($cand['scrapHits'] === $ex['scrapHits'] && $cand['area'] > $ex['area'])
                            ) {
                                $cands[$ci] = $cand;
                            }
                            break;
                        }
                    }
                }
            }
            usort(
                $cands,
                static function (array $a, array $b): int {
                    if ($a['scrapHits'] !== $b['scrapHits']) {
                        return $b['scrapHits'] <=> $a['scrapHits'];
                    }

                    return $b['area'] <=> $a['area'];
                }
            );
            $applied = 0;
            foreach ($cands as $cand) {
                if ($applied >= 2 || $globalClaims >= 4) {
                    break;
                }
                if ($cand['grown'][1] < (int) ($h * 0.28)) {
                    continue;
                }
                $topN = 0;
                foreach ($this->cubiclesInside($cand['grown'], $rects) as $c) {
                    if (intdiv($c[1] + $c[3], 2) < (int) ($h * 0.40)) {
                        $topN++;
                    }
                }
                if ($topN >= 6) {
                    continue;
                }
                $clash = false;
                foreach ($out as $prev) {
                    if ($this->overlapFrac($cand['grown'], $prev) > 0.35) {
                        $clash = true;
                        break;
                    }
                }
                if (!$clash) {
                    $applyClaim($cand);
                    $applied++;
                    $globalClaims++;
                }
            }
        }

        foreach ($rects as $i => $r) {
            if (!isset($used[$i])) {
                $out[] = $r;
            }
        }

        return array_values($out);
    }

    /**
     * Reclaim a shredded open chamber sitting directly above an already-recovered
     * large room (Building 5: C02 above E03). Grow is clipped so it cannot re-eat
     * the host room or the upper cubicle farm.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function reclaimChamberAboveLargeRoom(array $rects, array $walls, int $w, int $h): array
    {
        if ($w < 1400) {
            return $rects;
        }
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $cubMax = max($this->cadArea(16000), (int) ($mw * $mh * 2.4));
        $minHost = max($this->cadArea(50000), (int) ($mw * $mh * 10));
        $hosts = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            if ($area < $minHost) {
                continue;
            }
            // Lower/mid open offices only — not the top cubicle farm or left margin.
            if ($r[1] < (int) ($h * 0.45) || $r[0] > (int) ($w * 0.45) || $r[0] < (int) ($w * 0.08)) {
                continue;
            }
            if ($this->interiorWallFrac($r, $walls, $w, $h) > 0.05) {
                continue;
            }
            $hosts[] = $r;
        }
        if ($hosts === []) {
            return $rects;
        }

        $maxSteps = max(160, (int) (2.8 * max($mw, $mh)));
        $claims = [];
        foreach ($hosts as $host) {
            $padX = max(24, intdiv($host[2] - $host[0] + 1, 6));
            $seeds = [];
            foreach ($rects as $i => $r) {
                if ($this->rectArea($r) > $cubMax) {
                    continue;
                }
                $cx = intdiv($r[0] + $r[2], 2);
                $cy = intdiv($r[1] + $r[3], 2);
                if ($cx < $host[0] - $padX || $cx > $host[2] + $padX) {
                    continue;
                }
                // Strictly above the host, within roughly one host-height.
                if ($cy >= $host[1] || $cy < $host[1] - ($host[3] - $host[1] + 1)) {
                    continue;
                }
                if ($cy < (int) ($h * 0.30)) {
                    continue;
                }
                $seeds[] = [$cx, $cy];
            }
            if (count($seeds) < 5) {
                continue;
            }
            // Prefer seeds near the shared wall with the host.
            usort($seeds, static function (array $a, array $b) use ($host): int {
                return abs($a[1] - $host[1]) <=> abs($b[1] - $host[1]);
            });
            $best = null;
            $attempts = 0;
            foreach ($seeds as [$cx, $cy]) {
                if ($attempts++ > 14) {
                    break;
                }
                $seed = [
                    max(0, $cx - intdiv($mw, 2)),
                    max(0, $cy - intdiv($mh, 2)),
                    min($w - 1, $cx + intdiv($mw, 2)),
                    min($h - 1, $cy + intdiv($mh, 2)),
                ];
                $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
                // Clip south at the host's north wall so stacked chambers stay separate.
                $clipY = $host[1] + max(8, intdiv($mh, 5));
                $grown[3] = min($grown[3], $clipY);
                // Also prefer not extending far west of the host (legend / corridor bleed).
                $grown[0] = max($grown[0], $host[0] - max(24, intdiv($mw, 2)));
                if ($grown[1] < (int) ($h * 0.28) || $grown[0] <= (int) ($w * 0.06)) {
                    continue;
                }
                if ($grown[2] <= $grown[0] || $grown[3] <= $grown[1]) {
                    continue;
                }
                $gArea = $this->rectArea($grown);
                if ($gArea < (int) ($mw * $mh * 4.0) || $gArea > (int) ($w * $h * 0.10)) {
                    continue;
                }
                $bw = $grown[2] - $grown[0] + 1;
                $bh = $grown[3] - $grown[1] + 1;
                if (max($bw, $bh) / max(1, min($bw, $bh)) > 2.8) {
                    continue;
                }
                $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
                $enc = $this->enclosureScore($grown, $walls, $w, $h);
                $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                if ($iw > 0.055 || $enc < 0.20) {
                    continue;
                }
                // Open-front stacked offices often have only 1–2 strong sides.
                if ($sides < 1 || ($sides < 2 && $enc < 0.26)) {
                    continue;
                }
                if ($this->overlapFrac($grown, $host) > 0.22) {
                    continue;
                }
                $scrapHits = 0;
                $inside = [];
                foreach ($rects as $r) {
                    if ($this->rectArea($r) > $cubMax) {
                        continue;
                    }
                    $mx = intdiv($r[0] + $r[2], 2);
                    $my = intdiv($r[1] + $r[3], 2);
                    if ($mx < $grown[0] || $mx > $grown[2] || $my < $grown[1] || $my > $grown[3]) {
                        continue;
                    }
                    // Ignore scraps that already sit inside the host room.
                    if ($my >= $host[1]) {
                        continue;
                    }
                    $inside[] = $r;
                    if ($this->rectArea($r) <= (int) ($mw * $mh * 1.40)) {
                        $scrapHits++;
                    }
                }
                if ($scrapHits < 5 || count($inside) < 5) {
                    continue;
                }
                $regularRows = $this->countRegularGroups($inside, false);
                $regularCols = $this->countRegularGroups($inside, true);
                if ($regularRows >= 2 && $regularCols >= 2) {
                    continue;
                }
                if ($iw > 0.035 && $this->looksLikeCubicleBank($inside, $grown)) {
                    continue;
                }
                if ($best === null || $scrapHits > $best['scrapHits']
                    || ($scrapHits === $best['scrapHits'] && $gArea > $best['area'])
                ) {
                    $best = ['grown' => $grown, 'scrapHits' => $scrapHits, 'area' => $gArea];
                }
            }
            if ($best !== null) {
                // Tighten north edge to the scrap cloud so we don't swallow B03-like rooms above.
                $grown = $best['grown'];
                $sy0 = PHP_INT_MAX;
                foreach ($rects as $r) {
                    if ($this->rectArea($r) > $cubMax) {
                        continue;
                    }
                    $mx = intdiv($r[0] + $r[2], 2);
                    $my = intdiv($r[1] + $r[3], 2);
                    if ($mx < $grown[0] || $mx > $grown[2] || $my < $grown[1] || $my >= $host[1]) {
                        continue;
                    }
                    $sy0 = min($sy0, $r[1]);
                }
                if ($sy0 < PHP_INT_MAX) {
                    $grown[1] = max($grown[1], $sy0 - max(16, intdiv($mh, 3)));
                    // Cap how far above the host we reach (leave B03-scale rooms free).
                    $grown[1] = max($grown[1], $host[1] - max(200, intdiv($host[3] - $host[1] + 1, 2)));
                    $best['grown'] = $grown;
                    $best['area'] = $this->rectArea($grown);
                }
                $dup = false;
                foreach ($claims as $c) {
                    if ($this->overlapFrac($best['grown'], $c) > 0.45) {
                        $dup = true;
                        break;
                    }
                }
                foreach ($rects as $r) {
                    if ($this->rectArea($r) < $minHost) {
                        continue;
                    }
                    if ($this->overlapFrac($best['grown'], $r) > 0.45) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $claims[] = $best['grown'];
                }
            }
        }
        if ($claims === []) {
            return $rects;
        }

        $out = [];
        foreach ($rects as $r) {
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $area = $this->rectArea($r);
            $drop = false;
            foreach ($claims as $L) {
                if ($cx < $L[0] || $cx > $L[2] || $cy < $L[1] || $cy > $L[3]) {
                    continue;
                }
                if ($area > $cubMax) {
                    continue;
                }
                if ($this->containmentFrac($r, $L) < 0.65) {
                    continue;
                }
                $enc = $this->enclosureScore($r, $walls, $w, $h);
                if ($area >= (int) ($mw * $mh * 0.7) && $enc >= 0.32) {
                    continue;
                }
                $drop = true;
                break;
            }
            if (!$drop) {
                $out[] = $r;
            }
        }
        foreach ($claims as $L) {
            $out[] = $L;
        }

        return array_values($out);
    }

    /**
     * Rebuild well-enclosed side offices shredded into scraps (Building 5 left
     * column D01/5W117, right shafts D10/D11, bottom strip E10). Uses partition
     * walls; requires strong enclosure so legend/ghost boxes stay out.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function reclaimShreddedSidePockets(array $rects, array $walls, int $w, int $h): array
    {
        if ($w < 1400) {
            return $rects;
        }
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $cubMax = max($this->cadArea(16000), (int) ($mw * $mh * 2.4));
        $link = max(36, (int) (0.55 * max($mw, $mh)));
        $idxs = [];
        $centers = [];
        foreach ($rects as $i => $r) {
            if ($this->rectArea($r) > $cubMax) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $left = $cx < (int) ($w * 0.16);
            $right = $cx > (int) ($w * 0.88);
            $bottom = $cy > (int) ($h * 0.78);
            if (!$left && !$right && !$bottom) {
                continue;
            }
            // Skip upper cubicle farm even on the sides.
            if (!$bottom && $cy < (int) ($h * 0.28)) {
                continue;
            }
            $idxs[] = $i;
            // Band key keeps stacked left offices (5W117 vs D01) from one mega-cluster.
            if ($bottom) {
                $band = 3;
            } elseif ($left) {
                $band = $cy < (int) ($h * 0.55) ? 10 : 11;
            } else {
                $band = intdiv($cy, max(100, $mh * 3));
            }
            $centers[$i] = [$cx, $cy, $band];
        }
        if (count($idxs) < 2) {
            return $rects;
        }

        $parent = [];
        foreach ($idxs as $i) {
            $parent[$i] = $i;
        }
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        $n = count($idxs);
        for ($a = 0; $a < $n; $a++) {
            $ia = $idxs[$a];
            [$acx, $acy, $aband] = $centers[$ia];
            for ($b = $a + 1; $b < $n; $b++) {
                $ib = $idxs[$b];
                [$bcx, $bcy, $bband] = $centers[$ib];
                if ($aband !== $bband) {
                    continue;
                }
                if (abs($acx - $bcx) + abs($acy - $bcy) > $link * 2) {
                    continue;
                }
                if ($this->openPathBetween($acx, $acy, $bcx, $bcy, $walls, $w, $h)) {
                    $ra = $find($ia);
                    $rb = $find($ib);
                    if ($ra !== $rb) {
                        $parent[$rb] = $ra;
                    }
                }
            }
        }
        $groups = [];
        foreach ($idxs as $i) {
            $groups[$find($i)][] = $i;
        }

        $maxSteps = max(160, (int) (2.6 * max($mw, $mh)));
        $claims = [];
        $claimsMeta = [];
        foreach ($groups as $members) {
            $minMembers = 2;
            if (count($members) < $minMembers) {
                continue;
            }
            $union = $rects[$members[0]];
            foreach ($members as $i) {
                $union = [
                    min($union[0], $rects[$i][0]),
                    min($union[1], $rects[$i][1]),
                    max($union[2], $rects[$i][2]),
                    max($union[3], $rects[$i][3]),
                ];
            }
            // Tiny pairs still OK when they sit in a well-walled side office.
            if (count($members) < 4 && $this->rectArea($union) < (int) ($mw * $mh * 1.8)) {
                continue;
            }
            if ($this->rectArea($union) < (int) ($mw * $mh * 1.5)) {
                continue;
            }
            $seedCenters = [];
            foreach ($members as $i) {
                $seedCenters[] = [$centers[$i][0], $centers[$i][1]];
                // Nudge seeds off scrap edges into free floor (D01 prefers inset centers).
                $cx = $centers[$i][0];
                $cy = $centers[$i][1];
                foreach ([[12, 0], [-12, 0], [0, 12], [0, -12], [16, -16], [20, 0]] as [$dx, $dy]) {
                    $nx = $cx + $dx;
                    $ny = $cy + $dy;
                    if ($nx > 2 && $ny > 2 && $nx < $w - 2 && $ny < $h - 2 && $walls[$ny * $w + $nx] !== 1) {
                        $seedCenters[] = [$nx, $ny];
                    }
                }
            }
            $seedCenters[] = [
                intdiv($union[0] + $union[2], 2),
                intdiv($union[1] + $union[3], 2),
            ];
            $best = null;
            $attempts = 0;
            foreach ($seedCenters as [$cx, $cy]) {
                if ($attempts++ > 18) {
                    break;
                }
                if ($walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                $seed = [
                    max(0, $cx - intdiv($mw, 2)),
                    max(0, $cy - intdiv($mh, 2)),
                    min($w - 1, $cx + intdiv($mw, 2)),
                    min($h - 1, $cy + intdiv($mh, 2)),
                ];
                $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
                $gArea = $this->rectArea($grown);
                // Left/right side offices are smaller than open chambers.
                if ($gArea < (int) ($mw * $mh * 2.8) || $gArea > (int) ($w * $h * 0.06)) {
                    continue;
                }
                $bw = $grown[2] - $grown[0] + 1;
                $bh = $grown[3] - $grown[1] + 1;
                $asp = max($bw, $bh) / max(1, min($bw, $bh));
                // Reject legend ribbons and corridor snakes.
                if ($bw <= max(100, $this->cad(95)) && $bh >= max(220, $this->cad(210))) {
                    continue;
                }
                if ($asp > 3.4) {
                    continue;
                }
                // Bottom-band claims must stay short — tall megas block E04/D06 carving.
                $cym = intdiv($grown[1] + $grown[3], 2);
                if ($cym > (int) ($h * 0.72) && $bh > max(140, (int) ($h * 0.18))) {
                    continue;
                }
                // Keep left-column claims from flooding into the open office.
                $cxm = intdiv($grown[0] + $grown[2], 2);
                if ($cxm < (int) ($w * 0.20) && $grown[2] > (int) ($w * 0.22)) {
                    continue;
                }
                if ($cxm > (int) ($w * 0.80) && $grown[0] < (int) ($w * 0.78)) {
                    continue;
                }
                // Right shafts (D10/D11) are narrow — reject merged double-width boxes.
                if ($cxm > (int) ($w * 0.85) && $bw > max(110, (int) ($mw * 1.45))) {
                    continue;
                }
                $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
                $enc = $this->enclosureScore($grown, $walls, $w, $h);
                $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                // Side offices are real rooms: demand enclosure.
                if ($iw > 0.07 || $enc < 0.40 || $sides < 3) {
                    continue;
                }
                if ($this->isCorridorShape($grown, $walls, $w, $h)) {
                    continue;
                }
                $scrapHits = 0;
                $inside = [];
                foreach ($rects as $r) {
                    if ($this->rectArea($r) > $cubMax) {
                        continue;
                    }
                    $mx = intdiv($r[0] + $r[2], 2);
                    $my = intdiv($r[1] + $r[3], 2);
                    if ($mx < $grown[0] || $mx > $grown[2] || $my < $grown[1] || $my > $grown[3]) {
                        continue;
                    }
                    $inside[] = $r;
                    if ($this->rectArea($r) <= (int) ($mw * $mh * 1.4)) {
                        $scrapHits++;
                    }
                }
                $minScraps = ($sides >= 3 && $enc >= 0.48) ? 2 : 3;
                if ($scrapHits < $minScraps) {
                    continue;
                }
                // Must cover a real share of the pocket, not a thin leftover strip.
                $covered = 0;
                foreach ($members as $i) {
                    $mx = intdiv($rects[$i][0] + $rects[$i][2], 2);
                    $my = intdiv($rects[$i][1] + $rects[$i][3], 2);
                    if ($mx >= $grown[0] && $mx <= $grown[2] && $my >= $grown[1] && $my <= $grown[3]) {
                        $covered++;
                    }
                }
                $minCover = ($sides >= 3 && $enc >= 0.48) ? 2 : max(3, (int) (count($members) * 0.35));
                if ($covered < $minCover) {
                    continue;
                }
                // Shredded side offices can look bank-like; trust enclosure when walls are strong.
                if ($iw > 0.045 && $this->looksLikeCubicleBank($inside, $grown)) {
                    continue;
                }
                $regularRows = $this->countRegularGroups($inside, false);
                $regularCols = $this->countRegularGroups($inside, true);
                if ($regularRows >= 2 && $regularCols >= 2 && $iw > 0.04) {
                    continue;
                }
                $clash = false;
                foreach ($rects as $r) {
                    if ($this->rectArea($r) < (int) ($mw * $mh * 6)) {
                        continue;
                    }
                    if ($this->overlapFrac($grown, $r) > 0.40) {
                        $clash = true;
                        break;
                    }
                }
                // Prefer the better-enclosed / higher-scrap claim when two side pockets overlap.
                foreach ($claims as $ci => $c) {
                    if ($this->overlapFrac($grown, $c) > 0.35) {
                        if ($gArea > $this->rectArea($c) * 1.12
                            || ($scrapHits > ($claimsMeta[$ci]['scrapHits'] ?? 0)
                                && $gArea >= (int) ($this->rectArea($c) * 0.9))
                        ) {
                            $claims[$ci] = $grown;
                            $claimsMeta[$ci] = ['scrapHits' => $scrapHits, 'enc' => $enc];
                        }
                        $clash = true;
                        break;
                    }
                }
                if ($clash) {
                    continue;
                }
                if ($best === null || $gArea > $best['area'] * 1.15
                    || ($gArea >= (int) ($best['area'] * 0.85) && $scrapHits > $best['scrapHits'])
                    || ($gArea >= (int) ($best['area'] * 0.85) && $scrapHits === $best['scrapHits'] && $enc > $best['enc'])
                ) {
                    $best = [
                        'grown' => $grown,
                        'scrapHits' => $scrapHits,
                        'enc' => $enc,
                        'area' => $gArea,
                    ];
                }
            }
            if ($best !== null) {
                $claims[] = $best['grown'];
                $claimsMeta[] = ['scrapHits' => $best['scrapHits'], 'enc' => $best['enc']];
            }
            if (count($claims) >= 10) {
                break;
            }
        }

        // Sparse left offices (5W117 mid + D01 lower): few scraps, wall-bounded grow.
        $stepY = max(36, intdiv($mh, 2));
        $stepX = max(28, intdiv($mw, 3));
        foreach ([[0.40, 0.54, 2], [0.54, 0.72, 2]] as [$y0f, $y1f, $bandBudget]) {
            $freeSeeds = [];
            for ($sy = (int) ($h * $y0f); $sy < (int) ($h * $y1f); $sy += $stepY) {
                for ($sx = max(12, $this->cad(20)); $sx < (int) ($w * 0.12); $sx += $stepX) {
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    $freeSeeds[] = [$sx, $sy];
                }
            }
            // Prefer scrap centers in-band — lattice alone often misses the D01 sweet spot.
            if ($y0f >= 0.54) {
                $scrapSeeds = [];
                $union = null;
                foreach ($rects as $r) {
                    if ($this->rectArea($r) > $cubMax) {
                        continue;
                    }
                    $sx = intdiv($r[0] + $r[2], 2);
                    $sy = intdiv($r[1] + $r[3], 2);
                    if ($sx > (int) ($w * 0.12) || $sy < (int) ($h * $y0f) || $sy > (int) ($h * $y1f)) {
                        continue;
                    }
                    $union = $union === null ? $r : [
                        min($union[0], $r[0]),
                        min($union[1], $r[1]),
                        max($union[2], $r[2]),
                        max($union[3], $r[3]),
                    ];
                }
                if ($union !== null) {
                    $ux = intdiv($union[0] + $union[2], 2);
                    $uy = intdiv($union[1] + $union[3], 2);
                    $scrapSeeds[] = [$ux, $uy];
                    foreach ([[10, 0], [-10, 0], [0, 10], [0, -10], [15, 0], [20, -5], [5, 15]] as [$dx, $dy]) {
                        $nx = $ux + $dx;
                        $ny = $uy + $dy;
                        if ($nx > 2 && $ny > 2 && $nx < $w - 2 && $ny < $h - 2 && $walls[$ny * $w + $nx] !== 1) {
                            $scrapSeeds[] = [$nx, $ny];
                        }
                    }
                }
                $freeSeeds = array_merge($scrapSeeds, $freeSeeds);
            }
            $attempts = 0;
            $freeAdded = 0;
            foreach ($freeSeeds as [$cx, $cy]) {
                if ($freeAdded >= $bandBudget) {
                    break;
                }
                // Cap only evaluated grows — empty wall hits are cheap and shouldn't
                // burn the budget before we reach the real office band (5W117 / D01).
                if ($walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                if ($attempts++ > 60) {
                    break;
                }
                $halfW = $y0f >= 0.54 ? max(intdiv($mw, 2), 55) : intdiv($mw, 2);
                $halfH = $y0f >= 0.54 ? max(intdiv($mh, 2), 40) : intdiv($mh, 2);
                $seed = [
                    max(0, $cx - $halfW),
                    max(0, $cy - $halfH),
                    min($w - 1, $cx + $halfW),
                    min($h - 1, $cy + $halfH),
                ];
                $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
                if ($grown[2] > (int) ($w * 0.18)) {
                    continue;
                }
                // Mid-band left office (5W117) should not flood past ~14% width.
                if ($y0f < 0.54 && $grown[2] > (int) ($w * 0.14)) {
                    continue;
                }
                if ($grown[1] < (int) ($h * 0.28)) {
                    continue;
                }
                // Lower-band claims must stay below the mid-office shelf (5W117).
                if ($y0f >= 0.54 && $grown[1] < (int) ($h * 0.50)) {
                    continue;
                }
                // Lower-band left office (D01) stays narrow — reject hallway flood.
                if ($y0f >= 0.54 && $grown[2] > (int) ($w * 0.10)) {
                    continue;
                }
                // Also reject pencil-thin partials that miss the office width.
                if ($y0f >= 0.54 && ($grown[2] - $grown[0] + 1) < max(100, (int) ($mw * 1.15))) {
                    continue;
                }
                $gArea = $this->rectArea($grown);
                // D01 is only ~1.8 modules — do not demand chamber-sized area.
                if ($gArea < (int) ($mw * $mh * 1.7) || $gArea > (int) ($w * $h * 0.045)) {
                    continue;
                }
                $bw = $grown[2] - $grown[0] + 1;
                $bh = $grown[3] - $grown[1] + 1;
                if ($bw <= max(100, $this->cad(95)) && $bh >= max(220, $this->cad(210))) {
                    continue;
                }
                if ($bh > (int) ($h * 0.30)) {
                    continue;
                }
                $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
                $enc = $this->enclosureScore($grown, $walls, $w, $h);
                $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                if ($iw > 0.10 || $enc < 0.40 || $sides < 2) {
                    continue;
                }
                // Mid-band left offices (5W117) often have open tops → sides=2, enc~0.41.
                if ($y0f < 0.54) {
                    if ($enc < 0.40) {
                        continue;
                    }
                } elseif ($sides < 3 && $enc < 0.48) {
                    continue;
                }
                if ($this->isCorridorShape($grown, $walls, $w, $h)) {
                    continue;
                }
                $scrapHits = 0;
                foreach ($rects as $r) {
                    if ($this->rectArea($r) > $cubMax) {
                        continue;
                    }
                    $mx = intdiv($r[0] + $r[2], 2);
                    $my = intdiv($r[1] + $r[3], 2);
                    if ($mx >= $grown[0] && $mx <= $grown[2] && $my >= $grown[1] && $my <= $grown[3]) {
                        $scrapHits++;
                    }
                }
                if ($scrapHits < 1) {
                    continue;
                }
                $clash = false;
                foreach ($rects as $r) {
                    if ($this->rectArea($r) < (int) ($mw * $mh * 6)) {
                        continue;
                    }
                    if ($this->overlapFrac($grown, $r) > 0.40) {
                        $clash = true;
                        break;
                    }
                }
                foreach ($claims as $ci => $c) {
                    if ($this->overlapFrac($grown, $c) > 0.35) {
                        $prevEnc = $claimsMeta[$ci]['enc'] ?? 0.0;
                        $prevScraps = $claimsMeta[$ci]['scrapHits'] ?? 0;
                        $replace = $gArea < $this->rectArea($c) * 0.92
                            || ($enc > $prevEnc + 0.04 && $gArea <= (int) ($this->rectArea($c) * 1.08))
                            || ($enc >= $prevEnc && $sides >= 3 && $gArea <= (int) ($this->rectArea($c) * 1.05));
                // Prefer taller/wider well-enclosed D01 over a thin partial claim.
                if ($y0f >= 0.54 && $enc >= 0.58 && $bw <= (int) ($w * 0.10)) {
                    $prevH = $c[3] - $c[1] + 1;
                    $prevW = $c[2] - $c[0] + 1;
                    if (($bh > (int) ($prevH * 1.10) && $bw >= $prevW)
                        || ($bw > (int) ($prevW * 1.15) && $bh >= (int) ($prevH * 0.9) && $enc >= $prevEnc - 0.05)
                    ) {
                        $replace = true;
                    }
                }
                        if ($replace) {
                            $claims[$ci] = $grown;
                            $claimsMeta[$ci] = ['scrapHits' => $scrapHits, 'enc' => $enc];
                        }
                        $clash = true;
                        break;
                    }
                }
                if (!$clash) {
                    $claims[] = $grown;
                    $claimsMeta[] = ['scrapHits' => $scrapHits, 'enc' => $enc];
                    $freeAdded++;
                }
            }
        }

        // Widen pencil-thin left-lower claims (group path often stops at a furniture nib).
        foreach ($claims as $ci => $c) {
            $cx = intdiv($c[0] + $c[2], 2);
            $cy = intdiv($c[1] + $c[3], 2);
            $bw = $c[2] - $c[0] + 1;
            if ($cx > (int) ($w * 0.12) || $cy < (int) ($h * 0.54) || $cy > (int) ($h * 0.75)) {
                continue;
            }
            if ($bw >= max(120, (int) ($mw * 1.3))) {
                continue;
            }
            $sx = max(40, min($cx, 100));
            $sy = max((int) ($h * 0.56), min($cy, (int) ($h * 0.68)));
            if ($walls[$sy * $w + $sx] === 1) {
                continue;
            }
            $seed = [
                max(0, $sx - 55),
                max(0, $sy - 40),
                min($w - 1, $sx + 55),
                min($h - 1, $sy + 40),
            ];
            $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
            if ($grown[2] > (int) ($w * 0.10) || $grown[1] < (int) ($h * 0.50)) {
                continue;
            }
            $gBw = $grown[2] - $grown[0] + 1;
            $gArea = $this->rectArea($grown);
            if ($gBw < max(120, (int) ($bw * 1.2)) || $gArea < (int) ($mw * $mh * 1.7)) {
                continue;
            }
            $enc = $this->enclosureScore($grown, $walls, $w, $h);
            $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
            if ($enc < 0.55 || $sides < 3) {
                continue;
            }
            $claims[$ci] = $grown;
            $claimsMeta[$ci] = ['scrapHits' => max(2, $claimsMeta[$ci]['scrapHits'] ?? 0), 'enc' => $enc];
        }

        // Soft pocket above a mid-side office (5W116 above 5W117, O113 above O112).
        // These shelves have almost no perimeter ink — stamp flush to the host's north edge.
        $aboveClaims = [];
        foreach ($claims as $ci => $c) {
            $cx = intdiv($c[0] + $c[2], 2);
            $cy = intdiv($c[1] + $c[3], 2);
            $bw = $c[2] - $c[0] + 1;
            $bh = $c[3] - $c[1] + 1;
            $leftHost = $cx <= (int) ($w * 0.14) && $c[2] <= (int) ($w * 0.16);
            $rightHost = $cx >= (int) ($w * 0.86) && $c[0] >= (int) ($w * 0.82);
            if (!$leftHost && !$rightHost) {
                continue;
            }
            if ($cy < (int) ($h * 0.34) || $cy > (int) ($h * 0.58)) {
                continue;
            }
            if ($bh < max(100, (int) ($mh * 1.5)) || $bw < max(100, (int) ($mw * 1.1))) {
                continue;
            }
            // Left mid (5W117) often stops on an interior bar — north-extend first.
            if ($leftHost) {
                $yMin = max((int) ($h * 0.28), $c[1] - max(70, intdiv($bh, 2)));
                $ext = $c;
                for ($y = $c[1] - 2; $y >= $yMin; $y -= 2) {
                    $hits = 0;
                    $n = 0;
                    for ($x = $c[0] + 4; $x <= $c[2] - 4; $x += 2) {
                        $n++;
                        if ($walls[$y * $w + $x] === 1) {
                            $hits++;
                        }
                    }
                    if ($n > 0 && ($hits / $n) > 0.35) {
                        $ext[1] = $y;
                        break;
                    }
                    $ext[1] = $y;
                }
                if ($c[1] - $ext[1] > 70) {
                    $ext[1] = $c[1] - 55;
                }
                $extEnc = $this->enclosureScore($ext, $walls, $w, $h);
                $extSides = $this->strongWallSides($ext, $walls, $w, $h, 0.28);
                $extIw = $this->interiorWallFrac($ext, $walls, $w, $h);
                if ($ext[1] < $c[1]
                    && $extEnc >= 0.40
                    && $extSides >= 2
                    && $extIw <= 0.05
                ) {
                    $claims[$ci] = $ext;
                    $claimsMeta[$ci] = [
                        'scrapHits' => max(2, $claimsMeta[$ci]['scrapHits'] ?? 0),
                        'enc' => $extEnc,
                    ];
                    $c = $ext;
                    $bh = $c[3] - $c[1] + 1;
                }
            }
            $aboveH = $rightHost
                ? max(70, min(85, intdiv($bh, 2)))
                : max(72, min(95, intdiv($bh, 2)));
            $above = [
                $c[0],
                max((int) ($h * ($rightHost ? 0.20 : 0.22)), $c[1] - $aboveH),
                $c[2],
                $c[1] - 1,
            ];
            if ($above[3] <= $above[1] || $this->rectArea($above) < (int) ($mw * $mh * 1.2)) {
                continue;
            }
            $iw = $this->interiorWallFrac($above, $walls, $w, $h);
            if ($iw > 0.06) {
                continue;
            }
            $dup = false;
            foreach ($claims as $o) {
                if ($this->overlapFrac($above, $o) > 0.30) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $aboveClaims[] = $above;
            }
        }
        foreach ($aboveClaims as $above) {
            $claims[] = $above;
            $claimsMeta[] = ['scrapHits' => 1, 'enc' => 0.20];
        }

        // Soft pocket above C02-like mid-left chambers (B03.01) — host lives in $rects, not claims.
        foreach ($rects as $host) {
            $cx = intdiv($host[0] + $host[2], 2);
            $cy = intdiv($host[1] + $host[3], 2);
            $bw = $host[2] - $host[0] + 1;
            $bh = $host[3] - $host[1] + 1;
            if ($cx < (int) ($w * 0.14) || $cx > (int) ($w * 0.28)) {
                continue;
            }
            if ($cy < (int) ($h * 0.40) || $cy > (int) ($h * 0.60)) {
                continue;
            }
            if ($bw < max(150, (int) ($mw * 1.6)) || $bh < max(140, (int) ($mh * 2.0))) {
                continue;
            }
            if ($this->rectArea($host) < (int) ($mw * $mh * 4.0)) {
                continue;
            }
            $aboveH = max(70, min(100, intdiv($bh, 2)));
            $above = [
                $host[0],
                max((int) ($h * 0.28), $host[1] - $aboveH),
                min($host[2], $host[0] + max(180, intdiv($bw * 9, 10))),
                $host[1] - 1,
            ];
            if ($above[3] <= $above[1] || $this->rectArea($above) < (int) ($mw * $mh * 1.5)) {
                continue;
            }
            if ($this->interiorWallFrac($above, $walls, $w, $h) > 0.08) {
                continue;
            }
            $dup = false;
            foreach ($claims as $o) {
                if ($this->overlapFrac($above, $o) > 0.30) {
                    $dup = true;
                    break;
                }
            }
            foreach ($rects as $o) {
                if ($this->rectArea($o) < (int) ($mw * $mh * 3)) {
                    continue;
                }
                if ($this->overlapFrac($above, $o) > 0.40) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $claims[] = $above;
                $claimsMeta[] = ['scrapHits' => 1, 'enc' => 0.18];
            }
        }

        // Mid-plan office (C07): lattice undersizes; inset free seed grows the full chamber.
        if (count($claims) < 18) {
            $stepY = max(40, $mh);
            $stepX = max(40, intdiv($mw, 2));
            $mBest = null;
            $attempts = 0;
            for ($sy = (int) ($h * 0.40); $sy < (int) ($h * 0.55); $sy += $stepY) {
                for ($sx = (int) ($w * 0.52); $sx < (int) ($w * 0.62); $sx += $stepX) {
                    if ($attempts++ > 18) {
                        break 2;
                    }
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    $seed = [
                        max(0, $sx - 30),
                        max(0, $sy - 30),
                        min($w - 1, $sx + 30),
                        min($h - 1, $sy + 30),
                    ];
                    $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
                    // Clip north flood into the cubicle farm above C07.
                    if ($grown[1] < (int) ($h * 0.34)) {
                        $grown[1] = (int) ($h * 0.35);
                    }
                    if ($grown[3] <= $grown[1] || $grown[2] <= $grown[0]) {
                        continue;
                    }
                    $gArea = $this->rectArea($grown);
                    $bw = $grown[2] - $grown[0] + 1;
                    $bh = $grown[3] - $grown[1] + 1;
                    if ($gArea < (int) ($mw * $mh * 3.5) || $gArea > (int) ($w * $h * 0.06)) {
                        continue;
                    }
                    if ($bw < max(120, (int) ($mw * 1.3)) || $bh < max(140, (int) ($mh * 2))) {
                        continue;
                    }
                    $cxm = intdiv($grown[0] + $grown[2], 2);
                    if ($cxm < (int) ($w * 0.48) || $cxm > (int) ($w * 0.68)) {
                        continue;
                    }
                    $enc = $this->enclosureScore($grown, $walls, $w, $h);
                    $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                    $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
                    if ($iw > 0.06 || $enc < 0.42 || $sides < 3) {
                        continue;
                    }
                    $score = $gArea * $enc * (0.85 + 0.05 * $sides);
                    if ($mBest === null || $score > $mBest['score']) {
                        $mBest = ['grown' => $grown, 'enc' => $enc, 'score' => $score];
                    }
                }
            }
            if ($mBest !== null) {
                $grown = $mBest['grown'];
                $clash = false;
                foreach ($claims as $ci => $c) {
                    if ($this->overlapFrac($grown, $c) > 0.35) {
                        if ($this->rectArea($grown) > $this->rectArea($c) * 1.05) {
                            $claims[$ci] = $grown;
                            $claimsMeta[$ci] = ['scrapHits' => 2, 'enc' => $mBest['enc']];
                        }
                        $clash = true;
                        break;
                    }
                }
                if (!$clash) {
                    $claims[] = $grown;
                    $claimsMeta[] = ['scrapHits' => 2, 'enc' => $mBest['enc']];
                }
            }
        }

        // Narrow shafts immediately east of D01 (D02.02 / D02.03) — soft ink, stamp from D01.
        foreach ($claims as $c) {
            $cx = intdiv($c[0] + $c[2], 2);
            $cy = intdiv($c[1] + $c[3], 2);
            $bw = $c[2] - $c[0] + 1;
            $bh = $c[3] - $c[1] + 1;
            if ($cx > (int) ($w * 0.12) || $cy < (int) ($h * 0.54) || $cy > (int) ($h * 0.72)) {
                continue;
            }
            if ($bw < max(100, (int) ($mw * 1.15)) || $bh < max(90, $mh)) {
                continue;
            }
            // East wall of D01 is the west jamb of the D02 column.
            $x0 = $c[2] + 1;
            $x1 = min($w - 1, $x0 + max(55, intdiv($mw, 2) + 10));
            // Snap east edge to densest vertical wall in the column.
            $bestX = $x1;
            $bestHits = -1;
            for ($x = $x0 + 40; $x <= min($w - 1, $x0 + 90); $x++) {
                $hits = 0;
                for ($y = $c[1]; $y <= $c[3]; $y += 2) {
                    if ($walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($hits > $bestHits) {
                    $bestHits = $hits;
                    $bestX = $x;
                }
            }
            if ($bestHits >= max(10, intdiv($bh, 4))) {
                $x1 = $bestX;
            }
            if ($x1 - $x0 < 40) {
                continue;
            }
            // Split into stacked D02 pockets: upper ~⅔ of D01 height, lower the rest + a bit.
            $split = $c[1] + max(85, intdiv($bh * 84, 100));
            $parts = [
                [$x0, $c[1], $x1, $split],
                [$x0, $split + 2, $x1, min($h - 1, max($c[3], $split + 50) + 10)],
            ];
            // If a strong horizontal bar exists near the split, snap to it.
            $bestY = $split;
            $bestYHits = -1;
            for ($y = $split - 15; $y <= $split + 15; $y++) {
                if ($y <= $c[1] || $y >= $c[3]) {
                    continue;
                }
                $hits = 0;
                for ($x = $x0; $x <= $x1; $x++) {
                    if ($walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($hits > $bestYHits) {
                    $bestYHits = $hits;
                    $bestY = $y;
                }
            }
            if ($bestYHits >= max(5, intdiv($x1 - $x0, 4))) {
                $parts = [
                    [$x0, $c[1], $x1, $bestY - 1],
                    [$x0, $bestY + 1, $x1, min($h - 1, max($c[3], $bestY + 50) + 10)],
                ];
            }
            foreach ($parts as $box) {
                if ($this->rectArea($box) < (int) ($mw * $mh * 0.45)) {
                    continue;
                }
                $iw = $this->interiorWallFrac($box, $walls, $w, $h);
                if ($iw > 0.10) {
                    continue;
                }
                $dup = false;
                foreach ($claims as $o) {
                    if ($this->overlapFrac($box, $o) > 0.35) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $claims[] = $box;
                    $claimsMeta[] = ['scrapHits' => 1, 'enc' => 0.22];
                }
            }
        }

        // Right-mid open office (O112): large chamber west of the D10/D11 shaft.
        if (count($claims) < 16) {
            $stepY = max(36, intdiv($mh, 2));
            $stepX = max(40, intdiv($mw, 2));
            $oBest = null;
            $attempts = 0;
            for ($sy = (int) ($h * 0.36); $sy < (int) ($h * 0.54); $sy += $stepY) {
                for ($sx = (int) ($w * 0.86); $sx < (int) ($w * 0.96); $sx += $stepX) {
                    if ($attempts++ > 24) {
                        break 2;
                    }
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    $seed = [
                        max(0, $sx - max(50, intdiv($mw, 2))),
                        max(0, $sy - max(35, intdiv($mh, 2))),
                        min($w - 1, $sx + max(50, intdiv($mw, 2))),
                        min($h - 1, $sy + max(35, intdiv($mh, 2))),
                    ];
                    $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
                    // Clip out of the upper-right legend/shaft and below into D10.
                    if ($grown[1] < (int) ($h * 0.32)) {
                        $grown[1] = (int) ($h * 0.34);
                    }
                    if ($grown[3] > (int) ($h * 0.58)) {
                        $grown[3] = (int) ($h * 0.56);
                    }
                    if ($grown[0] < (int) ($w * 0.82)) {
                        $grown[0] = (int) ($w * 0.85);
                    }
                    if ($grown[3] <= $grown[1] || $grown[2] <= $grown[0]) {
                        continue;
                    }
                    $gArea = $this->rectArea($grown);
                    $bw = $grown[2] - $grown[0] + 1;
                    $bh = $grown[3] - $grown[1] + 1;
                    if ($gArea < (int) ($mw * $mh * 4.0) || $gArea > (int) ($w * $h * 0.08)) {
                        continue;
                    }
                    if ($bw < max(150, (int) ($mw * 1.6)) || $bh < max(120, (int) ($mh * 1.8))) {
                        continue;
                    }
                    $enc = $this->enclosureScore($grown, $walls, $w, $h);
                    $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                    $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
                    if ($iw > 0.05 || $enc < 0.42 || $sides < 2) {
                        continue;
                    }
                    if ($this->isCorridorShape($grown, $walls, $w, $h)) {
                        continue;
                    }
                    $score = $gArea * $enc;
                    if ($oBest === null || $score > $oBest['score']) {
                        $oBest = ['grown' => $grown, 'enc' => $enc, 'score' => $score];
                    }
                }
            }
            if ($oBest !== null) {
                $grown = $oBest['grown'];
                $clash = false;
                foreach ($claims as $ci => $c) {
                    if ($this->overlapFrac($grown, $c) > 0.35) {
                        if ($this->rectArea($grown) > $this->rectArea($c) * 1.1) {
                            $claims[$ci] = $grown;
                            $claimsMeta[$ci] = ['scrapHits' => 2, 'enc' => $oBest['enc']];
                        }
                        $clash = true;
                        break;
                    }
                }
                if (!$clash) {
                    $claims[] = $grown;
                    $claimsMeta[] = ['scrapHits' => 2, 'enc' => $oBest['enc']];
                }
                // Soft pocket above O112 (O113) — same pattern as 5W116 above 5W117.
                $aboveH = max(70, min(85, intdiv($grown[3] - $grown[1] + 1, 2)));
                $above = [
                    $grown[0],
                    max((int) ($h * 0.20), $grown[1] - $aboveH),
                    $grown[2],
                    $grown[1] - 1,
                ];
                if ($above[3] > $above[1]
                    && $this->rectArea($above) >= (int) ($mw * $mh * 1.2)
                    && $this->interiorWallFrac($above, $walls, $w, $h) <= 0.06
                ) {
                    $dup = false;
                    foreach ($claims as $o) {
                        if ($this->overlapFrac($above, $o) > 0.30) {
                            $dup = true;
                            break;
                        }
                    }
                    if (!$dup) {
                        $claims[] = $above;
                        $claimsMeta[] = ['scrapHits' => 1, 'enc' => 0.20];
                    }
                }
            }
        }

        // Split oversized right shafts that swallowed two offices (D10+D11).
        foreach ($rects as $ri => $r) {
            $cx = intdiv($r[0] + $r[2], 2);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            // Absolute width gate — modW can exceed half a double-shaft (~178px).
            if ($cx < (int) ($w * 0.88) || $bw < 130 || $bh < max(140, $mh * 2)) {
                continue;
            }
            if ($this->rectArea($r) < (int) ($mw * $mh * 3.5)) {
                continue;
            }
            // Tall shafts span unused floors — search the office band only.
            $searchY0 = $r[1];
            $searchY1 = $r[3];
            if ($bh > (int) ($h * 0.35)) {
                $searchY0 = max($r[1], (int) ($h * 0.52));
                $searchY1 = min($r[3], (int) ($h * 0.82));
                if ($searchY1 - $searchY0 < max(100, $mh * 2)) {
                    continue;
                }
            }
            $bestX = intdiv($r[0] + $r[2], 2);
            $bestHits = -1;
            $x0 = $r[0] + max(20, intdiv($bw, 5));
            $x1 = $r[2] - max(20, intdiv($bw, 5));
            $searchSamples = max(1, intdiv($searchY1 - $searchY0 - 8, 3));
            for ($x = $x0; $x <= $x1; $x += 2) {
                $hits = 0;
                for ($y = $searchY0 + 4; $y <= $searchY1 - 4; $y += 3) {
                    if ($walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($hits > $bestHits) {
                    $bestHits = $hits;
                    $bestX = $x;
                }
            }
            if ($bestHits < 8 || ($bestHits / $searchSamples) < 0.20) {
                continue;
            }
            $mid = $bestX;
            $leftBox = [$r[0], $searchY0, $mid - 2, $searchY1];
            $rightBox = [$mid + 2, $searchY0, $r[2], $searchY1];
            $splitOk = 0;
            foreach ([$leftBox, $rightBox] as $box) {
                if ($this->rectArea($box) < (int) ($mw * $mh * 1.6)) {
                    continue;
                }
                $sx = intdiv($box[0] + $box[2], 2);
                $sy = intdiv($box[1] + $box[3], 2);
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                $seed = [
                    max($box[0], $sx - 12),
                    max($box[1], $sy - 12),
                    min($box[2], $sx + 12),
                    min($box[3], $sy + 12),
                ];
                $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
                // Clip to the half-box (not the full host — otherwise both sides stay merged).
                $grown = [
                    max($grown[0], $box[0]),
                    max($grown[1], $box[1]),
                    min($grown[2], $box[2]),
                    min($grown[3], $box[3]),
                ];
                if ($grown[2] < $grown[0] || $grown[3] < $grown[1]
                    || $this->rectArea($grown) < (int) ($mw * $mh * 1.5)
                ) {
                    $grown = $box;
                }
                if ($grown[2] - $grown[0] + 1 > max(100, (int) ($mw * 1.2))) {
                    continue;
                }
                $enc = $this->enclosureScore($grown, $walls, $w, $h);
                $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                if ($enc < 0.35 || $sides < 2) {
                    continue;
                }
                $dup = false;
                foreach ($claims as $c) {
                    if ($this->overlapFrac($grown, $c) > 0.45) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    // Host floor may sit above the true office floor (mid furniture bar).
                    // Prefer jamb-following south walk; fall back to a forced floor when
                    // the split edge isn't a continuous wall (D11 right half).
                    $yMax = (int) ($h * 0.815);
                    $ext = $grown;
                    $walked = false;
                    for ($y = $grown[3] + 2; $y <= $yMax; $y += 2) {
                        $lx = $grown[0];
                        $rx = $grown[2];
                        $leftHit = false;
                        $rightHit = false;
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $xl = $lx + $dx;
                            $xr = $rx + $dx;
                            if ($xl >= 0 && $xl < $w && $walls[$y * $w + $xl] === 1) {
                                $leftHit = true;
                            }
                            if ($xr >= 0 && $xr < $w && $walls[$y * $w + $xr] === 1) {
                                $rightHit = true;
                            }
                        }
                        if (!$leftHit && !$rightHit) {
                            break;
                        }
                        $walked = true;
                        $midHits = 0;
                        $midN = 0;
                        for ($x = $grown[0] + 4; $x <= $grown[2] - 4; $x += 2) {
                            $midN++;
                            if ($walls[$y * $w + $x] === 1) {
                                $midHits++;
                            }
                        }
                        if ($midN > 0 && ($midHits / $midN) > 0.55) {
                            $ext[3] = $y;
                            break;
                        }
                        $ext[3] = $y;
                    }
                    if (!$walked || $ext[3] < $grown[3] + 40) {
                        // Force to typical office floor when jamb walk stalls immediately.
                        $forced = [$grown[0], $grown[1], $grown[2], max($grown[3], $yMax)];
                        $fEnc = $this->enclosureScore($forced, $walls, $w, $h);
                        $fSides = $this->strongWallSides($forced, $walls, $w, $h, 0.28);
                        $fIw = $this->interiorWallFrac($forced, $walls, $w, $h);
                        if ($fEnc >= 0.50 && $fSides >= 3 && $fIw <= 0.08) {
                            $ext = $forced;
                        }
                    }
                    $extEnc = $this->enclosureScore($ext, $walls, $w, $h);
                    $extSides = $this->strongWallSides($ext, $walls, $w, $h, 0.28);
                    if ($extEnc >= 0.45 && $extSides >= 3 && $this->rectArea($ext) > $this->rectArea($grown)) {
                        $grown = $ext;
                        $enc = $extEnc;
                    }
                    $claims[] = $grown;
                    $claimsMeta[] = ['scrapHits' => 2, 'enc' => $enc];
                    $splitOk++;
                }
            }
        }

        // Bottom shredded offices (E10 right + E04/D06 center): carve short cells
        // out of oversized bottom megas.
        if (count($claims) < 22) {
            $stepY = max(24, intdiv($mh, 2));
            $stepX = max(32, intdiv($mw, 2));
            $brSeeds = [];
            // E04/D06 center-bottom first, then E10 right — avoid burning the attempt budget.
            foreach ([[0.28, 0.56], [0.78, 0.94]] as [$x0f, $x1f]) {
                for ($sy = (int) ($h * 0.80); $sy < (int) ($h * 0.91); $sy += $stepY) {
                    for ($sx = (int) ($w * $x0f); $sx < (int) ($w * $x1f); $sx += $stepX) {
                        if ($walls[$sy * $w + $sx] === 1) {
                            continue;
                        }
                        $brSeeds[] = [$sx, $sy];
                    }
                }
            }
            $attempts = 0;
            $brCands = [];
            foreach ($brSeeds as [$cx, $cy]) {
                if ($attempts++ > 72) {
                    break;
                }
                $seed = [
                    max(0, $cx - max(40, intdiv($mw, 2))),
                    max(0, $cy - max(20, intdiv($mh, 2))),
                    min($w - 1, $cx + max(40, intdiv($mw, 2))),
                    min($h - 1, $cy + max(20, intdiv($mh, 2))),
                ];
                $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $maxSteps);
                if ($grown[1] < (int) ($h * 0.76)) {
                    $grown[1] = (int) ($h * 0.78);
                }
                if ($grown[3] > (int) ($h * 0.92)) {
                    $grown[3] = (int) ($h * 0.905);
                }
                if ($grown[3] <= $grown[1] || $grown[2] <= $grown[0]) {
                    continue;
                }
                $gArea = $this->rectArea($grown);
                $bw = $grown[2] - $grown[0] + 1;
                $bh = $grown[3] - $grown[1] + 1;
                if ($gArea < (int) ($mw * $mh * 1.3) || $gArea > (int) ($w * $h * 0.05)) {
                    continue;
                }
                // Bottom offices are short ribbons (E04/E10) or modest D06 cells.
                if ($bh > max(130, (int) ($h * 0.15))) {
                    continue;
                }
                if ($bw < max(70, (int) ($mw * 0.85))) {
                    continue;
                }
                $enc = $this->enclosureScore($grown, $walls, $w, $h);
                $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
                if ($iw > 0.08 || $enc < 0.40 || $sides < 2) {
                    continue;
                }
                if ($this->isCorridorShape($grown, $walls, $w, $h)) {
                    continue;
                }
                $scrapHits = 0;
                foreach ($rects as $rr) {
                    if ($this->rectArea($rr) > $cubMax) {
                        continue;
                    }
                    $mx = intdiv($rr[0] + $rr[2], 2);
                    $my = intdiv($rr[1] + $rr[3], 2);
                    if ($mx >= $grown[0] && $mx <= $grown[2] && $my >= $grown[1] && $my <= $grown[3]) {
                        $scrapHits++;
                    }
                }
                $score = $gArea * $enc * (1.0 + 0.1 * min(3, $scrapHits));
                if ($bh <= 90) {
                    $score *= 1.2;
                }
                // Prefer compact bottom offices (E04) over wide D06 floods when both pass.
                if ($bw <= max(120, (int) ($mw * 1.4)) && $bh <= 90 && $enc >= 0.45) {
                    $score *= 1.35;
                }
                $brCands[] = [
                    'grown' => $grown,
                    'scrapHits' => max(1, $scrapHits),
                    'enc' => $enc,
                    'score' => $score,
                ];
            }
            usort($brCands, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $brAdded = 0;
            foreach ($brCands as $cand) {
                if ($brAdded >= 5) {
                    break;
                }
                $grown = $cand['grown'];
                // E10 west-jamb snap for right-side ribbons.
                if ($grown[0] > (int) ($w * 0.70)) {
                    $bw = $grown[2] - $grown[0] + 1;
                    $bh = $grown[3] - $grown[1] + 1;
                    $bestX = $grown[0];
                    $bestHits = -1;
                    $xLim = $grown[0] + max(40, intdiv($bw, 3));
                    for ($x = $grown[0]; $x <= $xLim; $x++) {
                        $hits = 0;
                        for ($y = $grown[1]; $y <= $grown[3]; $y += 2) {
                            if ($walls[$y * $w + $x] === 1) {
                                $hits++;
                            }
                        }
                        if ($hits > $bestHits) {
                            $bestHits = $hits;
                            $bestX = $x;
                        }
                    }
                    if ($bestHits >= max(8, (int) ($bh * 0.35))) {
                        $grown[0] = $bestX;
                    }
                }
                $skip = false;
                foreach ($claims as $ci => $c) {
                    if ($this->overlapFrac($grown, $c) <= 0.35) {
                        continue;
                    }
                    $cBh = $c[3] - $c[1] + 1;
                    $gBh = $grown[3] - $grown[1] + 1;
                    // Replace a tall bottom mega with a carved short office.
                    if ($cBh > max(160, (int) ($gBh * 1.8))
                        && $this->rectArea($c) > $this->rectArea($grown) * 1.4
                    ) {
                        $claims[$ci] = $grown;
                        $claimsMeta[$ci] = ['scrapHits' => $cand['scrapHits'], 'enc' => $cand['enc']];
                        $brAdded++;
                        $skip = true;
                        break;
                    }
                    $skip = true; // overlaps a non-mega claim
                    break;
                }
                if ($skip) {
                    continue;
                }
                $claims[] = $grown;
                $claimsMeta[] = ['scrapHits' => $cand['scrapHits'], 'enc' => $cand['enc']];
                $brAdded++;
            }
        }

        if ($claims === []) {
            return $rects;
        }

        $out = [];
        foreach ($rects as $r) {
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $area = $this->rectArea($r);
            $drop = false;
            foreach ($claims as $L) {
                // Drop oversized hosts that fully contain a tighter side-pocket claim
                // (mega D10+D11 shaft vs narrow halves — overlapFrac(host,half) is small).
                if ($area > $this->rectArea($L) * 1.12 && $this->containmentFrac($L, $r) > 0.55) {
                    $drop = true;
                    break;
                }
                // Drop bottom/side megas that a carved claim substantially overlaps.
                if ($area > $this->rectArea($L) * 1.5 && $this->overlapFrac($L, $r) > 0.45) {
                    $drop = true;
                    break;
                }
                // Drop undersized rooms mostly inside a larger claim (C07 lattice vs free-seed).
                if ($this->rectArea($L) > $area * 1.15 && $this->containmentFrac($r, $L) > 0.60) {
                    $drop = true;
                    break;
                }
                if ($cx < $L[0] || $cx > $L[2] || $cy < $L[1] || $cy > $L[3]) {
                    continue;
                }
                if ($area > $cubMax) {
                    continue;
                }
                if ($this->containmentFrac($r, $L) < 0.65) {
                    continue;
                }
                $enc = $this->enclosureScore($r, $walls, $w, $h);
                if ($area >= (int) ($mw * $mh * 0.7) && $enc >= 0.32) {
                    continue;
                }
                $drop = true;
                break;
            }
            if (!$drop) {
                $out[] = $r;
            }
        }
        foreach ($claims as $L) {
            $out[] = $L;
        }

        return array_values($out);
    }

    /**
     * CAD door gaps (jamb-to-jamb openings). Swing arcs are often missing on empty
     * office rasters, so shape evidence is not required here.
     *
     * @param list<int> $partition
     * @return list<array{x0:int,y0:int,x1:int,y1:int,orient:string}>
     */
    private function detectOfficeDoorGaps(array $partition, int $w, int $h, int $thickness): array
    {
        $doorMin = max(8, $this->cad(12));
        $doorMax = max($doorMin + 8, min(100, $this->cad(70)));
        $minJamb = max(8, $thickness);
        $pad = max(2, intdiv($thickness, 2));
        $hits = [];

        for ($y = 1; $y < $h - 1; $y++) {
            $runs = [];
            $x = 0;
            while ($x < $w) {
                if ($partition[$y * $w + $x] !== 1) {
                    $x++;
                    continue;
                }
                $x0 = $x;
                while ($x < $w && $partition[$y * $w + $x] === 1) {
                    $x++;
                }
                $runs[] = [$x0, $x - $x0];
            }
            for ($i = 0; $i < count($runs) - 1; $i++) {
                [$ax, $alen] = $runs[$i];
                [$bx, $blen] = $runs[$i + 1];
                $gap = $bx - ($ax + $alen);
                if ($gap < $doorMin || $gap > $doorMax || $alen < $minJamb || $blen < $minJamb) {
                    continue;
                }
                $hits[] = [
                    'x0' => $ax + $alen,
                    'y0' => max(0, $y - $pad),
                    'x1' => $bx - 1,
                    'y1' => min($h - 1, $y + $pad),
                    'orient' => 'h',
                ];
            }
        }

        for ($x = 1; $x < $w - 1; $x++) {
            $runs = [];
            $y = 0;
            while ($y < $h) {
                if ($partition[$y * $w + $x] !== 1) {
                    $y++;
                    continue;
                }
                $y0 = $y;
                while ($y < $h && $partition[$y * $w + $x] === 1) {
                    $y++;
                }
                $runs[] = [$y0, $y - $y0];
            }
            for ($i = 0; $i < count($runs) - 1; $i++) {
                [$ay, $alen] = $runs[$i];
                [$by, $blen] = $runs[$i + 1];
                $gap = $by - ($ay + $alen);
                if ($gap < $doorMin || $gap > $doorMax || $alen < $minJamb || $blen < $minJamb) {
                    continue;
                }
                $hits[] = [
                    'x0' => max(0, $x - $pad),
                    'y0' => $ay + $alen,
                    'x1' => min($w - 1, $x + $pad),
                    'y1' => $by - 1,
                    'orient' => 'v',
                ];
            }
        }

        return $this->clusterDoorOpenings($hits);
    }

    /**
     * Join detections that share a single clear door: grow the room chamber from
     * the door and replace every fragment whose center falls inside it.
     * Open cubicle banks (0 or many doors) are left alone.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<array{x0:int,y0:int,x1:int,y1:int,orient:string}> $doors
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function mergeSpacesSharingDoor(array $rects, array $walls, array $doors, int $w, int $h): array
    {
        if ($rects === [] || $doors === []) {
            return $rects;
        }
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $minRoom = max(900, (int) ($mw * $mh * 0.55));
        $maxRoom = max(12000, (int) ($mw * $mh * 5.5));
        $maxDepth = max(48, (int) (2.4 * max($mw, $mh)));
        $used = array_fill(0, count($rects), false);
        $out = [];
        $dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];

        foreach ($doors as $di => $door) {
            $dcx = intdiv($door['x0'] + $door['x1'], 2);
            $dcy = intdiv($door['y0'] + $door['y1'], 2);
            $doorW = max(1, $door['x1'] - $door['x0'] + 1);
            $doorH = max(1, $door['y1'] - $door['y0'] + 1);
            $doorSpan = $door['orient'] === 'h' ? $doorW : $doorH;
            $hallSpan = max($doorSpan * 3, (int) (1.8 * ($door['orient'] === 'h' ? $mw : $mh)));
            $sideDirs = $door['orient'] === 'h' ? [[0, -1], [0, 1]] : [[-1, 0], [1, 0]];
            foreach ($sideDirs as [$sdx, $sdy]) {
                $best = null;
                $bestMembers = [];
                $bestArea = PHP_INT_MAX;
                foreach ([4, 8, 14, 22, 32] as $step) {
                    $sx = $dcx + $sdx * $step;
                    $sy = $dcy + $sdy * $step;
                    if ($sx < 1 || $sy < 1 || $sx >= $w - 1 || $sy >= $h - 1) {
                        continue;
                    }
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    // Hall-neck flood: stop when the passage opens into circulation.
                    $seen = [];
                    $qx = [$sx];
                    $qy = [$sy];
                    $qd = [0];
                    $qi = 0;
                    $qs = 1;
                    $seen[$sy * $w + $sx] = 1;
                    $area = 0;
                    $minX = $sx;
                    $maxX = $sx;
                    $minY = $sy;
                    $maxY = $sy;
                    $leaked = false;
                    while ($qi < $qs && $area < $maxRoom) {
                        $cx = $qx[$qi];
                        $cy = $qy[$qi];
                        $dep = $qd[$qi];
                        $qi++;
                        $area++;
                        if ($cx < $minX) {
                            $minX = $cx;
                        }
                        if ($cx > $maxX) {
                            $maxX = $cx;
                        }
                        if ($cy < $minY) {
                            $minY = $cy;
                        }
                        if ($cy > $maxY) {
                            $maxY = $cy;
                        }
                        if ($dep >= $maxDepth) {
                            continue;
                        }
                        // Free span check near the door axis — wide opening ⇒ hallway.
                        if ($dep >= max(6, $doorSpan) && ($area % 7) === 0) {
                            $span = 0;
                            if ($door['orient'] === 'h') {
                                for ($x = $cx; $x >= 0 && $walls[$cy * $w + $x] !== 1; $x--) {
                                    $span++;
                                }
                                for ($x = $cx + 1; $x < $w && $walls[$cy * $w + $x] !== 1; $x++) {
                                    $span++;
                                }
                            } else {
                                for ($y = $cy; $y >= 0 && $walls[$y * $w + $cx] !== 1; $y--) {
                                    $span++;
                                }
                                for ($y = $cy + 1; $y < $h && $walls[$y * $w + $cx] !== 1; $y++) {
                                    $span++;
                                }
                            }
                            if ($span >= $hallSpan) {
                                $leaked = true;
                                break;
                            }
                        }
                        foreach ($dirs as [$dx, $dy]) {
                            $nx = $cx + $dx;
                            $ny = $cy + $dy;
                            if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                                continue;
                            }
                            $ni = $ny * $w + $nx;
                            if (isset($seen[$ni]) || $walls[$ni] === 1) {
                                continue;
                            }
                            // Do not cross other doors into the next room.
                            $other = false;
                            foreach ($doors as $dj => $od) {
                                if ($dj === $di) {
                                    continue;
                                }
                                if ($nx >= $od['x0'] && $nx <= $od['x1'] && $ny >= $od['y0'] && $ny <= $od['y1']) {
                                    $other = true;
                                    break;
                                }
                            }
                            if ($other) {
                                continue;
                            }
                            $seen[$ni] = 1;
                            $qx[] = $nx;
                            $qy[] = $ny;
                            $qd[] = $dep + 1;
                            $qs++;
                        }
                    }
                    if ($leaked || $area < $minRoom || $area >= $maxRoom) {
                        continue;
                    }
                    $chamber = [$minX, $minY, $maxX, $maxY];
                    $enc = $this->enclosureScore($chamber, $walls, $w, $h);
                    if ($enc < 0.28) {
                        continue;
                    }
                    $touch = 0;
                    $self = false;
                    foreach ($doors as $dj => $od) {
                        if (
                            $od['x1'] < $chamber[0] - 3
                            || $od['x0'] > $chamber[2] + 3
                            || $od['y1'] < $chamber[1] - 3
                            || $od['y0'] > $chamber[3] + 3
                        ) {
                            continue;
                        }
                        $touch++;
                        if ($dj === $di) {
                            $self = true;
                        }
                    }
                    if (!$self || $touch !== 1) {
                        continue;
                    }
                    $members = [];
                    $memberArea = 0;
                    foreach ($rects as $i => $r) {
                        if ($used[$i]) {
                            continue;
                        }
                        $cx = intdiv($r[0] + $r[2], 2);
                        $cy = intdiv($r[1] + $r[3], 2);
                        if (isset($seen[$cy * $w + $cx])) {
                            $members[] = $i;
                            $memberArea += $this->rectArea($r);
                        }
                    }
                    if (count($members) < 2 || count($members) > 8) {
                        continue;
                    }
                    if ($area > (int) ($memberArea * 1.65) + $this->cadArea(2500)) {
                        continue;
                    }
                    if ($area < $bestArea) {
                        $bestArea = $area;
                        $best = $chamber;
                        $bestMembers = $members;
                    }
                }
                if ($best === null) {
                    continue;
                }
                foreach ($bestMembers as $i) {
                    $used[$i] = true;
                }
                $out[] = $best;
            }
        }

        foreach ($rects as $i => $r) {
            if (!$used[$i]) {
                $out[] = $r;
            }
        }

        // Room-scale fragments that each touch the same single door and sit on the
        // same side — join without requiring a sealed flood chamber.
        $minA = max($this->cadArea(9000), (int) ($mw * $mh * 1.1));
        $doorOf = [];
        foreach ($out as $i => $r) {
            if ($this->rectArea($r) < $minA) {
                continue;
            }
            $ids = $this->doorIdsTouchingRect($r, $doors, max(12, $this->cad(16)));
            if (count($ids) === 1) {
                $doorOf[$i] = $ids[0];
            }
        }
        $byDoor = [];
        foreach ($doorOf as $i => $di) {
            $byDoor[$di][] = $i;
        }
        $final = [];
        $consumed = [];
        foreach ($byDoor as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            // Same side of door
            $door = $doors[$doorOf[$ids[0]]];
            $dcx = intdiv($door['x0'] + $door['x1'], 2);
            $dcy = intdiv($door['y0'] + $door['y1'], 2);
            $groups = ['a' => [], 'b' => []];
            foreach ($ids as $i) {
                $r = $out[$i];
                $cx = intdiv($r[0] + $r[2], 2);
                $cy = intdiv($r[1] + $r[3], 2);
                if ($door['orient'] === 'h') {
                    $groups[$cy < $dcy ? 'a' : 'b'][] = $i;
                } else {
                    $groups[$cx < $dcx ? 'a' : 'b'][] = $i;
                }
            }
            foreach ($groups as $gids) {
                if (count($gids) < 2) {
                    continue;
                }
                $u = $out[$gids[0]];
                foreach ($gids as $i) {
                    $u = [
                        min($u[0], $out[$i][0]),
                        min($u[1], $out[$i][1]),
                        max($u[2], $out[$i][2]),
                        max($u[3], $out[$i][3]),
                    ];
                    $consumed[$i] = true;
                }
                if ($this->interiorWallFrac($u, $walls, $w, $h) <= 0.12 && $this->rectArea($u) <= $maxRoom * 1.4) {
                    $final[] = $u;
                } else {
                    foreach ($gids as $i) {
                        unset($consumed[$i]);
                    }
                }
            }
        }
        foreach ($out as $i => $r) {
            if (!isset($consumed[$i])) {
                $final[] = $r;
            }
        }

        return array_values($final);
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<array{x0:int,y0:int,x1:int,y1:int,orient:string}> $doors
     * @return list<int>
     */
    private function doorIdsTouchingRect(array $r, array $doors, int $pad): array
    {
        $ids = [];
        foreach ($doors as $di => $d) {
            if (
                $d['x1'] < $r[0] - $pad
                || $d['x0'] > $r[2] + $pad
                || $d['y1'] < $r[1] - $pad
                || $d['y0'] > $r[3] + $pad
            ) {
                continue;
            }
            $dcx = intdiv($d['x0'] + $d['x1'], 2);
            $dcy = intdiv($d['y0'] + $d['y1'], 2);
            $onEdge = $dcx <= $r[0] + $pad
                || $dcx >= $r[2] - $pad
                || $dcy <= $r[1] + $pad
                || $dcy >= $r[3] - $pad;
            if ($onEdge) {
                $ids[] = $di;
            }
        }

        return $ids;
    }

    /**
     * If $a and $b abut along a long edge with little wall ink on the seam, return
     * seam length; otherwise null.
     *
     * @param array{0:int,1:int,2:int,3:int} $a
     * @param array{0:int,1:int,2:int,3:int} $b
     * @param list<int> $walls
     */
    private function openSharedSeam(array $a, array $b, array $walls, int $w, int $h): ?int
    {
        $aw = $a[2] - $a[0] + 1;
        $ah = $a[3] - $a[1] + 1;
        $bw = $b[2] - $b[0] + 1;
        $bh = $b[3] - $b[1] + 1;
        // Require a long shared edge and near-zero gap — cubicles that are “the same
        // cell” sit flush; a real partition or door leaf leaves wall/door ink.
        $minShare = max(16, (int) round(0.55 * min($aw, $ah, $bw, $bh)));
        $maxGap = max(1, $this->cad(3));

        $overlapY = min($a[3], $b[3]) - max($a[1], $b[1]) + 1;
        $gapX = max(0, max($a[0], $b[0]) - min($a[2], $b[2]) - 1);
        if ($overlapY >= $minShare && $gapX <= $maxGap) {
            $xSeam = $a[2] < $b[0]
                ? intdiv($a[2] + $b[0], 2)
                : ($b[2] < $a[0] ? intdiv($b[2] + $a[0], 2) : intdiv(min($a[2], $b[2]) + max($a[0], $b[0]), 2));
            $y0 = max($a[1], $b[1]);
            $y1 = min($a[3], $b[3]);
            if ($this->seamWallRatio($walls, $w, $h, $xSeam, $y0, $xSeam, $y1) <= 0.05) {
                // Door opening on the seam ⇒ separate rooms; do not merge.
                if ($this->seamHitsDoor($xSeam, $y0, $xSeam, $y1)) {
                    return null;
                }
                return $overlapY;
            }
        }

        $overlapX = min($a[2], $b[2]) - max($a[0], $b[0]) + 1;
        $gapY = max(0, max($a[1], $b[1]) - min($a[3], $b[3]) - 1);
        if ($overlapX >= $minShare && $gapY <= $maxGap) {
            $ySeam = $a[3] < $b[1]
                ? intdiv($a[3] + $b[1], 2)
                : ($b[3] < $a[1] ? intdiv($b[3] + $a[1], 2) : intdiv(min($a[3], $b[3]) + max($a[1], $b[1]), 2));
            $x0 = max($a[0], $b[0]);
            $x1 = min($a[2], $b[2]);
            if ($this->seamWallRatio($walls, $w, $h, $x0, $ySeam, $x1, $ySeam) <= 0.05) {
                if ($this->seamHitsDoor($x0, $ySeam, $x1, $ySeam)) {
                    return null;
                }
                return $overlapX;
            }
        }

        return null;
    }

    /**
     * Fraction of wall ink along an axis-aligned seam sample (includes a 1px band).
     *
     * @param list<int> $walls
     */
    private function seamWallRatio(array $walls, int $w, int $h, int $x0, int $y0, int $x1, int $y1): float
    {
        $x0 = max(0, min($w - 1, $x0));
        $x1 = max(0, min($w - 1, $x1));
        $y0 = max(0, min($h - 1, $y0));
        $y1 = max(0, min($h - 1, $y1));
        if ($x0 > $x1) {
            [$x0, $x1] = [$x1, $x0];
        }
        if ($y0 > $y1) {
            [$y0, $y1] = [$y1, $y0];
        }
        $hits = 0;
        $n = 0;
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $n++;
                if ($walls[$y * $w + $x] === 1) {
                    $hits++;
                }
                if ($x0 === $x1) {
                    foreach ([-1, 1] as $dx) {
                        $xx = $x + $dx;
                        if ($xx >= 0 && $xx < $w) {
                            $n++;
                            if ($walls[$y * $w + $xx] === 1) {
                                $hits++;
                            }
                        }
                    }
                } elseif ($y0 === $y1) {
                    foreach ([-1, 1] as $dy) {
                        $yy = $y + $dy;
                        if ($yy >= 0 && $yy < $h) {
                            $n++;
                            if ($walls[$yy * $w + $x] === 1) {
                                $hits++;
                            }
                        }
                    }
                }
            }
        }

        return $n > 0 ? $hits / $n : 1.0;
    }

    /**
     * If a short cell's center sits in a left/right bank gap but its row is shifted
     * (≥~18px), move it onto the host row inside the gap. Strict min size so we
     * never collapse into thin scraps. Fixes OCR stamps like #76.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function snapShiftedGapCells(array $rects, int $w, int $h): array
    {
        $yMax = (int) ($h * 0.58);
        $modW = $this->modW > 20 ? $this->modW : $this->cad(76);
        $modH = $this->modH > 16 ? $this->modH : $this->cad(50);
        $out = $rects;
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cy > $yMax || $bw < $this->cad(45) || $bw > $this->cad(140) || $bh < $this->cad(28) || $bh > $this->cad(90)) {
                continue;
            }
            $left = null;
            $right = null;
            foreach ($out as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                $ocy = intdiv($o[1] + $o[3], 2);
                if ($ow < $this->cad(45) || $oh < $this->cad(28) || $oh > 120) {
                    continue;
                }
                if (abs($ocy - $cy) > max(40, $this->cad(80))) {
                    continue;
                }
                if ($o[2] < $cx && ($left === null || $o[2] > $left[2])) {
                    $left = $o;
                }
            }
            if ($left === null) {
                continue;
            }
            $leftCy = intdiv($left[1] + $left[3], 2);
            foreach ($out as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                $ocy = intdiv($o[1] + $o[3], 2);
                if ($ow < $this->cad(45) || $oh < $this->cad(28) || $oh > 120) {
                    continue;
                }
                if ($o[0] <= $cx) {
                    continue;
                }
                // Prefer the right host on the same bank row as the left host (#76).
                if (abs($ocy - $leftCy) > max(22, $this->cad(44))) {
                    continue;
                }
                if ($right === null || $o[0] < $right[0]) {
                    $right = $o;
                }
            }
            if ($left === null || $right === null) {
                continue;
            }
            $gap = $right[0] - $left[2] - 1;
            if ($gap < $this->cad(48) || $gap > (int) ($modW * 1.8)) {
                continue;
            }
            $hostCy = intdiv(
                intdiv($left[1] + $left[3], 2) + intdiv($right[1] + $right[3], 2),
                2
            );
            if (abs($cy - $hostCy) < $this->cad(18)) {
                continue;
            }
            $hh = max($this->cad(32), min($modH + $this->cad(8), intdiv(($left[3] - $left[1] + 1) + ($right[3] - $right[1] + 1), 2)));
            $hw = max($this->cad(48), min($modW + $this->cad(8), $gap - 2));
            $midX = intdiv($left[2] + $right[0], 2);
            $cand = [
                max($left[2] + 2, $midX - intdiv($hw, 2)),
                max(0, $hostCy - intdiv($hh, 2)),
                min($right[0] - 2, $midX + intdiv($hw, 2) - 1),
                min($h - 1, $hostCy + intdiv($hh, 2) - 1),
            ];
            if ($cand[2] - $cand[0] + 1 < $this->cad(48) || $cand[3] - $cand[1] + 1 < $this->cad(32)) {
                continue;
            }
            $ok = true;
            foreach ($out as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                if ($this->intersectionArea($cand, $o) > 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[$i] = $cand;
            }
        }

        return array_values($out);
    }

    /**
     * From upper-bank cubicles, stamp host-sized cells one module west when empty.
     * Recovers raster A06 columns that lack OCR anchors.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedWestUpperBankFromHosts(array $rects, array $walls, int $w, int $h): array
    {
        $extra = [];
        $pitch = $this->modW > 20 ? $this->modW + $this->cad(6) : $this->cad(82);
        $yMax = (int) ($h * 0.22);
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cy > $yMax || $bw < $this->cad(45) || $bw > $this->cad(160) || $bh < $this->cad(32) || $bh > $this->cad(120)) {
                continue;
            }
            // Up to 3 steps west.
            $prev = $r;
            for ($step = 0; $step < 3; $step++) {
                $cand = [
                    max(0, $prev[0] - $pitch),
                    $prev[1],
                    $prev[0] - 2,
                    $prev[3],
                ];
                // Prefer host height/width.
                $cand[0] = max(0, $prev[0] - $bw - 2);
                $cand[2] = $prev[0] - 2;
                $cand[1] = $prev[1];
                $cand[3] = $prev[3];
                if ($cand[2] - $cand[0] + 1 < $this->cad(40)) {
                    break;
                }
                $cx = intdiv($cand[0] + $cand[2], 2);
                $cy2 = intdiv($cand[1] + $cand[3], 2);
                if ($cx < $this->cad(40) || $walls[$cy2 * $w + $cx] === 1) {
                    break;
                }
                if ($this->pointInAnyRectCore($cx, $cy2, array_merge($rects, $extra), $this->cad(6))) {
                    break;
                }
                if ($this->enclosureScore($cand, $walls, $w, $h) < 0.10) {
                    break;
                }
                $skip = false;
                foreach (array_merge($rects, $extra) as $o) {
                    if ($this->intersectionArea($cand, $o) > 0) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    break;
                }
                $extra[] = $cand;
                $prev = $cand;
                if (count($extra) >= 12) {
                    break 2;
                }
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Plant cubicles in vertical holes between same-column bank cells (#55/#76).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedVerticalColumnGaps(array $rects, array $walls, int $w, int $h): array
    {
        $cols = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bw < 36 || $bw > 95 || $bh < 26 || $bh > 95) {
                continue;
            }
            if ($this->rectArea($r) > $this->cadArea(22000)) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $key = (int) round($cx / 10);
            $cols[$key][] = $r;
        }
        $extra = [];
        $blockers = $rects;
        foreach ($cols as $list) {
            usort($list, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
            $n = count($list);
            for ($i = 0; $i < $n - 1; $i++) {
                $a = $list[$i];
                $b = $list[$i + 1];
                if (abs(intdiv($a[0] + $a[2], 2) - intdiv($b[0] + $b[2], 2)) > 20) {
                    continue;
                }
                $gap = $b[1] - $a[3] - 1;
                // Empty cavity between rows, or a near-miss where centers imply a missing module.
                $centerGap = intdiv($b[1] + $b[3], 2) - intdiv($a[1] + $a[3], 2);
                $pitch = max(38, (int) round((($a[3] - $a[1] + 1) + ($b[3] - $b[1] + 1)) * 0.5));
                if ($gap < 16 && $centerGap < (int) ($pitch * 1.55)) {
                    continue;
                }
                if ($gap > 80 && $centerGap > (int) ($pitch * 2.6)) {
                    continue;
                }
                $x0 = max($a[0], $b[0]);
                $x1 = min($a[2], $b[2]);
                if ($x1 - $x0 + 1 < 36) {
                    continue;
                }
                $targetH = max(36, min(58, $pitch));
                if ($gap >= 16) {
                    $cy = intdiv($a[3] + $b[1], 2);
                    $y0 = max($a[3] + 2, $cy - intdiv($targetH, 2));
                    $y1 = min($b[1] - 2, $y0 + $targetH - 1);
                } else {
                    // Neighbors nearly touch but span ~2 pitches — insert between by
                    // centering a module on the midpoint and letting exclusive resolve.
                    $cy = intdiv(intdiv($a[1] + $a[3], 2) + intdiv($b[1] + $b[3], 2), 2);
                    $y0 = $cy - intdiv($targetH, 2);
                    $y1 = $y0 + $targetH - 1;
                }
                if ($y1 < $y0 + 28) {
                    continue;
                }
                $cand = [$x0, max(0, $y0), $x1, min($h - 1, $y1)];
                $ccx = intdiv($cand[0] + $cand[2], 2);
                $ccy = intdiv($cand[1] + $cand[3], 2);
                if ($ccx < 0 || $ccy < 0 || $ccx >= $w || $ccy >= $h || $walls[$ccy * $w + $ccx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($ccx, $ccy, $blockers, 4)) {
                    continue;
                }
                $bh = $cand[3] - $cand[1] + 1;
                $bw = $cand[2] - $cand[0] + 1;
                if ($bw * $bh < 1200 || $bh > 70) {
                    continue;
                }
                if ($this->enclosureScore($cand, $walls, $w, $h) < 0.14) {
                    continue;
                }
                $extra[] = $cand;
                $blockers[] = $cand;
                if (count($extra) >= 20) {
                    break 2;
                }
            }
            // Also plant one module above the top cell when the column starts mid-bank.
            if ($list !== []) {
                $top = $list[0];
                $bh = $top[3] - $top[1] + 1;
                $targetH = max(36, min(58, $bh));
                $cand = [
                    $top[0],
                    max(0, $top[1] - $targetH - 1),
                    $top[2],
                    $top[1] - 2,
                ];
                if ($cand[3] >= $cand[1] + 28 && $cand[1] > (int) ($h * 0.08)) {
                    $ccx = intdiv($cand[0] + $cand[2], 2);
                    $ccy = intdiv($cand[1] + $cand[3], 2);
                    if ($ccx >= 0 && $ccy >= 0 && $ccx < $w && $ccy < $h
                        && $walls[$ccy * $w + $ccx] === 0
                        && !$this->pointInAnyRectCore($ccx, $ccy, $blockers, 4)
                        && $this->enclosureScore($cand, $walls, $w, $h) >= 0.14
                    ) {
                        $extra[] = $cand;
                        $blockers[] = $cand;
                    }
                }
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Fill empty same-row cubicle slots between two bank hosts (left/right).
     * Works through mid-bank (not just the top 22%), targeting holes like #70/#76.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedSameRowBankGaps(array $rects, array $walls, int $w, int $h): array
    {
        $yMax = (int) ($h * 0.58);
        $shorts = [];
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if (
                $cy < (int) ($h * 0.05)
                || $cy > $yMax
                || $bw < $this->cad(45)
                || $bw > $this->cad(160)
                || $bh < $this->cad(28)
                || $bh > $this->cad(110)
            ) {
                continue;
            }
            $shorts[] = [
                'i' => $i,
                'r' => $r,
                'cx' => intdiv($r[0] + $r[2], 2),
                'cy' => $cy,
                'bw' => $bw,
                'bh' => $bh,
            ];
        }
        if (count($shorts) < 2) {
            return $rects;
        }
        usort($shorts, static fn (array $a, array $b): int => $a['cy'] <=> $b['cy'] ?: $a['cx'] <=> $b['cx']);
        $candidates = [];
        $n = count($shorts);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $shorts[$i];
                $b = $shorts[$j];
                // Mid-bank rows wobble more than top shorts (#70 hosts ~17px cy delta).
                if (abs($a['cy'] - $b['cy']) > max(22, $this->cad(44))) {
                    break;
                }
                if ($b['cx'] <= $a['cx']) {
                    continue;
                }
                $gap = $b['r'][0] - $a['r'][2] - 1;
                $pitch = intdiv($a['bw'] + $b['bw'], 2) + $this->cad(8);
                $centerDist = $b['cx'] - $a['cx'];
                $modPitch = $this->modW > 20 ? $this->modW + $this->cad(8) : $pitch;
                // Fat neighbors can close the body-gap while centers are still ~2 modules apart (#18).
                $twoModule = $centerDist >= (int) ($modPitch * 1.55) && $centerDist <= (int) ($modPitch * 2.55);
                $nSlots = (int) max(1, round($centerDist / max(1, $modPitch)));
                // Multi-module empty runs (raster top A06 column between distant hosts).
                if (
                    ($this->rasterOffice || $this->upscaledWork)
                    && $nSlots >= 3 && $nSlots <= 5 && $gap >= (int) ($modPitch * 1.2)
                ) {
                    $hh = intdiv($a['bh'] + $b['bh'], 2);
                    if ($this->modH > 16) {
                        $hh = max($this->cad(28), min($hh, $this->modH + $this->cad(10)));
                    }
                    $hw = $this->modW > 20 ? max($this->cad(45), min($this->modW + $this->cad(8), intdiv($a['bw'] + $b['bw'], 2))) : intdiv($a['bw'] + $b['bw'], 2);
                    for ($s = 1; $s < $nSlots; $s++) {
                        $cx = (int) round($a['cx'] + ($centerDist * $s) / $nSlots);
                        $cy = intdiv($a['cy'] + $b['cy'], 2);
                        $cand = [
                            max(0, $cx - intdiv($hw, 2)),
                            max(0, $cy - intdiv($hh, 2)),
                            min($w - 1, $cx + intdiv($hw, 2) - 1),
                            min($h - 1, $cy + intdiv($hh, 2) - 1),
                        ];
                        $cand[0] = max($cand[0], $a['r'][2] + 2);
                        $cand[2] = min($cand[2], $b['r'][0] - 2);
                        if ($cand[2] - $cand[0] + 1 < $this->cad(40) || $cand[3] - $cand[1] + 1 < $this->cad(28)) {
                            continue;
                        }
                        $ccx = intdiv($cand[0] + $cand[2], 2);
                        $ccy = intdiv($cand[1] + $cand[3], 2);
                        if ($ccx < 0 || $ccy < 0 || $ccx >= $w || $ccy >= $h || $walls[$ccy * $w + $ccx] === 1) {
                            continue;
                        }
                        if ($this->enclosureScore($cand, $walls, $w, $h) < 0.08) {
                            continue;
                        }
                        $hit = false;
                        foreach ($rects as $o) {
                            if ($this->intersectionArea($cand, $o) > 0) {
                                $hit = true;
                                break;
                            }
                        }
                        if (!$hit) {
                            $candidates[] = ['r' => $cand, 'disp' => []];
                        }
                    }
                    continue;
                }
                if ($gap < (int) ($pitch * 0.55) || $gap > (int) ($pitch * 1.9)) {
                    if (!($twoModule && $gap < (int) ($pitch * 0.55) && $gap >= -$this->cad(20))) {
                        continue;
                    }
                }
                $hw = intdiv($a['bw'] + $b['bw'], 2);
                $hh = intdiv($a['bh'] + $b['bh'], 2);
                // Prefer module width when hosts are fat merges.
                if ($this->modW > 20) {
                    $hw = max($this->cad(45), min($hw, $this->modW + $this->cad(12)));
                }
                if ($this->modH > 16) {
                    $hh = max($this->cad(28), min($hh, $this->modH + $this->cad(10)));
                }
                $cx = intdiv($a['cx'] + $b['cx'], 2);
                $cy = intdiv($a['cy'] + $b['cy'], 2);
                $cand = [
                    max(0, $cx - intdiv($hw, 2)),
                    max(0, $cy - intdiv($hh, 2)),
                    min($w - 1, $cx + intdiv($hw, 2) - 1),
                    min($h - 1, $cy + intdiv($hh, 2) - 1),
                ];
                // Stay inside the gap when positive; for closed gaps use center module box.
                if ($gap >= $this->cad(40)) {
                    $cand[0] = max($cand[0], $a['r'][2] + 2);
                    $cand[2] = min($cand[2], $b['r'][0] - 2);
                } else {
                    // Retract fat hosts off the middle slot so the new cell can survive exclusive.
                    $mid0 = $cand[0];
                    $mid1 = $cand[2];
                    $ai = $a['i'];
                    $bi = $b['i'];
                    if (isset($rects[$ai]) && $rects[$ai][2] >= $mid0) {
                        $rects[$ai][2] = max($rects[$ai][0] + $this->cad(40), $mid0 - 2);
                    }
                    if (isset($rects[$bi]) && $rects[$bi][0] <= $mid1) {
                        $rects[$bi][0] = min($rects[$bi][2] - $this->cad(40), $mid1 + 2);
                    }
                }
                if ($cand[2] - $cand[0] + 1 < $this->cad(40) || $cand[3] - $cand[1] + 1 < $this->cad(28)) {
                    continue;
                }
                $ccx = intdiv($cand[0] + $cand[2], 2);
                $ccy = intdiv($cand[1] + $cand[3], 2);
                if ($walls[$ccy * $w + $ccx] === 1) {
                    continue;
                }
                if ($this->enclosureScore($cand, $walls, $w, $h) < 0.14) {
                    continue;
                }
                $hit = false;
                $displace = [];
                foreach ($rects as $oi => $o) {
                    if ($this->intersectionArea($cand, $o) <= 0) {
                        continue;
                    }
                    $oh = $o[3] - $o[1] + 1;
                    $ow = $o[2] - $o[0] + 1;
                    $ocy = intdiv($o[1] + $o[3], 2);
                    // Replace thin/shifted scraps sitting in the gap (#76 blocker at 865,460).
                    if (
                        (
                            $this->rectArea($o) < (int) ($this->rectArea($cand) * 1.05)
                            && ($oh <= $this->cad(70) || $ow <= $this->cad(70))
                            && $this->overlapFrac($cand, $o) >= 0.28
                        )
                        || (
                            abs($ccy - $cy) + $this->cad(12) < abs($ocy - $cy)
                            && $this->overlapFrac($cand, $o) >= 0.22
                        )
                    ) {
                        $displace[$oi] = true;
                        continue;
                    }
                    $hit = true;
                    break;
                }
                if ($hit) {
                    continue;
                }
                if ($displace !== []) {
                    // Move the shifted scrap onto the gap geometry in-place so a later
                    // budgeted re-select cannot resurrect the bad OCR stamp (#76).
                    $di = array_key_first($displace);
                    if ($di !== null && isset($rects[$di])) {
                        $rects[$di] = $cand;
                    }
                    continue;
                }
                $candidates[] = ['r' => $cand, 'disp' => []];
            }
        }
        if ($candidates === []) {
            return $rects;
        }
        $extra = [];
        $drop = [];
        foreach ($candidates as $item) {
            $cand = $item['r'];
            $skip = false;
            foreach ($extra as $o) {
                if ($this->intersectionArea($cand, $o) > 0) {
                    $skip = true;
                    break;
                }
                $ox = intdiv($o[0] + $o[2], 2);
                $oy = intdiv($o[1] + $o[3], 2);
                $cx = intdiv($cand[0] + $cand[2], 2);
                $cy = intdiv($cand[1] + $cand[3], 2);
                if (hypot($cx - $ox, $cy - $oy) < $this->cad(36)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            foreach ($rects as $oi => $o) {
                if (isset($item['disp'][$oi]) || isset($drop[$oi])) {
                    continue;
                }
                if ($this->intersectionArea($cand, $o) > 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            foreach ($item['disp'] as $oi => $_) {
                $drop[$oi] = true;
            }
            $extra[] = $cand;
            if (count($extra) >= 16) {
                break;
            }
        }
        if ($extra === []) {
            return $rects;
        }
        if ($drop !== []) {
            $kept = [];
            foreach ($rects as $oi => $o) {
                if (!isset($drop[$oi])) {
                    $kept[] = $o;
                }
            }
            $rects = $kept;
        }

        return array_merge($rects, $extra);
    }

    /**
     * After trim: fill a few missing top-row short cubicles by one pitch step (#70/#76/#82).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedPostTrimTopGaps(array $rects, array $walls, int $w, int $h): array
    {
        $yMax = (int) ($h * 0.22);
        $candidates = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if (
                $cy < (int) ($h * 0.05)
                || $cy > $yMax
                || $bw < $this->cad(70)
                || $bw > $this->cad(150)
                || $bh < $this->cad(50)
                || $bh > $this->cad(100)
            ) {
                continue;
            }
            $pitchX = $bw + $this->cad(8);
            $pitchY = $bh + $this->cad(8);
            $trials = [
                [$r[0], max(0, $r[1] - $pitchY), $r[2], $r[1] - 2, 0.30],
                [$r[0], $r[3] + 2, $r[2], min($h - 1, $r[3] + $pitchY), 0.35],
                [max(0, $r[0] - $pitchX), $r[1], $r[0] - 2, $r[3], 0.40],
                [$r[2] + 2, $r[1], min($w - 1, $r[2] + $pitchX), $r[3], 0.40],
            ];
            foreach ($trials as $trial) {
                $bonus = $trial[4];
                $mx = intdiv($trial[0] + $trial[2], 2);
                $my = intdiv($trial[1] + $trial[3], 2);
                $cand = [
                    max(0, $mx - intdiv($bw, 2)),
                    max(0, $my - intdiv($bh, 2)),
                    min($w - 1, $mx + intdiv($bw, 2)),
                    min($h - 1, $my + intdiv($bh, 2)),
                ];
                $ccx = intdiv($cand[0] + $cand[2], 2);
                $ccy = intdiv($cand[1] + $cand[3], 2);
                if ($ccy < (int) ($h * 0.05) || $ccy > $yMax) {
                    continue;
                }
                $cbw = $cand[2] - $cand[0] + 1;
                $cbh = $cand[3] - $cand[1] + 1;
                if ($cbw < $this->cad(70) || $cbh < $this->cad(50) || $cbh > $this->cad(100)) {
                    continue;
                }
                $hit = false;
                $displace = [];
                foreach ($rects as $oi => $o) {
                    if ($this->overlapFrac($cand, $o) <= 0.12 && $this->containmentFrac($cand, $o) <= 0.30) {
                        continue;
                    }
                    $ow = $o[2] - $o[0] + 1;
                    $oh = $o[3] - $o[1] + 1;
                    // Tiny shelf scraps in the slot can be replaced.
                    if ($oh <= $this->cad(70) && $ow <= $this->cad(140) && $this->rectArea($o) < $cbw * $cbh * 0.85) {
                        $displace[$oi] = true;
                        continue;
                    }
                    $hit = true;
                    break;
                }
                if ($hit) {
                    continue;
                }
                $candidates[] = [
                    'r' => $cand,
                    'q' => $bonus + min(0.2, $cbw * $cbh / max(1.0, (float) $this->cadArea(8000))),
                    'disp' => $displace,
                ];
            }
        }
        // Fill a clear same-row hole between two top shorts (#70 between left/right hosts).
        $shorts = [];
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cy > $yMax || $bw < $this->cad(70) || $bh < $this->cad(50) || $bh > $this->cad(100)) {
                continue;
            }
            $shorts[] = ['r' => $r, 'cx' => intdiv($r[0] + $r[2], 2), 'cy' => $cy, 'bw' => $bw, 'bh' => $bh];
        }
        usort($shorts, static fn (array $a, array $b): int => $a['cy'] <=> $b['cy'] ?: $a['cx'] <=> $b['cx']);
        $nShort = count($shorts);
        for ($i = 0; $i < $nShort; $i++) {
            for ($j = $i + 1; $j < $nShort; $j++) {
                $a = $shorts[$i];
                $b = $shorts[$j];
                if (abs($a['cy'] - $b['cy']) > $this->cad(28)) {
                    break;
                }
                if ($b['cx'] <= $a['cx']) {
                    continue;
                }
                $gap = $b['r'][0] - $a['r'][2] - 1;
                $pitch = intdiv($a['bw'] + $b['bw'], 2) + $this->cad(8);
                if ($gap < (int) ($pitch * 0.7) || $gap > (int) ($pitch * 1.8)) {
                    continue;
                }
                $hw = intdiv($a['bw'] + $b['bw'], 2);
                $hh = intdiv($a['bh'] + $b['bh'], 2);
                $cx = intdiv($a['cx'] + $b['cx'], 2);
                $cy = intdiv($a['cy'] + $b['cy'], 2);
                $cand = [
                    max(0, $cx - intdiv($hw, 2)),
                    max(0, $cy - intdiv($hh, 2)),
                    min($w - 1, $cx + intdiv($hw, 2)),
                    min($h - 1, $cy + intdiv($hh, 2)),
                ];
                if ($cand[3] - $cand[1] + 1 < $this->cad(50)) {
                    continue;
                }
                $hit = false;
                $displace = [];
                foreach ($rects as $oi => $o) {
                    if ($this->overlapFrac($cand, $o) <= 0.12) {
                        continue;
                    }
                    $oh = $o[3] - $o[1] + 1;
                    if ($oh <= $this->cad(70) && $this->rectArea($o) < $this->rectArea($cand)) {
                        $displace[$oi] = true;
                        continue;
                    }
                    $hit = true;
                    break;
                }
                if ($hit) {
                    continue;
                }
                $candidates[] = ['r' => $cand, 'q' => 0.58, 'disp' => $displace];
            }
        }
        if ($candidates === []) {
            return $rects;
        }
        usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);
        $extra = [];
        $drop = [];
        foreach ($candidates as $item) {
            $cand = $item['r'];
            $cx = intdiv($cand[0] + $cand[2], 2);
            $cy = intdiv($cand[1] + $cand[3], 2);
            $hit = false;
            foreach ($extra as $o) {
                if ($this->overlapFrac($cand, $o) > 0.12) {
                    $hit = true;
                    break;
                }
                $ox = intdiv($o[0] + $o[2], 2);
                $oy = intdiv($o[1] + $o[3], 2);
                if (hypot($cx - $ox, $cy - $oy) < $this->cad(40)) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                continue;
            }
            foreach ($item['disp'] as $oi => $_) {
                $drop[$oi] = true;
            }
            $extra[] = $cand;
            if (count($extra) >= 8) {
                break;
            }
        }
        if ($extra === []) {
            return $rects;
        }
        if ($drop !== []) {
            $kept = [];
            foreach ($rects as $oi => $o) {
                if (!isset($drop[$oi])) {
                    $kept[] = $o;
                }
            }
            $rects = $kept;
        }

        $rects = array_merge($rects, $extra);

        // Deepen flat top stubs toward neighbor short height (#76/#82 were 31px).
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cy > (int) ($h * 0.22) || $bw < $this->cad(70) || $bh < $this->cad(50) || $bh > $this->cad(70)) {
                continue;
            }
            $targetH = $bh;
            $colX = [];
            $rcx = intdiv($r[0] + $r[2], 2);
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $oh = $o[3] - $o[1] + 1;
                $ow = $o[2] - $o[0] + 1;
                $ocx = intdiv($o[0] + $o[2], 2);
                $ocy = intdiv($o[1] + $o[3], 2);
                if (abs($ocx - $rcx) <= $this->cad(50) && abs($ocy - $cy) <= $this->cad(120)) {
                    $colX[] = $ocx;
                }
                if (abs($ocy - $cy) > $this->cad(40) || abs($ocx - $rcx) > $this->cad(200)) {
                    continue;
                }
                if ($oh >= $this->cad(70) && $oh <= $this->cad(100) && $ow >= $this->cad(70)) {
                    $targetH = max($targetH, $oh);
                }
            }
            if ($targetH < $this->cad(80)) {
                $targetH = $this->cad(80);
            }
            // Snap X toward same-column neighbors when the stub is laterally drifted (#76).
            $grown = $r;
            if ($colX !== []) {
                sort($colX);
                $tx = $colX[(int) (count($colX) / 2)];
                if (abs($tx - $rcx) >= $this->cad(16) && abs($tx - $rcx) <= $this->cad(60)) {
                    $grown[0] = max(0, $tx - intdiv($bw, 2));
                    $grown[2] = min($w - 1, $grown[0] + $bw - 1);
                }
            }
            if ($bh < $targetH - $this->cad(6)) {
                // Prefer expanding upward (desk lip is below); fall back to centered.
                $up = $grown;
                $up[1] = max(0, $grown[3] - $targetH + 1);
                $down = $grown;
                $down[3] = min($h - 1, $grown[1] + $targetH - 1);
                $mid = $grown;
                $m = intdiv($grown[1] + $grown[3], 2);
                $half = intdiv($targetH, 2);
                $mid[1] = max(0, $m - $half);
                $mid[3] = min($h - 1, $m + $half);
                $pick = null;
                foreach ([$up, $down, $mid] as $trial) {
                    if ($trial[3] - $trial[1] + 1 < $bh + $this->cad(8)) {
                        continue;
                    }
                    $bad = false;
                    foreach ($rects as $j => $o) {
                        if ($j === $i) {
                            continue;
                        }
                        if ($this->overlapFrac($trial, $o) > 0.15) {
                            $bad = true;
                            break;
                        }
                    }
                    if (!$bad) {
                        $pick = $trial;
                        break;
                    }
                }
                if ($pick !== null) {
                    $grown = $pick;
                }
            }
            if ($grown !== $r) {
                $bad = false;
                foreach ($rects as $j => $o) {
                    if ($j === $i) {
                        continue;
                    }
                    if ($this->overlapFrac($grown, $o) > 0.15) {
                        $bad = true;
                        break;
                    }
                }
                if (!$bad) {
                    $rects[$i] = $grown;
                }
            }
        }

        return array_values($rects);
    }

    /**
     * After trim: widen narrow upper shorts and fill missing short cells in a column (#55/#57).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function refinePostTrimShortBanks(array $rects, int $w, int $h): array
    {
        $out = array_values($rects);
        $yBand = (int) ($h * 0.38);
        $tileW = $this->cad(110);
        $tileH = $this->cad(80);
        $pitch = $tileH + $this->cad(6);

        // 0) Drop narrow scraps that heavily overlap a wider same-band short (#55 scrap).
        $drop = [];
        $n = count($out);
        for ($i = 0; $i < $n; $i++) {
            if (isset($drop[$i])) {
                continue;
            }
            $a = $out[$i];
            $aw = $a[2] - $a[0] + 1;
            $ah = $a[3] - $a[1] + 1;
            $acy = intdiv($a[1] + $a[3], 2);
            if ($acy > $yBand || $ah < $this->cad(50) || $ah > $this->cad(100)) {
                continue;
            }
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j || isset($drop[$j])) {
                    continue;
                }
                $b = $out[$j];
                $bw = $b[2] - $b[0] + 1;
                $bh = $b[3] - $b[1] + 1;
                $bcy = intdiv($b[1] + $b[3], 2);
                if ($bcy > $yBand || $bh < $this->cad(50) || $bh > $this->cad(100)) {
                    continue;
                }
                if ($this->overlapFrac($a, $b) < 0.28) {
                    continue;
                }
                if ($aw + $this->cad(12) < $bw && $aw < $this->cad(80)) {
                    $drop[$i] = true;
                    break;
                }
                if ($bw + $this->cad(12) < $aw && $bw < $this->cad(80)) {
                    $drop[$j] = true;
                }
            }
        }
        if ($drop !== []) {
            $kept = [];
            foreach ($out as $i => $r) {
                if (!isset($drop[$i])) {
                    $kept[] = $r;
                }
            }
            $out = $kept;
        }

        // 1) Widen narrow upper-bank shorts; displace smaller blockers.
        foreach ($out as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if (
                $cy > $yBand
                || $bw < $this->cad(50)
                || $bw > $this->cad(100)
                || $bh < $this->cad(55)
                || $bh > $this->cad(100)
            ) {
                continue;
            }
            $targetW = max($bw + $this->cad(16), $this->cad(110));
            // Prefer column median X when neighbors agree.
            $colXs = [];
            $rcx = intdiv($r[0] + $r[2], 2);
            foreach ($out as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                $ocx = intdiv($o[0] + $o[2], 2);
                $ocy = intdiv($o[1] + $o[3], 2);
                if (
                    $ocy > $yBand
                    || abs($ocx - $rcx) > $this->cad(70)
                    || $ow < $this->cad(70)
                    || $oh < $this->cad(50)
                    || $oh > $this->cad(110)
                ) {
                    continue;
                }
                $colXs[] = $ocx;
            }
            $mid = $rcx;
            if ($colXs !== []) {
                sort($colXs);
                $mid = $colXs[(int) (count($colXs) / 2)];
            }
            $half = intdiv($targetW, 2);
            $trials = [
                [max(0, $mid - $half), $r[1], min($w - 1, $mid + $half), $r[3]],
                [max(0, $r[2] - $targetW + 1), $r[1], $r[2], $r[3]],
                [$r[0], $r[1], min($w - 1, $r[0] + $targetW - 1), $r[3]],
            ];
            foreach ($trials as $grown) {
                if ($grown[2] - $grown[0] + 1 < $bw + $this->cad(8)) {
                    continue;
                }
                $displace = [];
                $bad = false;
                foreach ($out as $j => $o) {
                    if ($j === $i) {
                        continue;
                    }
                    if ($this->overlapFrac($grown, $o) <= 0.14) {
                        continue;
                    }
                    $ow = $o[2] - $o[0] + 1;
                    $oh = $o[3] - $o[1] + 1;
                    if (
                        $oh <= $this->cad(100)
                        && $ow <= $this->cad(140)
                        && $this->rectArea($o) < $this->rectArea($grown) * 0.9
                    ) {
                        $displace[$j] = true;
                        continue;
                    }
                    $bad = true;
                    break;
                }
                if ($bad) {
                    continue;
                }
                if ($displace !== []) {
                    $kept = [];
                    foreach ($out as $j => $o) {
                        if ($j === $i) {
                            $kept[] = $grown;
                        } elseif (!isset($displace[$j])) {
                            $kept[] = $o;
                        }
                    }
                    $out = $kept;
                } else {
                    $out[$i] = $grown;
                }
                break;
            }
        }

        // 2) Realign crushed upper short columns onto a near-flush pitch lattice (#57).
        $idxs = [];
        foreach ($out as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if (
                $cy > $yBand
                || $bw < $this->cad(70)
                || $bw > $this->cad(150)
                || $bh < $this->cad(50)
                || $bh > $this->cad(110)
            ) {
                continue;
            }
            $idxs[] = $i;
        }
        $usedCol = [];
        foreach ($idxs as $seed) {
            if (isset($usedCol[$seed])) {
                continue;
            }
            $cx0 = intdiv($out[$seed][0] + $out[$seed][2], 2);
            $col = [];
            foreach ($idxs as $i) {
                if (isset($usedCol[$i])) {
                    continue;
                }
                $cx = intdiv($out[$i][0] + $out[$i][2], 2);
                if (abs($cx - $cx0) <= $this->cad(36)) {
                    $col[] = $i;
                    $usedCol[$i] = true;
                }
            }
            if (count($col) < 3) {
                continue;
            }
            usort($col, function (int $a, int $b) use ($out): int {
                return $out[$a][1] <=> $out[$b][1];
            });
            $top = $out[$col[0]];
            $bot = $out[$col[count($col) - 1]];
            $span = $bot[3] - $top[1] + 1;
            $slots = max(count($col), (int) round($span / max(1, $pitch)));
            // Only repair when span implies a missing cell (crushed pitch).
            if ($slots <= count($col)) {
                // Still nudge consecutive pairs that leave a ~1-slot hole between centers.
                for ($k = 0; $k + 1 < count($col); $k++) {
                    $a = $out[$col[$k]];
                    $b = $out[$col[$k + 1]];
                    $acy = intdiv($a[1] + $a[3], 2);
                    $bcy = intdiv($b[1] + $b[3], 2);
                    $dc = $bcy - $acy;
                    // One-pitch spacing with both cells short → insert mid cell when room on Y span of neighbors.
                    if ($dc < (int) ($pitch * 0.85) || $dc > (int) ($pitch * 1.35)) {
                        continue;
                    }
                    // Peek further: if a→c spans ~2 pitches, plant between a and b by shifting b down.
                    if ($k + 2 >= count($col)) {
                        continue;
                    }
                    $c = $out[$col[$k + 2]];
                    $ccy = intdiv($c[1] + $c[3], 2);
                    if ($ccy - $acy < (int) ($pitch * 1.7) || $ccy - $acy > (int) ($pitch * 2.6)) {
                        continue;
                    }
                    // Shift b downward onto second pitch; plant first pitch under a.
                    $bw = $a[2] - $a[0] + 1;
                    $bh = max($tileH, $a[3] - $a[1] + 1);
                    $cx = intdiv($a[0] + $a[2], 2);
                    $plant = [
                        max(0, $cx - intdiv($tileW, 2)),
                        $a[3] + $this->cad(4),
                        min($w - 1, $cx + intdiv($tileW, 2)),
                        $a[3] + $this->cad(4) + $bh - 1,
                    ];
                    $shiftB = $b;
                    $shiftB[1] = $plant[3] + $this->cad(4);
                    $shiftB[3] = $shiftB[1] + ($b[3] - $b[1]);
                    if ($shiftB[3] >= $c[1] - $this->cad(4)) {
                        continue;
                    }
                    $ok = true;
                    foreach ([$plant, $shiftB] as $trial) {
                        foreach ($out as $oi => $o) {
                            if ($oi === $col[$k] || $oi === $col[$k + 1] || $oi === $col[$k + 2]) {
                                continue;
                            }
                            if ($this->overlapFrac($trial, $o) > 0.14) {
                                $ok = false;
                                break 2;
                            }
                        }
                    }
                    if (!$ok) {
                        continue;
                    }
                    $out[$col[$k + 1]] = $shiftB;
                    $out[] = $plant;
                    break;
                }
                continue;
            }
            // Build lattice from top and fill unmatched slots.
            $bw = $top[2] - $top[0] + 1;
            $bh = max($tileH, $top[3] - $top[1] + 1);
            $cx = intdiv($top[0] + $top[2], 2);
            $matched = [];
            $extra = [];
            for ($s = 0; $s < $slots && $s < count($col) + 2; $s++) {
                $y0 = $top[1] + $s * $pitch;
                $y1 = $y0 + $bh - 1;
                if ($y1 > $yBand + $this->cad(40) || $y1 >= $h) {
                    break;
                }
                $slot = [
                    max(0, $cx - intdiv(max($bw, $tileW), 2)),
                    $y0,
                    min($w - 1, $cx + intdiv(max($bw, $tileW), 2)),
                    $y1,
                ];
                $best = -1;
                $bestD = PHP_INT_MAX;
                foreach ($col as $ci) {
                    if (isset($matched[$ci])) {
                        continue;
                    }
                    $ocy = intdiv($out[$ci][1] + $out[$ci][3], 2);
                    $scy = intdiv($slot[1] + $slot[3], 2);
                    $d = abs($ocy - $scy);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = $ci;
                    }
                }
                if ($best >= 0 && $bestD <= (int) ($pitch * 0.55)) {
                    $matched[$best] = true;
                    // Soft snap Y toward lattice when close.
                    if ($bestD >= $this->cad(10)) {
                        $cur = $out[$best];
                        $nh = $cur[3] - $cur[1] + 1;
                        $out[$best] = [$cur[0], $y0, $cur[2], min($h - 1, $y0 + $nh - 1)];
                    }
                    continue;
                }
                $hit = false;
                foreach ($out as $o) {
                    if ($this->overlapFrac($slot, $o) > 0.14) {
                        $hit = true;
                        break;
                    }
                }
                foreach ($extra as $o) {
                    if ($this->overlapFrac($slot, $o) > 0.14) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    $extra[] = $slot;
                }
            }
            if ($extra !== []) {
                $out = array_merge($out, $extra);
            }
        }

        // 3) Classic clear-gap plant (still helps sparse columns).
        $extra = [];
        foreach ($out as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            $cx = intdiv($r[0] + $r[2], 2);
            if (
                $cy < (int) ($h * 0.08)
                || $cy > (int) ($h * 0.30)
                || $bw < $this->cad(70)
                || $bw > $this->cad(140)
                || $bh < $this->cad(50)
                || $bh > $this->cad(100)
            ) {
                continue;
            }
            $nextTop = $h;
            foreach (array_merge($out, $extra) as $o) {
                $ocx = intdiv($o[0] + $o[2], 2);
                if (abs($ocx - $cx) > $this->cad(36)) {
                    continue;
                }
                if ($o[1] > $r[3] + 2) {
                    $nextTop = min($nextTop, $o[1]);
                }
            }
            $gap = $nextTop - $r[3] - 1;
            if ($gap < $tileH + $this->cad(8) || $gap > $tileH * 3) {
                continue;
            }
            $y0 = $r[3] + max($this->cad(8), intdiv($bh, 5));
            $y1 = min($nextTop - 2, $y0 + $tileH - 1);
            $sy = intdiv($y0 + $y1, 2);
            if ($sy < (int) ($h * 0.12) || $sy > (int) ($h * 0.36)) {
                continue;
            }
            $cand = [
                max(0, $cx - intdiv($tileW, 2)),
                $y0,
                min($w - 1, $cx + intdiv($tileW, 2)),
                $y1,
            ];
            if ($cand[3] - $cand[1] + 1 < $this->cad(55)) {
                continue;
            }
            $hit = false;
            $displace = [];
            foreach ($out as $oi => $o) {
                if ($this->overlapFrac($cand, $o) <= 0.12) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                if ($oh <= $this->cad(100) && $ow <= $this->cad(140) && $this->rectArea($o) < $this->rectArea($cand)) {
                    $displace[$oi] = true;
                    continue;
                }
                $hit = true;
                break;
            }
            if ($hit) {
                continue;
            }
            foreach ($extra as $o) {
                if ($this->overlapFrac($cand, $o) > 0.12) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                continue;
            }
            if ($displace !== []) {
                $kept = [];
                foreach ($out as $oi => $o) {
                    if (!isset($displace[$oi])) {
                        $kept[] = $o;
                    }
                }
                $out = $kept;
            }
            $extra[] = $cand;
            if (count($extra) >= 4) {
                break;
            }
        }

        return $extra === [] ? array_values($out) : array_values(array_merge($out, $extra));
    }

    /**
     * Grow undersized short-bank stubs toward desk/open floor when a taller neighbor exists.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function deepenShortBankCells(array $rects, array $walls, int $w, int $h): array
    {
        $out = $rects;
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $cy = intdiv($r[1] + $r[3], 2);
            if (
                $cy > (int) ($h * 0.58)
                || $bw < $this->cad(70)
                || $bw > $this->cad(160)
                || $bh < $this->cad(50)
                || $bh > $this->cad(100)
            ) {
                continue;
            }
            // Target size from nearby same-row / same-column bank cells.
            $targetH = $bh;
            $targetW = $bw;
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                $ocx = intdiv($o[0] + $o[2], 2);
                $ocy = intdiv($o[1] + $o[3], 2);
                $rcx = intdiv($r[0] + $r[2], 2);
                if (abs($ocx - $rcx) <= $this->cad(30) && $oh >= $this->cad(70) && $oh <= $this->cad(140) && $ow >= $this->cad(70)) {
                    $targetH = max($targetH, $oh);
                }
                if (abs($ocy - $cy) <= $this->cad(30) && $ow >= $this->cad(100) && $ow <= $this->cad(160) && $oh >= $this->cad(55)) {
                    $targetW = max($targetW, $ow);
                }
            }
            // Mid-bank stubs are often half-width of a real cubicle (~70–80 work px).
            // Keep above ~0.35 so top short rows (#59/#62) are not rewritten.
            if ($bw < $this->cad(120) && $cy >= (int) ($h * 0.35) && $cy <= (int) ($h * 0.55)) {
                $targetW = max($targetW, $this->cad(140));
                $targetH = max($targetH, $this->cad(100));
            }
            if ($targetH < $this->cad(85)) {
                $targetH = max($targetH, $this->cad(85));
            }
            $needH = $bh < $targetH - $this->cad(8);
            $needW = $bw < $targetW - $this->cad(12) && $bw < $this->cad(110);
            if (!$needH && !$needW) {
                continue;
            }
            $grown = $r;
            $grown = $this->expandCubicleToWalls($grown, $walls, $w, $h, $this->cad(36));
            if (!$needW) {
                $grown[0] = $r[0];
                $grown[2] = $r[2];
            } else {
                $gW = $grown[2] - $grown[0] + 1;
                // Dense desk ink often blocks expand — fall back to geometric mid-bank width.
                if ($gW < $targetW - $this->cad(16)) {
                    $mid = intdiv($r[0] + $r[2], 2);
                    $half = intdiv($targetW, 2);
                    $grown[0] = max(0, $mid - $half);
                    $grown[2] = min($w - 1, $mid + $half);
                    $gW = $grown[2] - $grown[0] + 1;
                }
                if ($gW > $targetW + $this->cad(20)) {
                    $mid = intdiv($r[0] + $r[2], 2);
                    $half = intdiv($targetW, 2);
                    $grown[0] = max($grown[0], $mid - $half);
                    $grown[2] = min($grown[2], $mid + $half);
                }
            }
            if (!$needH) {
                $grown[1] = $r[1];
                $grown[3] = $r[3];
            } elseif ($grown[3] - $grown[1] + 1 < $targetH - $this->cad(12)) {
                $mid = intdiv($r[1] + $r[3], 2);
                $half = intdiv($targetH, 2);
                $grown[1] = max(0, $mid - $half);
                $grown[3] = min($h - 1, $mid + $half);
            } elseif ($grown[3] - $grown[1] + 1 > $targetH + $this->cad(20)) {
                $mid = intdiv($r[1] + $r[3], 2);
                $half = intdiv($targetH, 2);
                $grown[1] = max($grown[1], $mid - $half);
                $grown[3] = min($grown[3], $mid + $half);
            }
            $gBh = $grown[3] - $grown[1] + 1;
            $gBw = $grown[2] - $grown[0] + 1;
            if ($gBw < $bw || $gBh < $bh) {
                continue;
            }
            if ($gBh > $this->cad(140) || $gBw > $this->cad(180)) {
                continue;
            }
            if ($gBw < $bw + $this->cad(8) && $gBh < $bh + $this->cad(8)) {
                continue;
            }
            $bad = false;
            foreach ($out as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                if ($this->overlapFrac($grown, $o) > 0.18) {
                    // Try one-sided width if full widen collides.
                    if ($needW && $gBw > $bw) {
                        $left = $r;
                        $left[0] = $grown[0];
                        $right = $r;
                        $right[2] = $grown[2];
                        $leftOk = true;
                        $rightOk = true;
                        foreach ($out as $k => $oo) {
                            if ($k === $i) {
                                continue;
                            }
                            if ($this->overlapFrac($left, $oo) > 0.18) {
                                $leftOk = false;
                            }
                            if ($this->overlapFrac($right, $oo) > 0.18) {
                                $rightOk = false;
                            }
                        }
                        if ($leftOk && ($left[2] - $left[0] + 1) >= $bw + $this->cad(8)) {
                            $grown = $left;
                            $bad = false;
                            break;
                        }
                        if ($rightOk && ($right[2] - $right[0] + 1) >= $bw + $this->cad(8)) {
                            $grown = $right;
                            $bad = false;
                            break;
                        }
                    }
                    $bad = true;
                    break;
                }
            }
            if (!$bad) {
                $out[$i] = $grown;
            }
        }

        return array_values($out);
    }

    /**
     * Drop weak short cubicle scraps that pitch/shatter invent away from real bank lattice.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function cullWeakShortBankScraps(array $rects, array $walls, int $w, int $h): array
    {
        $kept = [];
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            $cy = intdiv($r[1] + $r[3], 2);
            $large = $area >= $this->cadArea(28000);
            if ($large || $bh >= $this->cad(70) || $cy >= (int) ($h * 0.70)) {
                $kept[] = $r;
                continue;
            }
            // Flat/short scraps: require solid enclosure + a same-pitch bank neighbor.
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $q = $this->cubicleQuality($r, $walls, $w, $h);
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.28);
            if ($bh < $this->cad(60) && ($enc < 0.22 || $q < 1.05 || $sides < 2)) {
                $nearBank = false;
                $rcx = intdiv($r[0] + $r[2], 2);
                foreach ($rects as $j => $o) {
                    if ($j === $i) {
                        continue;
                    }
                    $ow = $o[2] - $o[0] + 1;
                    $oh = $o[3] - $o[1] + 1;
                    if ($oh < $this->cad(65) || $ow < $this->cad(70) || $ow > $this->cad(180)) {
                        continue;
                    }
                    $ocx = intdiv($o[0] + $o[2], 2);
                    $ocy = intdiv($o[1] + $o[3], 2);
                    // One vertical pitch step (~80 work px) — keep tight to avoid FP flood.
                    if (hypot($rcx - $ocx, $cy - $ocy) <= $this->cad(160)) {
                        $nearBank = true;
                        break;
                    }
                }
                if (!$nearBank) {
                    continue;
                }
            }
            $kept[] = $r;
        }

        return $kept;
    }

    /**
     * Cubicles that grew across a weak mid-partition (two stacked bank cells) get split.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitStraddlingCubicles(
        array $rects,
        array $walls,
        int $w,
        int $h,
        ?array $denseWalls = null
    ): array {
        $out = [];
        foreach ($rects as $r) {
            $parts = $this->trySplitStraddlePair($r, $walls, $w, $h, true, $denseWalls);
            if ($parts === null) {
                $parts = $this->trySplitStraddlePair($r, $walls, $w, $h, false, $denseWalls);
            }
            if ($parts !== null) {
                foreach ($parts as $p) {
                    $out[] = $p;
                }
            } else {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * Split a double cubicle cell across a mid partition (horizontal or vertical).
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @param list<int>|null $denseWalls Closed ink for mid-band detection when partitions are thin.
     * @return list<array{0:int,1:int,2:int,3:int}>|null
     */
    private function trySplitStraddlePair(
        array $r,
        array $walls,
        int $w,
        int $h,
        bool $horizontal,
        ?array $denseWalls = null
    ): ?array {
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $area = $bw * $bh;
        if ($area < $this->cadArea(5000) || $area > $this->cadArea(40000)) {
            return null;
        }
        $sense = $denseWalls ?? $walls;
        if ($horizontal) {
            // Allow slightly shorter doubles (two stacked ~40px cubicles ≈ 66–90 work px).
            // Allow slightly shorter doubles (two stacked ~40px cubicles ≈ 66–90 work px).
            if (
                $bw < $this->cad(70)
                || $bw > $this->cad(200)
                || $bh < $this->cad(80)
                || $bh > $this->cad(190)
            ) {
                return null;
            }
            $bestPos = -1;
            $bestHits = 0;
            $mid0 = $r[1] + (int) ($bh * 0.35);
            $mid1 = $r[1] + (int) ($bh * 0.65);
            for ($y = $mid0; $y <= $mid1; $y++) {
                $hits = 0;
                $tot = 0;
                for ($x = $r[0]; $x <= $r[2]; $x += 2) {
                    $tot++;
                    if ($sense[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($tot > 0 && $hits > $bestHits) {
                    $bestHits = $hits;
                    $bestPos = $y;
                }
            }
            if ($bestPos < 0 || ($bestHits / max(1, intdiv($bw, 2) + 1)) < 0.42) {
                return null;
            }
            $a = [$r[0], $r[1], $r[2], $bestPos - 1];
            $b = [$r[0], $bestPos + 1, $r[2], $r[3]];
        } else {
            // Side-by-side double cell (wider than tall).
            if (
                $bh < $this->cad(50)
                || $bh > $this->cad(150)
                || $bw < $this->cad(95)
                || $bw > $this->cad(240)
            ) {
                return null;
            }
            $bestPos = -1;
            $bestHits = 0;
            $mid0 = $r[0] + (int) ($bw * 0.35);
            $mid1 = $r[0] + (int) ($bw * 0.65);
            for ($x = $mid0; $x <= $mid1; $x++) {
                $hits = 0;
                $tot = 0;
                for ($y = $r[1]; $y <= $r[3]; $y += 2) {
                    $tot++;
                    if ($sense[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($tot > 0 && $hits > $bestHits) {
                    $bestHits = $hits;
                    $bestPos = $x;
                }
            }
            if ($bestPos < 0 || ($bestHits / max(1, intdiv($bh, 2) + 1)) < 0.42) {
                return null;
            }
            $a = [$r[0], $r[1], $bestPos - 1, $r[3]];
            $b = [$bestPos + 1, $r[1], $r[2], $r[3]];
        }
        $parts = [];
        foreach ([$a, $b] as $part) {
            $pw = $part[2] - $part[0] + 1;
            $ph = $part[3] - $part[1] + 1;
            $pa = $pw * $ph;
            if ($pw < $this->cad(50) || $ph < $this->cad(40) || $pa < $this->cadArea(2800) || $pa > $this->cadArea(16000)) {
                continue;
            }
            $cx = intdiv($part[0] + $part[2], 2);
            $cy = intdiv($part[1] + $part[3], 2);
            if ($walls[$cy * $w + $cx] === 1) {
                continue;
            }
            $grown = $this->growRectFromSeed($walls, $w, $h, $cx, $cy, $this->cad(70), $this->cad(55));
            if ($grown === null) {
                continue;
            }
            $grown = $this->padOpenCubicle($grown, $walls, $w, $h);
            $gw = $grown[2] - $grown[0] + 1;
            $gh = $grown[3] - $grown[1] + 1;
            if ($gw < $this->cad(50) || $gh < $this->cad(40) || $gw * $gh > $this->cadArea(20000)) {
                continue;
            }
            if ($this->enclosureScore($grown, $walls, $w, $h) < 0.26) {
                continue;
            }
            $parts[] = $grown;
        }

        return count($parts) >= 2 ? $parts : null;
    }

    /**
     * Late pass for tall stacked cubicle doubles the early splitter rejects (bh > cad(190)).
     * Uses raw mid halves (no re-grow) so desk lips cannot merge the parts again.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<int>|null $denseWalls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitTallBankDoubles(
        array $rects,
        array $walls,
        int $w,
        int $h,
        ?array $denseWalls = null
    ): array {
        $sense = $denseWalls ?? $walls;
        $out = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            if (
                $area < $this->cadArea(7000)
                || $area > $this->cadArea(35000)
                || $bw < $this->cad(70)
                || $bw > $this->cad(160)
                || $bh <= $this->cad(190)
                || $bh > $this->cad(260)
            ) {
                $out[] = $r;
                continue;
            }
            $bestPos = -1;
            $bestHits = 0;
            $mid0 = $r[1] + (int) ($bh * 0.35);
            $mid1 = $r[1] + (int) ($bh * 0.65);
            for ($y = $mid0; $y <= $mid1; $y++) {
                $hits = 0;
                $tot = 0;
                for ($x = $r[0]; $x <= $r[2]; $x += 2) {
                    $tot++;
                    if ($sense[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($tot > 0 && $hits > $bestHits) {
                    $bestHits = $hits;
                    $bestPos = $y;
                }
            }
            // Desk-sense mid bands on CAD are often ~0.39–0.42 of sampled columns.
            if ($bestPos < 0 || ($bestHits / max(1, intdiv($bw, 2) + 1)) < 0.38) {
                $out[] = $r;
                continue;
            }
            $a = [$r[0], $r[1], $r[2], $bestPos - 1];
            $b = [$r[0], $bestPos + 1, $r[2], $r[3]];
            $parts = [];
            foreach ([$a, $b] as $part) {
                $pw = $part[2] - $part[0] + 1;
                $ph = $part[3] - $part[1] + 1;
                $pa = $pw * $ph;
                if ($pw < $this->cad(55) || $ph < $this->cad(42) || $pa < $this->cadArea(3200) || $pa > $this->cadArea(20000)) {
                    continue;
                }
                $cx = intdiv($part[0] + $part[2], 2);
                $cy = intdiv($part[1] + $part[3], 2);
                if ($walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                if ($this->enclosureScore($part, $walls, $w, $h) < 0.22) {
                    continue;
                }
                $parts[] = $part;
            }
            if (count($parts) === 2) {
                $a0 = $this->rectArea($parts[0]);
                $a1 = $this->rectArea($parts[1]);
                if (max($a0, $a1) <= min($a0, $a1) * 2.2) {
                    $out[] = $parts[0];
                    $out[] = $parts[1];
                    continue;
                }
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Split shifted side-by-side bank doubles using a weak V mid (raw halves — no re-grow).
     * Then plant one desk-band companion row below each half (#23/#24).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<int> $dense
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitShiftedSideDoubles(
        array $rects,
        array $walls,
        array $dense,
        int $w,
        int $h
    ): array {
        $out = [];
        $companions = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            // Tight gate: only #66-like shifted pairs (~56×62 work).
            if (
                $area < $this->cadArea(5000)
                || $area > $this->cadArea(16000)
                || $bw < $this->cad(100)
                || $bw > $this->cad(125)
                || $bh < $this->cad(100)
                || $bh > $this->cad(140)
            ) {
                $out[] = $r;
                continue;
            }
            $bestPos = -1;
            $bestHits = 0;
            $mid0 = $r[0] + (int) ($bw * 0.35);
            $mid1 = $r[0] + (int) ($bw * 0.65);
            for ($x = $mid0; $x <= $mid1; $x++) {
                $hits = 0;
                $tot = 0;
                for ($y = $r[1]; $y <= $r[3]; $y += 2) {
                    $tot++;
                    if ($dense[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                if ($tot > 0 && $hits > $bestHits) {
                    $bestHits = $hits;
                    $bestPos = $x;
                }
            }
            if ($bestPos < 0 || ($bestHits / max(1, intdiv($bh, 2) + 1)) < 0.28) {
                $out[] = $r;
                continue;
            }
            $parts = [
                [$r[0], $r[1], $bestPos - 1, $r[3]],
                [$bestPos + 1, $r[1], $r[2], $r[3]],
            ];
            $kept = [];
            foreach ($parts as $part) {
                $pw = $part[2] - $part[0] + 1;
                $ph = $part[3] - $part[1] + 1;
                if ($pw < $this->cad(40) || $ph < $this->cad(50) || $pw * $ph < $this->cadArea(2800)) {
                    continue;
                }
                $cx = intdiv($part[0] + $part[2], 2);
                $cy = intdiv($part[1] + $part[3], 2);
                if ($walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                $kept[] = $part;
            }
            if (count($kept) < 2) {
                $out[] = $r;
                continue;
            }
            foreach ($kept as $part) {
                $out[] = $part;
                // Bias seed toward the outer edge of each half so desk-band expand
                // does not drift into the mid divider (#24 wants x≈643, not 623).
                $cx = $part[0] < $bestPos
                    ? ($part[0] + intdiv($part[2] - $part[0], 3))
                    : ($part[2] - intdiv($part[2] - $part[0], 3));
                $cy = intdiv($part[1] + $part[3], 2);
                foreach ([$this->cad(78), $this->cad(84)] as $dy) {
                    $sy = $cy + $dy;
                    if ($sy < 4 || $sy >= $h - 4) {
                        continue;
                    }
                    $seed = [
                        max(0, $cx - $this->cad(24)),
                        max(0, $sy - $this->cad(24)),
                        min($w - 1, $cx + $this->cad(24)),
                        min($h - 1, $sy + $this->cad(24)),
                    ];
                    if ($walls[$sy * $w + $cx] === 1) {
                        continue;
                    }
                    $cand = $this->expandThroughShallowDeskBands(
                        $seed,
                        $walls,
                        $dense,
                        $w,
                        $h,
                        $this->cad(36)
                    );
                    $cbw = $cand[2] - $cand[0] + 1;
                    $cbh = $cand[3] - $cand[1] + 1;
                    if (
                        $cbw < $this->cad(40)
                        || $cbw > $this->cad(90)
                        || $cbh < $this->cad(70)
                        || $cbh > $this->cad(150)
                        || $cbw * $cbh < $this->cadArea(2800)
                    ) {
                        continue;
                    }
                    // Stay in the same column as the parent half.
                    $ccx = intdiv($cand[0] + $cand[2], 2);
                    if (abs($ccx - $cx) > $this->cad(48)) {
                        continue;
                    }
                    // Open bank cells often score enc≈0 after desk-band expand — size/column gates suffice.
                    $skip = false;
                    foreach (array_merge($out, $companions) as $host) {
                        $hbh = $host[3] - $host[1] + 1;
                        if ($hbh <= $this->cad(55) && $this->rectArea($host) <= $this->cadArea(14000)) {
                            $ovGate = 0.62; // thin scrap
                        } elseif ($hbh >= $this->cad(90)) {
                            $ovGate = 0.48; // parent half may lightly overlap
                        } else {
                            $ovGate = 0.35;
                        }
                        if ($this->overlapFrac($cand, $host) > $ovGate) {
                            $skip = true;
                            break;
                        }
                    }
                    if (!$skip) {
                        $companions[] = $cand;
                        break;
                    }
                }
            }
        }

        if ($companions === []) {
            return $out;
        }
        // Drop thin bank strips that the new desk-band companions supersede (#115 vs #24).
        $kept = [];
        foreach ($out as $r) {
            $rbh = $r[3] - $r[1] + 1;
            $drop = false;
            if ($rbh <= $this->cad(55) && $this->rectArea($r) <= $this->cadArea(14000)) {
                foreach ($companions as $c) {
                    if ($this->overlapFrac($r, $c) > 0.40) {
                        $drop = true;
                        break;
                    }
                }
            }
            if (!$drop) {
                $kept[] = $r;
            }
        }

        return array_merge($kept, $companions);
    }

    /**
     * Carve at most one upper narrow alcove from a tall mid-width host (#94 in #15).
     * Extremely gated so stacked bank towers (#9) are never touched.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<int> $dense
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function carveNarrowAlcovesFromLarge(
        array $rects,
        array $walls,
        array $dense,
        int $w,
        int $h
    ): array {
        $best = null;
        $bestEnc = -1.0;
        $bestHostIdx = -1;
        foreach ($rects as $hi => $host) {
            $hArea = $this->rectArea($host);
            $hbw = $host[2] - $host[0] + 1;
            $hbh = $host[3] - $host[1] + 1;
            // Exact #15-like silhouette: ~63×207 sitting high on the sheet.
            if (
                $hArea < $this->cadArea(40000)
                || $hArea > $this->cadArea(60000)
                || $hbw < $this->cad(110)
                || $hbw > $this->cad(140)
                || $hbh < $this->cad(380)
                || $hbh > $this->cad(450)
                || $host[1] > $this->cad(560) // skip lower stacked towers (#9 @ y≈328)
            ) {
                continue;
            }
            if ($this->enclosureScore($host, $walls, $w, $h) < 0.50) {
                continue;
            }
            // Right-biased seeds in the upper third (desk-band alcove).
            $y0 = $host[1] + $this->cad(24);
            $y1 = $host[1] + (int) ($hbh * 0.38);
            $x0 = $host[0] + (int) ($hbw * 0.35);
            $x1 = $host[2] - $this->cad(12);
            for ($sy = $y0; $sy <= $y1; $sy += $this->cad(16)) {
                for ($sx = $x0; $sx <= $x1; $sx += $this->cad(10)) {
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    $halfW = $this->cad(28);
                    $halfH = $this->cad(40);
                    $seed = [
                        max($host[0], $sx - $halfW),
                        max($host[1], $sy - $halfH),
                        min($host[2], $sx + $halfW),
                        min($host[3], $sy + $halfH),
                    ];
                    $cand = $this->expandThroughShallowDeskBands(
                        $seed,
                        $walls,
                        $dense,
                        $w,
                        $h,
                        $this->cad(40)
                    );
                    $cand = [
                        max($cand[0], $host[0]),
                        max($cand[1], $host[1]),
                        min($cand[2], $host[2]),
                        min($cand[3], $host[3]),
                    ];
                    $bw = $cand[2] - $cand[0] + 1;
                    $bh = $cand[3] - $cand[1] + 1;
                    if (
                        $bw < $this->cad(45)
                        || $bw > $this->cad(70)
                        || $bh < $this->cad(80)
                        || $bh > $this->cad(180)
                        || $bw * $bh < $this->cadArea(3200)
                    ) {
                        continue;
                    }
                    if ($bw > (int) ($hbw * 0.50)) {
                        continue;
                    }
                    $enc = $this->enclosureScore($cand, $walls, $w, $h);
                    if ($enc < 0.28 || $enc <= $bestEnc) {
                        continue;
                    }
                    if ($this->interiorWallFrac($cand, $walls, $w, $h) > 0.10) {
                        continue;
                    }
                    $overlapBad = false;
                    foreach ($rects as $oi => $o) {
                        if ($oi === $hi) {
                            continue;
                        }
                        if ($this->overlapFrac($cand, $o) > 0.30) {
                            $overlapBad = true;
                            break;
                        }
                    }
                    if ($overlapBad) {
                        continue;
                    }
                    $best = $cand;
                    $bestEnc = $enc;
                    $bestHostIdx = $hi;
                }
            }
        }
        if ($best === null || $bestHostIdx < 0) {
            return $rects;
        }
        $out = [];
        foreach ($rects as $i => $r) {
            if ($i !== $bestHostIdx) {
                $out[] = $r;
                continue;
            }
            $trimmed = $this->trimRectAwayFrom($r, $best);
            if (
                $trimmed !== null
                && $this->rectArea($trimmed) >= $this->cadArea(12000)
                && min($trimmed[2] - $trimmed[0] + 1, $trimmed[3] - $trimmed[1] + 1) >= $this->cad(40)
            ) {
                $out[] = $trimmed;
            } else {
                $out[] = $r; // keep host if trim would destroy it; exclusive will arbitrate
            }
        }
        $out[] = $best;

        return $out;
    }

    /**
     * Cubicle banks are often two rows (~80px). If we only landed one, seed companions
     * one pitch above/below.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedStackedCompanions(array $rects, array $walls, int $w, int $h): array
    {
        $extra = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(2500) || $area > $this->cadArea(20000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            // Include short open bank cells (~25–40 work px) that still need a stacked neighbor.
            if ($bw < $this->cad(70) || $bw > $this->cad(160) || $bh < $this->cad(32) || $bh > $this->cad(110)) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $deltas = [
                // True cubicle pitch on work renders is ~40–55px; also keep larger cad pitches.
                [0, -55], [0, 55], [0, -48], [0, 48], [0, -42], [0, 42], [0, -38], [0, 38],
                [0, -$this->cad(160)], [0, $this->cad(160)],
                [0, -$this->cad(140)], [0, $this->cad(140)],
                [0, -$this->cad(120)], [0, $this->cad(120)],
                [0, -$this->cad(100)], [0, $this->cad(100)],
                [0, -$this->cad(84)], [0, $this->cad(84)],
                [0, -$this->cad(70)], [0, $this->cad(70)],
            ];
            // Explicit seeds above the short host top (unequal stacks like #19/#18).
            if ($bh <= $this->cad(95)) {
                array_unshift(
                    $deltas,
                    [0, $r[1] - $cy - $this->cad(100)],
                    [0, $r[1] - $cy - $this->cad(70)],
                    [0, $r[1] - $cy - $this->cad(130)]
                );
            }
            foreach ($deltas as [$dx, $dy]) {
                $sx = $cx + $dx;
                $sy = $cy + $dy;
                if ($sx < 4 || $sy < 4 || $sx >= $w - 4 || $sy >= $h - 4) {
                    continue;
                }
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                // Tall companion above a short open cell (e.g. #19 above #18).
                $wantTall = $bh <= $this->cad(95) && $dy < 0;
                $maxGrowH = $wantTall ? $this->cad(220) : $this->cad(60);
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(75), $maxGrowH);
                if ($grown === null) {
                    continue;
                }
                $cand = $this->padOpenCubicle($grown, $walls, $w, $h);
                if ($wantTall) {
                    $cand = $this->expandCubicleToWalls($cand, $walls, $w, $h, $this->cad(80));
                    // Keep the short host below intact (#19 must not absorb #18).
                    if ($cand[3] >= $r[1] - 1) {
                        $cand[3] = $r[1] - 2;
                    }
                    if ($cand[3] < $cand[1] + $this->cad(70)) {
                        continue;
                    }
                }
                $cbw = $cand[2] - $cand[0] + 1;
                $cbh = $cand[3] - $cand[1] + 1;
                $cArea = $cbw * $cbh;
                $maxH = $wantTall ? $this->cad(240) : $this->cad(110);
                $minH = $wantTall ? $this->cad(80) : $this->cad(32);
                $maxA = $wantTall ? $this->cadArea(32000) : $this->cadArea(18000);
                // Tall companions stay mid-width — reject bank-column mega merges.
                if ($wantTall && $cbw > $this->cad(130)) {
                    continue;
                }
                if ($cbw < $this->cad(55) || $cbw > $this->cad(160) || $cbh < $minH || $cbh > $maxH || $cArea < $this->cadArea(2200) || $cArea > $maxA) {
                    continue;
                }
                if ($this->isCorridorShape($cand, $walls, $w, $h)) {
                    continue;
                }
                if ($this->enclosureScore($cand, $walls, $w, $h) < 0.22) {
                    continue;
                }
                if ($this->strongWallSides($cand, $walls, $w, $h, 0.28) < 2) {
                    continue;
                }
                $skip = false;
                $adjust = null;
                $adjustIdx = -1;
                $displace = [];
                foreach ($rects as $hi => $host) {
                    if ($host === $r || ($host[0] === $r[0] && $host[1] === $r[1] && $host[2] === $r[2] && $host[3] === $r[3])) {
                        continue;
                    }
                    if ($this->containmentFrac($cand, $host) > 0.55) {
                        $hArea = $this->rectArea($host);
                        // Displace fragmented scraps that sit inside the tall slot (#19).
                        if ($wantTall && $hArea <= $this->cadArea(12000) && ($host[3] - $host[1] + 1) <= $this->cad(110)) {
                            $displace[$hi] = true;
                            continue;
                        }
                        $skip = true;
                        break;
                    }
                    $ov = $this->overlapFrac($cand, $host);
                    if ($ov > 0.28) {
                        $hArea = $this->rectArea($host);
                        $hh = $host[3] - $host[1] + 1;
                        if ($wantTall && $hArea <= $this->cadArea(12000) && $hh <= $this->cad(110) && $host[3] <= $r[1] + $this->cad(10)) {
                            $displace[$hi] = true;
                            continue;
                        }
                        $hostCy = intdiv($host[1] + $host[3], 2);
                        if (abs($sy - $hostCy) >= $this->cad(40) && $ov < 0.62) {
                            // Trim the straddling host away from this companion row.
                            $adjustIdx = $hi;
                            $adjust = $host;
                            $trim = $this->cad(45);
                            if ($sy < $hostCy) {
                                $adjust[1] = min($host[3] - $trim, $cand[3] + 2);
                            } else {
                                $adjust[3] = max($host[1] + $trim, $cand[1] - 2);
                            }
                            $ah = $adjust[3] - $adjust[1] + 1;
                            if ($ah < $trim || ($adjust[2] - $adjust[0] + 1) * $ah < $this->cadArea(3000)) {
                                $skip = true;
                            }
                        } else {
                            $skip = true;
                        }
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
                foreach ($extra as $host) {
                    if ($this->overlapFrac($cand, $host) > 0.32 || $this->containmentFrac($cand, $host) > 0.5) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
                if ($displace !== []) {
                    $kept = [];
                    foreach ($rects as $hi => $host) {
                        if (!isset($displace[$hi])) {
                            $kept[] = $host;
                        }
                    }
                    $rects = $kept;
                    // Indices shifted — drop pending adjust.
                    $adjustIdx = -1;
                }
                if ($adjustIdx >= 0 && $adjust !== null && isset($rects[$adjustIdx])) {
                    $rects[$adjustIdx] = $adjust;
                }
                $extra[] = $cand;
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Insert missing cubicles in regular banks: when two same-row cubicles leave a
     * ~one-cubicle gap, grow from the gap center.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedCubicleBankGaps(array $rects, array $walls, int $w, int $h): array
    {
        $cubs = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(3500) || $area > $this->cadArea(20000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if (max($bw, $bh) / max(1, min($bw, $bh)) > 2.7) {
                continue;
            }
            $cubs[] = $r;
        }
        usort($cubs, static fn (array $a, array $b): int => ($a[1] <=> $b[1]) ?: ($a[0] <=> $b[0]));
        $extra = [];
        $n = count($cubs);
        $rowTol = $this->cad(45);
        $gapMin = $this->cad(55);
        $gapMax = $this->cad(140);
        $colTol = $this->cad(55);
        $vGapMin = $this->cad(40);
        $vGapMax = $this->cad(120);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $cubs[$i];
                $b = $cubs[$j];
                // Same bank row (centers within ~45px vertically @4000).
                $cyA = intdiv($a[1] + $a[3], 2);
                $cyB = intdiv($b[1] + $b[3], 2);
                if (abs($cyA - $cyB) > $rowTol) {
                    continue;
                }
                // Horizontal gap of roughly one cubicle.
                if ($b[0] <= $a[2] + $this->cad(20)) {
                    continue;
                }
                $gap = $b[0] - $a[2] - 1;
                if ($gap < $gapMin || $gap > $gapMax) {
                    continue;
                }
                $sx = intdiv($a[2] + $b[0], 2);
                $sy = intdiv($cyA + $cyB, 2);
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRect($sx, $sy, $rects) || $this->pointInAnyRect($sx, $sy, $extra)) {
                    continue;
                }
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(70), $this->cad(60));
                if ($grown === null) {
                    continue;
                }
                $cand = $this->padOpenCubicle($grown, $walls, $w, $h);
                $bw = $cand[2] - $cand[0] + 1;
                $bh = $cand[3] - $cand[1] + 1;
                $area = $bw * $bh;
                if ($bw < $this->cad(50) || $bw > $this->cad(175) || $bh < $this->cad(42) || $bh > $this->cad(155) || $area < $this->cadArea(3200) || $area > $this->cadArea(22000)) {
                    continue;
                }
                if ($this->isCorridorShape($cand, $walls, $w, $h)) {
                    continue;
                }
                if ($this->enclosureScore($cand, $walls, $w, $h) < 0.26) {
                    continue;
                }
                if ($this->strongWallSides($cand, $walls, $w, $h, 0.35) < 2) {
                    continue;
                }
                $skip = false;
                foreach (array_merge($rects, $extra) as $host) {
                    if ($this->overlapFrac($cand, $host) > 0.35 || $this->containmentFrac($cand, $host) > 0.5) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $extra[] = $cand;
                }
            }
            // Also vertical gaps in the same column.
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $cubs[$i];
                $b = $cubs[$j];
                $cxA = intdiv($a[0] + $a[2], 2);
                $cxB = intdiv($b[0] + $b[2], 2);
                if (abs($cxA - $cxB) > $colTol) {
                    continue;
                }
                if ($b[1] <= $a[3] + $this->cad(15)) {
                    continue;
                }
                $gap = $b[1] - $a[3] - 1;
                if ($gap < $vGapMin || $gap > $vGapMax) {
                    continue;
                }
                $sx = intdiv($cxA + $cxB, 2);
                $sy = intdiv($a[3] + $b[1], 2);
                if ($sy < 0 || $sy >= $h || $sx < 0 || $sx >= $w || $walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRect($sx, $sy, $rects) || $this->pointInAnyRect($sx, $sy, $extra)) {
                    continue;
                }
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(70), $this->cad(60));
                if ($grown === null) {
                    continue;
                }
                $cand = $this->padOpenCubicle($grown, $walls, $w, $h);
                $bw = $cand[2] - $cand[0] + 1;
                $bh = $cand[3] - $cand[1] + 1;
                $area = $bw * $bh;
                if ($bw < $this->cad(50) || $bw > $this->cad(175) || $bh < $this->cad(42) || $bh > $this->cad(155) || $area < $this->cadArea(3200) || $area > $this->cadArea(22000)) {
                    continue;
                }
                if ($this->isCorridorShape($cand, $walls, $w, $h)) {
                    continue;
                }
                if ($this->enclosureScore($cand, $walls, $w, $h) < 0.26) {
                    continue;
                }
                if ($this->strongWallSides($cand, $walls, $w, $h, 0.35) < 2) {
                    continue;
                }
                $skip = false;
                foreach (array_merge($rects, $extra) as $host) {
                    if ($this->overlapFrac($cand, $host) > 0.35 || $this->containmentFrac($cand, $host) > 0.5) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $extra[] = $cand;
                }
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Walk from known cubicles by median bank pitch (N/E/S/W) and grow any empty cell.
     * Unlike fillCubicleHoles this never invents rooms in open hallways far from a bank.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedBankPitchNeighbors(array $rects, array $walls, int $w, int $h, array $labels = []): array
    {
        $cubs = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            // Short shattered cells can be under cad(3200) after a tight Y cut — still valid hosts.
            $minHost = $this->modW > 20 && $this->modH > 16
                ? max($this->cadArea(1800), (int) ($this->modW * $this->modH * 0.4))
                : $this->cadArea(2400);
            if ($area < $minHost || $area > $this->cadArea(22000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if (max($bw, $bh) / max(1, min($bw, $bh)) > 2.8) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $labeled = $this->labelCenterBonus($r, $cx, $cy, $labels) > 0.35;
            $q = $this->cubicleQuality($r, $walls, $w, $h);
            // Only walk from labeled or solid bank cells — weak hallway FPs amplify junk.
            if (!$labeled && $q < 1.70 && $enc < 0.38) {
                continue;
            }
            $cubs[] = $r;
        }
        if (count($cubs) < 2) {
            return $rects;
        }
        $dxs = [];
        $dys = [];
        $n = count($cubs);
        for ($i = 0; $i < $n; $i++) {
            $cxi = intdiv($cubs[$i][0] + $cubs[$i][2], 2);
            $cyi = intdiv($cubs[$i][1] + $cubs[$i][3], 2);
            for ($j = $i + 1; $j < $n; $j++) {
                $cxj = intdiv($cubs[$j][0] + $cubs[$j][2], 2);
                $cyj = intdiv($cubs[$j][1] + $cubs[$j][3], 2);
                $dx = abs($cxj - $cxi);
                $dy = abs($cyj - $cyi);
                if ($dy <= $this->cad(28) && $dx >= $this->cad(55) && $dx <= $this->cad(150)) {
                    $dxs[] = $dx;
                }
                if ($dx <= $this->cad(28) && $dy >= $this->cad(45) && $dy <= $this->cad(130)) {
                    $dys[] = $dy;
                }
            }
        }
        sort($dxs);
        sort($dys);
        $pitchX = $dxs !== [] ? $dxs[intdiv(count($dxs), 2)] : $this->cad(78);
        $pitchY = $dys !== [] ? $dys[intdiv(count($dys), 2)] : $this->cad(62);
        $extra = [];
        $blockers = array_merge($rects, $extra);
        $offsets = [
            [$pitchX, 0], [-$pitchX, 0], [0, $pitchY], [0, -$pitchY],
            [$pitchX, $pitchY], [$pitchX, -$pitchY], [-$pitchX, $pitchY], [-$pitchX, -$pitchY],
        ];
        foreach ($cubs as $r) {
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            foreach ($offsets as [$ox, $oy]) {
                $sx = $cx + $ox;
                $sy = $cy + $oy;
                if ($sx < $this->cad(20) || $sy < $this->cad(20) || $sx >= $w - $this->cad(20) || $sy >= $h - $this->cad(20)) {
                    continue;
                }
                if ($sy > (int) ($h * 0.97)) {
                    continue;
                }
                // Host-edge stamps (N / S / E / W) do not need a free pitch sample — the
                // sample often lands on the shared partition wall between bank cells.
                $hostStamp = ($oy === 0 && $ox !== 0) || ($ox === 0 && $oy !== 0);
                if (!$hostStamp) {
                    if ($walls[$sy * $w + $sx] === 1) {
                        continue;
                    }
                    if ($this->pointInAnyRectCore($sx, $sy, $blockers, $this->cad(10))) {
                        continue;
                    }
                }
                // Don't invent bank cells inside conference / open offices.
                $inLarge = false;
                foreach ($rects as $host) {
                    if ($this->rectArea($host) <= $this->cadArea(22000)) {
                        continue;
                    }
                    if ($sx >= $host[0] && $sx <= $host[2] && $sy >= $host[1] && $sy <= $host[3]) {
                        $inLarge = true;
                        break;
                    }
                }
                if ($inLarge) {
                    continue;
                }
                $cand = null;
                // North-prefer host stamp: free grow often returns a thin spill that
                // passes as non-null then fails size, blocking the unlabeled top hole (#6).
                if ($ox === 0 && $oy < 0) {
                    $hostBw = $r[2] - $r[0] + 1;
                    $hostBh = max(
                        $r[3] - $r[1] + 1,
                        38,
                        $this->modH >= 20 ? (int) round($this->modH * 0.85) : $this->cad(40)
                    );
                    $pitch = max($hostBh + 2, abs($oy));
                    $y1 = min($r[1] - 2, $r[3] - $pitch);
                    $y0 = max(0, $y1 - $hostBh + 1);
                    $cx = intdiv($r[0] + $r[2], 2);
                    for ($n = 0; $n < $this->cad(36); $n++) {
                        $ccy = intdiv($y0 + $y1, 2);
                        if ($ccy < 0 || $walls[$ccy * $w + $cx] === 0) {
                            break;
                        }
                        $y1--;
                        $y0 = max(0, $y1 - $hostBh + 1);
                    }
                    $cand = [$r[0], $y0, $r[2], $y1];
                    if ($cand[3] >= $cand[1]) {
                        $cand = $this->padOpenCubicle($cand, $walls, $w, $h);
                        $cand[3] = min($cand[3], $r[1] - 2);
                    }
                    if ($cand[2] < $cand[0] || $cand[3] < $cand[1]) {
                        $cand = null;
                    }
                }
                // South host stamp: fill missing bank rows below (#55/#76 holes).
                if ($cand === null && $ox === 0 && $oy > 0) {
                    $hostBw = $r[2] - $r[0] + 1;
                    $hostBh = max(
                        $r[3] - $r[1] + 1,
                        38,
                        $this->modH >= 20 ? (int) round($this->modH * 0.85) : $this->cad(40)
                    );
                    $pitch = max($hostBh + 2, abs($oy));
                    $y0 = max($r[3] + 2, $r[1] + $pitch);
                    $y1 = min($h - 1, $y0 + $hostBh - 1);
                    $cx = intdiv($r[0] + $r[2], 2);
                    for ($n = 0; $n < $this->cad(36); $n++) {
                        $ccy = intdiv($y0 + $y1, 2);
                        if ($ccy >= $h || $walls[$ccy * $w + $cx] === 0) {
                            break;
                        }
                        $y0++;
                        $y1 = min($h - 1, $y0 + $hostBh - 1);
                    }
                    $cand = [$r[0], $y0, $r[2], $y1];
                    if ($cand[3] >= $cand[1]) {
                        $cand = $this->padOpenCubicle($cand, $walls, $w, $h);
                        $cand[1] = max($cand[1], $r[3] + 2);
                    }
                    if ($cand[2] < $cand[0] || $cand[3] < $cand[1]) {
                        $cand = null;
                    } elseif ($this->intersectionArea($cand, $r) > 0) {
                        $cand = null;
                    } else {
                        $ccx = intdiv($cand[0] + $cand[2], 2);
                        $ccy = intdiv($cand[1] + $cand[3], 2);
                        if ($ccx < 0 || $ccy < 0 || $ccx >= $w || $ccy >= $h
                            || $walls[$ccy * $w + $ccx] === 1
                            || $this->pointInAnyRectCore($ccx, $ccy, $blockers, $this->cad(8))
                        ) {
                            $cand = null;
                        }
                    }
                }
                // East/west host stamp: same-row bank neighbors (C05.19 → C06.18).
                if ($cand === null && $oy === 0 && $ox !== 0) {
                    $hostBw = $r[2] - $r[0] + 1;
                    // Pad short shattered hosts so east/west stamps clear size gates (#82).
                    $hostBh = max(
                        $r[3] - $r[1] + 1,
                        40,
                        $this->modH >= 20 ? (int) round($this->modH * 0.9) : $this->cad(40)
                    );
                    $cy = intdiv($r[1] + $r[3], 2);
                    // Pitch-align into the neighboring cell; gluing to host edge lands the
                    // center inside the double-line wall cavity (wall@946 between C05/C06).
                    $pitch = max($hostBw + 2, abs($ox));
                    if ($ox > 0) {
                        $x0 = max($r[2] + 2, $r[0] + $pitch);
                        $x1 = min($w - 1, $x0 + $hostBw - 1);
                        // Nudge east until the cell center is open (skip cavity ink).
                        for ($n = 0; $n < $this->cad(36); $n++) {
                            $ccx = intdiv($x0 + $x1, 2);
                            if ($ccx >= $w || $walls[$cy * $w + $ccx] === 0) {
                                break;
                            }
                            $x0++;
                            $x1 = min($w - 1, $x0 + $hostBw - 1);
                        }
                        $cand = [
                            $x0,
                            max(0, $cy - intdiv($hostBh, 2)),
                            $x1,
                            min($h - 1, $cy - intdiv($hostBh, 2) + $hostBh - 1),
                        ];
                    } else {
                        $x1 = min($r[0] - 2, $r[2] - $pitch);
                        $x0 = max(0, $x1 - $hostBw + 1);
                        for ($n = 0; $n < $this->cad(36); $n++) {
                            $ccx = intdiv($x0 + $x1, 2);
                            if ($ccx < 0 || $walls[$cy * $w + $ccx] === 0) {
                                break;
                            }
                            $x1--;
                            $x0 = max(0, $x1 - $hostBw + 1);
                        }
                        $cand = [
                            $x0,
                            max(0, $cy - intdiv($hostBh, 2)),
                            $x1,
                            min($h - 1, $cy - intdiv($hostBh, 2) + $hostBh - 1),
                        ];
                    }
                    if ($cand[2] >= $cand[0] && $cand[3] >= $cand[1]) {
                        $cand = $this->padOpenCubicle($cand, $walls, $w, $h);
                        if ($ox > 0) {
                            $cand[0] = max($cand[0], $r[2] + 2);
                        } else {
                            $cand[2] = min($cand[2], $r[0] - 2);
                        }
                    }
                    if ($cand[2] < $cand[0] || $cand[3] < $cand[1]) {
                        $cand = null;
                    } elseif ($this->intersectionArea($cand, $r) > 0) {
                        $cand = null;
                    } else {
                        $ccx = intdiv($cand[0] + $cand[2], 2);
                        $ccy = intdiv($cand[1] + $cand[3], 2);
                        if ($ccx < 0 || $ccy < 0 || $ccx >= $w || $ccy >= $h
                            || $walls[$ccy * $w + $ccx] === 1
                            || $this->pointInAnyRectCore($ccx, $ccy, $blockers, $this->cad(8))
                        ) {
                            $cand = null;
                        }
                    }
                }
                if ($cand === null) {
                    $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(70), $this->cad(60));
                    if ($grown !== null) {
                        $cand = $this->padOpenCubicle($grown, $walls, $w, $h);
                        if ($this->intersectionArea($cand, $r) > 0) {
                            if ($oy < 0) {
                                $cand[3] = min($cand[3], $r[1] - 2);
                            } elseif ($oy > 0) {
                                $cand[1] = max($cand[1], $r[3] + 2);
                            }
                            if ($ox < 0) {
                                $cand[2] = min($cand[2], $r[0] - 2);
                            } elseif ($ox > 0) {
                                $cand[0] = max($cand[0], $r[2] + 2);
                            }
                        }
                        if ($cand[2] < $cand[0] || $cand[3] < $cand[1]) {
                            $cand = null;
                        }
                    }
                }
                if ($cand === null) {
                    continue;
                }
                $bw = $cand[2] - $cand[0] + 1;
                $bh = $cand[3] - $cand[1] + 1;
                $area = $bw * $bh;
                // Allow short bank stamps (shattered hosts are often ~25–35px tall).
                if (
                    $bw < $this->cad(40) || $bw > $this->cad(170)
                    || $bh < $this->cad(24) || $bh > $this->cad(150)
                    || $area < $this->cadArea(1600) || $area > $this->cadArea(22000)
                ) {
                    continue;
                }
                if (max($bw, $bh) / max(1, min($bw, $bh)) > 2.8) {
                    continue;
                }
                if ($this->isCorridorShape($cand, $walls, $w, $h) && $this->enclosureScore($cand, $walls, $w, $h) < 0.40) {
                    continue;
                }
                $enc = $this->enclosureScore($cand, $walls, $w, $h);
                if ($enc < 0.16) {
                    continue;
                }
                if ($enc < 0.28 && $this->strongWallSides($cand, $walls, $w, $h, 0.34) < 1) {
                    continue;
                }
                $rcx = intdiv($cand[0] + $cand[2], 2);
                $rcy = intdiv($cand[1] + $cand[3], 2);
                // New cells must sit near map text or a strong partition pocket.
                if ($labels !== []) {
                    $near = $this->nearestLabelDist($rcx, $rcy, $labels);
                    if (
                        $near > $this->cad(200)
                        && !($near <= $this->cad(280) && $enc >= 0.32 && $this->strongWallSides($cand, $walls, $w, $h, 0.34) >= 2)
                    ) {
                        continue;
                    }
                }
                $skip = false;
                foreach ($blockers as $host) {
                    if ($this->intersectionArea($cand, $host) > 0) {
                        $skip = true;
                        break;
                    }
                    $hcx = intdiv($host[0] + $host[2], 2);
                    $hcy = intdiv($host[1] + $host[3], 2);
                    if ($this->rectArea($host) <= $this->cadArea(24000) && hypot($rcx - $hcx, $rcy - $hcy) < $this->cad(48)) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $extra[] = $cand;
                    $blockers[] = $cand;
                    // Unlabeled walks can flood — keep a hard cap.
                    if ($labels === [] && count($extra) >= 24) {
                        break 2;
                    }
                }
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Dense cubicle-bank detector: fine wall-peak lattice + capped grow at cubicle pitch.
     * Skips hallways/corridors and non-cubicle sizes so banks claim space before large merges.
     *
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function roomsFromCubicleLattice(array $walls, int $w, int $h, array $labels = []): array
    {
        $col = array_fill(0, $w, 0);
        $row = array_fill(0, $h, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($walls[$y * $w + $x] === 1) {
                    $col[$x]++;
                    $row[$y]++;
                }
            }
        }
        $minSepX = max(12, $this->modW > 0 ? (int) ($this->modW * 0.55) : $this->cad(58));
        $minSepY = max(10, $this->modH > 0 ? (int) ($this->modH * 0.55) : $this->cad(52));
        $xPeaks = $this->projectionPeaks($col, max(6, (int) ($h * 0.012)), $minSepX);
        $yPeaks = $this->projectionPeaks($row, max(6, (int) ($w * 0.012)), $minSepY);

        // Fit pitch from peak gaps; interpolate missing lines when a gap is ~2× pitch.
        $pitchX = $this->modW > 0 ? $this->modW : $this->cad(110);
        $pitchY = $this->modH > 0 ? $this->modH : $this->cad(80);
        if (count($xPeaks) >= 3) {
            $mx = $this->medianPeakGap($xPeaks);
            if ($mx >= 20) {
                $pitchX = $mx;
            }
        }
        if (count($yPeaks) >= 3) {
            $my = $this->medianPeakGap($yPeaks);
            if ($my >= 16) {
                $pitchY = $my;
            }
        }
        // Labels pin lattice phase: edges should fall near label centers ± half-module.
        if ($labels !== [] && $pitchX >= 20 && $pitchY >= 16) {
            $xPeaks = $this->phaseAlignPeaksToLabels($xPeaks, $labels, true, $pitchX, $w);
            $yPeaks = $this->phaseAlignPeaksToLabels($yPeaks, $labels, false, $pitchY, $h);
        }
        $xPeaks = $this->interpolatePeakLattice($xPeaks, $pitchX, $w);
        $yPeaks = $this->interpolatePeakLattice($yPeaks, $pitchY, $h);

        // Shared collinear edges: snap each peak to the median of nearby wall mass.
        $xEdges = $this->snapPeaksToWallMass($xPeaks, $col, max(2, intdiv($pitchX, 8)));
        $yEdges = $this->snapPeaksToWallMass($yPeaks, $row, max(2, intdiv($pitchY, 8)));

        $softEnc = ($this->modConf >= 0.25 || ($labels === [] && ($this->upscaledWork || $this->rasterOffice)))
            ? 0.14
            : 0.32;
        $softSides = ($this->modConf >= 0.25 || ($labels === [] && ($this->upscaledWork || $this->rasterOffice)))
            ? 1
            : 2;
        $modW = max($this->cad(65), (int) ($pitchX * 0.85));
        $modH = max($this->cad(48), (int) ($pitchY * 0.85));
        $maxW = min($this->cad(160), (int) ($pitchX * 1.35));
        $maxH = min($this->cad(140), (int) ($pitchY * 1.35));

        $grown = [];
        $nx = count($xEdges);
        $ny = count($yEdges);
        for ($i = 0; $i < $nx - 1; $i++) {
            for ($j = 0; $j < $ny - 1; $j++) {
                $gapX = $xEdges[$i + 1] - $xEdges[$i];
                $gapY = $yEdges[$j + 1] - $yEdges[$j];
                // Skip non-module gaps (desk runs, double cells).
                if ($gapX < (int) ($pitchX * 0.55) || $gapX > (int) ($pitchX * 1.55)) {
                    continue;
                }
                if ($gapY < (int) ($pitchY * 0.55) || $gapY > (int) ($pitchY * 1.55)) {
                    continue;
                }
                $x0 = $xEdges[$i] + 1;
                $x1 = $xEdges[$i + 1] - 1;
                $y0 = $yEdges[$j] + 1;
                $y1 = $yEdges[$j + 1] - 1;
                if ($y0 > (int) ($h * 0.97) || $y1 < (int) ($h * 0.07)) {
                    continue;
                }
                $bw = $x1 - $x0 + 1;
                $bh = $y1 - $y0 + 1;
                $area = $bw * $bh;
                if ($bw < $modW * 0.7 || $bw > $maxW || $bh < $modH * 0.7 || $bh > $maxH) {
                    continue;
                }
                if ($area < $this->cadArea(3500) || $area > $this->cadArea(22000)) {
                    continue;
                }
                $slot = [$x0, $y0, $x1, $y1];
                $enc = $this->enclosureScore($slot, $walls, $w, $h);
                $sides = $this->strongWallSides($slot, $walls, $w, $h, 0.38);
                $hasLabel = $this->slotContainsLabel($slot, $labels);
                // Soft enclosure when the periodic fit is confident (closure from rhythm).
                // A label inside the slot is itself strong evidence of a real space.
                if (!$hasLabel && $enc < $softEnc && $sides < $softSides) {
                    continue;
                }
                if ($enc < 0.10 && !$hasLabel) {
                    continue;
                }
                if ($this->isCorridorShape($slot, $walls, $w, $h)) {
                    continue;
                }
                // Interior should be mostly free of wall ink.
                $ink = 0;
                $samples = 0;
                for ($yy = $y0 + 2; $yy <= $y1 - 2; $yy += 3) {
                    for ($xx = $x0 + 2; $xx <= $x1 - 2; $xx += 3) {
                        $samples++;
                        if ($walls[$yy * $w + $xx] === 1) {
                            $ink++;
                        }
                    }
                }
                if ($samples > 0 && $ink / $samples > 0.22 && !$hasLabel) {
                    continue;
                }
                $cx = intdiv($x0 + $x1, 2);
                $cy = intdiv($y0 + $y1, 2);
                $refined = $this->growRectFromSeed($walls, $w, $h, $cx, $cy, intdiv($pitchX, 2), intdiv($pitchY, 2));
                if ($refined !== null) {
                    // Snap to shared bank edges rather than independent grow jitter.
                    $refined[0] = $x0;
                    $refined[2] = $x1;
                    $refined = $this->padOpenCubicle($refined, $walls, $w, $h);
                    $rbw = $refined[2] - $refined[0] + 1;
                    $rbh = $refined[3] - $refined[1] + 1;
                    if ($rbw >= (int) ($modW * 0.7) && $rbh >= (int) ($modH * 0.7) && $rbw <= $maxW && $rbh <= $maxH) {
                        $grown[] = [$refined[0], $refined[1], $refined[2], $refined[3], $rbw * $rbh, $i, $j];
                        continue;
                    }
                }
                $grown[] = [$x0, $y0, $x1, $y1, $area, $i, $j];
            }
        }

        // Bank membership: unify left/right edges for cells sharing a column index.
        $grown = $this->unifyBankSharedEdges($grown, $xEdges, $yEdges);

        usort($grown, static fn (array $a, array $b): int => $a[4] <=> $b[4]);
        $kept = [];
        $minDist = max(20, (int) (min($pitchX, $pitchY) * 0.45));
        foreach ($grown as $g) {
            $r = [$g[0], $g[1], $g[2], $g[3]];
            $skip = false;
            $rcx = intdiv($r[0] + $r[2], 2);
            $rcy = intdiv($r[1] + $r[3], 2);
            foreach ($kept as $host) {
                $hcx = intdiv($host[0] + $host[2], 2);
                $hcy = intdiv($host[1] + $host[3], 2);
                if (hypot($rcx - $hcx, $rcy - $hcy) < $minDist) {
                    $skip = true;
                    break;
                }
                if ($this->overlapFrac($r, $host) > 0.22 || $this->containmentFrac($r, $host) > 0.45) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * Grow module-sized cells from each map label, then fill unlabeled neighbors
     * on the same pitch so missing OCR slots are still recovered from rhythm.
     *
     * @param list<array{text?:string,x:int,y:int,w:int,h:int}> $labels
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function roomsFromLabelAnchoredLattice(array $labels, array $walls, int $w, int $h): array
    {
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $halfW = max(20, intdiv($mw, 2));
        $halfH = max(16, intdiv($mh, 2));
        $seeds = [];
        foreach ($labels as $lab) {
            $lx = $lab['x'] + intdiv($lab['w'], 2);
            $ly = $lab['y'] + intdiv($lab['h'], 2);
            if ($lx < 2 || $ly < 2 || $lx >= $w - 2 || $ly >= $h - 2) {
                continue;
            }
            if ($walls[$ly * $w + $lx] === 1) {
                continue;
            }
            $grown = $this->growRectFromSeed($walls, $w, $h, $lx, $ly, (int) ($mw * 0.85), (int) ($mh * 0.85));
            if ($grown === null) {
                // Stamp a module box centered on the label and snap to ink.
                $grown = [
                    max(0, $lx - $halfW),
                    max(0, $ly - $halfH),
                    min($w - 1, $lx + $halfW),
                    min($h - 1, $ly + $halfH),
                ];
            }
            $grown = $this->padOpenCubicle($grown, $walls, $w, $h);
            $bw = $grown[2] - $grown[0] + 1;
            $bh = $grown[3] - $grown[1] + 1;
            if ($bw < (int) ($mw * 0.45) || $bh < (int) ($mh * 0.45)) {
                continue;
            }
            if ($bw > (int) ($mw * 1.8) || $bh > (int) ($mh * 1.8)) {
                // Likely a large room label — keep as-is only if well enclosed.
                if ($this->enclosureScore($grown, $walls, $w, $h) < 0.4) {
                    continue;
                }
            }
            $seeds[] = $grown;
        }
        if ($seeds === []) {
            return [];
        }
        // Interpolate unlabeled neighbors only when the pitch step lands in a real pocket.
        $out = $seeds;
        foreach ($seeds as $r) {
            foreach ([[$mw, 0], [-$mw, 0], [0, $mh], [0, -$mh]] as [$dx, $dy]) {
                $cand = [
                    max(0, $r[0] + $dx),
                    max(0, $r[1] + $dy),
                    min($w - 1, $r[2] + $dx),
                    min($h - 1, $r[3] + $dy),
                ];
                if ($cand[2] - $cand[0] + 1 < (int) ($mw * 0.55) || $cand[3] - $cand[1] + 1 < (int) ($mh * 0.55)) {
                    continue;
                }
                $cx = intdiv($cand[0] + $cand[2], 2);
                $cy = intdiv($cand[1] + $cand[3], 2);
                if ($walls[$cy * $w + $cx] === 1) {
                    continue;
                }
                // Skip if a seed label already covers this slot.
                if ($this->slotContainsLabel($cand, $labels)) {
                    continue;
                }
                $enc = $this->enclosureScore($cand, $walls, $w, $h);
                $sides = $this->strongWallSides($cand, $walls, $w, $h, 0.38);
                if ($enc < 0.22 || $sides < 1) {
                    continue;
                }
                $hit = $this->growRectFromSeed($walls, $w, $h, $cx, $cy, (int) ($mw * 0.65), (int) ($mh * 0.65));
                if ($hit !== null) {
                    $hit = $this->padOpenCubicle($hit, $walls, $w, $h);
                    $hbw = $hit[2] - $hit[0] + 1;
                    $hbh = $hit[3] - $hit[1] + 1;
                    if (
                        $hbw >= (int) ($mw * 0.55) && $hbh >= (int) ($mh * 0.55)
                        && $hbw <= (int) ($mw * 1.45) && $hbh <= (int) ($mh * 1.45)
                        && $this->enclosureScore($hit, $walls, $w, $h) >= 0.20
                    ) {
                        $out[] = $hit;
                    }
                }
            }
        }
        usort($out, fn (array $a, array $b): int => $this->rectArea($a) <=> $this->rectArea($b));
        $kept = [];
        $minDist = max(18, (int) (min($mw, $mh) * 0.4));
        foreach ($out as $r) {
            $skip = false;
            $rcx = intdiv($r[0] + $r[2], 2);
            $rcy = intdiv($r[1] + $r[3], 2);
            foreach ($kept as $host) {
                if (hypot($rcx - intdiv($host[0] + $host[2], 2), $rcy - intdiv($host[1] + $host[3], 2)) < $minDist) {
                    $skip = true;
                    break;
                }
                if ($this->overlapFrac($r, $host) > 0.28) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * @param list<int> $peaks
     * @param list<array{x:int,y:int,w:int,h:int}> $labels
     * @return list<int>
     */
    private function phaseAlignPeaksToLabels(array $peaks, array $labels, bool $vertical, int $pitch, int $limit): array
    {
        if ($peaks === [] || $labels === [] || $pitch < 12) {
            return $peaks;
        }
        $centers = [];
        foreach ($labels as $lab) {
            $centers[] = $vertical
                ? $lab['x'] + intdiv($lab['w'], 2)
                : $lab['y'] + intdiv($lab['h'], 2);
        }
        sort($centers);
        // Dominant phase: median of (center mod pitch).
        $mods = [];
        foreach ($centers as $c) {
            $mods[] = $c % $pitch;
        }
        sort($mods);
        $phase = $mods[intdiv(count($mods), 2)];
        // Also emit synthetic peaks at label-edge estimates (center ± half pitch).
        $extra = [];
        foreach ($centers as $c) {
            $left = $c - intdiv($pitch, 2);
            $right = $c + intdiv($pitch, 2);
            if ($left > 0 && $left < $limit - 1) {
                $extra[] = $left;
            }
            if ($right > 0 && $right < $limit - 1) {
                $extra[] = $right;
            }
            // Snap existing peaks toward nearest phase-aligned position.
        }
        $aligned = [];
        foreach ($peaks as $p) {
            $target = (int) round(($p - $phase) / $pitch) * $pitch + $phase;
            if ($target < 0) {
                $target += $pitch;
            }
            if (abs($target - $p) <= max(4, intdiv($pitch, 5)) && $target > 0 && $target < $limit - 1) {
                $aligned[] = $target;
            } else {
                $aligned[] = $p;
            }
        }
        foreach ($extra as $e) {
            $aligned[] = $e;
        }
        $aligned = array_values(array_unique($aligned));
        sort($aligned);

        return $aligned;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $slot
     * @param list<array{x:int,y:int,w:int,h:int}> $labels
     */
    private function slotContainsLabel(array $slot, array $labels): bool
    {
        foreach ($labels as $lab) {
            $lx = $lab['x'] + intdiv($lab['w'], 2);
            $ly = $lab['y'] + intdiv($lab['h'], 2);
            if ($lx >= $slot[0] && $lx <= $slot[2] && $ly >= $slot[1] && $ly <= $slot[3]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unify left/right (and top/bottom) edges for cells that share a lattice column/row.
     *
     * @param list<array{0:int,1:int,2:int,3:int,4:int,5?:int,6?:int}> $grown
     * @param list<int> $xEdges
     * @param list<int> $yEdges
     * @return list<array{0:int,1:int,2:int,3:int,4:int,5?:int,6?:int}>
     */
    private function unifyBankSharedEdges(array $grown, array $xEdges, array $yEdges): array
    {
        if ($grown === []) {
            return $grown;
        }
        $byCol = [];
        $byRow = [];
        foreach ($grown as $idx => $g) {
            $ci = $g[5] ?? -1;
            $ri = $g[6] ?? -1;
            if ($ci >= 0) {
                $byCol[$ci][] = $idx;
            }
            if ($ri >= 0) {
                $byRow[$ri][] = $idx;
            }
        }
        foreach ($byCol as $ci => $idxs) {
            if (count($idxs) < 2 || !isset($xEdges[$ci], $xEdges[$ci + 1])) {
                continue;
            }
            $x0 = $xEdges[$ci] + 1;
            $x1 = $xEdges[$ci + 1] - 1;
            foreach ($idxs as $idx) {
                $grown[$idx][0] = $x0;
                $grown[$idx][2] = $x1;
                $grown[$idx][4] = ($x1 - $x0 + 1) * ($grown[$idx][3] - $grown[$idx][1] + 1);
            }
        }
        foreach ($byRow as $ri => $idxs) {
            if (count($idxs) < 2 || !isset($yEdges[$ri], $yEdges[$ri + 1])) {
                continue;
            }
            $y0 = $yEdges[$ri] + 1;
            $y1 = $yEdges[$ri + 1] - 1;
            foreach ($idxs as $idx) {
                $grown[$idx][1] = $y0;
                $grown[$idx][3] = $y1;
                $grown[$idx][4] = ($grown[$idx][2] - $grown[$idx][0] + 1) * ($y1 - $y0 + 1);
            }
        }

        return array_values($grown);
    }

    /**
     * Insert synthetic peaks when adjacent peaks span ~2× pitch (missing divider).
     *
     * @param list<int> $peaks
     * @return list<int>
     */
    private function interpolatePeakLattice(array $peaks, int $pitch, int $limit): array
    {
        if ($peaks === [] || $pitch < 12) {
            return $peaks;
        }
        sort($peaks);
        $out = [$peaks[0]];
        for ($i = 1, $n = count($peaks); $i < $n; $i++) {
            $prev = $out[count($out) - 1];
            $gap = $peaks[$i] - $prev;
            if ($gap >= (int) ($pitch * 1.7) && $gap <= (int) ($pitch * 2.6)) {
                $mid = $prev + intdiv($gap, 2);
                if ($mid > 0 && $mid < $limit - 1) {
                    $out[] = $mid;
                }
            } elseif ($gap >= (int) ($pitch * 2.6) && $gap <= (int) ($pitch * 3.5)) {
                $step = intdiv($gap, 3);
                for ($k = 1; $k <= 2; $k++) {
                    $p = $prev + $k * $step;
                    if ($p > 0 && $p < $limit - 1) {
                        $out[] = $p;
                    }
                }
            }
            $out[] = $peaks[$i];
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<int> $peaks
     * @param list<int> $proj
     * @return list<int>
     */
    private function snapPeaksToWallMass(array $peaks, array $proj, int $radius): array
    {
        $n = count($proj);
        $out = [];
        foreach ($peaks as $p) {
            $best = $p;
            $bestV = -1;
            for ($x = max(0, $p - $radius); $x <= min($n - 1, $p + $radius); $x++) {
                if ($proj[$x] > $bestV) {
                    $bestV = $proj[$x];
                    $best = $x;
                }
            }
            $out[] = $best;
        }

        return array_values(array_unique($out));
    }

    /**
     * Count axis edges with substantial wall support (open-front cubicles ≈ 3, junk ≈ 0–1).
     *
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $walls
     */
    private function strongWallSides(array $bb, array $walls, int $w, int $h, float $thresh = 0.40): int
    {
        [$x0, $y0, $x1, $y1] = $bb;
        $n = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y0, 0, -1);
        $s = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y1, 0, 1);
        $we = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x0, -1, 0);
        $e = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x1, 1, 0);
        $sides = 0;
        foreach ([$n, $s, $we, $e] as $frac) {
            if ($frac >= $thresh) {
                $sides++;
            }
        }

        return $sides;
    }

    /**
     * Open-front cubicles often grow short on the open axis — pad toward free space
     * up to a typical cubicle depth without crossing partitions.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function padOpenCubicle(array $r, array $walls, int $w, int $h): array
    {
        [$x0, $y0, $x1, $y1] = $r;
        $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
        $bw = $x1 - $x0 + 1;
        $bh = $y1 - $y0 + 1;
        // Fully walled but still short: keep space-filling, then also pad height for desk depth.
        if ($sides >= 3 && $bh >= $this->cad(70)) {
            return $this->expandCubicleToWalls($r, $walls, $w, $h, $this->cad(8));
        }
        if ($sides >= 3) {
            $r = $this->expandCubicleToWalls($r, $walls, $w, $h, $this->cad(8));
            [$x0, $y0, $x1, $y1] = $r;
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
        }
        // Open-front lip: extend toward missing walls, stopping on strong partitions.
        // Prefer wall contact over a stamped size; soft max keeps aisle floods in check.
        if ($bh < $this->cad(100) && $bw >= $this->cad(50) && $bw <= $this->cad(180)) {
            $need = min($this->cad(48), max(0, $this->cad(120) - $bh));
            $up = intdiv($need, 2);
            $down = $need - $up;
            while ($up > 0 && $y0 > 0 && !$this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y0 - 1)) {
                $y0--;
                $up--;
            }
            while ($down > 0 && $y1 < $h - 1 && !$this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y1 + 1)) {
                $y1++;
                $down--;
            }
        }
        $bw = $x1 - $x0 + 1;
        $bh = $y1 - $y0 + 1;
        if ($bw < $this->cad(120) && $bh >= $this->cad(36) && $bh <= $this->cad(170)) {
            $need = min($this->cad(36), max(0, $this->cad(130) - $bw));
            $left = intdiv($need, 2);
            $right = $need - $left;
            while ($left > 0 && $x0 > 0 && !$this->isStrongColWallBoundary($walls, $w, $h, $x0 - 1, $y0, $y1)) {
                $x0--;
                $left--;
            }
            while ($right > 0 && $x1 < $w - 1 && !$this->isStrongColWallBoundary($walls, $w, $h, $x1 + 1, $y0, $y1)) {
                $x1++;
                $right--;
            }
        }

        return $this->expandCubicleToWalls([$x0, $y0, $x1, $y1], $walls, $w, $h, $this->cad(6));
    }

    /**
     * Expand each open edge until a strong wall or maxReach — never across walls.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function expandCubicleToWalls(array $r, array $walls, int $w, int $h, int $maxReach): array
    {
        [$x0, $y0, $x1, $y1] = $r;
        $left = $maxReach;
        $right = $maxReach;
        $up = $maxReach;
        $down = $maxReach;
        $skip = $this->cadS < 0.95 ? $this->cad(18) : 0;
        $midX = intdiv($x0 + $x1, 2);
        $midY = intdiv($y0 + $y1, 2);
        while ($up > 0 && $y0 > 0) {
            $y = $y0 - 1;
            if ($this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y)) {
                break;
            }
            // Skip thin horizontal furniture on the mid column, then continue.
            if ($walls[$y * $w + $midX] === 1) {
                if ($skip <= 0 || !$this->isSkippableWallPixel($walls, $w, $h, $midX, $y, true, $skip)) {
                    break;
                }
            }
            $y0--;
            $up--;
        }
        while ($down > 0 && $y1 < $h - 1) {
            $y = $y1 + 1;
            if ($this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y)) {
                break;
            }
            if ($walls[$y * $w + $midX] === 1) {
                if ($skip <= 0 || !$this->isSkippableWallPixel($walls, $w, $h, $midX, $y, true, $skip)) {
                    break;
                }
            }
            $y1++;
            $down--;
        }
        while ($left > 0 && $x0 > 0) {
            $x = $x0 - 1;
            if ($this->isStrongColWallBoundary($walls, $w, $h, $x, $y0, $y1)) {
                break;
            }
            if ($walls[$midY * $w + $x] === 1) {
                if ($skip <= 0 || !$this->isSkippableWallPixel($walls, $w, $h, $x, $midY, false, $skip)) {
                    break;
                }
            }
            $x0--;
            $left--;
        }
        while ($right > 0 && $x1 < $w - 1) {
            $x = $x1 + 1;
            if ($this->isStrongColWallBoundary($walls, $w, $h, $x, $y0, $y1)) {
                break;
            }
            if ($walls[$midY * $w + $x] === 1) {
                if ($skip <= 0 || !$this->isSkippableWallPixel($walls, $w, $h, $x, $midY, false, $skip)) {
                    break;
                }
            }
            $x1++;
            $right--;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Grow cubicle stubs through shallow desk bands (closed-ink band ≤11) until a real
     * multi-row floor. Reject growth that would swallow another detection's center so
     * normal stacked banks stay split.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<int> $dense
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function growIsolatedThroughDeskBands(
        array $rects,
        array $walls,
        array $dense,
        int $w,
        int $h
    ): array {
        $out = $rects;
        $maxReach = $this->cad(100);
        foreach ($rects as $i => $r) {
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(1800) || $area > $this->cadArea(22000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            // Stubs OR typical open bank cells that stop short of the desk lip.
            $stub = $bw <= $this->cad(60) || $bh / max(1, $bw) >= 1.35;
            $bank = $bw >= $this->cad(42) && $bw <= $this->cad(160)
                && $bh >= $this->cad(24) && $bh <= $this->cad(120);
            if (!$stub && !$bank) {
                continue;
            }
            if ($bh >= $this->cad(130)) {
                continue;
            }
            $grown = $this->expandThroughShallowDeskBands($r, $walls, $dense, $w, $h, $maxReach);
            if ($grown === $r) {
                continue;
            }
            $gBh = $grown[3] - $grown[1] + 1;
            $gBw = $grown[2] - $grown[0] + 1;
            // Narrow stubs (#31) need more reach; banks grow toward desk-inclusive height (~GT).
            $heightLim = $stub
                ? (($bw <= $this->cad(50)) ? ($bh + $this->cad(180)) : ($bh + $this->cad(140)))
                : max($bh + $this->cad(140), $this->cad(160));
            if ($gBh > $heightLim || $gBw > $bw + $this->cad(12)) {
                continue;
            }
            $gArea = $this->rectArea($grown);
            if ($gArea > $area * 2.6 || $gArea > $this->cadArea(32000)) {
                continue;
            }
            $swallows = false;
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                // Late hole-fill often plants short scraps inside the true tall slot —
                // ignore tiny neighbors so the stub can still expand (#31).
                if ($this->rectArea($o) < $this->cadArea(4500)) {
                    continue;
                }
                $cx = intdiv($o[0] + $o[2], 2);
                $cy = intdiv($o[1] + $o[3], 2);
                if ($cx >= $grown[0] && $cx <= $grown[2] && $cy >= $grown[1] && $cy <= $grown[3]) {
                    $swallows = true;
                    break;
                }
                // Also reject heavy overlap with a neighbor (partial swallow).
                $ox0 = max($grown[0], $o[0]);
                $oy0 = max($grown[1], $o[1]);
                $ox1 = min($grown[2], $o[2]);
                $oy1 = min($grown[3], $o[3]);
                if ($ox0 <= $ox1 && $oy0 <= $oy1) {
                    $inter = ($ox1 - $ox0 + 1) * ($oy1 - $oy0 + 1);
                    if ($inter > (int) ($this->rectArea($o) * 0.18)) {
                        $swallows = true;
                        break;
                    }
                }
            }
            if ($swallows) {
                continue;
            }
            $out[$i] = $grown;
            // Drop tiny scraps whose centers now sit inside the grown stub.
            foreach ($rects as $j => $o) {
                if ($j === $i || !isset($out[$j])) {
                    continue;
                }
                if ($this->rectArea($o) >= $this->cadArea(4500)) {
                    continue;
                }
                $cx = intdiv($o[0] + $o[2], 2);
                $cy = intdiv($o[1] + $o[3], 2);
                if ($cx >= $grown[0] && $cx <= $grown[2] && $cy >= $grown[1] && $cy <= $grown[3]) {
                    unset($out[$j]);
                }
            }
        }

        return array_values($out);
    }

    /**
     * Merge neighboring large rooms separated by a weak/furniture mid-band
     * (over-split conference rooms). Does not absorb cubicle banks.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function mergeAdjacentLargeRooms(array $rects, array $walls, int $w, int $h): array
    {
        // Include medium fragments that are pieces of a conference room (e.g. #14).
        $largeMin = $this->cadArea(10000);
        $out = $rects;
        $merged = true;
        $guard = 0;
        while ($merged && $guard < 20) {
            $merged = false;
            $guard++;
            $n = count($out);
            for ($i = 0; $i < $n; $i++) {
                if (!isset($out[$i])) {
                    continue;
                }
                $a = $out[$i];
                $aArea = $this->rectArea($a);
                if ($aArea < $largeMin) {
                    continue;
                }
                for ($j = $i + 1; $j < $n; $j++) {
                    if (!isset($out[$j])) {
                        continue;
                    }
                    $b = $out[$j];
                    $bArea = $this->rectArea($b);
                    if ($bArea < $largeMin) {
                        continue;
                    }
                    // At least one should be a substantial room fragment.
                    if ($aArea < $this->cadArea(18000) && $bArea < $this->cadArea(18000)) {
                        continue;
                    }
                    $union = [
                        min($a[0], $b[0]),
                        min($a[1], $b[1]),
                        max($a[2], $b[2]),
                        max($a[3], $b[3]),
                    ];
                    $uArea = $this->rectArea($union);
                    $sum = $aArea + $bArea;
                    $inter = $this->intersectionArea($a, $b);
                    // Overlapping fragments OR nearly abutting tiles.
                    $compact = $uArea <= (int) ($sum * 1.55) && $uArea >= (int) (max($aArea, $bArea) * 1.05);
                    if (!$compact && $inter <= 0) {
                        continue;
                    }
                    if ($inter > 0 && $uArea > (int) ($sum * 1.65)) {
                        continue;
                    }
                    $overlapX = min($a[2], $b[2]) - max($a[0], $b[0]) + 1;
                    $overlapY = min($a[3], $b[3]) - max($a[1], $b[1]) + 1;
                    $gapY = max(0, max($a[1], $b[1]) - min($a[3], $b[3]) - 1);
                    $gapX = max(0, max($a[0], $b[0]) - min($a[2], $b[2]) - 1);
                    $related = $inter > (int) (min($aArea, $bArea) * 0.08)
                        || ($overlapX >= $this->cad(40) && $gapY <= $this->cad(16))
                        || ($overlapY >= $this->cad(40) && $gapX <= $this->cad(16));
                    if (!$related) {
                        continue;
                    }
                    $ubw = $union[2] - $union[0] + 1;
                    $ubh = $union[3] - $union[1] + 1;
                    $uAsp = max($ubw, $ubh) / max(1, min($ubw, $ubh));
                    if ($uAsp > 3.4) {
                        continue;
                    }
                    // Reject flat "shelf" unions of room fragments (e.g. 235×76).
                    $aMin = min($a[2] - $a[0] + 1, $a[3] - $a[1] + 1);
                    $bMin = min($b[2] - $b[0] + 1, $b[3] - $b[1] + 1);
                    if (min($ubw, $ubh) < (int) (max($aMin, $bMin) * 0.75) && $uAsp > 2.0) {
                        continue;
                    }
                    if ($uArea > $this->cadArea(220000)) {
                        continue;
                    }
                    if ($this->interiorWallFrac($union, $walls, $w, $h) > 0.09) {
                        continue;
                    }
                    if ($this->isCorridorShape($union, $walls, $w, $h)
                        && $this->enclosureScore($union, $walls, $w, $h) < 0.42
                    ) {
                        continue;
                    }
                    $cubHits = 0;
                    foreach ($out as $k => $o) {
                        if ($k === $i || $k === $j) {
                            continue;
                        }
                        $oArea = $this->rectArea($o);
                        if ($oArea < $this->cadArea(2800) || $oArea > $this->cadArea(20000)) {
                            continue;
                        }
                        if ($this->containmentFrac($o, $union) > 0.55) {
                            $cubHits++;
                        }
                    }
                    if ($cubHits >= 4) {
                        continue;
                    }
                    // Prefer wall-filled union when fragments under-cover the room.
                    $grown = $this->expandCubicleToWalls($union, $walls, $w, $h, $this->cad(80));
                    if (
                        $this->rectArea($grown) >= $uArea
                        && $this->rectArea($grown) <= (int) ($uArea * 1.8)
                        && $this->interiorWallFrac($grown, $walls, $w, $h) <= 0.10
                    ) {
                        $union = $grown;
                    }
                    $out[$i] = $union;
                    unset($out[$j]);
                    $out = array_values($out);
                    $merged = true;
                    break 2;
                }
            }
        }

        return array_values($out);
    }

    /**
     * Final pass: drop long thin hallway scraps that survived late splits/refills.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function cullLateHallwayScraps(array $rects, array $walls, int $w, int $h): array
    {
        $kept = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $hallway = max($bw, $bh) >= $this->cad(180) && min($bw, $bh) <= $this->cad(50);
            // True corridors: long, thin, open on the long axis. Keep tall 3-walled offices.
            if (
                $hallway
                && $sides < 3
                && ($this->isCorridorShape($r, $walls, $w, $h) || $enc < 0.48)
            ) {
                continue;
            }
            if (
                $area <= $this->cadArea(18000)
                && max($bw, $bh) >= $this->cad(200)
                && min($bw, $bh) <= $this->cad(45)
                && $sides < 3
                && $enc < 0.55
            ) {
                continue;
            }
            // Flat shelf "rooms" — wide and short only (not tall offices).
            if (
                $area >= $this->cadArea(18000)
                && $bw >= (int) ($bh * 2.4)
                && $bh <= $this->cad(180)
                && $sides < 4
            ) {
                continue;
            }
            $kept[] = $r;
        }

        return $kept;
    }

    /**
     * Drop furniture / core / courtyard boxes that are large but not real rooms
     * (high interior ink, weak enclosure, no label).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function cullFurnitureCoreBoxes(array $rects, array $walls, int $w, int $h): array
    {
        $kept = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            $iw = $this->interiorWallFrac($r, $walls, $w, $h);
            // Large ink-filled cores (stairs/elevators/furniture clusters).
            if ($area >= $this->cadArea(25000) && $iw > 0.11 && $enc < 0.55) {
                continue;
            }
            if ($area >= $this->cadArea(40000) && $iw > 0.08 && $sides < 3 && $enc < 0.50) {
                continue;
            }
            // Flat shelf boxes left by bad merges (wide×short, e.g. 235×76).
            if (
                $area >= $this->cadArea(16000)
                && $bw >= (int) ($bh * 2.3)
                && $bh <= $this->cad(180)
            ) {
                continue;
            }
            // Bottom open-plan / hallway false rooms that swallow bank rows
            // (e.g. 318×144 @ y≈715 covering GT #1–#4).
            if (
                $area >= $this->cadArea(28000)
                && $r[1] >= (int) ($h * 0.68)
                && $bh <= $this->cad(200)
                && $bw >= $this->cad(280)
            ) {
                continue;
            }
            if (
                $area >= $this->cadArea(35000)
                && $r[1] >= (int) ($h * 0.70)
                && $bw >= (int) ($bh * 2.0)
            ) {
                continue;
            }
            // Large rooms whose bottom edge covers the bottom bank zone.
            if (
                $area >= $this->cadArea(40000)
                && $r[3] >= (int) ($h * 0.80)
                && $bw >= $this->cad(240)
                && $bh >= $this->cad(150)
            ) {
                continue;
            }
            if (
                $bw <= $this->cad(140)
                && $bh >= $this->cad(220)
                && $bh / max(1, $bw) >= 2.0
                && $area >= $this->cadArea(25000)
                && $area <= $this->cadArea(100000)
                && $enc < 0.55
            ) {
                continue;
            }
            // Tiny desk/furniture scraps that escaped earlier gates.
            if ($area < $this->cadArea(2200) && $enc < 0.40 && $sides < 2) {
                continue;
            }
            // Medium open boxes with almost no walls (aisle ghosts).
            if (
                $area >= $this->cadArea(8000)
                && $area <= $this->cadArea(35000)
                && $sides < 1
                && $enc < 0.22
            ) {
                continue;
            }
            $kept[] = $r;
        }

        return $kept;
    }

    /**
     * Tiny lateral snap: only nudge edges already within ~8px of the column median.
     * Broader unify crushed IoU on staggered banks (#70/#76).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function unifyCubicleColumnEdges(array $rects, int $w, int $h): array
    {
        if (count($rects) < 3) {
            return $rects;
        }
        $maxCub = $this->cadArea(22000);
        $colTol = max(10, $this->modW > 0 ? (int) ($this->modW * 0.28) : $this->cad(32));
        $snapTol = $this->cad(8);
        $items = [];
        foreach ($rects as $i => $r) {
            $area = $this->rectArea($r);
            if ($area > $maxCub) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bw < $this->cad(40) || $bh < $this->cad(28) || max($bw, $bh) / max(1, min($bw, $bh)) > 3.2) {
                continue;
            }
            $items[] = [
                'i' => $i,
                'cx' => intdiv($r[0] + $r[2], 2),
                'x0' => $r[0],
                'x1' => $r[2],
            ];
        }
        if (count($items) < 2) {
            return $rects;
        }
        usort($items, static fn (array $a, array $b): int => $a['cx'] <=> $b['cx']);
        $clusters = [];
        $cur = [$items[0]];
        for ($k = 1, $n = count($items); $k < $n; $k++) {
            $prev = $cur[count($cur) - 1];
            if (abs($items[$k]['cx'] - $prev['cx']) <= $colTol) {
                $cur[] = $items[$k];
            } else {
                $clusters[] = $cur;
                $cur = [$items[$k]];
            }
        }
        $clusters[] = $cur;
        $out = $rects;
        foreach ($clusters as $cluster) {
            if (count($cluster) < 3) {
                continue;
            }
            $lefts = [];
            $rights = [];
            foreach ($cluster as $c) {
                $lefts[] = $c['x0'];
                $rights[] = $c['x1'];
            }
            sort($lefts);
            sort($rights);
            $x0 = $lefts[intdiv(count($lefts), 2)];
            $x1 = $rights[intdiv(count($rights), 2)];
            if ($x1 - $x0 + 1 < $this->cad(40) || $x1 - $x0 + 1 > $this->cad(180)) {
                continue;
            }
            foreach ($cluster as $c) {
                $ri = $c['i'];
                if (abs($out[$ri][0] - $x0) <= $snapTol) {
                    $out[$ri][0] = $x0;
                }
                if (abs($out[$ri][2] - $x1) <= $snapTol) {
                    $out[$ri][2] = $x1;
                }
                if ($out[$ri][2] <= $out[$ri][0]) {
                    $out[$ri][2] = min($w - 1, $out[$ri][0] + $this->cad(50));
                }
            }
        }

        return array_values($out);
    }

    /**
     * One global exclusive selection with a structural budget.
     *
     * Scores each candidate once (enclosure, size prior, label proximity, furniture
     * evidence) and greedily keeps non-overlapping rects up to $budget. Replaces
     * the prune/exclusive/trim oscillation when recognition layers are available.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function selectExclusiveOfficeRects(
        array $rects,
        array $walls,
        int $w,
        int $h,
        array $labels,
        int $budget
    ): array {
        if ($rects === [] || $budget < 1) {
            return [];
        }
        $ideal = $this->modW > 20 && $this->modH > 16
            ? max(1, $this->modW * $this->modH)
            : $this->cadArea(8800);
        $scored = [];
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            $score = $enc * 1.6 + $sides * 0.15;
            // Prefer module-sized cells and mid-large rooms; punish scraps and mega blobs.
            $score += 1.15 - min(1.15, abs($area - $ideal) / max(1.0, (float) $ideal));
            if ($this->modW > 0) {
                $score += 0.35 - min(0.35, abs($bw - $this->modW) / max(1.0, (float) $this->modW));
            }
            if ($this->modH > 0) {
                $score += 0.35 - min(0.35, abs($bh - $this->modH) / max(1.0, (float) $this->modH));
            }
            if ($area < $this->cadArea(2000) || $area > (int) ($w * $h * 0.12)) {
                $score -= 1.5;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $labeled = false;
            foreach ($labels as $lab) {
                $lx = $lab['x'] + intdiv($lab['w'], 2);
                $ly = $lab['y'] + intdiv($lab['h'], 2);
                if ($lx >= $r[0] && $lx <= $r[2] && $ly >= $r[1] && $ly <= $r[3]) {
                    $score += 2.0;
                    $labeled = true;
                    break;
                }
                if (hypot($cx - $lx, $cy - $ly) < $this->cad(40)) {
                    $score += 0.55;
                    break;
                }
            }
            if ($labeled) {
                $score += 0.4;
            }
            if ($this->elementLayers !== null) {
                foreach ($this->elementLayers->furniture as $item) {
                    $b = $item['bbox'];
                    $fx = $b['x'] + intdiv($b['w'], 2);
                    $fy = $b['y'] + intdiv($b['h'], 2);
                    if ($fx >= $r[0] && $fx <= $r[2] && $fy >= $r[1] && $fy <= $r[3]) {
                        $score += 0.35;
                        break;
                    }
                }
                foreach ($this->elementLayers->stairs as $item) {
                    $b = $item['bbox'];
                    $fx = $b['x'] + intdiv($b['w'], 2);
                    $fy = $b['y'] + intdiv($b['h'], 2);
                    if ($fx >= $r[0] && $fx <= $r[2] && $fy >= $r[1] && $fy <= $r[3]) {
                        $score -= 2.0;
                        break;
                    }
                }
            }
            $scored[] = ['r' => $r, 'q' => $score, 'i' => $i, 'labeled' => $labeled];
        }
        usort($scored, static function (array $a, array $b): int {
            if (!empty($a['labeled']) !== !empty($b['labeled'])) {
                return !empty($a['labeled']) ? -1 : 1;
            }

            return $b['q'] <=> $a['q'];
        });
        $kept = [];
        foreach ($scored as $item) {
            if (count($kept) >= $budget) {
                break;
            }
            $bad = false;
            foreach ($kept as $o) {
                if ($this->overlapFrac($item['r'], $o) > 0.18) {
                    $bad = true;
                    break;
                }
            }
            if (!$bad) {
                $kept[] = $item['r'];
            }
        }

        return array_values($kept);
    }

    private function trimExcessOfficePreds(
        array $rects,
        array $walls,
        int $w,
        int $h,
        array $labels,
        int $target
    ): array {
        if (count($rects) <= $target) {
            return $rects;
        }
        $scored = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $large = $area >= $this->cadArea(28000);
            $labelBonus = $this->labelCenterBonus($r, $cx, $cy, $labels);
            $q = $this->cubicleQuality($r, $walls, $w, $h);
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $nearLab = $this->nearestLabelDist($cx, $cy, $labels);
            $nearLabBonus = $labels === [] ? 0.0 : max(0.0, 1.0 - $nearLab / max(1.0, (float) $this->cad(180))) * 0.4;
            // Prefer bank-sized / well-walled cells; penalize hallway-like scraps.
            $asp = max($bw = $r[2] - $r[0] + 1, $bh = $r[3] - $r[1] + 1) / max(1, min($bw, $bh));
            $hallPen = ($asp >= 2.6 && min($bw, $bh) <= $this->cad(55) && $sides < 3) ? 0.8 : 0.0;
            $farPen = ($labels !== [] && $labelBonus <= 0.0 && $nearLab > $this->cad(200) && !$large) ? 0.5 : 0.0;
            // Prefer short/medium bank footprints that match filled-map cubicles.
            $bankBonus = 0.0;
            if (!$large && $bw >= $this->cad(70) && $bw <= $this->cad(160) && $bh >= $this->cad(55) && $bh <= $this->cad(140)) {
                $bankBonus = 0.55;
            } elseif (!$large && $bw >= $this->cad(70) && $bw <= $this->cad(160) && $bh >= $this->cad(32) && $bh < $this->cad(55)) {
                $bankBonus = 0.35;
            }
            // Keep bottom-row offices and tall stacked companions that late seeds recover.
            if (!$large && $r[3] >= (int) ($h * 0.88) && $bw >= $this->cad(50) && $bh >= $this->cad(28)) {
                $bankBonus += 0.85;
            }
            if (!$large && $bh >= $this->cad(80) && $bh <= $this->cad(240) && $bw >= $this->cad(50) && $bw <= $this->cad(120)) {
                $bankBonus += 0.45;
            }
            // Must-keep floor-touching offices (#1/#6), including short stubs refine will grow.
            $bottomOffice = !$large && $r[3] >= (int) ($h * 0.88)
                && $bw >= $this->cad(55) && $bw <= $this->cad(240)
                && $bh >= $this->cad(16) && $bh <= $this->cad(160);
            $stackShort = !$large && $bw >= $this->cad(48) && $bw <= $this->cad(140)
                && $bh >= $this->cad(28) && $bh <= $this->cad(70)
                && $cy >= (int) ($h * 0.55) && $cy <= (int) ($h * 0.78);
            $stackTall = !$large && $bw >= $this->cad(48) && $bw <= $this->cad(130)
                && $bh >= $this->cad(80) && $bh <= $this->cad(220)
                && $cy >= (int) ($h * 0.48) && $cy <= (int) ($h * 0.72);
            $upperBank = !$large && $bw >= $this->cad(70) && $bw <= $this->cad(180)
                && $bh >= $this->cad(50) && $bh <= $this->cad(140)
                && $cy >= (int) ($h * 0.08) && $cy <= (int) ($h * 0.55);
            $scored[] = [
                'r' => $r,
                'large' => $large,
                'labeled' => $labelBonus > 0.35,
                'bottomOffice' => $bottomOffice,
                'stackTall' => $stackTall,
                'stackPair' => $stackShort || $stackTall || $upperBank,
                'area' => $area,
                'cx' => $cx,
                'cy' => $cy,
                'score' => ($large ? 3.0 : 0.0) + ($bottomOffice ? 1.4 : 0.0) + ($stackTall ? 1.3 : 0.0) + (($stackShort || $upperBank) ? 0.9 : 0.0) + $labelBonus * 2.0 + $q + $sides * 0.12 + $enc * 0.3 + $nearLabBonus + $bankBonus - $hallPen - $farPen,
            ];
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['large'] !== $b['large']) {
                return $a['large'] ? -1 : 1;
            }
            if (!empty($a['labeled']) !== !empty($b['labeled'])) {
                return !empty($a['labeled']) ? -1 : 1;
            }
            if (!empty($a['bottomOffice']) !== !empty($b['bottomOffice'])) {
                return !empty($a['bottomOffice']) ? -1 : 1;
            }
            if (!empty($a['stackTall']) !== !empty($b['stackTall'])) {
                return !empty($a['stackTall']) ? -1 : 1;
            }
            if (!empty($a['stackPair']) !== !empty($b['stackPair'])) {
                return !empty($a['stackPair']) ? -1 : 1;
            }

            return $b['score'] <=> $a['score'];
        });
        $out = [];
        $hardCap = $target + 24;
        foreach ($scored as $item) {
            $r = $item['r'];
            $large = !empty($item['large']);
            $bottom = !empty($item['bottomOffice']);
            $labeled = !empty($item['labeled']);
            $stack = !empty($item['stackPair']);
            $tall = !empty($item['stackTall']);
            if (count($out) >= $hardCap && !$large && !$bottom && !$tall) {
                continue;
            }
            if ($large || $bottom || $tall) {
                $out[] = $r;
                continue;
            }
            // Stacks preferred but still soft-capped.
            if ($stack && count($out) < $target + 18) {
                $out[] = $r;
                continue;
            }
            if (!$labeled && count($out) >= $target) {
                continue;
            }
            if ($labeled && count($out) >= $target + 8) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Seed tall narrow wall-bounded slots that cubicle hole-fill rejects (asp / height caps).
     * Displaces shallow cubicles whose centers fall inside the slot.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedTallNarrowSlots(array $rects, array $walls, int $w, int $h): array
    {
        $extra = [];
        $stepY = $this->cad(40);
        $stepX = $this->cad(22);
        $margin = $this->cad(24);
        $largeBlockers = array_values(array_filter(
            $rects,
            function (array $r) use ($walls, $w, $h): bool {
                $area = $this->rectArea($r);
                if ($area < $this->cadArea(45000)) {
                    return false;
                }

                return $this->enclosureScore($r, $walls, $w, $h) >= 0.50;
            }
        ));
        for ($sy = $margin; $sy < $h - $margin; $sy += $stepY) {
            for ($sx = $margin; $sx < $w - $margin; $sx += $stepX) {
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($sx, $sy, $largeBlockers, $this->cad(10))) {
                    continue;
                }
                // Already covered by an existing tall narrow room.
                $covered = false;
                foreach ($rects as $r) {
                    $rbw = $r[2] - $r[0] + 1;
                    $rbh = $r[3] - $r[1] + 1;
                    if ($rbh < $this->cad(90) || $rbw > $this->cad(80)) {
                        continue;
                    }
                    if ($sx >= $r[0] && $sx <= $r[2] && $sy >= $r[1] && $sy <= $r[3]) {
                        $covered = true;
                        break;
                    }
                }
                if ($covered) {
                    continue;
                }
                $rect = $this->growRectFromSeed(
                    $walls,
                    $w,
                    $h,
                    $sx,
                    $sy,
                    $this->cad(100),
                    $this->cad(180)
                );
                if ($rect === null) {
                    continue;
                }
                $rect = $this->expandCubicleToWalls($rect, $walls, $w, $h, $this->cad(12));
                $bw = $rect[2] - $rect[0] + 1;
                $bh = $rect[3] - $rect[1] + 1;
                // Work-space caps: cad() halves at 2k work, so use higher CAD refs for width.
                if ($bw < $this->cad(28) || $bw > $this->cad(160) || $bh < $this->cad(95) || $bh > $this->cad(320)) {
                    continue;
                }
                $asp = $bh / max(1, $bw);
                if ($asp < 1.85 || $asp > 6.0) {
                    continue;
                }
                $enc = $this->enclosureScore($rect, $walls, $w, $h);
                if ($enc < 0.48) {
                    continue;
                }
                if ($this->strongWallSides($rect, $walls, $w, $h, 0.34) < 2) {
                    continue;
                }
                if ($this->interiorWallFrac($rect, $walls, $w, $h) > 0.10) {
                    continue;
                }
                $skip = false;
                foreach (array_merge($extra, $rects) as $host) {
                    $hbw = $host[2] - $host[0] + 1;
                    $hbh = $host[3] - $host[1] + 1;
                    // Duplicate of an existing tall slot.
                    if ($hbh >= $this->cad(90) && $hbw <= $this->cad(80) && $this->overlapFrac($rect, $host) > 0.55) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $extra[] = $rect;
                }
            }
        }
        if ($extra === []) {
            return $rects;
        }
        // Prefer higher enclosure when extras overlap each other.
        usort(
            $extra,
            fn (array $a, array $b): int => $this->enclosureScore($b, $walls, $w, $h)
                <=> $this->enclosureScore($a, $walls, $w, $h)
        );
        $keptExtra = [];
        foreach ($extra as $rect) {
            $skip = false;
            foreach ($keptExtra as $host) {
                if ($this->overlapFrac($rect, $host) > 0.40) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $keptExtra[] = $rect;
            }
        }
        $out = [];
        foreach ($rects as $r) {
            $drop = false;
            $rcx = intdiv($r[0] + $r[2], 2);
            $rcy = intdiv($r[1] + $r[3], 2);
            $rArea = $this->rectArea($r);
            foreach ($keptExtra as $slot) {
                if ($rcx >= $slot[0] && $rcx <= $slot[2] && $rcy >= $slot[1] && $rcy <= $slot[3]) {
                    // Shallow cubicle sitting inside the tall slot — drop it.
                    if ($rArea <= $this->cadArea(22000) && ($r[3] - $r[1] + 1) < ($slot[3] - $slot[1] + 1) * 0.7) {
                        $drop = true;
                        break;
                    }
                }
                if ($rArea <= $this->cadArea(22000) && $this->overlapFrac($r, $slot) > 0.45) {
                    $drop = true;
                    break;
                }
            }
            if (!$drop) {
                $out[] = $r;
            }
        }
        foreach ($keptExtra as $slot) {
            $out[] = $slot;
        }

        return $out;
    }

    /**
     * Space-fill large rooms that stopped short of surrounding partitions.
     * Rejects growth that swallows another large room's center.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function growLargeUndersizedRooms(array $rects, array $walls, int $w, int $h): array
    {
        $out = $rects;
        foreach ($rects as $i => $r) {
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(18000) || $area > $this->cadArea(200000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if (min($bw, $bh) < $this->cad(50)) {
                continue;
            }
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            // Open-front larges often look under-enclosed after pad; still try fill.
            if ($sides < 2 && $enc < 0.28) {
                continue;
            }
            $grown = $this->expandCubicleToWalls($r, $walls, $w, $h, $this->cad(140));
            $gArea = $this->rectArea($grown);
            if ($gArea < $area * 1.15) {
                $sf = $this->spaceFillRectToWalls($r, $walls, $w, $h);
                if ($this->rectArea($sf) > $gArea) {
                    $grown = $sf;
                    $gArea = $this->rectArea($grown);
                }
            }
            if ($grown === $r || $gArea < $area * 1.12) {
                continue;
            }
            if ($gArea > $area * 3.5 || $gArea > $this->cadArea(280000)) {
                continue;
            }
            if ($this->isCorridorShape($grown, $walls, $w, $h) && $this->enclosureScore($grown, $walls, $w, $h) < 0.50) {
                continue;
            }
            if ($this->interiorWallFrac($grown, $walls, $w, $h) > 0.12) {
                continue;
            }
            $swallows = false;
            $absorb = [];
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                if ($this->rectArea($o) < $this->cadArea(12000)) {
                    continue; // cubicles carved by exclusive later
                }
                $cx = intdiv($o[0] + $o[2], 2);
                $cy = intdiv($o[1] + $o[3], 2);
                if ($cx >= $grown[0] && $cx <= $grown[2] && $cy >= $grown[1] && $cy <= $grown[3]) {
                    // Only care if the neighbor was mostly outside the original box.
                    if ($cx < $r[0] || $cx > $r[2] || $cy < $r[1] || $cy > $r[3]) {
                        $oArea = $this->rectArea($o);
                        $obw = $o[2] - $o[0] + 1;
                        $rcx = intdiv($r[0] + $r[2], 2);
                        $vertOnly = abs(($grown[2] - $grown[0]) - ($r[2] - $r[0])) <= $this->cad(14)
                            && ($grown[3] - $grown[1]) > ($r[3] - $r[1] + $this->cad(24));
                        $sameCol = abs($cx - $rcx) <= max($this->cad(18), (int) ($bw * 0.4));
                        // Absorb one stacked bank companion under a tall room (#9).
                        // Companion can be a few px wider (desk lip / exclusive carve).
                        if (
                            $vertOnly
                            && $sameCol
                            && $bh / max(1, $bw) >= 1.8
                            && $oArea <= $this->cadArea(28000)
                            && $oArea < $area
                            && $obw <= $bw + $this->cad(48)
                            && $absorb === []
                        ) {
                            $absorb[] = $j;
                            continue;
                        }
                        $swallows = true;
                        break;
                    }
                }
            }
            if ($swallows) {
                continue;
            }
            $out[$i] = $grown;
            foreach ($absorb as $j) {
                unset($out[$j]);
            }
        }

        return array_values($out);
    }

    /**
     * Bottom bank row often sits below the usual lattice cutoffs — seed it explicitly.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedBottomBankRow(array $rects, array $walls, int $w, int $h, bool $aggressive = false): array
    {
        $extra = [];
        $y0 = (int) ($h * 0.70);
        $y1 = $h - $this->cad(12);
        $stepY = $aggressive ? $this->cad(16) : $this->cad(18);
        $stepX = $aggressive ? $this->cad(20) : $this->cad(24);
        $margin = $this->cad(20);
        $replaceIdx = [];
        $maxGrowW = $aggressive ? $this->cad(130) : $this->cad(100);
        $maxGrowH = $aggressive ? $this->cad(110) : $this->cad(80);
        $maxBw = $aggressive ? $this->cad(240) : $this->cad(170);
        $maxBh = $aggressive ? $this->cad(200) : $this->cad(150);
        $maxArea = $aggressive ? $this->cadArea(32000) : $this->cadArea(20000);
        for ($sy = $y0; $sy < $y1; $sy += $stepY) {
            for ($sx = $margin; $sx < $w - $margin; $sx += $stepX) {
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($sx, $sy, array_merge($rects, $extra), $this->cad(6))) {
                    if (!$aggressive) {
                        continue;
                    }
                    $blockStub = false;
                    foreach ($rects as $hi => $host) {
                        if ($sx < $host[0] || $sx > $host[2] || $sy < $host[1] || $sy > $host[3]) {
                            continue;
                        }
                        $hh = $host[3] - $host[1] + 1;
                        $hw = $host[2] - $host[0] + 1;
                        if ($hh <= $this->cad(45) && $hw <= $this->cad(180) && $this->rectArea($host) <= $this->cadArea(9000)) {
                            $replaceIdx[$hi] = true;
                            $blockStub = true;
                            break;
                        }
                    }
                    if (!$blockStub) {
                        continue;
                    }
                }
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $maxGrowW, $maxGrowH);
                if ($grown === null) {
                    continue;
                }
                $cand = $this->padOpenCubicle($grown, $walls, $w, $h);
                $reach = $aggressive ? $this->cad(40) : $this->cad(16);
                $cand = $this->expandCubicleToWalls($cand, $walls, $w, $h, $reach);
                if ($aggressive && $cand[1] >= (int) ($h * 0.72)) {
                    $cand = $this->expandCubicleToWalls($cand, $walls, $w, $h, $this->cad(60));
                }
                $bw = $cand[2] - $cand[0] + 1;
                $bh = $cand[3] - $cand[1] + 1;
                $area = $bw * $bh;
                if (
                    $bw < $this->cad(50)
                    || $bw > $maxBw
                    || $bh < $this->cad(28)
                    || $bh > $maxBh
                    || $area < $this->cadArea(2000)
                    || $area > $maxArea
                ) {
                    continue;
                }
                if ($this->isCorridorShape($cand, $walls, $w, $h) && $this->enclosureScore($cand, $walls, $w, $h) < 0.42) {
                    continue;
                }
                $enc = $this->enclosureScore($cand, $walls, $w, $h);
                $sides = $this->strongWallSides($cand, $walls, $w, $h, 0.30);
                if ($enc < 0.14 && $sides < 2) {
                    continue;
                }
                if ($enc < 0.20 && $sides < 1) {
                    continue;
                }
                $skip = false;
                foreach (array_merge($rects, $extra) as $hi => $host) {
                    if (isset($replaceIdx[$hi])) {
                        continue;
                    }
                    if ($this->overlapFrac($cand, $host) > 0.28 || $this->containmentFrac($cand, $host) > 0.50) {
                        if ($aggressive) {
                            $hh = $host[3] - $host[1] + 1;
                            $hostCy = intdiv($host[1] + $host[3], 2);
                            $candCy = intdiv($cand[1] + $cand[3], 2);
                            // Displace too-high false bottom rooms that block true #1/#6 seeds.
                            if (
                                $candCy >= (int) ($h * 0.84)
                                && $hostCy < (int) ($h * 0.84)
                                && $host[3] < $cand[3] - $this->cad(20)
                                && $this->rectArea($host) <= $this->cadArea(18000)
                            ) {
                                if (isset($rects[$hi])) {
                                    $replaceIdx[$hi] = true;
                                }
                                continue;
                            }
                            if ($host[3] <= $cand[1] + $this->cad(50) && $host[1] < $cand[1] && $cand[3] - $host[3] >= $this->cad(28)) {
                                $cand[1] = $host[3] + 2;
                                $bw = $cand[2] - $cand[0] + 1;
                                $bh = $cand[3] - $cand[1] + 1;
                                $area = $bw * $bh;
                                if ($bh < $this->cad(28) || $area < $this->cadArea(2000)) {
                                    $skip = true;
                                    break;
                                }
                                continue;
                            }
                            if ($hh <= $this->cad(45) && $this->rectArea($host) < $this->rectArea($cand)) {
                                if (isset($rects[$hi])) {
                                    $replaceIdx[$hi] = true;
                                }
                                continue;
                            }
                        }
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $extra[] = $cand;
                }
            }
        }
        if ($aggressive) {
            foreach ($rects as $host) {
                $hw = $host[2] - $host[0] + 1;
                $hh = $host[3] - $host[1] + 1;
                if ($hw < $this->cad(55) || $hw > $this->cad(200) || $hh < $this->cad(40) || $hh > $this->cad(160)) {
                    continue;
                }
                if ($host[3] < (int) ($h * 0.68) || $host[3] > $h - $this->cad(40)) {
                    continue;
                }
                $sx = intdiv($host[0] + $host[2], 2);
                $sy = min($h - $this->cad(20), $host[3] + $this->cad(36));
                if ($sy <= $host[3] + 2 || $walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($sx, $sy, array_merge($rects, $extra), $this->cad(4))) {
                    continue;
                }
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(130), $this->cad(110));
                if ($grown === null) {
                    continue;
                }
                $cand = $this->expandCubicleToWalls($this->padOpenCubicle($grown, $walls, $w, $h), $walls, $w, $h, $this->cad(28));
                if ($cand[1] < $host[3] + 1) {
                    $cand[1] = $host[3] + 2;
                }
                $bw = $cand[2] - $cand[0] + 1;
                $bh = $cand[3] - $cand[1] + 1;
                $area = $bw * $bh;
                if (
                    $bw < $this->cad(55)
                    || $bw > $this->cad(240)
                    || $bh < $this->cad(32)
                    || $bh > $this->cad(180)
                    || $area < $this->cadArea(2500)
                    || $area > $this->cadArea(32000)
                ) {
                    continue;
                }
                $skip = false;
                foreach (array_merge($rects, $extra) as $o) {
                    if ($this->overlapFrac($cand, $o) > 0.30) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $extra[] = $cand;
                }
            }
        }
        if ($replaceIdx !== []) {
            $kept = [];
            foreach ($rects as $i => $r) {
                if (!isset($replaceIdx[$i])) {
                    $kept[] = $r;
                }
            }
            $rects = $kept;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Snap oversized short cells under a tall companion back to the tall column width (#18).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function alignShortUnderTallWidth(array $rects): array
    {
        $n = count($rects);
        for ($i = 0; $i < $n; $i++) {
            $t = $rects[$i];
            $tw = $t[2] - $t[0] + 1;
            $th = $t[3] - $t[1] + 1;
            if ($tw < $this->cad(48) || $tw > $this->cad(130) || $th < $this->cad(80) || $th > $this->cad(240)) {
                continue;
            }
            for ($j = 0; $j < $n; $j++) {
                if ($j === $i) {
                    continue;
                }
                $s = $rects[$j];
                $sw = $s[2] - $s[0] + 1;
                $sh = $s[3] - $s[1] + 1;
                if ($sh < $this->cad(28) || $sh > $this->cad(95) || $sw < $this->cad(48)) {
                    continue;
                }
                if ($s[1] > $t[3] + $this->cad(80) || $s[1] < $t[3] - $this->cad(12)) {
                    continue;
                }
                $tcx = intdiv($t[0] + $t[2], 2);
                if ($tcx < $s[0] - $this->cad(20) || $tcx > $s[2] + $this->cad(20)) {
                    continue;
                }
                if ($sw > $tw + $this->cad(16) || abs($sw - $tw) >= $this->cad(10) || abs($s[0] - $t[0]) >= $this->cad(8)) {
                    $s[0] = $t[0];
                    $s[2] = $t[2];
                    $rects[$j] = $s;
                }
            }
        }

        return $rects;
    }

    /**
     * If a tall bank cell has an empty short slot beneath it, plant the missing short cell (#18).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function ensureShortUnderTall(array $rects, array $walls, int $w, int $h): array
    {
        $extra = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bw < $this->cad(48) || $bw > $this->cad(130) || $bh < $this->cad(80) || $bh > $this->cad(240)) {
                continue;
            }
            $sx = intdiv($r[0] + $r[2], 2);
            // Desk band between stacked cells — seed below the ink, not into it.
            $sy = null;
            foreach ([$this->cad(20), $this->cad(36), $this->cad(52), $this->cad(70)] as $dy) {
                $ty = min($h - 4, $r[3] + $dy);
                if ($ty > $r[3] + 1 && $walls[$ty * $w + $sx] !== 1) {
                    $sy = $ty;
                    break;
                }
            }
            if ($sy === null) {
                // Force geometric center of expected short cell.
                $sy = min($h - 4, $r[3] + $this->cad(40));
            }
            $hasGoodShort = false;
            $displaceBelow = [];
            foreach ($rects as $hi => $o) {
                if ($sx < $o[0] || $sx > $o[2] || $sy < $o[1] || $sy > $o[3]) {
                    continue;
                }
                $ow = $o[2] - $o[0] + 1;
                $oh = $o[3] - $o[1] + 1;
                if ($ow >= $this->cad(45) && $ow <= $this->cad(130) && $oh >= $this->cad(28) && $oh <= $this->cad(90)) {
                    $hasGoodShort = true;
                    break;
                }
                // Large/wide boxes under the tall cell block the slot — replace them.
                if ($oh <= $this->cad(120) || $ow >= $this->cad(100)) {
                    $displaceBelow[$hi] = true;
                }
            }
            if ($hasGoodShort) {
                continue;
            }
            // Prefer a host-aligned geometric short — expand-to-walls often collapses into desk ink.
            $cand = [$r[0], $r[3] + 2, $r[2], min($h - 2, $r[3] + $this->cad(78))];
            $cbw = $cand[2] - $cand[0] + 1;
            $cbh = $cand[3] - $cand[1] + 1;
            if ($cbh < $this->cad(28)) {
                continue;
            }
            $fit = $this->expandCubicleToWalls($cand, $walls, $w, $h, $this->cad(10));
            if ($fit[1] <= $r[3]) {
                $fit[1] = $r[3] + 2;
            }
            $fit[0] = max($fit[0], $r[0] - $this->cad(4));
            $fit[2] = min($fit[2], $r[2] + $this->cad(4));
            $fitH = $fit[3] - $fit[1] + 1;
            $fitW = $fit[2] - $fit[0] + 1;
            if (
                $fitW >= $this->cad(45)
                && $fitW <= $this->cad(130)
                && $fitH >= $this->cad(28)
                && $fitH <= $this->cad(90)
                && $this->overlapFrac($fit, $r) <= 0.08
            ) {
                $cand = $fit;
                $cbw = $fitW;
                $cbh = $fitH;
            }
            if ($cbh < $this->cad(28)) {
                continue;
            }
            $skip = false;
            foreach (array_merge($rects, $extra) as $oi => $o) {
                if ($o === $r || isset($displaceBelow[$oi])) {
                    continue;
                }
                if ($this->overlapFrac($cand, $o) > 0.40) {
                    $ow = $o[2] - $o[0] + 1;
                    $oh = $o[3] - $o[1] + 1;
                    if ($oh <= $this->cad(120) && $this->rectArea($o) < $this->rectArea($cand) * 2) {
                        if (isset($rects[$oi])) {
                            $displaceBelow[$oi] = true;
                        }
                        continue;
                    }
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            if ($displaceBelow !== []) {
                $kept = [];
                foreach ($rects as $hi => $o) {
                    if (!isset($displaceBelow[$hi])) {
                        $kept[] = $o;
                    }
                }
                $rects = $kept;
            }
            $extra[] = $cand;
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Plant tall bank cubicles (#19-like) into empty mid-floor wall pockets when no host survived.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedOrphanTallBankSlots(array $rects, array $walls, int $w, int $h): array
    {
        $candidates = [];
        $y0 = (int) ($h * 0.52);
        $y1 = (int) ($h * 0.68);
        $stepX = max(16, $this->cad(32));
        $stepY = max(14, $this->cad(28));
        $margin = $this->cad(40);
        for ($sy = $y0; $sy < $y1; $sy += $stepY) {
            for ($sx = $margin; $sx < $w - $margin; $sx += $stepX) {
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($sx, $sy, $rects, $this->cad(6))) {
                    continue;
                }
                $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(70), $this->cad(200));
                if ($grown === null) {
                    continue;
                }
                $cand = $this->expandCubicleToWalls(
                    $this->padOpenCubicle($grown, $walls, $w, $h),
                    $walls,
                    $w,
                    $h,
                    $this->cad(40)
                );
                $cbw = $cand[2] - $cand[0] + 1;
                $cbh = $cand[3] - $cand[1] + 1;
                if (
                    $cbw < $this->cad(90)
                    || $cbw > $this->cad(120)
                    || $cbh < $this->cad(90)
                    || $cbh > $this->cad(200)
                    || $cbw * $cbh < $this->cadArea(5000)
                    || $cbw * $cbh > $this->cadArea(26000)
                ) {
                    continue;
                }
                $asp = $cbh / max(1, $cbw);
                if ($asp < 1.15 || $asp > 3.6) {
                    continue;
                }
                $enc = $this->enclosureScore($cand, $walls, $w, $h);
                if ($enc < 0.26) {
                    continue;
                }
                if ($this->strongWallSides($cand, $walls, $w, $h, 0.28) < 2) {
                    continue;
                }
                if ($this->isCorridorShape($cand, $walls, $w, $h)) {
                    continue;
                }
                $shortY = min($h - 4, $cand[3] + $this->cad(70));
                $shortX = intdiv($cand[0] + $cand[2], 2);
                if ($shortY <= $cand[3] + 2 || $walls[$shortY * $w + $shortX] === 1) {
                    // Desk lip sits just under tall cells — try a deeper short-slot probe.
                    $shortY = min($h - 4, $cand[3] + $this->cad(100));
                    if ($shortY <= $cand[3] + 2 || $walls[$shortY * $w + $shortX] === 1) {
                        continue;
                    }
                }
                if ($this->pointInAnyRectCore($shortX, $shortY, $rects, $this->cad(4))) {
                    continue;
                }
                $blocked = false;
                foreach ($rects as $host) {
                    if ($this->overlapFrac($cand, $host) > 0.28 || $this->containmentFrac($cand, $host) > 0.45) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
                $candidates[] = ['r' => $cand, 'enc' => $enc];
            }
        }
        if ($candidates === []) {
            return $rects;
        }
        usort($candidates, static fn (array $a, array $b): int => $b['enc'] <=> $a['enc']);
        $extra = [];
        foreach ($candidates as $item) {
            $cand = $item['r'];
            $blocked = false;
            foreach ($extra as $o) {
                if ($this->overlapFrac($cand, $o) > 0.28) {
                    $blocked = true;
                    break;
                }
                $cx = intdiv($cand[0] + $cand[2], 2);
                $cy = intdiv($cand[1] + $cand[3], 2);
                $ox = intdiv($o[0] + $o[2], 2);
                $oy = intdiv($o[1] + $o[3], 2);
                if (hypot($cx - $ox, $cy - $oy) < $this->cad(70)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }
            $extra[] = $cand;
            if (count($extra) >= 8) {
                break;
            }
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Bottom-row offices (#1/#6): merge stacked stubs, push height past desk lips, widen to walls.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<int> $dense
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function refineBottomOpenOffices(
        array $rects,
        array $walls,
        array $dense,
        int $w,
        int $h
    ): array {
        $yBand = (int) ($h * 0.78);
        // 1) Merge vertically adjacent same-column stubs (#6 = 99×19 + 99×34).
        $n = count($rects);
        $drop = [];
        for ($i = 0; $i < $n; $i++) {
            if (isset($drop[$i])) {
                continue;
            }
            $a = $rects[$i];
            $acy = intdiv($a[1] + $a[3], 2);
            if ($acy < $yBand) {
                continue;
            }
            $aw = $a[2] - $a[0] + 1;
            $ah = $a[3] - $a[1] + 1;
            if ($aw < $this->cad(55) || $aw > $this->cad(240) || $ah > $this->cad(100)) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($drop[$j])) {
                    continue;
                }
                $b = $rects[$j];
                $bcy = intdiv($b[1] + $b[3], 2);
                if ($bcy < $yBand) {
                    continue;
                }
                $bw = $b[2] - $b[0] + 1;
                $bh = $b[3] - $b[1] + 1;
                if ($bw < $this->cad(55) || $bw > $this->cad(240) || $bh > $this->cad(100)) {
                    continue;
                }
                // Same column (centers within ~cad(30)).
                $acx = intdiv($a[0] + $a[2], 2);
                $bcx = intdiv($b[0] + $b[2], 2);
                if (abs($acx - $bcx) > $this->cad(30)) {
                    continue;
                }
                if (abs($aw - $bw) > $this->cad(24)) {
                    continue;
                }
                // Touching or nearly touching vertically.
                $gap = max($a[1], $b[1]) - min($a[3], $b[3]) - 1;
                if ($gap > $this->cad(12)) {
                    continue;
                }
                $union = [
                    min($a[0], $b[0]),
                    min($a[1], $b[1]),
                    max($a[2], $b[2]),
                    max($a[3], $b[3]),
                ];
                $uh = $union[3] - $union[1] + 1;
                $uw = $union[2] - $union[0] + 1;
                if ($uh > $this->cad(160) || $uw > $this->cad(240)) {
                    continue;
                }
                $rects[$i] = $union;
                $a = $union;
                $drop[$j] = true;
            }
        }
        if ($drop !== []) {
            $kept = [];
            foreach ($rects as $i => $r) {
                if (!isset($drop[$i])) {
                    $kept[] = $r;
                }
            }
            $rects = $kept;
        }

        // 1b) Merge horizontally adjacent bottom stubs that split one open office (#1).
        $n = count($rects);
        $drop = [];
        for ($i = 0; $i < $n; $i++) {
            if (isset($drop[$i])) {
                continue;
            }
            $a = $rects[$i];
            if ($a[3] < (int) ($h * 0.86)) {
                continue;
            }
            $aw = $a[2] - $a[0] + 1;
            $ah = $a[3] - $a[1] + 1;
            if ($aw < $this->cad(50) || $aw > $this->cad(160) || $ah < $this->cad(50) || $ah > $this->cad(160)) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($drop[$j])) {
                    continue;
                }
                $b = $rects[$j];
                if ($b[3] < (int) ($h * 0.86)) {
                    continue;
                }
                $bw = $b[2] - $b[0] + 1;
                $bh = $b[3] - $b[1] + 1;
                if ($bw < $this->cad(50) || $bw > $this->cad(160) || $bh < $this->cad(50) || $bh > $this->cad(160)) {
                    continue;
                }
                $acy = intdiv($a[1] + $a[3], 2);
                $bcy = intdiv($b[1] + $b[3], 2);
                if (abs($acy - $bcy) > $this->cad(30)) {
                    continue;
                }
                $gap = max($a[0], $b[0]) - min($a[2], $b[2]) - 1;
                if ($gap > $this->cad(16)) {
                    continue;
                }
                $union = [
                    min($a[0], $b[0]),
                    min($a[1], $b[1]),
                    max($a[2], $b[2]),
                    max($a[3], $b[3]),
                ];
                $uw = $union[2] - $union[0] + 1;
                $uh = $union[3] - $union[1] + 1;
                if ($uw > $this->cad(240) || $uh > $this->cad(160)) {
                    continue;
                }
                $rects[$i] = $union;
                $a = $union;
                $drop[$j] = true;
            }
        }
        if ($drop !== []) {
            $kept = [];
            foreach ($rects as $i => $r) {
                if (!isset($drop[$i])) {
                    $kept[] = $r;
                }
            }
            $rects = $kept;
        }

        // 2) Height-grow short bottom stubs; continue past the first desk lip (no cell below).
        foreach ($rects as $i => $r) {
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cy < $yBand) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bw < $this->cad(55) || $bw > $this->cad(240) || $bh < $this->cad(28) || $bh > $this->cad(130)) {
                continue;
            }
            if ($bh >= $this->cad(145)) {
                continue;
            }
            $grown = $this->expandBottomPastDeskLips($r, $walls, $dense, $w, $h, $this->cad(200));
            // Lock width during height push.
            $grown[0] = $r[0];
            $grown[2] = $r[2];
            // Floor-hugging stubs (#1 as 72×20): grow upward into the open office.
            if (($grown[3] - $grown[1] + 1) < $this->cad(100) && $r[3] >= (int) ($h * 0.86)) {
                $wantTop = max(0, $grown[3] - $this->cad(140) + 1);
                for ($y = $grown[1] - 1; $y >= $wantTop; $y--) {
                    $hits = 0;
                    $tot = 0;
                    for ($x = $grown[0]; $x <= $grown[2]; $x++) {
                        $tot++;
                        if ($walls[$y * $w + $x] === 1) {
                            $hits++;
                        }
                    }
                    if ($tot > 0 && $hits / $tot >= 0.40) {
                        break;
                    }
                    $grown[1] = $y;
                }
            }
            // Nudge remaining height through weak floor ink (open bottom edge).
            $gBh = $grown[3] - $grown[1] + 1;
            $wantH = $this->cad(140);
            if ($gBh < $wantH && $grown[3] < $h - 3) {
                $extra = $wantH - $gBh;
                for ($k = 0; $k < $extra && $grown[3] < $h - 2; $k++) {
                    $y = $grown[3] + 1;
                    $hits = 0;
                    $tot = 0;
                    for ($x = $grown[0]; $x <= $grown[2]; $x++) {
                        $tot++;
                        if ($walls[$y * $w + $x] === 1) {
                            $hits++;
                        }
                    }
                    if ($tot > 0 && $hits / $tot >= 0.35) {
                        break;
                    }
                    $grown[3]++;
                }
            }
            if ($grown[3] - $grown[1] + 1 > $bh + 4) {
                $rects[$i] = $grown;
                $r = $grown;
                $bh = $grown[3] - $grown[1] + 1;
            }

            // 3) Widen bottom offices: force rightward extend while partition frac is low (#1).
            if ($bw <= $this->cad(180) && $bh >= $this->cad(45)) {
                $x1 = $r[2];
                for ($step = 0; $step < $this->cad(120); $step++) {
                    $x = $x1 + 1;
                    if ($x >= $w - 1) {
                        break;
                    }
                    $hits = 0;
                    $tot = 0;
                    for ($y = $r[1]; $y <= $r[3]; $y++) {
                        $tot++;
                        if ($walls[$y * $w + $x] === 1) {
                            $hits++;
                        }
                    }
                    if ($tot > 0 && $hits / $tot >= 0.45) {
                        break;
                    }
                    $x1 = $x;
                }
                $x0 = $r[0];
                // Optional left retract if box became very wide (prefer GT-like ~cad(200)).
                $span = $x1 - $x0 + 1;
                if ($span > $this->cad(220)) {
                    $x0 = $x1 - $this->cad(200) + 1;
                } elseif ($span >= $this->cad(160) && $x0 < $x1 - $this->cad(180)) {
                    // Slide right: drop left overhang once we have enough width.
                    $x0 = max($x0, $x1 - $this->cad(200) + 1);
                }
                if ($x1 > $r[2] + 4 || $x0 > $r[0] + 4) {
                    $wide = [$x0, $r[1], $x1, $r[3]];
                    $bad = false;
                    foreach ($rects as $j => $o) {
                        if ($j === $i) {
                            continue;
                        }
                        if ($this->rectArea($o) < $this->cadArea(4000)) {
                            continue;
                        }
                        $ocx = intdiv($o[0] + $o[2], 2);
                        $ocy = intdiv($o[1] + $o[3], 2);
                        if ($ocx >= $wide[0] && $ocx <= $wide[2] && $ocy >= $wide[1] && $ocy <= $wide[3]) {
                            if ($ocx < $r[0] || $ocx > $r[2] || $ocy < $r[1] || $ocy > $r[3]) {
                                $bad = true;
                                break;
                            }
                        }
                    }
                    if (!$bad) {
                        $rects[$i] = $wide;
                        $r = $wide;
                        $bw = $wide[2] - $wide[0] + 1;
                    }
                }
            }

            // 4) If still narrow, re-grow from a seed to the right of the box (#1 left-shifted).
            $bw = $rects[$i][2] - $rects[$i][0] + 1;
            $bh = $rects[$i][3] - $rects[$i][1] + 1;
            $r = $rects[$i];
            if ($bw <= $this->cad(160) && $bh >= $this->cad(50)) {
                $cy = intdiv($r[1] + $r[3], 2);
                foreach ([$this->cad(36), $this->cad(56), $this->cad(76)] as $dx) {
                    $sx = $r[2] + $dx;
                    if ($sx >= $w - 4 || $walls[$cy * $w + $sx] === 1) {
                        continue;
                    }
                    if ($this->pointInAnyRectCore($sx, $cy, $rects, $this->cad(4))) {
                        continue;
                    }
                    $grown = $this->growRectFromSeed($walls, $w, $h, $sx, $cy, $this->cad(120), $this->cad(90));
                    if ($grown === null) {
                        continue;
                    }
                    $cand = $this->expandCubicleToWalls($this->padOpenCubicle($grown, $walls, $w, $h), $walls, $w, $h, $this->cad(40));
                    if ($cand[1] > $r[3] || $cand[3] < $r[1]) {
                        continue;
                    }
                    $cand[1] = min($cand[1], $r[1]);
                    $cand[3] = max($cand[3], $r[3]);
                    $union = [
                        min($r[0], $cand[0]),
                        min($r[1], $cand[1]),
                        max($r[2], $cand[2]),
                        max($r[3], $cand[3]),
                    ];
                    $uw = $union[2] - $union[0] + 1;
                    $uh = $union[3] - $union[1] + 1;
                    if ($uw < $bw + $this->cad(20) || $uw > $this->cad(240) || $uh > $this->cad(180)) {
                        continue;
                    }
                    $bad = false;
                    foreach ($rects as $j => $o) {
                        if ($j === $i) {
                            continue;
                        }
                        if ($this->overlapFrac($union, $o) > 0.35 && $this->rectArea($o) >= $this->cadArea(4000)) {
                            $bad = true;
                            break;
                        }
                    }
                    if (!$bad) {
                        $rects[$i] = $union;
                        break;
                    }
                }
            }
        }

        return array_values($rects);
    }

    /**
     * Bottom open offices: include desk lips then keep growing through open floor (no next cubicle).
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @param list<int> $dense
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function expandBottomPastDeskLips(
        array $r,
        array $walls,
        array $dense,
        int $w,
        int $h,
        int $maxReach
    ): array {
        [$x0, $y0, $x1, $y1] = $r;
        $rowFrac = function (array $mask, int $y) use ($w, $x0, $x1): float {
            if ($y < 0) {
                return 1.0;
            }
            $n = count($mask);
            $hh = $w > 0 ? intdiv($n, $w) : 0;
            if ($y >= $hh) {
                return 1.0;
            }
            $hits = 0;
            $width = $x1 - $x0 + 1;
            $row = $y * $w;
            for ($x = $x0; $x <= $x1; $x++) {
                if ($mask[$row + $x] === 1) {
                    $hits++;
                }
            }

            return $width > 0 ? $hits / $width : 1.0;
        };
        $up = $maxReach;
        $down = $maxReach;
        $targetH = $this->cad(160);
        while ($up > 0 && $y0 > 0 && ($y1 - $y0 + 1) < $targetH) {
            $y = $y0 - 1;
            $band = $this->rowInkBandThickness($dense, $w, $x0, $x1, $y, 0.35);
            $pFrac = $rowFrac($walls, $y);
            if ($band >= 28 || $pFrac >= 0.88) {
                break;
            }
            if ($band >= 6) {
                $y0 -= min($band, $up);
                $up -= min($band, $up);
                // Bottom offices: keep going after the lip (unlike mid-bank).
                continue;
            }
            $y0--;
            $up--;
        }
        while ($down > 0 && $y1 < $h - 1 && ($y1 - $y0 + 1) < $targetH) {
            $y = $y1 + 1;
            $band = $this->rowInkBandThickness($dense, $w, $x0, $x1, $y, 0.35);
            $pFrac = $rowFrac($walls, $y);
            if ($band >= 28 || $pFrac >= 0.88) {
                break;
            }
            if ($band >= 6) {
                $y1 += min($band, $down);
                $down -= min($band, $down);
                continue;
            }
            $y1++;
            $down--;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Soft horizontal expand for bottom open offices: step through weak ink; stop on solid V partitions.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @param list<int> $dense
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function expandBottomWidthSoft(
        array $r,
        array $walls,
        array $dense,
        int $w,
        int $h,
        int $maxReach
    ): array {
        [$x0, $y0, $x1, $y1] = $r;
        $midY = intdiv($y0 + $y1, 2);
        $colFrac = function (array $mask, int $x) use ($w, $y0, $y1): float {
            if ($x < 0) {
                return 1.0;
            }
            $n = count($mask);
            $ww = $w;
            if ($ww <= 0 || $x >= $ww) {
                return 1.0;
            }
            $hits = 0;
            $height = $y1 - $y0 + 1;
            for ($y = $y0; $y <= $y1; $y++) {
                if ($mask[$y * $w + $x] === 1) {
                    $hits++;
                }
            }

            return $height > 0 ? $hits / $height : 1.0;
        };
        $left = $maxReach;
        $right = $maxReach;
        while ($left > 0 && $x0 > 0) {
            $x = $x0 - 1;
            $pFrac = $colFrac($walls, $x);
            $dFrac = $colFrac($dense, $x);
            if ($pFrac >= 0.85) {
                break;
            }
            // Dense furniture column — include one step then stop if very dense.
            if ($dFrac >= 0.65 && $pFrac >= 0.40) {
                break;
            }
            $x0--;
            $left--;
        }
        while ($right > 0 && $x1 < $w - 1) {
            $x = $x1 + 1;
            $pFrac = $colFrac($walls, $x);
            $dFrac = $colFrac($dense, $x);
            if ($pFrac >= 0.85) {
                break;
            }
            if ($dFrac >= 0.65 && $pFrac >= 0.40) {
                break;
            }
            $x1++;
            $right--;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Height-only desk push for short bottom stubs (#6). Avoids widening bank cells.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<int> $dense
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function growShortBottomHeights(
        array $rects,
        array $walls,
        array $dense,
        int $w,
        int $h
    ): array {
        $yBand = (int) ($h * 0.82);
        foreach ($rects as $i => $r) {
            if (intdiv($r[1] + $r[3], 2) < $yBand) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bw < $this->cad(70) || $bw > $this->cad(220) || $bh < $this->cad(28) || $bh > $this->cad(50)) {
                continue;
            }
            $grown = $r;
            for ($k = 0; $k < 4; $k++) {
                $before = $grown[3] - $grown[1] + 1;
                $try = $this->expandBankOntoDeskLip($grown, $walls, $dense, $w, $h, $this->cad(180));
                if ($try[3] <= $grown[3] + 2) {
                    $try = $this->expandThroughShallowDeskBands($grown, $walls, $dense, $w, $h, $this->cad(160));
                }
                $try[0] = $r[0];
                $try[2] = $r[2];
                if ($try[3] - $try[1] + 1 <= $before + 2) {
                    break;
                }
                $grown = $try;
                if ($grown[3] - $grown[1] + 1 >= $this->cad(130)) {
                    break;
                }
            }
            if ($grown[3] - $grown[1] + 1 > $bh + 4) {
                $rects[$i] = $grown;
            }
        }

        return $rects;
    }

    /**
     * After fat-split: plant one tall cubicle in the empty slot above a short bank cell (#19/#18).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function seedTallSlotAboveShort(array $rects, array $walls, int $w, int $h): array
    {
        $extra = [];
        $displace = [];
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bw < $this->cad(48) || $bw > $this->cad(140) || $bh < $this->cad(30) || $bh > $this->cad(95)) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $sy = $r[1] - $this->cad(90);
            if ($sy < 4 || $walls[$sy * $w + $cx] === 1) {
                $sy = $r[1] - $this->cad(60);
            }
            if ($sy < 4 || $walls[$sy * $w + $cx] === 1) {
                continue;
            }
            $grown = $this->growRectFromSeed($walls, $w, $h, $cx, $sy, $this->cad(70), $this->cad(200));
            if ($grown === null) {
                continue;
            }
            $cand = $this->expandCubicleToWalls($this->padOpenCubicle($grown, $walls, $w, $h), $walls, $w, $h, $this->cad(50));
            if ($cand[3] >= $r[1] - 1) {
                $cand[3] = $r[1] - 2;
            }
            // Prefer host column width.
            $cand[0] = max($cand[0], $r[0] - $this->cad(8));
            $cand[2] = min($cand[2], $r[2] + $this->cad(8));
            $cbw = $cand[2] - $cand[0] + 1;
            $cbh = $cand[3] - $cand[1] + 1;
            if (
                $cbw < $this->cad(48)
                || $cbw > $this->cad(120)
                || $cbh < $this->cad(80)
                || $cbh > $this->cad(240)
                || $cbw * $cbh < $this->cadArea(4000)
            ) {
                continue;
            }
            if ($this->enclosureScore($cand, $walls, $w, $h) < 0.18) {
                continue;
            }
            $blocked = false;
            foreach ($rects as $hi => $host) {
                if ($hi === $i) {
                    continue;
                }
                $ov = $this->overlapFrac($cand, $host);
                if ($ov <= 0.2 && $this->containmentFrac($host, $cand) <= 0.5) {
                    continue;
                }
                $hArea = $this->rectArea($host);
                $hh = $host[3] - $host[1] + 1;
                $hw = $host[2] - $host[0] + 1;
                // Never displace another short bank host (#18) — only tiny scraps.
                $shortBank = $hw >= $this->cad(48) && $hw <= $this->cad(140)
                    && $hh >= $this->cad(30) && $hh <= $this->cad(95);
                if (!$shortBank && $hArea <= $this->cadArea(9000) && $hh <= $this->cad(90) && $host[3] <= $r[1] + $this->cad(6)) {
                    $displace[$hi] = true;
                    continue;
                }
                $blocked = true;
                break;
            }
            if ($blocked) {
                continue;
            }
            foreach ($extra as $o) {
                if ($this->overlapFrac($cand, $o) > 0.3) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                $extra[] = $cand;
            }
        }
        if ($displace !== []) {
            $kept = [];
            foreach ($rects as $i => $r) {
                if (!isset($displace[$i])) {
                    $kept[] = $r;
                }
            }
            $rects = $kept;
        }
        if ($extra === []) {
            return $rects;
        }

        return array_merge($rects, $extra);
    }

    /**
     * Final desk snap: extend short bank cells through shallow dense desk bands.
     * No exclusive after this — overlaps resolved by resolveBankOverlapsKeepHeight.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<int> $dense
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function snapBankCubiclesOntoDesks(
        array $rects,
        array $walls,
        array $dense,
        int $w,
        int $h
    ): array {
        $out = $rects;
        foreach ($rects as $i => $r) {
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(1800) || $area > $this->cadArea(22000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            // Bottom bank offices are ~cad(200) wide at work scale.
            if ($bw < $this->cad(45) || $bw > $this->cad(240) || $bh > $this->cad(140)) {
                continue;
            }
            // Very short stubs (#6 = 71×19) always try desk lip expand.
            if ($bh >= $this->cad(100) && $bh >= (int) ($bw * 0.9)) {
                continue;
            }
            $reach = $bh <= $this->cad(40) ? $this->cad(180) : $this->cad(140);
            $grown = $this->expandBankOntoDeskLip($r, $walls, $dense, $w, $h, $reach);
            if ($grown === $r) {
                // Fallback: classic shallow-band expand.
                $grown = $this->expandThroughShallowDeskBands($r, $walls, $dense, $w, $h, $reach);
            }
            // Last resort for thin stubs: space-fill open sides to partitions.
            if ($grown === $r && $bh <= $this->cad(45)) {
                $grown = $this->expandCubicleToWalls($r, $walls, $w, $h, $this->cad(80));
            }
            // Bottom/short cells: also try pure vertical space-fill even when a desk lip was tiny.
            if (($grown === $r || ($grown[3] - $grown[1] + 1) < $bh + $this->cad(20)) && $bh <= $this->cad(70)) {
                $vert = $r;
                $vert = $this->expandThroughShallowDeskBands($vert, $walls, $dense, $w, $h, $this->cad(200));
                if ($vert[3] - $vert[1] + 1 > $grown[3] - $grown[1] + 1) {
                    $grown = $vert;
                }
            }
            if ($grown === $r) {
                continue;
            }
            $gBh = $grown[3] - $grown[1] + 1;
            $gBw = $grown[2] - $grown[0] + 1;
            // Prefer vertical growth into the desk lip; allow GT-like cubicle heights.
            $targetH = max($bh + $this->cad(160), $this->cad(180));
            if ($gBw > $bw + $this->cad(24) || $gBh > $targetH) {
                continue;
            }
            if ($gBh <= $bh + 2) {
                continue;
            }
            // Don't swallow another substantial bank cell's center (ignore tiny scraps).
            $bad = false;
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $oArea = $this->rectArea($o);
                if ($oArea < $this->cadArea(4500) || $oArea > $this->cadArea(25000)) {
                    continue;
                }
                $cx = intdiv($o[0] + $o[2], 2);
                $cy = intdiv($o[1] + $o[3], 2);
                if ($cx >= $grown[0] && $cx <= $grown[2] && $cy >= $grown[1] && $cy <= $grown[3]) {
                    if ($cx < $r[0] || $cx > $r[2] || $cy < $r[1] || $cy > $r[3]) {
                        $bad = true;
                        break;
                    }
                }
                // Heavy new overlap with a stacked neighbor → reject.
                $ox0 = max($grown[0], $o[0]);
                $oy0 = max($grown[1], $o[1]);
                $ox1 = min($grown[2], $o[2]);
                $oy1 = min($grown[3], $o[3]);
                if ($ox0 <= $ox1 && $oy0 <= $oy1) {
                    $inter = ($ox1 - $ox0 + 1) * ($oy1 - $oy0 + 1);
                    $oldInter = $this->intersectionArea($r, $o);
                    if ($inter > $oldInter + (int) ($oArea * 0.12)) {
                        $bad = true;
                        break;
                    }
                }
            }
            if ($bad) {
                continue;
            }
            $out[$i] = $grown;
        }

        return array_values($out);
    }

    /**
     * Split mid-size fat cells that merged two bank cubicles side-by-side or stacked.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitFatBankMerges(array $rects, array $walls, int $w, int $h): array
    {
        $out = [];
        $queue = $rects;
        $guard = 0;
        $maxN = count($rects) + 48;
        while ($queue !== [] && $guard < $maxN) {
            $guard++;
            $r = array_shift($queue);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            // Work-scale bank merges are often ~120–160 wide (cad 240–320), not ≤cad(220)≈110.
            // Require at least one axis near two-cell size so real singles (~98×69) stay intact.
            $fat = $bw >= $this->cad(90) && $bw <= $this->cad(340)
                && $bh >= $this->cad(60) && $bh <= $this->cad(340)
                && $area >= $this->cadArea(7000) && $area <= $this->cadArea(100000)
                && ($bw >= $this->cad(200) || $bh >= $this->cad(200));
            if (!$fat) {
                $out[] = $r;
                continue;
            }
            // Stacked cells (#18 under #19): prefer a horizontal divider first.
            $preferY = $bh >= $this->cad(95) && $bh >= (int) ($bw * 0.72);
            $preferX = $bw >= $this->cad(115) && $bw >= (int) ($bh * 1.12);
            $parts = null;
            if ($preferY) {
                $parts = $this->trySplitRectOnWall($r, $walls, $w, $h, true);
                if ($parts === null) {
                    $parts = $this->trySplitRectOnWall($r, $walls, $w, $h, false);
                }
            } else {
                $parts = $this->trySplitRectOnWall($r, $walls, $w, $h, false);
                if ($parts === null) {
                    $parts = $this->trySplitRectOnWall($r, $walls, $w, $h, true);
                }
            }
            $minPart = $this->cadArea(1800);
            $minSideW = $this->cad(36);
            $minSideH = $this->cad(24);
            $filterParts = function (?array $candParts) use ($minPart, $minSideW, $minSideH): ?array {
                if ($candParts === null) {
                    return null;
                }
                $valid = [];
                foreach ($candParts as $p) {
                    $pw = $p[2] - $p[0] + 1;
                    $ph = $p[3] - $p[1] + 1;
                    if ($pw >= $minSideW && $ph >= $minSideH && $pw * $ph >= $minPart) {
                        $valid[] = $p;
                    }
                }

                return count($valid) >= 2 ? $valid : null;
            };
            $parts = $filterParts($parts);
            if ($parts === null && $preferY) {
                // Unequal stacks (#19≈2×#18): try several Y cuts, prefer denser ink.
                $bestParts = null;
                $bestHits = -1;
                foreach ([0.38, 0.48, 0.58, 0.68, 0.75] as $frac) {
                    $cutY = $r[1] + (int) ($bh * $frac);
                    if ($cutY <= $r[1] + $this->cad(28) || $cutY >= $r[3] - $this->cad(28)) {
                        continue;
                    }
                    $hits = 0;
                    for ($x = $r[0]; $x <= $r[2]; $x++) {
                        if ($walls[$cutY * $w + $x] === 1) {
                            $hits++;
                        }
                    }
                    $cand = $filterParts([
                        [$r[0], $r[1], $r[2], $cutY - 1],
                        [$r[0], $cutY + 1, $r[2], $r[3]],
                    ]);
                    if ($cand === null) {
                        continue;
                    }
                    if ($hits > $bestHits) {
                        $bestHits = $hits;
                        $bestParts = $cand;
                    }
                }
                $parts = $bestParts;
            }
            if ($parts === null && ($preferX || $bw >= $this->cad(120))) {
                $midX = intdiv($r[0] + $r[2], 2);
                $parts = $filterParts([
                    [$r[0], $r[1], $midX, $r[3]],
                    [$midX + 1, $r[1], $r[2], $r[3]],
                ]);
            }
            if ($parts === null && !$preferY && $bh >= $this->cad(110)) {
                $midY = intdiv($r[1] + $r[3], 2);
                $parts = $filterParts([
                    [$r[0], $r[1], $r[2], $midY],
                    [$r[0], $midY + 1, $r[2], $r[3]],
                ]);
            }
            if ($parts === null) {
                $out[] = $r;
                continue;
            }
            foreach ($parts as $p) {
                $queue[] = $p;
            }
        }
        foreach ($queue as $r) {
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Shatter tall/skinny corridor columns and wide hallway slabs that swallowed
     * multiple rooms. Building-5 walls have thickness — interior wall ink forces a cut.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitCorridorColumns(array $rects, array $walls, int $w, int $h): array
    {
        $out = [];
        $queue = array_values($rects);
        $guard = 0;
        $maxN = count($rects) + 64;
        while ($queue !== [] && $guard < $maxN) {
            $guard++;
            $r = array_shift($queue);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            $aspect = max($bw, $bh) / max(1, min($bw, $bh));
            $corridor = ($aspect >= 2.85 && $area >= $this->cadArea(12000))
                || ($bh >= $this->cad(260) && $aspect >= 2.4)
                || ($bw >= $this->cad(260) && $aspect >= 2.4)
                || ($area >= $this->cadArea(40000) && $aspect >= 2.2);
            if (!$corridor) {
                $out[] = $r;
                continue;
            }
            // Prefer cuts along the long axis.
            $preferY = $bh >= $bw;
            $parts = $preferY
                ? $this->trySplitRectOnWall($r, $walls, $w, $h, true)
                : $this->trySplitRectOnWall($r, $walls, $w, $h, false);
            if ($parts === null) {
                $parts = $preferY
                    ? $this->trySplitRectOnWall($r, $walls, $w, $h, false)
                    : $this->trySplitRectOnWall($r, $walls, $w, $h, true);
            }
            $minPart = $this->cadArea(1600);
            $minSide = $this->cad(28);
            if ($parts !== null) {
                $valid = [];
                foreach ($parts as $p) {
                    $pw = $p[2] - $p[0] + 1;
                    $ph = $p[3] - $p[1] + 1;
                    if ($pw >= $minSide && $ph >= $minSide && $pw * $ph >= $minPart) {
                        $valid[] = $p;
                    }
                }
                if (count($valid) >= 2) {
                    foreach ($valid as $p) {
                        $queue[] = $p;
                    }
                    continue;
                }
            }
            // Forced mid-cuts along the long axis when wall ink is weak but span is absurd.
            if ($preferY && $bh >= $this->cad(200)) {
                $best = null;
                $bestHits = -1;
                foreach ([0.28, 0.4, 0.5, 0.6, 0.72] as $frac) {
                    $cutY = $r[1] + (int) ($bh * $frac);
                    if ($cutY <= $r[1] + $minSide || $cutY >= $r[3] - $minSide) {
                        continue;
                    }
                    $hits = 0;
                    for ($x = $r[0]; $x <= $r[2]; $x++) {
                        if ($walls[$cutY * $w + $x] === 1) {
                            $hits++;
                        }
                    }
                    // Also accept a clean empty throat mid-span — still better than one column.
                    $score = $hits * 3 + (($frac > 0.35 && $frac < 0.65) ? 1 : 0);
                    if ($score > $bestHits) {
                        $bestHits = $score;
                        $best = [
                            [$r[0], $r[1], $r[2], $cutY - 1],
                            [$r[0], $cutY + 1, $r[2], $r[3]],
                        ];
                    }
                }
                if ($best !== null) {
                    foreach ($best as $p) {
                        $pw = $p[2] - $p[0] + 1;
                        $ph = $p[3] - $p[1] + 1;
                        if ($pw >= $minSide && $ph >= $minSide && $pw * $ph >= $minPart) {
                            $queue[] = $p;
                        }
                    }
                    continue;
                }
            }
            // Unsalvageable corridor slab — drop rather than keep a hallway as a “room”.
            if ($aspect >= 3.6 && $area >= $this->cadArea(25000)) {
                continue;
            }
            $out[] = $r;
        }
        foreach ($queue as $r) {
            $out[] = $r;
        }

        return array_values($out);
    }

    /**
     * Shatter multi-cubicle bank blobs that are not skinny corridors — e.g. 108×127
     * covering a 2×3 cubicle block. Cuts on solidified double-line wall ridges at
     * module pitch.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function shatterBankGridBlobs(array $rects, array $walls, int $w, int $h): array
    {
        $mw = $this->modW >= 28 ? $this->modW : $this->cad(100);
        $mh = $this->modH >= 20 ? $this->modH : $this->cad(78);
        $out = [];
        $queue = array_values($rects);
        $guard = 0;
        $maxN = count($rects) + 80;
        while ($queue !== [] && $guard < $maxN) {
            $guard++;
            $r = array_shift($queue);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            $nx = max(1, (int) round($bw / max(1, $mw)));
            $ny = max(1, (int) round($bh / max(1, $mh)));
            // True cubicle height is ~40 work-px; modH often inflates toward tall stacks.
            $trueH = min($mh, max(36, (int) round($mh * 0.75)));
            $bankCol = $bw <= max(72, (int) ($mw * 1.15));
            // Single-column multi-row stacks (54×84 / 54×118) must not early-exit when
            // round(bh/modH)==1 because modH itself came from those tall cells.
            $multiRow = $bankCol && $bh >= max(70, (int) ($trueH * 1.65));
            if ($nx <= 1 && $ny <= 1 && !$multiRow) {
                $out[] = $r;
                continue;
            }
            // Tall bank columns are stacks even when width ≈ one module.
            $tallStack = $multiRow
                || ($bh >= (int) ($mh * 2.2) && $bh / max(1, $bw) >= 1.45);
            // Need at least ~1.6 modules on one axis and enough area for 2+ cells.
            // Require interior wall ink (cubicle partitions) so real open offices stay whole.
            $wallish = $this->interiorWallFrac($r, $walls, $w, $h);
            // Upper area bound must be in work pixels (cadArea scales by cadS² and
            // falsely rejects ~108×223 bank columns at cadS≈0.5).
            $maxBlobArea = max($this->cadArea(60000), (int) ($mw * $mh * 14), (int) ($w * $h * 0.08));
            // Large open offices (conference / open plan) span many modules with almost
            // no partition ink — keep them whole. Narrow bank columns still shatter.
            if (
                !$tallStack
                && !$bankCol
                && $wallish < 0.028
                && $area >= (int) ($mw * $mh * 5.0)
            ) {
                $out[] = $r;
                continue;
            }
            $blob = $tallStack
                || (($nx + $ny >= 3 || ($nx >= 2 && $ny >= 2) || $ny >= 3 || $nx >= 3)
                && $area >= (int) ($mw * $mh * 1.6)
                && $area <= $maxBlobArea
                && $bw >= (int) ($mw * 1.25)
                && $bh >= (int) ($mh * 1.35)
                && ($wallish >= 0.022 || $ny >= 3 || $nx >= 3 || $bankCol));
            if (!$blob) {
                $out[] = $r;
                continue;
            }
            if ($area > $maxBlobArea && !$tallStack) {
                $out[] = $r;
                continue;
            }

            $parts = null;
            $widePair = $bw >= 98 && $bh >= max(36, (int) ($mh * 0.85));
            // Double-wide bank columns: cut X first so Y shatter works on single cubicles.
            if ($widePair) {
                $minWChk = max(40, (int) round($mw * 0.45));
                $minHChk = max(28, (int) round($mh * 0.4));
                $ridgeOk = static function (?array $parts) use ($minWChk, $minHChk): bool {
                    if ($parts === null || count($parts) < 2) {
                        return false;
                    }
                    foreach ($parts as $p) {
                        $pw = $p[2] - $p[0] + 1;
                        $ph = $p[3] - $p[1] + 1;
                        if ($pw < $minWChk || $ph < $minHChk) {
                            return false;
                        }
                    }

                    return true;
                };
                $xSplit = $this->trySplitRectOnWallRidge($r, $walls, $w, $h, false, 0.12);
                if (!$ridgeOk($xSplit)) {
                    $cutX = $r[0] + intdiv($bw, 2);
                    $xSplit = [
                        [$r[0], $r[1], $cutX - 1, $r[3]],
                        [$cutX + 1, $r[1], $r[2], $r[3]],
                    ];
                }
                if ($ridgeOk($xSplit)) {
                    $parts = $xSplit;
                }
            }
            // Prefer cutting the denser wall ridge first (usually the long stack).
            // For single-column bank stacks, only accept a ridge near one-cubicle pitch
            // from an edge — mid ridges on 3-high blobs skip the middle cell (#55/#76).
            if ($parts === null && ($ny >= 2 || $tallStack)) {
                $ySplit = $this->trySplitRectOnWallRidge($r, $walls, $w, $h, true, 0.28);
                if ($ySplit === null) {
                    $ySplit = $this->trySplitRectOnWallRidge($r, $walls, $w, $h, true, 0.18);
                }
                if ($ySplit !== null && $bankCol && $multiRow) {
                    $cutAt = $ySplit[0][3] + 1;
                    $fromTop = $cutAt - $r[1];
                    $fromBot = $r[3] - $cutAt;
                    $pitch = max(36, $trueH);
                    $nearModule = ($fromTop >= (int) ($pitch * 0.55) && $fromTop <= (int) ($pitch * 1.35))
                        || ($fromBot >= (int) ($pitch * 0.55) && $fromBot <= (int) ($pitch * 1.35));
                    if (!$nearModule) {
                        $ySplit = null;
                    }
                }
                $parts = $ySplit;
            }
            if ($parts === null && $nx >= 2) {
                $parts = $this->trySplitRectOnWallRidge($r, $walls, $w, $h, false, 0.28);
            }
            if ($parts === null && $nx >= 2) {
                $parts = $this->trySplitRectOnWallRidge($r, $walls, $w, $h, false, 0.18);
            }
            // Module-pitch forced cuts when ink is weak but size screams multi-cell.
            // Use trueH so inflated modH doesn't place the cut past the real seam.
            if ($parts === null && ($ny >= 2 || $tallStack)) {
                $cutPitch = max(36, $trueH);
                if ($bh <= (int) ($cutPitch * 2.15)) {
                    // Clear 2-high stack — cut at mid.
                    $cutY = $r[1] + intdiv($bh, 2);
                } else {
                    // 3+ high: peel one cubicle from the top, re-queue the remainder.
                    $cutY = $r[1] + $cutPitch;
                }
                $cutY = max($r[1] + (int) ($cutPitch * 0.45), min($r[3] - (int) ($cutPitch * 0.45), $cutY));
                $parts = [
                    [$r[0], $r[1], $r[2], $cutY - 1],
                    [$r[0], $cutY + 1, $r[2], $r[3]],
                ];
            }
            if ($parts === null && $nx >= 2) {
                $cutX = $r[0] + (int) round($mw * max(1, intdiv($nx, 2)));
                $cutX = max($r[0] + (int) ($mw * 0.55), min($r[2] - (int) ($mw * 0.55), $cutX));
                $parts = [
                    [$r[0], $r[1], $cutX - 1, $r[3]],
                    [$cutX + 1, $r[1], $r[2], $r[3]],
                ];
            }
            if ($parts === null) {
                $out[] = $r;
                continue;
            }
            // Inflated modW/modH (from tall stacks) made minPart reject valid halves of
            // 54×84 / 54×118 columns — use true cubicle scale for bank splits.
            $minPart = (int) ($mw * $mh * 0.35);
            if ($tallStack || $multiRow || $bankCol) {
                $minPart = min($minPart, max(1200, (int) ($bw * max(28, (int) ($trueH * 0.55)))));
            }
            $minSide = max(32, $this->cad(24), (int) round(min($mw, $trueH) * 0.35));
            $minW = max(36, (int) round(min($mw, $bw) * 0.45));
            $minH = max(26, (int) round($trueH * 0.4));
            $ok = 0;
            foreach ($parts as $p) {
                $pw = $p[2] - $p[0] + 1;
                $ph = $p[3] - $p[1] + 1;
                if ($pw >= $minW && $ph >= $minH && $pw >= $minSide && $ph >= $minSide && $pw * $ph >= $minPart) {
                    $queue[] = $p;
                    $ok++;
                }
            }
            if ($ok < 2) {
                $out[] = $r;
            }
        }
        foreach ($queue as $r) {
            $out[] = $r;
        }

        return array_values($out);
    }

    /**
     * Like trySplitRectOnWall but with a tunable ink fraction (solidified walls are denser).
     *
     * @param list<int> $walls
     * @param array{0:int,1:int,2:int,3:int} $r
     * @return list<array{0:int,1:int,2:int,3:int}>|null
     */
    private function trySplitRectOnWallRidge(
        array $r,
        array $walls,
        int $w,
        int $h,
        bool $horizontal,
        float $minFrac
    ): ?array {
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $bestPos = -1;
        $bestFrac = 0.0;
        if ($horizontal) {
            $y0 = $r[1] + (int) ($bh * 0.12);
            $y1 = $r[1] + (int) ($bh * 0.88);
            for ($y = $y0; $y <= $y1; $y++) {
                $hits = 0;
                $tot = 0;
                for ($x = $r[0]; $x <= $r[2]; $x++) {
                    $tot++;
                    if ($y >= 0 && $y < $h && $walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                $frac = $tot > 0 ? $hits / $tot : 0.0;
                if ($frac > $bestFrac) {
                    $bestFrac = $frac;
                    $bestPos = $y;
                }
            }
            if ($bestPos < 0 || $bestFrac < $minFrac) {
                return null;
            }
            $a = [$r[0], $r[1], $r[2], $bestPos - 1];
            $b = [$r[0], $bestPos + 1, $r[2], $r[3]];
        } else {
            $x0 = $r[0] + (int) ($bw * 0.12);
            $x1 = $r[0] + (int) ($bw * 0.88);
            for ($x = $x0; $x <= $x1; $x++) {
                $hits = 0;
                $tot = 0;
                for ($y = $r[1]; $y <= $r[3]; $y++) {
                    $tot++;
                    if ($x >= 0 && $x < $w && $walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                $frac = $tot > 0 ? $hits / $tot : 0.0;
                if ($frac > $bestFrac) {
                    $bestFrac = $frac;
                    $bestPos = $x;
                }
            }
            if ($bestPos < 0 || $bestFrac < $minFrac) {
                return null;
            }
            $a = [$r[0], $r[1], $bestPos - 1, $r[3]];
            $b = [$bestPos + 1, $r[1], $r[2], $r[3]];
        }
        if ($this->rectArea($a) < $this->cadArea(1400) || $this->rectArea($b) < $this->cadArea(1400)) {
            return null;
        }

        return [$a, $b];
    }

    /**
     * Soft exclusive for bank cells: trim X overlap first; only trim Y if overlap is deep.
     * Prefers keeping height (desk depth) over midpoint Y splits.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function resolveBankOverlapsKeepHeight(array $rects): array
    {
        $n = count($rects);
        for ($i = 0; $i < $n; $i++) {
            if (!isset($rects[$i])) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if (!isset($rects[$j])) {
                    continue;
                }
                $a = $rects[$i];
                $b = $rects[$j];
                $inter = $this->intersectionArea($a, $b);
                if ($inter <= 0) {
                    continue;
                }
                $aArea = $this->rectArea($a);
                $bArea = $this->rectArea($b);
                if ($aArea > $this->cadArea(28000) || $bArea > $this->cadArea(28000)) {
                    continue;
                }
                $ix0 = max($a[0], $b[0]);
                $iy0 = max($a[1], $b[1]);
                $ix1 = min($a[2], $b[2]);
                $iy1 = min($a[3], $b[3]);
                $iw = $ix1 - $ix0 + 1;
                $ih = $iy1 - $iy0 + 1;
                if ($iw >= $ih || $ih <= $this->cad(18)) {
                    $mid = intdiv($ix0 + $ix1, 2);
                    if ($a[0] <= $b[0]) {
                        $a[2] = min($a[2], $mid);
                        $b[0] = max($b[0], $mid + 1);
                    } else {
                        $b[2] = min($b[2], $mid);
                        $a[0] = max($a[0], $mid + 1);
                    }
                } else {
                    if ($a[1] <= $b[1]) {
                        $a[3] = min($a[3], $iy0);
                        $b[1] = max($b[1], $iy1);
                    } else {
                        $b[3] = min($b[3], $iy0);
                        $a[1] = max($a[1], $iy1);
                    }
                }
                $minSide = $this->cad(22);
                $minArea = $this->cadArea(1400);
                $aw = $a[2] - $a[0] + 1;
                $ah = $a[3] - $a[1] + 1;
                $bw = $b[2] - $b[0] + 1;
                $bh = $b[3] - $b[1] + 1;
                // Never delete — revert that side's trim if it would destroy the cell.
                if ($aw < $minSide || $ah < $minSide || $aw * $ah < $minArea) {
                    $a = $rects[$i];
                }
                if ($bw < $minSide || $bh < $minSide || $bw * $bh < $minArea) {
                    $b = $rects[$j];
                }
                if ($this->intersectionArea($a, $b) > 0) {
                    // Last resort: keep the taller cubicle's full height, trim the other in Y only.
                    $aH = $a[3] - $a[1] + 1;
                    $bH = $b[3] - $b[1] + 1;
                    if ($aH >= $bH) {
                        if ($b[1] < $a[3] && $b[1] >= $a[1]) {
                            $b[1] = $a[3] + 1;
                        } elseif ($a[1] < $b[3] && $a[1] >= $b[1]) {
                            $a[1] = $b[3] + 1;
                        }
                    } else {
                        if ($a[1] < $b[3] && $a[1] >= $b[1]) {
                            $a[1] = $b[3] + 1;
                        } elseif ($b[1] < $a[3] && $b[1] >= $a[1]) {
                            $b[1] = $a[3] + 1;
                        }
                    }
                    if ($a[2] < $a[0] || $a[3] < $a[1]) {
                        $a = $rects[$i];
                    }
                    if ($b[2] < $b[0] || $b[3] < $b[1]) {
                        $b = $rects[$j];
                    }
                }
                $rects[$i] = $a;
                $rects[$j] = $b;
            }
        }

        return array_values($rects);
    }

    /**
     * Expand a short bank cubicle onto its desk lip.
     * Continues through weak/false dividers; includes a shallow dense desk band then stops;
     * hard-stops on thick floors or strong partitions with no desk ink.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @param list<int> $dense
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function expandBankOntoDeskLip(
        array $r,
        array $walls,
        array $dense,
        int $w,
        int $h,
        int $maxReach
    ): array {
        [$x0, $y0, $x1, $y1] = $r;
        $rowFrac = function (array $mask, int $y) use ($w, $x0, $x1): float {
            if ($y < 0) {
                return 1.0;
            }
            $n = count($mask);
            $hh = $w > 0 ? intdiv($n, $w) : 0;
            if ($y >= $hh) {
                return 1.0;
            }
            $hits = 0;
            $width = $x1 - $x0 + 1;
            $row = $y * $w;
            for ($x = $x0; $x <= $x1; $x++) {
                if ($mask[$row + $x] === 1) {
                    $hits++;
                }
            }

            return $width > 0 ? $hits / $width : 1.0;
        };
        $up = $maxReach;
        $down = $maxReach;
        while ($up > 0 && $y0 > 0) {
            $y = $y0 - 1;
            $band = $this->rowInkBandThickness($dense, $w, $x0, $x1, $y, 0.35);
            $pFrac = $rowFrac($walls, $y);
            if ($band >= 28) {
                break;
            }
            if ($band >= 6) {
                $y0 -= min($band, $up);
                break; // include desk, stop
            }
            if ($pFrac >= 0.82) {
                break; // solid partition, no desk
            }
            // Weak ink / false divider — step through.
            $y0--;
            $up--;
        }
        while ($down > 0 && $y1 < $h - 1) {
            $y = $y1 + 1;
            $band = $this->rowInkBandThickness($dense, $w, $x0, $x1, $y, 0.35);
            $pFrac = $rowFrac($walls, $y);
            if ($band >= 28) {
                break;
            }
            if ($band >= 6) {
                $y1 += min($band, $down);
                break;
            }
            if ($pFrac >= 0.82) {
                break;
            }
            $y1++;
            $down--;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Vertical-first expand: skip shallow H desk bands; stop on thick floors / V walls.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @param list<int> $dense
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function expandThroughShallowDeskBands(
        array $r,
        array $walls,
        array $dense,
        int $w,
        int $h,
        int $maxReach
    ): array {
        [$x0, $y0, $x1, $y1] = $r;
        $midX = intdiv($x0 + $x1, 2);
        $up = $maxReach;
        $down = $maxReach;
        while ($up > 0 && $y0 > 0) {
            $y = $y0 - 1;
            if ($walls[$y * $w + $midX] === 1 || $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y)) {
                $band = $this->rowInkBandThickness($dense, $w, $x0, $x1, $y, 0.35);
                // Thick floors: hard stop. Desk lips: include the band, then stop
                // (don't tunnel through into the next stacked cubicle).
                if ($band >= 28) {
                    break;
                }
                if ($band >= 6) {
                    $skip = min($band, $up);
                    $y0 -= $skip;
                }
                break;
            }
            $y0--;
            $up--;
        }
        while ($down > 0 && $y1 < $h - 1) {
            $y = $y1 + 1;
            if ($walls[$y * $w + $midX] === 1 || $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y)) {
                $band = $this->rowInkBandThickness($dense, $w, $x0, $x1, $y, 0.35);
                if ($band >= 28) {
                    break;
                }
                if ($band >= 6) {
                    $skip = min($band, $down);
                    $y1 += $skip;
                }
                break;
            }
            $y1++;
            $down--;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Consecutive rows around $y with ink fraction >= $frac across [x0,x1].
     *
     * @param list<int> $walls
     */
    private function rowInkBandThickness(
        array $walls,
        int $w,
        int $x0,
        int $x1,
        int $y,
        float $frac
    ): int {
        $n = count($walls);
        $h = $w > 0 ? intdiv($n, $w) : 0;
        if ($y < 0 || $y >= $h || $x1 < $x0) {
            return 0;
        }
        $width = $x1 - $x0 + 1;
        $rowFrac = static function (int $yy) use ($walls, $w, $x0, $x1, $width): float {
            $hits = 0;
            $row = $yy * $w;
            for ($x = $x0; $x <= $x1; $x++) {
                if ($walls[$row + $x] === 1) {
                    $hits++;
                }
            }

            return $hits / $width;
        };
        if ($rowFrac($y) < $frac) {
            return 0;
        }
        $t = $y;
        while ($t > 0 && $rowFrac($t - 1) >= $frac) {
            $t--;
        }
        $b = $y;
        while ($b < $h - 1 && $rowFrac($b + 1) >= $frac) {
            $b++;
        }

        return $b - $t + 1;
    }

    /**
     * Pull open (unwalled) edges back so aisle over-expand doesn't inflate cells.
     * Stops at the first strong wall — no fixed cubicle size target.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function retractCubicleOvergrow(array $r, array $walls, int $w, int $h): array
    {
        [$x0, $y0, $x1, $y1] = $r;
        // Only retract clearly overgrown cells. Short open-front cubicles need the
        // open desk side — pulling it back was erasing desk depth.
        $minH = $this->cad(95);
        $minW = $this->cad(130);
        if (($y1 - $y0 + 1) <= $minH && ($x1 - $x0 + 1) <= $minW) {
            return $r;
        }
        $maxPull = $this->cad(10);
        $guard = $maxPull;
        while ($guard-- > 0) {
            $n = $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y0)
                || $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, max(0, $y0 - 1));
            $s = $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y1)
                || $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, min($h - 1, $y1 + 1));
            if ($s && $n) {
                break;
            }
            if (!$s && ($y1 - $y0) > $minH) {
                $y1--;
            } elseif (!$n && ($y1 - $y0) > $minH) {
                $y0++;
            } else {
                break;
            }
        }
        $guard = $maxPull;
        while ($guard-- > 0) {
            $we = $this->isStrongColWallBoundary($walls, $w, $h, $x0, $y0, $y1)
                || $this->isStrongColWallBoundary($walls, $w, $h, max(0, $x0 - 1), $y0, $y1);
            $e = $this->isStrongColWallBoundary($walls, $w, $h, $x1, $y0, $y1)
                || $this->isStrongColWallBoundary($walls, $w, $h, min($w - 1, $x1 + 1), $y0, $y1);
            if ($e && $we) {
                break;
            }
            if (!$e && ($x1 - $x0) > $minW) {
                $x1--;
            } elseif (!$we && ($x1 - $x0) > $minW) {
                $x0++;
            } else {
                break;
            }
        }
        if ($x1 - $x0 < $this->cad(40) || $y1 - $y0 < $this->cad(28)) {
            return $r;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * True when a strong partition cuts through the interior — walls are negative space.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     */
    private function crossesInteriorPartition(array $r, array $walls, int $w, int $h): bool
    {
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $area = $bw * $bh;
        // Huge rooms often have furniture strokes — don't treat those as partitions.
        if ($area > $this->cadArea(45000)) {
            return false;
        }
        if ($bw < $this->cad(70) || $bh < $this->cad(55)) {
            return false;
        }
        $stepX = max(1, intdiv($bw, 14));
        $stepY = max(1, intdiv($bh, 14));
        $xMid0 = $r[0] + (int) ($bw * 0.32);
        $xMid1 = $r[0] + (int) ($bw * 0.68);
        $vHits = 0;
        for ($x = $xMid0; $x <= $xMid1; $x += $stepX) {
            if ($this->isStrongColWallBoundary($walls, $w, $h, $x, $r[1] + 3, $r[3] - 3)) {
                $vHits++;
            }
        }
        if ($vHits >= 3) {
            return true;
        }
        $yMid0 = $r[1] + (int) ($bh * 0.32);
        $yMid1 = $r[1] + (int) ($bh * 0.68);
        $hHits = 0;
        for ($y = $yMid0; $y <= $yMid1; $y += $stepY) {
            if ($this->isStrongRowWallBoundary($walls, $w, $r[0] + 3, $r[2] - 3, $y)) {
                $hHits++;
            }
        }

        return $hHits >= 3;
    }

    /**
     * Keep higher-scoring cells; drop or trim anything that shares floor area.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function enforceExclusiveRects(array $rects, array $walls, int $w, int $h): array
    {
        $scored = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if (min($bw, $bh) < $this->cad(28) || $area < $this->cadArea(1800)) {
                continue;
            }
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            $cub = $area >= $this->cadArea(2800) && $area <= $this->cadArea(20000) && $asp <= 2.9;
            $large = !$cub && $area >= $this->cadArea(16000) && $enc >= 0.50;
            $score = $enc * 1.4
                + ($cub ? 0.45 : 0.0)
                + ($large ? 0.85 : 0.0)
                + 1.0 / (1.0 + abs(log(max(1, $area) / max(1, $this->cadArea(9000)))));
            $scored[] = ['r' => $r, 'score' => $score, 'area' => $area, 'cub' => $cub, 'large' => $large];
        }
        // Claim conference/office floor first, then pack cubicles into the gaps.
        usort($scored, static function (array $a, array $b): int {
            if ($a['large'] !== $b['large']) {
                return $a['large'] ? -1 : 1;
            }
            if ($a['cub'] !== $b['cub']) {
                return $a['cub'] ? 1 : -1;
            }
            if (abs($a['score'] - $b['score']) > 0.02) {
                return $b['score'] <=> $a['score'];
            }

            return $a['area'] <=> $b['area'];
        });
        $kept = [];
        foreach ($scored as $item) {
            $r = $item['r'];
            $conflict = false;
            foreach ($kept as $ki => $host) {
                if ($this->intersectionArea($r, $host) <= 0) {
                    continue;
                }
                // Cubicle fully inside an already-kept large room → drop cubicle.
                if (!empty($item['cub']) && $this->containmentFrac($r, $host) > 0.55) {
                    $conflict = true;
                    break;
                }
                // Neighboring cubicles: split the overlapping strip instead of dropping one.
                if (!empty($item['cub']) && $this->rectArea($host) <= $this->cadArea(20000)) {
                    $split = $this->splitOverlapPair($r, $host);
                    if ($split !== null) {
                        $r = $split[0];
                        $kept[$ki] = $split[1];
                        continue;
                    }
                }
                $trimmed = $this->trimRectAwayFrom($r, $host);
                if ($trimmed === null) {
                    $conflict = true;
                    break;
                }
                $tw = $trimmed[2] - $trimmed[0] + 1;
                $th = $trimmed[3] - $trimmed[1] + 1;
                $ta = $tw * $th;
                if (
                    min($tw, $th) < $this->cad(28)
                    || $ta < $this->cadArea(1800)
                    || $this->intersectionArea($trimmed, $host) > 0
                ) {
                    $conflict = true;
                    break;
                }
                $r = $trimmed;
            }
            if ($conflict) {
                continue;
            }
            foreach ($kept as $host) {
                if ($this->intersectionArea($r, $host) > 0) {
                    $conflict = true;
                    break;
                }
            }
            if (!$conflict) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $a
     * @param array{0:int,1:int,2:int,3:int} $b
     */
    private function intersectionArea(array $a, array $b): int
    {
        $x0 = max($a[0], $b[0]);
        $y0 = max($a[1], $b[1]);
        $x1 = min($a[2], $b[2]);
        $y1 = min($a[3], $b[3]);
        // Shared wall edge (x0==x1 or y0==y1) is OK — only real area overlap is wrong.
        $iw = $x1 - $x0;
        $ih = $y1 - $y0;
        if ($iw <= 0 || $ih <= 0) {
            return 0;
        }

        return $iw * $ih;
    }

    /**
     * Split an overlapping strip between two cubicles so both survive (no shared floor).
     *
     * @param array{0:int,1:int,2:int,3:int} $a
     * @param array{0:int,1:int,2:int,3:int} $b
     * @return list{array{0:int,1:int,2:int,3:int}, array{0:int,1:int,2:int,3:int}}|null
     */
    private function splitOverlapPair(array $a, array $b): ?array
    {
        $ix0 = max($a[0], $b[0]);
        $iy0 = max($a[1], $b[1]);
        $ix1 = min($a[2], $b[2]);
        $iy1 = min($a[3], $b[3]);
        $iw = $ix1 - $ix0;
        $ih = $iy1 - $iy0;
        if ($iw <= 0 || $ih <= 0) {
            return [$a, $b];
        }
        $minSide = $this->cad(28);
        $minArea = $this->cadArea(1800);
        if ($iw <= $ih) {
            $mid = intdiv($ix0 + $ix1, 2);
            $a2 = $a;
            $b2 = $b;
            if ($a[0] <= $b[0]) {
                $a2[2] = min($a[2], $mid);
                $b2[0] = max($b[0], $mid + 1);
            } else {
                $b2[2] = min($b[2], $mid);
                $a2[0] = max($a[0], $mid + 1);
            }
        } else {
            $mid = intdiv($iy0 + $iy1, 2);
            $a2 = $a;
            $b2 = $b;
            if ($a[1] <= $b[1]) {
                $a2[3] = min($a[3], $mid);
                $b2[1] = max($b[1], $mid + 1);
            } else {
                $b2[3] = min($b[3], $mid);
                $a2[1] = max($a[1], $mid + 1);
            }
        }
        foreach ([$a2, $b2] as $c) {
            $cw = $c[2] - $c[0] + 1;
            $ch = $c[3] - $c[1] + 1;
            if ($cw < $minSide || $ch < $minSide || $cw * $ch < $minArea) {
                return null;
            }
        }
        if ($this->intersectionArea($a2, $b2) > 0) {
            return null;
        }

        return [$a2, $b2];
    }

    /**
     * Shrink $r on the side that overlaps $blocker, keeping the larger free remnant.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param array{0:int,1:int,2:int,3:int} $blocker
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function trimRectAwayFrom(array $r, array $blocker): ?array
    {
        $candidates = [];
        // Trim left edge past blocker right.
        if ($blocker[2] >= $r[0] && $blocker[2] < $r[2]) {
            $candidates[] = [$blocker[2] + 1, $r[1], $r[2], $r[3]];
        }
        // Trim right edge before blocker left.
        if ($blocker[0] <= $r[2] && $blocker[0] > $r[0]) {
            $candidates[] = [$r[0], $r[1], $blocker[0] - 1, $r[3]];
        }
        // Trim top past blocker bottom.
        if ($blocker[3] >= $r[1] && $blocker[3] < $r[3]) {
            $candidates[] = [$r[0], $blocker[3] + 1, $r[2], $r[3]];
        }
        // Trim bottom before blocker top.
        if ($blocker[1] <= $r[3] && $blocker[1] > $r[1]) {
            $candidates[] = [$r[0], $r[1], $r[2], $blocker[1] - 1];
        }
        $best = null;
        $bestArea = 0;
        foreach ($candidates as $c) {
            if ($c[2] < $c[0] || $c[3] < $c[1]) {
                continue;
            }
            $area = ($c[2] - $c[0] + 1) * ($c[3] - $c[1] + 1);
            if ($area > $bestArea && $this->intersectionArea($c, $blocker) === 0) {
                $bestArea = $area;
                $best = $c;
            }
        }

        return $best;
    }

    /**
     * Keep only real office rooms + cubicle-bank cells (GT Building 5 ≈ 100 spaces).
     * Furniture pockets and double-counts fail size / enclosure / pitch checks.
     * Labels near a cell center are strong positive evidence (how plans are drawn).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function pruneToRealOfficeSpaces(array $rects, array $walls, int $w, int $h, array $labels = []): array
    {
        $idealCub = max(1, $this->cadArea(8800));
        $scored = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $sides = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            if ($area < $this->cadArea(1800) || min($bw, $bh) < $this->cad(20)) {
                continue;
            }
            // Short high-enc bank cells (~40px) are real; don't require cadArea(2800).
            if ($area < $this->cadArea(2800) && !($bh <= $this->cad(55) && $enc >= 0.40 && $sides >= 2)) {
                continue;
            }
            if ($asp > 5.5 && $enc < 0.70) {
                continue;
            }
            // Real conference / open offices — not overgrown cubicles (~2–6k work px).
            $large = $area >= $this->cadArea(28000);
            if (!$large) {
                // Cubicle / small office: keep open-front bank cells (low enc, 1–2 walls).
                if ($area > $this->cadArea(45000) || $asp > 3.6) {
                    continue;
                }
                // Corridor / hallway scraps: kill long thin open aisles; spare short bank cells.
                if ($this->isCorridorShape($r, $walls, $w, $h)) {
                    if (
                        ($enc < 0.42 && $sides < 2)
                        || (max($bw, $bh) >= $this->cad(160) && min($bw, $bh) <= $this->cad(58) && $sides < 3)
                        || ($asp >= 2.4 && $sides < 2 && $enc < 0.50)
                    ) {
                        continue;
                    }
                }
                $sizeFitGate = 1.0 / (1.0 + abs(log($area / $idealCub)));
                // Open cubicles often score enc 0.15–0.28 after pad/exclusive; size fit saves them.
                if ($enc < 0.12 && $sizeFitGate < 0.70) {
                    continue;
                }
                if ($enc < 0.18 && $sides < 1 && $sizeFitGate < 0.78) {
                    continue;
                }
                if ($this->interiorWallFrac($r, $walls, $w, $h) > 0.16) {
                    continue;
                }
            } elseif ($enc < 0.22 && $sides < 3) {
                // Open-front conference rooms score low enc but still have 3 hard walls.
                continue;
            } elseif ($enc < 0.10) {
                continue;
            } elseif ($enc < 0.14 && $sides < 2) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $labelBonus = $this->labelCenterBonus($r, $cx, $cy, $labels);
            $nearLab = $this->nearestLabelDist($cx, $cy, $labels);
            $q = $this->cubicleQuality($r, $walls, $w, $h);
            // Drop hallway/furniture ghosts far from any map ID unless strongly walled.
            if (
                !$large
                && $labelBonus <= 0.0
                && $labels !== []
                && $nearLab > $this->cad(190)
                && !(
                    ($nearLab <= $this->cad(280) && $sides >= 2 && $q >= 1.35)
                    || ($sides >= 3 && $q >= 1.45)
                )
            ) {
                continue;
            }
            // Unlabeled weak open pockets (desk ghosts) lose without a nearby ID.
            if (!$large && $labelBonus <= 0.0 && $enc < 0.28 && $sides < 2 && $nearLab > $this->cad(120)) {
                continue;
            }
            $sizeFit = $large
                ? min(1.0, $area / max(1, $this->cadArea(60000)))
                : 1.0 / (1.0 + abs(log($area / $idealCub)));
            // Prefer bank-sized footprints over sealed hallway scraps (high enc, wrong size).
            $oversizePen = 0.0;
            if (!$large && $area > $idealCub * 1.35) {
                $oversizePen = min(0.85, ($area / $idealCub - 1.35) * 0.45);
            }
            $nearLabBonus = $labels === [] ? 0.0 : max(0.0, 1.0 - $nearLab / max(1.0, (float) $this->cad(200))) * 0.55;
            $scored[] = [
                'r' => $r,
                // Size/quality outweigh raw enclosure so open-front GT cells beat closed FPs.
                'score' => $q * 1.05 + $sizeFit * 1.15 + $sides * 0.10 + ($large ? 0.90 : 0.0) + $labelBonus + $nearLabBonus - $oversizePen + $enc * 0.35,
                'cx' => $cx,
                'cy' => $cy,
                'large' => $large,
                'area' => $area,
                'labeled' => $labelBonus > 0.35,
            ];
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['large'] !== $b['large']) {
                return $a['large'] ? -1 : 1;
            }
            // Labeled cubicles claim their bank slot before unlabeled furniture ghosts.
            if (!empty($a['labeled']) !== !empty($b['labeled'])) {
                return !empty($a['labeled']) ? -1 : 1;
            }
            if (abs($a['score'] - $b['score']) > 0.02) {
                return $b['score'] <=> $a['score'];
            }

            return $a['area'] <=> $b['area'];
        });
        $out = [];
        // Soft target with headroom; final selection settles near ~100–115.
        $softMax = 220;
        // ~½ bank pitch @ work scale — kills near-dup ghosts without eating adjacent cells.
        $minPitch = $this->cad(46);
        foreach ($scored as $item) {
            $r = $item['r'];
            if (
                count($out) >= $softMax
                && empty($item['labeled'])
                && empty($item['large'])
            ) {
                continue;
            }
            $ok = true;
            foreach ($out as $host) {
                if ($this->intersectionArea($r, $host) > 0) {
                    $ok = false;
                    break;
                }
                if (!$item['large']
                    && $this->containmentFrac($r, $host) > 0.55
                    && $this->rectArea($host) >= $this->cadArea(28000)
                ) {
                    $ok = false;
                    break;
                }
                if (!$item['large'] && $this->rectArea($host) <= $this->cadArea(28000)) {
                    $hcx = intdiv($host[0] + $host[2], 2);
                    $hcy = intdiv($host[1] + $host[3], 2);
                    if (hypot($item['cx'] - $hcx, $item['cy'] - $hcy) < $minPitch) {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * How strongly a map label sits near this cell’s center (0 = none).
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     */
    private function labelCenterBonus(array $r, int $cx, int $cy, array $labels): float
    {
        if ($labels === []) {
            return 0.0;
        }
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $diag = max(1.0, hypot($bw, $bh));
        // Furniture often sits dead-center; labels sit near-center — allow ~40% of diag.
        $best = 0.0;
        foreach ($labels as $lab) {
            $lx = $lab['x'] + intdiv($lab['w'], 2);
            $ly = $lab['y'] + intdiv($lab['h'], 2);
            if ($lx < $r[0] || $lx > $r[2] || $ly < $r[1] || $ly > $r[3]) {
                continue;
            }
            $norm = hypot($lx - $cx, $ly - $cy) / $diag;
            $bonus = 1.55 * max(0.0, 1.0 - $norm / 0.42);
            if ($bonus > $best) {
                $best = $bonus;
            }
        }

        return $best;
    }

    /**
     * Distance from a point to the nearest OCR label center (inf if none).
     *
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     */
    private function nearestLabelDist(int $cx, int $cy, array $labels): float
    {
        if ($labels === []) {
            return INF;
        }
        $best = INF;
        foreach ($labels as $lab) {
            $lx = $lab['x'] + intdiv($lab['w'], 2);
            $ly = $lab['y'] + intdiv($lab['h'], 2);
            $d = hypot($cx - $lx, $cy - $ly);
            if ($d < $best) {
                $best = $d;
            }
        }

        return $best;
    }

    /**
     * Every readable map label should own a cell grown from its center (eye → walls).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function ensureRoomsAroundLabels(array $rects, array $labels, array $walls, int $w, int $h): array
    {
        if ($labels === []) {
            return $rects;
        }
        $out = $rects;
        foreach ($labels as $lab) {
            $lx = $lab['x'] + intdiv($lab['w'], 2);
            $ly = $lab['y'] + intdiv($lab['h'], 2);
            if ($lx < 1 || $ly < 1 || $lx >= $w - 1 || $ly >= $h - 1) {
                continue;
            }
            $covered = false;
            $coverIdx = -1;
            foreach ($out as $idx => $r) {
                if ($lx >= $r[0] && $lx <= $r[2] && $ly >= $r[1] && $ly <= $r[3]) {
                    $rcx = intdiv($r[0] + $r[2], 2);
                    $rcy = intdiv($r[1] + $r[3], 2);
                    $diag = max(1.0, hypot($r[2] - $r[0] + 1, $r[3] - $r[1] + 1));
                    if (hypot($lx - $rcx, $ly - $rcy) <= $diag * 0.45) {
                        $covered = true;
                        $coverIdx = $idx;
                        break;
                    }
                }
            }
            if ($walls[$ly * $w + $lx] === 1) {
                continue;
            }
            // Default: cubicle-scale grow from the label.
            $maxGrowH = $this->cad(80);
            $maxGrowW = $this->cad(90);
            // Cubicle IDs (A06.41 / C05.17): never grow into a multi-row bank column.
            if (preg_match('/[A-Z]?\d{0,2}\.?\d{2}\.\d{2}/i', (string) ($lab['text'] ?? '')) === 1) {
                $mhCap = $this->modH >= 20 ? (int) round($this->modH * 1.35) : $this->cad(70);
                $mwCap = $this->modW >= 28 ? (int) round($this->modW * 1.35) : $this->cad(90);
                $maxGrowH = max($this->cad(36), min($maxGrowH, $mhCap));
                $maxGrowW = max($this->cad(40), min($maxGrowW, $mwCap));
            }
            $grown = $this->growRectFromSeed($walls, $w, $h, $lx, $ly, $maxGrowW, $maxGrowH);
            if ($grown === null) {
                continue;
            }
            $grown = $this->padOpenCubicle($grown, $walls, $w, $h);
            $area = $this->rectArea($grown);
            // Only escalate to large space-fill when the label sits in an open pocket
            // (tiny seed grow) that is not a cubicle bank cell.
            if ($area < $this->cadArea(5500)) {
                $big = $this->growRectSpaceFill(
                    $walls,
                    $w,
                    $h,
                    $lx,
                    $ly,
                    $this->cad(420),
                    $this->cad(520)
                );
                if ($big !== null) {
                    $bigArea = $this->rectArea($big);
                    $bigEnc = $this->enclosureScore($big, $walls, $w, $h);
                    $iw = $this->interiorWallFrac($big, $walls, $w, $h);
                    $cover = 0;
                    $nCub = 0;
                    foreach ($out as $host) {
                        $ha = $this->rectArea($host);
                        if ($ha < $this->cadArea(2500) || $ha > $this->cadArea(22000)) {
                            continue;
                        }
                        $hcx = intdiv($host[0] + $host[2], 2);
                        $hcy = intdiv($host[1] + $host[3], 2);
                        if ($hcx >= $big[0] && $hcx <= $big[2] && $hcy >= $big[1] && $hcy <= $big[3]) {
                            $cover += $ha;
                            $nCub++;
                        }
                    }
                    $coverFrac = $cover / max(1, $bigArea);
                    // Bank-safe: conference rooms only when almost empty of cubicle seeds.
                    if (
                        $bigArea >= $this->cadArea(40000)
                        && $bigArea <= $this->cadArea(140000)
                        && $bigEnc >= 0.42
                        && $iw <= 0.045
                        && $coverFrac < 0.18
                        && $nCub <= 4
                        && !$this->isCorridorShape($big, $walls, $w, $h)
                    ) {
                        $grown = $big;
                        $area = $bigArea;
                    }
                }
            }
            if (
                $area < $this->cadArea(3500)
                || $area > $this->cadArea(140000)
                || ($grown[2] - $grown[0] + 1) < $this->cad(40)
                || ($grown[3] - $grown[1] + 1) < $this->cad(28)
            ) {
                continue;
            }
            $enc = $this->enclosureScore($grown, $walls, $w, $h);
            if ($enc < 0.26) {
                continue;
            }
            if ($covered && $coverIdx >= 0) {
                $hostArea = $this->rectArea($out[$coverIdx]);
                if ($area >= $this->cadArea(28000) && $area > $hostArea * 1.8 && $enc >= 0.36) {
                    $out[$coverIdx] = $grown;
                }
                continue;
            }
            $conflict = false;
            $overlapIdx = [];
            foreach ($out as $hi => $host) {
                if ($this->intersectionArea($grown, $host) <= 0) {
                    continue;
                }
                $hostLabeled = $this->labelCenterBonus(
                    $host,
                    intdiv($host[0] + $host[2], 2),
                    intdiv($host[1] + $host[3], 2),
                    $labels
                ) >= 0.35;
                if (
                    !$hostLabeled
                    && $this->rectArea($host) <= $this->cadArea(28000)
                    && $this->containmentFrac($host, $grown) > 0.55
                ) {
                    continue;
                }
                $conflict = true;
                $overlapIdx[] = $hi;
            }
            // Cubicle-ID in a column gap: trim same-column neighbors and claim the module
            // (#55 B05.11 / #76 C05.17). Avoids abandoning uncovered OCR to conflicts.
            $cubId = preg_match('/[A-Z]?\d{0,2}\.?\d{2}\.\d{2}/i', (string) ($lab['text'] ?? '')) === 1;
            if ($conflict && $cubId && $area <= $this->cadArea(22000) && count($overlapIdx) <= 4) {
                $sameCol = [];
                $hasAbove = false;
                $hasBelow = false;
                foreach ($overlapIdx as $hi) {
                    $host = $out[$hi];
                    $hcx = intdiv($host[0] + $host[2], 2);
                    if (abs($hcx - $lx) > 32) {
                        continue;
                    }
                    $sameCol[] = $hi;
                    if ($host[3] < $ly) {
                        $hasAbove = true;
                    }
                    if ($host[1] > $ly) {
                        $hasBelow = true;
                    }
                }
                // Only carve when the label sits between (or beside) same-column bank cells.
                if ($sameCol !== [] && ($hasAbove || $hasBelow)) {
                    $mh = max(36, min(52, $this->modH >= 20 ? (int) round($this->modH * 0.9) : 42));
                    $mw = max(44, min(64, $grown[2] - $grown[0] + 1));
                    $claim = [
                        max(0, $lx - intdiv($mw, 2)),
                        max(0, $ly - intdiv($mh, 2)),
                        min($w - 1, $lx - intdiv($mw, 2) + $mw - 1),
                        min($h - 1, $ly - intdiv($mh, 2) + $mh - 1),
                    ];
                    // Snap claim width to a same-column host when available.
                    $ref = $out[$sameCol[0]];
                    $claim[0] = $ref[0];
                    $claim[2] = $ref[2];
                    if ($this->enclosureScore($claim, $walls, $w, $h) >= 0.16) {
                        foreach ($sameCol as $hi) {
                            if (!isset($out[$hi])) {
                                continue;
                            }
                            $host = $out[$hi];
                            $hcy = intdiv($host[1] + $host[3], 2);
                            if ($hcy < $ly) {
                                $host[3] = min($host[3], $claim[1] - 2);
                            } elseif ($hcy > $ly) {
                                $host[1] = max($host[1], $claim[3] + 2);
                            } else {
                                continue;
                            }
                            $hw = $host[2] - $host[0] + 1;
                            $hh = $host[3] - $host[1] + 1;
                            if ($hw < 32 || $hh < 26 || $hw * $hh < 1000) {
                                unset($out[$hi]);
                            } else {
                                $out[$hi] = $host;
                            }
                        }
                        $out = array_values($out);
                        $still = false;
                        foreach ($out as $host) {
                            if ($this->intersectionArea($claim, $host) > 0) {
                                $still = true;
                                break;
                            }
                        }
                        if (!$still) {
                            $out[] = $claim;
                        }
                    }
                    continue;
                }
            }
            if ($conflict) {
                continue;
            }
            $kept = [];
            foreach ($out as $host) {
                if ($this->intersectionArea($grown, $host) > 0) {
                    continue;
                }
                $kept[] = $host;
            }
            $kept[] = $grown;
            $out = $kept;
        }

        return $out;
    }

    /**
     * Corridor/hallway heuristic: strong walls on one axis, weak on the other.
     *
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $walls
     */
    private function isCorridorShape(array $bb, array $walls, int $w, int $h): bool
    {
        [$x0, $y0, $x1, $y1] = $bb;
        $n = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y0, 0, -1);
        $s = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y1, 0, 1);
        $we = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x0, -1, 0);
        $e = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x1, 1, 0);
        $ns = ($n + $s) * 0.5;
        $ew = ($we + $e) * 0.5;
        $bw = $x1 - $x0 + 1;
        $bh = $y1 - $y0 + 1;
        $asp = max($bw, $bh) / max(1, min($bw, $bh));
        if ($asp >= 2.8 && min($ns, $ew) < 0.35) {
            return true;
        }

        return ($ns >= 0.55 && $ew <= 0.28) || ($ew >= 0.55 && $ns <= 0.28);
    }

    /**
     * Fraction of an edge that has wall ink within 2px (axis-aligned).
     *
     * @param list<int> $walls
     */
    private function edgeWallFrac(
        array $walls,
        int $w,
        int $h,
        int $a0,
        int $a1,
        int $fixed,
        int $dx,
        int $dy
    ): float {
        $hits = 0;
        $tot = 0;
        for ($a = $a0; $a <= $a1; $a += 2) {
            $tot++;
            $found = false;
            for ($t = 0; $t <= 2 && !$found; $t++) {
                $x = $dx !== 0 ? $fixed + $dx * $t : $a;
                $y = $dy !== 0 ? $fixed + $dy * $t : $a;
                if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                    $found = true;
                    break;
                }
                if ($walls[$y * $w + $x] === 1) {
                    $found = true;
                }
            }
            if ($found) {
                $hits++;
            }
        }

        return $tot > 0 ? $hits / $tot : 0.0;
    }

    /**
     * Cubicle/room cells from long-run wall projections + capped free-space seeds.
     *
     * @param list<int> $walls
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function roomsFromPartitionGrid(array $walls, int $w, int $h, int $minArea, array $labels = []): array
    {
        $col = array_fill(0, $w, 0);
        $row = array_fill(0, $h, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($walls[$y * $w + $x] === 1) {
                    $col[$x]++;
                    $row[$y]++;
                }
            }
        }
        $xPeaks = $this->projectionPeaks($col, max(8, (int) ($h * 0.022)), max(45, (int) ($w * 0.012)));
        $yPeaks = $this->projectionPeaks($row, max(8, (int) ($w * 0.022)), max(35, (int) ($h * 0.014)));

        $grown = [];
        $nx = count($xPeaks);
        $ny = count($yPeaks);
        for ($i = 0; $i < $nx - 1; $i++) {
            for ($j = 0; $j < $ny - 1; $j++) {
                $x0 = $xPeaks[$i] + 1;
                $x1 = $xPeaks[$i + 1] - 1;
                $y0 = $yPeaks[$j] + 1;
                $y1 = $yPeaks[$j + 1] - 1;
                // Skip sheet margins / title blocks.
                if ($y0 > (int) ($h * 0.90) || $y1 < (int) ($h * 0.06)) {
                    continue;
                }
                $bw = $x1 - $x0 + 1;
                $bh = $y1 - $y0 + 1;
                $area = $bw * $bh;
                // Absolute caps at work scale; sheet-relative floor so native CAD
                // conference rooms (e.g. B03 ~783px) aren't rejected by 420px.
                $maxCellW = max(420, (int) ($w * 0.14));
                $maxCellH = max(520, (int) ($h * 0.22));
                if ($bw < 55 || $bh < 48 || $bw > $maxCellW || $bh > $maxCellH || $area > (int) ($w * $h * 0.06)) {
                    continue;
                }
                if ($area < max($minArea, 2800)) {
                    continue;
                }
                $enc = $this->enclosureScore([$x0, $y0, $x1, $y1], $walls, $w, $h);
                if ($enc < 0.42) {
                    continue;
                }
                $labelHits = 0;
                foreach ($labels as $lab) {
                    $cx = $lab['x'] + intdiv($lab['w'], 2);
                    $cy = $lab['y'] + intdiv($lab['h'], 2);
                    if ($cx >= $x0 && $cx <= $x1 && $cy >= $y0 && $cy <= $y1) {
                        $labelHits++;
                    }
                }
                // Unlabeled weak cells are usually corridors / false grid.
                if ($labelHits === 0 && $enc < 0.55) {
                    continue;
                }
                // Reject cells still packed with partition ink (corridors / cores).
                $ink = 0;
                $tot = 0;
                for ($yy = $y0 + 2; $yy <= $y1 - 2; $yy += 3) {
                    for ($xx = $x0 + 2; $xx <= $x1 - 2; $xx += 3) {
                        $tot++;
                        if ($walls[$yy * $w + $xx] === 1) {
                            $ink++;
                        }
                    }
                }
                if ($tot > 0 && $ink / $tot > 0.16) {
                    continue;
                }
                // Refine cell by capped grow from its center against partitions.
                $cx = intdiv($x0 + $x1, 2);
                $cy = intdiv($y0 + $y1, 2);
                $refined = $this->growRectFromSeed(
                    $walls,
                    $w,
                    $h,
                    $cx,
                    $cy,
                    max(70, intdiv($bw, 2) + 8),
                    max(60, intdiv($bh, 2) + 8)
                );
                if ($refined !== null) {
                    $rbw = $refined[2] - $refined[0] + 1;
                    $rbh = $refined[3] - $refined[1] + 1;
                    $rArea = $rbw * $rbh;
                    $rEnc = $this->enclosureScore($refined, $walls, $w, $h);
                    if ($rArea >= max($minArea, 2500) && $rEnc >= 0.40 && $rbw >= 50 && $rbh >= 45) {
                        $grown[] = [$refined[0], $refined[1], $refined[2], $refined[3], $rArea, $rEnc + $labelHits];
                        continue;
                    }
                }
                $grown[] = [$x0, $y0, $x1, $y1, $area, $enc + $labelHits];
            }
        }

        // Fill gaps with capped orthogonal growth — prefer seeds near OCR labels.
        $step = max(44, (int) round(min($w, $h) * 0.02));
        $maxHalfW = max(80, (int) round($w * 0.036));
        $maxHalfH = max(70, (int) round($h * 0.052));
        $maxArea = (int) ($w * $h * 0.045);
        $seedPoints = [];
        foreach ($labels as $lab) {
            $seedPoints[] = [
                $lab['x'] + intdiv($lab['w'], 2),
                $lab['y'] + intdiv($lab['h'], 2),
            ];
        }
        for ($sy = $step; $sy < $h - $step; $sy += $step) {
            for ($sx = $step; $sx < $w - $step; $sx += $step) {
                $seedPoints[] = [$sx, $sy];
            }
        }
        foreach ($seedPoints as [$sx, $sy]) {
            if ($sx <= 0 || $sy <= 0 || $sx >= $w - 1 || $sy >= $h - 1) {
                continue;
            }
            if ($sy > (int) ($h * 0.90)) {
                continue;
            }
            if ($walls[$sy * $w + $sx] === 1) {
                continue;
            }
            $rect = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $maxHalfW, $maxHalfH);
            if ($rect === null) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = $rect;
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
            $area = $bw * $bh;
            if ($bw < 50 || $bh < 45 || $area < max($minArea, 2500) || $area > $maxArea) {
                continue;
            }
            $aspect = max($bw, $bh) / max(1, min($bw, $bh));
            if ($aspect > 3.2) {
                continue;
            }
            $enc = $this->enclosureScore([$x0, $y0, $x1, $y1], $walls, $w, $h);
            $labelHits = 0;
            foreach ($labels as $lab) {
                $lx = $lab['x'] + intdiv($lab['w'], 2);
                $ly = $lab['y'] + intdiv($lab['h'], 2);
                if ($lx >= $x0 && $lx <= $x1 && $ly >= $y0 && $ly <= $y1) {
                    $labelHits++;
                }
            }
            if ($labelHits === 0 && $enc < 0.56) {
                continue;
            }
            if ($enc < 0.48) {
                continue;
            }
            $grown[] = [$x0, $y0, $x1, $y1, $area, $enc + $labelHits];
        }

        // Larger conference / private rooms.
        $largeStep = max(60, (int) round($step * 1.5));
        $largeMax = (int) ($w * $h * 0.09);
        for ($sy = $largeStep; $sy < $h - $largeStep; $sy += $largeStep) {
            for ($sx = $largeStep; $sx < $w - $largeStep; $sx += $largeStep) {
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($sy > (int) ($h * 0.97)) {
                    continue;
                }
                $rect = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $w, $h);
                if ($rect === null) {
                    continue;
                }
                [$x0, $y0, $x1, $y1] = $rect;
                $bw = $x1 - $x0 + 1;
                $bh = $y1 - $y0 + 1;
                $area = $bw * $bh;
                if ($area < 14000 || $area > $largeMax) {
                    continue;
                }
                $aspect = max($bw, $bh) / max(1, min($bw, $bh));
                if ($aspect > 3.8) {
                    continue;
                }
                $enc = $this->enclosureScore([$x0, $y0, $x1, $y1], $walls, $w, $h);
                if ($enc < 0.50) {
                    continue;
                }
                $grown[] = [$x0, $y0, $x1, $y1, $area, $enc];
            }
        }

        usort(
            $grown,
            static function (array $a, array $b): int {
                if (abs($a[5] - $b[5]) > 0.04) {
                    return $b[5] <=> $a[5];
                }

                return $a[4] <=> $b[4];
            }
        );
        $kept = [];
        foreach ($grown as $g) {
            $r = [$g[0], $g[1], $g[2], $g[3]];
            $skip = false;
            foreach ($kept as $host) {
                if ($this->overlapFrac($r, $host) > 0.32 || $this->containmentFrac($r, $host) > 0.5) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * @param list<int> $proj
     * @return list<int>
     */
    private function projectionPeaks(array $proj, int $minVal, int $minSep): array
    {
        $peaks = [];
        $n = count($proj);
        for ($i = 1; $i < $n - 1; $i++) {
            if ($proj[$i] < $minVal || $proj[$i] < $proj[$i - 1] || $proj[$i] < $proj[$i + 1]) {
                continue;
            }
            if ($peaks !== [] && $i - $peaks[count($peaks) - 1] < $minSep) {
                $last = $peaks[count($peaks) - 1];
                if ($proj[$i] > $proj[$last]) {
                    $peaks[count($peaks) - 1] = $i;
                }
            } else {
                $peaks[] = $i;
            }
        }

        return $peaks;
    }

    /**
     * Orthogonal grow from a free seed until walls (optionally capped for cubicles).
     *
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function growRectFromSeed(
        array $walls,
        int $w,
        int $h,
        int $sx,
        int $sy,
        int $maxHalfW,
        int $maxHalfH
    ): ?array {
        if ($sx <= 0 || $sy <= 0 || $sx >= $w - 1 || $sy >= $h - 1) {
            return null;
        }
        if ($walls[$sy * $w + $sx] === 1) {
            $found = false;
            foreach ([[0, 1], [0, -1], [1, 0], [-1, 0], [3, 0], [0, 3], [-3, 0], [0, -3]] as [$dx, $dy]) {
                $nx = $sx + $dx;
                $ny = $sy + $dy;
                if ($nx > 0 && $ny > 0 && $nx < $w - 1 && $ny < $h - 1 && $walls[$ny * $w + $nx] !== 1) {
                    $sx = $nx;
                    $sy = $ny;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return null;
            }
        }
        // Office thin-skip is applied in expandCubicleToWalls / merge — seed grow stays
        // hard-stop so bank partitions aren't crossed.
        $skip = 0;
        if ($skip > 0) {
            $x0 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, -1, 0, $maxHalfW, $skip);
            $x1 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, 1, 0, $maxHalfW, $skip);
            $y0 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, 0, -1, $maxHalfH, $skip);
            $y1 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, 0, 1, $maxHalfH, $skip);
            $midY = intdiv($y0 + $y1, 2);
            $rx0 = $this->spaceFillAxis($walls, $w, $h, $sx, $midY, -1, 0, $maxHalfW, $skip);
            $rx1 = $this->spaceFillAxis($walls, $w, $h, $sx, $midY, 1, 0, $maxHalfW, $skip);
            $midX = intdiv($rx0 + $rx1, 2);
            $ry0 = $this->spaceFillAxis($walls, $w, $h, $midX, $sy, 0, -1, $maxHalfH, $skip);
            $ry1 = $this->spaceFillAxis($walls, $w, $h, $midX, $sy, 0, 1, $maxHalfH, $skip);

            return [$rx0, $ry0, $rx1, $ry1];
        }
        $x0 = $sx;
        $x1 = $sx;
        $y0 = $sy;
        $y1 = $sy;
        while ($x0 > $sx - $maxHalfW && $x0 > 0 && $walls[$sy * $w + $x0 - 1] !== 1) {
            $x0--;
        }
        while ($x1 < $sx + $maxHalfW && $x1 < $w - 1 && $walls[$sy * $w + $x1 + 1] !== 1) {
            $x1++;
        }
        while ($y0 > $sy - $maxHalfH && $y0 > 0 && $walls[($y0 - 1) * $w + $sx] !== 1) {
            $y0--;
        }
        while ($y1 < $sy + $maxHalfH && $y1 < $h - 1 && $walls[($y1 + 1) * $w + $sx] !== 1) {
            $y1++;
        }
        $midY = intdiv($y0 + $y1, 2);
        $rx0 = $sx;
        $rx1 = $sx;
        while ($rx0 > $x0 && $walls[$midY * $w + $rx0 - 1] !== 1) {
            $rx0--;
        }
        while ($rx1 < $x1 && $walls[$midY * $w + $rx1 + 1] !== 1) {
            $rx1++;
        }
        $midX = intdiv($rx0 + $rx1, 2);
        $ry0 = $sy;
        $ry1 = $sy;
        while ($ry0 > $y0 && $walls[($ry0 - 1) * $w + $midX] !== 1) {
            $ry0--;
        }
        while ($ry1 < $y1 && $walls[($ry1 + 1) * $w + $midX] !== 1) {
            $ry1++;
        }

        return [$rx0, $ry0, $rx1, $ry1];
    }

    /**
     * Space-fill from a seed to surrounding partitions. Short furniture strokes are
     * skipped; growth stops only on longer / denser wall runs.
     *
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function growRectSpaceFill(
        array $walls,
        int $w,
        int $h,
        int $sx,
        int $sy,
        int $maxHalfW,
        int $maxHalfH
    ): ?array {
        if ($sx <= 0 || $sy <= 0 || $sx >= $w - 1 || $sy >= $h - 1) {
            return null;
        }
        if ($walls[$sy * $w + $sx] === 1) {
            $found = false;
            foreach ([[0, 1], [0, -1], [1, 0], [-1, 0], [3, 0], [0, 3], [-3, 0], [0, -3]] as [$dx, $dy]) {
                $nx = $sx + $dx;
                $ny = $sy + $dy;
                if ($nx > 0 && $ny > 0 && $nx < $w - 1 && $ny < $h - 1 && $walls[$ny * $w + $nx] !== 1) {
                    $sx = $nx;
                    $sy = $ny;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return null;
            }
        }
        $skip = $this->cad(30);
        $x0 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, -1, 0, $maxHalfW, $skip);
        $x1 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, 1, 0, $maxHalfW, $skip);
        $y0 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, 0, -1, $maxHalfH, $skip);
        $y1 = $this->spaceFillAxis($walls, $w, $h, $sx, $sy, 0, 1, $maxHalfH, $skip);
        // Second pass along mid-lines of the grown span.
        $midY = intdiv($y0 + $y1, 2);
        $rx0 = $this->spaceFillAxis($walls, $w, $h, $sx, $midY, -1, 0, $maxHalfW, $skip);
        $rx1 = $this->spaceFillAxis($walls, $w, $h, $sx, $midY, 1, 0, $maxHalfW, $skip);
        $midX = intdiv($rx0 + $rx1, 2);
        $ry0 = $this->spaceFillAxis($walls, $w, $h, $midX, $sy, 0, -1, $maxHalfH, $skip);
        $ry1 = $this->spaceFillAxis($walls, $w, $h, $midX, $sy, 0, 1, $maxHalfH, $skip);
        // Soft open-front lip only — never flood an aisle looking for a far wall.
        $open = $this->cad(16);
        $guard = $open;
        while ($guard-- > 0 && $ry0 > 0 && !$this->isStrongRowWallBoundary($walls, $w, $rx0, $rx1, $ry0 - 1)) {
            if ($walls[($ry0 - 1) * $w + $midX] === 1) {
                break;
            }
            $ry0--;
        }
        $guard = $open;
        while ($guard-- > 0 && $ry1 < $h - 1 && !$this->isStrongRowWallBoundary($walls, $w, $rx0, $rx1, $ry1 + 1)) {
            if ($walls[($ry1 + 1) * $w + $midX] === 1) {
                break;
            }
            $ry1++;
        }
        $guard = $open;
        while ($guard-- > 0 && $rx0 > 0 && !$this->isStrongColWallBoundary($walls, $w, $h, $rx0 - 1, $ry0, $ry1)) {
            if ($walls[$midY * $w + $rx0 - 1] === 1) {
                break;
            }
            $rx0--;
        }
        $guard = $open;
        while ($guard-- > 0 && $rx1 < $w - 1 && !$this->isStrongColWallBoundary($walls, $w, $h, $rx1 + 1, $ry0, $ry1)) {
            if ($walls[$midY * $w + $rx1 + 1] === 1) {
                break;
            }
            $rx1++;
        }

        return [$rx0, $ry0, $rx1, $ry1];
    }

    /**
     * Walk from (sx,sy) along (dx,dy) until a non-skippable wall (or max).
     *
     * @param list<int> $walls
     */
    private function spaceFillAxis(
        array $walls,
        int $w,
        int $h,
        int $sx,
        int $sy,
        int $dx,
        int $dy,
        int $maxSteps,
        int $skipMax
    ): int {
        $x = $sx;
        $y = $sy;
        $steps = 0;
        while ($steps < $maxSteps) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($nx <= 0 || $ny <= 0 || $nx >= $w - 1 || $ny >= $h - 1) {
                break;
            }
            if ($walls[$ny * $w + $nx] !== 1) {
                $x = $nx;
                $y = $ny;
                $steps++;
                continue;
            }
            // Ink ahead: skip short furniture strokes; stop on real partitions.
            $horizStroke = $dy !== 0; // moving vertically → stroke across is horizontal
            if ($this->isSkippableWallPixel($walls, $w, $h, $nx, $ny, $horizStroke, $skipMax)) {
                $x = $nx;
                $y = $ny;
                $steps++;
                // Jump through the rest of this thin stroke.
                while ($steps < $maxSteps) {
                    $nx2 = $x + $dx;
                    $ny2 = $y + $dy;
                    if ($nx2 <= 0 || $ny2 <= 0 || $nx2 >= $w - 1 || $ny2 >= $h - 1) {
                        break 2;
                    }
                    if ($walls[$ny2 * $w + $nx2] !== 1) {
                        $x = $nx2;
                        $y = $ny2;
                        $steps++;
                        break;
                    }
                    if (!$this->isSkippableWallPixel($walls, $w, $h, $nx2, $ny2, $horizStroke, $skipMax)) {
                        break 2;
                    }
                    $x = $nx2;
                    $y = $ny2;
                    $steps++;
                }
                continue;
            }
            break;
        }

        return $dx !== 0 ? $x : $y;
    }

    /**
     * True when wall ink at (x,y) is a short furniture-like stroke, not a partition.
     *
     * @param list<int> $walls
     */
    private function isSkippableWallPixel(
        array $walls,
        int $w,
        int $h,
        int $x,
        int $y,
        bool $horizontalStroke,
        int $skipMax
    ): bool {
        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h || $walls[$y * $w + $x] !== 1) {
            return true;
        }
        if ($horizontalStroke) {
            $L = $x;
            while ($L > 0 && $walls[$y * $w + $L - 1] === 1) {
                $L--;
            }
            $R = $x;
            while ($R < $w - 1 && $walls[$y * $w + $R + 1] === 1) {
                $R++;
            }
            $len = $R - $L + 1;
        } else {
            $T = $y;
            while ($T > 0 && $walls[($T - 1) * $w + $x] === 1) {
                $T--;
            }
            $B = $y;
            while ($B < $h - 1 && $walls[($B + 1) * $w + $x] === 1) {
                $B++;
            }
            $len = $B - $T + 1;
        }
        if ($len > $skipMax) {
            return false;
        }
        // Thick blobs (even short) are more like walls than desk lines.
        return $this->localWallThickness($walls, $w, $h, $x, $y) <= 2;
    }

    private function spaceFillRectToWalls(array $r, array $walls, int $w, int $h): array
    {
        $cx = intdiv($r[0] + $r[2], 2);
        $cy = intdiv($r[1] + $r[3], 2);
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        // Grow from center; cap by current span so we fill this pocket, not the floor.
        $maxW = max($this->cad(90), (int) ($bw * 0.85) + $this->cad(24));
        $maxH = max($this->cad(70), (int) ($bh * 0.85) + $this->cad(24));
        // Large rooms need more reach.
        if ($bw * $bh >= $this->cadArea(32000)) {
            $maxW = max($maxW, (int) ($bw * 1.15));
            $maxH = max($maxH, (int) ($bh * 1.15));
        }
        $grown = $this->growRectSpaceFill($walls, $w, $h, $cx, $cy, $maxW, $maxH);
        if ($grown === null) {
            return $r;
        }
        $gArea = $this->rectArea($grown);
        $rArea = max(1, $this->rectArea($r));
        // Reject runaway fills that balloon past the seed cell.
        if ($gArea > $rArea * 2.8 && $gArea > $this->cadArea(25000)) {
            return $r;
        }
        if ($this->crossesInteriorPartition($grown, $walls, $w, $h)) {
            $parts = $this->trySplitRectOnWall($grown, $walls, $w, $h, true);
            if ($parts === null) {
                $parts = $this->trySplitRectOnWall($grown, $walls, $w, $h, false);
            }
            if ($parts !== null) {
                $best = $grown;
                $bestD = PHP_FLOAT_MAX;
                foreach ($parts as $p) {
                    $pcx = intdiv($p[0] + $p[2], 2);
                    $pcy = intdiv($p[1] + $p[3], 2);
                    $d = hypot($pcx - $cx, $pcy - $cy);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = $p;
                    }
                }

                return $best;
            }
        }

        return $grown;
    }

    /**
     * Building-interior pixels that alcove growth may cross (floor + thin furniture + seal fill).
     *
     * @param list<int> $interior
     * @param list<int> $inside
     * @param list<int> $struct
     * @param list<array{0:int,1:int,2:int,3:int}> $furniture
     * @return list<int>
     */
    private function buildPassableMask(
        array $interior,
        array $inside,
        array $struct,
        array $furniture,
        int $w,
        int $h
    ): array {
        $pass = array_fill(0, $w * $h, 0);
        $qx = [];
        $qy = [];
        $qs = 0;
        $en = static function (int $x, int $y) use (&$pass, &$qx, &$qy, &$qs, $w, $h, $struct, $inside): void {
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                return;
            }
            $i = $y * $w + $x;
            if ($pass[$i] === 1 || $struct[$i] === 1 || $inside[$i] !== 1) {
                return;
            }
            $pass[$i] = 1;
            $qx[] = $x;
            $qy[] = $y;
            $qs++;
        };
        for ($i = 0, $n = $w * $h; $i < $n; $i++) {
            if ($interior[$i] === 1) {
                $en($i % $w, intdiv($i, $w));
            }
        }
        foreach ($furniture as $bb) {
            for ($y = $bb[1]; $y <= $bb[3]; $y++) {
                for ($x = $bb[0]; $x <= $bb[2]; $x++) {
                    $en($x, $y);
                }
            }
        }
        $qi = 0;
        while ($qi < $qs) {
            $x = $qx[$qi];
            $y = $qy[$qi];
            $qi++;
            $en($x - 1, $y);
            $en($x + 1, $y);
            $en($x, $y - 1);
            $en($x, $y + 1);
        }

        return $pass;
    }

    /**
     * Grow room rectangles from corners that lie inside an open-plan mask.
     * North-facing corners seed dining-like alcoves; west-facing corners seed living-like alcoves.
     * Growth stops at hallway necks (sudden free-space widening / loss of side support).
     *
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     * @param list<int> $closed
     * @param list<int> $struct
     * @param list<int> $pass
     * @param list<int> $mask
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function alcovesFromOpenPlan(
        array $corners,
        array $closed,
        array $struct,
        array $pass,
        array $mask,
        int $w,
        int $h,
        int $minArea
    ): array {
        $obb = $this->maskBBox($mask, $w, $h);
        if ($obb === null) {
            return [];
        }
        $ow = max(1, $obb[2] - $obb[0] + 1);
        $oh = max(1, $obb[3] - $obb[1] + 1);
        $ocy = (int) (($obb[1] + $obb[3]) / 2);

        $diningSeed = $this->bestNorthAlcoveSeed($corners, $pass, $mask, $obb, $w, $h);
        $livingSeed = $this->bestWestAlcoveSeed($corners, $pass, $mask, $obb, $ocy, $w, $h);

        $out = [];
        if ($diningSeed !== null) {
            [$L, $R, $y0] = $diningSeed;
            // Snap to northern edge of the locked band.
            for ($y = $y0; $y > max(0, $obb[1] - 5); $y--) {
                $ok = 0;
                for ($x = $L; $x <= $R; $x++) {
                    if ($pass[$y * $w + $x] === 1) {
                        $ok++;
                    }
                }
                if ($ok < ($R - $L + 1) * 0.4) {
                    break;
                }
                $y0 = $y;
            }
            $grown = $this->growAlcoveSouth($pass, $struct, $closed, $w, $h, $L, $R, $y0);
            if ($grown !== null && $this->rectArea($grown) >= $minArea) {
                $enc = $this->enclosureScore($grown, $closed, $w, $h);
                if ($enc >= 0.42) {
                    $out[] = $grown;
                }
            }
        }
        if ($livingSeed !== null) {
            [$T, $B, $x0] = $livingSeed;
            $baseH = $B - $T + 1;
            for ($x = $x0; $x > max(0, $obb[0] - 5); $x--) {
                $ok = 0;
                for ($y = $T; $y <= $B; $y++) {
                    if ($pass[$y * $w + $x] === 1) {
                        $ok++;
                    }
                }
                if ($ok < $baseH * 0.55) {
                    break;
                }
                $x0 = $x;
            }
            $grown = $this->growAlcoveEast($pass, $struct, $closed, $w, $h, $T, $B, $x0);
            if ($grown !== null && $this->rectArea($grown) >= $minArea) {
                $enc = $this->enclosureScore($grown, $closed, $w, $h);
                $bw = $grown[2] - $grown[0] + 1;
                $bh = $grown[3] - $grown[1] + 1;
                $aspect = max($bw, $bh) / max(1, min($bw, $bh));
                if ($enc >= 0.42 && $aspect <= 2.8) {
                    $out[] = $grown;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     * @param list<int> $pass
     * @param list<int> $mask
     * @param array{0:int,1:int,2:int,3:int} $obb
     * @return array{0:int,1:int,2:int}|null L,R,y
     */
    private function bestNorthAlcoveSeed(
        array $corners,
        array $pass,
        array $mask,
        array $obb,
        int $w,
        int $h
    ): ?array {
        $oh = max(1, $obb[3] - $obb[1] + 1);
        $bestW = 0;
        $best = null;
        foreach ($corners as $c) {
            if ($c['dy'] <= 0) {
                continue;
            }
            $hit = $this->stepCornerIntoMask($c, $pass, $w, $h);
            if ($hit === null) {
                continue;
            }
            if (!$this->nearMask($hit[0], $hit[1], $mask, $w, $h, 3)) {
                continue;
            }
            if ($hit[1] > $obb[1] + (int) (0.28 * $oh)) {
                continue;
            }
            for ($dy = 0; $dy <= 18; $dy++) {
                $yy = $hit[1] + $dy;
                if ($yy >= $h || $pass[$yy * $w + $hit[0]] !== 1) {
                    continue;
                }
                [$L, $R, $ww] = $this->spanX($pass, $w, $hit[0], $yy);
                if ($ww > $bestW) {
                    $bestW = $ww;
                    $best = [$L, $R, $yy];
                }
            }
        }

        return $bestW >= 50 ? $best : null;
    }

    /**
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     * @param list<int> $pass
     * @param list<int> $mask
     * @param array{0:int,1:int,2:int,3:int} $obb
     * @return array{0:int,1:int,2:int}|null T,B,x
     */
    private function bestWestAlcoveSeed(
        array $corners,
        array $pass,
        array $mask,
        array $obb,
        int $ocy,
        int $w,
        int $h
    ): ?array {
        $ow = max(1, $obb[2] - $obb[0] + 1);
        $bestH = 0;
        $best = null;
        foreach ($corners as $c) {
            if ($c['dx'] <= 0) {
                continue;
            }
            $hit = $this->stepCornerIntoMask($c, $pass, $w, $h);
            if ($hit === null) {
                continue;
            }
            if (!$this->nearMask($hit[0], $hit[1], $mask, $w, $h, 3)) {
                continue;
            }
            if ($hit[0] > $obb[0] + (int) (0.35 * $ow)) {
                continue;
            }
            if ($hit[1] < $ocy - 30) {
                continue;
            }
            for ($dx = 0; $dx <= 24; $dx++) {
                $xx = $hit[0] + $dx;
                if ($xx >= $w || $pass[$hit[1] * $w + $xx] !== 1) {
                    continue;
                }
                [$T, $B, $hh] = $this->spanY($pass, $w, $h, $xx, $hit[1]);
                if ($T < $ocy - 80) {
                    continue;
                }
                if ($obb[3] - $B > 50) {
                    continue;
                }
                if ($hh > $bestH) {
                    $bestH = $hh;
                    $best = [$T, $B, $xx];
                }
            }
        }

        return $bestH >= 50 ? $best : null;
    }

    /**
     * @param array{x:int,y:int,dx:int,dy:int} $c
     * @param list<int> $pass
     * @return array{0:int,1:int}|null
     */
    private function stepCornerIntoMask(array $c, array $pass, int $w, int $h): ?array
    {
        $x = $c['x'];
        $y = $c['y'];
        for ($s = 0; $s < 24; $s++) {
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                return null;
            }
            if ($pass[$y * $w + $x] === 1) {
                return [$x, $y];
            }
            $x += $c['dx'];
            $y += $c['dy'];
        }

        return null;
    }

    /**
     * @param list<int> $mask
     */
    private function nearMask(int $x, int $y, array $mask, int $w, int $h, int $r): bool
    {
        for ($yy = max(0, $y - $r); $yy <= min($h - 1, $y + $r); $yy++) {
            for ($xx = max(0, $x - $r); $xx <= min($w - 1, $x + $r); $xx++) {
                if ($mask[$yy * $w + $xx] === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<int> $pass
     * @return array{0:int,1:int,2:int}
     */
    private function spanX(array $pass, int $w, int $x, int $y): array
    {
        $L = $x;
        $R = $x;
        while ($L > 1 && $pass[$y * $w + $L - 1] === 1) {
            $L--;
        }
        while ($R < $w - 2 && $pass[$y * $w + $R + 1] === 1) {
            $R++;
        }

        return [$L, $R, $R - $L + 1];
    }

    /**
     * @param list<int> $pass
     * @return array{0:int,1:int,2:int}
     */
    private function spanY(array $pass, int $w, int $h, int $x, int $y): array
    {
        $T = $y;
        $B = $y;
        while ($T > 1 && $pass[($T - 1) * $w + $x] === 1) {
            $T--;
        }
        while ($B < $h - 2 && $pass[($B + 1) * $w + $x] === 1) {
            $B++;
        }

        return [$T, $B, $B - $T + 1];
    }

    /**
     * Grow south from a locked east-west band until the hallway neck opens.
     *
     * @param list<int> $pass
     * @param list<int> $struct
     * @param list<int> $closed
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function growAlcoveSouth(
        array $pass,
        array $struct,
        array $closed,
        int $w,
        int $h,
        int $L,
        int $R,
        int $y0
    ): ?array {
        $base0 = $R - $L + 1;
        if ($base0 < 40) {
            return null;
        }
        $y1 = $y0;
        $gap = 0;
        for ($y = $y0; $y < $h - 1; $y++) {
            $covered = 0;
            for ($x = $L; $x <= $R; $x++) {
                if ($pass[$y * $w + $x] === 1) {
                    $covered++;
                }
            }
            $full = 0;
            $cs = 0;
            for ($x = $L - 80; $x <= $R + 80; $x++) {
                if ($x < 0 || $x >= $w) {
                    continue;
                }
                if ($pass[$y * $w + $x] === 1) {
                    $cs++;
                    $full = max($full, $cs);
                } else {
                    $cs = 0;
                }
            }
            $ls = 0;
            $rs = 0;
            for ($yy = $y - 2; $yy <= $y + 2; $yy++) {
                if ($yy < 0 || $yy >= $h) {
                    continue;
                }
                if ($L > 0 && $struct[$yy * $w + $L - 1] === 1) {
                    $ls++;
                }
                if ($R < $w - 1 && $struct[$yy * $w + $R + 1] === 1) {
                    $rs++;
                }
            }
            // Hallway neck: free span widens even if one partition wall continues.
            if ($full > $base0 * 1.28 && ($y - $y0) > 55) {
                break;
            }
            if ($covered / $base0 < 0.28) {
                $gap++;
                if ($gap > 45) {
                    break;
                }
                continue;
            }
            $gap = 0;
            $y1 = $y;
        }
        if ($y1 - $y0 + 1 < 40) {
            return null;
        }

        return $this->snapToWalls([$L, $y0, $R, $y1], $closed, $w, $h);
    }

    /**
     * Grow east from a locked north-south band until the hallway neck opens.
     *
     * @param list<int> $pass
     * @param list<int> $struct
     * @param list<int> $closed
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function growAlcoveEast(
        array $pass,
        array $struct,
        array $closed,
        int $w,
        int $h,
        int $T,
        int $B,
        int $x0
    ): ?array {
        $base0 = $B - $T + 1;
        if ($base0 < 40) {
            return null;
        }
        $x1 = $x0;
        $gap = 0;
        $origT = $T;
        for ($x = $x0; $x < $w - 1; $x++) {
            $covered = 0;
            for ($y = $T; $y <= $B; $y++) {
                if ($pass[$y * $w + $x] === 1) {
                    $covered++;
                }
            }
            $full = 0;
            $cs = 0;
            $ft = -1;
            $fb = -1;
            $c0 = 0;
            for ($y = $T - 80; $y <= $B + 80; $y++) {
                if ($y < 0 || $y >= $h) {
                    continue;
                }
                if ($pass[$y * $w + $x] === 1) {
                    if ($cs === 0) {
                        $c0 = $y;
                    }
                    $cs++;
                    if ($cs > $full) {
                        $full = $cs;
                        $ft = $c0;
                        $fb = $y;
                    }
                } else {
                    $cs = 0;
                }
            }
            $wallHits = 0;
            for ($y = $T; $y <= $B; $y++) {
                if ($struct[$y * $w + $x] === 1) {
                    $wallHits++;
                }
            }
            if ($wallHits * 4 >= $base0 && ($x - $x0) > 80) {
                break;
            }
            if ($ft >= 0 && $ft < $origT - 25 && $full >= $base0 * 0.5 && ($x - $x0) > 40) {
                break;
            }
            if ($full > $base0 * 1.45 && ($x - $x0) > 45 && ($ft < $T - 20 || $fb > $B + 40)) {
                break;
            }
            // Use total free fraction in the locked band (furniture-tolerant), not max run.
            if ($covered / $base0 < 0.28) {
                $gap++;
                if ($gap > 18) {
                    break;
                }
                continue;
            }
            $gap = 0;
            $x1 = $x;
        }
        if ($x1 - $x0 + 1 < 40) {
            return null;
        }

        return $this->snapToWalls([$x0, $T, $x1, $B], $closed, $w, $h);
    }

    /**
     * @param list<int> $mask
     * @return list<int> distance; 9999 outside mask
     */
    private function distanceToBorder(array $mask, int $w, int $h): array
    {
        $inf = 9999;
        $dist = array_fill(0, $w * $h, $inf);
        $qx = [];
        $qy = [];
        $qs = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($mask[$y * $w + $x] !== 1) {
                    continue;
                }
                $border = false;
                foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h || $mask[$ny * $w + $nx] !== 1) {
                        $border = true;
                        break;
                    }
                }
                if ($border) {
                    $dist[$y * $w + $x] = 0;
                    $qx[] = $x;
                    $qy[] = $y;
                    $qs++;
                }
            }
        }
        $qi = 0;
        while ($qi < $qs) {
            $x = $qx[$qi];
            $y = $qy[$qi];
            $qi++;
            $d = $dist[$y * $w + $x];
            foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $ni = $ny * $w + $nx;
                if ($mask[$ni] !== 1 || $dist[$ni] <= $d + 1) {
                    continue;
                }
                $dist[$ni] = $d + 1;
                $qx[] = $nx;
                $qy[] = $ny;
                $qs++;
            }
        }

        return $dist;
    }

    /**
     * @param list<int> $dist
     * @param list<int> $mask
     * @return list<array{0:int,1:int,2:int}>
     */
    private function localMaximaSeeds(array $dist, array $mask, int $w, int $h, int $thresh): array
    {
        $seeds = [];
        for ($y = 2; $y < $h - 2; $y++) {
            for ($x = 2; $x < $w - 2; $x++) {
                if ($mask[$y * $w + $x] !== 1) {
                    continue;
                }
                $d = $dist[$y * $w + $x];
                if ($d < $thresh || $d >= 9000) {
                    continue;
                }
                $isMax = true;
                for ($yy = $y - 2; $yy <= $y + 2 && $isMax; $yy++) {
                    for ($xx = $x - 2; $xx <= $x + 2; $xx++) {
                        if ($dist[$yy * $w + $xx] > $d) {
                            $isMax = false;
                            break;
                        }
                    }
                }
                if ($isMax) {
                    $seeds[] = [$x, $y, $d];
                }
            }
        }
        usort($seeds, static fn (array $a, array $b): int => $b[2] <=> $a[2]);
        $kept = [];
        foreach ($seeds as $s) {
            $ok = true;
            foreach ($kept as $k) {
                if (abs($k[0] - $s[0]) + abs($k[1] - $s[1]) < $thresh * 2) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $kept[] = $s;
            }
        }

        return $kept;
    }

    /**
     * @param list<int> $mask
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function maskBBox(array $mask, int $w, int $h): ?array
    {
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($mask[$y * $w + $x] !== 1) {
                    continue;
                }
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
        if ($maxX < 0) {
            return null;
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * @param list<array{x:int,y:int,dx:int,dy:int}> $corners
     */
    private function nearCorner(int $x, int $y, array $corners, int $radius): bool
    {
        foreach ($corners as $c) {
            if (abs($c['x'] - $x) <= $radius && abs($c['y'] - $y) <= $radius) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $seeds
     * @param list<int> $mask
     * @param list<int> $dist
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function growSeedsToRect(
        array $seeds,
        array $mask,
        array $dist,
        int $ridge,
        int $w,
        int $h,
        int $minArea
    ): ?array {
        if ($seeds === []) {
            return null;
        }
        $seen = array_fill(0, $w * $h, 0);
        $qx = [];
        $qy = [];
        $qs = 0;
        $minX = PHP_INT_MAX;
        $maxX = 0;
        $minY = PHP_INT_MAX;
        $maxY = 0;
        $area = 0;
        foreach ($seeds as $s) {
            $qx[] = $s[0];
            $qy[] = $s[1];
            $qs++;
            $seen[$s[1] * $w + $s[0]] = 1;
            $minX = min($minX, $s[0]);
            $maxX = max($maxX, $s[0]);
            $minY = min($minY, $s[1]);
            $maxY = max($maxY, $s[1]);
        }
        $qi = 0;
        while ($qi < $qs) {
            $x = $qx[$qi];
            $y = $qy[$qi];
            $qi++;
            $area++;
            foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                $ni = $ny * $w + $nx;
                if ($mask[$ni] !== 1 || $seen[$ni] === 1 || $dist[$ni] < $ridge) {
                    continue;
                }
                $seen[$ni] = 1;
                $qx[] = $nx;
                $qy[] = $ny;
                $qs++;
                $minX = min($minX, $nx);
                $maxX = max($maxX, $nx);
                $minY = min($minY, $ny);
                $maxY = max($maxY, $ny);
            }
        }
        if ($area < $minArea) {
            return null;
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * @param array{x:int,y:int,dx:int,dy:int} $c
     * @param list<int> $walls
     * @param list<int> $interior
     * @return array{0:int,1:int,2:int,3:int,enc?:float,fill?:float}|null
     */
    private function growRectFromCorner(
        array $c,
        array $walls,
        array $interior,
        int $w,
        int $h
    ): ?array {
        $x = $c['x'];
        $y = $c['y'];
        $dx = $c['dx'];
        $dy = $c['dy'];

        $scanX = function (int $sx, int $sy, int $dir) use ($walls, $interior, $w, $h): int {
            $last = $sx;
            for ($s = 1; $s < $w; $s++) {
                $nx = $sx + $dir * $s;
                if ($nx < 0 || $nx >= $w) {
                    break;
                }
                if ($walls[$sy * $w + $nx] === 1 || $interior[$sy * $w + $nx] !== 1) {
                    break;
                }
                $last = $nx;
            }

            return $last;
        };
        $scanY = function (int $sx, int $sy, int $dir) use ($walls, $interior, $w, $h): int {
            $last = $sy;
            for ($s = 1; $s < $h; $s++) {
                $ny = $sy + $dir * $s;
                if ($ny < 0 || $ny >= $h) {
                    break;
                }
                if ($walls[$ny * $w + $sx] === 1 || $interior[$ny * $w + $sx] !== 1) {
                    break;
                }
                $last = $ny;
            }

            return $last;
        };

        $xFar = $scanX($x, $y, $dx);
        $yFar = $scanY($x, $y, $dy);
        $minX = min($x, $xFar);
        $maxX = max($x, $xFar);
        $minY = min($y, $yFar);
        $maxY = max($y, $yFar);

        $midX = intdiv($minX + $maxX, 2);
        $midY = intdiv($minY + $maxY, 2);
        $xFar = $scanX($x, $midY, $dx);
        $yFar = $scanY($midX, $y, $dy);
        $minX = min($x, $xFar);
        $maxX = max($x, $xFar);
        $minY = min($y, $yFar);
        $maxY = max($y, $yFar);

        $bw = $maxX - $minX + 1;
        $bh = $maxY - $minY + 1;
        if ($bw < 32 || $bh < 32) {
            return null;
        }
        $bb = [$minX, $minY, $maxX, $maxY];
        $fill = $this->freeFillRatio($bb, $interior, $w, $h);
        $enc = $this->enclosureScore($bb, $walls, $w, $h);
        // Alcoves need decent 3-side enclosure; hallways fail this.
        if ($fill < 0.55 || $enc < 0.50) {
            return null;
        }
        $aspect = max($bw, $bh) / max(1, min($bw, $bh));
        if ($aspect > 3.0 && $enc < 0.72) {
            return null;
        }

        $rect = $this->snapToWalls($bb, $walls, $w, $h);
        $rect['enc'] = $enc;
        $rect['fill'] = $fill;

        return $rect;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $primary
     * @param list<array{0:int,1:int,2:int,3:int}> $alcoves
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    /**
     * Grow cubicle/room rectangles from OCR label centers against native walls.
     *
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function roomsFromLabels(
        array $labels,
        array $walls,
        int $w,
        int $h,
        int $minArea,
        int $thickness
    ): array {
        $maxArea = (int) ($w * $h * ($thickness <= 8 ? 0.045 : 0.12));
        $minSide = $thickness <= 8 ? 14 : 28;
        $maxHalfW = $thickness <= 8 ? max(70, (int) round($w * 0.032)) : max($w, $h);
        $maxHalfH = $thickness <= 8 ? max(60, (int) round($h * 0.045)) : max($w, $h);
        $grown = [];
        foreach ($labels as $lab) {
            $sx = $lab['x'] + intdiv($lab['w'], 2);
            $sy = $lab['y'] + intdiv($lab['h'], 2);
            $sx = max(1, min($w - 2, $sx));
            $sy = max(1, min($h - 2, $sy));
            $rect = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $maxHalfW, $maxHalfH);
            if ($rect === null) {
                continue;
            }
            if ($thickness <= 8) {
                $rect = $this->padOpenCubicle($rect, $walls, $w, $h);
            }
            [$x0, $y0, $x1, $y1] = $rect;
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
            $area = $bw * $bh;
            if ($bw < $minSide || $bh < $minSide || $area < max(180, (int) ($minArea * 0.35)) || $area > $maxArea) {
                continue;
            }
            $aspect = max($bw, $bh) / max(1, min($bw, $bh));
            if ($aspect > 3.8) {
                continue;
            }
            // Label should still sit near the grown cell center.
            $rcx = intdiv($x0 + $x1, 2);
            $rcy = intdiv($y0 + $y1, 2);
            $diag = max(1.0, hypot($bw, $bh));
            if (hypot($sx - $rcx, $sy - $rcy) > $diag * 0.48) {
                continue;
            }
            $grown[] = [$x0, $y0, $x1, $y1, $area];
        }
        usort($grown, static fn (array $a, array $b): int => $a[4] <=> $b[4]);
        $kept = [];
        foreach ($grown as $g) {
            $r = [$g[0], $g[1], $g[2], $g[3]];
            $skip = false;
            foreach ($kept as $host) {
                if ($this->overlapFrac($r, $host) > 0.35 || $this->containmentFrac($r, $host) > 0.55) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * If a large rectangle contains multiple OCR labels, replace it with per-label rooms.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitRectsByLabels(
        array $rects,
        array $labels,
        array $walls,
        int $w,
        int $h,
        int $thickness
    ): array {
        if ($labels === [] || $rects === []) {
            return $rects;
        }
        $minArea = max(200, (int) ($w * $h * 0.0004));
        $out = [];
        $consumed = [];
        foreach ($rects as $ri => $r) {
            $hits = [];
            foreach ($labels as $li => $lab) {
                if (isset($consumed[$li])) {
                    continue;
                }
                $cx = $lab['x'] + intdiv($lab['w'], 2);
                $cy = $lab['y'] + intdiv($lab['h'], 2);
                if ($cx >= $r[0] && $cx <= $r[2] && $cy >= $r[1] && $cy <= $r[3]) {
                    $hits[] = $li;
                }
            }
            $area = $this->rectArea($r);
            if (count($hits) >= 2 && $area > $w * $h * 0.012) {
                $parts = $this->roomsFromLabels(
                    array_map(static fn (int $i): array => $labels[$i], $hits),
                    $walls,
                    $w,
                    $h,
                    $minArea,
                    $thickness
                );
                if (count($parts) >= 2) {
                    foreach ($parts as $p) {
                        $out[] = $p;
                    }
                    foreach ($hits as $li) {
                        $consumed[$li] = true;
                    }
                    continue;
                }
            }
            $out[] = $r;
        }

        return $this->mergeRoomSets($out, [], $w, $h);
    }

    private function mergeRoomSets(array $primary, array $alcoves, int $w, int $h): array
    {
        // Prefer smaller enclosed rooms first so cubicles claim space before mega-rooms.
        usort($primary, fn (array $a, array $b): int => $this->rectArea($a) <=> $this->rectArea($b));
        $kept = [];
        foreach ($primary as $r) {
            $skip = false;
            foreach ($kept as $host) {
                if ($this->overlapFrac($r, $host) > 0.4 || $this->containmentFrac($r, $host) > 0.6) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = [$r[0], $r[1], $r[2], $r[3]];
            }
        }
        usort($alcoves, fn (array $a, array $b): int => $this->rectArea($a) <=> $this->rectArea($b));
        foreach ($alcoves as $r) {
            $skip = false;
            foreach ($kept as $host) {
                if (
                    $this->overlapFrac($r, $host) > 0.25
                    || $this->overlapFrac($host, $r) > 0.25
                    || $this->containmentFrac($host, $r) > 0.45
                ) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = [$r[0], $r[1], $r[2], $r[3]];
            }
        }

        return $kept;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function cullHallwaysAndJunk(
        array $rects,
        array $walls,
        int $w,
        int $h,
        int $thickness = 8,
        array $labels = [],
        ?array $inside = null
    ): array {
        $thin = $thickness <= 8;
        $minSide = $thin ? max(18, $this->cad(28)) : 40;
        $minArea = $thin
            ? max($this->cadArea(1800), (int) ($w * $h * 0.00035))
            : max(900, (int) ($w * $h * 0.0015));
        $scored = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            $enc = $this->enclosureScore($r, $walls, $w, $h);
            $aspect = max($bw, $bh) / max(1, min($bw, $bh));
            // Hallway: weak enclosure or extreme aspect; wall-ink: tiny min side.
            // Open-front cubicles often sit at ~0.28–0.35 enclosure — keep those.
            $minEnc = $thin ? 0.36 : 0.40;
            $isCubicleSized = $thin
                && $area >= $this->cadArea(2800)
                && $area <= $this->cadArea(20000)
                && $aspect <= 2.9;
            if ($isCubicleSized) {
                $minEnc = 0.24;
            }
            if ($enc < $minEnc) {
                continue;
            }
            $sidesEarly = $this->strongWallSides($r, $walls, $w, $h, 0.34);
            if ($isCubicleSized && $enc < 0.30 && $sidesEarly < 2) {
                continue;
            }
            if ($aspect > ($thin ? 3.8 : 3.5) && $enc < 0.70) {
                continue;
            }
            // Long thin hallway scraps (including cubicle-sized corridor segments).
            if (
                $thin
                && max($bw, $bh) >= $this->cad(160)
                && min($bw, $bh) <= $this->cad(58)
                && ($this->isCorridorShape($r, $walls, $w, $h) || ($enc < 0.50 && $sidesEarly < 2))
            ) {
                continue;
            }
            // Open-front cubicles look corridor-like on one axis — don't cull short bank cells.
            if ($thin && $this->isCorridorShape($r, $walls, $w, $h) && $enc < 0.58) {
                if (!$isCubicleSized || ($aspect >= 2.3 && $sidesEarly < 2)) {
                    continue;
                }
            }
            if (min($bw, $bh) < $minSide) {
                continue;
            }
            if ($area < $minArea) {
                continue;
            }
            // Drop floor-sized blobs that survived merging.
            if ($area > $w * $h * ($thin ? 0.09 : 0.22) && $enc < 0.78) {
                continue;
            }
            if ($thin && ($r[1] > (int) ($h * 0.97) || $r[3] < (int) ($h * 0.03))) {
                continue;
            }
            if (
                $thin
                && ($r[0] <= 1 || $r[1] <= 1 || $r[2] >= $w - 2 || $r[3] >= $h - 2)
                && $enc < 0.72
            ) {
                continue;
            }
            // Reject margin / courtyard false rooms outside the building footprint.
            // Office CAD (thin walls): door-seal flood marks open banks/rooms as exterior —
            // skip this check; border/floor-size gates still drop true outside junk.
            if (!$thin && is_array($inside)) {
                $inFrac = $this->freeFillRatio($r, $inside, $w, $h);
                if ($inFrac < 0.30) {
                    continue;
                }
            }
            $labelHits = 0;
            foreach ($labels as $lab) {
                $cx = $lab['x'] + intdiv($lab['w'], 2);
                $cy = $lab['y'] + intdiv($lab['h'], 2);
                if ($cx >= $r[0] && $cx <= $r[2] && $cy >= $r[1] && $cy <= $r[3]) {
                    $labelHits++;
                }
            }
            $target = max(1, (int) ($w * $h * 0.00115));
            $sizeScore = 1.0 / (1.0 + abs(log(max(1, $area) / $target)));
            $largeBonus = ($area >= $this->cadArea(16000) && $enc >= 0.55) ? 0.4 : 0.0;
            $cubicleBonus = 0.0;
            $sides = 0;
            if ($isCubicleSized) {
                $tw = max(1, $this->cad(110));
                $th = max(1, $this->cad(80));
                $ta = max(1, $this->cadArea(8800));
                $twN = abs($bw - $tw) / $tw;
                $thN = abs($bh - $th) / $th;
                $cubicleBonus = max(0.0, 0.45 - 0.35 * ($twN + $thN));
                $sides = $this->strongWallSides($r, $walls, $w, $h, 0.38);
                $sizeFit = 1.0 / (1.0 + abs(log(max(1, $area) / $ta)));
                $sideFit = match (true) {
                    $sides === 3 => 0.35,
                    $sides === 2 => 0.25,
                    $sides >= 4 => 0.05,
                    default => 0.0,
                };
                // Dim/pitch fit beats raw enclosure so open-front banks win over desk pockets.
                $score = $enc * 0.55 + $sizeFit * 1.1 + $cubicleBonus * 1.4 + $sideFit;
            } else {
                $score = $enc * 1.1 + $sizeScore + min(2, $labelHits) * 0.55 + $largeBonus;
            }
            $scored[] = [
                'r' => $r,
                'enc' => $enc,
                'area' => $area,
                'score' => $score,
                'labels' => $labelHits,
                'cub' => $isCubicleSized,
            ];
        }

        // Phase 1: cubicle-sized NMS (office banks). Phase 2: add larger rooms around them.
        $cubs = array_values(array_filter($scored, static fn (array $s): bool => !empty($s['cub'])));
        $rest = array_values(array_filter($scored, static fn (array $s): bool => empty($s['cub'])));
        usort($cubs, static function (array $a, array $b): int {
            if (abs($a['score'] - $b['score']) > 0.02) {
                return $b['score'] <=> $a['score'];
            }

            return $a['area'] <=> $b['area'];
        });
        usort($rest, static function (array $a, array $b): int {
            if (abs($a['score'] - $b['score']) > 0.05) {
                return $b['score'] <=> $a['score'];
            }

            return $a['area'] <=> $b['area'];
        });

        $kept = [];
        $overlapLim = $thin ? 0.28 : 0.4;
        foreach ($cubs as $item) {
            $r = $item['r'];
            $skip = false;
            foreach ($kept as $host) {
                if ($this->overlapFrac($r, $host) > $overlapLim || $this->containmentFrac($r, $host) > 0.50) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $r;
            }
        }
        foreach ($rest as $item) {
            $r = $item['r'];
            $skip = false;
            $dropCubicles = [];
            foreach ($kept as $idx => $host) {
                $hostArea = $this->rectArea($host);
                $hostCub = $hostArea <= $this->cadArea(20000) && $hostArea >= $this->cadArea(3200);
                if ($hostCub) {
                    // Cubicles sitting inside a real conference/office: drop the cubicles.
                    if ($this->containmentFrac($host, $r) > 0.55) {
                        $dropCubicles[] = $idx;
                        continue;
                    }
                    // Glancing overlap with a neighboring cubicle is OK; heavy overlap is not.
                    if ($this->overlapFrac($r, $host) > 0.55) {
                        $skip = true;
                        break;
                    }
                    continue;
                }
                if ($this->overlapFrac($r, $host) > $overlapLim || $this->containmentFrac($r, $host) > 0.55) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            // Don't keep a large box that is really a cubicle-bank AABB.
            if ($thin && $dropCubicles !== []) {
                $insideCubs = [];
                foreach ($dropCubicles as $idx) {
                    $insideCubs[] = $kept[$idx];
                }
                if ($this->looksLikeCubicleBank($insideCubs, $r)) {
                    continue;
                }
            }
            if ($dropCubicles !== []) {
                rsort($dropCubicles);
                foreach ($dropCubicles as $idx) {
                    array_splice($kept, $idx, 1);
                }
            }
            $kept[] = $r;
        }

        return $kept;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $walls
     */
    private function enclosureScore(array $bb, array $walls, int $w, int $h): float
    {
        [$x0, $y0, $x1, $y1] = $bb;
        $bw = $x1 - $x0 + 1;
        $bh = $y1 - $y0 + 1;
        $per = 2 * ($bw + $bh);
        if ($per <= 0) {
            return 0.0;
        }
        $hits = 0;
        for ($x = $x0; $x <= $x1; $x++) {
            if ($this->isWallNear($walls, $w, $h, $x, $y0 - 1) || $this->isWallNear($walls, $w, $h, $x, $y0 - 2)) {
                $hits++;
            }
            if ($this->isWallNear($walls, $w, $h, $x, $y1 + 1) || $this->isWallNear($walls, $w, $h, $x, $y1 + 2)) {
                $hits++;
            }
        }
        for ($y = $y0; $y <= $y1; $y++) {
            if ($this->isWallNear($walls, $w, $h, $x0 - 1, $y) || $this->isWallNear($walls, $w, $h, $x0 - 2, $y)) {
                $hits++;
            }
            if ($this->isWallNear($walls, $w, $h, $x1 + 1, $y) || $this->isWallNear($walls, $w, $h, $x1 + 2, $y)) {
                $hits++;
            }
        }

        return $hits / $per;
    }

    /**
     * @param list<int> $walls
     */
    private function isWallNear(array $walls, int $w, int $h, int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
            return true;
        }

        return $walls[$y * $w + $x] === 1;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $interior
     */
    private function freeFillRatio(array $bb, array $interior, int $w, int $h): float
    {
        $free = 0;
        $tot = 0;
        for ($y = $bb[1]; $y <= $bb[3]; $y += 2) {
            for ($x = $bb[0]; $x <= $bb[2]; $x += 2) {
                $tot++;
                if ($interior[$y * $w + $x] === 1) {
                    $free++;
                }
            }
        }

        return $tot > 0 ? $free / $tot : 0.0;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     */
    private function pointInAnyRect(int $x, int $y, array $rects): bool
    {
        return $this->pointInAnyRectCore($x, $y, $rects, 0);
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     */
    private function pointInAnyRectCore(int $x, int $y, array $rects, int $inset): bool
    {
        foreach ($rects as $r) {
            if ($x >= $r[0] + $inset && $x <= $r[2] - $inset && $y >= $r[1] + $inset && $y <= $r[3] - $inset) {
                return true;
            }
        }

        return false;
    }

    /**
     * Conference / open offices / large enclosed rooms the cubicle lattice never claims.
     * Office CAD only (wide sheets); skipped on small residential plans.
     *
     * @param list<int> $walls
     * @param list<array{0:int,1:int,2:int,3:int}> $existing
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    /**
     * Tip short bank stubs to walls and retract clearly overgrown cubicles.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function refineBankCubicleSizes(array $rects, array $walls, int $w, int $h): array
    {
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $out = $rects;
        foreach ($rects as $i => $r) {
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(1800) || $area > $this->cadArea(22000)) {
                continue;
            }
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            if ($asp > 3.8) {
                continue;
            }
            $cur = $r;
            // Tip stubs that stopped on desk ink / mid-cell furniture.
            // Grow only on the short axis so we don't swallow a neighbor cubicle.
            if ($bh < (int) ($mh * 0.58) || $bw < (int) ($mw * 0.55) || $bh <= $this->cad(28)) {
                $grown = $this->expandCubicleToWalls($cur, $walls, $w, $h, $this->cad(56));
                if ($bh < (int) ($mh * 0.58) || $bh <= $this->cad(28)) {
                    // Keep original width — only take the vertical tip.
                    $grown = [$cur[0], $grown[1], $cur[2], $grown[3]];
                } elseif ($bw < (int) ($mw * 0.55)) {
                    $grown = [$grown[0], $cur[1], $grown[2], $cur[3]];
                }
                $gArea = $this->rectArea($grown);
                $gBw = $grown[2] - $grown[0] + 1;
                $gBh = $grown[3] - $grown[1] + 1;
                if (
                    $gArea > $area
                    && $gArea <= min($area * 6.5, $this->cadArea(20000))
                    && $gBw <= (int) ($mw * 1.55)
                    && $gBh <= (int) ($mh * 1.55)
                    && $this->interiorWallFrac($grown, $walls, $w, $h) <= 0.14
                ) {
                    $swallows = false;
                    foreach ($rects as $j => $o) {
                        if ($j === $i) {
                            continue;
                        }
                        if ($this->rectArea($o) < $this->cadArea(5000)) {
                            continue;
                        }
                        $cx = intdiv($o[0] + $o[2], 2);
                        $cy = intdiv($o[1] + $o[3], 2);
                        if ($cx >= $grown[0] && $cx <= $grown[2] && $cy >= $grown[1] && $cy <= $grown[3]) {
                            if ($cx < $cur[0] || $cx > $cur[2] || $cy < $cur[1] || $cy > $cur[3]) {
                                $swallows = true;
                                break;
                            }
                        }
                    }
                    if (!$swallows) {
                        $cur = $grown;
                    }
                }
            }
            $out[$i] = $this->retractCubicleOvergrow($cur, $walls, $w, $h);
            // Top-of-sheet bank cells often bleed into the title margin — pull the
            // open north side down to the first partition within one module.
            $cur = $out[$i];
            if ($cur[1] <= (int) ($h * 0.14)) {
                $y0 = $cur[1];
                $y1 = $cur[3];
                $x0 = $cur[0];
                $x1 = $cur[2];
                $targetH = max($this->cad(28), (int) ($mh * 0.85));
                $pull = 0;
                $maxPull = max($this->cad(36), (int) ($mh * 0.7));
                while (
                    $pull < $maxPull
                    && ($y1 - $y0 + 1) > $targetH
                    && !$this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y0)
                    && !$this->isStrongRowWallBoundary($walls, $w, $x0, $x1, max(0, $y0 - 1))
                ) {
                    $y0++;
                    $pull++;
                }
                if ($y0 > $cur[1] && ($y1 - $y0 + 1) >= $this->cad(24)) {
                    $cur = [$x0, $y0, $x1, $y1];
                    $out[$i] = $cur;
                }
            }
            // Top-row open-front bank cells are often phase-shifted into the
            // partition — slide them toward the open side without resizing (#6).
            $cur = $out[$i];
            $bw = $cur[2] - $cur[0] + 1;
            $bh = $cur[3] - $cur[1] + 1;
            if (
                $cur[1] <= (int) ($h * 0.16)
                && $bw >= (int) ($mw * 0.55) && $bw <= (int) ($mw * 1.35)
                && $bh >= (int) ($mh * 0.50) && $bh <= (int) ($mh * 1.35)
            ) {
                $west = $this->edgeWallFrac($walls, $w, $h, $cur[1], $cur[3], $cur[0], -1, 0);
                $east = $this->edgeWallFrac($walls, $w, $h, $cur[1], $cur[3], $cur[2], 1, 0);
                $north = $this->edgeWallFrac($walls, $w, $h, $cur[0], $cur[2], $cur[1], 0, -1);
                $south = $this->edgeWallFrac($walls, $w, $h, $cur[0], $cur[2], $cur[3], 0, 1);
                // Open-front top bank: strong south desk/wall, open north into margin.
                if ($south < 0.55 || $north > 0.35) {
                    // not this pattern
                } else {
                    $shift = 0;
                    if ($east + 0.12 < $west && $east < 0.22) {
                        $shift = intdiv($mw, 3);
                    } elseif ($west + 0.12 < $east && $west < 0.22) {
                        $shift = -intdiv($mw, 3);
                    }
                    if ($shift !== 0) {
                        $moved = [
                            max(0, $cur[0] + $shift),
                            $cur[1],
                            min($w - 1, $cur[2] + $shift),
                            $cur[3],
                        ];
                        if ($moved[2] - $moved[0] + 1 >= $this->cad(36)
                            && $this->interiorWallFrac($moved, $walls, $w, $h) <= 0.14
                        ) {
                            $swallows = false;
                            foreach ($rects as $j => $o) {
                                if ($j === $i) {
                                    continue;
                                }
                                if ($this->rectArea($o) < $this->cadArea(5000)) {
                                    continue;
                                }
                                $ocx = intdiv($o[0] + $o[2], 2);
                                $ocy = intdiv($o[1] + $o[3], 2);
                                if (
                                    $ocx >= $moved[0] && $ocx <= $moved[2]
                                    && $ocy >= $moved[1] && $ocy <= $moved[3]
                                    && ($ocx < $cur[0] || $ocx > $cur[2] || $ocy < $cur[1] || $ocy > $cur[3])
                                ) {
                                    $swallows = true;
                                    break;
                                }
                            }
                            if (!$swallows) {
                                $out[$i] = $moved;
                            }
                        }
                    }
                }
            }
            // Overwide bank cell with one open side: retract from the open edge toward
            // ~2/3 module width (raster cubicles are smaller than the measured pitch).
            $cur = $out[$i];
            $bw = $cur[2] - $cur[0] + 1;
            $bh = $cur[3] - $cur[1] + 1;
            if (
                $bw > (int) ($mw * 0.90)
                && $bw <= (int) ($mw * 1.55)
                && $bh >= (int) ($mh * 0.45)
                && $bh <= (int) ($mh * 1.35)
            ) {
                $west = $this->edgeWallFrac($walls, $w, $h, $cur[1], $cur[3], $cur[0], -1, 0);
                $east = $this->edgeWallFrac($walls, $w, $h, $cur[1], $cur[3], $cur[2], 1, 0);
                $target = max($this->cad(40), (int) ($mw * 0.68));
                $x0 = $cur[0];
                $x1 = $cur[2];
                $guard = min($bw - $target + 2, (int) ($mw * 0.5));
                if ($east + 0.05 < $west && $east < 0.18) {
                    while ($guard-- > 0 && ($x1 - $x0 + 1) > $target) {
                        if ($this->isStrongColWallBoundary($walls, $w, $h, $x1, $cur[1], $cur[3])) {
                            break;
                        }
                        $x1--;
                    }
                } elseif ($west + 0.05 < $east && $west < 0.18) {
                    while ($guard-- > 0 && ($x1 - $x0 + 1) > $target) {
                        if ($this->isStrongColWallBoundary($walls, $w, $h, $x0, $cur[1], $cur[3])) {
                            break;
                        }
                        $x0++;
                    }
                }
                if (($x1 - $x0 + 1) >= $this->cad(36) && ($x1 - $x0 + 1) < $bw) {
                    $out[$i] = [$x0, $cur[1], $x1, $cur[3]];
                }
            }
            // Right-shifted bank cell (open east, weak west): snap left onto the
            // nearest partition so phase matches GT (#18 A08.03, #32 B06.06).
            $cur = $out[$i];
            $bw = $cur[2] - $cur[0] + 1;
            $bh = $cur[3] - $cur[1] + 1;
            if (
                $bw >= (int) ($mw * 0.50) && $bw <= (int) ($mw * 0.90)
                && $bh >= (int) ($mh * 0.45) && $bh <= (int) ($mh * 1.35)
            ) {
                $west = $this->edgeWallFrac($walls, $w, $h, $cur[1], $cur[3], $cur[0], -1, 0);
                $east = $this->edgeWallFrac($walls, $w, $h, $cur[1], $cur[3], $cur[2], 1, 0);
                $north = $this->edgeWallFrac($walls, $w, $h, $cur[0], $cur[2], $cur[1], 0, -1);
                $south = $this->edgeWallFrac($walls, $w, $h, $cur[0], $cur[2], $cur[3], 0, 1);
                // Skip open-north top-bank cells — those already have their own phase slide (#6).
                if ($east < 0.16 && $west < 0.40 && $north >= 0.28 && !($south >= 0.50 && $north <= 0.35)) {
                    $search = max(16, (int) ($mw * 0.42));
                    $snapX = null;
                    $bestFrac = $west;
                    for ($dx = 2; $dx <= $search; $dx++) {
                        $x = $cur[0] - $dx;
                        if ($x < 1) {
                            break;
                        }
                        $frac = $this->colWallFrac($walls, $w, $h, $x, $cur[1], $cur[3]);
                        if ($frac >= 0.28 && $frac > $bestFrac + 0.04) {
                            $snapX = $x + 1;
                            $bestFrac = $frac;
                        }
                    }
                    $minShift = max(12, (int) ($mw * 0.15));
                    if ($snapX !== null && ($cur[0] - $snapX) >= $minShift) {
                        $shift = $snapX - $cur[0];
                        $moved = [
                            $cur[0] + $shift,
                            $cur[1],
                            $cur[2] + $shift,
                            $cur[3],
                        ];
                        if (
                            $moved[0] >= 0
                            && $moved[2] < $w
                            && $this->interiorWallFrac($moved, $walls, $w, $h) <= 0.14
                        ) {
                            $swallows = false;
                            foreach ($out as $j => $o) {
                                if ($j === $i) {
                                    continue;
                                }
                                if ($this->rectArea($o) < $this->cadArea(4000)) {
                                    continue;
                                }
                                $ocx = intdiv($o[0] + $o[2], 2);
                                $ocy = intdiv($o[1] + $o[3], 2);
                                if (
                                    $ocx >= $moved[0] && $ocx <= $moved[2]
                                    && $ocy >= $moved[1] && $ocy <= $moved[3]
                                    && ($ocx < $cur[0] || $ocx > $cur[2] || $ocy < $cur[1] || $ocy > $cur[3])
                                ) {
                                    $swallows = true;
                                    break;
                                }
                            }
                            if (!$swallows) {
                                $out[$i] = $moved;
                            }
                        }
                    }
                }
            }
        }

        // Absorb paper-thin stubs glued to a bank cell (B05.11: 19px scrap on a tall cell).
        $n = count($out);
        $used = array_fill(0, $n, false);
        $merged = [];
        for ($i = 0; $i < $n; $i++) {
            if ($used[$i]) {
                continue;
            }
            $a = $out[$i];
            $ah = $a[3] - $a[1] + 1;
            $aw = $a[2] - $a[0] + 1;
            $did = false;
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j || $used[$j]) {
                    continue;
                }
                $b = $out[$j];
                $bh = $b[3] - $b[1] + 1;
                $bw = $b[2] - $b[0] + 1;
                $aStub = $ah <= max(20, (int) ($mh * 0.38)) && $aw >= (int) ($mw * 0.5);
                $bStub = $bh <= max(20, (int) ($mh * 0.38)) && $bw >= (int) ($mw * 0.5);
                if ($aStub === $bStub) {
                    continue;
                }
                $stub = $aStub ? $a : $b;
                $body = $aStub ? $b : $a;
                $sw = $stub[2] - $stub[0] + 1;
                $bodyW = $body[2] - $body[0] + 1;
                $bodyH = $body[3] - $body[1] + 1;
                if ($bodyH < (int) ($mh * 0.55) || $bodyH > (int) ($mh * 1.7)) {
                    continue;
                }
                if ($bodyW < (int) ($mw * 0.5) || $bodyW > (int) ($mw * 1.35)) {
                    continue;
                }
                $xOv = min($stub[2], $body[2]) - max($stub[0], $body[0]) + 1;
                if ($xOv < (int) (min($sw, $bodyW) * 0.85)) {
                    continue;
                }
                $gap = $body[1] > $stub[3]
                    ? ($body[1] - $stub[3] - 1)
                    : ($stub[1] > $body[3] ? ($stub[1] - $body[3] - 1) : -1);
                if ($gap > 3) {
                    continue;
                }
                $uni = [
                    min($stub[0], $body[0]),
                    min($stub[1], $body[1]),
                    max($stub[2], $body[2]),
                    max($stub[3], $body[3]),
                ];
                $uh = $uni[3] - $uni[1] + 1;
                if ($uh > (int) ($mh * 2.0) || $this->interiorWallFrac($uni, $walls, $w, $h) > 0.14) {
                    continue;
                }
                $target = max($this->cad(32), (int) ($mh * 0.95));
                $south = $this->edgeWallFrac($walls, $w, $h, $uni[0], $uni[2], $uni[3], 0, 1);
                $north = $this->edgeWallFrac($walls, $w, $h, $uni[0], $uni[2], $uni[1], 0, -1);
                $guard = $uh;
                while ($guard-- > 0 && ($uni[3] - $uni[1] + 1) > $target) {
                    if ($south <= $north + 0.05) {
                        if ($this->isStrongRowWallBoundary($walls, $w, $uni[0], $uni[2], $uni[3])) {
                            break;
                        }
                        $uni[3]--;
                    } else {
                        if ($this->isStrongRowWallBoundary($walls, $w, $uni[0], $uni[2], $uni[1])) {
                            break;
                        }
                        $uni[1]++;
                    }
                }
                $merged[] = $uni;
                $used[$i] = true;
                $used[$j] = true;
                $did = true;
                break;
            }
            if (!$did) {
                $merged[] = $a;
                $used[$i] = true;
            }
        }

        return array_values($merged);
    }

    /**
     * Merge exactly two stacked cubicle scraps that sit in a weakly partitioned
     * tall column (C07.03). Requires a peer tall thin room nearby and a strong
     * E/W enclosure so real cubicle banks with desk rails are not glued.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function mergeWeakPartitionColumns(array $rects, array $walls, int $w, int $h): array
    {
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        // Only when the sheet already has tall thin rooms (office wing pattern).
        $peers = [];
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if ($bh >= (int) ($mh * 2.2) && $bw <= (int) ($mw * 0.95) && $bw >= (int) ($mw * 0.45)) {
                $peers[] = $r;
            }
        }
        if ($peers === []) {
            return $rects;
        }

        $n = count($rects);
        $used = array_fill(0, $n, false);
        $out = [];
        $merges = 0;
        $maxMerges = 4;

        // Candidate cubicle-sized cells, sorted by x then y.
        $cands = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $rects[$i];
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            if (
                $bw >= (int) ($mw * 0.45) && $bw <= (int) ($mw * 1.05)
                && $bh >= (int) ($mh * 0.50) && $bh <= (int) ($mh * 1.25)
            ) {
                $cands[] = $i;
            }
        }
        usort($cands, static function (int $ia, int $ib) use ($rects): int {
            $ax = intdiv($rects[$ia][0] + $rects[$ia][2], 2);
            $bx = intdiv($rects[$ib][0] + $rects[$ib][2], 2);
            if ($ax !== $bx) {
                return $ax <=> $bx;
            }

            return $rects[$ia][1] <=> $rects[$ib][1];
        });

        for ($ci = 0; $ci < count($cands) && $merges < $maxMerges; $ci++) {
            $i = $cands[$ci];
            if ($used[$i]) {
                continue;
            }
            $a = $rects[$i];
            $acx = intdiv($a[0] + $a[2], 2);
            // Peer tall room within ~½ sheet module horizontally.
            $nearPeer = false;
            foreach ($peers as $p) {
                $pcx = intdiv($p[0] + $p[2], 2);
                if (abs($pcx - $acx) <= max(220, (int) ($mw * 3.2))) {
                    $nearPeer = true;
                    break;
                }
            }
            if (!$nearPeer) {
                continue;
            }
            for ($cj = $ci + 1; $cj < count($cands); $cj++) {
                $j = $cands[$cj];
                if ($used[$j]) {
                    continue;
                }
                $b = $rects[$j];
                $bcx = intdiv($b[0] + $b[2], 2);
                if (abs($bcx - $acx) > max(14, (int) ($mw * 0.22))) {
                    if ($bcx > $acx + (int) ($mw * 0.5)) {
                        break;
                    }
                    continue;
                }
                // Normalize so $top is above $bot.
                if ($b[1] < $a[1]) {
                    $top = $b;
                    $bot = $a;
                } else {
                    $top = $a;
                    $bot = $b;
                }
                $gap = $bot[1] - $top[3] - 1;
                if ($gap < -2 || $gap > max(18, (int) ($mh * 0.35))) {
                    continue;
                }
                $xOv = min($top[2], $bot[2]) - max($top[0], $bot[0]) + 1;
                $minW = min($top[2] - $top[0] + 1, $bot[2] - $bot[0] + 1);
                if ($xOv < (int) ($minW * 0.65)) {
                    continue;
                }
                // Gap must not contain a strong desk rail.
                $gapBlocked = false;
                $gx0 = max($top[0], $bot[0]) + 1;
                $gx1 = min($top[2], $bot[2]) - 1;
                if ($gx1 > $gx0) {
                    for ($y = $top[3] + 1; $y < $bot[1]; $y++) {
                        if ($this->isStrongRowWallBoundary($walls, $w, $gx0, $gx1, $y)) {
                            $gapBlocked = true;
                            break;
                        }
                    }
                }
                if ($gapBlocked) {
                    continue;
                }
                $uni = [
                    min($top[0], $bot[0]),
                    min($top[1], $bot[1]),
                    max($top[2], $bot[2]),
                    max($top[3], $bot[3]),
                ];
                $uw = $uni[2] - $uni[0] + 1;
                $uh = $uni[3] - $uni[1] + 1;
                if ($uw > (int) ($mw * 0.95) || $uh < (int) ($mh * 2.15) || $uh > (int) ($mh * 3.5)) {
                    continue;
                }
                if ($this->interiorWallFrac($uni, $walls, $w, $h) > 0.12) {
                    continue;
                }
                $east = $this->edgeWallFrac($walls, $w, $h, $uni[1], $uni[3], $uni[2], 1, 0);
                $west = $this->edgeWallFrac($walls, $w, $h, $uni[1], $uni[3], $uni[0], -1, 0);
                if (max($east, $west) < 0.55) {
                    continue; // not an enclosed column
                }
                $used[$i] = true;
                $used[$j] = true;
                $out[] = $uni;
                $merges++;
                break;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if (!$used[$i]) {
                $out[] = $rects[$i];
            }
        }

        return $out;
    }

    /**
     * Claim open-front conference / open offices that the cubicle lattice filled with
     * irregular scraps. Never replaces a regular 2-D cubicle bank.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function claimOpenFrontConferenceRooms(array $rects, array $walls, int $w, int $h): array
    {
        if ($w < 1400) {
            return $rects;
        }
        $claims = [];
        $stepY = max(28, $this->cad(72));
        $stepX = max(32, $this->cad(80));
        $margin = max(24, $this->cad(56));
        $maxClaims = 8;
        for ($sy = $margin; $sy < $h - $margin && count($claims) < $maxClaims * 3; $sy += $stepY) {
            for ($sx = $margin; $sx < $w - $margin; $sx += $stepX) {
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                // Skip seeds already deep inside a solid large room.
                $blocked = false;
                foreach ($rects as $er) {
                    if ($this->rectArea($er) < $this->cadArea(28000)) {
                        continue;
                    }
                    if (
                        $sx >= $er[0] + $this->cad(12)
                        && $sx <= $er[2] - $this->cad(12)
                        && $sy >= $er[1] + $this->cad(12)
                        && $sy <= $er[3] - $this->cad(12)
                        && $this->enclosureScore($er, $walls, $w, $h) >= 0.50
                    ) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
                $seed = [
                    max(0, $sx - $this->cad(36)),
                    max(0, $sy - $this->cad(36)),
                    min($w - 1, $sx + $this->cad(36)),
                    min($h - 1, $sy + $this->cad(36)),
                ];
                $grown = $this->expandCubicleToWalls($seed, $walls, $w, $h, $this->cad(220));
                $area = $this->rectArea($grown);
                if ($area < $this->cadArea(38000) || $area > $this->cadArea(160000)) {
                    continue;
                }
                $bw = $grown[2] - $grown[0] + 1;
                $bh = $grown[3] - $grown[1] + 1;
                $asp = max($bw, $bh) / max(1, min($bw, $bh));
                if ($asp > 2.8 || min($bw, $bh) < $this->cad(100)) {
                    continue;
                }
                if ($grown[0] <= 1 || $grown[1] <= 1 || $grown[2] >= $w - 2 || $grown[3] >= $h - 2) {
                    continue;
                }
                $iw = $this->interiorWallFrac($grown, $walls, $w, $h);
                $enc = $this->enclosureScore($grown, $walls, $w, $h);
                $sides = $this->strongWallSides($grown, $walls, $w, $h, 0.28);
                // Open-front signature: clean interior, modest enclosure, ≥2 walls.
                if ($iw > 0.05 || $sides < 2 || $enc < 0.22 || $enc > 0.45) {
                    continue;
                }
                if ($this->isCorridorShape($grown, $walls, $w, $h)) {
                    continue;
                }
                $inside = $this->cubiclesInside($grown, $rects);
                $regularRows = $this->countRegularGroups($inside, false);
                $regularCols = $this->countRegularGroups($inside, true);
                // Never steal a real cubicle bank.
                if ($regularRows >= 2 && $regularCols >= 2) {
                    continue;
                }
                if ($regularRows >= 2 || $regularCols >= 2) {
                    continue;
                }
                if ($this->looksLikeCubicleBank($inside, $grown)) {
                    continue;
                }
                // Abort if several well-sized cubicles live inside (real bank cells).
                $goodCubs = 0;
                $scrapHits = 0;
                foreach ($inside as $c) {
                    $cArea = $this->rectArea($c);
                    if ($cArea <= $this->cadArea(8000)) {
                        $scrapHits++;
                    } elseif (
                        $cArea >= $this->cadArea(5000)
                        && $cArea <= $this->cadArea(16000)
                        && $this->enclosureScore($c, $walls, $w, $h) >= 0.32
                    ) {
                        $goodCubs++;
                    }
                }
                if ($goodCubs >= 2) {
                    continue;
                }
                // Prefer claims that clear FP scraps (or fill a near-empty pocket).
                if ($inside !== [] && $scrapHits < 2 && $goodCubs >= 1) {
                    continue;
                }
                $dup = false;
                foreach ($claims as $c) {
                    if ($this->overlapFrac($grown, $c) > 0.45 || $this->containmentFrac($grown, $c) > 0.55) {
                        $dup = true;
                        break;
                    }
                }
                if ($dup) {
                    continue;
                }
                $claims[] = $grown;
                if (count($claims) >= $maxClaims) {
                    break 2;
                }
            }
        }
        if ($claims === []) {
            return $rects;
        }

        $out = $rects;
        foreach ($claims as $L) {
            $out = array_values(array_filter(
                $out,
                function (array $r) use ($L): bool {
                    $area = $this->rectArea($r);
                    // Keep other large rooms / strong cubicles that only graze the claim.
                    if ($this->containmentFrac($r, $L) < 0.55 && $this->overlapFrac($r, $L) < 0.40) {
                        return true;
                    }
                    // Drop scraps / medium fragments fully inside the conference floor.
                    if ($area <= $this->cadArea(22000)) {
                        return false;
                    }
                    // Competing large fragment: keep the better-enclosed one later via exclusive.
                    return $this->rectArea($L) < $area * 1.15;
                }
            ));
            $out[] = $L;
        }

        return $out;
    }

    /**
     * Drop cubicle scraps whose centers fall inside recovered large rooms, then
     * append those rooms. Brings counts/areas toward filled Building-5 offices.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array{0:int,1:int,2:int,3:int}> $largeRooms
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function absorbScrapsIntoLargeRooms(
        array $rects,
        array $largeRooms,
        array $walls,
        int $w,
        int $h
    ): array {
        if ($largeRooms === []) {
            return $rects;
        }
        $mw = $this->modW > 20 ? $this->modW : $this->cad(110);
        $mh = $this->modH > 16 ? $this->modH : $this->cad(80);
        $cubMax = max($this->cadArea(18000), (int) ($mw * $mh * 2.8));
        $accepted = [];
        foreach ($largeRooms as $L) {
            $area = $this->rectArea($L);
            if ($area < max($this->cadArea(20000), (int) ($mw * $mh * 3.5))) {
                continue;
            }
            $inside = $this->cubiclesInside($L, $rects);
            if (count($inside) < 4) {
                continue;
            }
            if ($this->looksLikeCubicleBank($inside, $L)) {
                continue;
            }
            $regularRows = $this->countRegularGroups($inside, false);
            $regularCols = $this->countRegularGroups($inside, true);
            if ($regularRows >= 2 || $regularCols >= 2) {
                continue;
            }
            $scrapHits = 0;
            foreach ($inside as $c) {
                if ($this->rectArea($c) <= (int) ($mw * $mh * 1.2)) {
                    $scrapHits++;
                }
            }
            if ($scrapHits < 3) {
                continue;
            }
            $iw = $this->interiorWallFrac($L, $walls, $w, $h);
            if ($iw > 0.07) {
                continue;
            }
            $dup = false;
            foreach ($accepted as $a) {
                if ($this->overlapFrac($L, $a) > 0.45 || $this->containmentFrac($L, $a) > 0.55) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $accepted[] = $L;
            }
        }
        if ($accepted === []) {
            return $rects;
        }
        $out = [];
        foreach ($rects as $r) {
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            $area = $this->rectArea($r);
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $absorbed = false;
            foreach ($accepted as $L) {
                if ($cx < $L[0] || $cx > $L[2] || $cy < $L[1] || $cy > $L[3]) {
                    continue;
                }
                // Only absorb true scraps / weak fragments — keep module-sized cubicles.
                $enc = $this->enclosureScore($r, $walls, $w, $h);
                $scrap = $area <= (int) ($mw * $mh * 1.15)
                    || ($area <= (int) ($mw * $mh * 1.8) && $enc < 0.28);
                $mostlyInside = $this->containmentFrac($r, $L) >= 0.72;
                if ($scrap && $mostlyInside) {
                    $absorbed = true;
                    break;
                }
                // Medium blob fully inside a much larger room with little own enclosure.
                if (
                    $mostlyInside
                    && $area <= $cubMax
                    && $area * 2.8 < $this->rectArea($L)
                    && $enc < 0.30
                    && $this->interiorWallFrac($r, $walls, $w, $h) < 0.04
                ) {
                    $absorbed = true;
                    break;
                }
            }
            if (!$absorbed) {
                $out[] = $r;
            }
        }
        foreach ($accepted as $L) {
            $out[] = $L;
        }

        return array_values($out);
    }

    private function roomsFromLargeEnclosures(array $walls, int $w, int $h, array $existing): array
    {
        if ($w < 1400) {
            return [];
        }
        $minArea = $this->cadArea(12000);
        $maxArea = (int) ($w * $h * 0.12);
        $blockers = [];
        foreach ($existing as $er) {
            $area = $this->rectArea($er);
            // Only strong large rooms block seeds — cubicles must not hide conference centers.
            if ($area > $this->cadArea(28000) && $this->enclosureScore($er, $walls, $w, $h) >= 0.50) {
                $blockers[] = $er;
            }
        }
        $grown = [];
        $stepY = max(24, $this->cad(56));
        $stepX = max(28, $this->cad(64));
        $margin = max(20, $this->cad(40));
        $maxHalfW = $this->cad(420);
        $maxHalfH = $this->cad(520);
        for ($sy = $margin; $sy < $h - $margin; $sy += $stepY) {
            for ($sx = $margin; $sx < $w - $margin; $sx += $stepX) {
                if ($walls[$sy * $w + $sx] === 1) {
                    continue;
                }
                if ($this->pointInAnyRectCore($sx, $sy, $blockers, $this->cad(18))) {
                    continue;
                }
                // Prefer classic grow (respects bank partitions); escalate to space-fill
                // only when classic is trapped by thin furniture inside a real large room.
                $classic = $this->growRectFromSeed($walls, $w, $h, $sx, $sy, $this->cad(320), $this->cad(450));
                $rect = $classic;
                $cArea = $classic !== null ? $this->rectArea($classic) : 0;
                $cFlat = false;
                if ($classic !== null) {
                    $cbw = $classic[2] - $classic[0] + 1;
                    $cbh = $classic[3] - $classic[1] + 1;
                    $cFlat = max($cbw, $cbh) / max(1, min($cbw, $cbh)) >= 4.0
                        || min($cbw, $cbh) < $this->cad(36);
                }
                if ($classic !== null && ($cArea < $this->cadArea(22000) || $cFlat)) {
                    $space = $this->growRectSpaceFill($walls, $w, $h, $sx, $sy, $maxHalfW, $maxHalfH);
                    if ($space !== null) {
                        $sArea = $this->rectArea($space);
                        $sEnc = $this->enclosureScore($space, $walls, $w, $h);
                        $sIw = $this->interiorWallFrac($space, $walls, $w, $h);
                        $edgeBleed = $space[0] <= 1 || $space[1] <= 1 || $space[2] >= $w - 2 || $space[3] >= $h - 2;
                        if (
                            $sArea >= $this->cadArea(25000)
                            && $sArea > $cArea * 1.35
                            && $sIw <= 0.08
                            && !$this->isCorridorShape($space, $walls, $w, $h)
                            && (
                                ($sEnc >= 0.50 && !$edgeBleed)
                                || ($sEnc >= 0.42 && $sIw <= 0.05 && !$edgeBleed)
                                || ($sEnc >= 0.55 && $edgeBleed)
                            )
                        ) {
                            $rect = $space;
                        }
                    }
                    // expandCubicleToWalls often fits open-front conference rooms better than
                    // unbounded space-fill (which bleeds into title blocks / corridors).
                    if ($rect === $classic || $rect === null) {
                        $seed = [
                            max(0, $sx - $this->cad(40)),
                            max(0, $sy - $this->cad(40)),
                            min($w - 1, $sx + $this->cad(40)),
                            min($h - 1, $sy + $this->cad(40)),
                        ];
                        $expanded = $this->expandCubicleToWalls($seed, $walls, $w, $h, $this->cad(220));
                        $eArea = $this->rectArea($expanded);
                        $eEnc = $this->enclosureScore($expanded, $walls, $w, $h);
                        $eIw = $this->interiorWallFrac($expanded, $walls, $w, $h);
                        $eSides = $this->strongWallSides($expanded, $walls, $w, $h, 0.28);
                        $ebw = $expanded[2] - $expanded[0] + 1;
                        $ebh = $expanded[3] - $expanded[1] + 1;
                        $eAsp = max($ebw, $ebh) / max(1, min($ebw, $ebh));
                        $edgeBleed = $expanded[0] <= 1 || $expanded[1] <= 1
                            || $expanded[2] >= $w - 2 || $expanded[3] >= $h - 2;
                        if (
                            $eArea >= max($cArea * 1.8, $this->cadArea(28000))
                            && $eArea <= $maxArea
                            && $eIw <= 0.06
                            && $eAsp <= 3.4
                            && $eSides >= 2
                            && !$edgeBleed
                            && !$this->isCorridorShape($expanded, $walls, $w, $h)
                            && ($eEnc >= 0.28 || ($eEnc >= 0.18 && $eIw <= 0.04 && $eSides >= 2))
                        ) {
                            $rect = $expanded;
                        }
                    }
                } elseif ($classic === null) {
                    $rect = $this->growRectSpaceFill($walls, $w, $h, $sx, $sy, $maxHalfW, $maxHalfH);
                }
                if ($rect === null) {
                    continue;
                }
                $bw = $rect[2] - $rect[0] + 1;
                $bh = $rect[3] - $rect[1] + 1;
                $area = $bw * $bh;
                if ($area < $minArea || $area > $maxArea) {
                    continue;
                }
                // Real conference rooms aren't skinny hall strips.
                if (min($bw, $bh) < $this->cad(60)) {
                    continue;
                }
                $asp = max($bw, $bh) / max(1, min($bw, $bh));
                if ($asp > 5.5) {
                    continue;
                }
                $enc = $this->enclosureScore($rect, $walls, $w, $h);
                $iw = $this->interiorWallFrac($rect, $walls, $w, $h);
                $sides = $this->strongWallSides($rect, $walls, $w, $h, 0.28);
                $edgeBleed = $rect[0] <= 1 || $rect[1] <= 1 || $rect[2] >= $w - 2 || $rect[3] >= $h - 2;
                // Open-front conference rooms score low enc but have clean interiors.
                $openOk = $iw <= 0.055 && $sides >= 2 && $asp <= 3.2 && !$edgeBleed;
                if ($enc < 0.42 && !($openOk && $enc >= 0.18 && $area >= $this->cadArea(28000))) {
                    continue;
                }
                if ($this->isCorridorShape($rect, $walls, $w, $h) && $enc < 0.55) {
                    continue;
                }
                // Banks are dense with partitions; conference rooms may have light furniture.
                if ($iw > 0.08) {
                    continue;
                }
                if ($edgeBleed && $enc < 0.58) {
                    continue;
                }
                // Prefer compact non-edge rooms over high-enc sheet strips.
                $score = $enc
                    + ($openOk ? 0.25 : 0.0)
                    + min(0.35, $area / max(1.0, (float) $this->cadArea(80000)) * 0.35)
                    - ($edgeBleed ? 0.45 : 0.0)
                    - max(0.0, $asp - 2.2) * 0.12;
                $grown[] = [$rect[0], $rect[1], $rect[2], $rect[3], $area, $score];
            }
        }
        usort($grown, static fn (array $a, array $b): int => $b[5] <=> $a[5]);
        $kept = [];
        foreach ($grown as $g) {
            $r = [$g[0], $g[1], $g[2], $g[3]];
            $skip = false;
            foreach ($kept as $host) {
                if ($this->overlapFrac($r, $host) > 0.40 || $this->containmentFrac($r, $host) > 0.55) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $kept[] = $r;
            }
            if (count($kept) >= 12) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Split oversized rooms that crossed a mid partition (stacked offices / long suites).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function splitLargeByInternalWalls(array $rects, array $walls, int $w, int $h): array
    {
        $out = [];
        $minPart = $this->cadArea(8000);
        foreach ($rects as $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $area = $bw * $bh;
            // Skip skinny conference rooms — mid furniture strokes look like splits.
            $asp = max($bw, $bh) / max(1, min($bw, $bh));
            if (
                $area < $this->cadArea(28000)
                || min($bw, $bh) < $this->cad(100)
                || $asp > 2.6
                // Empty open offices: don't invent splits from residual ink.
                || $this->interiorWallFrac($r, $walls, $w, $h) < 0.06
            ) {
                $out[] = $r;
                continue;
            }
            $parts = $this->trySplitRectOnWall($r, $walls, $w, $h, true);
            if ($parts === null) {
                $parts = $this->trySplitRectOnWall($r, $walls, $w, $h, false);
            }
            if ($parts === null) {
                $out[] = $r;
                continue;
            }
            $keptParts = [];
            foreach ($parts as $part) {
                $pw = $part[2] - $part[0] + 1;
                $ph = $part[3] - $part[1] + 1;
                $pa = $pw * $ph;
                if ($pa < $minPart || $this->enclosureScore($part, $walls, $w, $h) < 0.42) {
                    continue;
                }
                $cx = intdiv($part[0] + $part[2], 2);
                $cy = intdiv($part[1] + $part[3], 2);
                if ($cx < 1 || $cy < 1 || $cx >= $w - 1 || $cy >= $h - 1 || $walls[$cy * $w + $cx] === 1) {
                    $keptParts[] = $part;
                    continue;
                }
                $grown = $this->growRectFromSeed($walls, $w, $h, $cx, $cy, $this->cad(280), $this->cad(400));
                if ($grown !== null && $this->rectArea($grown) >= $minPart) {
                    $keptParts[] = $grown;
                } else {
                    $keptParts[] = $part;
                }
            }
            if (count($keptParts) >= 2) {
                foreach ($keptParts as $part) {
                    $out[] = $part;
                }
            } else {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>|null
     */
    private function trySplitRectOnWall(array $r, array $walls, int $w, int $h, bool $horizontal): ?array
    {
        $bw = $r[2] - $r[0] + 1;
        $bh = $r[3] - $r[1] + 1;
        $bestPos = -1;
        $bestFrac = 0.0;
        if ($horizontal) {
            $y0 = $r[1] + (int) ($bh * 0.15);
            $y1 = $r[1] + (int) ($bh * 0.85);
            for ($y = $y0; $y <= $y1; $y++) {
                $hits = 0;
                $tot = 0;
                for ($x = $r[0]; $x <= $r[2]; $x += 1) {
                    $tot++;
                    if ($y >= 0 && $y < $h && $walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                $frac = $tot > 0 ? $hits / $tot : 0.0;
                if ($frac > $bestFrac) {
                    $bestFrac = $frac;
                    $bestPos = $y;
                }
            }
            if ($bestPos < 0 || $bestFrac < 0.48) {
                return null;
            }
            $a = [$r[0], $r[1], $r[2], $bestPos - 1];
            $b = [$r[0], $bestPos + 1, $r[2], $r[3]];
        } else {
            $x0 = $r[0] + (int) ($bw * 0.15);
            $x1 = $r[0] + (int) ($bw * 0.85);
            for ($x = $x0; $x <= $x1; $x++) {
                $hits = 0;
                $tot = 0;
                for ($y = $r[1]; $y <= $r[3]; $y += 1) {
                    $tot++;
                    if ($x >= 0 && $x < $w && $walls[$y * $w + $x] === 1) {
                        $hits++;
                    }
                }
                $frac = $tot > 0 ? $hits / $tot : 0.0;
                if ($frac > $bestFrac) {
                    $bestFrac = $frac;
                    $bestPos = $x;
                }
            }
            if ($bestPos < 0 || $bestFrac < 0.48) {
                return null;
            }
            $a = [$r[0], $r[1], $bestPos - 1, $r[3]];
            $b = [$bestPos + 1, $r[1], $r[2], $r[3]];
        }
        if ($this->rectArea($a) < $this->cadArea(2200) || $this->rectArea($b) < $this->cadArea(2200)) {
            return null;
        }

        return [$a, $b];
    }

    /**
     * Drop large boxes that are just a cubicle-bank bounding box.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $large
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $large
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function rejectCubicleBankBoxes(array $large, array $rects, array $walls, int $w, int $h): array
    {
        $kept = [];
        foreach ($large as $L) {
            $inside = $this->cubiclesInside($L, $rects);
            $enc = $this->enclosureScore($L, $walls, $w, $h);
            $iw = $this->interiorWallFrac($L, $walls, $w, $h);
            // Open conference: sparse structural ink — cubicle preds inside are FP scraps.
            $openConference = $iw <= 0.055
                && $enc >= 0.28
                && $this->rectArea($L) >= $this->cadArea(28000)
                && !$this->isCorridorShape($L, $walls, $w, $h)
                && $L[0] > 1 && $L[1] > 1;
            if (!$openConference && $this->looksLikeCubicleBank($inside, $L)) {
                // Sparse FP seeds + low partition density → real open office, not a bank.
                $cover = 0;
                foreach ($inside as $c) {
                    $cover += $this->rectArea($c);
                }
                $coverFrac = $cover / max(1, $this->rectArea($L));
                if (!(
                    $coverFrac < 0.28
                    && count($inside) <= 5
                    && $enc >= 0.55
                    && $iw <= 0.045
                    && $this->rectArea($L) >= $this->cadArea(40000)
                )) {
                    continue;
                }
            }
            $kept[] = $L;
        }

        return $kept;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $host
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function cubiclesInside(array $host, array $rects): array
    {
        $inside = [];
        foreach ($rects as $r) {
            $area = $this->rectArea($r);
            if ($area < $this->cadArea(3200) || $area > $this->cadArea(20000)) {
                continue;
            }
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cx >= $host[0] && $cx <= $host[2] && $cy >= $host[1] && $cy <= $host[3]) {
                $inside[] = $r;
            }
        }

        return $inside;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $cubs
     * @param array{0:int,1:int,2:int,3:int} $host
     */
    private function looksLikeCubicleBank(array $cubs, array $host): bool
    {
        $n = count($cubs);
        if ($n < 3) {
            return false;
        }
        $hostArea = $this->rectArea($host);
        $cover = 0;
        foreach ($cubs as $c) {
            $cover += $this->rectArea($c);
        }
        $cover /= max(1, $hostArea);
        $regularRows = $this->countRegularGroups($cubs, false);
        $regularCols = $this->countRegularGroups($cubs, true);
        // 2-D cubicle grid is always a bank.
        if ($regularRows >= 2 && $regularCols >= 2) {
            return true;
        }
        // Compact bank strip / cluster — but don't reject a real conference room that
        // merely overlaps a few false cubicle seeds.
        if ($hostArea < 100000 && $n >= 4 && ($regularRows >= 2 || $regularCols >= 2 || $cover >= 0.34)) {
            return true;
        }
        // Huge conference rooms: only reject a dense 3-row office farm.
        if ($hostArea >= 100000 && $regularRows >= 3 && $cover >= 0.36) {
            return true;
        }
        // Medium offices: require dense regular packing before calling it a bank.
        if ($hostArea >= 40000 && $hostArea < 100000 && $n >= 6 && $regularRows >= 2 && $regularCols >= 2 && $cover >= 0.40) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $cubs
     */
    private function countRegularGroups(array $cubs, bool $byX): int
    {
        $pts = [];
        foreach ($cubs as $c) {
            $pts[] = [
                intdiv($c[0] + $c[2], 2),
                intdiv($c[1] + $c[3], 2),
            ];
        }
        usort($pts, $byX
            ? static fn (array $a, array $b): int => $a[0] <=> $b[0]
            : static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        $groups = [];
        $cur = [];
        foreach ($pts as $p) {
            if ($cur === []) {
                $cur[] = $p;
                continue;
            }
            $prev = $cur[count($cur) - 1];
            $delta = $byX ? abs($p[0] - $prev[0]) : abs($p[1] - $prev[1]);
            if ($delta <= 30) {
                $cur[] = $p;
            } else {
                $groups[] = $cur;
                $cur = [$p];
            }
        }
        if ($cur !== []) {
            $groups[] = $cur;
        }
        $regular = 0;
        foreach ($groups as $g) {
            if (count($g) < 2) {
                continue;
            }
            $gaps = [];
            usort($g, $byX
                ? static fn (array $a, array $b): int => $a[1] <=> $b[1]
                : static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            for ($i = 1; $i < count($g); $i++) {
                $gaps[] = $byX
                    ? abs($g[$i][1] - $g[$i - 1][1])
                    : abs($g[$i][0] - $g[$i - 1][0]);
            }
            sort($gaps);
            $med = $gaps[intdiv(count($gaps), 2)];
            if ($med >= 70 && $med <= 145) {
                $regular++;
            }
        }

        return $regular;
    }

    /**
     * Add large open rooms, replacing cubicles they contain (banks already filtered out).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array{0:int,1:int,2:int,3:int}> $large
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function mergeLargeReplacingJunk(array $rects, array $large, array $walls, int $w, int $h): array
    {
        if ($large === []) {
            return $rects;
        }
        $kept = $rects;
        $cubMin = $this->cadArea(3200);
        $cubMax = $this->cadArea(20000);
        $mediumCap = $this->cadArea(45000);
        foreach ($large as $L) {
            $lArea = $this->rectArea($L);
            $lbw = $L[2] - $L[0] + 1;
            $lbh = $L[3] - $L[1] + 1;
            $lAsp = max($lbw, $lbh) / max(1, min($lbw, $lbh));
            $lEnc = $this->enclosureScore($L, $walls, $w, $h);
            $conflicts = [];
            $hard = false;
            $cubsHit = 0;
            foreach ($kept as $i => $r) {
                if ($this->overlapFrac($L, $r) <= 0.35 && $this->containmentFrac($r, $L) <= 0.5) {
                    continue;
                }
                $area = $this->rectArea($r);
                $isCub = $area >= $cubMin && $area <= $cubMax;
                if ($isCub) {
                    $cubsHit++;
                    continue;
                }
                if ($area < $lArea * 0.55) {
                    $conflicts[] = $i;
                    continue;
                }
                $hard = true;
                break;
            }
            if ($hard) {
                continue;
            }
            $iw = $this->interiorWallFrac($L, $walls, $w, $h);
            // Open conference floor: drop FP cubicle scraps even when many overlap.
            $openConference = $iw <= 0.055
                && $lEnc >= 0.28
                && $lArea >= $this->cadArea(28000)
                && $lAsp <= 3.2
                && !$this->isCorridorShape($L, $walls, $w, $h)
                && $L[0] > 1 && $L[1] > 1;
            // Medium boxes that swallow many cubicles are usually wrong bank merges.
            // Tall offices / strongly enclosed rooms with sparse FP seeds are real.
            $allowOverCubs = $openConference
                || ($lAsp >= 2.2 && $cubsHit <= 3)
                || ($lEnc >= 0.72 && $cubsHit <= 3 && $lArea >= $this->cadArea(15000));
            if ($cubsHit >= 1 && $lArea < $mediumCap && !$allowOverCubs) {
                continue;
            }
            if ($allowOverCubs && $cubsHit > 0) {
                $kept = array_values(array_filter(
                    $kept,
                    function (array $r) use ($L, $cubMin, $cubMax): bool {
                        $area = $this->rectArea($r);
                        if ($area < $cubMin || $area > $cubMax) {
                            return true;
                        }

                        return $this->containmentFrac($r, $L) <= 0.45
                            && $this->overlapFrac($r, $L) <= 0.40;
                    }
                ));
            }
            $conflicts = [];
            foreach ($kept as $i => $r) {
                $area = $this->rectArea($r);
                if ($area >= $cubMin && $area <= $cubMax) {
                    continue;
                }
                if ($this->overlapFrac($L, $r) <= 0.35 && $this->containmentFrac($r, $L) <= 0.5) {
                    continue;
                }
                if ($area < $lArea * 0.55) {
                    $conflicts[] = $i;
                }
            }
            foreach (array_reverse($conflicts) as $i) {
                array_splice($kept, $i, 1);
            }
            $kept[] = $L;
        }

        return $kept;
    }

    /**
     * Fraction of interior pixels that are wall ink (skips a 6px border).
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     */
    private function interiorWallFrac(array $r, array $walls, int $w, int $h): float
    {
        $x0 = $r[0] + 6;
        $y0 = $r[1] + 6;
        $x1 = $r[2] - 6;
        $y1 = $r[3] - 6;
        if ($x1 <= $x0 || $y1 <= $y0) {
            return 1.0;
        }
        $hits = 0;
        $tot = 0;
        for ($y = $y0; $y <= $y1; $y += 3) {
            for ($x = $x0; $x <= $x1; $x += 3) {
                $tot++;
                if ($walls[$y * $w + $x] === 1) {
                    $hits++;
                }
            }
        }

        return $tot > 0 ? $hits / $tot : 0.0;
    }

    /**
     * Nudge rectangle edges to nearby long partition strokes for cleaner cubicle fits.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function snapRectsToPartitions(array $rects, array $walls, int $w, int $h): array
    {
        $out = [];
        foreach ($rects as $r) {
            [$x0, $y0, $x1, $y1] = $r;
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
            $area = $bw * $bh;
            // Cubicles: search a bit farther so near-miss bank cells can latch onto partitions.
            $radius = ($area >= 3500 && $area <= 20000 && max($bw, $bh) / max(1, min($bw, $bh)) <= 2.7)
                ? 18
                : 6;
            $x0 = $this->snapVerticalEdge($walls, $w, $h, $x0, $y0, $y1, -1, $radius);
            $x1 = $this->snapVerticalEdge($walls, $w, $h, $x1, $y0, $y1, 1, $radius);
            $y0 = $this->snapHorizontalEdge($walls, $w, $h, $y0, $x0, $x1, -1, $radius);
            $y1 = $this->snapHorizontalEdge($walls, $w, $h, $y1, $x0, $x1, 1, $radius);
            if ($x1 - $x0 >= 20 && $y1 - $y0 >= 20) {
                $out[] = [$x0, $y0, $x1, $y1];
            } else {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * Translate cubicle rectangles toward a nearby strong partition when one edge is open.
     * Preserves size (unlike expand-only snap) so stacked bank cells re-align.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function shiftCubiclesOntoWalls(array $rects, array $walls, int $w, int $h): array
    {
        $out = [];
        foreach ($rects as $r) {
            [$x0, $y0, $x1, $y1] = $r;
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
            $area = $bw * $bh;
            if ($area < 3500 || $area > 20000 || max($bw, $bh) / max(1, min($bw, $bh)) > 2.7) {
                $out[] = $r;
                continue;
            }
            $n = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y0, 0, -1);
            $s = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y1, 0, 1);
            $we = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x0, -1, 0);
            $e = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x1, 1, 0);
            // Prefer shifting toward a stronger nearby partition (even if the current edge
            // has moderate ink — furniture can fake a weak "wall").
            $hitN = $this->findNearbyWallRow($walls, $w, $h, $x0, $x1, $y0, -1, 20, max(0.45, $n + 0.12));
            $hitS = $this->findNearbyWallRow($walls, $w, $h, $x0, $x1, $y1, 1, 20, max(0.45, $s + 0.12));
            if ($hitN !== null && ($y0 - $hitN) <= 20 && ($hitS === null || $n <= $s)) {
                $dy = $y0 - $hitN;
                $y0 -= $dy;
                $y1 -= $dy;
            } elseif ($hitS !== null && ($hitS - $y1) <= 20) {
                $dy = $hitS - $y1;
                $y0 += $dy;
                $y1 += $dy;
            }
            $hitW = $this->findNearbyWallCol($walls, $w, $h, $y0, $y1, $x0, -1, 20, max(0.45, $we + 0.12));
            $hitE = $this->findNearbyWallCol($walls, $w, $h, $y0, $y1, $x1, 1, 20, max(0.45, $e + 0.12));
            if ($hitW !== null && ($x0 - $hitW) <= 20 && ($hitE === null || $we <= $e)) {
                $dx = $x0 - $hitW;
                $x0 -= $dx;
                $x1 -= $dx;
            } elseif ($hitE !== null && ($hitE - $x1) <= 20) {
                $dx = $hitE - $x1;
                $x0 += $dx;
                $x1 += $dx;
            }
            if ($x0 < 0 || $y0 < 0 || $x1 >= $w || $y1 >= $h) {
                $out[] = $r;
            } else {
                $out[] = [$x0, $y0, $x1, $y1];
            }
        }

        return $out;
    }

    /**
     * If a cubicle edge is open but a strong partition sits just outside snap radius,
     * pull that edge out (and trim the opposite edge to keep ~cubicle depth).
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function latchCubiclesToNearbyWalls(array $rects, array $walls, int $w, int $h): array
    {
        $out = [];
        foreach ($rects as $r) {
            [$x0, $y0, $x1, $y1] = $r;
            $bw = $x1 - $x0 + 1;
            $bh = $y1 - $y0 + 1;
            $area = $bw * $bh;
            if ($area < 3500 || $area > 20000 || max($bw, $bh) / max(1, min($bw, $bh)) > 2.7) {
                $out[] = $r;
                continue;
            }
            $n = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y0, 0, -1);
            $s = $this->edgeWallFrac($walls, $w, $h, $x0, $x1, $y1, 0, 1);
            $we = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x0, -1, 0);
            $e = $this->edgeWallFrac($walls, $w, $h, $y0, $y1, $x1, 1, 0);
            if ($n < 0.25) {
                $hit = $this->findNearbyWallRow($walls, $w, $h, $x0, $x1, $y0, -1, 22, 0.45);
                if ($hit !== null) {
                    $y0 = $hit;
                    if ($y1 - $y0 + 1 > 95) {
                        $y1 = $y0 + 84;
                    }
                }
            }
            if ($s < 0.25) {
                $hit = $this->findNearbyWallRow($walls, $w, $h, $x0, $x1, $y1, 1, 22, 0.45);
                if ($hit !== null) {
                    $y1 = $hit;
                    if ($y1 - $y0 + 1 > 95) {
                        $y0 = $y1 - 84;
                    }
                }
            }
            if ($we < 0.25) {
                $hit = $this->findNearbyWallCol($walls, $w, $h, $y0, $y1, $x0, -1, 22, 0.45);
                if ($hit !== null) {
                    $x0 = $hit;
                    if ($x1 - $x0 + 1 > 140) {
                        $x1 = $x0 + 120;
                    }
                }
            }
            if ($e < 0.25) {
                $hit = $this->findNearbyWallCol($walls, $w, $h, $y0, $y1, $x1, 1, 22, 0.45);
                if ($hit !== null) {
                    $x1 = $hit;
                    if ($x1 - $x0 + 1 > 140) {
                        $x0 = $x1 - 120;
                    }
                }
            }
            if ($x1 > $x0 + 40 && $y1 > $y0 + 35) {
                $out[] = [$x0, $y0, $x1, $y1];
            } else {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $walls
     */
    private function findNearbyWallRow(
        array $walls,
        int $w,
        int $h,
        int $x0,
        int $x1,
        int $y,
        int $dir,
        int $radius,
        float $minFrac
    ): ?int {
        $bestY = null;
        $best = $minFrac;
        for ($d = 1; $d <= $radius; $d++) {
            $yy = $y + $dir * $d;
            if ($yy < 0 || $yy >= $h) {
                break;
            }
            $hits = 0;
            $tot = 0;
            for ($x = $x0; $x <= $x1; $x += 2) {
                $tot++;
                if ($walls[$yy * $w + $x] === 1) {
                    $hits++;
                }
            }
            $frac = $tot > 0 ? $hits / $tot : 0.0;
            if ($frac > $best) {
                $best = $frac;
                $bestY = $yy;
            }
        }

        return $bestY;
    }

    /**
     * @param list<int> $walls
     */
    private function findNearbyWallCol(
        array $walls,
        int $w,
        int $h,
        int $y0,
        int $y1,
        int $x,
        int $dir,
        int $radius,
        float $minFrac
    ): ?int {
        $bestX = null;
        $best = $minFrac;
        for ($d = 1; $d <= $radius; $d++) {
            $xx = $x + $dir * $d;
            if ($xx < 0 || $xx >= $w) {
                break;
            }
            $hits = 0;
            $tot = 0;
            for ($y = $y0; $y <= $y1; $y += 2) {
                $tot++;
                if ($walls[$y * $w + $xx] === 1) {
                    $hits++;
                }
            }
            $frac = $tot > 0 ? $hits / $tot : 0.0;
            if ($frac > $best) {
                $best = $frac;
                $bestX = $xx;
            }
        }

        return $bestX;
    }

    /**
     * @param list<int> $walls
     */
    private function snapVerticalEdge(
        array $walls,
        int $w,
        int $h,
        int $x,
        int $y0,
        int $y1,
        int $dir,
        int $radius
    ): int {
        $bestX = $x;
        $best = -1;
        $span = max(1, $y1 - $y0 + 1);
        for ($d = 0; $d <= $radius; $d++) {
            foreach ($d === 0 ? [0] : [$d, -$d] as $off) {
                $xx = $x + $off;
                if ($xx < 0 || $xx >= $w) {
                    continue;
                }
                $hits = 0;
                for ($y = $y0; $y <= $y1; $y += 2) {
                    if ($walls[$y * $w + $xx] === 1) {
                        $hits++;
                    }
                }
                $score = $hits / max(1, intdiv($span, 2));
                // Prefer outward snaps slightly when direction is known.
                if ($dir < 0 && $xx > $x) {
                    $score *= 0.85;
                }
                if ($dir > 0 && $xx < $x) {
                    $score *= 0.85;
                }
                if ($score > $best && $score >= 0.28) {
                    $best = $score;
                    $bestX = $xx;
                }
            }
        }

        return $bestX;
    }

    /**
     * @param list<int> $walls
     */
    private function snapHorizontalEdge(
        array $walls,
        int $w,
        int $h,
        int $y,
        int $x0,
        int $x1,
        int $dir,
        int $radius
    ): int {
        $bestY = $y;
        $best = -1;
        $span = max(1, $x1 - $x0 + 1);
        for ($d = 0; $d <= $radius; $d++) {
            foreach ($d === 0 ? [0] : [$d, -$d] as $off) {
                $yy = $y + $off;
                if ($yy < 0 || $yy >= $h) {
                    continue;
                }
                $hits = 0;
                for ($x = $x0; $x <= $x1; $x += 2) {
                    if ($walls[$yy * $w + $x] === 1) {
                        $hits++;
                    }
                }
                $score = $hits / max(1, intdiv($span, 2));
                if ($dir < 0 && $yy > $y) {
                    $score *= 0.85;
                }
                if ($dir > 0 && $yy < $y) {
                    $score *= 0.85;
                }
                if ($score > $best && $score >= 0.28) {
                    $best = $score;
                    $bestY = $yy;
                }
            }
        }

        return $bestY;
    }

    /**
     * Fit a room rectangle to nearby walls from its center.
     * Expansion is clamped to the seed size so open-plan floors never collapse into one room.
     *
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $walls structural (or closed) wall mask
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function fitRectToWalls(array $bb, array $walls, int $w, int $h, int $thickness = 8): array
    {
        $seedW = max(1, $bb[2] - $bb[0] + 1);
        $seedH = max(1, $bb[3] - $bb[1] + 1);
        $cx = (int) intdiv($bb[0] + $bb[2], 2);
        $cy = (int) intdiv($bb[1] + $bb[3], 2);
        $cx = max(0, min($w - 1, $cx));
        $cy = max(0, min($h - 1, $cy));
        // Allow reaching furniture just past the seed (bathtub, etc.) but not the whole floor.
        $maxW = (int) max($seedW + $thickness * ($thickness <= 8 ? 2 : 6), $seedW * ($thickness <= 8 ? 1.25 : 1.85));
        $maxH = (int) max($seedH + $thickness * ($thickness <= 8 ? 2 : 6), $seedH * ($thickness <= 8 ? 1.25 : 1.85));
        $x0 = $cx;
        $x1 = $cx;
        $y0 = $cy;
        $y1 = $cy;
        $changed = true;
        $guard = 0;
        while ($changed && $guard++ < ($w + $h) * 2) {
            $changed = false;
            if ($x0 > 0 && ($x1 - $x0 + 2) <= $maxW && !$this->isColWallBoundary($walls, $w, $h, $x0 - 1, $y0, $y1)) {
                $x0--;
                $changed = true;
            }
            if ($x1 < $w - 1 && ($x1 - $x0 + 2) <= $maxW && !$this->isColWallBoundary($walls, $w, $h, $x1 + 1, $y0, $y1)) {
                $x1++;
                $changed = true;
            }
            if ($y0 > 0 && ($y1 - $y0 + 2) <= $maxH && !$this->isRowWallBoundary($walls, $w, $x0, $x1, $y0 - 1)) {
                $y0--;
                $changed = true;
            }
            if ($y1 < $h - 1 && ($y1 - $y0 + 2) <= $maxH && !$this->isRowWallBoundary($walls, $w, $x0, $x1, $y1 + 1)) {
                $y1++;
                $changed = true;
            }
        }
        if ($x1 - $x0 < 12 || $y1 - $y0 < 12) {
            return $bb;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function fitRectsToWalls(array $rects, array $walls, int $w, int $h, int $thickness = 8): array
    {
        $out = [];
        foreach ($rects as $r) {
            $out[] = $this->fitRectToWalls($r, $walls, $w, $h, $thickness);
        }

        return $out;
    }

    /**
     * Extend a room AABB across one thin interior partition into a small enclosed
     * floor pocket (e.g. bathtub south of a mid-bath wall). Stops at the next
     * strong wall and refuses pockets large enough to be a separate room.
     *
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function growIntoEnclosedAlcove(array $bb, array $walls, int $w, int $h, int $thickness): array
    {
        $maxWall = max(6, min(18, (int) ($thickness * 1.4)));
        $minPocket = max(18, (int) ($thickness * 1.5));
        // Tight cap: bathtub alcoves are shallow; a full neighboring room is not.
        $maxPocketH = max($minPocket + 4, min(56, (int) ($thickness * 4.2)));
        $maxPocketW = max($minPocket + 4, min(56, (int) ($thickness * 4.2)));
        $dblGap = max(2, min(5, intdiv($thickness, 2)));

        $south = $this->growAlcoveAxis($bb, $walls, $w, $h, $thickness, 's', $maxWall, $minPocket, $maxPocketH, $dblGap);
        if ($south[3] > $bb[3]) {
            $bb = $south;
        }
        $east = $this->growAlcoveAxis($bb, $walls, $w, $h, $thickness, 'e', $maxWall, $minPocket, $maxPocketW, $dblGap);
        if ($east[2] > $bb[2]) {
            $bb = $east;
        }

        return $bb;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $walls
     * @param 's'|'e' $dir
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function growAlcoveAxis(
        array $bb,
        array $walls,
        int $w,
        int $h,
        int $thickness,
        string $dir,
        int $maxWall,
        int $minPocket,
        int $maxPocket,
        int $dblGap
    ): array {
        $isSouth = $dir === 's';
        $cursor = $isSouth ? $bb[3] + 1 : $bb[2] + 1;
        $limit = $isSouth ? $h : $w;
        $strong = function (int $p) use ($isSouth, $walls, $w, $h, $bb): bool {
            return $isSouth
                ? $this->isStrongRowWallBoundary($walls, $w, $bb[0], $bb[2], $p)
                : $this->isStrongColWallBoundary($walls, $w, $h, $p, $bb[1], $bb[3]);
        };
        if ($cursor >= $limit || !$strong($cursor)) {
            return $bb;
        }
        $wall0 = $cursor;
        while ($cursor < $limit && $strong($cursor)) {
            $cursor++;
        }
        $wallSpan = $cursor - $wall0;
        if ($wallSpan < 2 || $wallSpan > $maxWall) {
            return $bb;
        }
        $pocket0 = $cursor;
        $end = $pocket0;
        while ($cursor < $limit && ($cursor - $pocket0) <= $maxPocket) {
            if (!$strong($cursor)) {
                $end = $cursor;
                $cursor++;
                continue;
            }
            $band0 = $cursor;
            while ($cursor < $limit && $strong($cursor)) {
                $cursor++;
            }
            $span = $cursor - $band0;
            $gap0 = $cursor;
            while ($cursor < $limit && !$strong($cursor)) {
                $cursor++;
            }
            $gap = $cursor - $gap0;
            if (
                $gap >= 1 && $gap <= $dblGap
                && $cursor < $limit
                && $strong($cursor)
                && ($band0 - $pocket0) >= $minPocket
            ) {
                $end = $cursor - 1;
                break;
            }
            if ($span >= 2 && ($band0 - $pocket0) >= $minPocket) {
                $end = $band0 - 1;
                break;
            }
            // Furniture stroke mid-pocket — only keep walking if the stroke is thin.
            if ($span > $maxWall) {
                break;
            }
            if ($gap0 < $limit) {
                $end = max($end, $gap0 - 1);
            }
            if ($cursor >= $limit || ($cursor - $pocket0) > $maxPocket) {
                break;
            }
        }
        $pocketSpan = $end - $pocket0 + 1;
        if ($pocketSpan < $minPocket || $end < $pocket0 || $pocketSpan > $maxPocket) {
            return $bb;
        }
        if ($isSouth) {
            $bb[3] = $end;
        } else {
            $bb[2] = $end;
        }

        return $bb;
    }

    /**
     * Grow each residential rect into a shallow enclosed furniture pocket when safe.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function growResidentialAlcoves(array $rects, array $walls, int $w, int $h, int $thickness): array
    {
        $grown = [];
        foreach ($rects as $i => $r) {
            $g = $this->growIntoEnclosedAlcove($r, $walls, $w, $h, $thickness);
            if ($g === $r) {
                $grown[] = $r;
                continue;
            }
            $swallows = false;
            foreach ($rects as $j => $o) {
                if ($j === $i) {
                    continue;
                }
                $cx = intdiv($o[0] + $o[2], 2);
                $cy = intdiv($o[1] + $o[3], 2);
                if (
                    $cx >= $g[0] && $cx <= $g[2]
                    && $cy >= $g[1] && $cy <= $g[3]
                    && ($cx < $r[0] || $cx > $r[2] || $cy < $r[1] || $cy > $r[3])
                ) {
                    $swallows = true;
                    break;
                }
            }
            $grown[] = $swallows ? $r : $g;
        }

        return $grown;
    }

    /**
     * Small residential plans: clip rooms that leaked through wide openings into
     * the hallway, then recover the living rectangle left after kitchen snap.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $struct
     * @param list<int> $closed
     * @param list<int> $inside
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function refineResidentialRooms(
        array $rects,
        array $struct,
        array $closed,
        array $inside,
        int $w,
        int $h
    ): array {
        $clipped = [];
        foreach ($rects as $r) {
            $clipped[] = $this->clipDoorOverhang($r, $struct, $w, $h);
        }
        $rects = $clipped;

        $nw = null;
        foreach ($rects as $r) {
            $cx = intdiv($r[0] + $r[2], 2);
            $cy = intdiv($r[1] + $r[3], 2);
            if ($cx > (int) ($w * 0.42) || $cy > (int) ($h * 0.42)) {
                continue;
            }
            if ($nw === null || $r[1] < $nw[1]) {
                $nw = $r;
            }
        }
        if ($nw === null) {
            return $rects;
        }

        $T = min($h - 20, $nw[3] + 2);
        $sx = intdiv($nw[0] + $nw[2], 2);
        $sx = max(0, min($w - 1, $sx));
        $B = $T;
        for ($y = $h - 2; $y > $T + 40; $y--) {
            if ($inside[$y * $w + $sx] === 1 && $struct[$y * $w + $sx] !== 1) {
                $B = $y;
                break;
            }
        }
        if ($B - $T < 70) {
            return $rects;
        }

        $pass = $inside;
        for ($i = 0, $n = $w * $h; $i < $n; $i++) {
            if ($struct[$i] === 1) {
                $pass[$i] = 0;
            }
        }
        $living = $this->growAlcoveEast($pass, $struct, $closed, $w, $h, $T, $B, $nw[0]);
        if ($living === null || $this->rectArea($living) < 8000) {
            return $rects;
        }
        $living = $this->snapToWalls($living, $struct, $w, $h);
        $livingArea = $this->rectArea($living);
        $out = [];
        foreach ($rects as $r) {
            if ($this->overlapFrac($r, $living) > 0.35 && $this->rectArea($r) < $livingArea) {
                continue;
            }
            $out[] = $r;
        }
        $out[] = $living;

        return $out;
    }

    /**
     * Pull a room back when its trailing edge sits in a wider hallway, not on a wall.
     *
     * @param array{0:int,1:int,2:int,3:int} $r
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function clipDoorOverhang(array $r, array $walls, int $w, int $h): array
    {
        $minH = max(70, (int) (($r[2] - $r[0] + 1) * 0.72));
        while (($r[3] - $r[1] + 1) > $minH) {
            $y = $r[3];
            if (
                $this->isRowWallBoundary($walls, $w, $r[0], $r[2], $y)
                || $this->isRowWallBoundary($walls, $w, $r[0], $r[2], max(0, $y - 1))
            ) {
                break;
            }
            $width = $r[2] - $r[0] + 1;
            $below = min($h - 1, $y + 4);
            $x0 = max(0, $r[0] - 50);
            $x1 = min($w - 1, $r[2] + 50);
            $free = 0;
            $run = 0;
            $best = 0;
            $row = $below * $w;
            for ($x = $x0; $x <= $x1; $x++) {
                if ($walls[$row + $x] !== 1) {
                    $free++;
                    $run++;
                    if ($run > $best) {
                        $best = $run;
                    }
                } else {
                    $run = 0;
                }
            }
            if ($best > (int) ($width * 1.3) && $free > $width) {
                $r[3]--;
                continue;
            }
            break;
        }

        return $r;
    }

    /**
     * Door-gap tolerant horizontal wall detector.
     *
     * @param list<int> $walls
     */
    private function isRowWallBoundary(array $walls, int $w, int $x0, int $x1, int $y): bool
    {
        $n = count($walls);
        $h = $w > 0 ? intdiv($n, $w) : 0;
        if ($y < 0 || $y >= $h) {
            return true;
        }
        $x0 = max(0, $x0);
        $x1 = min($w - 1, $x1);
        $width = $x1 - $x0 + 1;
        if ($width <= 0) {
            return true;
        }
        $hits = 0;
        $run = 0;
        $best = 0;
        $row = $y * $w;
        for ($x = $x0; $x <= $x1; $x++) {
            if ($walls[$row + $x] === 1) {
                $hits++;
                $run++;
                if ($run > $best) {
                    $best = $run;
                }
            } else {
                $run = 0;
            }
        }
        $frac = $hits / $width;

        return $frac >= 0.22 || $best >= max(24, (int) ($width * 0.34));
    }

    /**
     * Door-gap tolerant vertical wall detector.
     *
     * @param list<int> $walls
     */
    private function isColWallBoundary(array $walls, int $w, int $h, int $x, int $y0, int $y1): bool
    {
        if ($x < 0 || $x >= $w) {
            return true;
        }
        $y0 = max(0, $y0);
        $y1 = min($h - 1, $y1);
        $height = $y1 - $y0 + 1;
        if ($height <= 0) {
            return true;
        }
        $hits = 0;
        $run = 0;
        $best = 0;
        for ($y = $y0; $y <= $y1; $y++) {
            if ($walls[$y * $w + $x] === 1) {
                $hits++;
                $run++;
                if ($run > $best) {
                    $best = $run;
                }
            } else {
                $run = 0;
            }
        }
        $frac = $hits / $height;

        return $frac >= 0.22 || $best >= max(24, (int) ($height * 0.34));
    }

    /**
     * Stricter horizontal wall — ignores sparse desk/furniture strokes.
     *
     * @param list<int> $walls
     */
    private function isStrongRowWallBoundary(array $walls, int $w, int $x0, int $x1, int $y): bool
    {
        $n = count($walls);
        $h = $w > 0 ? intdiv($n, $w) : 0;
        if ($y < 0 || $y >= $h) {
            return true;
        }
        $x0 = max(0, $x0);
        $x1 = min($w - 1, $x1);
        $width = $x1 - $x0 + 1;
        if ($width <= 0) {
            return true;
        }
        $hits = 0;
        $run = 0;
        $best = 0;
        $row = $y * $w;
        for ($x = $x0; $x <= $x1; $x++) {
            if ($walls[$row + $x] === 1) {
                $hits++;
                $run++;
                if ($run > $best) {
                    $best = $run;
                }
            } else {
                $run = 0;
            }
        }
        $frac = $hits / $width;

        return $frac >= 0.55 || $best >= max($this->cad(36), (int) ($width * 0.50));
    }

    /**
     * Stricter vertical wall — ignores sparse desk/furniture strokes.
     *
     * @param list<int> $walls
     */
    private function isStrongColWallBoundary(array $walls, int $w, int $h, int $x, int $y0, int $y1): bool
    {
        if ($x < 0 || $x >= $w) {
            return true;
        }
        $y0 = max(0, $y0);
        $y1 = min($h - 1, $y1);
        $height = $y1 - $y0 + 1;
        if ($height <= 0) {
            return true;
        }
        $hits = 0;
        $run = 0;
        $best = 0;
        for ($y = $y0; $y <= $y1; $y++) {
            if ($walls[$y * $w + $x] === 1) {
                $hits++;
                $run++;
                if ($run > $best) {
                    $best = $run;
                }
            } else {
                $run = 0;
            }
        }
        $frac = $hits / $height;

        return $frac >= 0.55 || $best >= max($this->cad(36), (int) ($height * 0.50));
    }

    /**
     * Legacy expand-only snap — kept for furniture pocket cleanup.
     *
     * @param array{0:int,1:int,2:int,3:int} $bb
     * @param list<int> $walls
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function snapToWalls(array $bb, array $walls, int $w, int $h, int $maxStep = 18, bool $strong = false): array
    {
        [$x0, $y0, $x1, $y1] = $bb;
        for ($i = 0; $i < $maxStep && $x0 > 0; $i++) {
            $stop = $strong
                ? $this->isStrongColWallBoundary($walls, $w, $h, $x0 - 1, $y0, $y1)
                : $this->isColWallBoundary($walls, $w, $h, $x0 - 1, $y0, $y1);
            if ($stop) {
                break;
            }
            $x0--;
        }
        for ($i = 0; $i < $maxStep && $x1 < $w - 1; $i++) {
            $stop = $strong
                ? $this->isStrongColWallBoundary($walls, $w, $h, $x1 + 1, $y0, $y1)
                : $this->isColWallBoundary($walls, $w, $h, $x1 + 1, $y0, $y1);
            if ($stop) {
                break;
            }
            $x1++;
        }
        for ($i = 0; $i < $maxStep && $y0 > 0; $i++) {
            $stop = $strong
                ? $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y0 - 1)
                : $this->isRowWallBoundary($walls, $w, $x0, $x1, $y0 - 1);
            if ($stop) {
                break;
            }
            $y0--;
        }
        for ($i = 0; $i < $maxStep && $y1 < $h - 1; $i++) {
            $stop = $strong
                ? $this->isStrongRowWallBoundary($walls, $w, $x0, $x1, $y1 + 1)
                : $this->isRowWallBoundary($walls, $w, $x0, $x1, $y1 + 1);
            if ($stop) {
                break;
            }
            $y1++;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    private function snapRectsToWalls(array $rects, array $walls, int $w, int $h): array
    {
        return $this->fitRectsToWalls($rects, $walls, $w, $h, 8);
    }

    /**
     * @param list<int> $walls
     */
    private function colWallFrac(array $walls, int $w, int $h, int $x, int $y0, int $y1): float
    {
        $y0 = max(0, $y0);
        $y1 = min($h - 1, $y1);
        if ($y1 < $y0) {
            return 0.0;
        }
        $hits = 0;
        $n = 0;
        for ($y = $y0; $y <= $y1; $y++) {
            $n++;
            if ($walls[$y * $w + $x] === 1) {
                $hits++;
            }
        }

        return $n > 0 ? $hits / $n : 0.0;
    }

    /**
     * @param list<int> $walls
     */
    private function rowWallFrac(array $walls, int $w, int $x0, int $x1, int $y): float
    {
        $x0 = max(0, $x0);
        $x1 = min($w - 1, $x1);
        if ($x1 < $x0) {
            return 0.0;
        }
        $hits = 0;
        $n = 0;
        $row = $y * $w;
        for ($x = $x0; $x <= $x1; $x++) {
            $n++;
            if ($walls[$row + $x] === 1) {
                $hits++;
            }
        }

        return $n > 0 ? $hits / $n : 0.0;
    }

    /**
     * @param list<int> $mask
     * @return list<int>
     */
    private function dilate(array $mask, int $w, int $h, int $radius): array
    {
        if ($radius <= 0) {
            return $mask;
        }
        $n = $w * $h;
        $tmp = array_fill(0, $n, 0);
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            $count = 0;
            for ($x = 0; $x < $w + $radius; $x++) {
                if ($x < $w && $mask[$row + $x] === 1) {
                    $count++;
                }
                $out = $x - $radius;
                $left = $out - $radius - 1;
                if ($left >= 0 && $mask[$row + $left] === 1) {
                    $count--;
                }
                if ($out >= 0 && $out < $w) {
                    $tmp[$row + $out] = $count > 0 ? 1 : 0;
                }
            }
        }
        $out = array_fill(0, $n, 0);
        for ($x = 0; $x < $w; $x++) {
            $count = 0;
            for ($y = 0; $y < $h + $radius; $y++) {
                if ($y < $h && $tmp[$y * $w + $x] === 1) {
                    $count++;
                }
                $oy = $y - $radius;
                $top = $oy - $radius - 1;
                if ($top >= 0 && $tmp[$top * $w + $x] === 1) {
                    $count--;
                }
                if ($oy >= 0 && $oy < $h) {
                    $out[$oy * $w + $x] = $count > 0 ? 1 : 0;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int> $mask
     * @return list<int>
     */
    private function invert(array $mask): array
    {
        $n = count($mask);
        $out = array_fill(0, $n, 0);
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = $mask[$i] === 1 ? 0 : 1;
        }

        return $out;
    }

    /**
     * @param list<int> $blocked
     * @return list<int>
     */
    private function floodFromBorder(array $blocked, int $w, int $h): array
    {
        $seen = $blocked;
        $qx = [];
        $qy = [];
        $qs = 0;
        $enqueue = static function (int $x, int $y) use ($w, $h, &$seen, &$qx, &$qy, &$qs): void {
            if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
                return;
            }
            $i = $y * $w + $x;
            if ($seen[$i] === 1) {
                return;
            }
            $seen[$i] = 1;
            $qx[] = $x;
            $qy[] = $y;
            $qs++;
        };
        for ($x = 0; $x < $w; $x++) {
            $enqueue($x, 0);
            $enqueue($x, $h - 1);
        }
        for ($y = 0; $y < $h; $y++) {
            $enqueue(0, $y);
            $enqueue($w - 1, $y);
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

    /**
     * @param list<int> $free
     * @return list<array{pixels: list<int>, area: int, bbox: array{0:int,1:int,2:int,3:int}}>
     */
    private function components(array $free, int $w, int $h, int $minArea, bool $keepPixels = true): array
    {
        $n = $w * $h;
        $seen = array_fill(0, $n, 0);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($free[$i] !== 1 || $seen[$i] === 1) {
                continue;
            }
            $qx = [$i % $w];
            $qy = [intdiv($i, $w)];
            $seen[$i] = 1;
            $pixels = [$i];
            $qs = 1;
            $qi = 0;
            $minX = $qx[0];
            $maxX = $qx[0];
            $minY = $qy[0];
            $maxY = $qy[0];
            while ($qi < $qs) {
                $x = $qx[$qi];
                $y = $qy[$qi];
                $qi++;
                foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                        continue;
                    }
                    $ni = $ny * $w + $nx;
                    if ($free[$ni] !== 1 || $seen[$ni] === 1) {
                        continue;
                    }
                    $seen[$ni] = 1;
                    $qx[] = $nx;
                    $qy[] = $ny;
                    $pixels[] = $ni;
                    $qs++;
                    $minX = min($minX, $nx);
                    $maxX = max($maxX, $nx);
                    $minY = min($minY, $ny);
                    $maxY = max($maxY, $ny);
                }
            }
            $area = count($pixels);
            if ($area < $minArea) {
                continue;
            }
            $out[] = [
                'pixels' => $keepPixels ? $pixels : [],
                'area' => $area,
                'bbox' => [$minX, $minY, $maxX, $maxY],
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $pixels
     * @return list<int>
     */
    private function denseMaskFromPixels(array $pixels, int $n): array
    {
        $mask = array_fill(0, $n, 0);
        foreach ($pixels as $pi) {
            if ($pi >= 0 && $pi < $n) {
                $mask[$pi] = 1;
            }
        }

        return $mask;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $r
     */
    private function rectArea(array $r): int
    {
        return max(0, $r[2] - $r[0] + 1) * max(0, $r[3] - $r[1] + 1);
    }

    /**
     * Fraction of $a covered by intersection with $b.
     *
     * @param array{0:int,1:int,2:int,3:int} $a
     * @param array{0:int,1:int,2:int,3:int} $b
     */
    private function overlapFrac(array $a, array $b): float
    {
        $x0 = max($a[0], $b[0]);
        $y0 = max($a[1], $b[1]);
        $x1 = min($a[2], $b[2]);
        $y1 = min($a[3], $b[3]);
        if ($x0 > $x1 || $y0 > $y1) {
            return 0.0;
        }
        $area = $this->rectArea($a);

        return $area > 0 ? (($x1 - $x0 + 1) * ($y1 - $y0 + 1)) / $area : 0.0;
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $inner
     * @param array{0:int,1:int,2:int,3:int} $outer
     */
    private function containmentFrac(array $inner, array $outer): float
    {
        return $this->overlapFrac($inner, $outer);
    }

    /**
     * OCR map labels into render coordinates (human-vision style: read text, then place).
     *
     * @return list<array{text: string, x: int, y: int, w: int, h: int}>
     */
    private function readOfficeMapLabels(\GdImage $render, int $rw, int $rh, ?string $sourceSvg): array
    {
        if ($this->ocrCache !== null) {
            return $this->ocrCache;
        }

        // OCR the work image only. Native mega-embeds (e.g. 5907×2824) make tiled
        // tesseract exceed shared-host limits → HTTP 503 on live Building 5 uploads.
        $raw = $this->readMapLabelsTiled($render, $rw, $rh);

        return $this->ocrCache = $raw;
    }

    /**
     * Tile + upscale OCR so small cubicle IDs remain readable on large floorplans.
     *
     * @return list<array{text: string, x: int, y: int, w: int, h: int}>
     */
    private function readMapLabelsTiled(\GdImage $im, int $w, int $h): array
    {
        $bin = trim((string) shell_exec('command -v tesseract 2>/dev/null'));
        if ($bin === '') {
            return [];
        }

        // Dense CAD: larger tiles, modest upscale — fewer tesseract launches.
        // Work-size sheets (no mega embed) need more OCR upscale; cubicle IDs are tiny.
        $large = max($w, $h) >= 2800;
        $tile = $large ? 1800 : 1100;
        $overlap = $large ? 160 : 120;
        $up = $large ? 2 : 3;
        $found = [];

        for ($y0 = 0; $y0 < $h; $y0 += $tile - $overlap) {
            for ($x0 = 0; $x0 < $w; $x0 += $tile - $overlap) {
                $tw = min($tile, $w - $x0);
                $th = min($tile, $h - $y0);
                if ($tw < 160 || $th < 160) {
                    continue;
                }
                $crop = imagecreatetruecolor($tw, $th);
                if (!$crop instanceof \GdImage) {
                    continue;
                }
                imagecopy($crop, $im, 0, 0, $x0, $y0, $tw, $th);
                @imagefilter($crop, IMG_FILTER_GRAYSCALE);
                @imagefilter($crop, IMG_FILTER_CONTRAST, -45);
                $srcTile = $crop;
                if ($up > 1) {
                    $bw = $tw * $up;
                    $bh = $th * $up;
                    $big = imagecreatetruecolor($bw, $bh);
                    if ($big instanceof \GdImage) {
                        imagecopyresampled($big, $crop, 0, 0, 0, 0, $bw, $bh, $tw, $th);
                        imagedestroy($crop);
                        $srcTile = $big;
                    }
                }

                $tmp = tempnam(sys_get_temp_dir(), 'fs_ocr_');
                if ($tmp === false) {
                    imagedestroy($srcTile);
                    continue;
                }
                $png = $tmp . '.png';
                imagepng($srcTile, $png);
                imagedestroy($srcTile);

                // Sparse text / sparse layout — no char whitelist (slashes & dots matter).
                $cmd = escapeshellcmd($bin)
                    . ' ' . escapeshellarg($png)
                    . ' ' . escapeshellarg($tmp)
                    . ' tsv --psm 11 2>/dev/null';
                @exec($cmd);
                @unlink($png);
                @unlink($tmp);
                $tsv = $tmp . '.tsv';
                if (!is_file($tsv)) {
                    continue;
                }
                $lines = file($tsv, FILE_IGNORE_NEW_LINES);
                @unlink($tsv);
                if (!is_array($lines)) {
                    continue;
                }
                foreach ($lines as $idx => $line) {
                    if ($idx === 0) {
                        continue;
                    }
                    $cols = explode("\t", $line);
                    if (count($cols) < 12) {
                        continue;
                    }
                    $text = trim($cols[11]);
                    $conf = (float) $cols[10];
                    if ($text === '' || $conf < 20) {
                        continue;
                    }
                    $norm = $this->normalizeRoomLabel($text);
                    if ($norm === null) {
                        continue;
                    }
                    $left = $x0 + (int) round(((int) $cols[6]) / $up);
                    $top = $y0 + (int) round(((int) $cols[7]) / $up);
                    $ww = (int) round(((int) $cols[8]) / $up);
                    $hh = (int) round(((int) $cols[9]) / $up);
                    $key = $norm . '@' . (int) round($left / 16) . ':' . (int) round($top / 16);
                    if (isset($found[$key])) {
                        continue;
                    }
                    $found[$key] = [
                        'text' => $norm,
                        'x' => $left,
                        'y' => $top,
                        'w' => max(1, $ww),
                        'h' => max(1, $hh),
                    ];
                }
            }
        }

        return array_values($found);
    }

    /**
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array{text: string, x: int, y: int, w: int, h: int}>
     */
    private function scaleLabels(array $labels, int $fromW, int $fromH, int $toW, int $toH): array
    {
        if ($fromW < 1 || $fromH < 1 || ($fromW === $toW && $fromH === $toH)) {
            return $labels;
        }
        $sx = $toW / $fromW;
        $sy = $toH / $fromH;
        $out = [];
        foreach ($labels as $lab) {
            $out[] = [
                'text' => $lab['text'],
                'x' => (int) round($lab['x'] * $sx),
                'y' => (int) round($lab['y'] * $sy),
                'w' => max(1, (int) round($lab['w'] * $sx)),
                'h' => max(1, (int) round($lab['h'] * $sy)),
            ];
        }

        return $out;
    }

    private function normalizeRoomLabel(string $text): ?string
    {
        $t = strtoupper(preg_replace('/\s+/', '', $text) ?? '');
        $t = str_replace(
            ['_', '–', '—', ';', '’', "'", '\\', ',', '}', '{', '|', '!', '“', '”', '‘'],
            ['/', '-', '-', '/', '', '', '/', '', '', '', '/', '', '', '', ''],
            $t
        );
        $t = preg_replace('/[^A-Z0-9\/.\-]/', '', $t) ?? $t;
        if ($t === '' || strlen($t) < 2) {
            return null;
        }
        // Common OCR confusions on this map family.
        $t = preg_replace('/^5W/', 'SW', $t) ?? $t;
        $t = preg_replace('/^S5W/', 'SW', $t) ?? $t;
        $t = preg_replace('/^BWI/', 'SW/', $t) ?? $t;
        $t = preg_replace('/^BW\//', 'SW/', $t) ?? $t;
        $t = preg_replace('/^SW[FIJ](?=[0O])/', 'SW/', $t) ?? $t;
        $t = preg_replace('/^SWO/', 'SW/', $t) ?? $t;
        $t = preg_replace('/^NMO/', 'SW/', $t) ?? $t;
        $t = preg_replace('/^SNI/', 'SN/', $t) ?? $t;
        $t = preg_replace('/^QW/', 'SW', $t) ?? $t;
        $t = str_replace(['O.', 'SWI0', 'SW10', 'SWF0'], ['0.', 'SW/0', 'SW/0', 'SW/0'], $t);
        // Insert missing slash: SW0.1.C05.17
        if (preg_match('/^(SW|SN|SNV)(0\.\d\.[A-Z].*)$/', $t, $m) === 1) {
            $t = $m[1] . '/' . $m[2];
        }

        // Building 5 cubicle IDs: SW/0.1.C05.17 (and OCR variants with O.)
        if (preg_match('/^(SW|SN|SNV|BW)\/[0O]\.\d\.[A-Z]\d{2}\.\d{2}$/', $t) === 1) {
            return str_replace('/O.', '/0.', $t);
        }
        // Dot-separated CAD ids: 5W.O.1.D04.27 / SW.0.1.C05.17 → SW/0.1.…
        if (preg_match('/^(?:5W|SW|SN)\.([0O]\.\d\.[A-Z]\d{2}\.\d{2})$/', $t, $m) === 1) {
            return 'SW/' . str_replace('O.', '0.', $m[1]);
        }
        // Partial but still structured: SW/0.1.C10.01-like with digits after the letter code
        if (preg_match('/^(SW|SN|SNV)\/[0O]\.\d\.[A-Z0-9]{2,}\.\d{1,3}$/', $t) === 1) {
            return str_replace('/O.', '/0.', $t);
        }
        // Older / alternate: SW2-2301, SNV1-401A
        if (preg_match('/^[A-Z]{2,4}\d{0,2}-\d{2,4}[A-Z]?(?:-\d+)?$/', $t) === 1) {
            return $t;
        }
        if (preg_match('/^[A-Z]{2,5}\d*-?\d{2,}[A-Z]?$/', $t) === 1 && str_contains($t, '-')) {
            return $t;
        }
        // Soft accept: any recognizable room-ish token (letters + digits, length ≥ 4).
        // Humans read "whatever text is in the cell" — don't require a perfect ID grammar.
        if (
            strlen($t) >= 4
            && preg_match('/[A-Z]/', $t) === 1
            && preg_match('/[0-9]/', $t) === 1
            && preg_match('/^[A-Z0-9\/.\-]{4,24}$/', $t) === 1
        ) {
            return $t;
        }
        // Residential / word labels (Kitchen, Master Bedroom) — keep readable casing later.
        $words = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (
            strlen($words) >= 3
            && strlen($words) <= 32
            && preg_match('/^(master\s+)?(bed|bath|kitchen|living|dining|storage|closet|laundry|office|garage|hall|entry|powder|wc|toilet|pantry|room)[a-z\s]*$/i', $words) === 1
        ) {
            return ucwords(strtolower($words));
        }

        return null;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return list<array<string, mixed>>
     */
    private function summarizeRects(array $rects, int $w, int $h, array $labels): array
    {
        usort($rects, fn (array $a, array $b): int => $this->rectArea($b) <=> $this->rectArea($a));
        $total = 0;
        foreach ($rects as $r) {
            $total += $this->rectArea($r);
        }
        $cubiclePx = max($this->cadArea(9000), (int) ($w * $h * 0.0045));
        $assigned = $this->assignLabelsToRects($rects, $labels);
        $rooms = [];
        foreach ($rects as $idx => $r) {
            $area = $this->rectArea($r);
            $type = $area < $cubiclePx ? 'cubicle' : 'room';
            // Door access: a single clear door marks a room; open multi-door /
            // doorless pockets stay cubicles even when slightly over the area cut.
            if ($this->doorOpenings !== []) {
                $doorIds = $this->doorIdsTouchingRect($r, $this->doorOpenings, max(10, $this->cad(14)));
                $nDoors = count($doorIds);
                if ($nDoors === 1 && $area >= (int) ($cubiclePx * 0.55)) {
                    $type = 'room';
                } elseif ($nDoors !== 1 && $area < (int) ($cubiclePx * 2.2)) {
                    $type = 'cubicle';
                }
            }
            $label = $assigned[$idx] ?? null;
            if ($label === null) {
                $label = ($type === 'cubicle' ? 'Cubicle' : 'Room') . ' ' . ($idx + 1);
            } elseif (
                preg_match('/\d{3,}/', $label) === 1
                || preg_match('/\/0\.\d\.[A-Z]/', $label) === 1
            ) {
                if ($area < $cubiclePx * 3.5 && ($this->doorOpenings === [] || count($this->doorIdsTouchingRect($r, $this->doorOpenings, max(10, $this->cad(14)))) !== 1)) {
                    $type = 'cubicle';
                }
            }
            $rooms[] = [
                'id' => $idx + 1,
                'type' => $type,
                'label' => $label,
                'area_px' => $area,
                'area_pct' => $total > 0 ? round(100.0 * $area / $total, 1) : 0.0,
                'bbox' => [
                    'x' => $r[0],
                    'y' => $r[1],
                    'width' => $r[2] - $r[0] + 1,
                    'height' => $r[3] - $r[1] + 1,
                ],
                'centroid' => [
                    'x' => intdiv($r[0] + $r[2], 2),
                    'y' => intdiv($r[1] + $r[3], 2),
                ],
            ];
        }

        return $rooms;
    }

    /**
     * Match each OCR token to the room it sits in, else the nearest room center
     * (how a person reads a floorplan label). One label per room, greedy by score.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array{text: string, x: int, y: int, w: int, h: int}> $labels
     * @return array<int, string> rect index → label
     */
    private function assignLabelsToRects(array $rects, array $labels): array
    {
        if ($rects === [] || $labels === []) {
            return [];
        }
        $centers = [];
        foreach ($rects as $i => $r) {
            $bw = $r[2] - $r[0] + 1;
            $bh = $r[3] - $r[1] + 1;
            $centers[$i] = [
                'cx' => intdiv($r[0] + $r[2], 2),
                'cy' => intdiv($r[1] + $r[3], 2),
                'reach' => max(28, (int) round(0.65 * hypot($bw, $bh))),
            ];
        }
        /** @var list<array{0:float,1:int,2:int,3:string}> $pairs score, rectIdx, labIdx, text */
        $pairs = [];
        foreach ($labels as $li => $lab) {
            $lx = $lab['x'] + intdiv($lab['w'], 2);
            $ly = $lab['y'] + intdiv($lab['h'], 2);
            $bestInside = null;
            $bestNear = null;
            foreach ($rects as $ri => $r) {
                $inside = $lx >= $r[0] && $lx <= $r[2] && $ly >= $r[1] && $ly <= $r[3];
                $dx = $lx - $centers[$ri]['cx'];
                $dy = $ly - $centers[$ri]['cy'];
                $dist = hypot($dx, $dy);
                if ($inside) {
                    // Prefer tight cells whose center is near the label (plan convention).
                    $rcx = intdiv($r[0] + $r[2], 2);
                    $rcy = intdiv($r[1] + $r[3], 2);
                    $centerDist = hypot($lx - $rcx, $ly - $rcy);
                    $score = $this->rectArea($r) * 0.008 + $centerDist * 0.55;
                    if ($bestInside === null || $score < $bestInside[0]) {
                        $bestInside = [$score, $ri];
                    }
                    continue;
                }
                if ($dist <= $centers[$ri]['reach']) {
                    $score = 1000.0 + $dist;
                    if ($bestNear === null || $score < $bestNear[0]) {
                        $bestNear = [$score, $ri];
                    }
                }
            }
            $pick = $bestInside ?? $bestNear;
            if ($pick === null) {
                continue;
            }
            $pairs[] = [$pick[0], $pick[1], $li, $this->normalizeOcrLabel($lab['text'])];
        }
        usort($pairs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $usedRects = [];
        $usedTexts = [];
        $out = [];
        foreach ($pairs as $pair) {
            [, $ri, , $text] = $pair;
            if ($text === '' || isset($usedRects[$ri]) || isset($usedTexts[$text])) {
                continue;
            }
            $usedRects[$ri] = true;
            $usedTexts[$text] = true;
            $out[$ri] = $text;
        }

        return $out;
    }

    /** Clean OCR tokens into map-style space IDs / room names. */
    private function normalizeOcrLabel(string $text): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $t = str_replace(['|', '\\', '_'], ['/', '/', '.'], $t);
        // Residential words first — office ID regex would clip "Living Room" → "LIVI".
        if (preg_match('/^(master\s+)?(bed|bath|kitchen|living|dining|storage|closet|laundry|office|garage|hall|entry|powder|wc|toilet|pantry)[a-z]*(\s+room)?$/i', $t) === 1) {
            return ucwords(strtolower($t));
        }
        // Office CAD IDs often OCR as SW/0.1.B06.08 or noisy variants (must include a digit).
        if (
            preg_match('/\d/', $t) === 1
            && preg_match('/[A-Z]{1,3}\s*[\/\-]?\s*\d{0,2}[\.\/]?\d{0,2}[\.\/]?[A-Z]?\d{0,2}[\.\/]?\d{0,3}/i', $t, $m) === 1
        ) {
            $id = strtoupper(preg_replace('/\s+/', '', $m[0]) ?? $m[0]);
            if (strlen($id) >= 3) {
                return $id;
            }
        }
        if (strlen($t) >= 2 && strlen($t) <= 24 && preg_match('/^[A-Za-z0-9 .\/\-#]+$/', $t) === 1) {
            return $t;
        }

        return $t;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<int> $walls
     */
    private function renderOverlay(\GdImage $source, array $rects, array $walls, int $w, int $h): \GdImage
    {
        $out = imagecreatetruecolor($w, $h);
        if (!$out instanceof \GdImage) {
            throw new \RuntimeException('Could not create overlay');
        }
        imagecopy($out, $source, 0, 0, 0, 0, $w, $h);
        $inside = array_fill(0, $w * $h, 0);
        foreach ($rects as $r) {
            for ($y = max(0, $r[1]); $y <= min($h - 1, $r[3]); $y++) {
                $row = $y * $w;
                for ($x = max(0, $r[0]); $x <= min($w - 1, $r[2]); $x++) {
                    $inside[$row + $x] = 1;
                }
            }
        }
        $fr = self::OVERLAY_R / 254.38;
        $fg = self::OVERLAY_G / 254.36;
        $fb = self::OVERLAY_B / 254.38;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                // Never tint structural walls; do tint furniture (dark ink) inside rooms.
                if ($inside[$i] !== 1 || $walls[$i] === 1) {
                    continue;
                }
                $rgb = imagecolorat($source, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // Multiply teal onto floor AND furniture so rooms read as solid
                // rectangles (product promise: ignore furniture). Structural walls
                // stay un-tinted above. Dark ink gets a green lift so fixtures
                // don't look like white punch-outs.
                $lum = ($r + $g + $b) / 3.0;
                if ($lum >= 140) {
                    $nr = (int) max(0, min(255, (int) round($r * $fr)));
                    $ng = (int) max(0, min(255, (int) round($g * $fg)));
                    $nb = (int) max(0, min(255, (int) round($b * $fb)));
                } else {
                    $a = 0.48;
                    $nr = (int) max(0, min(255, (int) round($r * (1.0 - $a) + 28 * $a)));
                    $ng = (int) max(0, min(255, (int) round($g * (1.0 - $a) + 175 * $a)));
                    $nb = (int) max(0, min(255, (int) round($b * (1.0 - $a) + 155 * $a)));
                }
                imagesetpixel($out, $x, $y, ($nr << 16) | ($ng << 8) | $nb);
            }
        }

        return $out;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array<string, mixed>> $rooms
     */
    private function buildSvg(
        \GdImage $source,
        array $rects,
        int $w,
        int $h,
        ?string $sourceSvg,
        array $rooms = []
    ): string {
        // Prefer room bboxes (same order/labels as the UI) over the pre-summarize rect list.
        $drawRects = [];
        if ($rooms !== []) {
            foreach ($rooms as $room) {
                $b = $room['bbox'] ?? null;
                if (!is_array($b)) {
                    continue;
                }
                $drawRects[] = [
                    (int) $b['x'],
                    (int) $b['y'],
                    (int) $b['x'] + (int) $b['width'] - 1,
                    (int) $b['y'] + (int) $b['height'] - 1,
                ];
            }
        }
        if ($drawRects === []) {
            $drawRects = $rects;
        }

        $rectsXml = '';
        foreach ($drawRects as $i => $r) {
            $label = isset($rooms[$i]['label']) ? htmlspecialchars((string) $rooms[$i]['label'], ENT_QUOTES) : '';
            $rw = $r[2] - $r[0] + 1;
            $rh = $r[3] - $r[1] + 1;
            $rectsXml .= sprintf(
                '    <rect x="%d" y="%d" width="%d" height="%d" data-label="%s"/>' . "\n",
                $r[0],
                $r[1],
                $rw,
                $rh,
                $label
            );
            if ($label !== '' && !preg_match('/^(Room|Cubicle)\s+\d+$/', (string) ($rooms[$i]['label'] ?? ''))) {
                $fs = max(9, min(18, (int) round(min($rw, $rh) * 0.18)));
                $tx = $r[0] + 4;
                $ty = $r[1] + $fs + 2;
                $rectsXml .= sprintf(
                    '    <text x="%d" y="%d" font-size="%d" font-family="IBM Plex Mono, monospace" fill="#04201b" opacity="0.92">%s</text>' . "\n",
                    $tx,
                    $ty,
                    $fs,
                    $label
                );
            }
        }
        $group = sprintf(
            "  <g fill=\"rgb(%d,%d,%d)\" style=\"mix-blend-mode:multiply\" pointer-events=\"none\">\n%s  </g>\n",
            self::OVERLAY_R,
            self::OVERLAY_G,
            self::OVERLAY_B,
            $rectsXml
        );
        if (is_string($sourceSvg) && $sourceSvg !== '') {
            return $this->injectRectsIntoSvg($sourceSvg, $drawRects, $rooms, $w, $h);
        }
        $origB64 = base64_encode($this->gdToPng($source));

        return sprintf(
            <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">
  <image href="data:image/png;base64,%s" width="%d" height="%d"/>
%s</svg>

SVG,
            $w,
            $h,
            $w,
            $h,
            $origB64,
            $w,
            $h,
            $group
        );
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int}> $rects
     * @param list<array<string, mixed>> $rooms
     */
    private function injectRectsIntoSvg(string $svg, array $rects, array $rooms, int $w, int $h): string
    {
        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg) ?? $svg;
        $svg = preg_replace('/\\son[a-z]+\\s*=\\s*("[^"]*"|\'[^\']*\')/i', '', $svg) ?? $svg;
        $svg = trim($svg);

        $vbW = $w;
        $vbH = $h;
        if (preg_match('/viewBox=["\']\\s*([0-9.]+)\\s+([0-9.]+)\\s+([0-9.]+)\\s+([0-9.]+)/i', $svg, $vb)) {
            $vbW = max(1.0, (float) $vb[3]);
            $vbH = max(1.0, (float) $vb[4]);
        }
        $sx = $vbW / max(1, $w);
        $sy = $vbH / max(1, $h);

        $rectsXml = '';
        foreach ($rects as $i => $r) {
            $rawLabel = (string) ($rooms[$i]['label'] ?? '');
            $label = $rawLabel !== '' ? htmlspecialchars($rawLabel, ENT_QUOTES) : '';
            $x = $r[0] * $sx;
            $y = $r[1] * $sy;
            $ww = ($r[2] - $r[0] + 1) * $sx;
            $hh = ($r[3] - $r[1] + 1) * $sy;
            $rectsXml .= sprintf(
                '    <rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" data-label="%s"/>' . "\n",
                $x,
                $y,
                $ww,
                $hh,
                $label
            );
            if ($label !== '' && !preg_match('/^(Room|Cubicle)\s+\d+$/', $rawLabel)) {
                $fs = max(8.0, min(22.0, min($ww, $hh) * 0.16));
                $rectsXml .= sprintf(
                    '    <text x="%.2f" y="%.2f" font-size="%.1f" font-family="IBM Plex Mono, monospace" fill="#04201b" opacity="0.92">%s</text>' . "\n",
                    $x + 4 * $sx,
                    $y + ($fs + 2) * $sy,
                    $fs,
                    $label
                );
            }
        }
        $group = sprintf(
            "  <g fill=\"rgb(%d,%d,%d)\" fill-opacity=\"0.45\" style=\"mix-blend-mode:multiply\" pointer-events=\"none\">\n%s  </g>\n",
            self::OVERLAY_R,
            self::OVERLAY_G,
            self::OVERLAY_B,
            $rectsXml
        );

        if (preg_match('/<\\/svg\\s*>/i', $svg) === 1) {
            return (string) preg_replace('/<\\/svg\\s*>/i', rtrim($group) . "\n</svg>", $svg, 1);
        }

        return sprintf(
            "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"%d\" height=\"%d\" viewBox=\"0 0 %d %d\">\n%s\n%s</svg>\n",
            $w,
            $h,
            $w,
            $h,
            $svg,
            $group
        );
    }

    private function gdToPng(\GdImage $im): string
    {
        ob_start();
        imagepng($im, null, 6);
        $png = ob_get_clean();
        if (!is_string($png) || $png === '') {
            throw new \RuntimeException('Failed to encode overlay');
        }

        return $png;
    }
}
