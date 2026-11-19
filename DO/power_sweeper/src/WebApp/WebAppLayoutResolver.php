<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Resolve canvas X/Y/Width/Height formulas against App, Parent, Self, and siblings.
 *
 * Handles the subset VCR and similar apps use: App.Width, Parent.Width - Self.X,
 * Max(), If(), control.Height sums, and AutoLayout stacking. Width/Height of 0
 * on an AutoLayout child fills the parent remaining space.
 */
final class WebAppLayoutResolver
{
    /** @var array<string, float|int|bool|string> */
    private array $vars = [
        'varSideMenu' => false,
        'varTopMenu' => false,
        'varBugReport' => false,
        'varShowLoadingContainer' => false,
        'varDebugMode' => false,
        'gblDarkMode' => false,
        'fnIsAcknowledged' => false,
        'varShowValidation' => false,
        'varFormSubmitted' => false,
        'varIsEditMode' => false,
        'varIsEditingForm' => false,
        'varNavLock' => false,
    ];

    /** @var array<string, array{x:float,y:float,width:float,height:float}> */
    private array $boxes = [];

    /**
     * @param array<string, mixed> $ir
     * @return array<string, mixed>
     */
    public function resolve(array $ir): array
    {
        $appW = (float) (($ir['document']['layout']['width'] ?? null) ?: 1366);
        $appH = (float) (($ir['document']['layout']['height'] ?? null) ?: 768);
        $this->vars['App.Width'] = $appW;
        $this->vars['App.Height'] = $appH;
        $this->boxes = [];

        $screens = is_array($ir['screens'] ?? null) ? $ir['screens'] : [];
        foreach ($screens as $i => $screen) {
            if (!is_array($screen)) {
                continue;
            }
            $parent = ['x' => 0.0, 'y' => 0.0, 'width' => $appW, 'height' => $appH];
            for ($pass = 0; $pass < 3; $pass++) {
                $screens[$i] = $this->resolveNode($screen, $parent, $appW, $appH, false);
                $screen = $screens[$i];
            }
        }
        $ir['screens'] = $screens;

        return $ir;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{x:float,y:float,width:float,height:float} $parent
     * @return array<string, mixed>
     */
    private function resolveNode(array $node, array $parent, float $appW, float $appH, bool $parentAuto): array
    {
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $name = (string) ($node['name'] ?? '');
        $self = [
            'x' => 0.0,
            'y' => 0.0,
            'width' => $parent['width'],
            'height' => $parent['height'],
        ];
        if ($name !== '' && isset($this->boxes[$name])) {
            $self['width'] = $this->boxes[$name]['width'];
            $self['height'] = $this->boxes[$name]['height'];
        }

        $widthRaw = $this->stripFx((string) ($props['Width'] ?? ''));
        $heightRaw = $this->stripFx((string) ($props['Height'] ?? ''));
        $fillPortions = $this->evalDim($props['FillPortions'] ?? null, $parent, $self, $appW, $appH, 0.0);
        $minH = $this->evalDim($props['LayoutMinHeight'] ?? null, $parent, $self, $appW, $appH, 0.0);
        $explicitWidth = $widthRaw !== '' && $widthRaw !== '0';
        $explicitHeight = $heightRaw !== '' && $heightRaw !== '0';
        // FillPortions owns the main-axis size in AutoLayout; content width is only a floor.
        $fillsMain = $parentAuto && $fillPortions > 0 && !$explicitWidth;

        $widthFallback = $explicitWidth ? 0.0 : ($this->isChoice($node) ? $this->estimateChoiceWidth($node) : $parent['width']);
        $contentW = $this->estimateContentWidth($node);
        if (!$explicitWidth && $contentW > 0 && !$fillsMain) {
            $widthFallback = $contentW;
        }
        $heightFallback = $parent['height'];
        if ($parentAuto && !$explicitWidth) {
            if ($fillsMain) {
                $widthFallback = 0.0;
            } else {
                $widthFallback = $contentW > 0
                    ? $contentW
                    : ($this->isChoice($node) ? $this->estimateChoiceWidth($node) : $parent['width']);
            }
        }
        if ($parentAuto && !$explicitHeight && $fillPortions <= 0) {
            $leafH = $this->defaultLeafHeight($node);
            if ($leafH > 0) {
                $heightFallback = max($minH, $leafH);
            } else {
                $maxH = $this->evalDim($props['LayoutMaxHeight'] ?? null, $parent, $self, $appW, $appH, 0.0);
                if ($maxH > 0 && $minH > 0) {
                    $heightFallback = min($maxH, max($minH, 32.0));
                } else {
                    $heightFallback = $minH > 0 ? $minH : 0.0;
                }
            }
        }

        // Width/Height first so Self.Width / Self.Height work in X/Y, then re-eval.
        $self['width'] = $this->evalDim($props['Width'] ?? null, $parent, $self, $appW, $appH, $widthFallback);
        $self['height'] = $this->evalDim($props['Height'] ?? null, $parent, $self, $appW, $appH, $heightFallback);
        $self['x'] = $this->evalDim($props['X'] ?? null, $parent, $self, $appW, $appH, 0.0);
        $self['y'] = $this->evalDim($props['Y'] ?? null, $parent, $self, $appW, $appH, 0.0);
        $self['width'] = $this->evalDim($props['Width'] ?? null, $parent, $self, $appW, $appH, $self['width']);
        $self['height'] = $this->evalDim($props['Height'] ?? null, $parent, $self, $appW, $appH, $self['height']);
        $self['x'] = $this->evalDim($props['X'] ?? null, $parent, $self, $appW, $appH, $self['x']);
        $self['y'] = $this->evalDim($props['Y'] ?? null, $parent, $self, $appW, $appH, $self['y']);

        if ($self['width'] <= 0 && !$explicitWidth && !$fillsMain) {
            $est = $this->estimateContentWidth($node);
            $self['width'] = $est > 0 ? $est : ($this->isChoice($node) ? $this->estimateChoiceWidth($node) : max(0.0, $parent['width']));
        }
        if ($self['height'] < 0) {
            $self['height'] = 0.0;
        }
        $maxH = $this->evalDim($props['LayoutMaxHeight'] ?? null, $parent, $self, $appW, $appH, 0.0);
        // LayoutMin/MaxHeight clamp AutoLayout sizing even when Height is set.
        if ($minH > 0 && $self['height'] < $minH) {
            $self['height'] = $minH;
        }
        if ($maxH > 0 && $self['height'] > $maxH) {
            $self['height'] = $maxH;
        }
        if (!$explicitHeight) {
            $leafH = $this->defaultLeafHeight($node);
            if ($leafH > 0) {
                $self['height'] = max($self['height'], $leafH, $minH);
            } elseif ($self['height'] <= 0 && $minH > 0) {
                $self['height'] = $minH;
            }
        }
        $node['_explicit_height'] = $explicitHeight;
        $node['_explicit_width'] = $explicitWidth;
        $node['_fill_portions'] = $fillPortions;
        $node['_content_min_width'] = $contentW > 0
            ? $contentW
            : ($this->isChoice($node) ? $this->estimateChoiceWidth($node) : 0.0);

        $dir = $this->directionOf($props['LayoutDirection'] ?? ($node['variant'] ?? ''));
        if ($dir === '' && strcasecmp((string) ($node['variant'] ?? ''), 'AutoLayout') === 0) {
            $dir = 'vertical';
        }
        // Gallery "Vertical"/"Horizontal" describes item flow, not template AutoLayout.
        // Template children keep absolute X/Y inside each row.
        $isGallery = strcasecmp((string) ($node['role'] ?? ''), 'gallery') === 0
            || str_contains(strtolower((string) ($node['type'] ?? '')), 'gallery');
        if ($isGallery) {
            $dir = '';
            $templateSize = $this->evalDim($props['TemplateSize'] ?? null, $parent, $self, $appW, $appH, 40.0);
            if ($templateSize <= 0) {
                $templateSize = 40.0;
            }
            $node['_template_size'] = $templateSize;
            // Admin lookup galleries should fill remaining pane height, not sit at 100px.
            $remain = max(280.0, $parent['height'] - $self['y'] - 8.0);
            if ($remain > $self['height']) {
                $self['height'] = $remain;
            }
        }

        $node['layout'] = [
            'x' => (int) round($self['x']),
            'y' => (int) round($self['y']),
            'width' => (int) max(0, round($self['width'])),
            'height' => (int) max(0, round($self['height'])),
        ];
        $node['layout_direction'] = $dir;
        $node['layout_gap'] = (int) ($this->evalDim($props['LayoutGap'] ?? null, $parent, $self, $appW, $appH, 0.0));
        $node['layout_pad'] = [
            'l' => (int) ($this->evalDim($props['PaddingLeft'] ?? null, $parent, $self, $appW, $appH, 0.0)),
            'r' => (int) ($this->evalDim($props['PaddingRight'] ?? null, $parent, $self, $appW, $appH, 0.0)),
            't' => (int) ($this->evalDim($props['PaddingTop'] ?? null, $parent, $self, $appW, $appH, 0.0)),
            'b' => (int) ($this->evalDim($props['PaddingBottom'] ?? null, $parent, $self, $appW, $appH, 0.0)),
        ];
        $node['layout_align'] = strtolower($this->stripFx((string) ($props['LayoutAlignItems'] ?? '')));
        $node['layout_justify'] = strtolower($this->stripFx((string) ($props['LayoutJustifyContent'] ?? '')));

        if ($name !== '') {
            $this->boxes[$name] = $self;
        }

        $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
        $isAuto = $dir !== '';
        // AutoLayout children lay out in the padded content box — Parent.Width must
        // match that inset so Width=Parent.Width does not overflow horizontal padding.
        $childParent = $self;
        if ($isAuto) {
            $padL = (float) ($node['layout_pad']['l'] ?? 0);
            $padR = (float) ($node['layout_pad']['r'] ?? 0);
            $padT = (float) ($node['layout_pad']['t'] ?? 0);
            $padB = (float) ($node['layout_pad']['b'] ?? 0);
            $childParent = [
                'x' => $self['x'] + $padL,
                'y' => $self['y'] + $padT,
                'width' => max(0.0, $self['width'] - $padL - $padR),
                'height' => max(0.0, $self['height'] - $padT - $padB),
                'pad_l' => $padL,
                'pad_r' => $padR,
                'pad_t' => $padT,
                'pad_b' => $padB,
                'template_height' => (float) ($node['_template_size'] ?? 0),
            ];
        } else {
            $childParent = [
                'x' => $self['x'],
                'y' => $self['y'],
                'width' => $self['width'],
                'height' => $self['height'],
                'pad_l' => (float) ($node['layout_pad']['l'] ?? 0),
                'pad_r' => (float) ($node['layout_pad']['r'] ?? 0),
                'pad_t' => (float) ($node['layout_pad']['t'] ?? 0),
                'pad_b' => (float) ($node['layout_pad']['b'] ?? 0),
                'template_height' => (float) ($node['_template_size'] ?? max(40.0, $self['height'] > 0 ? min($self['height'], 50.0) : 40.0)),
            ];
        }
        foreach ($kids as $i => $child) {
            if (is_array($child)) {
                $kids[$i] = $this->resolveNode($child, $childParent, $appW, $appH, $isAuto);
            }
        }
        $node['children'] = $kids;

        if ($isAuto) {
            $node = $this->applyAutoLayout($node);
            $self = [
                'x' => (float) $node['layout']['x'],
                'y' => (float) $node['layout']['y'],
                'width' => (float) $node['layout']['width'],
                'height' => (float) $node['layout']['height'],
            ];
        } else {
            // ManualLayout: expand parents that omit Height (or Parent.Height
            // resolved to 0 before the parent box was known) so absolute children
            // and ghost hit-targets like AdminWholeButtonClassic fit the card.
            $heightFx = $this->stripFx((string) ($props['Height'] ?? ''));
            $heightIsParent = $heightFx !== '' && preg_match('/^Parent\.Height\b/', $heightFx) === 1;
            if (!$explicitHeight || ($heightIsParent && $self['height'] <= 0)) {
                $bottom = 0.0;
                foreach ($kids as $child) {
                    if (!is_array($child)) {
                        continue;
                    }
                    $cl = is_array($child['layout'] ?? null) ? $child['layout'] : [];
                    $bottom = max(
                        $bottom,
                        (float) ($cl['y'] ?? 0) + (float) ($cl['height'] ?? 0)
                    );
                }
                if ($bottom > $self['height']) {
                    $self['height'] = $bottom;
                    $node['layout']['height'] = (int) max(0, round($bottom));
                }
            }
            // Re-stretch children authored as Parent.Width / Parent.Height now that
            // the parent box is known (home card ghost buttons, etc.).
            foreach ($kids as $i => $child) {
                if (!is_array($child)) {
                    continue;
                }
                $cp = is_array($child['properties'] ?? null) ? $child['properties'] : [];
                $ch = $this->stripFx((string) ($cp['Height'] ?? ''));
                $cw = $this->stripFx((string) ($cp['Width'] ?? ''));
                $cl = is_array($child['layout'] ?? null) ? $child['layout'] : [];
                $changed = false;
                if ($ch !== '' && preg_match('/^Parent\.Height\b/', $ch) === 1 && $self['height'] > 0) {
                    $cl['height'] = (int) max(0, round($self['height']));
                    $changed = true;
                }
                if ($cw !== '' && preg_match('/^Parent\.Width\b/', $cw) === 1 && $self['width'] > 0) {
                    $cl['width'] = (int) max(0, round($self['width']));
                    $changed = true;
                }
                if ($changed) {
                    $kids[$i]['layout'] = $cl;
                    $childName = (string) ($child['name'] ?? '');
                    if ($childName !== '') {
                        $this->boxes[$childName] = [
                            'x' => (float) ($cl['x'] ?? 0),
                            'y' => (float) ($cl['y'] ?? 0),
                            'width' => (float) ($cl['width'] ?? 0),
                            'height' => (float) ($cl['height'] ?? 0),
                        ];
                    }
                }
            }
            $node['children'] = $kids;
        }

        $reH = $this->evalDim($props['Height'] ?? null, $parent, $self, $appW, $appH, $self['height']);
        if ($reH > 0) {
            $self['height'] = $reH;
            $node['layout']['height'] = (int) max(0, round($reH));
        }
        if ($name !== '') {
            $this->boxes[$name] = $self;
        }

        return $node;
    }

    /** @param array<string, mixed> $node */
    private function applyAutoLayout(array $node): array
    {
        $dir = (string) ($node['layout_direction'] ?? '');
        if ($dir === '') {
            return $node;
        }
        $pad = is_array($node['layout_pad'] ?? null) ? $node['layout_pad'] : ['l' => 0, 'r' => 0, 't' => 0, 'b' => 0];
        $gap = (int) ($node['layout_gap'] ?? 0);
        $align = (string) ($node['layout_align'] ?? '');
        $justify = (string) ($node['layout_justify'] ?? '');
        $boxW = (int) ($node['layout']['width'] ?? 0);
        $boxH = (int) ($node['layout']['height'] ?? 0);
        $innerW = max(0, $boxW - (int) $pad['l'] - (int) $pad['r']);
        $innerH = max(0, $boxH - (int) $pad['t'] - (int) $pad['b']);

        $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
        $visible = [];
        foreach ($kids as $i => $child) {
            if (!is_array($child) || $this->isHidden($child)) {
                continue;
            }
            $visible[] = $i;
        }

        // FillPortions: share leftover main-axis space like Power Apps AutoLayout.
        $gapTotal = count($visible) > 1 ? $gap * (count($visible) - 1) : 0;
        $innerMain = $dir === 'horizontal' ? $innerW : $innerH;
        $fixedMain = 0;
        $fillTotal = 0.0;
        $fillIdx = [];
        foreach ($visible as $i) {
            $fp = (float) ($kids[$i]['_fill_portions'] ?? 0);
            $explicitChild = (bool) ($kids[$i]['_explicit_width'] ?? false);
            if ($dir === 'vertical') {
                $explicitChild = (bool) ($kids[$i]['_explicit_height'] ?? false);
            }
            $cl = is_array($kids[$i]['layout'] ?? null) ? $kids[$i]['layout'] : [];
            if ($fp > 0 && !$explicitChild) {
                $fillIdx[$i] = $fp;
                $fillTotal += $fp;
            } else {
                $fixedMain += $dir === 'horizontal' ? (int) ($cl['width'] ?? 0) : (int) ($cl['height'] ?? 0);
            }
        }
        if ($fillTotal > 0) {
            $remainFill = max(0, $innerMain - $fixedMain - $gapTotal);
            $assigned = 0;
            $keys = array_keys($fillIdx);
            $last = $keys[count($keys) - 1];
            foreach ($fillIdx as $i => $fp) {
                $share = $i === $last
                    ? max(0, $remainFill - $assigned)
                    : (int) floor($remainFill * ($fp / $fillTotal));
                $assigned += $share;
                $minW = (int) ceil((float) ($kids[$i]['_content_min_width'] ?? 0));
                if ($dir === 'horizontal') {
                    $kids[$i]['layout']['width'] = max($minW > 0 ? $minW : 16, $share);
                } else {
                    $kids[$i]['layout']['height'] = max(16, $share);
                }
            }
        }

        $totalMain = 0;
        foreach ($visible as $i) {
            $cl = is_array($kids[$i]['layout'] ?? null) ? $kids[$i]['layout'] : [];
            $totalMain += $dir === 'horizontal' ? (int) ($cl['width'] ?? 0) : (int) ($cl['height'] ?? 0);
        }
        if (count($visible) > 1) {
            $totalMain += $gap * (count($visible) - 1);
        }

        $cursor = $dir === 'horizontal' ? (int) $pad['l'] : (int) $pad['t'];
        if (str_contains($justify, 'center')) {
            $remain = ($dir === 'horizontal' ? $innerW : $innerH) - $totalMain;
            if ($remain > 0) {
                $cursor += (int) floor($remain / 2);
            }
        } elseif (str_contains($justify, 'end')) {
            $remain = ($dir === 'horizontal' ? $innerW : $innerH) - $totalMain;
            if ($remain > 0) {
                $cursor += $remain;
            }
        }

        foreach ($visible as $i) {
            $child = $kids[$i];
            $cl = is_array($child['layout'] ?? null) ? $child['layout'] : ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];
            $cw = (int) ($cl['width'] ?? 0);
            $ch = (int) ($cl['height'] ?? 0);
            $childAlign = strtolower($this->stripFx((string) (($child['properties']['AlignInContainer'] ?? '') ?: '')));
            $childFill = (float) ($child['_fill_portions'] ?? 0);

            if ($dir === 'horizontal') {
                $childWidthRaw = $this->stripFx((string) (($child['properties']['Width'] ?? '') ?: ''));
                if ($childFill <= 0) {
                    if ($this->isChoice($child) && ($childWidthRaw === '' || $childWidthRaw === '0')) {
                        $cw = (int) $this->estimateChoiceWidth($child);
                    } elseif ($cw <= 0 || ($cw >= $innerW && ($childWidthRaw === '' || $childWidthRaw === '0'))) {
                        $cw = max(16, (int) floor($innerW / max(1, count($visible))));
                    }
                }
                // AutoLayout content box is inset by padding — don't let Parent.Width overflow it.
                if ($cw > $innerW) {
                    $cw = $innerW;
                }
                if ($ch <= 0 || str_contains($align, 'stretch') || str_contains($childAlign, 'stretch')) {
                    $ch = $innerH > 0 ? $innerH : $ch;
                }
                if ($innerH > 0 && $ch > $innerH && (str_contains($align, 'stretch') || str_contains($childAlign, 'stretch'))) {
                    $ch = $innerH;
                }
                $y = (int) $pad['t'];
                if (str_contains($align, 'center') || str_contains($childAlign, 'center')) {
                    $y = (int) $pad['t'] + (int) max(0, floor(($innerH - $ch) / 2));
                } elseif (str_contains($align, 'end') || str_contains($childAlign, 'end')) {
                    $y = (int) $pad['t'] + max(0, $innerH - $ch);
                }
                $cl['x'] = $cursor;
                $cl['y'] = $y;
                $cl['width'] = $cw;
                $cl['height'] = $ch;
                $cursor += $cw + $gap;
            } else {
                if ($cw <= 0 || str_contains($align, 'stretch') || str_contains($childAlign, 'stretch') || str_contains($childAlign, 'setbycontainer')) {
                    $cw = $innerW;
                }
                if ($cw > $innerW) {
                    $cw = $innerW;
                }
                $x = (int) $pad['l'];
                if (str_contains($align, 'center') || str_contains($childAlign, 'center')) {
                    $x = (int) $pad['l'] + (int) max(0, floor(($innerW - $cw) / 2));
                } elseif (str_contains($align, 'end') || str_contains($childAlign, 'end')) {
                    $x = (int) $pad['l'] + max(0, $innerW - $cw);
                }
                $cl['x'] = $x;
                $cl['y'] = $cursor;
                $cl['width'] = $cw;
                $cl['height'] = $ch;
                $cursor += $ch + $gap;
            }

            $child['layout'] = $cl;
            $kids[$i] = $child;
            $cname = (string) ($child['name'] ?? '');
            if ($cname !== '') {
                $this->boxes[$cname] = [
                    'x' => (float) $cl['x'],
                    'y' => (float) $cl['y'],
                    'width' => (float) $cl['width'],
                    'height' => (float) $cl['height'],
                ];
            }
        }

        $node['children'] = $kids;
        $content = $cursor - $gap + ($dir === 'horizontal' ? (int) $pad['r'] : (int) $pad['b']);
        $explicitHeight = (bool) ($node['_explicit_height'] ?? false);
        if ($dir === 'vertical' && (!$explicitHeight || (int) ($node['layout']['height'] ?? 0) <= 0) && $content > 0) {
            $node['layout']['height'] = $content;
        }
        if ($dir === 'horizontal' && !$explicitHeight) {
            $maxCh = 0;
            foreach ($visible as $i) {
                $maxCh = max($maxCh, (int) (($kids[$i]['layout']['height'] ?? 0)));
            }
            if ($maxCh > 0) {
                $node['layout']['height'] = $maxCh + (int) $pad['t'] + (int) $pad['b'];
            }
        } elseif ($dir === 'horizontal' && (int) ($node['layout']['height'] ?? 0) <= 0) {
            $maxCh = 0;
            foreach ($visible as $i) {
                $maxCh = max($maxCh, (int) (($kids[$i]['layout']['height'] ?? 0)));
            }
            if ($maxCh > 0) {
                $node['layout']['height'] = $maxCh + (int) $pad['t'] + (int) $pad['b'];
            }
        }

        return $node;
    }

