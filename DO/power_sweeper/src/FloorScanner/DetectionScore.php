<?php

declare(strict_types=1);

namespace PowerSweeper\FloorScanner;

/**
 * Honest scoring for floor scanner detections.
 *
 * Matching is greedy and unique: one prediction can satisfy at most one ground
 * truth space, so flooding the plan with rectangles cannot inflate recall.
 */
final class DetectionScore
{
    /** A ground truth space at or below this area (in source units) is a module cell. */
    private const CELL_AREA = 40000;

    /**
     * @param list<array{bbox: array{x: int, y: int, width: int, height: int}, label?: string, type?: string}|array<string, mixed>> $rooms
     * @return list<array{box: array{0:int,1:int,2:int,3:int}, label: string, type: string}>
     */
    public static function predsFromRooms(array $rooms): array
    {
        $preds = [];
        foreach ($rooms as $room) {
            $b = $room['bbox'] ?? null;
            if (!is_array($b)) {
                continue;
            }
            $x = (int) ($b['x'] ?? 0);
            $y = (int) ($b['y'] ?? 0);
            $bw = (int) ($b['width'] ?? 0);
            $bh = (int) ($b['height'] ?? 0);
            if ($bw <= 0 || $bh <= 0) {
                continue;
            }
            $preds[] = [
                'box' => [$x, $y, $x + $bw - 1, $y + $bh - 1],
                'label' => (string) ($room['label'] ?? ''),
                'type' => (string) ($room['type'] ?? ''),
            ];
        }

        return $preds;
    }

    /**
     * Scale ground truth rectangles (x/y/w/h in viewBox units) into detection space.
     *
     * Accepts either the legacy `rects` list or the richer `spaces` list.
     *
     * @param list<array{x: float, y: float, w: float, h: float, label?: string, type?: string}> $rects
     * @param array{0: float, 1: float, 2: float, 3: float} $viewBox
     * @return list<array{box: array{0:int,1:int,2:int,3:int}, cell: bool, label: string, type: string}>
     */
    public static function scaleGroundTruth(array $rects, array $viewBox, int $w, int $h): array
    {
        $vw = (float) ($viewBox[2] ?? 0);
        $vh = (float) ($viewBox[3] ?? 0);
        if ($vw <= 0.0 || $vh <= 0.0) {
            return [];
        }
        $sx = $w / $vw;
        $sy = $h / $vh;
        $out = [];
        foreach ($rects as $r) {
            $rw = (float) $r['w'];
            $rh = (float) $r['h'];
            $label = (string) ($r['label'] ?? '');
            $type = (string) ($r['type'] ?? '');
            if ($type === '') {
                $type = ($rw * $rh) <= self::CELL_AREA ? 'cubicle' : 'room';
            }
            $out[] = [
                'box' => [
                    (int) floor((float) $r['x'] * $sx),
                    (int) floor((float) $r['y'] * $sy),
                    (int) floor(((float) $r['x'] + $rw - 1) * $sx),
                    (int) floor(((float) $r['y'] + $rh - 1) * $sy),
                ],
                'cell' => ($rw * $rh) <= self::CELL_AREA,
                'label' => $label,
                'type' => $type,
            ];
        }

        return $out;
    }

    /**
     * Prefer `spaces` when present; fall back to `rects`.
     *
     * @param array<string, mixed> $gt
     * @return list<array{x: float, y: float, w: float, h: float, label?: string, type?: string}>
     */
    public static function rectsFromGtDocument(array $gt): array
    {
        if (isset($gt['spaces']) && is_array($gt['spaces']) && $gt['spaces'] !== []) {
            return $gt['spaces'];
        }
        if (isset($gt['rects']) && is_array($gt['rects'])) {
            return $gt['rects'];
        }

        return [];
    }

    /**
     * @param array{0:int,1:int,2:int,3:int} $a
     * @param array{0:int,1:int,2:int,3:int} $b
     */
    public static function iou(array $a, array $b): float
    {
        $x0 = max($a[0], $b[0]);
        $y0 = max($a[1], $b[1]);
        $x1 = min($a[2], $b[2]);
        $y1 = min($a[3], $b[3]);
        if ($x0 > $x1 || $y0 > $y1) {
            return 0.0;
        }
        $inter = ($x1 - $x0 + 1) * ($y1 - $y0 + 1);
        $areaA = ($a[2] - $a[0] + 1) * ($a[3] - $a[1] + 1);
        $areaB = ($b[2] - $b[0] + 1) * ($b[3] - $b[1] + 1);

        return $inter / max(1, $areaA + $areaB - $inter);
    }

