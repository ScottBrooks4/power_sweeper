<?php

declare(strict_types=1);

use PowerSweeper\FloorScanner\DetectionScore;
use PowerSweeper\FloorScanner\ElementLayers;
use PowerSweeper\FloorScanner\FloorplanImage;
use PowerSweeper\FloorScanner\GroundTruth;
use PowerSweeper\FloorScanner\RoomDetector;
use PowerSweeper\FloorScanner\TimingModel;

function fs_draw_wall(\GdImage $im, int $x0, int $y0, int $x1, int $y1, int $color): void
{
    imagefilledrectangle($im, $x0, $y0, $x1, $y1, $color);
}

function fs_mean_abs_diff(\GdImage $a, \GdImage $b): float
{
    $w = imagesx($a);
    $h = imagesy($a);
    if ($w !== imagesx($b) || $h !== imagesy($b)) {
        return 999.0;
    }
    $sum = 0.0;
    $n = 0;
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $pa = imagecolorat($a, $x, $y);
            $pb = imagecolorat($b, $x, $y);
            $sum += abs((($pa >> 16) & 255) - (($pb >> 16) & 255));
            $sum += abs((($pa >> 8) & 255) - (($pb >> 8) & 255));
            $sum += abs(($pa & 255) - ($pb & 255));
            $n += 3;
        }
    }
    return $n > 0 ? $sum / $n : 999.0;
}

{
    $detector = new RoomDetector();

    $im = imagecreatetruecolor(260, 160);
    $white = imagecolorallocate($im, 248, 248, 248);
    $wall = imagecolorallocate($im, 136, 136, 136);
    imagefilledrectangle($im, 0, 0, 259, 159, $white);
    fs_draw_wall($im, 20, 20, 239, 27, $wall);
    fs_draw_wall($im, 20, 132, 239, 139, $wall);
    fs_draw_wall($im, 20, 20, 27, 139, $wall);
    fs_draw_wall($im, 232, 20, 239, 139, $wall);
    fs_draw_wall($im, 126, 20, 133, 84, $wall);
    fs_draw_wall($im, 126, 94, 133, 139, $wall);

    $tmp = sys_get_temp_dir() . '/fs_two_room_' . bin2hex(random_bytes(3)) . '.png';
    imagepng($im, $tmp);
    imagedestroy($im);

    $result = $detector->detectFile($tmp);
    @unlink($tmp);
    assert_true(count($result['rooms']) >= 2, 'synthetic floorplan finds at least two spaces');
    assert_true(str_starts_with($result['overlay_png'], "\x89PNG"), 'overlay PNG is encoded');
    assert_true(str_contains($result['overlay_svg'], '<svg'), 'overlay is SVG');
    assert_true(str_contains($result['overlay_svg'], '<rect'), 'SVG contains rectangular rooms');
    assert_true(str_contains($result['overlay_svg'], 'mix-blend-mode:multiply'), 'SVG rectangles paint the fill');
    foreach ($result['rooms'] as $room) {
        assert_true(($room['bbox']['width'] ?? 0) > 0 && ($room['bbox']['height'] ?? 0) > 0, 'rooms are rectangles');
    }
}

{
    $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="260" height="160">
  <rect width="260" height="160" fill="#f8f8f8"/>
  <rect x="20" y="20" width="220" height="8" fill="#888888"/>
  <rect x="20" y="132" width="220" height="8" fill="#888888"/>
  <rect x="20" y="20" width="8" height="120" fill="#888888"/>
  <rect x="232" y="20" width="8" height="120" fill="#888888"/>
  <rect x="126" y="20" width="8" height="64" fill="#888888"/>
  <rect x="126" y="94" width="8" height="46" fill="#888888"/>
</svg>
SVG;
    assert_true(FloorplanImage::looksLikeSvg($svg, 'plan.svg'), 'SVG is detected');
    $loaded = FloorplanImage::loadBlob($svg, 'plan.svg');
    assert_true($loaded['image'] instanceof GdImage, 'SVG rasterizes');
    imagedestroy($loaded['image']);
    $detector = new RoomDetector();
    $result = $detector->detectBlob($svg, 'plan.svg');
    assert_true(count($result['rooms']) >= 2, 'SVG floorplan finds at least two spaces');
    assert_true(str_contains($result['overlay_svg'], '<rect'), 'SVG result keeps room rectangles');
    assert_true(str_contains($result['overlay_svg'], 'mix-blend-mode:multiply'), 'SVG input keeps rectangular fills');
    assert_true(str_contains($result['overlay_svg'], 'fill="#888888"'), 'SVG input geometry is preserved in the result');
}

