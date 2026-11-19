<?php

declare(strict_types=1);

namespace PowerSweeper\Sweeps;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\Report;
use PowerSweeper\WebApp\SectionAnchors;

/**
 * Apply VCR-style mid-section SetFocus anchors into scrollable form sections.
 *
 * Power Apps scrolls with SetFocus on 1×1 Classic buttons whose Y is typically
 * `{ScrollParent}.Height / 2` (mid-viewport so the section header lands near the
 * top). Web export turns those into proper top-of-section HTML anchors; this
 * sweep is the reverse / repair path: put each AnchorN in the section it serves
 * and set mid-pane Y.
 *
 * Also relocates “hacked” anchors that Studio left in the wrong section
 * (e.g. Anchor2 under Classification instead of Organization).
 */
final class ApplySectionAnchorsSweep implements SweepInterface
{
    public const PARK_NAME = 'AnchorPark';

    public static function id(): string
    {
        return 'apply_section_anchors';
    }

    public static function label(): string
    {
        return 'Apply section anchors';
    }

    public static function description(): string
    {
        return 'Place 1×1 Classic SetFocus anchors in the correct scrollable form sections (Y = ScrollParent.Height / 2) plus AnchorPark. Relocates misplaced Studio anchors so SetFocus and web <a id> section tops stay convertible.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $prefix = is_string($options['prefix'] ?? null) && trim((string) $options['prefix']) !== ''
            ? trim((string) $options['prefix'])
            : 'Anchor';
        $forceY = !array_key_exists('force_y', $options) || (bool) $options['force_y'];
        $park = !array_key_exists('anchor_park', $options) || (bool) $options['anchor_park'];
        $relocate = !array_key_exists('relocate', $options) || (bool) $options['relocate'];
        $x = isset($options['x']) ? (string) $options['x'] : '40';

        foreach ($documents as $doc) {
            if (!$doc instanceof ControlDocument || $doc->format !== 'yaml') {
                continue;
            }

            $mutated = false;
            foreach ($doc->controls() as $scroll) {
                if (!$this->isScrollParent($scroll, $options)) {
                    continue;
                }
                $sections = $this->sectionChildren($scroll);
                $usedNames = [];
                $index = 1;
                foreach ($sections as $section) {
                    $anchorName = $this->preferredAnchorName($section, $prefix, $index, $usedNames);
                    $usedNames[strtolower($anchorName)] = true;

                    $inSection = $this->findNamedChild($section, $anchorName);
                    if ($inSection !== null) {
                        if ($forceY) {
                            $this->ensureMidViewportY($inSection, $scroll->name, $report);
                        }
                        $this->ensureAnchorChrome($inSection, $report);
                        $index++;
                        continue;
                    }

                    if ($relocate) {
                        $body = $scroll->extractYamlDescendant($anchorName)
                            ?? $this->extractAnchorFromDocument($doc, $anchorName);
                        if (is_array($body)) {
                            $body = $this->withMidViewportY($body, $scroll->name);
                            $body = $this->withAnchorChrome($body);
                            $section->addYamlChild($anchorName, $body);
                            $report->add(
                                self::id(),
                                $section->path . '/' . $anchorName,
                                'Control',
                                '(misplaced)',
                                'relocated + Y=' . ltrim(SectionAnchors::midViewportY($scroll->name), '=')
                            );
                            $mutated = true;
                            $index++;
                            continue;
                        }
                    }

                    $this->insertAnchor($section, $anchorName, $scroll->name, $x, $report, true);
                    $mutated = true;
                    $index++;
                }
                if ($park) {
                    $this->ensureAnchorPark($scroll, $x, $report);
                }
            }
            if ($mutated || $relocate) {
                $doc->reindex();
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractAnchorFromDocument(ControlDocument $doc, string $anchorName): ?array
    {
        foreach ($doc->controls() as $control) {
            if (strcasecmp($control->name, $anchorName) !== 0) {
                continue;
            }
            // Find direct parent by path prefix.
            $parentPath = preg_replace('#/[^/]+$#', '', $control->path) ?? '';
            foreach ($doc->controls() as $parent) {
                if ($parent->path === $parentPath) {
                    return $parent->extractYamlChild($anchorName);
                }
            }
            // Fallback: try extract on every node.
            foreach ($doc->controls() as $parent) {
                $body = $parent->extractYamlChild($anchorName);
                if ($body !== null) {
                    return $body;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function isScrollParent(ControlNode $control, array $options): bool
    {
        $nameHint = is_string($options['scroll_name'] ?? null) ? trim((string) $options['scroll_name']) : '';
        if ($nameHint !== '' && strcasecmp($control->name, $nameHint) === 0) {
            return true;
        }
        if (preg_match('/scrollable|scroll.?form|form.?scroll/i', $control->name) === 1) {
            return true;
        }
        if (!$control->isContainer()) {
            return false;
        }
        $dir = strtolower((string) ($control->getProperty('LayoutDirection') ?? ''));
        $overflow = strtolower((string) ($control->getProperty('LayoutOverflowY') ?? $control->getProperty('OverflowY') ?? ''));
        if (str_contains($dir, 'vertical') && (str_contains($overflow, 'scroll') || str_contains($overflow, 'auto'))) {
            return true;
        }

        return false;
    }

    /**
     * @return list<ControlNode>
     */
    private function sectionChildren(ControlNode $scroll): array
    {
        $out = [];
        foreach ($scroll->children as $child) {
            if (!$child instanceof ControlNode) {
                continue;
            }
            if ($this->looksLikeAnchor($child) || SectionAnchors::isParkName($child->name)) {
                continue;
            }
            if (!$child->isContainer()) {
                continue;
            }
            if (preg_match(
                '/^(AnchorPark|NavTabs|Sidebar|Topbar|Header|Footer)|Checkboxes?$|_Header$|HeaderAndSelect/i',
                $child->name
            ) === 1) {
                continue;
            }
            $out[] = $child;
        }

        $named = array_values(array_filter(
            $out,
            fn(ControlNode $c): bool => $this->looksLikeSectionName($c->name)
                || SectionAnchors::forSectionName($c->name) !== null
        ));

        return $named !== [] ? $named : $out;
    }

    private function looksLikeSectionName(string $name): bool
    {
        return (bool) preg_match(
            '/^(\d+[_\s-]|Annex|Section|Destination|Organization|Summary|Sites|Visitors|Remarks|Pertinence|Initiative|Classification|Dates?|Security)/i',
            $name
        );
    }

    private function looksLikeAnchor(ControlNode $control): bool
    {
        if (SectionAnchors::isSectionAnchorName($control->name) || SectionAnchors::isParkName($control->name)) {
            return true;
        }
        $w = $this->numericProp($control->getProperty('Width'));
        $h = $this->numericProp($control->getProperty('Height'));

        return $w !== null && $h !== null && $w <= 2 && $h <= 2
            && stripos($control->type, 'button') !== false;
    }

    /**
     * @param array<string, true> $usedNames
     */
    private function preferredAnchorName(ControlNode $section, string $prefix, int $index, array $usedNames): string
    {
        $mapped = SectionAnchors::forSectionName($section->name);
        if ($mapped !== null && ($prefix === 'Anchor' || str_starts_with($mapped, $prefix))) {
            if ($prefix !== 'Anchor' && preg_match('/^Anchor(.+)$/i', $mapped, $m) === 1) {
                $mapped = $prefix . $m[1];
            }
            if (!isset($usedNames[strtolower($mapped)])) {
                return $mapped;
            }
        }

        $n = strtolower($section->name);
        $candidates = [];
        if (str_contains($n, 'site') && !str_contains($n, 'visit')) {
            $candidates[] = $prefix === 'Anchor' ? 'AnchorSites' : $prefix . 'Sites';
        }
        if (str_contains($n, 'visitor')) {
            $candidates[] = $prefix === 'Anchor' ? 'AnchorVisitors' : $prefix . 'Visitors';
        }
        if (preg_match('/emergenc/i', $n) === 1) {
            $candidates[] = $prefix === 'Anchor' ? 'AnchorEmer' : $prefix . 'Emer';
        }
        if (preg_match('/^(\d+)/', $section->name, $m) === 1) {
            $candidates[] = $prefix . (int) $m[1];
        }
        $candidates[] = $prefix . $index;

        foreach ($candidates as $name) {
            if (!isset($usedNames[strtolower($name)])) {
                return $name;
            }
        }

        return $prefix . $index . '_' . substr(sha1($section->path), 0, 4);
    }

    private function findNamedChild(ControlNode $parent, string $name): ?ControlNode
    {
        foreach ($parent->children as $child) {
            if ($child instanceof ControlNode && strcasecmp($child->name, $name) === 0) {
                return $child;
            }
        }

        return null;
    }

    private function ensureAnchorPark(ControlNode $scroll, string $x, Report $report): void
    {
        $existing = null;
        foreach ($scroll->children as $child) {
            if ($child instanceof ControlNode && strcasecmp($child->name, self::PARK_NAME) === 0) {
                $existing = $child;
                break;
            }
            if ($child instanceof ControlNode) {
                $nested = $this->findNamedChild($child, self::PARK_NAME);
                if ($nested !== null) {
                    $existing = $nested;
                    break;
                }
            }
        }
        if ($existing !== null) {
            $this->ensureAnchorChrome($existing, $report);
            return;
        }
        foreach ($scroll->children as $child) {
            if ($child instanceof ControlNode && preg_match('/AnchorPark/i', $child->name) === 1 && $child->isContainer()) {
                if ($this->findNamedChild($child, self::PARK_NAME) === null) {
                    $this->insertAnchor($child, self::PARK_NAME, $scroll->name, $x, $report, false);
                }
                return;
            }
        }
        $this->insertAnchor($scroll, self::PARK_NAME, $scroll->name, $x, $report, false);
    }

    private function insertAnchor(
        ControlNode $section,
        string $anchorName,
        string $scrollName,
        string $x,
        Report $report,
        bool $midViewport,
    ): void {
        $y = $midViewport ? SectionAnchors::midViewportY($scrollName) : '=15';
        $body = [
            'Control' => 'Classic/Button@2.2.0',
            'Properties' => [
                'X' => '=' . $x,
                'Y' => $y,
                'Width' => '=1',
                'Height' => '=1',
                'Text' => '="' . str_replace('"', '""', $anchorName) . '"',
                'Fill' => '=RGBA(0, 0, 0, 0)',
                'Color' => '=RGBA(0, 0, 0, 0)',
                'BorderThickness' => '=0',
                'BorderColor' => '=RGBA(0, 0, 0, 0)',
                'FocusedBorderThickness' => '=0',
                'TabIndex' => '=-1',
                'AccessibleLabel' => '="' . str_replace('"', '""', $anchorName) . ' scroll target"',
            ],
        ];
        $section->addYamlChild($anchorName, $body);
        $report->add(
            self::id(),
            $section->path . '/' . $anchorName,
            'Control',
            '(missing)',
            'Classic/Button 1×1 Y=' . ltrim($y, '=')
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function withMidViewportY(array $body, string $scrollName): array
    {
        if (!isset($body['Properties']) || !is_array($body['Properties'])) {
            $body['Properties'] = [];
        }
        $body['Properties']['Y'] = SectionAnchors::midViewportY($scrollName);

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function withAnchorChrome(array $body): array
    {
        if (!isset($body['Properties']) || !is_array($body['Properties'])) {
            $body['Properties'] = [];
        }
        $body['Properties']['Width'] = '=1';
        $body['Properties']['Height'] = '=1';
        $body['Properties']['Fill'] = '=RGBA(0, 0, 0, 0)';
        $body['Properties']['TabIndex'] = '=-1';

        return $body;
    }

    private function ensureMidViewportY(ControlNode $anchor, string $scrollName, Report $report): void
    {
        if (strcasecmp($anchor->name, self::PARK_NAME) === 0) {
            return;
        }
        $want = SectionAnchors::midViewportY($scrollName);
        $from = $anchor->getProperty('Y');
        $compact = str_replace(' ', '', (string) $from);
        $safeScroll = preg_match('/^[A-Za-z_][\w]*$/', $scrollName) === 1
            ? $scrollName
            : "'" . str_replace("'", "''", $scrollName) . "'";
        if ($from !== null && str_contains($compact, $safeScroll . '.Height/2')) {
            return;
        }
        if ($from !== null && str_contains((string) $from, $safeScroll) && str_contains((string) $from, 'Height')) {
            return;
        }
        $anchor->setProperty('Y', $want);
        $report->add(self::id(), $anchor->path, 'Y', $from ?? '(unset)', $want);
    }

    private function ensureAnchorChrome(ControlNode $anchor, Report $report): void
    {
        $targets = [
            'Width' => '=1',
            'Height' => '=1',
            'Fill' => '=RGBA(0, 0, 0, 0)',
            'TabIndex' => '=-1',
        ];
        foreach ($targets as $prop => $want) {
            $from = $anchor->getProperty($prop);
            $normFrom = str_replace(' ', '', ltrim((string) $from, '='));
            $normWant = str_replace(' ', '', ltrim($want, '='));
            if ($from !== null && strcasecmp($normFrom, $normWant) === 0) {
                continue;
            }
            if (($prop === 'Width' || $prop === 'Height') && $from !== null && !is_numeric(ltrim($from, '='))) {
                continue;
            }
            $anchor->setProperty($prop, $want);
            $report->add(self::id(), $anchor->path, $prop, $from ?? '(unset)', $want);
        }
    }

    private function numericProp(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $v = ltrim(trim($raw), '=');
        if (is_numeric($v)) {
            return (float) $v;
        }

        return null;
    }
}