    /** @param array<string, mixed> $node */
    private function isChoice(array $node): bool
    {
        $role = strtolower((string) ($node['role'] ?? ''));
        $type = strtolower((string) ($node['type'] ?? ''));

        return $role === 'choice' || str_contains($type, 'checkbox') || str_contains($type, 'toggle');
    }

    /** @param array<string, mixed> $node */
    private function defaultLeafHeight(array $node): float
    {
        $role = strtolower((string) ($node['role'] ?? ''));
        $type = strtolower((string) ($node['type'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        if (str_contains($type, 'informationbutton') || preg_match('/^InformationButton/i', $name) === 1) {
            return 28.0;
        }
        if ($role === 'input' || str_contains($type, 'dropdown') || str_contains($type, 'combobox')
            || str_contains($type, 'textinput') || str_contains($type, 'datepicker')
            || str_contains($type, 'numberinput')
        ) {
            return 32.0;
        }
        if ($role === 'html' || str_contains($type, 'html')) {
            return 32.0;
        }
        if ($role === 'label' || $role === 'choice' || str_contains($type, 'text') || str_contains($type, 'label')) {
            return 24.0;
        }

        return 0.0;
    }

    /** @param array<string, mixed> $node */
    private function estimateContentWidth(array $node): float
    {
        if ($this->isChoice($node)) {
            return $this->estimateChoiceWidth($node);
        }
        $role = strtolower((string) ($node['role'] ?? ''));
        $type = strtolower((string) ($node['type'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        // Modern InformationButton has no Width — keep a compact (i) footprint.
        if (str_contains($type, 'informationbutton') || preg_match('/^InformationButton/i', $name) === 1) {
            return 28.0;
        }
        if ($role === 'html' || str_contains($type, 'html')) {
            $raw = (string) (($node['properties']['HtmlText'] ?? $node['labels']['HtmlText'] ?? '') ?: '');
            if (preg_match('/Jump to (Sites|Visitors)/i', $raw, $m)) {
                return (float) (strlen('Jump to ' . $m[1]) * 7.2 + 16);
            }
        }

        return 0.0;
    }

    /** @param array<string, mixed> $node */
    private function estimateChoiceWidth(array $node): float
    {
        $text = (string) (($node['labels']['Text'] ?? '') ?: '');
        if ($text === '') {
            $raw = $this->stripFx((string) (($node['properties']['Text'] ?? '') ?: ''));
            if (
                (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
                || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
            ) {
                $text = substr($raw, 1, -1);
            } else {
                $text = $raw;
            }
        }
        $text = trim($text);
        if ($text === '' || preg_match('/\b(If|Switch|ThisItem)\s*[\.(]/', $text) === 1) {
            $text = (string) ($node['name'] ?? 'Choice');
            $text = preg_replace('/^RequestingAgency/i', '', $text) ?? $text;
        }
        $chars = max(1, strlen($text));

        return (float) (28 + (int) round($chars * 7.2) + 16);
    }

    /** @param array<string, mixed> $node */
    private function isHidden(array $node): bool
    {
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $v = $this->stripFx((string) ($props['Visible'] ?? 'true'));
        if ($v === '' || strcasecmp($v, 'true') === 0) {
            return false;
        }
        if (strcasecmp($v, 'false') === 0) {
            return true;
        }
        $val = $this->evalExpr($v, ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0], ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0], 0, 0);

        return $val !== null && (float) $val == 0.0;
    }

    private function directionOf(mixed $raw): string
    {
        $dir = strtolower($this->stripFx((string) $raw));
        if (str_contains($dir, 'horizontal')) {
            return 'horizontal';
        }
        if (str_contains($dir, 'vertical')) {
            return 'vertical';
        }

        return '';
    }

    /** Resolve Control.Width / .TemplateWidth (gallery template = control width). */
    private function boxDim(string $name, string $prop): float
    {
        $box = $this->boxes[$name] ?? null;
        if ($box === null) {
            return 0.0;
        }
        $key = strtolower($prop);
        if ($key === 'templatewidth') {
            return (float) ($box['width'] ?? 0);
        }
        if ($key === 'templateheight') {
            $th = (float) ($box['template_height'] ?? 0);
            if ($th > 0) {
                return $th;
            }

            return (float) ($box['height'] ?? 0);
        }

        return (float) ($box[$key] ?? 0);
    }

    /**
     * @param array{x:float,y:float,width:float,height:float} $parent
     * @param array{x:float,y:float,width:float,height:float} $self
     */
    private function evalDim(mixed $raw, array $parent, array $self, float $appW, float $appH, float $fallback): float
    {
        if ($raw === null) {
            return $fallback;
        }
        $expr = $this->stripFx((string) $raw);
        if ($expr === '') {
            return $fallback;
        }
        if (is_numeric($expr)) {
            return (float) $expr;
        }
        $val = $this->evalExpr($expr, $parent, $self, $appW, $appH);

        return $val === null ? $fallback : $val;
    }

    /**
     * @param array{x:float,y:float,width:float,height:float} $parent
     * @param array{x:float,y:float,width:float,height:float} $self
     */
    private function evalExpr(string $expr, array $parent, array $self, float $appW, float $appH): ?float
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        if (is_numeric($expr)) {
            return (float) $expr;
        }

        $expr = preg_replace('/\bApp\.Width\b/', (string) $appW, $expr) ?? $expr;
        $expr = preg_replace('/\bApp\.Height\b/', (string) $appH, $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.Width\b/', (string) $parent['width'], $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.Height\b/', (string) $parent['height'], $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.X\b/', (string) $parent['x'], $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.Y\b/', (string) $parent['y'], $expr) ?? $expr;
        $padL = (float) ($parent['pad_l'] ?? 0);
        $padR = (float) ($parent['pad_r'] ?? 0);
        $padT = (float) ($parent['pad_t'] ?? 0);
        $padB = (float) ($parent['pad_b'] ?? 0);
        $expr = preg_replace('/\bParent\.PaddingLeft\b/', (string) $padL, $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.PaddingRight\b/', (string) $padR, $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.PaddingTop\b/', (string) $padT, $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.PaddingBottom\b/', (string) $padB, $expr) ?? $expr;
        $templateH = (float) ($parent['template_height'] ?? 0);
        if ($templateH <= 0) {
            $templateH = max(40.0, $parent['height'] > 0 ? min($parent['height'], 50.0) : 40.0);
        }
        $expr = preg_replace('/\bParent\.TemplateHeight\b/', (string) $templateH, $expr) ?? $expr;
        $expr = preg_replace('/\bParent\.TemplateWidth\b/', (string) $parent['width'], $expr) ?? $expr;
        $expr = preg_replace('/\bSelf\.X\b/', (string) $self['x'], $expr) ?? $expr;
        $expr = preg_replace('/\bSelf\.Y\b/', (string) $self['y'], $expr) ?? $expr;
        $expr = preg_replace('/\bSelf\.Width\b/', (string) $self['width'], $expr) ?? $expr;
        $expr = preg_replace('/\bSelf\.Height\b/', (string) $self['height'], $expr) ?? $expr;
        $expr = preg_replace('/\bSelf\.AllItemsCount\b/', '3', $expr) ?? $expr;

        $expr = preg_replace_callback(
            "/'([^']+)'\.(Width|Height|X|Y|TemplateWidth|TemplateHeight)\b/",
            function (array $m): string {
                return (string) $this->boxDim($m[1], $m[2]);
            },
            $expr,
        ) ?? $expr;
        $expr = preg_replace_callback(
            '/\b([A-Za-z_][\w]*)\.(Width|Height|X|Y|TemplateWidth|TemplateHeight)\b/',
            function (array $m): string {
                if (in_array($m[1], ['App', 'Parent', 'Self'], true)) {
                    return $m[0];
                }
                return (string) $this->boxDim($m[1], $m[2]);
            },
            $expr,
        ) ?? $expr;

        foreach ($this->vars as $name => $val) {
            if (is_bool($val)) {
                $expr = preg_replace('/\b' . preg_quote((string) $name, '/') . '\b/', $val ? 'true' : 'false', $expr) ?? $expr;
            } elseif (is_numeric($val)) {
                $expr = preg_replace('/\b' . preg_quote((string) $name, '/') . '\b/', (string) $val, $expr) ?? $expr;
            }
        }

        $expr = preg_replace('/\btrue\b/i', '1', $expr) ?? $expr;
        $expr = preg_replace('/\bfalse\b/i', '0', $expr) ?? $expr;
        $expr = preg_replace('/\bIsBlank\s*\([^()]*\)/i', '1', $expr) ?? $expr;

        $guard = 0;
        while ($guard++ < 12) {
            $next = preg_replace_callback(
                '/\b(Max|Min)\s*\(([^()]*)\)/i',
                function (array $m) use ($parent, $self, $appW, $appH): string {
                    $parts = array_map('trim', explode(',', $m[2]));
                    $nums = [];
                    foreach ($parts as $p) {
                        $v = $this->evalExpr($p, $parent, $self, $appW, $appH);
                        if ($v !== null) {
                            $nums[] = $v;
                        }
                    }
                    if ($nums === []) {
                        return '0';
                    }

                    return (string) (strcasecmp($m[1], 'Max') === 0 ? max($nums) : min($nums));
                },
                $expr,
            );
            $next = preg_replace_callback(
                '/\bIf\s*\(([^(),]+),([^,]+),([^()]*)\)/i',
                function (array $m) use ($parent, $self, $appW, $appH): string {
                    $cond = $this->evalExpr(trim($m[1]), $parent, $self, $appW, $appH);
                    $pick = ($cond !== null && $cond != 0.0) ? trim($m[2]) : trim($m[3]);
                    $v = $this->evalExpr($pick, $parent, $self, $appW, $appH);

                    return (string) ($v ?? 0);
                },
                is_string($next) ? $next : $expr,
            );
            if (!is_string($next) || $next === $expr) {
                $expr = is_string($next) ? $next : $expr;
                break;
            }
            $expr = $next;
        }

        if (preg_match('/^[0-9eE+.\-\s\/*()]+$/', $expr) !== 1) {
            return null;
        }
        try {
            $result = @eval('return (float)(' . $expr . ');');
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($result) ? (float) $result : null;
    }

    private function stripFx(string $value): string
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = substr($v, 1);
        }
        $v = preg_replace('#/\*.*?\*/#s', '', $v) ?? $v;
        $v = preg_replace('#//[^\n]*#', '', $v) ?? $v;
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;

        return trim($v);
    }
}