{
    $sample = __DIR__ . '/fixtures/sample_floorplan.png';
    $afterPath = __DIR__ . '/fixtures/sample_floorplan_after.png';
    assert_true(is_file($sample), 'sample floorplan fixture exists');
    $detector = new RoomDetector();
    $result = $detector->detectFile($sample);
    assert_true($result['width'] === 676 && $result['height'] === 676, 'sample floorplan size');
    assert_true(count($result['rooms']) >= 5, 'sample floorplan finds at least five spaces');
    assert_true(str_contains($result['overlay_svg'], '<svg'), 'sample overlay is SVG');
    $overlay = @imagecreatefromstring($result['overlay_png']);
    assert_true($overlay instanceof GdImage, 'sample overlay decodes');
    if ($overlay instanceof GdImage && is_file($afterPath)) {
        $target = imagecreatefrompng($afterPath);
        if ($target instanceof GdImage) {
            $mae = fs_mean_abs_diff($overlay, $target);
            assert_true($mae < 18.0, 'sample overlay is within pixels of the provided after (mae ' . sprintf('%.2f', $mae) . ')');
            imagedestroy($target);
        }
        imagedestroy($overlay);
    }
    foreach ($result['rooms'] as $room) {
        assert_true(isset($room['bbox']['width'], $room['label'], $room['area_pct']), 'room payload is complete');
    }
}

{
    $little = __DIR__ . '/fixtures/floor_scanner/little_empty.png';
    assert_true(is_file($little), 'little floorplan fixture exists');
    $detector = new RoomDetector();
    $result = $detector->detectFile($little);
    assert_true(count($result['rooms']) === 7, 'little floorplan finds exactly seven rooms (got ' . count($result['rooms']) . ')');
    // Hallway should not dominate: no single room covering most of the open-plan core.
    $coreHits = 0;
    foreach ($result['rooms'] as $room) {
        $b = $room['bbox'];
        $cx = $b['x'] + intdiv($b['width'], 2);
        $cy = $b['y'] + intdiv($b['height'], 2);
        if ($cx > 300 && $cx < 420 && $cy > 300 && $cy < 380) {
            $coreHits++;
        }
    }
    assert_true($coreHits === 0, 'little floorplan does not treat the central hallway as a room');
    foreach ($result['rooms'] as $room) {
        assert_true($room['bbox']['width'] >= 70 && $room['bbox']['height'] >= 60, 'little rooms are not furniture-sized');
    }
}

{
    $littleSvg = __DIR__ . '/fixtures/floor_scanner/little_empty.svg';
    assert_true(is_file($littleSvg), 'little floorplan SVG fixture exists');
    $detector = new RoomDetector();
    $result = $detector->detectFile($littleSvg);
    assert_true(
        count($result['rooms']) === 7,
        'little SVG finds exactly seven rooms (got ' . count($result['rooms']) . ')'
    );
    assert_true($result['width'] <= 700 && $result['height'] <= 700, 'little SVG uses CSS display size not embed oversample');
}

{
    $gt = __DIR__ . '/fixtures/floor_scanner/bldg5_filled_rects.json';
    assert_true(is_file($gt), 'building 5 filled overlay fixture exists');
    $data = json_decode((string) file_get_contents($gt), true);
    assert_true(is_array($data) && (int) ($data['count'] ?? 0) === 128, 'filled building 5 marks 128 spaces (got ' . (int) ($data['count'] ?? 0) . ')');
    assert_true(isset($data['spaces'][0]['label']) && $data['spaces'][0]['label'] !== '', 'filled building 5 spaces carry labels');
    $emptySvg = '/home/lulu/Downloads/Bldg 5W - North - 1st floor - Empty.svg';
    if (is_file($emptySvg)) {
        $probe = FloorplanImage::probeWorkSize((string) file_get_contents($emptySvg), 'bldg5.svg');
        assert_true($probe['width'] >= 1400, 'building 5 work size stays usable (got ' . $probe['width'] . ')');
    }
}

