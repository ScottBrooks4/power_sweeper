<?php

declare(strict_types=1);

namespace PowerSweeper\FloorScanner;

/**
 * Element layers from the recognition sidecar (furniture, doors, stairs, …).
 *
 * Consumed by RoomDetector to subtract furniture from growth masks and to
 * treat desk+chair clusters as cubicle evidence.
 *
 * @phpstan-type BBox array{x:int,y:int,w:int,h:int}
 * @phpstan-type LayerItem array{class:string,bbox:BBox,confidence?:float,source?:string}
 */
final class ElementLayers
{
    public const SCHEMA_VERSION = 1;

    /** Classes treated as furniture hard-negatives for wall growth. */
    public const FURNITURE_CLASSES = [
        'chair', 'table', 'desk', 'sofa', 'bed', 'sink', 'toilet', 'bathtub',
        'bath', 'bath_tub', 'wardrobe', 'cabinet', 'tv_cabinet', 'refrigerator',
        'gas_stove', 'washing_machine', 'urinal', 'squat_toilet',
    ];

    /** Circulation — never rooms. */
    public const CIRCULATION_CLASSES = [
        'stair', 'elevator', 'escalator', 'parking',
    ];

    public const DOOR_CLASSES = [
        'door', 'single_door', 'double_door', 'sliding_door', 'door2',
    ];

    /**
     * @param array<string, mixed> $json
     */
    public static function fromArray(array $json): self
    {
        return new self(
            self::items($json['furniture'] ?? []),
            self::items($json['doors'] ?? []),
            self::items($json['stairs'] ?? []),
            self::items($json['walls'] ?? []),
            self::items($json['labels'] ?? []),
            (string) ($json['source'] ?? 'unknown'),
            (int) ($json['width'] ?? 0),
            (int) ($json['height'] ?? 0),
        );
    }

    public static function fromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? self::fromArray($data) : null;
    }

    /**
     * Empty layers document (schema contract for the sidecar).
     *
     * @return array<string, mixed>
     */
    public static function emptyDocument(int $width = 0, int $height = 0): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'width' => $width,
            'height' => $height,
            'source' => 'none',
            'walls' => [],
            'doors' => [],
            'stairs' => [],
            'furniture' => [],
            'labels' => [],
        ];
    }

    /**
     * @param list<LayerItem> $furniture
     * @param list<LayerItem> $doors
     * @param list<LayerItem> $stairs
     * @param list<LayerItem> $walls
     * @param list<LayerItem> $labels
     */
    public function __construct(
        public readonly array $furniture = [],
        public readonly array $doors = [],
        public readonly array $stairs = [],
        public readonly array $walls = [],
        public readonly array $labels = [],
        public readonly string $source = 'unknown',
        public readonly int $width = 0,
        public readonly int $height = 0,
    ) {
    }

    public function hasFurniture(): bool
    {
        return $this->furniture !== [];
    }

    /**
     * Paint furniture (and optional circulation) into a binary mask aligned to w×h.
     *
     * @param list<int> $base  existing wall mask (copied)
     * @return list<int> mask where 1 = forbidden for room growth
     */
    public function furnitureNegativeMask(array $base, int $w, int $h, float $minConf = 0.25): array
    {
        $mask = $base;
        $sx = ($this->width > 0 && $this->width !== $w) ? ($w / $this->width) : 1.0;
        $sy = ($this->height > 0 && $this->height !== $h) ? ($h / $this->height) : 1.0;

        foreach (array_merge($this->furniture, $this->stairs) as $item) {
            $conf = (float) ($item['confidence'] ?? 1.0);
            if ($conf < $minConf) {
                continue;
            }
            $b = $item['bbox'];
            $x0 = max(0, (int) floor($b['x'] * $sx));
            $y0 = max(0, (int) floor($b['y'] * $sy));
            $x1 = min($w - 1, (int) floor(($b['x'] + $b['w'] - 1) * $sx));
            $y1 = min($h - 1, (int) floor(($b['y'] + $b['h'] - 1) * $sy));
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
     * Desk+chair pairs whose centres lie within a module-sized window —
     * positive evidence for a cubicle cell.
     *
     * @return list<array{0:int,1:int,2:int,3:int}>
     */
    public function cubicleEvidenceBoxes(int $moduleW, int $moduleH): array
    {
        $chairs = [];
        $desks = [];
        foreach ($this->furniture as $item) {
            $class = strtolower((string) ($item['class'] ?? ''));
            $b = $item['bbox'];
            $box = [$b['x'], $b['y'], $b['x'] + $b['w'] - 1, $b['y'] + $b['h'] - 1];
            if (str_contains($class, 'chair')) {
                $chairs[] = $box;
            } elseif (str_contains($class, 'table') || str_contains($class, 'desk')) {
                $desks[] = $box;
            }
        }
        $out = [];
        foreach ($desks as $d) {
            $dcx = intdiv($d[0] + $d[2], 2);
            $dcy = intdiv($d[1] + $d[3], 2);
            foreach ($chairs as $c) {
                $ccx = intdiv($c[0] + $c[2], 2);
                $ccy = intdiv($c[1] + $c[3], 2);
                if (abs($dcx - $ccx) > $moduleW || abs($dcy - $ccy) > $moduleH) {
                    continue;
                }
                $x0 = min($d[0], $c[0]);
                $y0 = min($d[1], $c[1]);
                $x1 = max($d[2], $c[2]);
                $y1 = max($d[3], $c[3]);
                // Expand toward a module-sized cell around the pair.
                $cx = intdiv($x0 + $x1, 2);
                $cy = intdiv($y0 + $y1, 2);
                $out[] = [
                    max(0, $cx - intdiv($moduleW, 2)),
                    max(0, $cy - intdiv($moduleH, 2)),
                    $cx + intdiv($moduleW, 2),
                    $cy + intdiv($moduleH, 2),
                ];
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'width' => $this->width,
            'height' => $this->height,
            'source' => $this->source,
            'walls' => $this->walls,
            'doors' => $this->doors,
            'stairs' => $this->stairs,
            'furniture' => $this->furniture,
            'labels' => $this->labels,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<LayerItem>
     */
    private static function items(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $bbox = $item['bbox'] ?? null;
            if (!is_array($bbox)) {
                continue;
            }
            $out[] = [
                'class' => (string) ($item['class'] ?? $item['label'] ?? 'unknown'),
                'bbox' => [
                    'x' => (int) ($bbox['x'] ?? 0),
                    'y' => (int) ($bbox['y'] ?? 0),
                    'w' => (int) ($bbox['w'] ?? $bbox['width'] ?? 0),
                    'h' => (int) ($bbox['h'] ?? $bbox['height'] ?? 0),
                ],
                'confidence' => (float) ($item['confidence'] ?? $item['score'] ?? 1.0),
                'source' => (string) ($item['source'] ?? ''),
            ];
        }

        return $out;
    }
}