    /**
     * @param list<array{box: array{0:int,1:int,2:int,3:int}, label?: string, type?: string}|array{0:int,1:int,2:int,3:int}> $preds
     * @return list<array{box: array{0:int,1:int,2:int,3:int}, label: string, type: string}>
     */
    private static function normalizePreds(array $preds): array
    {
        $out = [];
        foreach ($preds as $p) {
            if (isset($p['box']) && is_array($p['box'])) {
                $out[] = [
                    'box' => $p['box'],
                    'label' => (string) ($p['label'] ?? ''),
                    'type' => (string) ($p['type'] ?? ''),
                ];
            } elseif (isset($p[0], $p[1], $p[2], $p[3])) {
                $out[] = [
                    'box' => [(int) $p[0], (int) $p[1], (int) $p[2], (int) $p[3]],
                    'label' => '',
                    'type' => '',
                ];
            }
        }

        return $out;
    }

    /**
     * Greedy unique matching at one IoU threshold.
     *
     * @param list<array{box: array{0:int,1:int,2:int,3:int}, label: string, type: string}> $preds
     * @param list<array{box: array{0:int,1:int,2:int,3:int}, cell: bool, label?: string, type?: string}> $gts
     * @return array{
     *   hits: int,
     *   cellHits: int,
     *   ious: list<float>,
     *   best: list<float>,
     *   pairs: list<array{gi: int, pi: int, iou: float}>
     * }
     */
    private static function match(array $preds, array $gts, float $threshold): array
    {
        $used = [];
        $hits = 0;
        $cellHits = 0;
        $ious = [];
        $best = [];
        $pairs = [];
        foreach ($gts as $gi => $gt) {
            $bestIou = 0.0;
            $bestIdx = -1;
            foreach ($preds as $pi => $p) {
                if (isset($used[$pi])) {
                    continue;
                }
                $v = self::iou($gt['box'], $p['box']);
                if ($v > $bestIou) {
                    $bestIou = $v;
                    $bestIdx = $pi;
                }
            }
            $best[] = $bestIou;
            if ($bestIdx >= 0 && $bestIou >= $threshold) {
                $used[$bestIdx] = true;
                $hits++;
                $ious[] = $bestIou;
                $pairs[] = ['gi' => $gi, 'pi' => $bestIdx, 'iou' => $bestIou];
                if ($gt['cell']) {
                    $cellHits++;
                }
            }
        }

        return [
            'hits' => $hits,
            'cellHits' => $cellHits,
            'ious' => $ious,
            'best' => $best,
            'pairs' => $pairs,
        ];
    }

