<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;

final class AlignNearMissHop implements HopInterface
{
    public static function id(): string
    {
        return 'align_near_miss';
    }

    public static function label(): string
    {
        return 'Align near-misses';
    }

    public static function description(): string
    {
        return 'Snap sibling X/Y/Width/Height values that are off by only a few pixels.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $tolerance = (int) ($options['tolerance'] ?? 3);
        if ($tolerance < 1) {
            $tolerance = 3;
        }

        foreach ($documents as $doc) {
            $alignedViaChildren = false;
            foreach ($doc->controls() as $parent) {
                if (count($parent->children) < 2) {
                    continue;
                }
                $alignedViaChildren = true;
                $this->alignGroup($parent->children, $tolerance, $report, $parent->format);
            }

            // Fallback for flat JSON trees without populated children links
            if (!$alignedViaChildren) {
                $byParent = [];
                foreach ($doc->controls() as $control) {
                    $parts = explode('/', $control->path);
                    array_pop($parts);
                    $key = implode('/', $parts);
                    $byParent[$key][] = $control;
                }
                foreach ($byParent as $group) {
                    if (count($group) >= 2) {
                        $this->alignGroup($group, $tolerance, $report, $group[0]->format);
                    }
                }
            }
        }
    }

    /**
     * @param list<ControlNode> $siblings
     */
    private function alignGroup(array $siblings, int $tolerance, Report $report, string $format): void
    {
        foreach (['X', 'Y', 'Width', 'Height'] as $prop) {
            $numeric = [];
            foreach ($siblings as $i => $control) {
                $raw = $control->getProperty($prop);
                if ($raw === null) {
                    continue;
                }
                $num = $this->toNumber($raw);
                if ($num === null) {
                    continue;
                }
                $numeric[] = ['control' => $control, 'value' => $num, 'raw' => $raw];
            }

            if (count($numeric) < 2) {
                continue;
            }

            // Cluster values within tolerance; snap cluster to median (rounded)
            $used = [];
            for ($i = 0; $i < count($numeric); $i++) {
                if (isset($used[$i])) {
                    continue;
                }
                $cluster = [$i];
                for ($j = $i + 1; $j < count($numeric); $j++) {
                    if (isset($used[$j])) {
                        continue;
                    }
                    if (abs($numeric[$i]['value'] - $numeric[$j]['value']) <= $tolerance) {
                        $cluster[] = $j;
                    }
                }
                if (count($cluster) < 2) {
                    continue;
                }
                // Only snap if they are near but not already equal
                $values = array_map(static fn($idx) => $numeric[$idx]['value'], $cluster);
                $unique = array_unique($values);
                if (count($unique) < 2) {
                    foreach ($cluster as $idx) {
                        $used[$idx] = true;
                    }
                    continue;
                }

                sort($values);
                $median = $values[(int) floor((count($values) - 1) / 2)];
                $target = (float) $median;

                foreach ($cluster as $idx) {
                    $used[$idx] = true;
                    $item = $numeric[$idx];
                    if (abs($item['value'] - $target) < 0.001) {
                        continue;
                    }
                    $to = $this->formatNumber($target, $format, $item['raw']);
                    $item['control']->setProperty($prop, $to);
                    $report->add(self::id(), $item['control']->path, $prop, $item['raw'], $to);
                }
            }
        }
    }

    private function toNumber(string $raw): ?float
    {
        $v = trim($raw);
        if (str_starts_with($v, '=')) {
            $v = substr($v, 1);
        }
        $v = trim($v);
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    private function formatNumber(float $n, string $format, string $original): string
    {
        $asInt = abs($n - round($n)) < 0.001;
        $num = $asInt ? (string) (int) round($n) : rtrim(rtrim(sprintf('%.4F', $n), '0'), '.');
        $hadEquals = str_starts_with(trim($original), '=') || $format === 'yaml';
        return $hadEquals ? '=' . $num : $num;
    }
}