// Detection metrics may only improve. Baseline is refreshed deliberately with
// `php scripts/floor_scanner_bench.php --full --write`, never silently.
{
    $baselinePath = __DIR__ . '/fixtures/floor_scanner/baseline_metrics.json';
    assert_true(is_file($baselinePath), 'floor scanner baseline metrics exist');
    $baseline = json_decode((string) file_get_contents($baselinePath), true);
    $cases = is_array($baseline) ? ($baseline['cases'] ?? []) : [];
    assert_true(is_array($cases) && $cases !== [], 'baseline records at least one case');

    $gtData = json_decode(
        (string) file_get_contents(__DIR__ . '/fixtures/floor_scanner/bldg5_filled_rects.json'),
        true
    );

    $score = static function (string $path) use ($gtData): array {
        $result = (new RoomDetector())->detectFile($path);
        $preds = DetectionScore::predsFromRooms($result['rooms']);
        $rects = DetectionScore::rectsFromGtDocument($gtData);
        $gts = DetectionScore::scaleGroundTruth(
            $rects,
            $gtData['viewBox'],
            (int) $result['width'],
            (int) $result['height']
        );

        return DetectionScore::evaluate($preds, $gts) + ['total' => count($result['rooms'])];
    };

    // Raster office plan (corpus holdout). Prefer the 2000px render variant —
    // that is a stored DPI asset, not a runtime upscale of the awkward 886px PNG.
    $rasterBase = $cases['bldg5_north_1f_raster'] ?? $cases['bldg5_png'] ?? null;
    $rasterPath = __DIR__ . '/fixtures/floor_scanner/corpus/bldg5_north_1f_raster/empty_2000.png';
    if (!is_file($rasterPath)) {
        $rasterPath = __DIR__ . '/fixtures/floor_scanner/corpus/bldg5_north_1f_raster/empty.png';
    }
    if (!is_file($rasterPath)) {
        $rasterPath = __DIR__ . '/fixtures/floor_scanner/bldg5_empty.png';
    }
    if (is_array($rasterBase) && is_file($rasterPath)) {
        $got = $score($rasterPath);
        assert_true(
            $got['recall25'] >= (int) $rasterBase['recall25'],
            'bldg5 raster recall@0.25 does not regress (baseline ' . $rasterBase['recall25'] . ', got ' . $got['recall25'] . ')'
        );
        assert_true(
            $got['empty_cell_centers'] <= (int) $rasterBase['empty_cell_centers'],
            'bldg5 raster leaves no more empty cells (baseline ' . $rasterBase['empty_cell_centers'] . ', got ' . $got['empty_cell_centers'] . ')'
        );
    }

    // The 5907x2824 office SVG costs ~80s, so it is opt-in for the suite.
    $emptySvg = __DIR__ . '/fixtures/floor_scanner/corpus/bldg5_north_1f/empty.svg';
    $svgBase = $cases['bldg5_north_1f'] ?? $cases['bldg5_svg'] ?? null;
    if (getenv('FLOORSCANNER_FULL') === '1' && is_array($svgBase) && is_file($emptySvg)) {
        $got = $score($emptySvg);
        assert_true(
            $got['recall25'] >= (int) $svgBase['recall25'] - 1,
            'bldg5 SVG recall@0.25 holds (baseline ' . $svgBase['recall25'] . ', got ' . $got['recall25'] . ')'
        );
        assert_true(
            $got['total'] <= (int) $svgBase['total'] + 5,
            'bldg5 SVG prediction count does not balloon (baseline ' . $svgBase['total'] . ', got ' . $got['total'] . ')'
        );
    }
}

{
    $filled = __DIR__ . '/fixtures/floor_scanner/corpus/bldg5_north_1f/filled.svg';
    assert_true(is_file($filled), 'corpus bldg5 filled SVG exists');
    $extracted = GroundTruth::extractFromFilledSvg((string) file_get_contents($filled));
    assert_true($extracted['count'] === 128, 'GroundTruth extracts 128 spaces');
    assert_true(GroundTruth::normalizeLabel('_5W.O.1.D04.27') === 'D04.27', 'label normalisation keeps trailing code');
    $layers = ElementLayers::fromFile(__DIR__ . '/../scripts/recognition/element_layers.schema.example.json');
    assert_true($layers !== null && $layers->hasFurniture(), 'ElementLayers loads schema example');
    assert_true(count($layers->cubicleEvidenceBoxes(40, 30)) >= 1, 'desk+chair evidence boxes form');
}

{
    $tmpRoot = sys_get_temp_dir() . '/fs_timing_' . bin2hex(random_bytes(3));
    mkdir($tmpRoot . '/config', 0777, true);
    mkdir($tmpRoot . '/storage/benchmarks', 0777, true);
    $seed = TimingModel::seedModel();
    file_put_contents($tmpRoot . '/config/floorscanner_timing.json', json_encode($seed));

    $ms = TimingModel::predictMs(676, 676, 150000, TimingModel::load($tmpRoot));
    assert_true($ms >= 3000 && $ms <= 30000, 'timing model predicts a stable little-plan ETA');

    $recorded = TimingModel::recordSample(1200, 900, 9500, 400000, $tmpRoot);
    assert_true($recorded['ok'] === true, 'timing model records a sample');
    $again = TimingModel::predictMs(1200, 900, 400000, TimingModel::load($tmpRoot));
    assert_true($again >= 3000, 'refit still predicts a positive ETA');
    // Cleanup best-effort
    @unlink($tmpRoot . '/config/floorscanner_timing.json');
    @unlink($tmpRoot . '/storage/benchmarks/floorscanner_timing.json');
    @unlink($tmpRoot . '/storage/benchmarks/floorscanner_runs.jsonl');
    @rmdir($tmpRoot . '/config');
    @rmdir($tmpRoot . '/storage/benchmarks');
    @rmdir($tmpRoot . '/storage');
    @rmdir($tmpRoot);
}