    /**
     * @param list<array{box: array{0:int,1:int,2:int,3:int}, label?: string, type?: string}|array{0:int,1:int,2:int,3:int}> $preds
     * @param list<array{box: array{0:int,1:int,2:int,3:int}, cell: bool, label?: string, type?: string}> $gts
     * @return array<string, float|int>
     */
    public static function evaluate(array $preds, array $gts): array
    {
        $preds = self::normalizePreds($preds);
        $total = count($gts);
        $cells = 0;
        foreach ($gts as $gt) {
            if ($gt['cell']) {
                $cells++;
            }
        }
        $loose = self::match($preds, $gts, 0.25);
        $tight = self::match($preds, $gts, 0.40);

        $fp = 0;
        foreach ($preds as $p) {
            $bestIou = 0.0;
            foreach ($gts as $gt) {
                $bestIou = max($bestIou, self::iou($gt['box'], $p['box']));
            }
            if ($bestIou < 0.15) {
                $fp++;
            }
        }

        $emptyCells = 0;
        foreach ($gts as $gt) {
            if (!$gt['cell']) {
                continue;
            }
            [$gx0, $gy0, $gx1, $gy1] = $gt['box'];
            $cx = intdiv($gx0 + $gx1, 2);
            $cy = intdiv($gy0 + $gy1, 2);
            $covered = false;
            foreach ($preds as $p) {
                $b = $p['box'];
                if ($cx >= $b[0] + 4 && $cx <= $b[2] - 4 && $cy >= $b[1] + 4 && $cy <= $b[3] - 4) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $emptyCells++;
            }
        }

        $nPreds = count($preds);
        $meanIou = $loose['ious'] === [] ? 0.0 : array_sum($loose['ious']) / count($loose['ious']);

        // Label accuracy over matched pairs that have a non-empty GT label.
        $labelExact = 0;
        $labelNorm = 0;
        $labelScored = 0;
        $typeCorrect = 0;
        $typeScored = 0;
        $typeConfusion = [
            'room_as_cubicle' => 0,
            'cubicle_as_room' => 0,
        ];
        foreach ($loose['pairs'] as $pair) {
            $gt = $gts[$pair['gi']];
            $pred = $preds[$pair['pi']];
            $gtLabel = (string) ($gt['label'] ?? '');
            $predLabel = (string) ($pred['label'] ?? '');
            if ($gtLabel !== '') {
                $labelScored++;
                if ($predLabel === $gtLabel) {
                    $labelExact++;
                }
                if (GroundTruth::normalizeLabel($predLabel) === GroundTruth::normalizeLabel($gtLabel)
                    && GroundTruth::normalizeLabel($gtLabel) !== ''
                ) {
                    $labelNorm++;
                }
            }
            $gtType = (string) ($gt['type'] ?? ($gt['cell'] ? 'cubicle' : 'room'));
            $predType = (string) ($pred['type'] ?? '');
            if ($gtType !== '' && $predType !== '') {
                $typeScored++;
                if ($predType === $gtType) {
                    $typeCorrect++;
                } elseif ($gtType === 'room' && $predType === 'cubicle') {
                    $typeConfusion['room_as_cubicle']++;
                } elseif ($gtType === 'cubicle' && $predType === 'room') {
                    $typeConfusion['cubicle_as_room']++;
                }
            }
        }

        return [
            'gt' => $total,
            'gt_cells' => $cells,
            'preds' => $nPreds,
            'recall25' => $loose['hits'],
            'recall40' => $tight['hits'],
            'cell_recall25' => $loose['cellHits'],
            'precision' => $nPreds > 0 ? round($loose['hits'] / $nPreds, 3) : 0.0,
            'false_positives' => $fp,
            'mean_iou' => round($meanIou, 3),
            'empty_cell_centers' => $emptyCells,
            'label_scored' => $labelScored,
            'label_exact' => $labelExact,
            'label_norm' => $labelNorm,
            'label_exact_acc' => $labelScored > 0 ? round($labelExact / $labelScored, 3) : 0.0,
            'label_norm_acc' => $labelScored > 0 ? round($labelNorm / $labelScored, 3) : 0.0,
            'type_scored' => $typeScored,
            'type_correct' => $typeCorrect,
            'type_acc' => $typeScored > 0 ? round($typeCorrect / $typeScored, 3) : 0.0,
            'type_room_as_cubicle' => $typeConfusion['room_as_cubicle'],
            'type_cubicle_as_room' => $typeConfusion['cubicle_as_room'],
        ];
    }

    /**
     * Best IoU per ground truth index, for probing individual spaces.
     *
     * @param list<array{box: array{0:int,1:int,2:int,3:int}, label?: string, type?: string}|array{0:int,1:int,2:int,3:int}> $preds
     * @param list<array{box: array{0:int,1:int,2:int,3:int}, cell: bool, label?: string, type?: string}> $gts
     * @param list<int> $indexes
     * @return array<int, array{iou: float, pred: ?array{0:int,1:int,2:int,3:int}, label?: string}>
     */
    public static function probe(array $preds, array $gts, array $indexes): array
    {
        $preds = self::normalizePreds($preds);
        $out = [];
        foreach ($indexes as $gi) {
            if (!isset($gts[$gi])) {
                continue;
            }
            $bestIou = 0.0;
            $bestPred = null;
            $bestLabel = '';
            foreach ($preds as $p) {
                $v = self::iou($gts[$gi]['box'], $p['box']);
                if ($v > $bestIou) {
                    $bestIou = $v;
                    $bestPred = $p['box'];
                    $bestLabel = $p['label'];
                }
            }
            $out[$gi] = [
                'iou' => round($bestIou, 3),
                'pred' => $bestPred,
                'label' => $bestLabel,
                'gt_label' => (string) ($gts[$gi]['label'] ?? ''),
            ];
        }

        return $out;
    }
}
